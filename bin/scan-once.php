<?php
// Test: quét một group và in kết quả. Không đụng tới lịch đợt.
// Dùng: php bin/scan-once.php <link group hoặc UID> [--pages=N] [--dry-run]
//   --dry-run: lưu bài với trạng thái baseline, KHÔNG đưa vào outbox (không gửi Telegram)
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$args = array_slice($argv, 1);
$target = null;
$pages = null;
$dryRun = false;
foreach ($args as $a) {
    if ($a === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--pages=(\d+)$/', $a, $m)) {
        $pages = (int) $m[1];
    } elseif (!str_starts_with($a, '--')) {
        $target = $a;
    }
}
if ($target === null) {
    fwrite(STDERR, "Dùng: php bin/scan-once.php <link group hoặc UID> [--pages=N] [--dry-run]\n");
    exit(1);
}

try {
    $parsed = InputParser::parseGroup($target);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$group = Groups::ensure($parsed);
$scanner = new GroupScanner(new ScrapeCreators(new CurlHttpClient()));
$started = microtime(true);
try {
    $r = $scanner->scan($group, null, null, $dryRun, $pages);
} catch (AllKeysExhaustedException $e) {
    fwrite(STDERR, "Không còn API key nào dùng được. Thêm key ở trang Cài đặt hoặc: php bin/setup.php --api-keys=KEY\n");
    exit(2);
}

echo sprintf(
    "Group %s (%s)%s%s\n",
    $group['fb_ref'],
    $group['ref_type'],
    $dryRun ? ' · dry-run' : '',
    $r['first_scan'] ? ' · lần quét đầu (chỉ 1 trang)' : ''
);
foreach ($r['posts'] as $p) {
    $text = preg_replace('/\s+/u', ' ', (string) ($p['text'] ?? '(không có chữ)'));
    echo sprintf(
        "  [%s] %s · %s · %s\n      %s\n      %s\n",
        $p['status'],
        $p['fb_post_id'],
        $p['author_name'] ?? 'Ẩn danh',
        $p['publish_time'] ? date('H:i d/m', (int) $p['publish_time']) : '?',
        mb_strimwidth($text, 0, 140, '…'),
        $p['url'] ?? ''
    );
}
echo sprintf(
    "Kết quả: %d lần gọi API · %d bài mới (%d đưa vào outbox, %d của seller, %d trùng nội dung)%s%s%s · %.1fs\n",
    $r['api_calls'],
    $r['new_posts'],
    $r['queued'],
    $r['skipped_seller'],
    $r['duplicate'],
    $r['stale'] ? ' · bỏ qua ' . $r['stale'] . ' bài quá cũ' : '',
    $r['cap_hit'] ? ' · ⚠️ chạm trần số trang, có thể còn bài' : '',
    $r['error'] !== null ? ' · LỖI: ' . $r['error'] : '',
    microtime(true) - $started
);
$key = ApiKeys::current();
if ($key) {
    echo 'Credit còn lại (key ' . InputParser::mask($key['api_key']) . '): ' . ($key['credits_remaining'] ?? '?') . "\n";
}
exit($r['error'] === null ? 0 : 3);
