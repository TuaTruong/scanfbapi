<?php
declare(strict_types=1);

/** Dựng nội dung tin Telegram (parse_mode HTML) và bàn phím inline. */
final class MessageFormatter
{
    public const MAX_TEXT = 3500;
    public const MAX_PHOTOS = 10;     // giới hạn album của Telegram
    public const CAPTION_LIMIT = 1024; // giới hạn chú thích ảnh của Telegram
    private const CALLBACK_LIMIT = 64;
    private const SEPARATOR = '────────────';

    /**
     * @param array  $post  dòng bảng posts (id, author_id, author_name, text, url, publish_time)
     * @param array  $group dòng bảng fb_groups
     * @param ?array $info  dòng bảng fb_group_info (null nếu chưa quét thông tin group)
     * @return array{text: string, reply_markup: ?array, photos: list<string>}
     */
    public static function post(array $post, array $group, ?array $info = null): array
    {
        $media = self::media($post['media'] ?? null);
        $photos = $media['images'] ?: (isset($media['video']['thumb']) ? [$media['video']['thumb']] : []);
        $lines = [];
        $lines[] = '📌 <b>' . self::escape(Groups::displayName($group)) . '</b>' . self::groupStats($info);

        $meta = '👤 ' . self::escape(self::authorName($post));
        if (!empty($post['publish_time'])) {
            $meta .= ' · 🕒 ' . date('H:i d/m', (int) $post['publish_time']);
        }
        $lines[] = $meta;
        $lines[] = self::SEPARATOR;
        $lines[] = self::body($post['text'] ?? null, $photos !== []);
        if ($media['video']) {
            $lines[] = '🎬 <i>Bài có video — bấm “Mở bài viết” để xem</i>';
        }

        return [
            'text' => implode("\n", $lines),
            'reply_markup' => self::keyboard($post['url'] ?? null, $post['author_id'] ?? null, false, isset($post['id']) ? (int) $post['id'] : null, $post['author_name'] ?? null),
            'photos' => array_slice($photos, 0, self::MAX_PHOTOS),
        ];
    }

    /**
     * Cột posts.media (JSON do GroupScanner lưu): {"images": [...], "video": {"sd","hd","thumb"} | null}.
     * @return array{images: list<string>, video: ?array}
     */
    public static function media(?string $json): array
    {
        $data = $json ? json_decode($json, true) : null;
        $images = array_values(array_filter((array) ($data['images'] ?? []), static fn($u) => is_string($u) && preg_match('#^https?://#i', $u)));
        $video = is_array($data['video'] ?? null) && $data['video'] ? $data['video'] : null;
        return ['images' => $images, 'video' => $video];
    }

    /** Chữ (sau khi bỏ thẻ HTML) có vừa chú thích ảnh không; Telegram đếm theo đơn vị UTF-16. */
    public static function captionFits(string $html): bool
    {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return strlen(mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8')) / 2 <= self::CAPTION_LIMIT;
    }

    /** " · 👥 19.424 thành viên · 📝 30 bài/tháng" cạnh tên group; rỗng nếu chưa có thông tin group. */
    public static function groupStats(?array $info): string
    {
        $parts = [];
        if (isset($info['member_count'])) {
            $parts[] = '👥 ' . number_format((int) $info['member_count'], 0, ',', '.') . ' thành viên';
        }
        if (isset($info['posts_last_month'])) {
            $parts[] = '📝 ' . number_format((int) $info['posts_last_month'], 0, ',', '.') . ' bài/tháng';
        }
        return $parts ? ' · ' . implode(' · ', $parts) : '';
    }

    public static function body(?string $text, bool $hasPhotos = false): string
    {
        $text = $text === null ? '' : trim($text);
        if ($text === '') {
            return $hasPhotos ? '<i>(Bài chỉ có ảnh)</i>' : '<i>(Bài không có chữ — mở link để xem)</i>';
        }
        $cut = mb_strlen($text) > self::MAX_TEXT;
        if ($cut) {
            $text = rtrim(mb_substr($text, 0, self::MAX_TEXT)) . '…';
        }
        return self::markdownToHtml(self::escape($text));
    }

    /** ScrapeCreators trả text dạng markdown nhẹ (**đậm**, [nhãn](link)). Đổi sang HTML Telegram. */
    private static function markdownToHtml(string $escaped): string
    {
        $out = preg_replace('/\*\*([^*\n]+?)\*\*/u', '<b>$1</b>', $escaped) ?? $escaped;
        $out = preg_replace_callback(
            '/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)/u',
            static fn(array $m) => $m[1] === $m[2] ? $m[2] : $m[1] . ' (' . $m[2] . ')',
            $out
        ) ?? $out;
        return $out;
    }

    public static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function authorName(array $post): string
    {
        $name = trim((string) ($post['author_name'] ?? ''));
        return $name !== '' ? $name : 'Ẩn danh';
    }

    /**
     * Bàn phím dưới mỗi tin: [🔗 Mở bài viết] [👤 Trang cá nhân] / [🚫 Đánh dấu seller] hoặc [↩️ Bỏ seller].
     * Bài không có author_id (ẩn danh) thì không có nút trang cá nhân và nút seller.
     */
    public static function keyboard(?string $url, ?string $authorId, bool $isSeller, ?int $postId = null, ?string $authorName = null): ?array
    {
        $rows = [];
        $links = [];
        if ($url !== null && preg_match('#^https?://#i', $url)) {
            $links[] = ['text' => '🔗 Mở bài viết', 'url' => $url];
        }
        $profile = self::profileUrl($authorId, $authorName);
        if ($profile !== null) {
            $links[] = ['text' => '👤 Trang cá nhân', 'url' => $profile];
        }
        if ($links) {
            $rows[] = $links;
        }
        if ($authorId !== null && $authorId !== '') {
            $data = self::callbackData($isSeller ? 'unmark' : 'mark', $authorId, $postId);
            if ($data !== null) {
                $rows[] = [['text' => $isSeller ? '↩️ Bỏ seller' : '🚫 Đánh dấu seller', 'callback_data' => $data]];
            }
        }
        return $rows ? ['inline_keyboard' => $rows] : null;
    }

    /**
     * Link trang cá nhân người đăng. API chỉ trả ID tác giả nên tự dựng:
     * ID số → profile.php?id=…; ID pfbid… → /people/<tên>/<pfbid>/ (dạng chính API trả cho admin group).
     */
    public static function profileUrl(?string $authorId, ?string $authorName = null): ?string
    {
        if ($authorId === null || $authorId === '') {
            return null;
        }
        if (ctype_digit($authorId)) {
            return 'https://www.facebook.com/profile.php?id=' . $authorId;
        }
        if (str_starts_with($authorId, 'pfbid')) {
            $slug = trim(preg_replace('/\s+/u', '-', trim((string) $authorName)) ?? '', '-');
            return 'https://www.facebook.com/people/' . rawurlencode($slug !== '' ? $slug : 'user') . '/' . rawurlencode($authorId) . '/';
        }
        return 'https://www.facebook.com/' . rawurlencode($authorId);
    }

    /**
     * callback_data tối đa 64 byte: s:<author_id> / us:<author_id>.
     * author_id quá dài thì dùng id bài trong DB: sp:<post_id> / usp:<post_id>.
     */
    public static function callbackData(string $action, string $authorId, ?int $postId): ?string
    {
        $prefix = $action === 'mark' ? 's' : 'us';
        $data = $prefix . ':' . $authorId;
        if (strlen($data) <= self::CALLBACK_LIMIT) {
            return $data;
        }
        return $postId !== null ? $prefix . 'p:' . $postId : null;
    }

    /** Lấy link nút "Mở bài viết" từ bàn phím có sẵn của tin (dùng khi đổi nút seller). */
    public static function urlFromMarkup(?array $markup): ?string
    {
        foreach ($markup['inline_keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                if (isset($button['url'])) {
                    return (string) $button['url'];
                }
            }
        }
        return null;
    }
}
