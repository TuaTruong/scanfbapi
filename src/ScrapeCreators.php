<?php
declare(strict_types=1);

/**
 * Client ScrapeCreators + xoay vòng API key (KeyPool = ApiKeys::current()).
 *
 * Key lỗi 401 → invalid; hết credit → exhausted; cả hai đều chuyển ngay sang key kế tiếp.
 * Lỗi phía server (5xx, timeout) thì thử lại trên CÙNG key vì không phải lỗi key.
 */
final class ScrapeCreators
{
    public const BASE_URL = 'https://api.scrapecreators.com';
    public const DEFAULT_SORT = 'CHRONOLOGICAL';
    /** Giá trị sort_by theo OpenAPI của /v1/facebook/group/posts → nhãn hiển thị trên trang Cài đặt. */
    public const SORT_OPTIONS = [
        'CHRONOLOGICAL' => 'Mới đăng (CHRONOLOGICAL) — mặc định, khuyên dùng',
        'RECENT_ACTIVITY' => 'Hoạt động gần đây (RECENT_ACTIVITY) — bài cũ có bình luận mới cũng nổi lên',
        'TOP_POSTS' => 'Nổi bật (TOP_POSTS) — theo tương tác, không theo thời gian',
        'CHRONOLOGICAL_LISTINGS' => 'Bài rao bán (CHRONOLOGICAL_LISTINGS) — khi test 17/10 API trả lỗi 500',
    ];
    private const SERVER_RETRY_WAITS = [5, 15];
    private const RATE_LIMIT_WAIT = 10;
    private const REVIVE_EVERY_HOURS = 6;

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Lấy một trang bài viết của group.
     * @return array{posts: list<array>, cursor: ?string, credits_remaining: ?int}
     * @throws AllKeysExhaustedException|ApiException
     */
    public function groupPosts(array $group, ?string $cursor = null, ?int $runId = null): array
    {
        $params = self::groupParams($group) + ['sort_by' => self::sortBy()];
        if ($cursor !== null && $cursor !== '') {
            $params['cursor'] = $cursor;
        }
        $data = $this->request('/v1/facebook/group/posts', $params, 'group_posts', isset($group['id']) ? (int) $group['id'] : null, $runId);
        $next = $data['cursor'] ?? null;
        return [
            'posts' => is_array($data['posts'] ?? null) ? array_values($data['posts']) : [],
            'cursor' => is_string($next) && $next !== '' ? $next : null,
            'credits_remaining' => isset($data['credits_remaining']) ? (int) $data['credits_remaining'] : null,
        ];
    }

    /**
     * Thông tin trang "Giới thiệu" của group (1 credit): tên, mô tả, quyền riêng tư, số thành viên,
     * admin/kiểm duyệt, hoạt động, nội quy... Trả nguyên mảng JSON của API.
     * @throws AllKeysExhaustedException|ApiException
     */
    public function groupInfo(array $group): array
    {
        return $this->request('/v1/facebook/group', self::groupParams($group), 'group_info', isset($group['id']) ? (int) $group['id'] : null, null);
    }

    private static function groupParams(array $group): array
    {
        return $group['ref_type'] === 'group_id'
            ? ['group_id' => (string) $group['fb_ref']]
            : ['url' => InputParser::groupUrl((string) $group['fb_ref'])];
    }

    /**
     * Gọi một endpoint có xoay vòng key. Thành công thì trả JSON đã giải mã.
     * @throws AllKeysExhaustedException|ApiException
     */
    private function request(string $path, array $params, string $endpoint, ?int $groupId, ?int $runId): array
    {
        $url = self::BASE_URL . $path . '?' . http_build_query($params);
        $serverRetries = 0;
        $rateLimitRetried = false;
        while (true) {
            $key = ApiKeys::current();
            if ($key === null) {
                throw new AllKeysExhaustedException('Không còn API key nào dùng được');
            }
            $keyId = (int) $key['id'];
            $res = $this->http->request('GET', $url, ['x-api-key: ' . $key['api_key']], null, 60);
            $data = $res->json();

            if ($res->status === 200 && is_array($data) && ($data['success'] ?? false) === true) {
                $credits = isset($data['credits_remaining']) ? (int) $data['credits_remaining'] : null;
                $charged = isset($data['credits_charged']) ? (int) $data['credits_charged'] : 1;
                $posts = is_array($data['posts'] ?? null) ? count($data['posts']) : null;
                self::logCall($runId, $keyId, $groupId, $endpoint, $res, $charged, $posts, null);
                ApiKeys::recordUse($keyId, $credits);
                return $data;
            }

            $message = self::errorMessage($res, $data);
            self::logCall($runId, $keyId, $groupId, $endpoint, $res, null, null, self::rawSnippet($res));

            if ($res->status === 401) {
                ApiKeys::setStatus($keyId, 'invalid', $message);
                continue;
            }
            if ($res->status === 402) {
                ApiKeys::setStatus($keyId, 'exhausted', $message);
                continue;
            }
            $mentionsCredit = stripos($res->body, 'credit') !== false;
            if ($res->status === 403 || ($mentionsCredit && $res->status >= 400 && $res->status !== 429)
                || ($res->status === 200 && $mentionsCredit)) {
                // Có thể là hết credit. Kiểm tra lại bằng credit-balance (miễn phí) trước khi bỏ key,
                // tránh trường hợp 403 do group private làm "hết" lần lượt mọi key.
                $balance = $this->creditBalance((string) $key['api_key'], $keyId);
                if ($balance['status'] === 401) {
                    ApiKeys::setStatus($keyId, 'invalid', $message);
                    continue;
                }
                if ($balance['credits'] === null || $balance['credits'] <= 0) {
                    ApiKeys::setStatus($keyId, 'exhausted', $message);
                    continue;
                }
                throw new ApiException($message, $res->status);
            }
            if ($res->status === 429 && !$rateLimitRetried) {
                $rateLimitRetried = true;
                Clock::sleep(self::RATE_LIMIT_WAIT);
                continue;
            }
            if (($res->status === 0 || $res->status >= 500) && $serverRetries < count(self::SERVER_RETRY_WAITS)) {
                Clock::sleep(self::SERVER_RETRY_WAITS[$serverRetries++]);
                continue;
            }
            throw new ApiException($message, $res->status);
        }
    }

    /** sort_by đang chọn ở trang Cài đặt; giá trị lạ thì dùng mặc định. */
    public static function sortBy(): string
    {
        $sort = (string) Settings::get('sort_by', self::DEFAULT_SORT);
        return isset(self::SORT_OPTIONS[$sort]) ? $sort : self::DEFAULT_SORT;
    }

    /**
     * Số dư credit (miễn phí).
     * @return array{status: int, credits: ?int, error: ?string}
     */
    public function creditBalance(string $apiKey, ?int $keyId = null): array
    {
        $res = $this->http->request('GET', self::BASE_URL . '/v1/account/credit-balance', ['x-api-key: ' . $apiKey], null, 30);
        $data = $res->json();
        $credits = (is_array($data) && isset($data['creditCount'])) ? (int) $data['creditCount'] : null;
        $error = ($res->status === 200 && $credits !== null) ? null : self::errorMessage($res, $data);
        self::logCall(null, $keyId, null, 'credit_balance', $res, 0, null, $error === null ? null : self::rawSnippet($res));
        return ['status' => $res->status, 'credits' => $credits, 'error' => $error];
    }

    /**
     * Kiểm tra credit một key và cập nhật trạng thái.
     * @return string trạng thái mới: active | exhausted | invalid | unknown (lỗi mạng)
     */
    public function refreshKey(array $key): string
    {
        $b = $this->creditBalance((string) $key['api_key'], (int) $key['id']);
        if ($b['status'] === 401) {
            ApiKeys::setStatus((int) $key['id'], 'invalid', $b['error']);
            return 'invalid';
        }
        if ($b['status'] === 200 && $b['credits'] !== null) {
            ApiKeys::setCredits((int) $key['id'], $b['credits']);
            $status = $b['credits'] > 0 ? 'active' : 'exhausted';
            ApiKeys::setStatus((int) $key['id'], $status, $status === 'exhausted' ? 'creditCount = 0' : null);
            return $status;
        }
        Db::run('UPDATE api_keys SET last_error = ?, last_checked_at = ? WHERE id = ?', [$b['error'], Clock::str(), $key['id']]);
        return 'unknown';
    }

    /**
     * Kiểm tra credit các key (mặc định: chỉ key exhausted để hồi sinh).
     * @return array<string, int> đếm theo trạng thái
     */
    public function refreshKeys(bool $onlyExhausted = true): array
    {
        $sql = 'SELECT * FROM api_keys WHERE in_list = 1' . ($onlyExhausted ? " AND status = 'exhausted'" : '') . ' ORDER BY priority, id';
        $counts = ['active' => 0, 'exhausted' => 0, 'invalid' => 0, 'unknown' => 0];
        foreach (Db::all($sql) as $key) {
            $counts[$this->refreshKey($key)]++;
        }
        if (ApiKeys::current()) {
            Settings::set('all_keys_exhausted_notified', '0');
        }
        return $counts;
    }

    /** Mỗi 6 giờ thử hồi sinh key exhausted (nạp thêm tiền là bot tự dùng lại). */
    public function reviveIfDue(): ?array
    {
        $last = Clock::parse(Settings::get('last_key_revive_at'));
        if ($last && Clock::ts() - $last->getTimestamp() < self::REVIVE_EVERY_HOURS * 3600) {
            return null;
        }
        Settings::set('last_key_revive_at', Clock::str());
        if (!Db::val("SELECT 1 FROM api_keys WHERE in_list = 1 AND status = 'exhausted' LIMIT 1")) {
            return null;
        }
        return $this->refreshKeys(true);
    }

    private static function errorMessage(HttpResponse $res, ?array $data): string
    {
        if ($res->status === 0) {
            return 'Lỗi mạng/timeout: ' . ($res->error ?? 'không rõ');
        }
        $msg = is_array($data) ? (string) ($data['message'] ?? $data['error'] ?? '') : '';
        if ($msg === '') {
            $msg = mb_substr(trim(strip_tags($res->body)), 0, 200);
        }
        return 'HTTP ' . $res->status . ($msg !== '' ? ': ' . $msg : '');
    }

    private static function rawSnippet(HttpResponse $res): string
    {
        if ($res->status === 0) {
            return 'network: ' . ($res->error ?? '');
        }
        return 'HTTP ' . $res->status . ' ' . mb_substr($res->body, 0, 480);
    }

    private static function logCall(?int $runId, ?int $keyId, ?int $groupId, string $endpoint, HttpResponse $res, ?int $charged, ?int $posts, ?string $error): void
    {
        Db::run(
            'INSERT INTO api_calls (run_id, key_id, group_id, endpoint, http_code, credits_charged, duration_ms, posts_returned, error, called_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$runId, $keyId, $groupId, $endpoint, $res->status, $charged, $res->durationMs, $posts, $error === null ? null : mb_substr($error, 0, 500), Clock::str()]
        );
    }
}
