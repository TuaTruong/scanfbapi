<?php
// POST {csrf, id} → quét thông tin 1 group qua ScrapeCreators, trả JSON để trang Groups cập nhật hàng đó.
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/_group_info_view.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!Auth::check()) {
    respond(401, ['ok' => false, 'stop' => true, 'error' => 'Phiên đăng nhập đã hết, tải lại trang.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(Auth::csrfToken(), (string) ($_POST['csrf'] ?? ''))) {
    respond(400, ['ok' => false, 'stop' => true, 'error' => 'CSRF token sai, tải lại trang.']);
}
session_write_close(); // nhả khoá session để trang chạy được nhiều yêu cầu song song
set_time_limit(180);

$id = (int) ($_POST['id'] ?? 0);
$group = Groups::find($id);
if (!$group) {
    respond(404, ['ok' => false, 'error' => 'Không tìm thấy group.']);
}

$error = null;
$stop = false;
try {
    GroupInfo::fetch(new ScrapeCreators(new CurlHttpClient()), $group);
} catch (AllKeysExhaustedException) {
    $error = 'Không còn API key nào dùng được.';
    $stop = true;
} catch (ApiException $e) {
    $error = $e->getMessage();
}

$group = Groups::find($id);
$info = GroupInfo::find($id);
respond(200, [
    'ok' => $error === null,
    'stop' => $stop,
    'error' => $error,
    'id' => $id,
    'name' => (string) ($group['name'] ?? ''),
    'summary' => group_info_summary($info),
    'detail' => group_info_detail($info),
]);
