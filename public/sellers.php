<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
Auth::requireLogin();

$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT s.*, u.first_name AS marked_by_name, u.username AS marked_by_username,
               (SELECT COUNT(*) FROM posts p WHERE p.author_id = s.author_id AND p.status = \'skipped_seller\') AS blocked_posts
        FROM sellers s LEFT JOIN tg_users u ON u.chat_id = s.marked_by_chat_id';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE s.author_id LIKE ? OR s.author_name LIKE ?';
    $params = ["%$q%", "%$q%"];
}
$sellers = Db::all($sql . ' ORDER BY s.marked_at DESC LIMIT 500', $params);
$total = (int) Db::val('SELECT COUNT(*) FROM sellers');

page_header('Sellers', 'sellers');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Sellers <span class="text-secondary fs-6">(<?= $total ?>)</span></h1>
    <div class="text-secondary small">Bài của seller không được gửi lên Telegram nữa. Danh sách dùng chung cho toàn bộ bot.</div>
  </div>
  <form class="d-flex gap-2" method="get"><input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Tìm tên hoặc FB ID"><button class="btn btn-sm btn-outline-secondary">Tìm</button></form>
</div>

<form method="post" action="actions.php" class="card card-body mb-4">
  <?= Auth::csrfField() ?><input type="hidden" name="action" value="add_seller"><input type="hidden" name="back" value="sellers">
  <div class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label small mb-1">FB ID hoặc link profile</label><input class="form-control form-control-sm" name="author_id" required placeholder="100090179152479"></div>
    <div class="col-md-3"><label class="form-label small mb-1">Tên (tuỳ chọn)</label><input class="form-control form-control-sm" name="author_name"></div>
    <div class="col-md-3"><label class="form-label small mb-1">Ghi chú</label><input class="form-control form-control-sm" name="note"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Thêm seller</button></div>
  </div>
</form>

<div class="table-responsive">
<table class="table table-hover align-middle">
  <thead class="table-light"><tr><th>Tên</th><th>FB ID</th><th>Người đánh dấu</th><th>Thời gian</th><th>Bài đã chặn</th><th>Ghi chú</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($sellers as $s): ?>
    <tr>
      <td><?= h($s['author_name'] ?? '—') ?></td>
      <td><a href="https://www.facebook.com/<?= h(rawurlencode($s['author_id'])) ?>" target="_blank" rel="noopener" class="font-monospace"><?= h($s['author_id']) ?> ↗</a></td>
      <td class="small"><?= h(Sellers::markerLabel($s)) ?></td>
      <td class="small"><?= h(fmt_time($s['marked_at'], 'H:i d/m/Y')) ?></td>
      <td><?= (int) $s['blocked_posts'] ?></td>
      <td class="small"><?= h($s['note'] ?? '') ?></td>
      <td class="text-end">
        <form method="post" action="actions.php" onsubmit="return confirm('Bỏ đánh dấu seller này?')"><?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="unmark_seller"><input type="hidden" name="back" value="sellers"><input type="hidden" name="author_id" value="<?= h($s['author_id']) ?>">
          <button class="btn btn-sm btn-outline-secondary">↩️ Bỏ seller</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$sellers): ?><tr><td colspan="7" class="text-secondary">Chưa có seller nào. Bấm “🚫 Đánh dấu seller” dưới tin trên Telegram hoặc thêm thủ công ở trên.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php page_footer();
