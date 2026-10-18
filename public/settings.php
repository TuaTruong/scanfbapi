<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
Auth::requireLogin();

$form = [
    'groups' => Groups::textareaValue(),
    'keys' => ApiKeys::textareaValue(),
    'window_start' => (string) Settings::get('window_start'),
    'window_end' => (string) Settings::get('window_end'),
    'rest_minutes' => (string) Settings::get('rest_minutes'),
    'max_pages_default' => (string) Settings::get('max_pages_default'),
    'max_post_age_hours' => (string) Settings::get('max_post_age_hours'),
    'sort_by' => ScrapeCreators::sortBy(),
    'dedupe_days' => (string) Settings::get('dedupe_days'),
];
$dedupeOptions = [
    'forever' => 'Không bao giờ gửi lại (mặc định)',
    '30' => 'Không gửi lại trong 30 ngày',
    '7' => 'Không gửi lại trong 7 ngày',
    '1' => 'Không gửi lại trong 24 giờ',
    '0' => 'Tắt — vẫn gửi bài trùng nội dung',
];
$errors = [];
$tokenCheck = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $do = (string) ($_POST['do'] ?? 'save');
    foreach (array_keys($form) as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $postedToken = trim((string) ($_POST['bot_token'] ?? ''));

    if ($do === 'test_token') {
        $token = $postedToken !== '' ? $postedToken : (string) Settings::get('bot_token');
        if ($token === '') {
            $tokenCheck = ['danger', 'Chưa nhập bot token.'];
        } else {
            $me = (new Telegram(new CurlHttpClient(), $token))->getMe();
            $tokenCheck = !empty($me['ok'])
                ? ['success', '✅ Token đúng: bot <b>@' . h($me['result']['username']) . '</b> (' . h($me['result']['first_name']) . ')' . ($postedToken !== '' ? '. Bấm Lưu để dùng token này.' : '')]
                : ['danger', '❌ Token không dùng được: ' . h($me['description'] ?? 'lỗi không rõ')];
        }
    } elseif ($do === 'change_admin_password') {
        $new = (string) ($_POST['admin_password_new'] ?? '');
        if (strlen($new) < 6) {
            flash('danger', 'Mật khẩu admin mới phải từ 6 ký tự.');
        } elseif ($new !== (string) ($_POST['admin_password_confirm'] ?? '')) {
            flash('danger', 'Hai lần nhập mật khẩu admin không khớp.');
        } else {
            Settings::set('admin_password_hash', password_hash($new, PASSWORD_DEFAULT));
            flash('success', 'Đã đổi mật khẩu admin web.');
        }
        redirect('settings.php');
    } else {
        // Kiểm tra các ô đơn giản trước khi lưu bất cứ thứ gì.
        foreach (['window_start' => 'Giờ bắt đầu', 'window_end' => 'Giờ kết thúc'] as $k => $label) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $form[$k])) {
                $errors[] = "$label phải có dạng HH:MM.";
            }
        }
        if (!ctype_digit($form['rest_minutes']) || (int) $form['rest_minutes'] < 5) {
            $errors[] = 'Thời gian nghỉ tối thiểu 5 phút.';
        }
        $pagesDefault = (int) $form['max_pages_default'];
        if ($pagesDefault < 1 || $pagesDefault > Groups::MAX_PAGES_LIMIT) {
            $errors[] = 'Số trang mặc định phải từ 1 đến ' . Groups::MAX_PAGES_LIMIT . '.';
        }
        if (!isset($dedupeOptions[$form['dedupe_days']])) {
            $errors[] = 'Lựa chọn chống trùng nội dung không hợp lệ.';
        }
        if (!isset(ScrapeCreators::SORT_OPTIONS[$form['sort_by']])) {
            $errors[] = 'Kiểu sắp xếp bài (sort_by) không hợp lệ.';
        }
        if (!ctype_digit($form['max_post_age_hours']) || (int) $form['max_post_age_hours'] > 2160) {
            $errors[] = 'Tuổi bài tối đa phải từ 0 đến 2160 giờ (0 = không lọc).';
        }
        if ($postedToken !== '' && !preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $postedToken)) {
            $errors[] = 'Bot token sai định dạng (dạng đúng: 123456789:AAH...).';
        }
        $botPassword = (string) ($_POST['bot_password'] ?? '');
        if ($botPassword !== '' && mb_strlen($botPassword) < 4) {
            $errors[] = 'Mật khẩu bot phải từ 4 ký tự.';
        }

        if (!$errors) {
            Settings::set('window_start', $form['window_start']);
            Settings::set('window_end', $form['window_end']);
            Settings::set('rest_minutes', (int) $form['rest_minutes']);
            Settings::set('max_pages_default', $pagesDefault);
            Settings::set('max_post_age_hours', (int) $form['max_post_age_hours']);
            Settings::set('sort_by', $form['sort_by']);
            Settings::set('dedupe_days', $form['dedupe_days']);
            Settings::set('send_images', isset($_POST['send_images']) ? '1' : '0');
            if ($postedToken !== '' && $postedToken !== Settings::get('bot_token')) {
                Settings::set('bot_token', $postedToken);
                Settings::set('bot_username', '');
                Settings::set('tg_offset', 0);
                flash('success', 'Đã lưu bot token mới. Bot sẽ kết nối lại ở vòng lặp kế tiếp.');
            }
            if ($botPassword !== '') {
                Settings::set('bot_password_hash', password_hash($botPassword, PASSWORD_DEFAULT));
                flash('success', 'Đã đổi mật khẩu bot. Người đã đăng nhập vẫn giữ quyền (thu hồi ở trang Người dùng TG).');
            }

            $g = Groups::syncFromText($form['groups']);
            $groupMsg = sprintf('Group: đã thêm %d, bỏ %d, thêm lại %d, trùng %d.', $g['added'], $g['removed'], $g['reactivated'], $g['duplicates']);
            if ($g['errors']) {
                $errors = array_merge($errors, $g['errors']);
            }
            flash($g['errors'] ? 'warning' : 'success', h($groupMsg) . ($g['errors'] ? ' Có ' . count($g['errors']) . ' dòng lỗi bị bỏ qua (xem bên dưới).' : ''));

            $oldKeys = ApiKeys::textareaValue();
            $k = ApiKeys::syncFromText($form['keys']);
            $keyMsg = sprintf('API key: đã thêm %d, bỏ %d, trùng %d.', $k['added'], $k['removed'], $k['duplicates']);
            if ($k['errors']) {
                $errors = array_merge($errors, $k['errors']);
            }
            if ($oldKeys !== ApiKeys::textareaValue() || $k['added'] > 0) {
                $c = (new ScrapeCreators(new CurlHttpClient()))->refreshKeys(false);
                $keyMsg .= sprintf(' Kiểm tra (miễn phí): %d hoạt động, %d hết credit, %d sai key%s.',
                    $c['active'], $c['exhausted'], $c['invalid'], $c['unknown'] ? ', ' . $c['unknown'] . ' lỗi mạng' : '');
            }
            flash($k['errors'] ? 'warning' : 'success', h($keyMsg));

            if ($do === 'apply_pages_all') {
                $n = Db::exec('UPDATE fb_groups SET max_pages = ?, updated_at = ? WHERE in_list = 1', [$pagesDefault, Clock::str()]);
                flash('success', "Đã đặt $pagesDefault trang cho $n group.");
            }
            if (!$errors) {
                redirect('settings.php');
            }
            // Có dòng lỗi: hiển thị lại đúng nội dung người dùng vừa nhập để sửa.
        }
    }
}

$botTokenSaved = (string) Settings::get('bot_token');
page_header('Cài đặt', 'settings');
?>
<h1 class="h4 mb-3">Cài đặt</h1>
<?php if ($errors): ?>
  <div class="alert alert-danger"><b>Cần sửa:</b><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($tokenCheck): ?><div class="alert alert-<?= $tokenCheck[0] ?>"><?= $tokenCheck[1] ?></div><?php endif; ?>

<form method="post" class="row g-4">
  <?= Auth::csrfField() ?>
  <div class="col-lg-6">
    <label class="form-label fw-semibold" for="groups">Danh sách group (link hoặc UID)</label>
    <textarea class="form-control font-monospace" id="groups" name="groups" rows="12" placeholder="https://www.facebook.com/groups/vpsmincom&#10;742354120555345"><?= h($form['groups']) ?></textarea>
    <div class="form-text">Mỗi dòng 1 group. Xoá dòng thì group ngừng quét nhưng vẫn giữ lịch sử bài đã thấy (thêm lại không gửi trùng). Cài đặt riêng từng group (N đợt, số trang, tên) ở trang <a href="groups.php">Groups</a>.</div>
  </div>
  <div class="col-lg-6">
    <label class="form-label fw-semibold" for="keys">API key ScrapeCreators</label>
    <textarea class="form-control font-monospace" id="keys" name="keys" rows="6" spellcheck="false" autocomplete="off"><?= h($form['keys']) ?></textarea>
    <div class="form-text">Mỗi dòng 1 key, thứ tự dòng = thứ tự ưu tiên. Key hết credit thì tự chuyển sang key kế tiếp. Khi lưu sẽ kiểm tra credit từng key (miễn phí).</div>

    <div class="row g-3 mt-1">
      <div class="col-6">
        <label class="form-label fw-semibold" for="window_start">Quét từ</label>
        <input class="form-control" type="time" id="window_start" name="window_start" value="<?= h($form['window_start']) ?>" required>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold" for="window_end">Đến</label>
        <input class="form-control" type="time" id="window_end" name="window_end" value="<?= h($form['window_end']) ?>" required>
      </div>
      <div class="col-12 form-text mt-1">Cho phép qua nửa đêm (ví dụ 20:00 → 02:00). Từ = Đến nghĩa là quét cả ngày. Đợt chỉ <i>bắt đầu</i> trong khung giờ.</div>
      <div class="col-6">
        <label class="form-label fw-semibold" for="rest_minutes">Nghỉ giữa các đợt (phút)</label>
        <input class="form-control" type="number" min="5" step="1" id="rest_minutes" name="rest_minutes" value="<?= h($form['rest_minutes']) ?>" required>
        <div class="form-text">Tính từ lúc đợt trước kết thúc. Tối thiểu 5.</div>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold" for="max_pages_default">Số trang mặc định cho group mới</label>
        <select class="form-select" id="max_pages_default" name="max_pages_default">
          <?php for ($p = 1; $p <= Groups::MAX_PAGES_LIMIT; $p++): ?>
            <option value="<?= $p ?>" <?= (int) $form['max_pages_default'] === $p ? 'selected' : '' ?>><?= h(pages_label($p)) ?></option>
          <?php endfor; ?>
        </select>
        <button class="btn btn-link btn-sm px-0" name="do" value="apply_pages_all" onclick="return confirm('Lưu và đặt số trang này cho TẤT CẢ group?')">Lưu và áp dụng cho tất cả group</button>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold" for="max_post_age_hours">Bỏ qua bài đăng cũ hơn (giờ)</label>
        <input class="form-control" type="number" min="0" max="2160" step="1" id="max_post_age_hours" name="max_post_age_hours" value="<?= h($form['max_post_age_hours']) ?>" required>
        <div class="form-text">API đôi khi trả nhầm cả trang bài từ nhiều tuần trước; bài đăng cũ hơn mốc này được ghi nhận nhưng không gửi. Group duyệt bài chậm thì để lớn hơn thời gian chờ duyệt. 0 = không lọc.</div>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold" for="sort_by">Kiểu sắp xếp bài khi quét (sort_by)</label>
        <select class="form-select" id="sort_by" name="sort_by">
          <?php foreach (ScrapeCreators::SORT_OPTIONS as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $form['sort_by'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Scanner dừng lật trang khi gặp bài đã thấy, nên cách này chỉ chính xác với <b>Mới đăng</b>. Kiểu khác có thể làm sót bài mới hoặc đẩy lên bài cũ; bài cũ hơn mốc giờ bên cạnh vẫn bị chặn.</div>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold" for="dedupe_days">Bài trùng nội dung (đăng lại, đăng nhiều group)</label>
        <select class="form-select" id="dedupe_days" name="dedupe_days">
          <?php foreach ($dedupeOptions as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $form['dedupe_days'] === (string) $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-check form-switch mt-3">
          <input class="form-check-input" type="checkbox" role="switch" id="send_images" name="send_images" value="1" <?= Settings::get('send_images', '1') === '1' ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="send_images">Gửi kèm ảnh của bài lên Telegram</label>
          <div class="form-text">1 ảnh + chữ ngắn: một tin có ảnh, chữ làm chú thích. Nhiều ảnh (tối đa 10) hoặc chữ dài: album ảnh rồi đến tin chữ có nút. Không tốn thêm credit.</div>
        </div>
        <div class="form-text">Mỗi lần đăng lại hoặc đăng sang group khác, Facebook tạo bài mới với ID mới. Bài có nội dung đã xuất hiện ở lần quét trước sẽ không gửi nữa (so bỏ qua dấu cách, emoji, chữ hoa). Nội dung ngắn dưới 30 chữ cái chỉ tính trùng khi cùng người đăng.</div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <label class="form-label fw-semibold" for="bot_token">Bot token Telegram</label>
    <div class="input-group">
      <input class="form-control font-monospace" type="password" id="bot_token" name="bot_token" autocomplete="off"
             placeholder="<?= $botTokenSaved ? 'Đã lưu: ' . h(InputParser::mask($botTokenSaved)) . ' — để trống nếu giữ nguyên' : '123456789:AAH...' ?>">
      <button class="btn btn-outline-secondary" name="do" value="test_token" formnovalidate>Kiểm tra</button>
    </div>
    <div class="form-text"><?= Settings::get('bot_username') ? 'Bot hiện tại: <b>@' . h(Settings::get('bot_username')) . '</b>. ' : '' ?>Lấy token từ @BotFather.</div>
  </div>
  <div class="col-lg-6">
    <label class="form-label fw-semibold" for="bot_password">Mật khẩu bot</label>
    <input class="form-control" type="password" id="bot_password" name="bot_password" autocomplete="new-password" placeholder="Để trống nếu giữ mật khẩu cũ">
    <div class="form-text">Một mật khẩu chung cho mọi người dùng: gửi /start cho bot rồi nhập mật khẩu này (mặc định <code>cloudcheap</code>). Đổi mật khẩu không đá người đã đăng nhập ra.</div>
  </div>

  <div class="col-12">
    <button class="btn btn-primary px-4" name="do" value="save">💾 Lưu cài đặt</button>
    <span class="text-secondary small ms-2">Thay đổi có hiệu lực ở vòng lặp kế tiếp của scanner/bot, không cần khởi động lại.</span>
  </div>
</form>

<hr class="my-5">
<h2 class="h5">Đổi mật khẩu admin web</h2>
<form method="post" class="row g-3" style="max-width:640px">
  <?= Auth::csrfField() ?>
  <div class="col-sm-6"><input class="form-control" type="password" name="admin_password_new" placeholder="Mật khẩu mới" autocomplete="new-password" required minlength="6"></div>
  <div class="col-sm-6"><input class="form-control" type="password" name="admin_password_confirm" placeholder="Nhập lại" autocomplete="new-password" required minlength="6"></div>
  <div class="col-12"><button class="btn btn-outline-primary" name="do" value="change_admin_password">Đổi mật khẩu admin</button></div>
</form>
<?php page_footer();
