<?php
// Các thao tác POST nhỏ: bật/tắt quét, quét ngay, kiểm tra credit, seller, thu hồi người dùng...
declare(strict_types=1);
require __DIR__ . '/_init.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
Auth::verifyCsrf();

$pages = ['index', 'settings', 'groups', 'keys', 'sellers', 'users', 'logs'];
$back = in_array($_POST['back'] ?? '', $pages, true) ? $_POST['back'] . '.php' : 'index.php';
$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    case 'toggle_scan':
        $on = !Settings::bool('scan_enabled');
        Settings::set('scan_enabled', $on ? '1' : '0');
        flash($on ? 'success' : 'secondary', $on ? '▶️ Đã bật quét. Scanner áp dụng ở vòng lặp kế tiếp (≤ 10 giây).' : '⏸ Đã tắt quét.');
        break;

    case 'run_now':
        if (!Settings::bool('scan_enabled')) {
            flash('warning', 'Đang tắt quét. Bật quét trước rồi bấm Quét ngay.');
            break;
        }
        Settings::set('run_now', '1');
        flash('info', '⚡ Đã yêu cầu quét ngay (bỏ qua khung giờ và thời gian nghỉ). Scanner sẽ chạy trong vài giây.');
        break;

    case 'check_credits':
        $counts = (new ScrapeCreators(new CurlHttpClient()))->refreshKeys(false);
        flash('info', sprintf(
            'Đã kiểm tra credit (miễn phí): %d hoạt động, %d hết credit, %d sai key%s.',
            $counts['active'], $counts['exhausted'], $counts['invalid'],
            $counts['unknown'] ? ', ' . $counts['unknown'] . ' lỗi mạng' : ''
        ));
        break;

    case 'reset_cap':
        Db::run('UPDATE fb_groups SET cap_hits = 0, last_cap_hit_at = NULL WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        flash('secondary', 'Đã bỏ cảnh báo "có thể sót bài".');
        break;

    case 'clear_pause':
        Db::run('UPDATE fb_groups SET consecutive_errors = 0, paused_until_cycle = NULL WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        flash('secondary', 'Đã gỡ tạm hoãn, group sẽ được quét ở đợt kế tiếp đến hạn.');
        break;

    case 'remove_group':
        $g = Groups::find((int) ($_POST['id'] ?? 0));
        $ok = $g && Groups::remove([(int) $g['id']]) > 0;
        flash($ok ? 'success' : 'warning', $ok
            ? '🗑 Đã xoá group <b>' . h(Groups::displayName($g)) . '</b> khỏi danh sách quét. Khôi phục được ở mục “Group đã xoá” cuối trang.'
            : 'Group không tồn tại hoặc đã bị xoá.');
        break;

    case 'restore_group':
        $g = Groups::find((int) ($_POST['id'] ?? 0));
        $ok = $g && Groups::restore([(int) $g['id']]) > 0;
        flash($ok ? 'success' : 'warning', $ok
            ? '↩️ Đã khôi phục group <b>' . h(Groups::displayName($g)) . '</b>, sẽ được quét từ đợt kế tiếp đến hạn.'
            : 'Không khôi phục được (group không tồn tại hoặc đang trong danh sách).');
        break;

    case 'purge_group':
        $g = Groups::find((int) ($_POST['id'] ?? 0));
        $ok = $g && Groups::purge([(int) $g['id']]) > 0;
        flash($ok ? 'secondary' : 'warning', $ok
            ? 'Đã xoá vĩnh viễn group <b>' . h(Groups::displayName($g)) . '</b> cùng lịch sử bài.'
            : 'Chỉ xoá vĩnh viễn được group đã xoá khỏi danh sách.');
        break;

    case 'unmark_seller':
        $aid = (string) ($_POST['author_id'] ?? '');
        $ok = Sellers::unmark($aid);
        flash($ok ? 'success' : 'warning', $ok ? 'Đã bỏ đánh dấu seller <code>' . h($aid) . '</code>.' : 'Người này không còn là seller.');
        break;

    case 'add_seller':
        $aid = trim((string) ($_POST['author_id'] ?? ''));
        if (preg_match('#facebook\.com/(?:profile\.php\?id=)?(\d{5,25})#', $aid, $m)) {
            $aid = $m[1];
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,191}$/', $aid)) {
            flash('danger', 'FB ID không hợp lệ.');
            break;
        }
        $name = trim((string) ($_POST['author_name'] ?? '')) ?: Sellers::latestAuthorName($aid);
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        $added = Sellers::mark($aid, $name, null, $note === null ? null : mb_substr($note, 0, 255));
        flash($added ? 'success' : 'warning', $added ? 'Đã thêm seller <code>' . h($aid) . '</code>.' : 'Người này đã là seller.');
        break;

    case 'revoke_user':
        $chatId = (int) ($_POST['chat_id'] ?? 0);
        Db::run("UPDATE tg_users SET state = 'revoked' WHERE chat_id = ?", [$chatId]);
        Outbox::cancelForChat($chatId, 'revoked');
        flash('secondary', 'Đã thu hồi quyền của chat ' . $chatId . '. Người này phải nhập lại mật khẩu.');
        break;

    case 'revoke_all':
        $n = Db::exec("UPDATE tg_users SET state = 'revoked' WHERE state IN ('authorized', 'awaiting_password')");
        Db::run("UPDATE outbox SET status = 'cancelled', last_error = 'revoked' WHERE status = 'pending'");
        flash('secondary', "Đã thu hồi $n người dùng.");
        break;

    default:
        flash('danger', 'Thao tác không hợp lệ.');
}
redirect($back);
