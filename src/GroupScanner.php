<?php
declare(strict_types=1);

/**
 * Quét một group: phân trang tới khi đuổi kịp bài cũ hoặc chạm trần số trang,
 * chống trùng theo (group, post.id), lọc seller, đưa bài mới vào outbox theo thứ tự cũ → mới.
 */
final class GroupScanner
{
    /** Một trang có từ chừng này bài đã thấy trở lên thì coi như đã đuổi kịp. 1 bài cũ có thể chỉ là bài ghim. */
    private const CAUGHT_UP_OLD_POSTS = 2;
    private const ERRORS_BEFORE_PAUSE = 3;
    /** Nội dung (đã bỏ dấu cách, emoji, ký hiệu) từ chừng này ký tự trở lên thì so trùng không cần cùng tác giả. */
    private const DEDUPE_MIN_CHARS = 30;

    /** @param ?Closure $onProgress gọi sau mỗi lần gọi API (scanner dùng để ghi heartbeat) */
    public function __construct(
        private readonly ScrapeCreators $api,
        private readonly ?Closure $onProgress = null,
    ) {
    }

    /**
     * @param ?int $cycleNo  null khi quét tay (scan-once): không đụng tới lịch đợt
     * @param bool $dryRun   lưu bài với status baseline, không tạo outbox
     * @return array{api_calls: int, pages: int, new_posts: int, queued: int, skipped_seller: int, cap_hit: bool, first_scan: bool, error: ?string, posts: list<array>}
     * @throws AllKeysExhaustedException (bài đã lấy được trước đó vẫn được lưu)
     */
    public function scan(array $group, ?int $cycleNo = null, ?int $runId = null, bool $dryRun = false, ?int $pagesOverride = null): array
    {
        $groupId = (int) $group['id'];
        $firstScan = !Db::val('SELECT 1 FROM posts WHERE group_id = ? LIMIT 1', [$groupId]);
        $maxPages = $firstScan ? 1 : Groups::clampPages($pagesOverride ?? (int) $group['max_pages']);

        $maxAgeHours = Settings::int('max_post_age_hours');
        $cutoff = $maxAgeHours > 0 ? Clock::ts() - $maxAgeHours * 3600 : null;

        $result = ['api_calls' => 0, 'pages' => 0, 'new_posts' => 0, 'queued' => 0, 'skipped_seller' => 0, 'stale' => 0, 'duplicate' => 0,
            'cap_hit' => false, 'first_scan' => $firstScan, 'error' => null, 'posts' => []];
        $collected = [];
        $stale = [];
        $seen = [];
        $cursor = null;
        $exhausted = null;

        try {
            while (true) {
                $res = $this->api->groupPosts($group, $cursor, $runId);
                $result['api_calls']++;
                $result['pages']++;
                if ($this->onProgress) {
                    ($this->onProgress)();
                }

                $posts = array_values(array_filter($res['posts'], static fn($p) => is_array($p) && (string) ($p['id'] ?? '') !== ''));
                $known = $this->knownIds($groupId, array_map(static fn($p) => (string) $p['id'], $posts));
                $old = 0;
                foreach ($posts as $p) {
                    $pid = (string) $p['id'];
                    if (isset($known[$pid]) || isset($seen[$pid])) {
                        $old++;
                        continue;
                    }
                    $seen[$pid] = true;
                    $published = (int) ($p['publishTime'] ?? 0);
                    if ($cutoff !== null && $published > 0 && $published < $cutoff) {
                        // API đôi khi trả nhầm một trang bài từ nhiều tuần trước (gặp thực tế 18/10/2026):
                        // lưu làm mốc, không gửi, và tính như bài cũ để không lật thêm trang.
                        $old++;
                        $stale[] = $p;
                        continue;
                    }
                    $collected[] = $p;
                }

                if ($old >= self::CAUGHT_UP_OLD_POSTS || $old === count($posts)) {
                    break;
                }
                if ($res['cursor'] === null) {
                    break;
                }
                if ($result['pages'] >= $maxPages) {
                    if ($old === 0 && !$firstScan) {
                        $result['cap_hit'] = true; // trang cuối vẫn toàn bài mới → có thể sót bài
                    }
                    break;
                }
                $cursor = $res['cursor'];
            }
        } catch (ApiException $e) {
            $result['error'] = $e->getMessage();
        } catch (AllKeysExhaustedException $e) {
            $exhausted = $e;
        }

        foreach ($stale as $p) {
            if ($this->savePost($group, $p, true) !== null) {
                $result['stale']++;
            }
        }
        // Feed trả mới → cũ; gửi theo thứ tự cũ → mới cho dễ đọc trên Telegram.
        foreach (array_reverse($collected) as $p) {
            $saved = $this->savePost($group, $p, $dryRun);
            if ($saved === null) {
                continue;
            }
            $result['new_posts']++;
            $result['posts'][] = $saved;
            if ($saved['status'] === 'queued') {
                $result['queued']++;
            } elseif ($saved['status'] === 'skipped_seller') {
                $result['skipped_seller']++;
            } elseif ($saved['status'] === 'duplicate') {
                $result['duplicate']++;
            }
        }

        if ($exhausted) {
            throw $exhausted;
        }
        $this->updateGroup($group, $cycleNo, $result);
        return $result;
    }

    /** @return array<string, true> */
    private function knownIds(int $groupId, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $rows = Db::col(
            'SELECT fb_post_id FROM posts WHERE group_id = ? AND fb_post_id IN (' . Db::placeholders($ids) . ')',
            array_merge([$groupId], $ids)
        );
        return array_fill_keys(array_map('strval', $rows), true);
    }

    private function savePost(array $group, array $p, bool $dryRun): ?array
    {
        $authorId = isset($p['author']['id']) && $p['author']['id'] !== '' ? (string) $p['author']['id'] : null;
        $authorName = isset($p['author']['name']) ? mb_substr((string) $p['author']['name'], 0, 255) : null;
        $url = (string) ($p['url'] ?? $p['permalink'] ?? '');
        $text = isset($p['text']) && is_string($p['text']) ? $p['text'] : null;
        $authorId = $authorId === null ? null : mb_substr($authorId, 0, 191); // có thể là pfbid… dài 64+ ký tự
        $hash = self::contentHash($authorId, $text);
        $status = match (true) {
            $dryRun => 'baseline',
            Sellers::isSeller($authorId) => 'skipped_seller',
            $hash !== null && self::seenContent($hash) => 'duplicate',
            default => 'queued',
        };
        $row = [
            'group_id' => (int) $group['id'],
            'fb_post_id' => mb_substr((string) $p['id'], 0, 64),
            'author_id' => $authorId,
            'author_name' => $authorName,
            'text' => $text,
            'content_hash' => $hash,
            'url' => $url === '' ? null : mb_substr($url, 0, 500),
            'media' => self::extractMedia($p),
            'publish_time' => isset($p['publishTime']) ? (int) $p['publishTime'] : null,
            'first_seen_at' => Clock::str(),
            'status' => $status,
        ];
        $inserted = Db::exec(
            'INSERT IGNORE INTO posts (group_id, fb_post_id, author_id, author_name, text, content_hash, url, media, publish_time, first_seen_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values($row)
        );
        if ($inserted === 0) {
            return null;
        }
        $row['id'] = (int) Db::pdo()->lastInsertId();
        if ($row['status'] === 'queued') {
            Outbox::enqueuePost($row, $group);
        }
        return $row;
    }

    /**
     * Link ảnh/video của bài (JSON cho cột posts.media), null nếu không có.
     * API: "images" = mọi ảnh ở kích thước gốc (bài nhiều ảnh), "image" = 1 ảnh, "videoDetails" = {sdUrl, hdUrl, thumbnailUrl}.
     * Link fbcdn hết hạn sau khoảng 4 ngày, đủ để gửi ngay lên Telegram.
     */
    public static function extractMedia(array $p): ?string
    {
        $isUrl = static fn($u) => is_string($u) && preg_match('#^https?://#i', $u);
        $images = array_values(array_filter(is_array($p['images'] ?? null) ? $p['images'] : [], $isUrl));
        if (!$images && $isUrl($p['image'] ?? null)) {
            $images = [$p['image']];
        }
        $vd = is_array($p['videoDetails'] ?? null) ? $p['videoDetails'] : [];
        $video = array_filter(['sd' => $vd['sdUrl'] ?? null, 'hd' => $vd['hdUrl'] ?? null, 'thumb' => $vd['thumbnailUrl'] ?? null], $isUrl);
        if (!$images && !$video) {
            return null;
        }
        return json_encode([
            'images' => array_slice(array_values(array_unique($images)), 0, MessageFormatter::MAX_PHOTOS),
            'video' => $video ?: null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Mã nội dung để chống gửi lại bài đăng lại / đăng nhiều group (mỗi lần đăng có ID mới).
     * Bỏ khoảng trắng, emoji, ký hiệu, chữ hoa. Nội dung ngắn thì kèm tác giả để không gộp nhầm
     * hai người cùng viết "cần mua vps"; bài không có chữ thì không so trùng.
     */
    public static function contentHash(?string $authorId, ?string $text): ?string
    {
        $norm = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $text)) ?? '';
        if ($norm === '') {
            return null;
        }
        return md5(mb_strlen($norm) >= self::DEDUPE_MIN_CHARS ? $norm : ($authorId ?? '') . '|' . $norm);
    }

    /** Nội dung đã xuất hiện ở lần quét trước (trong khoảng thời gian cài ở trang Cài đặt) chưa. */
    private static function seenContent(string $hash): bool
    {
        $window = (string) Settings::get('dedupe_days', 'forever');
        if ($window === '0') {
            return false;
        }
        if ($window === 'forever') {
            return (bool) Db::val('SELECT 1 FROM posts WHERE content_hash = ? LIMIT 1', [$hash]);
        }
        $since = Clock::now()->modify('-' . max(1, (int) $window) . ' days');
        return (bool) Db::val('SELECT 1 FROM posts WHERE content_hash = ? AND first_seen_at >= ? LIMIT 1', [$hash, Clock::str($since)]);
    }

    private function updateGroup(array $group, ?int $cycleNo, array $result): void
    {
        $now = Clock::str();
        $id = (int) $group['id'];
        if ($result['error'] === null) {
            Db::run(
                'UPDATE fb_groups SET last_scanned_cycle = COALESCE(?, last_scanned_cycle), last_scanned_at = ?,
                        consecutive_errors = 0, paused_until_cycle = NULL, last_error = NULL,
                        cap_hits = cap_hits + ?, last_cap_hit_at = IF(? = 1, ?, last_cap_hit_at)
                 WHERE id = ?',
                [$cycleNo, $now, $result['cap_hit'] ? 1 : 0, $result['cap_hit'] ? 1 : 0, $now, $id]
            );
            return;
        }

        // Lỗi: không cập nhật last_scanned_cycle để đợt sau thử lại; lỗi liên tiếp thì tạm hoãn theo cấp số.
        $errors = (int) $group['consecutive_errors'] + 1;
        $pausedUntil = null;
        if ($cycleNo !== null && $errors >= self::ERRORS_BEFORE_PAUSE) {
            $pauseCycles = min(2 ** ($errors - self::ERRORS_BEFORE_PAUSE), Estimator::cyclesPer24h());
            $pausedUntil = $cycleNo + max(1, $pauseCycles);
        }
        Db::run(
            'UPDATE fb_groups SET consecutive_errors = ?, last_error = ?, last_error_at = ?, paused_until_cycle = ? WHERE id = ?',
            [$errors, mb_substr((string) $result['error'], 0, 1000), $now, $pausedUntil, $id]
        );
    }
}
