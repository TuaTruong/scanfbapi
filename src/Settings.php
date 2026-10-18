<?php
declare(strict_types=1);

/** Bảng settings dạng key-value. Worker gọi reload() mỗi vòng lặp để nhận thay đổi từ web. */
final class Settings
{
    public const DEFAULTS = [
        'scan_enabled' => '0',
        'window_start' => '06:00',
        'window_end' => '22:00',
        'rest_minutes' => '30',
        'max_pages_default' => '2',
        'max_post_age_hours' => '72',
        'sort_by' => 'CHRONOLOGICAL',
        'dedupe_days' => 'forever',
        'send_images' => '1',
        'bot_token' => '',
        'bot_username' => '',
        'bot_password_hash' => '',
        'admin_password_hash' => '',
        'cycle_no' => '0',
        'next_run_at' => '',
        'run_now' => '0',
        'run_now_chat_id' => '',
        'heartbeat_scanner' => '',
        'heartbeat_bot' => '',
        'scanner_state' => '',
        'all_keys_exhausted_notified' => '0',
        'last_key_revive_at' => '',
        'last_mass_error_alert_at' => '',
        'tg_offset' => '0',
    ];

    private static ?array $cache = null;

    public static function reload(): void
    {
        self::$cache = null;
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = array_map('strval', Db::pairs('SELECT k, v FROM settings'));
        }
        return self::$cache + self::DEFAULTS;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function int(string $key): int
    {
        return (int) self::get($key, '0');
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    public static function set(string $key, string|int|null $value): void
    {
        $value = $value === null ? null : (string) $value;
        Db::run(
            'INSERT INTO settings (k, v, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)',
            [$key, $value, Clock::str()]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = (string) $value;
        }
    }

    /** Đọc thẳng từ DB, bỏ qua cache (dùng cho cờ có thể bị tiến trình khác đổi). */
    public static function fresh(string $key): ?string
    {
        $v = Db::val('SELECT v FROM settings WHERE k = ?', [$key]);
        return $v === null ? (self::DEFAULTS[$key] ?? null) : (string) $v;
    }
}
