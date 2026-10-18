<?php
// Hiển thị thông tin group lấy từ ScrapeCreators (dùng chung cho groups.php và group-info.php).
declare(strict_types=1);

const GROUP_INFO_LABELS = ['Public' => 'Công khai', 'Private' => 'Riêng tư', 'Visible' => 'Hiển thị', 'Hidden' => 'Ẩn'];

/** Các trường đã có chỗ hiển thị riêng; trường nào API trả thêm sẽ hiện ở mục "Trường khác". */
const GROUP_INFO_KNOWN_KEYS = ['id', 'url', 'name', 'description', 'privacy', 'visibility', 'categories', 'created_at',
    'history_summary', 'member_count', 'member_count_text', 'administrator_count', 'moderator_count', 'administrators',
    'moderators', 'activity', 'rules', 'about_info'];

function group_info_label(?string $v): string
{
    return $v === null || $v === '' ? '—' : (GROUP_INFO_LABELS[$v] ?? $v);
}

/** Một dòng tóm tắt dưới tên group trong bảng. */
function group_info_summary(?array $info): string
{
    if (!$info || $info['fetched_at'] === null) {
        $html = '<span class="text-secondary">Chưa quét thông tin</span>';
    } else {
        $parts = [];
        if ($info['member_count'] !== null) {
            $parts[] = '👥 ' . fmt_num((int) $info['member_count']) . ' thành viên';
        }
        if ($info['privacy']) {
            $parts[] = ($info['privacy'] === 'Public' ? '🌐 ' : '🔒 ') . h(group_info_label($info['privacy']));
        }
        if ($info['posts_last_day'] !== null || $info['posts_last_month'] !== null) {
            $parts[] = '📝 ' . (int) $info['posts_last_day'] . ' bài/ngày · ' . (int) $info['posts_last_month'] . ' bài/tháng';
        }
        $html = implode(' · ', $parts) . ' <span class="text-secondary">(cập nhật ' . h(fmt_time($info['fetched_at'])) . ')</span>';
    }
    if ($info && $info['last_error']) {
        $html .= '<div class="text-danger">❌ Lỗi quét thông tin ' . h(fmt_time($info['last_error_at'])) . ': '
            . h(mb_strimwidth((string) $info['last_error'], 0, 160, '…')) . '</div>';
    }
    return $html;
}

/** @param list<array> $people admin hoặc người kiểm duyệt */
function group_info_people(array $people): string
{
    if (!$people) {
        return '<span class="text-secondary">Không có / Facebook không công khai</span>';
    }
    $items = [];
    foreach ($people as $p) {
        $name = h($p['name'] ?? '?');
        $link = !empty($p['url']) ? '<a href="' . h($p['url']) . '" target="_blank" rel="noopener">' . $name . ' ↗</a>' : $name;
        $avatar = !empty($p['profile_picture_url'])
            ? '<img src="' . h($p['profile_picture_url']) . '" alt="" width="20" height="20" class="rounded-circle me-1 align-text-bottom" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">'
            : '';
        $items[] = '<li class="list-unstyled">' . $avatar . $link . ' <span class="text-secondary font-monospace small">' . h($p['id'] ?? '') . '</span>'
            . group_info_extra($p, ['id', 'name', 'url', 'profile_picture_url']) . '</li>';
    }
    return '<ul class="mb-0 ps-0">' . implode('', $items) . '</ul>';
}

/** Các trường còn lại của một mục mà giao diện chưa có chỗ hiển thị riêng (để không bỏ sót dữ liệu API). */
function group_info_extra(array $item, array $shownKeys): string
{
    $extra = array_diff_key($item, array_flip($shownKeys));
    return $extra ? ' <span class="font-monospace small text-secondary">' . h(json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</span>' : '';
}

/** Toàn bộ thông tin API trả về, hiển thị trong hàng "Chi tiết". */
function group_info_detail(?array $info): string
{
    $d = GroupInfo::data($info);
    if (!$d) {
        return '<div class="text-secondary small py-1">Chưa có thông tin. Bấm “ℹ️ Quét thông tin”.</div>';
    }
    $rows = [];
    $rows['ID Facebook'] = '<span class="font-monospace">' . h($d['id'] ?? '—') . '</span>';
    $rows['Link'] = !empty($d['url']) ? '<a href="' . h($d['url']) . '" target="_blank" rel="noopener">' . h($d['url']) . ' ↗</a>' : '—';
    $rows['Tên'] = h($d['name'] ?? '—');
    $rows['Mô tả'] = !empty($d['description']) ? nl2br(h($d['description'])) : '—';
    foreach (['privacy' => 'Quyền riêng tư', 'visibility' => 'Hiển thị'] as $key => $label) {
        $v = $d[$key] ?? [];
        $rows[$label] = '<b>' . h(group_info_label($v['label'] ?? null)) . '</b>'
            . (!empty($v['description']) ? ' — ' . h($v['description']) : '');
    }
    $cats = array_map(static fn($c) => h($c['name'] ?? '') . ' <span class="text-secondary small">(' . h($c['id'] ?? '') . ')</span>', $d['categories'] ?? []);
    $rows['Danh mục'] = $cats ? implode(', ', $cats) : '—';
    $rows['Ngày tạo'] = $info['created_at_fb'] ? h(fmt_time($info['created_at_fb'], 'H:i d/m/Y')) : h($d['created_at'] ?? '—');
    $rows['Lịch sử'] = h($d['history_summary'] ?? '—');
    $rows['Thành viên'] = isset($d['member_count']) ? fmt_num((int) $d['member_count']) . ' <span class="text-secondary">(' . h($d['member_count_text'] ?? '') . ')</span>' : '—';
    $a = $d['activity'] ?? [];
    $rows['Hoạt động'] = 'Bài 24 giờ qua: <b>' . h($a['posts_last_day'] ?? '—') . '</b> · Bài 30 ngày qua: <b>' . h($a['posts_last_month'] ?? '—') . '</b>'
        . (!empty($a['new_members_text']) ? ' · ' . h($a['new_members_text']) : '');
    $rows['Quản trị viên (' . (int) ($d['administrator_count'] ?? 0) . ')'] = group_info_people($d['administrators'] ?? []);
    $rows['Người kiểm duyệt (' . (int) ($d['moderator_count'] ?? 0) . ')'] = group_info_people($d['moderators'] ?? []);
    $rules = [];
    foreach ($d['rules'] ?? [] as $r) {
        $rules[] = '<li><b>' . h($r['title'] ?? '') . '</b>' . (!empty($r['description']) ? '<br><span class="small">' . nl2br(h($r['description'])) . '</span>' : '')
            . group_info_extra($r, ['id', 'title', 'description']) . '</li>';
    }
    $rows['Nội quy'] = $rules ? '<ol class="mb-0 ps-3">' . implode('', $rules) . '</ol>' : '—';
    $about = [];
    foreach ($d['about_info'] ?? [] as $item) {
        $content = ($item['label'] ?? null) === null && ($item['description'] ?? null) === null
            ? '<span class="text-secondary">(API không trả nội dung)</span>'
            : '<b>' . h($item['label'] ?? '') . '</b>: ' . h($item['description'] ?? '');
        $about[] = '<li>' . $content . ' <span class="text-secondary small">(' . h($item['type'] ?? '') . ')</span>'
            . group_info_extra($item, ['type', 'label', 'description']) . '</li>';
    }
    $rows['Mục giới thiệu'] = $about ? '<ul class="mb-0 ps-3">' . implode('', $about) . '</ul>' : '—';
    foreach ($d as $key => $value) {
        if (in_array($key, GROUP_INFO_KNOWN_KEYS, true) || in_array($key, GroupInfo::BILLING_KEYS, true)) {
            continue;
        }
        $rows['Trường khác: ' . $key] = '<span class="font-monospace small">' . h(is_scalar($value) || $value === null
            ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</span>';
    }
    $rows['Lần quét'] = h(fmt_time($info['fetched_at'], 'H:i:s d/m/Y')) . (isset($d['credits_charged']) ? ' · tốn ' . (int) $d['credits_charged'] . ' credit' : '');

    $html = '<dl class="row mb-2 small group-info-detail">';
    foreach ($rows as $label => $value) {
        $html .= '<dt class="col-sm-3 col-lg-2">' . h($label) . '</dt><dd class="col-sm-9 col-lg-10">' . $value . '</dd>';
    }
    $html .= '</dl><details class="small"><summary>JSON gốc API trả về</summary><pre class="bg-light border rounded p-2 mt-1 mb-0" style="max-height:24rem;overflow:auto">'
        . h(json_encode(json_decode((string) $info['raw_json'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        . '</pre></details>';
    return $html;
}
