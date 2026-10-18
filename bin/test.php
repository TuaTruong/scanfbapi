<?php
// Bộ test tự viết (không PHPUnit). Chạy trên DB riêng scanpostfbapi_test, không gọi mạng thật:
// ScrapeCreators và Telegram được thay bằng bản giả dựng theo response thật.
// Dùng: /Applications/XAMPP/xamppfiles/bin/php bin/test.php [lọc theo tên]
declare(strict_types=1);

putenv('SCANPOST_DB=scanpostfbapi_test');
require dirname(__DIR__) . '/src/bootstrap.php';

// ───────────────────────── Hạ tầng test ─────────────────────────

final class TestFailure extends RuntimeException
{
}

function check(bool $cond, string $msg = 'điều kiện sai'): void
{
    if (!$cond) {
        throw new TestFailure($msg);
    }
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(($msg !== '' ? "$msg: " : '') . 'mong đợi ' . var_export($expected, true) . ', nhận ' . var_export($actual, true));
    }
}

function throws(string $class, callable $fn, string $msg = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new TestFailure("$msg: mong đợi $class, nhận " . $e::class . ': ' . $e->getMessage());
    }
    throw new TestFailure("$msg: mong đợi $class nhưng không có exception");
}

$TESTS = [];
function test(string $name, callable $fn): void
{
    $GLOBALS['TESTS'][] = [$name, $fn];
}

final class FakeHttp implements HttpClient
{
    public array $calls = [];

    public function __construct(private readonly Closure $handler)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        return ($this->handler)($url, $headers, $body);
    }
}

function json_res(int $status, array $data): HttpResponse
{
    return new HttpResponse($status, json_encode($data, JSON_UNESCAPED_UNICODE), null, 5);
}

/** ScrapeCreators giả: trang đầu 4 bài, các trang sau 3 bài, 1 credit mỗi lần gọi, mỗi lần gọi tốn 5 giây. */
final class FakeScrape
{
    public array $feeds = [];        // fb_ref => bài mới → cũ
    public array $credits = [];      // api_key => credit còn (mặc định 100)
    public array $invalid = [];      // api_key => true
    public array $queue = [];        // HttpResponse trả trước cho các lần gọi group posts kế tiếp
    public array $groupErrors = [];  // fb_ref => HttpResponse
    public array $infos = [];        // fb_ref => phản hồi /v1/facebook/group (không có thì 404)
    public FakeHttp $http;

    public function __construct()
    {
        $this->http = new FakeHttp(fn(string $url, array $headers) => $this->handle($url, $headers));
    }

    public function api(): ScrapeCreators
    {
        return new ScrapeCreators($this->http);
    }

    public function scanner(): GroupScanner
    {
        return new GroupScanner($this->api());
    }

    public function postCalls(): array
    {
        return array_values(array_filter($this->http->calls, static fn($c) => str_contains($c['url'], '/group/posts')));
    }

    public static function keyOf(array $call): string
    {
        foreach ($call['headers'] as $h) {
            if (str_starts_with($h, 'x-api-key: ')) {
                return substr($h, 11);
            }
        }
        return '';
    }

    private function handle(string $url, array $headers): HttpResponse
    {
        $key = self::keyOf(['headers' => $headers]);
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $q);
        if ($parts['path'] === '/v1/account/credit-balance') {
            if (isset($this->invalid[$key])) {
                return json_res(401, ['success' => false, 'message' => 'Invalid API key']);
            }
            return json_res(200, ['success' => true, 'creditCount' => $this->credits[$key] ?? 100]);
        }
        if ($parts['path'] === '/v1/facebook/group') {
            $ref = $q['group_id'] ?? basename(rtrim((string) ($q['url'] ?? ''), '/'));
            if (!isset($this->infos[$ref])) {
                return json_res(404, ['success' => false, 'message' => 'Group not found']);
            }
            $credits = ($this->credits[$key] ?? 100) - 1;
            $this->credits[$key] = $credits;
            return json_res(200, ['success' => true, 'credits_remaining' => $credits, 'credits_charged' => 1] + $this->infos[$ref]);
        }
        Clock::advance(5);
        if ($this->queue) {
            return array_shift($this->queue);
        }
        if (isset($this->invalid[$key])) {
            return json_res(401, ['success' => false, 'message' => 'Invalid API key']);
        }
        $credits = $this->credits[$key] ?? 100;
        if ($credits <= 0) {
            return json_res(402, ['success' => false, 'message' => 'Insufficient credits']);
        }
        $ref = $q['group_id'] ?? basename(rtrim((string) ($q['url'] ?? ''), '/'));
        if (isset($this->groupErrors[$ref])) {
            return $this->groupErrors[$ref];
        }
        $feed = $this->feeds[$ref] ?? [];
        $offset = isset($q['cursor']) ? (int) substr($q['cursor'], 1) : 0;
        $size = $offset === 0 ? 4 : 3;
        $next = $offset + $size < count($feed) ? 'c' . ($offset + $size) : null;
        $this->credits[$key] = $credits - 1;
        return json_res(200, ['success' => true, 'credits_remaining' => $credits - 1, 'credits_charged' => 1,
            'posts' => array_slice($feed, $offset, $size), 'cursor' => $next]);
    }
}

/** Telegram giả: ghi lại mọi lời gọi, mặc định trả ok. */
final class FakeTg
{
    public FakeHttp $http;
    public array $responses = []; // method => list<array> trả lần lượt
    private int $messageId = 1000;

    public function __construct()
    {
        $this->http = new FakeHttp(fn(string $url, array $headers, ?string $body) => $this->handle($url));
    }

    public function bot(): Bot
    {
        return new Bot(new Telegram($this->http, '123456:TEST'));
    }

    /** @return list<array> tham số các lần gọi $method */
    public function calls(string $method): array
    {
        $out = [];
        foreach ($this->http->calls as $c) {
            if (str_ends_with(parse_url($c['url'], PHP_URL_PATH), '/' . $method)) {
                $out[] = json_decode((string) $c['body'], true);
            }
        }
        return $out;
    }

    public function lastText(): string
    {
        $calls = $this->calls('sendMessage');
        return $calls ? (string) end($calls)['text'] : '';
    }

    private function handle(string $url): HttpResponse
    {
        $method = basename((string) parse_url($url, PHP_URL_PATH));
        if (!empty($this->responses[$method])) {
            $data = array_shift($this->responses[$method]);
            return json_res((int) ($data['error_code'] ?? 200), $data);
        }
        $result = match ($method) {
            'sendMessage', 'sendPhoto' => ['message_id' => ++$this->messageId],
            'sendMediaGroup' => [['message_id' => ++$this->messageId], ['message_id' => ++$this->messageId]],
            default => true,
        };
        return json_res(200, ['ok' => true, 'result' => $result]);
    }
}

$PASSWORD_HASH = password_hash('cloudcheap', PASSWORD_DEFAULT);

function reset_db(): void
{
    foreach (Migrator::tables() as $t) {
        Db::run("DELETE FROM $t");
    }
    Clock::set(new DateTimeImmutable('2026-10-18 09:00:00'));
    Migrator::seedSettings();
    Settings::set('bot_password_hash', $GLOBALS['PASSWORD_HASH']);
    Settings::reload();
}

function add_group(string $line, array $f = []): array
{
    $p = InputParser::parseGroup($line);
    $now = Clock::str();
    $id = Db::insert(
        'INSERT INTO fb_groups (input_raw, fb_ref, ref_type, in_list, sort_order, enabled, every_n_cycles, max_pages, created_at, updated_at)
         VALUES (?, ?, ?, 1, 0, ?, ?, ?, ?, ?)',
        [$line, $p['fb_ref'], $p['ref_type'], $f['enabled'] ?? 1, $f['n'] ?? 1, $f['pages'] ?? 2, $now, $now]
    );
    return Groups::find($id);
}

function add_key(string $key, int $priority = 0): int
{
    return Db::insert("INSERT INTO api_keys (api_key, priority, in_list, status, created_at) VALUES (?, ?, 1, 'active', ?)", [$key, $priority, Clock::str()]);
}

function add_user(int $chatId, string $state = 'authorized', string $name = 'User'): void
{
    Db::run('INSERT INTO tg_users (chat_id, first_name, state, created_at) VALUES (?, ?, ?, ?)', [$chatId, "$name$chatId", $state, Clock::str()]);
}

function user(int $chatId): array
{
    return Db::one('SELECT * FROM tg_users WHERE chat_id = ?', [$chatId]);
}

function key_status(string $key): string
{
    return (string) Db::val('SELECT status FROM api_keys WHERE api_key = ?', [$key]);
}

function fp(string $id, ?string $author = 'u1', ?string $text = null): array
{
    return [
        'id' => $id,
        'text' => $text ?? "Nội dung bài $id", // mỗi bài một nội dung để không bị chống trùng nội dung

        'url' => "https://www.facebook.com/groups/g/permalink/$id/",
        'author' => $author === null ? null : ['__typename' => 'User', 'name' => "Tên $author", 'id' => $author],
        'publishTime' => 1792254259,
    ];
}

/** Bài $prefix$to … $prefix$from (mới → cũ). */
function feed(string $prefix, int $from, int $to, ?string $author = 'u1'): array
{
    $out = [];
    for ($i = $to; $i >= $from; $i--) {
        $out[] = fp($prefix . $i, $author);
    }
    return $out;
}

function msg(int $chatId, string $text, int $messageId = 1): array
{
    static $u = 1;
    return ['update_id' => $u++, 'message' => [
        'message_id' => $messageId,
        'chat' => ['id' => $chatId, 'type' => 'private'],
        'from' => ['id' => $chatId, 'first_name' => "User$chatId", 'username' => "user$chatId"],
        'text' => $text,
    ]];
}

function cb(int $chatId, string $data, int $messageId = 500): array
{
    static $u = 100000;
    return ['update_id' => $u++, 'callback_query' => [
        'id' => 'cb' . $u,
        'from' => ['id' => $chatId, 'first_name' => "User$chatId"],
        'data' => $data,
        'message' => [
            'message_id' => $messageId,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'reply_markup' => ['inline_keyboard' => [[['text' => '🔗 Mở bài viết', 'url' => 'https://www.facebook.com/groups/g/permalink/1/']], [['text' => 'x', 'callback_data' => $data]]]],
        ],
    ]];
}

function count_of(string $sql, array $params = []): int
{
    return (int) Db::val($sql, $params);
}

function last(array $items): mixed
{
    return $items ? $items[array_key_last($items)] : null;
}

// ───────────────────────── InputParser ─────────────────────────

test('InputParser: 4 dạng link ở mục 6.1', function () {
    $cases = [
        'https://www.facebook.com/groups/vpsmincom' => ['vpsmincom', 'url'],
        'https://facebook.com/groups/742354120555345/permalink/123/' => ['742354120555345', 'group_id'],
        '742354120555345' => ['742354120555345', 'group_id'],
        'm.facebook.com/groups/abc?ref=share' => ['abc', 'url'],
    ];
    foreach ($cases as $line => [$ref, $type]) {
        $line = (string) $line; // key mảng dạng số bị PHP đổi thành int
        $g = InputParser::parseGroup($line);
        eq($ref, $g['fb_ref'], $line);
        eq($type, $g['ref_type'], $line);
    }
    eq('https://www.facebook.com/groups/vpsmincom', InputParser::groupUrl('vpsmincom'));
});

test('InputParser: bỏ dòng trống và dòng trùng', function () {
    $r = InputParser::parseGroupList("https://www.facebook.com/groups/vpsmincom/\n\n   \r\nfacebook.com/groups/VPSMINCOM?x=1\n742354120555345\n  742354120555345  \n");
    eq(2, count($r['groups']));
    eq(2, $r['duplicates']);
    eq([], $r['errors']);
});

test('InputParser: link sai báo lỗi rõ', function () {
    $r = InputParser::parseGroupList("https://google.com/groups/x\nfacebook.com/vpsmincom\nhttps://www.facebook.com/groups/vpsmincom");
    eq(1, count($r['groups']));
    eq(2, count($r['errors']));
    check(str_contains($r['errors'][0], 'google.com/groups/x'), 'thông báo phải nêu dòng sai: ' . $r['errors'][0]);
    check(str_contains($r['errors'][1], '/groups/'), 'thông báo phải nói thiếu /groups/: ' . $r['errors'][1]);
    throws(InvalidArgumentException::class, fn() => InputParser::parseGroup('xin chào'), 'chữ thường');
});

test('Groups: xoá dòng thì in_list=0, giữ lịch sử; thêm lại không mất', function () {
    $a = 'https://www.facebook.com/groups/aaa';
    $b = 'https://www.facebook.com/groups/bbb';
    eq(2, Groups::syncFromText("$a\n$b")['added']);
    $idB = (int) Groups::findByRef('bbb')['id'];
    Db::run("INSERT INTO posts (group_id, fb_post_id, first_seen_at, status) VALUES (?, 'p1', ?, 'queued')", [$idB, Clock::str()]);

    $r = Groups::syncFromText($a);
    eq(1, $r['removed']);
    eq(0, (int) Groups::findByRef('bbb')['in_list']);
    eq(1, count_of('SELECT COUNT(*) FROM posts WHERE group_id = ?', [$idB]), 'lịch sử bài phải còn');
    eq($a, Groups::textareaValue());

    $r = Groups::syncFromText("$b\n$a");
    eq(1, $r['reactivated']);
    eq(0, $r['added']);
    eq($idB, (int) Groups::findByRef('bbb')['id'], 'thêm lại phải dùng đúng group cũ');
    eq("$b\n$a", Groups::textareaValue(), 'giữ thứ tự dòng');
    eq(2, (int) Groups::findByRef('aaa')['max_pages'], 'group mới nhận max_pages_default = 2');
});

test('Groups: xoá trên web giữ lịch sử, huỷ tin chờ; khôi phục; xoá vĩnh viễn', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = [fp('a2'), fp('a1')];
    $g1 = add_group('https://www.facebook.com/groups/g1');
    $g2 = add_group('https://www.facebook.com/groups/g2');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    $fs->scanner()->scan($g1, 1);
    eq(2, count_of("SELECT COUNT(*) FROM outbox WHERE status = 'pending'"));

    eq(1, Groups::remove([(int) $g1['id']]));
    eq(0, Groups::remove([(int) $g1['id']]), 'xoá lần nữa không tính');
    eq(0, (int) Groups::find((int) $g1['id'])['in_list']);
    eq(2, count_of('SELECT COUNT(*) FROM posts'), 'giữ lịch sử bài');
    eq(2, count_of("SELECT COUNT(*) FROM outbox WHERE status = 'cancelled' AND last_error = 'group removed'"));
    eq('https://www.facebook.com/groups/g2', Groups::textareaValue(), 'textarea ở Cài đặt cũng mất dòng đó');
    eq([(int) $g1['id']], array_map(static fn($r) => (int) $r['id'], Groups::removed()));
    eq(0, Groups::purge([(int) $g2['id']]), 'không xoá vĩnh viễn group còn trong danh sách');

    eq(1, Groups::restore([(int) $g1['id']]));
    eq(1, (int) Groups::find((int) $g1['id'])['in_list']);
    eq(0, $fs->scanner()->scan(Groups::find((int) $g1['id']), 2)['new_posts'], 'khôi phục không gửi lại bài cũ');

    Groups::remove([(int) $g1['id']]);
    eq(1, Groups::purge([(int) $g1['id']]));
    eq(null, Groups::find((int) $g1['id']));
    eq(0, count_of('SELECT COUNT(*) FROM posts'));
    eq(0, count_of('SELECT COUNT(*) FROM outbox'));
});

test('Scheduler: group bị xoá hoặc tắt giữa đợt thì bỏ qua', function () {
    $fs = new FakeScrape();
    add_group('https://www.facebook.com/groups/g1');
    $g2 = add_group('https://www.facebook.com/groups/g2');
    add_key('KEY_AAAAAAAA');
    // Xoá g2 ngay sau khi g1 được quét (mô phỏng người dùng bấm Xoá trên web giữa đợt)
    $http = new FakeHttp(function (string $url, array $headers) use ($fs, $g2) {
        Groups::remove([(int) $g2['id']]);
        return (fn() => $this->handle($url, $headers))->call($fs);
    });
    $r = (new Scheduler(new GroupScanner(new ScrapeCreators($http))))->runCycle();
    eq(2, $r['groups_due']);
    eq(1, $r['groups_scanned']);
    eq(null, Groups::find((int) $g2['id'])['last_scanned_cycle']);
});

test('ApiKeys: thứ tự dòng = ưu tiên, bỏ trùng, xoá thì in_list=0', function () {
    $r = ApiKeys::syncFromText("KEY_AAAAAAAA\n\nKEY_BBBBBBBB, KEY_CCCCCCCC\nKEY_AAAAAAAA");
    eq(3, $r['added']);
    eq(1, $r['duplicates']);
    eq('KEY_AAAAAAAA', ApiKeys::current()['api_key']);
    ApiKeys::syncFromText("KEY_CCCCCCCC\nKEY_AAAAAAAA");
    eq('KEY_CCCCCCCC', ApiKeys::current()['api_key'], 'đổi thứ tự dòng thì đổi ưu tiên');
    eq(0, (int) Db::val("SELECT in_list FROM api_keys WHERE api_key = 'KEY_BBBBBBBB'"));
});

// ───────────────────────── Scheduler ─────────────────────────

test('Scheduler: trong/ngoài khung giờ 06:00–22:00', function () {
    $at = static fn(string $hm) => new DateTimeImmutable("2026-10-18 $hm:00");
    eq(false, Scheduler::inWindow('06:00', '22:00', $at('05:59')));
    eq(true, Scheduler::inWindow('06:00', '22:00', $at('06:00')));
    eq(true, Scheduler::inWindow('06:00', '22:00', $at('21:59')));
    eq(false, Scheduler::inWindow('06:00', '22:00', $at('22:00')));
});

test('Scheduler: khung qua nửa đêm 20:00→02:00', function () {
    $at = static fn(string $hm) => new DateTimeImmutable("2026-10-18 $hm:00");
    eq(true, Scheduler::inWindow('20:00', '02:00', $at('20:00')));
    eq(true, Scheduler::inWindow('20:00', '02:00', $at('23:30')));
    eq(true, Scheduler::inWindow('20:00', '02:00', $at('01:59')));
    eq(false, Scheduler::inWindow('20:00', '02:00', $at('02:00')));
    eq(false, Scheduler::inWindow('20:00', '02:00', $at('19:59')));
    eq(true, Scheduler::inWindow('00:00', '00:00', $at('03:00')), 'Từ = Đến là cả ngày');
});

test('Scheduler: next_run_at tính từ lúc đợt KẾT THÚC + nghỉ', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 20);
    $fs->feeds['g2'] = feed('q', 1, 20);
    add_group('https://www.facebook.com/groups/g1');
    add_group('https://www.facebook.com/groups/g2');
    add_key('KEY_AAAAAAAA');
    Settings::set('scan_enabled', '1');
    Settings::set('rest_minutes', '30');
    $s = new Scheduler($fs->scanner());

    eq('run', $s->tick());
    // 2 group × 1 trang × 5 giây → đợt kết thúc 09:00:10
    eq('2026-10-18 09:30:10', Settings::fresh('next_run_at'));
    eq('wait', $s->tick());
    Clock::set(new DateTimeImmutable('2026-10-18 09:30:09'));
    eq('wait', $s->tick());
    Clock::set(new DateTimeImmutable('2026-10-18 09:30:10'));
    eq('run', $s->tick());
    eq(2, Settings::int('cycle_no'));
});

test('Scheduler: group N=3 chỉ đến hạn ở đợt 1, 4, 7', function () {
    $fs = new FakeScrape();
    $fs->feeds['g3'] = feed('p', 1, 20);
    add_group('https://www.facebook.com/groups/g3', ['n' => 3]);
    add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    $s = new Scheduler($fs->scanner());
    $g3Cycles = [];
    for ($i = 1; $i <= 7; $i++) {
        $s->runCycle();
        $g = Groups::findByRef('g3');
        if ((int) $g['last_scanned_cycle'] === $i) {
            $g3Cycles[] = $i;
        }
    }
    eq([1, 4, 7], $g3Cycles);
    eq(7, (int) Groups::findByRef('g1')['last_scanned_cycle'], 'group N=1 quét mọi đợt');
});

test('Scheduler: run_now bỏ qua khung giờ và tự tắt cờ', function () {
    $fs = new FakeScrape();
    add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    Clock::set(new DateTimeImmutable('2026-10-18 23:30:00'));
    Settings::set('scan_enabled', '1');
    $s = new Scheduler($fs->scanner());
    eq('outside_window', $s->tick());
    Settings::set('run_now', '1');
    eq('run_now', $s->tick());
    eq('0', Settings::fresh('run_now'));
    eq(1, Settings::int('cycle_no'));
    eq('outside_window', $s->tick());
});

test('Scheduler: scan_enabled=0 thì không chạy', function () {
    $fs = new FakeScrape();
    add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    Settings::set('scan_enabled', '0');
    Settings::set('run_now', '1');
    eq('disabled', (new Scheduler($fs->scanner()))->tick());
    eq(0, Settings::int('cycle_no'));
    eq(0, count($fs->postCalls()));
});

test('Scheduler: đổi cài đặt trong DB có hiệu lực ở tick kế tiếp (không khởi động lại)', function () {
    $fs = new FakeScrape();
    add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    Settings::set('scan_enabled', '1');
    $s = new Scheduler($fs->scanner());
    eq('run', $s->tick());
    // Web ghi thẳng vào DB (tiến trình khác)
    Db::run("UPDATE settings SET v = '10:00' WHERE k = 'window_start'");
    Db::run("UPDATE settings SET v = '' WHERE k = 'next_run_at'");
    eq('outside_window', $s->tick());
    Db::run("UPDATE settings SET v = '0' WHERE k = 'scan_enabled'");
    eq('disabled', $s->tick());
});

// ───────────────────────── GroupScanner ─────────────────────────

test('GroupScanner: lần quét đầu chỉ 1 trang, gửi theo thứ tự cũ → mới', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 20);
    $g = add_group('https://www.facebook.com/groups/g1', ['pages' => 5]);
    add_key('KEY_AAAAAAAA');
    add_user(1);
    $r = $fs->scanner()->scan($g, 1);
    eq(true, $r['first_scan']);
    eq(1, $r['api_calls']);
    eq(4, $r['new_posts']);
    eq(4, $r['queued']);
    eq(false, $r['cap_hit']);
    $order = Db::col('SELECT p.fb_post_id FROM outbox o JOIN posts p ON p.id = o.post_id ORDER BY o.id');
    eq(['p17', 'p18', 'p19', 'p20'], $order);
});

test('GroupScanner: dừng khi trang có ≥2 bài cũ', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 20);
    $g = add_group('https://www.facebook.com/groups/g1', ['pages' => 5]);
    add_key('KEY_AAAAAAAA');
    $sc = $fs->scanner();
    $sc->scan($g, 1);
    $fs->feeds['g1'] = array_merge([fp('n2'), fp('n1')], feed('p', 1, 20));
    $r = $sc->scan(Groups::find((int) $g['id']), 2);
    eq(1, $r['api_calls']);
    eq(2, $r['new_posts']);
});

test('GroupScanner: chỉ 1 bài cũ (bài ghim) thì chưa dừng', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 20);
    $g = add_group('https://www.facebook.com/groups/g1', ['pages' => 5]);
    add_key('KEY_AAAAAAAA');
    $sc = $fs->scanner();
    $sc->scan($g, 1); // lưu p20..p17
    // Bài ghim p20 đứng đầu, sau đó 6 bài mới, rồi bài cũ
    $fs->feeds['g1'] = array_merge([fp('p20')], feed('n', 1, 6), feed('p', 1, 19));
    $r = $sc->scan(Groups::find((int) $g['id']), 2);
    eq(3, $r['api_calls'], 'trang 1 có 1 bài cũ → đi tiếp; trang 2 toàn mới; trang 3 toàn cũ → dừng');
    eq(6, $r['new_posts']);
    eq(false, $r['cap_hit']);
});

test('GroupScanner: chạm trần mà trang cuối toàn bài mới thì cap_hits+1', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 20);
    $g = add_group('https://www.facebook.com/groups/g1', ['pages' => 2]);
    add_key('KEY_AAAAAAAA');
    $sc = $fs->scanner();
    $sc->scan($g, 1);
    $fs->feeds['g1'] = array_merge(feed('n', 1, 10), feed('p', 1, 20));
    $r = $sc->scan(Groups::find((int) $g['id']), 2);
    eq(2, $r['api_calls']);
    eq(7, $r['new_posts']);
    eq(true, $r['cap_hit']);
    $row = Groups::find((int) $g['id']);
    eq(1, (int) $row['cap_hits']);
    check($row['last_cap_hit_at'] !== null);
    // Đúng rủi ro cảnh báo: n3..n1 nằm sâu sau trang toàn bài cũ nên không bao giờ lấy được.
    $r = $sc->scan($row, 3);
    eq(0, $r['new_posts']);
    // Tăng số trang thì lần sau đuổi kịp, không chạm trần nữa.
    Groups::update((int) $g['id'], ['max_pages' => 5]);
    $fs->feeds['g1'] = array_merge(feed('m', 1, 5), $fs->feeds['g1']);
    $r = $sc->scan(Groups::find((int) $g['id']), 4);
    eq(false, $r['cap_hit']);
    eq(5, $r['new_posts']);
    eq(2, $r['api_calls']);
    eq(1, (int) Groups::find((int) $g['id'])['cap_hits']);
});

test('GroupScanner: API trả nhầm trang bài cũ nhiều tuần thì không gửi, không lật thêm trang', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 20);
    $g = add_group('https://www.facebook.com/groups/g1', ['pages' => 2]);
    add_key('KEY_AAAAAAAA');
    add_user(1);
    $sc = $fs->scanner();
    $sc->scan($g, 1);
    // Tình huống thật 18/10/2026: trang đầu toàn bài 22–24/9 với ID chưa từng thấy
    $old = [];
    for ($i = 10; $i >= 1; $i--) {
        $old[] = ['publishTime' => strtotime('2026-09-22 10:00:00') + $i * 3600] + fp("old$i");
    }
    $fs->feeds['g1'] = $old;
    $r = $sc->scan(Groups::find((int) $g['id']), 2);
    eq(1, $r['api_calls'], 'trang toàn bài quá cũ coi như đã đuổi kịp');
    eq(0, $r['queued']);
    eq(4, $r['stale']);
    eq(false, $r['cap_hit']);
    eq(4, count_of('SELECT COUNT(*) FROM outbox'), 'chỉ có 4 tin của lần quét đầu');
    eq(4, count_of("SELECT COUNT(*) FROM posts WHERE status = 'baseline'"));

    // Bài duyệt muộn nhưng còn trong hạn 72 giờ vẫn được gửi
    $fs->feeds['g1'] = [['publishTime' => Clock::ts() - 48 * 3600] + fp('late'), fp('p20'), fp('p19')];
    $r = $sc->scan(Groups::find((int) $g['id']), 3);
    eq(1, $r['queued']);

    // 0 = tắt bộ lọc
    Settings::set('max_post_age_hours', '0');
    $fs->feeds['g1'] = [['publishTime' => strtotime('2026-09-01')] + fp('ancient'), fp('p20'), fp('p19')];
    eq(1, $sc->scan(Groups::find((int) $g['id']), 4)['queued']);
});

test('GroupScanner: bài trùng nội dung (đăng lại, đăng nhiều group) không gửi lại', function () {
    $ad = "🔥 CẦN VPS & PROXY CHẤT LƯỢNG? VPSPROXY365 SẴN HÀNG, giá chỉ từ 50k/tháng";
    $fs = new FakeScrape();
    $fs->feeds['g1'] = [fp('a1', 'seller1', $ad)];
    $fs->feeds['g2'] = [fp('b1', 'seller1', "  🔥🔥 cần vps & proxy chất lượng?\nVPSPROXY365 sẵn hàng, GIÁ chỉ từ 50k/tháng!! ")];
    $fs->feeds['g3'] = [fp('c1', 'nick2', $ad)]; // cùng nội dung dài, nick khác
    $g1 = add_group('https://www.facebook.com/groups/g1');
    $g2 = add_group('https://www.facebook.com/groups/g2');
    $g3 = add_group('https://www.facebook.com/groups/g3');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    $sc = $fs->scanner();
    eq(1, $sc->scan($g1, 1)['queued']);
    $r = $sc->scan($g2, 1);
    eq(0, $r['queued'], 'khác emoji, dấu cách, chữ hoa vẫn là trùng');
    eq(1, $r['duplicate']);
    eq(1, $sc->scan($g3, 1)['duplicate'], 'nội dung dài trùng thì chặn cả nick khác');
    eq(1, count_of('SELECT COUNT(*) FROM outbox'));

    // Đăng lại trong cùng group với ID mới ở lần quét sau
    $fs->feeds['g1'] = [fp('a2', 'seller1', $ad), fp('a1', 'seller1', $ad)];
    $r = $sc->scan(Groups::find((int) $g1['id']), 2);
    eq(1, $r['new_posts']);
    eq(1, $r['duplicate']);
    eq(1, count_of('SELECT COUNT(*) FROM outbox'));

    // Nội dung ngắn: chỉ trùng khi cùng người đăng
    $fs->feeds['g2'] = [fp('b3', 'buyerB', 'Cần mua VPS'), fp('b2', 'buyerA', 'Cần mua VPS'), fp('b1', 'seller1')];
    $r = $sc->scan(Groups::find((int) $g2['id']), 2);
    eq(2, $r['queued'], 'hai người khác nhau cùng viết "Cần mua VPS" vẫn gửi cả hai');
    $fs->feeds['g3'] = [fp('c2', 'buyerA', 'cần mua vps!'), fp('c1', 'nick2')];
    eq(1, $sc->scan(Groups::find((int) $g3['id']), 2)['duplicate']);

    // Bài không có chữ thì không so trùng
    $fs->feeds['g1'] = [['text' => null] + fp('a4'), ['text' => null] + fp('a3'), fp('a2', 'seller1', $ad)];
    eq(2, $sc->scan(Groups::find((int) $g1['id']), 3)['queued']);
});

test('GroupScanner: khoảng chống trùng theo cài đặt (24 giờ / tắt)', function () {
    $ad = 'Bán proxy tĩnh Việt Nam giá rẻ, inbox để nhận báo giá chi tiết';
    $fs = new FakeScrape();
    $fs->feeds['g1'] = [fp('a1', 'u1', $ad)];
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    $sc = $fs->scanner();
    Settings::set('dedupe_days', '1');
    $sc->scan($g, 1);
    Clock::set(new DateTimeImmutable('2026-10-19 08:00:00')); // 23 giờ sau
    $fs->feeds['g1'] = [fp('a2', 'u1', $ad), fp('a1', 'u1', $ad)];
    eq(1, $sc->scan(Groups::find((int) $g['id']), 2)['duplicate']);
    Clock::set(new DateTimeImmutable('2026-10-20 10:00:00')); // quá 24 giờ kể từ lần thấy gần nhất
    $fs->feeds['g1'] = [['publishTime' => Clock::ts()] + fp('a3', 'u1', $ad), fp('a2', 'u1', $ad), fp('a1', 'u1', $ad)];
    eq(1, $sc->scan(Groups::find((int) $g['id']), 3)['queued']);
    Settings::set('dedupe_days', '0');
    $fs->feeds['g1'] = [['publishTime' => Clock::ts()] + fp('a4', 'u1', $ad), fp('a3', 'u1', $ad), fp('a2', 'u1', $ad)];
    eq(1, $sc->scan(Groups::find((int) $g['id']), 4)['queued'], 'tắt thì gửi cả bài trùng');
});

test('GroupScanner: bài của seller thành skipped_seller và không có outbox', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = [fp('a4', 'good'), fp('a3', 'bad'), fp('a2', 'bad'), fp('a1', 'good')];
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    Sellers::mark('bad', 'Seller', null);
    $r = $fs->scanner()->scan($g, 1);
    eq(4, $r['new_posts']);
    eq(2, $r['queued']);
    eq(2, $r['skipped_seller']);
    eq(2, count_of("SELECT COUNT(*) FROM posts WHERE status = 'skipped_seller' AND author_id = 'bad'"));
    eq(0, count_of("SELECT COUNT(*) FROM outbox o JOIN posts p ON p.id = o.post_id WHERE p.author_id = 'bad'"));
    eq(2, count_of('SELECT COUNT(*) FROM outbox'));
});

test('GroupScanner: quét lại không gửi trùng', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 20);
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    $sc = $fs->scanner();
    $sc->scan($g, 1);
    $r = $sc->scan(Groups::find((int) $g['id']), 2);
    eq(0, $r['new_posts']);
    eq(1, $r['api_calls'], 'chỉ 1 lần gọi API');
    eq(4, count_of('SELECT COUNT(*) FROM outbox'));
    eq(4, count_of('SELECT COUNT(*) FROM posts'));
});

test('GroupScanner: group lỗi 3 lần liên tiếp thì bị tạm hoãn', function () {
    $fs = new FakeScrape();
    $fs->groupErrors['broken'] = json_res(404, ['success' => false, 'message' => 'Group not found']);
    add_group('https://www.facebook.com/groups/broken');
    add_key('KEY_AAAAAAAA');
    $s = new Scheduler($fs->scanner());
    $s->runCycle();
    $s->runCycle();
    $g = Groups::findByRef('broken');
    eq(2, (int) $g['consecutive_errors']);
    eq(null, $g['paused_until_cycle'], 'chưa tạm hoãn khi mới 2 lỗi');
    $r = $s->runCycle();
    eq(1, $r['errors']);
    $g = Groups::findByRef('broken');
    eq(3, (int) $g['consecutive_errors']);
    eq(4, (int) $g['paused_until_cycle'], 'lỗi lần 3 ở đợt 3 → hoãn 1 đợt');
    eq(null, $g['last_scanned_cycle'], 'lỗi thì không tính là đã quét');
    eq([], $s->dueGroups(4));
    eq(1, count($s->dueGroups(5)));
    check(str_contains((string) $g['last_error'], 'Group not found'));
    eq('active', key_status('KEY_AAAAAAAA'), '404 của group không làm hỏng key');
    $s->runCycle(); // đợt 4: bị bỏ qua
    $s->runCycle(); // đợt 5: lỗi lần 4 → hoãn 2 đợt
    eq(7, (int) Groups::findByRef('broken')['paused_until_cycle']);
});

// ───────────────────────── KeyPool / ScrapeCreators ─────────────────────────

test('ScrapeCreators: sort_by lấy từ cài đặt, giá trị lạ thì về CHRONOLOGICAL', function () {
    $fs = new FakeScrape();
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    $sortOf = static function () use ($fs): string {
        parse_str((string) parse_url(last($fs->postCalls())['url'], PHP_URL_QUERY), $q);
        return (string) $q['sort_by'];
    };
    $fs->api()->groupPosts($g);
    eq('CHRONOLOGICAL', $sortOf());
    Db::run("UPDATE settings SET v = 'RECENT_ACTIVITY' WHERE k = 'sort_by'"); // web đổi trong DB
    Settings::reload();
    $fs->api()->groupPosts($g);
    eq('RECENT_ACTIVITY', $sortOf());
    Settings::set('sort_by', 'BAD_VALUE');
    $fs->api()->groupPosts($g);
    eq('CHRONOLOGICAL', $sortOf());
});

test('KeyPool: 401 thì invalid và chuyển key', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 5);
    $fs->invalid['KEY_AAAAAAAA'] = true;
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA', 0);
    add_key('KEY_BBBBBBBB', 1);
    $r = $fs->api()->groupPosts($g);
    eq(4, count($r['posts']));
    eq('invalid', key_status('KEY_AAAAAAAA'));
    eq('active', key_status('KEY_BBBBBBBB'));
    eq(['KEY_AAAAAAAA', 'KEY_BBBBBBBB'], array_map([FakeScrape::class, 'keyOf'], $fs->postCalls()));
});

test('KeyPool: 402 thì exhausted và chuyển key', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 5);
    $fs->credits['KEY_AAAAAAAA'] = 0;
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA', 0);
    add_key('KEY_BBBBBBBB', 1);
    $fs->api()->groupPosts($g);
    eq('exhausted', key_status('KEY_AAAAAAAA'));
    eq('KEY_BBBBBBBB', ApiKeys::current()['api_key']);
});

test('KeyPool: body có chữ "credit" (kiểm tra số dư = 0) thì exhausted và chuyển key', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 5);
    $fs->credits['KEY_AAAAAAAA'] = 0;
    $fs->queue[] = json_res(400, ['success' => false, 'message' => 'Not enough credits to make this request']);
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA', 0);
    add_key('KEY_BBBBBBBB', 1);
    $r = $fs->api()->groupPosts($g);
    eq(4, count($r['posts']));
    eq('exhausted', key_status('KEY_AAAAAAAA'));
    eq(1, count_of("SELECT COUNT(*) FROM api_calls WHERE endpoint = 'credit_balance'"), 'đã kiểm tra số dư trước khi bỏ key');
});

test('KeyPool: 403 nhưng key vẫn còn credit thì là lỗi group, không bỏ key', function () {
    $fs = new FakeScrape();
    $fs->queue[] = json_res(403, ['success' => false, 'message' => 'Forbidden: private group']);
    $g = add_group('https://www.facebook.com/groups/private1');
    add_key('KEY_AAAAAAAA', 0);
    add_key('KEY_BBBBBBBB', 1);
    $e = throws(ApiException::class, fn() => $fs->api()->groupPosts($g), '403');
    eq(403, $e->httpStatus);
    eq('active', key_status('KEY_AAAAAAAA'));
    eq(1, count($fs->postCalls()), 'không thử key khác');
});

test('KeyPool: credits_remaining = 0 thì chủ động đánh dấu exhausted', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 5);
    $fs->credits['KEY_AAAAAAAA'] = 1;
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA', 0);
    add_key('KEY_BBBBBBBB', 1);
    $fs->api()->groupPosts($g);
    eq('exhausted', key_status('KEY_AAAAAAAA'));
    eq(0, (int) Db::val("SELECT credits_remaining FROM api_keys WHERE api_key = 'KEY_AAAAAAAA'"));
    $fs->api()->groupPosts($g);
    eq('KEY_BBBBBBBB', FakeScrape::keyOf($fs->postCalls()[1]), 'lần sau dùng key kế tiếp, không tốn lượt gọi hỏng');
    eq(2, count($fs->postCalls()));
});

test('KeyPool: 5xx thì thử lại trên CÙNG key (chờ 5s, 15s)', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 5);
    $fs->queue = [json_res(500, ['success' => false, 'message' => 'Internal error']), new HttpResponse(0, '', 'Operation timed out')];
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA', 0);
    add_key('KEY_BBBBBBBB', 1);
    $r = $fs->api()->groupPosts($g);
    eq(4, count($r['posts']));
    eq(['KEY_AAAAAAAA', 'KEY_AAAAAAAA', 'KEY_AAAAAAAA'], array_map([FakeScrape::class, 'keyOf'], $fs->postCalls()));
    eq(20.0, Clock::sleptSeconds());
    eq('active', key_status('KEY_AAAAAAAA'));
    eq(3, count_of('SELECT COUNT(*) FROM api_calls'), 'mỗi lần gọi đều ghi api_calls');
});

test('KeyPool: 5xx quá 2 lần thử lại thì báo lỗi group', function () {
    $fs = new FakeScrape();
    $fs->queue = array_fill(0, 3, json_res(502, ['message' => 'Bad gateway']));
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    throws(ApiException::class, fn() => $fs->api()->groupPosts($g), '5xx');
    eq(3, count($fs->postCalls()));
});

test('KeyPool: 429 thì chờ 10s rồi thử lại 1 lần', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 5);
    $fs->queue = [json_res(429, ['message' => 'Too many requests'])];
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    $fs->api()->groupPosts($g);
    eq(10.0, Clock::sleptSeconds());
    eq(2, count($fs->postCalls()));
});

test('KeyPool: hết key thì ném AllKeysExhausted và chỉ tạo 1 cảnh báo', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = feed('p', 1, 5);
    $fs->credits = ['KEY_AAAAAAAA' => 0, 'KEY_BBBBBBBB' => 0];
    $g = add_group('https://www.facebook.com/groups/g1');
    add_group('https://www.facebook.com/groups/g2');
    add_key('KEY_AAAAAAAA', 0);
    add_key('KEY_BBBBBBBB', 1);
    add_user(1);
    add_user(2, 'awaiting_password');
    throws(AllKeysExhaustedException::class, fn() => $fs->api()->groupPosts($g), 'hết key');
    eq('exhausted', key_status('KEY_BBBBBBBB'));

    Settings::set('scan_enabled', '1');
    $s = new Scheduler($fs->scanner());
    Db::run("UPDATE api_keys SET status = 'active'");
    $r = $s->runCycle();
    eq(true, $r['exhausted']);
    eq(0, $r['groups_scanned']);
    eq(null, Groups::findByRef('g1')['last_scanned_cycle'], 'group chưa quét được vẫn đến hạn đợt sau');
    Settings::set('run_now', '1');
    eq('no_keys', $s->tick());
    $s->runCycle();
    eq(1, count_of("SELECT COUNT(*) FROM outbox WHERE kind = 'alert'"), 'chỉ 1 cảnh báo cho 1 người đã đăng nhập');

    // Nạp thêm credit → hồi sinh key (credit-balance miễn phí), cờ cảnh báo reset
    $fs->credits['KEY_AAAAAAAA'] = 50;
    $res = $fs->api()->reviveIfDue();
    eq(1, $res['active']);
    eq('active', key_status('KEY_AAAAAAAA'));
    eq('0', Settings::fresh('all_keys_exhausted_notified'));
    eq(null, $fs->api()->reviveIfDue(), 'chưa đủ 6 giờ thì không kiểm tra lại');
});

// ───────────────────────── GroupInfo ─────────────────────────

test('GroupInfo: lưu đủ JSON, giải mã tên, tự điền tên group chưa đặt', function () {
    $fs = new FakeScrape();
    $fs->infos['2018165638381128'] = [
        'id' => '2018165638381128',
        'url' => 'https://www.facebook.com/groups/2018165638381128',
        'name' => 'C&#x1ed9;ng &#x110;&#x1ed3;ng VPS Treo Game Gi&#xe1; R&#x1ebb;',
        'description' => 'Hơn 200 bài viết/ngày',
        'privacy' => ['label' => 'Public', 'description' => "Anyone can see who's in the group and what they post."],
        'visibility' => ['label' => 'Visible', 'description' => 'Anyone can find this group.'],
        'categories' => [], 'created_at' => '2022-12-19T02:38:09.000Z',
        'history_summary' => 'Group created on December 19, 2022.',
        'member_count' => 19424, 'member_count_text' => '19,424 total members',
        'administrator_count' => 2, 'moderator_count' => 0,
        'administrators' => [['id' => '100048815033135', 'name' => 'Pham Anh Nguyên', 'url' => 'https://www.facebook.com/pangaming07']],
        'moderators' => [],
        'activity' => ['posts_last_day' => 7, 'posts_last_month' => 30, 'new_members_text' => 'No new members in the last week'],
        'rules' => [['id' => '1', 'title' => 'Không spam', 'description' => 'Cấm spam link']],
        'about_info' => [['type' => 'XFBPrivacyGroupsAboutInfoItem', 'label' => 'Public', 'description' => '...']],
        'new_field_from_api' => ['x' => 1],
    ];
    $g = add_group('2018165638381128');
    $named = add_group('https://www.facebook.com/groups/named');
    Groups::update((int) $named['id'], ['name' => 'Tên tôi tự đặt']);
    $fs->infos['named'] = ['id' => '999', 'name' => 'Tên trên Facebook', 'member_count' => 5];
    add_key('KEY_AAAAAAAA');

    $info = GroupInfo::fetch($fs->api(), $g);
    eq('Cộng Đồng VPS Treo Game Giá Rẻ', $info['name']);
    eq('Cộng Đồng VPS Treo Game Giá Rẻ', Groups::find((int) $g['id'])['name'], 'group chưa đặt tên thì lấy tên thật');
    eq(19424, (int) $info['member_count']);
    eq(7, (int) $info['posts_last_day']);
    eq(30, (int) $info['posts_last_month']);
    eq('Public', $info['privacy']);
    eq('2022-12-19 09:38:09', $info['created_at_fb'], 'đổi sang giờ Việt Nam');
    $raw = json_decode($info['raw_json'], true);
    eq(['x' => 1], $raw['new_field_from_api'], 'giữ cả trường API mới thêm');
    eq('Không spam', $raw['rules'][0]['title']);
    eq('Cộng Đồng VPS Treo Game Giá Rẻ', GroupInfo::data($info)['name']);
    eq(1, count_of("SELECT COUNT(*) FROM api_calls WHERE endpoint = 'group_info' AND credits_charged = 1"));
    eq([], Estimator::avgPagesByGroup(), 'không tính vào số trang quét bài');

    GroupInfo::fetch($fs->api(), Groups::find((int) $named['id']));
    eq('Tên tôi tự đặt', Groups::find((int) $named['id'])['name'], 'không ghi đè tên người dùng tự đặt');
});

test('GroupInfo: lỗi được ghi lại, quét lại thành công thì xoá lỗi', function () {
    $fs = new FakeScrape();
    $g = add_group('https://www.facebook.com/groups/missing');
    add_key('KEY_AAAAAAAA');
    throws(ApiException::class, fn() => GroupInfo::fetch($fs->api(), $g), '404');
    $info = GroupInfo::find((int) $g['id']);
    check(str_contains((string) $info['last_error'], 'Group not found'));
    eq(null, $info['fetched_at']);
    eq('active', key_status('KEY_AAAAAAAA'), '404 không làm hỏng key');
    $fs->infos['missing'] = ['id' => '1', 'name' => 'Đã có', 'member_count' => 10];
    $info = GroupInfo::fetch($fs->api(), $g);
    eq(null, $info['last_error']);
    eq(10, (int) $info['member_count']);
});

// ───────────────────────── MessageFormatter ─────────────────────────

$group = ['fb_ref' => 'vpsmincom', 'name' => 'VPS <MIN> & Proxy'];

test('MessageFormatter: escape HTML', function () use ($group) {
    $m = MessageFormatter::post(['id' => 1, 'author_id' => '1', 'author_name' => '<script>A</script>', 'text' => 'Giá <b>rẻ</b> & "tốt"', 'url' => 'https://x.com/1', 'publish_time' => 1792254259], $group);
    check(str_contains($m['text'], '📌 <b>VPS &lt;MIN&gt; &amp; Proxy</b>'), $m['text']);
    check(str_contains($m['text'], '&lt;script&gt;A&lt;/script&gt;'));
    check(str_contains($m['text'], 'Giá &lt;b&gt;rẻ&lt;/b&gt; &amp; "tốt"'));
    check(str_contains($m['text'], '🕒 ' . date('H:i d/m', 1792254259)));
    check(!str_contains($m['text'], '<script>'));
});

test('MessageFormatter: cạnh tên group có số thành viên và số bài/tháng', function () use ($group) {
    $info = ['member_count' => 19424, 'posts_last_month' => 1230];
    $m = MessageFormatter::post(['text' => 'x'], $group, $info);
    eq('📌 <b>VPS &lt;MIN&gt; &amp; Proxy</b> · 👥 19.424 thành viên · 📝 1.230 bài/tháng', explode("\n", $m['text'])[0]);
    eq('📌 <b>VPS &lt;MIN&gt; &amp; Proxy</b>', explode("\n", MessageFormatter::post(['text' => 'x'], $group)['text'])[0], 'chưa có thông tin group thì chỉ có tên');
    eq(' · 👥 5 thành viên', MessageFormatter::groupStats(['member_count' => 5, 'posts_last_month' => null]));

    // Tin trong outbox lấy thông tin group đã quét
    $fs = new FakeScrape();
    $fs->feeds['g1'] = [fp('a1')];
    $fs->infos['g1'] = ['id' => '1', 'name' => 'Cộng Đồng VPS', 'member_count' => 33611, 'activity' => ['posts_last_day' => 0, 'posts_last_month' => 2]];
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    GroupInfo::fetch($fs->api(), $g);
    $fs->scanner()->scan(Groups::find((int) $g['id']), 1);
    $text = json_decode((string) Db::val('SELECT payload FROM outbox'), true)['text'];
    eq('📌 <b>Cộng Đồng VPS</b> · 👥 33.611 thành viên · 📝 2 bài/tháng', explode("\n", $text)[0]);
});

test('Bot: lúc gửi dựng lại tin theo thông tin group mới nhất', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = [fp('a1')];
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    $fs->scanner()->scan($g, 1); // lúc này group chưa có thông tin
    check(!str_contains((string) Db::val('SELECT payload FROM outbox'), 'thành viên'));
    $fs->infos['g1'] = ['id' => '1', 'name' => 'Chợ Proxy', 'member_count' => 1500, 'activity' => ['posts_last_month' => 45]];
    GroupInfo::fetch($fs->api(), Groups::find((int) $g['id'])); // quét thông tin sau khi bài đã vào hàng chờ
    $tg = new FakeTg();
    eq(1, $tg->bot()->sendOutbox());
    eq('📌 <b>Chợ Proxy</b> · 👥 1.500 thành viên · 📝 45 bài/tháng', explode("\n", $tg->calls('sendMessage')[0]['text'])[0]);
    eq('🔗 Mở bài viết', $tg->calls('sendMessage')[0]['reply_markup']['inline_keyboard'][0][0]['text']);
});

test('GroupScanner: lưu link ảnh/video của bài', function () {
    eq(null, GroupScanner::extractMedia(['image' => null]));
    eq(['https://f/1.jpg'], json_decode(GroupScanner::extractMedia(['image' => 'https://f/1.jpg']), true)['images'], '1 ảnh chỉ có key image');
    $m = json_decode(GroupScanner::extractMedia(['image' => 'https://f/thumb.jpg', 'images' => ['https://f/a.jpg', 'https://f/b.jpg']]), true);
    eq(['https://f/a.jpg', 'https://f/b.jpg'], $m['images'], 'nhiều ảnh dùng danh sách ảnh gốc');
    eq(10, count(json_decode(GroupScanner::extractMedia(['images' => array_map(fn($i) => "https://f/$i.jpg", range(1, 15))]), true)['images']));
    $v = json_decode(GroupScanner::extractMedia(['videoDetails' => ['sdUrl' => 'https://v/sd.mp4', 'hdUrl' => 'https://v/hd.mp4', 'thumbnailUrl' => 'https://v/t.jpg']]), true);
    eq(['sd' => 'https://v/sd.mp4', 'hd' => 'https://v/hd.mp4', 'thumb' => 'https://v/t.jpg'], $v['video']);
    eq(null, GroupScanner::extractMedia(['videoDetails' => []]), 'videoDetails rỗng thì không phải video');

    $p = MessageFormatter::post(['text' => null, 'media' => GroupScanner::extractMedia(['videoDetails' => ['thumbnailUrl' => 'https://v/t.jpg']])], ['fb_ref' => 'g']);
    eq(['https://v/t.jpg'], $p['photos'], 'video không có ảnh thì gửi ảnh bìa');
    check(str_contains($p['text'], 'Bài có video'));
    check(str_contains($p['text'], '(Bài chỉ có ảnh)'));
});

/** Tạo 1 bài có ảnh trong outbox cho chat 1, trả về id outbox. */
function queue_photo_post(array $images, ?string $text = 'Bán proxy giá rẻ'): int
{
    $g = add_group('https://www.facebook.com/groups/photo' . count($images) . strlen((string) $text));
    add_user(1);
    $media = GroupScanner::extractMedia(count($images) === 1 ? ['image' => $images[0]] : ['images' => $images]);
    $postId = Db::insert(
        "INSERT INTO posts (group_id, fb_post_id, author_id, author_name, text, url, media, first_seen_at, status) VALUES (?, 'p', 'u1', 'A', ?, 'https://fb/p', ?, ?, 'queued')",
        [(int) $g['id'], $text, $media, Clock::str()]
    );
    Outbox::enqueuePost(Db::one('SELECT * FROM posts WHERE id = ?', [$postId]), $g);
    return (int) Db::val('SELECT id FROM outbox ORDER BY id DESC LIMIT 1');
}

test('Bot: 1 ảnh + chữ ngắn → một tin ảnh có chú thích và nút', function () {
    $oid = queue_photo_post(['https://f/1.jpg']);
    $tg = new FakeTg();
    eq(1, $tg->bot()->sendOutbox());
    eq([], $tg->calls('sendMessage'));
    $p = $tg->calls('sendPhoto')[0];
    eq('https://f/1.jpg', $p['photo']);
    check(str_contains($p['caption'], 'Bán proxy giá rẻ'));
    eq('HTML', $p['parse_mode']);
    eq('🔗 Mở bài viết', $p['reply_markup']['inline_keyboard'][0][0]['text']);
    eq('sent', Db::val('SELECT status FROM outbox WHERE id = ?', [$oid]));
});

test('Bot: nhiều ảnh → album rồi tin chữ có nút; chữ dài + 1 ảnh → ảnh rồi tin chữ', function () {
    queue_photo_post(['https://f/a.jpg', 'https://f/b.jpg', 'https://f/c.jpg']);
    $tg = new FakeTg();
    eq(1, $tg->bot()->sendOutbox());
    eq(['https://f/a.jpg', 'https://f/b.jpg', 'https://f/c.jpg'], array_column($tg->calls('sendMediaGroup')[0]['media'], 'media'));
    eq(1, count($tg->calls('sendMessage')));
    check(isset($tg->calls('sendMessage')[0]['reply_markup']), 'nút nằm ở tin chữ');
    $order = array_map(fn($c) => basename(parse_url($c['url'], PHP_URL_PATH)), $tg->http->calls);
    eq(['sendMediaGroup', 'sendMessage'], $order, 'album trước, chữ sau');

    reset_db();
    queue_photo_post(['https://f/1.jpg'], str_repeat('Chữ rất dài. ', 120));
    $tg = new FakeTg();
    eq(1, $tg->bot()->sendOutbox());
    check(!isset($tg->calls('sendPhoto')[0]['caption']), 'chữ dài không làm chú thích');
    eq(1, count($tg->calls('sendMessage')));
});

test('Bot: ảnh lỗi (link hết hạn) vẫn gửi phần chữ; tắt cài đặt thì không gửi ảnh', function () {
    $oid = queue_photo_post(['https://f/a.jpg', 'https://f/b.jpg']);
    $tg = new FakeTg();
    $tg->responses['sendMediaGroup'] = [['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: failed to get HTTP URL content']];
    eq(1, $tg->bot()->sendOutbox());
    eq(1, count($tg->calls('sendMessage')));
    $row = Db::one('SELECT * FROM outbox WHERE id = ?', [$oid]);
    eq('sent', $row['status']);
    check(str_contains((string) $row['last_error'], 'Không gửi được ảnh'));

    reset_db();
    Settings::set('send_images', '0');
    queue_photo_post(['https://f/1.jpg']);
    $tg = new FakeTg();
    eq(1, $tg->bot()->sendOutbox());
    eq([], $tg->calls('sendPhoto'));
    eq(1, count($tg->calls('sendMessage')));
});

test('Bot: album đã gửi mà tin chữ bị 429 thì lần sau không gửi lặp album', function () {
    $oid = queue_photo_post(['https://f/a.jpg', 'https://f/b.jpg']);
    $tg = new FakeTg();
    $tg->responses['sendMessage'] = [['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 3]]];
    eq(0, $tg->bot()->sendOutbox());
    eq(1, (int) Db::val('SELECT media_sent FROM outbox WHERE id = ?', [$oid]));
    Clock::advance(5);
    eq(1, $tg->bot()->sendOutbox());
    eq(1, count($tg->calls('sendMediaGroup')), 'album chỉ gửi 1 lần');
    eq(2, count($tg->calls('sendMessage')));
});

test('MessageFormatter: markdown **đậm** của API thành <b>', function () use ($group) {
    $m = MessageFormatter::post(['text' => "Bên em có **Proxy & VPS** giá rẻ\n🌐 [https://proxyvps.vn](https://proxyvps.vn)", 'author_name' => 'A'], $group);
    check(str_contains($m['text'], '<b>Proxy &amp; VPS</b>'), $m['text']);
    check(str_contains($m['text'], '🌐 https://proxyvps.vn'), $m['text']);
});

test('MessageFormatter: cắt khi dài hơn 3500 ký tự', function () use ($group) {
    $m = MessageFormatter::post(['text' => str_repeat('Ạ', 5000), 'author_name' => 'A'], $group);
    check(str_contains($m['text'], str_repeat('Ạ', 3500) . '…'));
    check(!str_contains($m['text'], str_repeat('Ạ', 3501)));
    check(mb_strlen($m['text']) < 4096);
});

test('MessageFormatter: text null', function () use ($group) {
    $m = MessageFormatter::post(['text' => null, 'author_name' => null], $group);
    check(str_contains($m['text'], '(Bài không có chữ — mở link để xem)'));
    check(str_contains($m['text'], '👤 Ẩn danh'));
});

test('MessageFormatter: callback_data ≤ 64 byte', function () {
    eq('s:100090179152479', MessageFormatter::callbackData('mark', '100090179152479', 5));
    eq('us:100090179152479', MessageFormatter::callbackData('unmark', '100090179152479', 5));
    $long = str_repeat('x', 64);
    eq('sp:123', MessageFormatter::callbackData('mark', $long, 123));
    eq('usp:123', MessageFormatter::callbackData('unmark', $long, 123));
    eq(null, MessageFormatter::callbackData('mark', $long, null));
    foreach (MessageFormatter::keyboard('https://x.com', str_repeat('9', 62), false, 99999999)['inline_keyboard'] as $row) {
        foreach ($row as $b) {
            check(!isset($b['callback_data']) || strlen($b['callback_data']) <= 64, 'callback_data quá 64 byte');
        }
    }
});

test('MessageFormatter: nút trang cá nhân người đăng (ID số, pfbid, ẩn danh)', function () use ($group) {
    eq('https://www.facebook.com/profile.php?id=100090179152479', MessageFormatter::profileUrl('100090179152479', 'Tanny'));
    eq('https://www.facebook.com/people/Nguy%E1%BB%85n-V%C4%83n-Ng%E1%BB%8Dc/pfbid02Xz2rAKrm/', MessageFormatter::profileUrl('pfbid02Xz2rAKrm', ' Nguyễn  Văn Ngọc '));
    eq('https://www.facebook.com/people/user/pfbid02Xz2rAKrm/', MessageFormatter::profileUrl('pfbid02Xz2rAKrm', null));
    eq(null, MessageFormatter::profileUrl(null, 'Ẩn danh'));

    $m = MessageFormatter::post(['id' => 7, 'author_id' => '100090179152479', 'author_name' => 'Tanny', 'text' => 'x', 'url' => 'https://x.com/1'], $group);
    eq([['text' => '🔗 Mở bài viết', 'url' => 'https://x.com/1'], ['text' => '👤 Trang cá nhân', 'url' => 'https://www.facebook.com/profile.php?id=100090179152479']],
        $m['reply_markup']['inline_keyboard'][0], 'hai nút link cùng một hàng');
    eq('s:100090179152479', $m['reply_markup']['inline_keyboard'][1][0]['callback_data']);
});

test('Bot: đánh dấu seller vẫn giữ nút trang cá nhân', function () {
    Db::run("INSERT INTO posts (group_id, fb_post_id, author_id, author_name, first_seen_at, status) VALUES (1, 'p1', '100090179152479', 'Tanny', ?, 'queued')", [Clock::str()]);
    add_user(1);
    $tg = new FakeTg();
    $tg->bot()->handleUpdate(cb(1, 's:100090179152479'));
    $kb = last($tg->calls('editMessageReplyMarkup'))['reply_markup']['inline_keyboard'];
    eq('👤 Trang cá nhân', $kb[0][1]['text']);
    eq('https://www.facebook.com/profile.php?id=100090179152479', $kb[0][1]['url']);
    eq('us:100090179152479', $kb[1][0]['callback_data']);
});

test('MessageFormatter: không có nút seller khi author.id null', function () use ($group) {
    $m = MessageFormatter::post(['id' => 1, 'author_id' => null, 'text' => 'x', 'url' => 'https://x.com/1'], $group);
    eq([[['text' => '🔗 Mở bài viết', 'url' => 'https://x.com/1']]], $m['reply_markup']['inline_keyboard']);
    $m = MessageFormatter::post(['id' => 1, 'author_id' => '42', 'text' => 'x', 'url' => 'https://x.com/1'], $group);
    eq('s:42', $m['reply_markup']['inline_keyboard'][1][0]['callback_data']);
});

// ───────────────────────── Bot ─────────────────────────

test('Bot: /start thì hỏi mật khẩu', function () {
    $tg = new FakeTg();
    $tg->bot()->handleUpdate(msg(1, '/start'));
    eq('awaiting_password', user(1)['state']);
    check(str_contains($tg->lastText(), 'Nhập mật khẩu'), $tg->lastText());
});

test('Bot: nhập đúng cloudcheap thì authorized và tin mật khẩu bị xoá', function () {
    $tg = new FakeTg();
    $bot = $tg->bot();
    $bot->handleUpdate(msg(1, '/start', 10));
    $bot->handleUpdate(msg(1, 'cloudcheap', 11));
    eq('authorized', user(1)['state']);
    eq([['chat_id' => 1, 'message_id' => 11]], $tg->calls('deleteMessage'));
    check(str_contains($tg->lastText(), 'Đăng nhập thành công'));
    $bot->handleUpdate(msg(1, '/start', 12));
    check(str_contains($tg->lastText(), 'đã đăng nhập'), 'đã đăng nhập thì /start báo trạng thái');
});

test('Bot: sai 5 lần thì khoá 15 phút', function () {
    $tg = new FakeTg();
    $bot = $tg->bot();
    $bot->handleUpdate(msg(1, '/start'));
    for ($i = 0; $i < 4; $i++) {
        $bot->handleUpdate(msg(1, "sai$i", 20 + $i));
    }
    eq(null, user(1)['locked_until']);
    check(str_contains($tg->lastText(), 'còn 1 lần'), $tg->lastText());
    $bot->handleUpdate(msg(1, 'sai4', 24));
    eq('2026-10-18 09:15:00', user(1)['locked_until']);
    $bot->handleUpdate(msg(1, 'cloudcheap', 25));
    eq('awaiting_password', user(1)['state'], 'đang khoá thì mật khẩu đúng cũng không vào được');
    check(str_contains($tg->lastText(), 'quá nhiều'), $tg->lastText());
    Clock::set(new DateTimeImmutable('2026-10-18 09:15:01'));
    $bot->handleUpdate(msg(1, 'cloudcheap', 26));
    eq('authorized', user(1)['state'], 'hết khoá thì đăng nhập được');
});

test('Bot: /scan yêu cầu quét ngay và báo kết quả cho đúng người gọi', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = [fp('a2'), fp('a1')];
    add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    add_user(2);
    add_user(3, 'new');
    $tg = new FakeTg();
    $bot = $tg->bot();

    $bot->handleUpdate(msg(1, '/scan'));
    check(str_contains($tg->lastText(), 'Đang tắt quét'), 'tắt quét thì không chạy: ' . $tg->lastText());
    eq('0', Settings::fresh('run_now'));

    Settings::set('scan_enabled', '1');
    Clock::set(new DateTimeImmutable('2026-10-18 23:30:00')); // ngoài khung giờ
    $bot->handleUpdate(msg(3, '/scan'));
    eq('0', Settings::fresh('run_now'), 'chưa đăng nhập thì không được quét');
    $bot->handleUpdate(msg(1, '/scan'));
    eq('1', Settings::fresh('run_now'));
    check(str_contains($tg->lastText(), 'Đang quét ngay'));
    $bot->handleUpdate(msg(2, '/scan'));
    check(str_contains($tg->lastText(), 'đang chờ'), 'đã có yêu cầu thì không tạo thêm');

    eq('run_now', (new Scheduler($fs->scanner()))->tick());
    $alerts = Db::all("SELECT chat_id, payload FROM outbox WHERE kind = 'alert'");
    eq(1, count($alerts));
    eq(1, (int) $alerts[0]['chat_id']);
    check(str_contains($alerts[0]['payload'], 'Quét xong đợt #1: 1/1 group, 2 bài mới'), $alerts[0]['payload']);
    eq('', Settings::fresh('run_now_chat_id'));
});

test('Bot: người chưa đăng nhập không nhận outbox, không xem được /status', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = [fp('a2'), fp('a1')];
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    add_user(2, 'awaiting_password');
    add_user(3, 'revoked');
    $fs->scanner()->scan($g, 1);
    eq([1, 1], array_map('intval', Db::col('SELECT chat_id FROM outbox ORDER BY id')));

    $tg = new FakeTg();
    $bot = $tg->bot();
    eq(2, $bot->sendOutbox());
    eq([1, 1], array_map(static fn($c) => (int) $c['chat_id'], $tg->calls('sendMessage')));
    check(str_contains($tg->calls('sendMessage')[0]['text'], 'Nội dung bài'));
    eq('HTML', $tg->calls('sendMessage')[0]['parse_mode']);
    check(Clock::sleptSeconds() > 0, 'giãn cách giữa 2 tin cùng chat');

    $bot->handleUpdate(msg(3, '/status'));
    check(str_contains($tg->lastText(), 'Nhập mật khẩu'), 'người bị thu hồi chỉ nhận lời nhắc mật khẩu');
    $bot->handleUpdate(msg(1, '/status'));
    check(str_contains($tg->lastText(), 'Trạng thái'));
});

test('Bot: hai người cùng bấm seller không tạo trùng; tin chờ của seller bị huỷ', function () {
    $fs = new FakeScrape();
    $fs->feeds['g1'] = [fp('a2', 'u1'), fp('a1', 'u9')];
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    add_user(2);
    $fs->scanner()->scan($g, 1);
    eq(4, count_of("SELECT COUNT(*) FROM outbox WHERE status = 'pending'"));

    $tg = new FakeTg();
    $bot = $tg->bot();
    $bot->handleUpdate(cb(1, 's:u1'));
    eq(1, count_of('SELECT COUNT(*) FROM sellers'));
    eq(1, (int) Db::val("SELECT marked_by_chat_id FROM sellers WHERE author_id = 'u1'"));
    check(str_contains((string) last($tg->calls('answerCallbackQuery'))['text'], 'Đã chặn bài của Tên u1'));
    $edit = last($tg->calls('editMessageReplyMarkup'));
    eq('us:u1', $edit['reply_markup']['inline_keyboard'][1][0]['callback_data'], 'nút đổi thành Bỏ seller');
    eq('https://www.facebook.com/groups/g/permalink/1/', $edit['reply_markup']['inline_keyboard'][0][0]['url'], 'giữ nút mở bài');
    eq(2, count_of("SELECT COUNT(*) FROM outbox WHERE status = 'cancelled' AND last_error = 'seller'"), 'huỷ tin chờ của seller cho cả 2 người');

    $bot->handleUpdate(cb(2, 's:u1'));
    eq(1, count_of('SELECT COUNT(*) FROM sellers'));
    check(str_contains((string) last($tg->calls('answerCallbackQuery'))['text'], 'Đã là seller (do User1'), (string) last($tg->calls('answerCallbackQuery'))['text']);

    eq(2, $bot->sendOutbox(), 'chỉ còn bài của u9');
});

test('Bot: bỏ seller được và quét sau lại gửi bài người đó', function () {
    $tg = new FakeTg();
    $bot = $tg->bot();
    add_user(1);
    Sellers::mark('u1', 'Tên u1', 1);
    $bot->handleUpdate(cb(1, 'us:u1'));
    eq(0, count_of('SELECT COUNT(*) FROM sellers'));
    eq('s:u1', last($tg->calls('editMessageReplyMarkup'))['reply_markup']['inline_keyboard'][1][0]['callback_data']);
    check(str_contains((string) last($tg->calls('answerCallbackQuery'))['text'], 'Đã bỏ đánh dấu'));
});

test('Bot: tác giả có ID pfbid dài 64 ký tự dùng nút sp:<post_id> và vẫn chặn được', function () {
    $pfbid = 'pfbid02Xz2rAKrmhLRT89edW56HJTtpBwujeomZzhT8n9Tavjg92Mzzo2ng7168Z';
    eq(64, strlen($pfbid));
    $fs = new FakeScrape();
    $fs->feeds['g1'] = [fp('a1', $pfbid)];
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    $fs->scanner()->scan($g, 1);
    $postId = (int) Db::val("SELECT id FROM posts WHERE fb_post_id = 'a1'");
    $payload = json_decode((string) Db::val('SELECT payload FROM outbox'), true);
    eq("sp:$postId", $payload['reply_markup']['inline_keyboard'][1][0]['callback_data']);

    $tg = new FakeTg();
    $tg->bot()->handleUpdate(cb(1, "sp:$postId"));
    eq($pfbid, Db::val('SELECT author_id FROM sellers'));
    eq("usp:$postId", last($tg->calls('editMessageReplyMarkup'))['reply_markup']['inline_keyboard'][1][0]['callback_data']);
    $fs->feeds['g1'] = [fp('a3', $pfbid), fp('a2', $pfbid), fp('a1', $pfbid)];
    $r = $fs->scanner()->scan(Groups::find((int) $g['id']), 2);
    eq(2, $r['skipped_seller'], 'bài sau của cùng pfbid bị chặn');
    $tg->bot()->handleUpdate(cb(1, "usp:$postId"));
    eq(0, count_of('SELECT COUNT(*) FROM sellers'));
});

test('Sellers: seller lưu ID pfbid bị cắt 64 ký tự vẫn chặn được bài có ID đầy đủ', function () {
    $full = 'pfbid02Xz2rAKrmhLRT89edW56HJTtpBwujeomZzhT8n9Tavjg92Mzzo2ng7168Z1PSoLyel';
    $short = substr($full, 0, 64);
    Db::run('INSERT INTO sellers (author_id, author_name, marked_at) VALUES (?, ?, ?)', [$short, 'Khanh Cao', Clock::str()]);
    eq(true, Sellers::isSeller($full));
    eq(true, Sellers::isSeller($short));
    eq(false, Sellers::isSeller('pfbid0ZZZ2rAKrmhLRT89edW56HJTtpBwujeomZzhT8n9Tavjg92Mzzo2ng7168Z1PSoLyel'), 'khác trong 64 ký tự đầu thì không khớp');
    eq(false, Sellers::isSeller('100090179152479'));

    $fs = new FakeScrape();
    $fs->feeds['g1'] = [fp('a1', $full)];
    $g = add_group('https://www.facebook.com/groups/g1');
    add_key('KEY_AAAAAAAA');
    add_user(1);
    eq(1, $fs->scanner()->scan($g, 1)['skipped_seller']);
    Migrator::run();
    eq($full, Db::val('SELECT author_id FROM sellers'), 'migrate đổi sang ID đầy đủ');
});

test('Bot: người chưa đăng nhập bấm nút seller thì bị từ chối', function () {
    $tg = new FakeTg();
    add_user(5, 'new');
    $tg->bot()->handleUpdate(cb(5, 's:u1'));
    eq(0, count_of('SELECT COUNT(*) FROM sellers'));
    eq(true, last($tg->calls('answerCallbackQuery'))['show_alert']);
});

test('Bot: 429 thì đặt not_before theo retry_after', function () {
    add_user(1);
    Outbox::enqueueAlert('tin 1');
    Outbox::enqueueAlert('tin 2');
    $tg = new FakeTg();
    $tg->responses['sendMessage'] = [['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7]]];
    eq(0, $tg->bot()->sendOutbox());
    eq(1, count($tg->calls('sendMessage')), 'dừng gửi tiếp cho chat đó');
    eq(['2026-10-18 09:00:07', '2026-10-18 09:00:07'], Db::col("SELECT not_before FROM outbox WHERE status = 'pending' ORDER BY id"));
    eq(false, Outbox::hasReady());
    Clock::set(new DateTimeImmutable('2026-10-18 09:00:07'));
    eq(2, $tg->bot()->sendOutbox());
});

test('Bot: 403 (người dùng chặn bot) thì blocked_bot=1 và bỏ tin chờ', function () {
    add_user(1);
    add_user(2);
    Outbox::enqueueAlert('tin');
    $tg = new FakeTg();
    $tg->responses['sendMessage'] = [['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user']];
    eq(1, $tg->bot()->sendOutbox());
    eq(1, (int) user(1)['blocked_bot']);
    eq('cancelled', Db::val('SELECT status FROM outbox WHERE chat_id = 1'));
    eq([2], Outbox::authorizedChats(), 'không tạo tin mới cho người đã chặn bot');
    $tg->bot()->handleUpdate(msg(1, '/status'));
    eq(0, (int) user(1)['blocked_bot'], 'nhắn lại bot thì bỏ cờ chặn');
});

test('Bot: lỗi khác thì thử lại tối đa 3 lần rồi failed', function () {
    add_user(1);
    Outbox::enqueueAlert('tin');
    $tg = new FakeTg();
    $err = ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: something'];
    $tg->responses['sendMessage'] = [$err, $err, $err];
    $bot = $tg->bot();
    for ($i = 0; $i < 3; $i++) {
        $bot->sendOutbox();
        Clock::advance(120);
    }
    $row = Db::one('SELECT * FROM outbox');
    eq('failed', $row['status']);
    eq(3, (int) $row['attempts']);
});

// ───────────────────────── Estimator ─────────────────────────

test('Estimator: khung 6h–22h, nghỉ 30 phút ≈ 29 đợt/ngày (ví dụ mục 6.7)', function () {
    Settings::set('rest_minutes', '30');
    eq(960, Estimator::windowMinutes('06:00', '22:00'));
    eq(360, Estimator::windowMinutes('20:00', '02:00'));
    check(abs(Estimator::cyclesPerDay() - 960 / 33) < 0.01);
    $g1 = add_group('https://www.facebook.com/groups/g1', ['n' => 1, 'pages' => 2]);
    $g6 = add_group('https://www.facebook.com/groups/g6', ['n' => 6, 'pages' => 5]);
    $rows = Estimator::forGroups([$g1, $g6]);
    check(abs($rows[0]['credits_day'] - 960 / 33 * 1.3) < 0.01, 'N=1: lượt/ngày × 1,3');
    check(abs($rows[0]['credits_ceiling'] - 960 / 33 * 2) < 0.01);
    check(abs($rows[1]['scans_per_day'] - 960 / 33 / 6) < 0.01);
    eq(29, Estimator::cyclesPer24h());
});

// ───────────────────────── Db / Migrate ─────────────────────────

test('Db: tự nối lại sau KILL CONNECTION_ID()', function () {
    $id = (int) Db::val('SELECT CONNECTION_ID()');
    $other = Db::connect();
    $other->exec("KILL $id");
    eq(1, (int) Db::val('SELECT 1'));
    check((int) Db::val('SELECT CONNECTION_ID()') !== $id, 'phải là kết nối mới');
    Settings::set('cycle_no', 5);
    eq(5, Settings::int('cycle_no'));
});

test('Migrate: chạy lại 2 lần không lỗi, không mất dữ liệu', function () {
    Sellers::mark('keep', 'Giữ', null);
    Settings::set('rest_minutes', '45');
    Settings::set('admin_password_hash', password_hash('admin-cu', PASSWORD_DEFAULT));
    $hash = Settings::fresh('admin_password_hash');
    Migrator::run();
    Migrator::run();
    eq(1, count_of("SELECT COUNT(*) FROM sellers WHERE author_id = 'keep'"));
    eq('45', Settings::fresh('rest_minutes'));
    eq($hash, Settings::fresh('admin_password_hash'), 'không đổi mật khẩu admin đã có');
});

// ───────────────────────── Chạy ─────────────────────────

$filter = $argv[1] ?? '';
$messages = Migrator::run('test-admin');
$pass = 0;
$fail = 0;
$started = microtime(true);
foreach ($TESTS as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    reset_db();
    try {
        $fn();
        $pass++;
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $fail++;
        $where = $e instanceof TestFailure ? '' : ' (' . $e::class . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        echo "  ✗ $name\n      → " . $e->getMessage() . "$where\n";
    }
}
Clock::set(null);
printf("\n%d pass, %d fail (%.1fs) · DB %s\n", $pass, $fail, microtime(true) - $started, Db::name());
exit($fail === 0 ? 0 : 1);
