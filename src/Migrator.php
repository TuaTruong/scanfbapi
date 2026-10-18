<?php
declare(strict_types=1);

/** Tạo DB + bảng + settings mặc định. Chạy lại nhiều lần không lỗi, không mất dữ liệu. */
final class Migrator
{
    public const DEFAULT_BOT_PASSWORD = 'cloudcheap';

    /** @return list<string> các dòng thông báo */
    public static function run(?string $adminPassword = null): array
    {
        $messages = [];
        $name = Db::name();
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException("Tên DB không hợp lệ: $name");
        }
        $server = Db::connect(false);
        $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        Db::disconnect();

        $sql = (string) file_get_contents(APP_ROOT . '/sql/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            Db::run($statement);
        }
        $messages[] = "Đã tạo/kiểm tra bảng trong DB `$name`";
        foreach (self::upgrade() as $line) {
            $messages[] = $line;
        }

        $added = self::seedSettings();
        if ($added) {
            $messages[] = 'Đã thêm settings mặc định: ' . implode(', ', $added);
        }

        if (Settings::fresh('bot_password_hash') === '') {
            Settings::set('bot_password_hash', password_hash(self::DEFAULT_BOT_PASSWORD, PASSWORD_DEFAULT));
            $messages[] = 'Mật khẩu bot mặc định: ' . self::DEFAULT_BOT_PASSWORD;
        }

        if ($adminPassword !== null && $adminPassword !== '') {
            Settings::set('admin_password_hash', password_hash($adminPassword, PASSWORD_DEFAULT));
            $messages[] = 'Đã đặt mật khẩu admin web theo tham số --admin-password';
        } elseif (Settings::fresh('admin_password_hash') === '') {
            $random = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'xy'), '=');
            Settings::set('admin_password_hash', password_hash($random, PASSWORD_DEFAULT));
            $messages[] = "Mật khẩu admin web (ngẫu nhiên, chỉ in một lần): $random";
        }
        Settings::reload();
        return $messages;
    }

    /**
     * Nâng cấp DB đã tạo từ phiên bản trước. Chỉ ALTER khi cột còn khác định nghĩa mới.
     * @return list<string>
     */
    private static function upgrade(): array
    {
        $changes = [
            // ID tác giả có thể là pfbid… dài 64+ ký tự (gặp thực tế 18/10/2026)
            ['posts', 'author_id', 191, 'VARCHAR(191) NULL'],
            ['sellers', 'author_id', 191, 'VARCHAR(191) NOT NULL'],
        ];
        $done = [];
        foreach ($changes as [$table, $column, $length, $definition]) {
            $current = Db::val(
                'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [Db::name(), $table, $column]
            );
            if ($current !== null && (int) $current < $length) {
                Db::run("ALTER TABLE `$table` MODIFY `$column` $definition");
                $done[] = "Nâng cấp $table.$column → $definition";
            }
        }

        // Chống trùng theo nội dung (thêm 18/10/2026)
        if (!self::columnType('posts', 'content_hash')) {
            Db::run('ALTER TABLE posts ADD COLUMN content_hash CHAR(32) NULL AFTER text, ADD KEY idx_content (content_hash, first_seen_at)');
            $done[] = 'Thêm cột posts.content_hash';
        }
        if (!str_contains((string) self::columnType('posts', 'status'), "'duplicate'")) {
            Db::run("ALTER TABLE posts MODIFY status ENUM('queued','skipped_seller','baseline','duplicate') NOT NULL");
            $done[] = "Thêm trạng thái bài 'duplicate'";
        }
        // Gửi kèm ảnh lên Telegram (thêm 18/10/2026)
        if (!self::columnType('posts', 'media')) {
            Db::run('ALTER TABLE posts ADD COLUMN media TEXT NULL AFTER url');
            $done[] = 'Thêm cột posts.media (link ảnh/video)';
        }
        if (!self::columnType('outbox', 'media_sent')) {
            Db::run('ALTER TABLE outbox ADD COLUMN media_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER attempts');
            $done[] = 'Thêm cột outbox.media_sent';
        }

        // Seller lưu ID pfbid bị cắt 64 ký tự (trước khi nới cột) → đổi sang ID đầy đủ nếu đã thấy bài mới của người đó.
        foreach (Db::col("SELECT author_id FROM sellers WHERE CHAR_LENGTH(author_id) = 64 AND author_id LIKE 'pfbid%'") as $short) {
            $full = Db::val(
                "SELECT author_id FROM posts WHERE author_id LIKE CONCAT(?, '%') AND CHAR_LENGTH(author_id) > 64 ORDER BY id DESC LIMIT 1",
                [$short]
            );
            if ($full !== null && Db::exec('UPDATE IGNORE sellers SET author_id = ? WHERE author_id = ?', [$full, $short]) > 0) {
                $done[] = 'Sửa ID seller bị cắt: ' . substr((string) $short, 0, 16) . '… → đủ ' . strlen((string) $full) . ' ký tự';
            }
        }

        $filled = 0;
        foreach (Db::all('SELECT id, author_id, text FROM posts WHERE content_hash IS NULL AND text IS NOT NULL') as $p) {
            $hash = GroupScanner::contentHash($p['author_id'], $p['text']);
            if ($hash !== null) {
                $filled += Db::exec('UPDATE posts SET content_hash = ? WHERE id = ?', [$hash, $p['id']]);
            }
        }
        if ($filled) {
            $done[] = "Tính mã nội dung cho $filled bài đã có";
        }
        return $done;
    }

    private static function columnType(string $table, string $column): ?string
    {
        $type = Db::val(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [Db::name(), $table, $column]
        );
        return $type === null ? null : (string) $type;
    }

    /** @return list<string> các key vừa được thêm */
    public static function seedSettings(): array
    {
        $added = [];
        foreach (Settings::DEFAULTS as $k => $v) {
            if (Db::exec('INSERT IGNORE INTO settings (k, v, updated_at) VALUES (?, ?, ?)', [$k, $v, Clock::str()]) > 0) {
                $added[] = $k;
            }
        }
        Settings::reload();
        return $added;
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return ['settings', 'fb_groups', 'api_keys', 'posts', 'sellers', 'tg_users', 'outbox', 'scan_runs', 'api_calls', 'fb_group_info'];
    }
}
