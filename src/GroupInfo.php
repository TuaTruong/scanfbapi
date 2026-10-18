<?php
declare(strict_types=1);

/**
 * Thông tin group từ /v1/facebook/group. Lưu nguyên văn JSON (raw_json) để không mất trường nào,
 * kèm vài cột chính để hiển thị/sắp xếp nhanh.
 */
final class GroupInfo
{
    /** Trường thanh toán của API, không thuộc thông tin group. */
    public const BILLING_KEYS = ['success', 'credits_remaining', 'credits_charged'];

    /**
     * Gọi API rồi lưu. Lỗi của group (404, private...) được ghi lại rồi ném tiếp.
     * @throws AllKeysExhaustedException|ApiException
     */
    public static function fetch(ScrapeCreators $api, array $group): array
    {
        try {
            $data = $api->groupInfo($group);
        } catch (ApiException $e) {
            self::saveError((int) $group['id'], $e->getMessage());
            throw $e;
        }
        self::save($group, $data);
        return self::find((int) $group['id']);
    }

    public static function save(array $group, array $data): void
    {
        $groupId = (int) $group['id'];
        $name = self::text($data['name'] ?? null);
        $createdAt = null;
        if (!empty($data['created_at']) && is_string($data['created_at'])) {
            try {
                $createdAt = Clock::str((new DateTimeImmutable($data['created_at']))->setTimezone(new DateTimeZone(date_default_timezone_get())));
            } catch (Exception) {
            }
        }
        $row = [
            'group_id' => $groupId,
            'fb_group_id' => isset($data['id']) ? mb_substr((string) $data['id'], 0, 32) : null,
            'name' => $name === null ? null : mb_substr($name, 0, 255),
            'privacy' => self::shortText($data['privacy']['label'] ?? null, 64),
            'visibility' => self::shortText($data['visibility']['label'] ?? null, 64),
            'member_count' => self::int($data['member_count'] ?? null),
            'posts_last_day' => self::int($data['activity']['posts_last_day'] ?? null),
            'posts_last_month' => self::int($data['activity']['posts_last_month'] ?? null),
            'administrator_count' => self::int($data['administrator_count'] ?? null),
            'moderator_count' => self::int($data['moderator_count'] ?? null),
            'created_at_fb' => $createdAt,
            'raw_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'fetched_at' => Clock::str(),
        ];
        $cols = array_keys($row);
        $updates = implode(', ', array_map(static fn($c) => "$c = VALUES($c)", array_slice($cols, 1)));
        Db::run(
            'INSERT INTO fb_group_info (' . implode(', ', $cols) . ', last_error, last_error_at) VALUES (' . Db::placeholders($cols) . ', NULL, NULL)
             ON DUPLICATE KEY UPDATE ' . $updates . ', last_error = NULL, last_error_at = NULL',
            array_values($row)
        );
        // Group chưa đặt tên thì lấy tên thật trên Facebook (không ghi đè tên người dùng tự đặt).
        if ($name !== null && trim((string) ($group['name'] ?? '')) === '') {
            Db::run("UPDATE fb_groups SET name = ? WHERE id = ? AND (name IS NULL OR name = '')", [mb_substr($name, 0, 255), $groupId]);
        }
    }

    public static function saveError(int $groupId, string $error): void
    {
        Db::run(
            'INSERT INTO fb_group_info (group_id, last_error, last_error_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_error = VALUES(last_error), last_error_at = VALUES(last_error_at)',
            [$groupId, mb_substr($error, 0, 1000), Clock::str()]
        );
    }

    public static function find(int $groupId): ?array
    {
        return Db::one('SELECT * FROM fb_group_info WHERE group_id = ?', [$groupId]);
    }

    /** @return array<int, array> group_id => dòng fb_group_info */
    public static function all(): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM fb_group_info') as $row) {
            $out[(int) $row['group_id']] = $row;
        }
        return $out;
    }

    /** JSON gốc đã giải mã, chuỗi được giải mã thực thể HTML (API trả tên dạng "C&#x1ed9;ng"). */
    public static function data(?array $info): ?array
    {
        if (!$info || empty($info['raw_json'])) {
            return null;
        }
        $data = json_decode((string) $info['raw_json'], true);
        return is_array($data) ? self::decodeAll($data) : null;
    }

    private static function decodeAll(array $data): array
    {
        array_walk_recursive($data, static function (&$v): void {
            if (is_string($v)) {
                $v = self::text($v);
            }
        });
        return $data;
    }

    public static function text(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        return str_contains($v, '&') ? html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $v;
    }

    private static function shortText(mixed $v, int $max): ?string
    {
        $t = self::text($v);
        return $t === null ? null : mb_substr($t, 0, $max);
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
