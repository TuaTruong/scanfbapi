<?php
declare(strict_types=1);

/** Bảng fb_groups: đồng bộ từ textarea và cài đặt riêng từng group. */
final class Groups
{
    public const MAX_PAGES_LIMIT = 10;

    /**
     * Dòng mới → thêm; dòng bị xoá → in_list = 0 (giữ lịch sử); dòng thêm lại → in_list = 1.
     * @return array{added: int, removed: int, reactivated: int, duplicates: int, errors: list<string>}
     */
    public static function syncFromText(string $text): array
    {
        $parsed = InputParser::parseGroupList($text);
        $now = Clock::str();
        $defaultPages = self::clampPages(Settings::int('max_pages_default') ?: 2);
        $stats = ['added' => 0, 'removed' => 0, 'reactivated' => 0, 'duplicates' => $parsed['duplicates'], 'errors' => $parsed['errors']];

        Db::tx(function () use ($parsed, $now, $defaultPages, &$stats) {
            $existing = [];
            foreach (Db::all('SELECT id, fb_ref, in_list FROM fb_groups') as $row) {
                $existing[$row['fb_ref']] = $row;
            }
            $keep = [];
            foreach ($parsed['groups'] as $i => $g) {
                $keep[$g['fb_ref']] = true;
                if (isset($existing[$g['fb_ref']])) {
                    $row = $existing[$g['fb_ref']];
                    if (!(int) $row['in_list']) {
                        $stats['reactivated']++;
                    }
                    Db::run(
                        'UPDATE fb_groups SET in_list = 1, input_raw = ?, sort_order = ?, updated_at = ? WHERE id = ?',
                        [mb_substr($g['input_raw'], 0, 500), $i, $now, $row['id']]
                    );
                } else {
                    Db::run(
                        'INSERT INTO fb_groups (input_raw, fb_ref, ref_type, in_list, sort_order, max_pages, every_n_cycles, created_at, updated_at)
                         VALUES (?, ?, ?, 1, ?, ?, 1, ?, ?)',
                        [mb_substr($g['input_raw'], 0, 500), $g['fb_ref'], $g['ref_type'], $i, $defaultPages, $now, $now]
                    );
                    $stats['added']++;
                }
            }
            foreach ($existing as $ref => $row) {
                if (!isset($keep[$ref]) && (int) $row['in_list']) {
                    Db::run('UPDATE fb_groups SET in_list = 0, updated_at = ? WHERE id = ?', [$now, $row['id']]);
                    $stats['removed']++;
                }
            }
        });
        return $stats;
    }

    /** Nội dung textarea theo thứ tự đã lưu. */
    public static function textareaValue(): string
    {
        return implode("\n", Db::col('SELECT input_raw FROM fb_groups WHERE in_list = 1 ORDER BY sort_order, id'));
    }

    public static function listed(): array
    {
        return Db::all('SELECT * FROM fb_groups WHERE in_list = 1 ORDER BY sort_order, id');
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM fb_groups WHERE id = ?', [$id]);
    }

    public static function findByRef(string $fbRef): ?array
    {
        return Db::one('SELECT * FROM fb_groups WHERE fb_ref = ?', [$fbRef]);
    }

    /** Tạo group nếu chưa có (dùng cho scan-once), không đưa vào danh sách quét. */
    public static function ensure(array $parsed): array
    {
        $row = self::findByRef($parsed['fb_ref']);
        if ($row) {
            return $row;
        }
        $now = Clock::str();
        $id = Db::insert(
            'INSERT INTO fb_groups (input_raw, fb_ref, ref_type, in_list, sort_order, max_pages, created_at, updated_at)
             VALUES (?, ?, ?, 0, 0, ?, ?, ?)',
            [$parsed['input_raw'], $parsed['fb_ref'], $parsed['ref_type'], self::clampPages(Settings::int('max_pages_default') ?: 2), $now, $now]
        );
        return self::find($id);
    }

    public static function update(int $id, array $fields): void
    {
        $allowed = ['name', 'enabled', 'every_n_cycles', 'max_pages'];
        $sets = [];
        $params = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $sets[] = "$k = ?";
            $params[] = match ($k) {
                'name' => ($v === null || trim((string) $v) === '') ? null : mb_substr(trim((string) $v), 0, 255),
                'enabled' => $v ? 1 : 0,
                'every_n_cycles' => max(1, min(1000, (int) $v)),
                'max_pages' => self::clampPages((int) $v),
            };
        }
        if (!$sets) {
            return;
        }
        $sets[] = 'updated_at = ?';
        $params[] = Clock::str();
        $params[] = $id;
        Db::run('UPDATE fb_groups SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    /**
     * Xoá khỏi danh sách quét (giữ lịch sử bài đã thấy để thêm lại không gửi trùng) và huỷ tin đang chờ gửi của group.
     * @param list<int> $ids
     * @return int số group vừa xoá
     */
    public static function remove(array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $in = Db::placeholders($ids);
        $n = Db::exec("UPDATE fb_groups SET in_list = 0, updated_at = ? WHERE in_list = 1 AND id IN ($in)", array_merge([Clock::str()], $ids));
        Db::run(
            "UPDATE outbox o JOIN posts p ON p.id = o.post_id SET o.status = 'cancelled', o.last_error = 'group removed'
             WHERE o.status = 'pending' AND p.group_id IN ($in)",
            $ids
        );
        return $n;
    }

    /** Đưa group đã xoá trở lại danh sách (xếp cuối). */
    public static function restore(array $ids): int
    {
        $count = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $count += Db::exec(
                'UPDATE fb_groups SET in_list = 1, sort_order = (SELECT m FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS m FROM fb_groups) t), updated_at = ?
                 WHERE id = ? AND in_list = 0',
                [Clock::str(), $id]
            );
        }
        return $count;
    }

    /**
     * Xoá vĩnh viễn group đã xoá khỏi danh sách: group, thông tin group, bài đã thấy và tin của các bài đó.
     * Thêm lại sau này thì lần quét đầu sẽ gửi lại tối đa 1 trang bài.
     */
    public static function purge(array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        return (int) Db::tx(function () use ($ids) {
            $in = Db::placeholders($ids);
            $ids = Db::col("SELECT id FROM fb_groups WHERE in_list = 0 AND id IN ($in)", $ids);
            if (!$ids) {
                return 0;
            }
            $in = Db::placeholders($ids);
            Db::run("DELETE o FROM outbox o JOIN posts p ON p.id = o.post_id WHERE p.group_id IN ($in)", $ids);
            Db::run("DELETE FROM posts WHERE group_id IN ($in)", $ids);
            Db::run("DELETE FROM fb_group_info WHERE group_id IN ($in)", $ids);
            return Db::exec("DELETE FROM fb_groups WHERE id IN ($in)", $ids);
        });
    }

    public static function removed(): array
    {
        return Db::all(
            "SELECT g.*, (SELECT COUNT(*) FROM posts p WHERE p.group_id = g.id) AS post_count
             FROM fb_groups g WHERE g.in_list = 0 ORDER BY g.updated_at DESC, g.id DESC"
        );
    }

    public static function clampPages(int $pages): int
    {
        return max(1, min(self::MAX_PAGES_LIMIT, $pages));
    }

    /** Số bài tối đa ước lượng theo số trang: trang đầu 4 bài, mỗi trang sau 3 bài. */
    public static function postsForPages(int $pages): int
    {
        return 4 + 3 * (max(1, $pages) - 1);
    }

    public static function displayName(array $group): string
    {
        $name = trim((string) ($group['name'] ?? ''));
        return $name !== '' ? $name : (string) $group['fb_ref'];
    }

    public static function facebookUrl(array $group): string
    {
        return InputParser::groupUrl((string) $group['fb_ref']);
    }
}
