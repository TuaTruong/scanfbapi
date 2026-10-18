<?php
declare(strict_types=1);

/** Danh sách seller dùng chung cho toàn bộ bot. */
final class Sellers
{
    public static function find(string $authorId): ?array
    {
        return Db::one(
            'SELECT s.*, u.first_name AS marked_by_name, u.username AS marked_by_username
             FROM sellers s LEFT JOIN tg_users u ON u.chat_id = s.marked_by_chat_id
             WHERE s.author_id = ?',
            [$authorId]
        );
    }

    public static function isSeller(?string $authorId): bool
    {
        if ($authorId === null || $authorId === '') {
            return false;
        }
        // Seller đánh dấu trước 18/10/2026 có thể lưu ID pfbid… bị cắt còn 64 ký tự (ID thật dài hơn) → so theo tiền tố.
        return (bool) Db::val(
            "SELECT 1 FROM sellers WHERE author_id = ?
                OR (CHAR_LENGTH(author_id) = 64 AND author_id LIKE 'pfbid%' AND ? LIKE CONCAT(author_id, '%'))
             LIMIT 1",
            [$authorId, $authorId]
        );
    }

    /** @return bool true nếu vừa thêm mới, false nếu đã là seller từ trước */
    public static function mark(string $authorId, ?string $authorName, ?int $byChatId, ?string $note = null): bool
    {
        $inserted = Db::exec(
            'INSERT IGNORE INTO sellers (author_id, author_name, marked_by_chat_id, marked_at, note) VALUES (?, ?, ?, ?, ?)',
            [$authorId, $authorName, $byChatId, Clock::str(), $note]
        ) > 0;
        if ($inserted) {
            self::cancelPendingOutbox($authorId);
        }
        return $inserted;
    }

    public static function unmark(string $authorId): bool
    {
        return Db::exec('DELETE FROM sellers WHERE author_id = ?', [$authorId]) > 0;
    }

    /** Huỷ các tin đang chờ gửi của tác giả vừa bị đánh dấu. */
    public static function cancelPendingOutbox(string $authorId): int
    {
        return Db::exec(
            "UPDATE outbox o JOIN posts p ON p.id = o.post_id
             SET o.status = 'cancelled', o.last_error = 'seller'
             WHERE o.status = 'pending' AND p.author_id = ?",
            [$authorId]
        );
    }

    /** Tên gần nhất của tác giả trong các bài đã thấy. */
    public static function latestAuthorName(string $authorId): ?string
    {
        $name = Db::val('SELECT author_name FROM posts WHERE author_id = ? AND author_name IS NOT NULL ORDER BY id DESC LIMIT 1', [$authorId]);
        return $name === null ? null : (string) $name;
    }

    public static function markerLabel(array $seller): string
    {
        if (!empty($seller['marked_by_name'])) {
            return (string) $seller['marked_by_name'];
        }
        if (!empty($seller['marked_by_username'])) {
            return '@' . $seller['marked_by_username'];
        }
        return !empty($seller['marked_by_chat_id']) ? 'chat ' . $seller['marked_by_chat_id'] : 'admin web';
    }
}
