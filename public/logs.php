<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
Auth::requireLogin();

$runs = Db::all('SELECT * FROM scan_runs ORDER BY id DESC LIMIT 50');
$posts = Db::all(
    "SELECT p.*, g.name AS group_name, g.fb_ref,
            SUM(o.status = 'sent') AS sent, SUM(o.status = 'pending') AS pending,
            SUM(o.status = 'failed') AS failed, SUM(o.status = 'cancelled') AS cancelled
     FROM posts p
     JOIN fb_groups g ON g.id = p.group_id
     LEFT JOIN outbox o ON o.post_id = p.id
     GROUP BY p.id ORDER BY p.id DESC LIMIT 100"
);
$apiErrors = Db::all(
    "SELECT c.*, g.name AS group_name, g.fb_ref, k.api_key FROM api_calls c
     LEFT JOIN fb_groups g ON g.id = c.group_id LEFT JOIN api_keys k ON k.id = c.key_id
     WHERE c.error IS NOT NULL ORDER BY c.id DESC LIMIT 30"
);
$statusLabels = ['queued' => ['primary', 'Gửi'], 'skipped_seller' => ['secondary', 'Seller – bỏ qua'], 'baseline' => ['light', 'Mốc (không gửi)'],
    'duplicate' => ['warning', 'Trùng nội dung – bỏ qua']];

page_header('Nhật ký', 'logs');
?>
<h1 class="h4 mb-3">Nhật ký</h1>
<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-runs" type="button">Đợt quét (50)</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-posts" type="button">Bài gần nhất (100)</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-errors" type="button">Lỗi API (30)</button></li>
</ul>
<div class="tab-content">
  <div class="tab-pane fade show active" id="tab-runs">
    <div class="table-responsive"><table class="table table-sm table-hover align-middle">
      <thead class="table-light"><tr><th>Đợt</th><th>Bắt đầu</th><th>Kết thúc</th><th>Group đến hạn</th><th>Đã quét</th><th>Gọi API</th><th>Credit</th><th>Bài mới</th><th>Gửi</th><th>Seller</th><th>Lỗi</th><th>Ghi chú</th></tr></thead>
      <tbody>
      <?php foreach ($runs as $r): ?>
        <tr>
          <td>#<?= (int) $r['cycle_no'] ?></td><td><?= h(fmt_time($r['started_at'], 'H:i:s d/m')) ?></td><td><?= h(fmt_time($r['ended_at'], 'H:i:s')) ?></td>
          <td><?= (int) $r['groups_due'] ?></td><td><?= (int) $r['groups_scanned'] ?></td><td><?= (int) $r['api_calls'] ?></td><td><?= (int) $r['credits_used'] ?></td>
          <td><?= (int) $r['new_posts'] ?></td><td><?= (int) $r['queued_posts'] ?></td><td><?= (int) $r['skipped_seller'] ?></td>
          <td class="<?= (int) $r['errors'] ? 'text-danger' : '' ?>"><?= (int) $r['errors'] ?></td><td class="small text-secondary"><?= h($r['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$runs): ?><tr><td colspan="12" class="text-secondary">Chưa có đợt quét.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <div class="tab-pane fade" id="tab-posts">
    <div class="table-responsive"><table class="table table-sm table-hover align-middle">
      <thead class="table-light"><tr><th>Thấy lúc</th><th>Group</th><th>Tác giả</th><th style="min-width:20rem">Nội dung</th><th>Trạng thái</th><th>Telegram</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($posts as $p): [$c, $l] = $statusLabels[$p['status']]; ?>
        <tr>
          <td class="small"><?= h(fmt_time($p['first_seen_at'])) ?></td>
          <td class="small"><?= h($p['group_name'] ?: $p['fb_ref']) ?></td>
          <td class="small"><?= h($p['author_name'] ?? 'Ẩn danh') ?><?= Sellers::isSeller($p['author_id']) ? ' <span class="badge text-bg-secondary">seller</span>' : '' ?></td>
          <td class="small"><?= h(mb_strimwidth(preg_replace('/\s+/u', ' ', (string) ($p['text'] ?? '(không có chữ)')), 0, 160, '…')) ?></td>
          <td><span class="badge text-bg-<?= $c ?>"><?= $l ?></span></td>
          <td class="small">
            <?php if ($p['status'] === 'queued'): ?>
              <?= (int) $p['sent'] ?> đã gửi<?= (int) $p['pending'] ? ' · ' . (int) $p['pending'] . ' chờ' : '' ?><?= (int) $p['failed'] ? ' · <span class="text-danger">' . (int) $p['failed'] . ' lỗi</span>' : '' ?><?= (int) $p['cancelled'] ? ' · ' . (int) $p['cancelled'] . ' huỷ' : '' ?>
            <?php endif; ?>
          </td>
          <td><?php if ($p['url']): ?><a href="<?= h($p['url']) ?>" target="_blank" rel="noopener">↗</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$posts): ?><tr><td colspan="7" class="text-secondary">Chưa có bài nào.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <div class="tab-pane fade" id="tab-errors">
    <p class="small text-secondary">Lưu nguyên văn phản hồi lỗi của API (dùng để xác định đúng định dạng lỗi “hết credit” khi gặp lần đầu).</p>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle">
      <thead class="table-light"><tr><th>Lúc</th><th>Endpoint</th><th>Group</th><th>Key</th><th>HTTP</th><th>Thời gian</th><th>Phản hồi</th></tr></thead>
      <tbody>
      <?php foreach ($apiErrors as $e): ?>
        <tr>
          <td class="small"><?= h(fmt_time($e['called_at'], 'H:i:s d/m')) ?></td><td class="small"><?= h($e['endpoint']) ?></td>
          <td class="small"><?= h($e['group_name'] ?: ($e['fb_ref'] ?? '—')) ?></td>
          <td class="small font-monospace"><?= $e['api_key'] ? h(InputParser::mask($e['api_key'])) : '—' ?></td>
          <td><?= (int) $e['http_code'] ?></td><td class="small"><?= (int) $e['duration_ms'] ?> ms</td>
          <td class="small font-monospace text-break"><?= h($e['error']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$apiErrors): ?><tr><td colspan="7" class="text-secondary">Không có lỗi.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php page_footer();
