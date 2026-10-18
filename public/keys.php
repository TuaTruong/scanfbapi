<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
Auth::requireLogin();

$keys = ApiKeys::listed();
$current = ApiKeys::current();
$usage = Db::pairs(
    "SELECT key_id, COUNT(*) FROM api_calls WHERE endpoint = 'group_posts' AND http_code = 200 AND called_at >= ? GROUP BY key_id",
    [Clock::now()->format('Y-m-d 00:00:00')]
);
$badges = ['active' => 'success', 'exhausted' => 'warning', 'invalid' => 'danger'];
$labels = ['active' => 'Hoạt động', 'exhausted' => 'Hết credit', 'invalid' => 'Sai key'];

page_header('API keys', 'keys');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">API keys ScrapeCreators</h1>
    <div class="text-secondary small">Thêm/xoá/đổi thứ tự key ở trang <a href="settings.php">Cài đặt</a>. Bot dùng cạn key ưu tiên cao nhất rồi mới sang key kế tiếp.
      Key hết credit được kiểm tra lại tự động mỗi 6 giờ.</div>
  </div>
  <form method="post" action="actions.php"><?= Auth::csrfField() ?><input type="hidden" name="action" value="check_credits"><input type="hidden" name="back" value="keys">
    <button class="btn btn-outline-primary">🔄 Kiểm tra credit (miễn phí)</button></form>
</div>

<div class="table-responsive">
<table class="table table-hover align-middle">
  <thead class="table-light"><tr><th>Ưu tiên</th><th>Key</th><th>Trạng thái</th><th>Credit còn</th><th>Gọi hôm nay</th><th>Lần dùng cuối</th><th>Kiểm tra cuối</th><th>Lỗi gần nhất</th></tr></thead>
  <tbody>
  <?php foreach ($keys as $i => $k): ?>
    <tr>
      <td><?= $i + 1 ?><?= $current && (int) $current['id'] === (int) $k['id'] ? ' <span class="badge text-bg-primary">đang dùng</span>' : '' ?></td>
      <td class="font-monospace"><?= h(InputParser::mask($k['api_key'])) ?></td>
      <td><span class="badge text-bg-<?= $badges[$k['status']] ?>"><?= $labels[$k['status']] ?></span></td>
      <td><?= $k['credits_remaining'] === null ? '—' : fmt_num((int) $k['credits_remaining']) ?></td>
      <td><?= (int) ($usage[$k['id']] ?? 0) ?></td>
      <td class="small"><?= h(fmt_time($k['last_used_at'], 'H:i:s d/m')) ?></td>
      <td class="small"><?= h(fmt_time($k['last_checked_at'], 'H:i:s d/m')) ?></td>
      <td class="small text-danger"><span class="d-inline-block text-truncate" style="max-width:24rem" title="<?= h($k['last_error'] ?? '') ?>"><?= h($k['last_error'] ?? '') ?></span></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$keys): ?><tr><td colspan="8" class="text-secondary">Chưa có key. Thêm ở <a href="settings.php">Cài đặt</a>.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php page_footer();
