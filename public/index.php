<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
Auth::requireLogin();

Settings::reload();
$groups = Estimator::forGroups(Groups::listed());
$est = Estimator::summary($groups);
$scanOn = Settings::bool('scan_enabled');
$scannerOk = heartbeat_ok('heartbeat_scanner');
$botOk = heartbeat_ok('heartbeat_bot');
$keysListed = ApiKeys::countListed();
$keyActive = ApiKeys::current() !== null;
$cycle = Settings::int('cycle_no');
$nextRun = Clock::parse(Settings::get('next_run_at'));
$inWindow = Scheduler::inWindow((string) Settings::get('window_start'), (string) Settings::get('window_end'), Clock::now());
$today = Clock::now()->format('Y-m-d 00:00:00');
$sentToday = (int) Db::val("SELECT COUNT(*) FROM posts WHERE status = 'queued' AND first_seen_at >= ?", [$today]);
$skippedToday = (int) Db::val("SELECT COUNT(*) FROM posts WHERE status = 'skipped_seller' AND first_seen_at >= ?", [$today]);
$creditsToday = (int) Db::val('SELECT COALESCE(SUM(credits_charged), 0) FROM api_calls WHERE called_at >= ?', [$today]); // gồm cả quét thông tin group
$tgUsers = (int) Db::val("SELECT COUNT(*) FROM tg_users WHERE state = 'authorized' AND blocked_bot = 0");
$pendingOutbox = (int) Db::val("SELECT COUNT(*) FROM outbox WHERE status = 'pending'");
$runs = Db::all('SELECT * FROM scan_runs ORDER BY id DESC LIMIT 8');
$problemGroups = array_filter($groups, static fn($g) => (int) $g['consecutive_errors'] >= 3);
$capGroups = array_filter($groups, static fn($g) => (int) $g['cap_hits'] > 0);

if (!$scanOn) {
    $nextText = 'Đang tắt quét';
} elseif (Settings::bool('run_now')) {
    $nextText = 'Đang chờ quét ngay…';
} elseif (!$inWindow) {
    $nextText = 'Ngoài khung giờ · bắt đầu ' . Estimator::nextWindowStart((string) Settings::get('window_start'), Clock::now())->format('H:i d/m');
} elseif ($nextRun && $nextRun > Clock::now()) {
    $nextText = $nextRun->format('H:i:s d/m');
} else {
    $nextText = 'Ngay bây giờ';
}

page_header('Dashboard', 'index');
?>
<?php if ($keysListed > 0 && !$keyActive): ?>
  <div class="alert alert-danger"><b>Tất cả API key đã hết credit hoặc không hợp lệ.</b> Bot tạm dừng quét. Nạp thêm credit rồi bấm <a href="keys.php">Kiểm tra credit</a>, hoặc thêm key mới ở <a href="settings.php">Cài đặt</a>.</div>
<?php elseif ($keysListed === 0): ?>
  <div class="alert alert-warning">Chưa có API key ScrapeCreators. Thêm ở <a href="settings.php">Cài đặt</a>.</div>
<?php endif; ?>
<?php if (Settings::get('bot_token') === ''): ?>
  <div class="alert alert-warning">Chưa có bot token Telegram. Thêm ở <a href="settings.php">Cài đặt</a>.</div>
<?php endif; ?>
<?php if (!$groups): ?>
  <div class="alert alert-warning">Chưa có group nào. Thêm ở <a href="settings.php">Cài đặt</a>.</div>
<?php endif; ?>
<?php if ($problemGroups): ?>
  <div class="alert alert-danger"><?= count($problemGroups) ?> group lỗi liên tiếp (đang tạm hoãn): <?= h(implode(', ', array_map([Groups::class, 'displayName'], $problemGroups))) ?>. Xem <a href="groups.php">Groups</a>.</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 align-items-center mb-4">
  <form method="post" action="actions.php"><?= Auth::csrfField() ?><input type="hidden" name="action" value="toggle_scan">
    <button class="btn <?= $scanOn ? 'btn-outline-secondary' : 'btn-success' ?>"><?= $scanOn ? '⏸ Tắt quét' : '▶️ Bật quét' ?></button></form>
  <form method="post" action="actions.php"><?= Auth::csrfField() ?><input type="hidden" name="action" value="run_now">
    <button class="btn btn-primary" <?= $scanOn ? '' : 'disabled' ?>>⚡ Quét ngay</button></form>
  <span class="text-secondary small ms-2">Quét ngay bỏ qua khung giờ và thời gian nghỉ, chạy một đợt bình thường.</span>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="stat-label">Scanner</div>
      <div class="stat-value"><span class="dot <?= $scannerOk ? 'dot-ok' : 'dot-bad' ?>"></span><?= $scannerOk ? 'Đang chạy' : 'Không chạy' ?></div>
      <div class="small text-secondary">Heartbeat <?= h(ago(Settings::get('heartbeat_scanner'))) ?> · <?= $scanOn ? 'đang bật quét' : 'đang tắt quét' ?></div>
    </div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="stat-label">Bot Telegram</div>
      <div class="stat-value"><span class="dot <?= $botOk ? 'dot-ok' : 'dot-bad' ?>"></span><?= $botOk ? 'Đang chạy' : 'Không chạy' ?></div>
      <div class="small text-secondary"><?= Settings::get('bot_username') ? '@' . h(Settings::get('bot_username')) . ' · ' : '' ?><?= $tgUsers ?> người nhận · <?= $pendingOutbox ?> tin chờ gửi</div>
    </div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="stat-label">Đợt quét</div>
      <div class="stat-value">#<?= $cycle ?></div>
      <div class="small text-secondary">Đợt kế tiếp: <b><?= h($nextText) ?></b><br>Khung <?= h(Settings::get('window_start') . '–' . Settings::get('window_end')) ?> · nghỉ <?= Settings::int('rest_minutes') ?> phút</div>
    </div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="stat-label">Hôm nay</div>
      <div class="stat-value"><?= $sentToday ?> bài gửi</div>
      <div class="small text-secondary"><?= $skippedToday ?> bài seller bị chặn · <?= $creditsToday ?> credit đã dùng</div>
    </div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="stat-label">Credit còn lại</div>
      <div class="stat-value"><?= fmt_num($est['credits_left']) ?></div>
      <div class="small text-secondary"><a href="keys.php"><?= $keysListed ?> key</a> trong danh sách</div>
    </div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="stat-label">Credit/ngày (ước tính)</div>
      <div class="stat-value">≈ <?= fmt_num($est['credits_day']) ?></div>
      <div class="small text-secondary">Trần (lần nào cũng đủ trang): <?= fmt_num($est['credits_ceiling']) ?></div>
    </div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="stat-label">Số ngày còn dùng</div>
      <div class="stat-value"><?= $est['days_left'] === null ? '—' : '≈ ' . fmt_num($est['days_left'], 1) ?></div>
      <div class="small text-secondary">≈ <?= fmt_num($est['cycles_per_day'], 1) ?> đợt/ngày · mỗi vòng ≈ <?= fmt_num($est['cycle_minutes'], 1) ?> phút</div>
    </div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card h-100"><div class="card-body">
      <div class="stat-label">Groups</div>
      <div class="stat-value"><?= count(array_filter($groups, static fn($g) => (int) $g['enabled'] === 1)) ?> / <?= count($groups) ?> đang bật</div>
      <div class="small text-secondary"><?= count($capGroups) ?> group có thể sót bài · <?= count($problemGroups) ?> group lỗi</div>
    </div></div>
  </div>
</div>

<h2 class="h5">Các đợt quét gần nhất</h2>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
  <thead><tr><th>Đợt</th><th>Bắt đầu</th><th>Thời gian</th><th>Group quét</th><th>Gọi API</th><th>Credit</th><th>Bài mới</th><th>Gửi</th><th>Seller</th><th>Lỗi</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($runs as $r): $dur = $r['ended_at'] ? strtotime($r['ended_at']) - strtotime($r['started_at']) : null; ?>
    <tr>
      <td>#<?= (int) $r['cycle_no'] ?></td>
      <td><?= h(fmt_time($r['started_at'], 'H:i:s d/m')) ?></td>
      <td><?= $dur === null ? '<span class="text-warning">đang chạy</span>' : $dur . 's' ?></td>
      <td><?= (int) $r['groups_scanned'] ?>/<?= (int) $r['groups_due'] ?></td>
      <td><?= (int) $r['api_calls'] ?></td>
      <td><?= (int) $r['credits_used'] ?></td>
      <td><?= (int) $r['new_posts'] ?></td>
      <td><?= (int) $r['queued_posts'] ?></td>
      <td><?= (int) $r['skipped_seller'] ?></td>
      <td><?= (int) $r['errors'] ? '<span class="text-danger">' . (int) $r['errors'] . '</span>' : 0 ?></td>
      <td class="small text-secondary"><?= h($r['note'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$runs): ?><tr><td colspan="11" class="text-secondary">Chưa có đợt quét nào.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php page_footer();
