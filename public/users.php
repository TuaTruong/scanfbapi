<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
Auth::requireLogin();

$users = Db::all(
    "SELECT u.*, (SELECT COUNT(*) FROM outbox o WHERE o.chat_id = u.chat_id AND o.status = 'sent') AS sent
     FROM tg_users u ORDER BY (u.state = 'authorized') DESC, u.authorized_at DESC, u.created_at DESC"
);
$badges = ['authorized' => ['success', 'Đã đăng nhập'], 'awaiting_password' => ['warning', 'Đang nhập mật khẩu'],
    'new' => ['secondary', 'Chưa đăng nhập'], 'revoked' => ['dark', 'Đã thu hồi']];

page_header('Người dùng Telegram', 'users');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Người dùng Telegram</h1>
    <div class="text-secondary small">Người dùng gửi /start cho <?= Settings::get('bot_username') ? '<b>@' . h(Settings::get('bot_username')) . '</b>' : 'bot' ?> rồi nhập mật khẩu bot. Mỗi người đã đăng nhập nhận một bản tin.</div>
  </div>
  <form method="post" action="actions.php" onsubmit="return confirm('Thu hồi quyền của TẤT CẢ người dùng? Họ phải nhập lại mật khẩu.')"><?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="revoke_all"><input type="hidden" name="back" value="users">
    <button class="btn btn-outline-danger">Thu hồi tất cả</button></form>
</div>

<div class="table-responsive">
<table class="table table-hover align-middle">
  <thead class="table-light"><tr><th>Chat ID</th><th>Tên</th><th>Trạng thái</th><th>Ngày đăng nhập</th><th>Hoạt động cuối</th><th>Tin đã nhận</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): [$color, $label] = $badges[$u['state']]; ?>
    <tr>
      <td class="font-monospace"><?= (int) $u['chat_id'] ?></td>
      <td><?= h($u['first_name'] ?? '') ?> <?= $u['username'] ? '<span class="text-secondary">@' . h($u['username']) . '</span>' : '' ?></td>
      <td>
        <span class="badge text-bg-<?= $color ?>"><?= $label ?></span>
        <?php if ((int) $u['blocked_bot']): ?><span class="badge text-bg-danger">Đã chặn bot</span><?php endif; ?>
        <?php if ($u['locked_until'] && Clock::parse($u['locked_until']) > Clock::now()): ?><span class="badge text-bg-danger">Khoá tới <?= h(fmt_time($u['locked_until'], 'H:i')) ?></span><?php endif; ?>
      </td>
      <td class="small"><?= h(fmt_time($u['authorized_at'], 'H:i d/m/Y')) ?></td>
      <td class="small"><?= h(ago($u['last_seen_at'])) ?></td>
      <td><?= (int) $u['sent'] ?></td>
      <td class="text-end">
        <?php if (in_array($u['state'], ['authorized', 'awaiting_password'], true)): ?>
        <form method="post" action="actions.php" onsubmit="return confirm('Thu hồi quyền người dùng này?')"><?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="revoke_user"><input type="hidden" name="back" value="users"><input type="hidden" name="chat_id" value="<?= (int) $u['chat_id'] ?>">
          <button class="btn btn-sm btn-outline-danger">Thu hồi</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?><tr><td colspan="7" class="text-secondary">Chưa có ai nhắn bot.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php page_footer();
