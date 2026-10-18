<?php
declare(strict_types=1);

/** Hàng chờ tin Telegram. Scanner ghi vào, bot đọc ra rồi gửi. */
final class Outbox
{
    /** @return list<int> chat_id của người dùng đã đăng nhập và không chặn bot */
    public static function authorizedChats(): array
    {
        return array_map('intval', Db::col("SELECT chat_id FROM tg_users WHERE state = 'authorized' AND blocked_bot = 0 ORDER BY chat_id"));
    }

    /** Tạo một dòng outbox cho mỗi người dùng đã đăng nhập. */
    public static function enqueuePost(array $post, array $group): int
    {
        $info = GroupInfo::find((int) $group['id']);
        $payload = json_encode(MessageFormatter::post($post, $group, $info), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $count = 0;
        foreach (self::authorizedChats() as $chatId) {
            Db::run(
                "INSERT INTO outbox (chat_id, post_id, kind, payload, status, created_at) VALUES (?, ?, 'post', ?, 'pending', ?)",
                [$chatId, (int) $post['id'], $payload, Clock::str()]
            );
            $count++;
        }
        return $count;
    }

    /**
     * Dựng lại nội dung tin của một bài lúc gửi, để dùng thông tin group mới nhất (tên, số thành viên, bài/tháng).
     * @return ?array{text: string, reply_markup: ?array} null nếu bài hoặc group không còn
     */
    public static function buildPostPayload(int $postId): ?array
    {
        $post = Db::one('SELECT * FROM posts WHERE id = ?', [$postId]);
        $group = $post ? Groups::find((int) $post['group_id']) : null;
        if (!$post || !$group) {
            return null;
        }
        return MessageFormatter::post($post, $group, GroupInfo::find((int) $group['id']));
    }

    public static function enqueueAlert(string $html): int
    {
        $payload = json_encode(['text' => $html, 'reply_markup' => null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $count = 0;
        foreach (self::authorizedChats() as $chatId) {
            Db::run(
                "INSERT INTO outbox (chat_id, post_id, kind, payload, status, created_at) VALUES (?, NULL, 'alert', ?, 'pending', ?)",
                [$chatId, $payload, Clock::str()]
            );
            $count++;
        }
        return $count;
    }

    /** Tin hệ thống cho riêng một chat (ví dụ kết quả /scan). */
    public static function enqueueAlertTo(int $chatId, string $html): void
    {
        Db::run(
            "INSERT INTO outbox (chat_id, post_id, kind, payload, status, created_at) VALUES (?, NULL, 'alert', ?, 'pending', ?)",
            [$chatId, json_encode(['text' => $html, 'reply_markup' => null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), Clock::str()]
        );
    }

    public static function cancelForChat(int $chatId, string $reason): int
    {
        return Db::exec(
            "UPDATE outbox SET status = 'cancelled', last_error = ? WHERE chat_id = ? AND status = 'pending'",
            [$reason, $chatId]
        );
    }

    public static function hasReady(): bool
    {
        return (bool) Db::val(
            "SELECT 1 FROM outbox WHERE status = 'pending' AND (not_before IS NULL OR not_before <= ?) LIMIT 1",
            [Clock::str()]
        );
    }
}
