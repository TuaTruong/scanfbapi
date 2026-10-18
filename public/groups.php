<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/_group_info_view.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $do = (string) ($_POST['do'] ?? 'save');
    $ids = array_map('intval', array_keys((array) ($_POST['n'] ?? [])));

    if ($do === 'bulk_remove') {
        $n = Groups::remove((array) ($_POST['sel'] ?? []));
        flash($n ? 'success' : 'warning', $n
            ? "🗑 Đã xoá $n group khỏi danh sách quét. Lịch sử bài vẫn giữ; khôi phục được ở mục “Group đã xoá” cuối trang."
            : 'Chọn ít nhất một group để xoá.');
    } elseif ($do === 'bulk') {
        $selected = array_map('intval', (array) ($_POST['sel'] ?? []));
        $fields = [];
        if (($_POST['bulk_pages'] ?? '') !== '') {
            $fields['max_pages'] = (int) $_POST['bulk_pages'];
        }
        if (($_POST['bulk_n'] ?? '') !== '') {
            $fields['every_n_cycles'] = (int) $_POST['bulk_n'];
        }
        if (($_POST['bulk_enabled'] ?? '') !== '') {
            $fields['enabled'] = $_POST['bulk_enabled'] === '1';
        }
        if (!$selected || !$fields) {
            flash('warning', 'Chọn ít nhất một group và một giá trị cần đổi.');
        } else {
            foreach ($selected as $id) {
                Groups::update($id, $fields);
            }
            flash('success', 'Đã cập nhật ' . count($selected) . ' group.');
        }
    } else {
        foreach ($ids as $id) {
            Groups::update($id, [
                'name' => $_POST['name'][$id] ?? null,
                'every_n_cycles' => $_POST['n'][$id] ?? 1,
                'max_pages' => $_POST['pages'][$id] ?? 2,
                'enabled' => isset($_POST['enabled'][$id]),
            ]);
        }
        flash('success', 'Đã lưu cài đặt ' . count($ids) . ' group. Có hiệu lực từ đợt quét kế tiếp.');
    }
    redirect('groups.php');
}

$groups = Estimator::forGroups(Groups::listed());
$infos = GroupInfo::all();
$missingInfo = count(array_filter($groups, static fn($g) => empty($infos[(int) $g['id']]['fetched_at'])));
$sentCounts = Db::pairs("SELECT group_id, COUNT(*) FROM posts WHERE status = 'queued' GROUP BY group_id");
$removed = Groups::removed();
$removedCount = count($removed);
$cycle = Settings::int('cycle_no');
$scanOn = Settings::bool('scan_enabled');

page_header('Groups', 'groups');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Groups</h1>
    <div class="text-secondary small">Thêm group ở trang <a href="settings.php">Cài đặt</a>. Đợt hiện tại: #<?= $cycle ?>.
      <?= $removedCount ? '<a href="#removed-groups">' . $removedCount . ' group đã xoá</a> (giữ lịch sử, khôi phục được).' : '' ?></div>
  </div>
</div>

<?php if (!$groups): ?>
  <div class="alert alert-secondary">Chưa có group. Thêm ở <a href="settings.php">Cài đặt</a>.</div>
<?php else: ?>
<form method="post" id="groups-form">
  <?= Auth::csrfField() ?>
  <div class="card mb-3"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center small">
    <b>Đổi hàng loạt cho group đã chọn:</b>
    <select class="form-select form-select-sm w-auto" name="bulk_pages">
      <option value="">Số trang: giữ nguyên</option>
      <?php for ($p = 1; $p <= Groups::MAX_PAGES_LIMIT; $p++): ?><option value="<?= $p ?>"><?= h(pages_label($p)) ?></option><?php endfor; ?>
    </select>
    <input class="form-control form-control-sm" style="width:9rem" type="number" min="1" name="bulk_n" placeholder="N: giữ nguyên">
    <select class="form-select form-select-sm w-auto" name="bulk_enabled">
      <option value="">Bật/tắt: giữ nguyên</option><option value="1">Bật</option><option value="0">Tắt</option>
    </select>
    <button class="btn btn-sm btn-outline-primary" name="do" value="bulk">Áp dụng</button>
    <button class="btn btn-sm btn-outline-danger ms-auto" name="do" value="bulk_remove"
            onclick="var n=document.querySelectorAll('input[name=&quot;sel[]&quot;]:checked').length; if(!n){alert('Chọn ít nhất một group để xoá.');return false;} return confirm('Xoá '+n+' group đã chọn khỏi danh sách quét?\nLịch sử bài vẫn giữ, khôi phục được ở cuối trang.')">🗑 Xoá các group đã chọn</button>
  </div></div>

  <div class="card mb-3"><div class="card-body py-2 small">
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <b>Thông tin group trên Facebook:</b>
      <button type="button" class="btn btn-sm btn-info js-info-all" data-mode="missing">ℹ️ Quét thông tin group chưa có (<?= $missingInfo ?>)</button>
      <button type="button" class="btn btn-sm btn-outline-info js-info-all" data-mode="all">🔄 Quét lại thông tin tất cả (<?= count($groups) ?>)</button>
      <span class="text-secondary">Lấy tên, mô tả, số thành viên, số bài/ngày, admin, nội quy… Mỗi group tốn 1 credit. Group chưa đặt tên sẽ tự lấy tên thật.</span>
    </div>
    <div id="info-progress" class="d-none mt-2">
      <div class="progress mb-1" style="height:.6rem"><div class="progress-bar" role="progressbar" style="width:0%"></div></div>
      <span class="js-progress-text"></span>
      <button type="button" class="btn btn-link btn-sm p-0 ms-2 js-info-stop">Dừng</button>
    </div>
  </div></div>

  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle groups-table">
    <thead class="table-light">
      <tr>
        <th><input class="form-check-input" type="checkbox" data-check-all="sel[]" title="Chọn tất cả"></th>
        <th style="min-width:14rem">Tên / Link</th>
        <th title="Quét sau mỗi N đợt">Mỗi N đợt</th>
        <th style="min-width:14rem">Số trang mỗi lần quét</th>
        <th>Bật</th>
        <th>Lần quét cuối</th>
        <th>Lần quét tới</th>
        <th title="Số trang thực dùng trung bình 7 ngày">Trang TB</th>
        <th title="Ước tính / trần">Credit/ngày</th>
        <th>Đã gửi</th>
        <th style="min-width:12rem">Cảnh báo / lỗi</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($groups as $g): $id = (int) $g['id']; $paused = $g['paused_until_cycle'] !== null && $cycle <= (int) $g['paused_until_cycle']; ?>
      <tr class="<?= (int) $g['enabled'] ? '' : 'table-secondary' ?>" data-group-id="<?= $id ?>" data-has-info="<?= empty($infos[$id]['fetched_at']) ? '0' : '1' ?>">
        <td><input class="form-check-input" type="checkbox" name="sel[]" value="<?= $id ?>"></td>
        <td>
          <input class="form-control form-control-sm mb-1" name="name[<?= $id ?>]" value="<?= h($g['name'] ?? '') ?>" placeholder="<?= h($g['fb_ref']) ?>">
          <a class="small text-secondary" href="<?= h(Groups::facebookUrl($g)) ?>" target="_blank" rel="noopener"><?= h($g['fb_ref']) ?> ↗</a>
          <div class="small mt-1" id="info-summary-<?= $id ?>"><?= group_info_summary($infos[$id] ?? null) ?></div>
          <div class="mt-1 d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-info py-0 js-info-one" data-id="<?= $id ?>">ℹ️ Quét thông tin</button>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="collapse" data-bs-target="#info-detail-<?= $id ?>">Chi tiết</button>
            <button class="btn btn-sm btn-outline-danger py-0 ms-auto" form="f-remove-<?= $id ?>"
                    onclick="return confirm('Xoá group <?= h(addslashes(Groups::displayName($g))) ?> khỏi danh sách quét?\nLịch sử bài vẫn giữ, khôi phục được ở cuối trang.')">🗑 Xoá</button>
          </div>
        </td>
        <td><input class="form-control form-control-sm" style="width:5rem" type="number" min="1" name="n[<?= $id ?>]" value="<?= (int) $g['every_n_cycles'] ?>"></td>
        <td>
          <select class="form-select form-select-sm" name="pages[<?= $id ?>]">
            <?php for ($p = 1; $p <= Groups::MAX_PAGES_LIMIT; $p++): ?>
              <option value="<?= $p ?>" <?= (int) $g['max_pages'] === $p ? 'selected' : '' ?>><?= h(pages_label($p)) ?></option>
            <?php endfor; ?>
          </select>
        </td>
        <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled[<?= $id ?>]" value="1" <?= (int) $g['enabled'] ? 'checked' : '' ?>></div></td>
        <td class="small"><?= $g['last_scanned_at'] ? h(fmt_time($g['last_scanned_at'])) . '<br><span class="text-secondary">đợt #' . (int) $g['last_scanned_cycle'] . '</span>' : '<span class="text-secondary">chưa quét</span>' ?></td>
        <td class="small">
          <?php if ($g['next_cycle'] === null): ?><span class="text-secondary">—</span>
          <?php else: ?>đợt #<?= (int) $g['next_cycle'] ?><br><span class="text-secondary"><?= $g['next_time'] ? '~' . h($g['next_time']->format('H:i d/m')) : ($scanOn ? '' : 'đang tắt quét') ?></span><?php endif; ?>
        </td>
        <td class="small"><?= $g['avg_pages_measured'] === null ? '<span class="text-secondary" title="Chưa có dữ liệu, tạm tính 1,3">—</span>' : fmt_num($g['avg_pages_measured'], 1) ?></td>
        <td class="small">≈ <?= fmt_num($g['credits_day'], 1) ?><br><span class="text-secondary">trần <?= fmt_num($g['credits_ceiling'], 1) ?></span></td>
        <td class="small"><?= (int) ($sentCounts[$id] ?? 0) ?></td>
        <td class="small">
          <?php if ((int) $g['cap_hits'] > 0): ?>
            <div class="text-warning-emphasis">⚠️ Có thể sót bài (<?= (int) $g['cap_hits'] ?> lần, gần nhất <?= h(fmt_time($g['last_cap_hit_at'])) ?>). Tăng số trang hoặc giảm N.
              <button class="btn btn-link btn-sm p-0 align-baseline" form="f-cap-<?= $id ?>">Bỏ cảnh báo</button></div>
          <?php endif; ?>
          <?php if ($g['last_error']): ?>
            <div class="<?= (int) $g['consecutive_errors'] >= 3 ? 'text-danger fw-semibold' : 'text-danger' ?>">
              ❌ <?= (int) $g['consecutive_errors'] ?> lỗi liên tiếp<?= $paused ? ', tạm hoãn tới hết đợt #' . (int) $g['paused_until_cycle'] : '' ?>:
              <span class="d-block text-truncate" style="max-width:18rem" title="<?= h($g['last_error']) ?>"><?= h($g['last_error']) ?></span>
              <?php if ($paused || (int) $g['consecutive_errors'] > 0): ?><button class="btn btn-link btn-sm p-0" form="f-pause-<?= $id ?>">Gỡ tạm hoãn</button><?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if (!(int) $g['cap_hits'] && !$g['last_error']): ?><span class="text-success">OK</span><?php endif; ?>
        </td>
      </tr>
      <tr class="collapse" id="info-detail-<?= $id ?>">
        <td></td>
        <td colspan="10" class="bg-light"><div id="info-detail-body-<?= $id ?>"><?= group_info_detail($infos[$id] ?? null) ?></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <button class="btn btn-primary" name="do" value="save">💾 Lưu thay đổi</button>
</form>

<?php foreach ($groups as $g): $id = (int) $g['id']; ?>
  <form method="post" action="actions.php" id="f-cap-<?= $id ?>"><?= Auth::csrfField() ?><input type="hidden" name="action" value="reset_cap"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="groups"></form>
  <form method="post" action="actions.php" id="f-pause-<?= $id ?>"><?= Auth::csrfField() ?><input type="hidden" name="action" value="clear_pause"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="groups"></form>
  <form method="post" action="actions.php" id="f-remove-<?= $id ?>"><?= Auth::csrfField() ?><input type="hidden" name="action" value="remove_group"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="groups"></form>
<?php endforeach; ?>

<div class="mt-4 small text-secondary" style="max-width:60rem">
  <b>Gợi ý:</b> group ít bài để 1–2 trang · group nhiều bài tăng số trang <i>hoặc</i> giảm N (quét dày thường rẻ hơn) ·
  group admin chỉ duyệt 1–2 lần/ngày để N lớn kèm số trang lớn (ví dụ N=6, 5 trang).
  Số trang là mức trần: scanner dừng ngay khi đã gặp bài cũ, nên group ít bài thường chỉ tốn 1 credit mỗi lần quét.
</div>
<script src="assets/groups.js" defer></script>
<?php endif; ?>

<?php if ($removed): ?>
<div class="mt-5" id="removed-groups">
  <h2 class="h5">Group đã xoá (<?= $removedCount ?>)</h2>
  <p class="small text-secondary mb-2">Không còn được quét. Lịch sử bài đã thấy vẫn giữ để nếu khôi phục hoặc thêm lại thì không gửi lại bài cũ.
    <b>Xoá vĩnh viễn</b> xoá luôn lịch sử bài và thông tin group; thêm lại sau này thì lần quét đầu sẽ gửi lại tối đa 1 trang bài.</p>
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Tên / Link</th><th>Bài đã lưu</th><th>Xoá lúc</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($removed as $g): $id = (int) $g['id']; ?>
      <tr>
        <td><?= h(Groups::displayName($g)) ?><br><a class="small text-secondary" href="<?= h(Groups::facebookUrl($g)) ?>" target="_blank" rel="noopener"><?= h($g['fb_ref']) ?> ↗</a></td>
        <td><?= (int) $g['post_count'] ?></td>
        <td class="small"><?= h(fmt_time($g['updated_at'], 'H:i d/m/Y')) ?></td>
        <td class="text-end text-nowrap">
          <form method="post" action="actions.php" class="d-inline"><?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="restore_group"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="groups">
            <button class="btn btn-sm btn-outline-success">↩️ Khôi phục</button></form>
          <form method="post" action="actions.php" class="d-inline"
                onsubmit="return confirm('Xoá VĨNH VIỄN group <?= h(addslashes(Groups::displayName($g))) ?>?\nMất cả <?= (int) $g['post_count'] ?> bài đã lưu và thông tin group. Không hoàn tác được.')"><?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="purge_group"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="groups">
            <button class="btn btn-sm btn-outline-danger">Xoá vĩnh viễn</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<?php page_footer();
