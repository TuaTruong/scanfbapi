<?php
declare(strict_types=1);

/**
 * Xử lý update Telegram (đăng nhập bằng mật khẩu, lệnh, nút seller) và gửi tin từ outbox.
 * bin/bot.php chỉ là vòng lặp gọi vào đây.
 */
final class Bot
{
    public const MAX_FAILED_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;
    private const SAME_CHAT_GAP = 1.1;   // giây giữa 2 tin cùng chat (giới hạn ~1 tin/giây)
    private const MAX_SEND_ATTEMPTS = 3;

    private ?string $lastChat = null;
    private float $lastSentAt = 0.0;

    public function __construct(private readonly Telegram $tg)
    {
    }

    public function handleUpdate(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        } elseif (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    // ───────────────────────── Tin nhắn & đăng nhập ─────────────────────────

    private function handleMessage(array $msg): void
    {
        if (($msg['chat']['type'] ?? '') !== 'private') {
            return; // chỉ dùng trong chat riêng với bot
        }
        $chatId = (int) $msg['chat']['id'];
        $user = $this->touchUser($chatId, $msg['from'] ?? []);
        $text = trim((string) ($msg['text'] ?? ''));
        $command = str_starts_with($text, '/') ? strtolower(explode('@', strtok($text, ' '))[0]) : null;

        if ($user['locked_until'] !== null && Clock::parse($user['locked_until']) > Clock::now()) {
            if ($user['state'] === 'awaiting_password' && $command === null && $text !== '') {
                $this->deleteQuietly($chatId, (int) $msg['message_id']);
            }
            $this->reply($chatId, '🔒 Bạn đã nhập sai mật khẩu quá nhiều lần. Thử lại sau '
                . Clock::parse($user['locked_until'])->format('H:i') . '.');
            return;
        }

        $authorized = $user['state'] === 'authorized';
        switch ($command) {
            case '/start':
                if ($authorized) {
                    $this->reply($chatId, "✅ Bạn đã đăng nhập. Bot sẽ gửi bài mới tại đây.\n\n" . self::statusText());
                } else {
                    $this->askPassword($chatId);
                }
                return;
            case '/stop':
                if ($authorized) {
                    Db::run("UPDATE tg_users SET state = 'new' WHERE chat_id = ?", [$chatId]);
                    Outbox::cancelForChat($chatId, 'stop');
                    $this->reply($chatId, '⏹ Đã ngừng nhận tin. Gửi /start để đăng nhập lại.');
                } else {
                    $this->askPassword($chatId);
                }
                return;
            case '/status':
                $authorized ? $this->reply($chatId, self::statusText()) : $this->askPassword($chatId);
                return;
            case '/scan':
                $authorized ? $this->requestScan($chatId) : $this->askPassword($chatId);
                return;
            case '/help':
                $authorized ? $this->reply($chatId, self::helpText()) : $this->askPassword($chatId);
                return;
        }

        if ($authorized) {
            $this->reply($chatId, self::helpText());
            return;
        }
        if ($user['state'] !== 'awaiting_password' || $command !== null || $text === '') {
            $this->askPassword($chatId);
            return;
        }
        $this->checkPassword($chatId, $user, $text, (int) $msg['message_id']);
    }

    /** /scan: như nút "Quét ngay" trên web; scanner báo kết quả lại cho chat này khi xong. */
    private function requestScan(int $chatId): void
    {
        if (Settings::fresh('scan_enabled') !== '1') {
            $this->reply($chatId, '⏸ Đang tắt quét. Bật quét trên web trước rồi gửi lại /scan.');
            return;
        }
        if (Settings::fresh('run_now') === '1') {
            $this->reply($chatId, '⏳ Đã có yêu cầu quét đang chờ, bot sẽ báo khi xong.');
            return;
        }
        Settings::set('run_now_chat_id', $chatId);
        Settings::set('run_now', '1');
        $groups = (int) Db::val('SELECT COUNT(*) FROM fb_groups WHERE in_list = 1 AND enabled = 1');
        $this->reply($chatId, "⚡ Đang quét ngay (bỏ qua khung giờ và thời gian nghỉ, group nào đến hạn theo N mới quét; đang bật $groups group). Bài mới sẽ gửi tới đây, quét xong bot báo kết quả.");
    }

    private function askPassword(int $chatId): void
    {
        Db::run("UPDATE tg_users SET state = 'awaiting_password' WHERE chat_id = ?", [$chatId]);
        $this->reply($chatId, '🔒 Nhập mật khẩu để sử dụng bot:');
    }

    private function checkPassword(int $chatId, array $user, string $password, int $messageId): void
    {
        // Xoá tin chứa mật khẩu khỏi đoạn chat (bot được xoá tin đến trong chat riêng).
        $this->deleteQuietly($chatId, $messageId);
        $hash = (string) Settings::fresh('bot_password_hash');
        if ($hash !== '' && password_verify($password, $hash)) {
            Db::run(
                "UPDATE tg_users SET state = 'authorized', failed_attempts = 0, locked_until = NULL, authorized_at = ?, blocked_bot = 0 WHERE chat_id = ?",
                [Clock::str(), $chatId]
            );
            $this->reply($chatId, "✅ Đăng nhập thành công. Bot sẽ gửi bài mới tại đây.\n\n" . self::helpText());
            return;
        }
        $failed = (int) $user['failed_attempts'] + 1;
        if ($failed >= self::MAX_FAILED_ATTEMPTS) {
            $until = Clock::now()->modify('+' . self::LOCK_MINUTES . ' minutes');
            Db::run('UPDATE tg_users SET failed_attempts = 0, locked_until = ? WHERE chat_id = ?', [Clock::str($until), $chatId]);
            $this->reply($chatId, '🔒 Sai mật khẩu ' . self::MAX_FAILED_ATTEMPTS . ' lần. Tạm khoá đến ' . $until->format('H:i') . '.');
            return;
        }
        Db::run('UPDATE tg_users SET failed_attempts = ? WHERE chat_id = ?', [$failed, $chatId]);
        $this->reply($chatId, '❌ Sai mật khẩu (còn ' . (self::MAX_FAILED_ATTEMPTS - $failed) . " lần thử). Nhập lại:");
    }

    private function touchUser(int $chatId, array $from): array
    {
        Db::run(
            'INSERT INTO tg_users (chat_id, username, first_name, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name),
                                     last_seen_at = VALUES(last_seen_at), blocked_bot = 0',
            [$chatId, isset($from['username']) ? mb_substr((string) $from['username'], 0, 64) : null,
                isset($from['first_name']) ? mb_substr((string) $from['first_name'], 0, 128) : null, Clock::str(), Clock::str()]
        );
        if (Db::val('SELECT locked_until FROM tg_users WHERE chat_id = ? AND locked_until IS NOT NULL AND locked_until <= ?', [$chatId, Clock::str()])) {
            Db::run('UPDATE tg_users SET locked_until = NULL WHERE chat_id = ?', [$chatId]);
        }
        return Db::one('SELECT * FROM tg_users WHERE chat_id = ?', [$chatId]);
    }

    // ───────────────────────── Nút đánh dấu seller ─────────────────────────

    private function handleCallback(array $cq): void
    {
        $cbId = (string) $cq['id'];
        $chatId = (int) ($cq['message']['chat']['id'] ?? $cq['from']['id'] ?? 0);
        $user = Db::one('SELECT * FROM tg_users WHERE chat_id = ?', [$chatId]);
        if (!$user || $user['state'] !== 'authorized') {
            $this->tg->answerCallbackQuery($cbId, '🔒 Bạn chưa đăng nhập. Gửi /start', true);
            return;
        }
        if (!preg_match('/^(s|us|sp|usp):(.+)$/', (string) ($cq['data'] ?? ''), $m)) {
            $this->tg->answerCallbackQuery($cbId, 'Nút không hợp lệ');
            return;
        }
        $mark = $m[1] === 's' || $m[1] === 'sp';
        $authorId = $m[2];
        $postId = null;
        if ($m[1] === 'sp' || $m[1] === 'usp') {
            $postId = (int) $m[2];
            $authorId = (string) Db::val('SELECT author_id FROM posts WHERE id = ?', [$postId]);
            if ($authorId === '') {
                $this->tg->answerCallbackQuery($cbId, 'Không tìm thấy bài viết');
                return;
            }
        }
        $realName = Sellers::latestAuthorName($authorId);
        $name = $realName ?? $authorId;

        if ($mark) {
            if (Sellers::mark($authorId, $name, $chatId)) {
                $this->tg->answerCallbackQuery($cbId, "🚫 Đã chặn bài của $name");
            } else {
                $seller = Sellers::find($authorId);
                $this->tg->answerCallbackQuery($cbId, 'Đã là seller (do ' . Sellers::markerLabel($seller ?? []) . ' đánh dấu)');
            }
        } else {
            $this->tg->answerCallbackQuery($cbId, Sellers::unmark($authorId) ? "↩️ Đã bỏ đánh dấu seller $name" : 'Người này không còn là seller');
        }

        $messageId = (int) ($cq['message']['message_id'] ?? 0);
        if ($messageId > 0) {
            $url = MessageFormatter::urlFromMarkup($cq['message']['reply_markup'] ?? null);
            $this->tg->editMessageReplyMarkup($chatId, $messageId, MessageFormatter::keyboard($url, $authorId, $mark, $postId, $realName));
        }
    }

    // ───────────────────────── Gửi outbox ─────────────────────────

    /** Gửi tối đa $limit tin đang chờ. @return int số tin gửi thành công */
    public function sendOutbox(int $limit = 20): int
    {
        $rows = Db::all(
            "SELECT o.*, u.state AS user_state, u.blocked_bot, p.author_id
             FROM outbox o
             LEFT JOIN tg_users u ON u.chat_id = o.chat_id
             LEFT JOIN posts p ON p.id = o.post_id
             WHERE o.status = 'pending' AND (o.not_before IS NULL OR o.not_before <= ?)
             ORDER BY o.id LIMIT " . max(1, $limit),
            [Clock::str()]
        );
        $deferred = [];
        $sent = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $chatId = (int) $row['chat_id'];
            if (isset($deferred[$chatId])) {
                continue;
            }
            if ($row['user_state'] !== 'authorized' || (int) $row['blocked_bot'] === 1) {
                Db::run("UPDATE outbox SET status = 'cancelled', last_error = 'user not authorized' WHERE id = ?", [$id]);
                continue;
            }
            if ($row['kind'] === 'post' && Sellers::isSeller($row['author_id'])) {
                Db::run("UPDATE outbox SET status = 'cancelled', last_error = 'seller' WHERE id = ?", [$id]);
                continue;
            }

            $payload = json_decode((string) $row['payload'], true) ?: [];
            if ($row['kind'] === 'post' && $row['post_id'] !== null) {
                $payload = Outbox::buildPostPayload((int) $row['post_id']) ?? $payload; // thông tin group mới nhất
            }
            $text = (string) ($payload['text'] ?? '');
            $markup = $payload['reply_markup'] ?? null;
            $photos = Settings::get('send_images', '1') === '1' ? array_values($payload['photos'] ?? []) : [];
            $mediaNote = null;

            if ($photos && !(int) $row['media_sent']) {
                if (count($photos) === 1 && MessageFormatter::captionFits($text)) {
                    // 1 ảnh + chữ ngắn: một tin duy nhất, chữ làm chú thích, nút ngay dưới ảnh.
                    $res = $this->send($chatId, fn() => $this->tg->sendPhoto($chatId, $photos[0], $text, $markup));
                    if (!empty($res['ok'])) {
                        $this->markSent($id, $res, null);
                        $sent++;
                        continue;
                    }
                    if ((int) ($res['error_code'] ?? 0) !== 400) {
                        $this->fail($row, $res, $deferred);
                        continue;
                    }
                    $mediaNote = 'Không gửi được ảnh: ' . ($res['description'] ?? ''); // link hỏng/hết hạn: vẫn gửi chữ
                } else {
                    // Nhiều ảnh hoặc chữ dài hơn chú thích: gửi ảnh/album trước, rồi tin chữ có nút (album không gắn được nút).
                    $res = $this->send($chatId, fn() => count($photos) === 1
                        ? $this->tg->sendPhoto($chatId, $photos[0])
                        : $this->tg->sendMediaGroup($chatId, $photos));
                    if (!empty($res['ok'])) {
                        Db::run('UPDATE outbox SET media_sent = 1 WHERE id = ?', [$id]); // gửi lại phần chữ thì không lặp album
                    } elseif ((int) ($res['error_code'] ?? 0) === 400) {
                        $mediaNote = 'Không gửi được ảnh: ' . ($res['description'] ?? '');
                    } else {
                        $this->fail($row, $res, $deferred);
                        continue;
                    }
                }
            }

            $res = $this->send($chatId, fn() => $this->tg->sendMessage($chatId, $text, $markup));
            if (!empty($res['ok'])) {
                $this->markSent($id, $res, $mediaNote);
                $sent++;
            } else {
                $this->fail($row, $res, $deferred);
            }
        }
        return $sent;
    }

    /** Giãn cách tin trong cùng chat rồi gọi API. */
    private function send(int $chatId, callable $call): array
    {
        $this->pace($chatId);
        $res = $call();
        $this->lastChat = (string) $chatId;
        $this->lastSentAt = microtime(true);
        return $res;
    }

    private function markSent(int $id, array $res, ?string $note): void
    {
        Db::run(
            "UPDATE outbox SET status = 'sent', attempts = attempts + 1, tg_message_id = ?, sent_at = ?, last_error = ? WHERE id = ?",
            [(int) ($res['result']['message_id'] ?? 0), Clock::str(), $note === null ? null : mb_substr($note, 0, 500), $id]
        );
    }

    /** 429 → hoãn cả chat; 403 → người dùng chặn bot; lỗi khác → thử lại tối đa 3 lần. */
    private function fail(array $row, array $res, array &$deferred): void
    {
        $id = (int) $row['id'];
        $chatId = (int) $row['chat_id'];
        $code = (int) ($res['error_code'] ?? 0);
        $desc = mb_substr((string) ($res['description'] ?? ''), 0, 500);
        if ($code === 429) {
            $retry = max(1, (int) ($res['parameters']['retry_after'] ?? 5));
            Db::run(
                "UPDATE outbox SET not_before = ?, last_error = ? WHERE chat_id = ? AND status = 'pending'",
                [Clock::str(Clock::now()->modify("+$retry seconds")), $desc, $chatId]
            );
            $deferred[$chatId] = true;
        } elseif ($code === 403) {
            Db::run('UPDATE tg_users SET blocked_bot = 1 WHERE chat_id = ?', [$chatId]);
            Outbox::cancelForChat($chatId, 'blocked: ' . $desc);
            $deferred[$chatId] = true;
        } else {
            $attempts = (int) $row['attempts'] + 1;
            if ($attempts >= self::MAX_SEND_ATTEMPTS) {
                Db::run("UPDATE outbox SET status = 'failed', attempts = ?, last_error = ? WHERE id = ?", [$attempts, $desc, $id]);
            } else {
                Db::run(
                    'UPDATE outbox SET attempts = ?, last_error = ?, not_before = ? WHERE id = ?',
                    [$attempts, $desc, Clock::str(Clock::now()->modify('+' . (30 * $attempts) . ' seconds')), $id]
                );
            }
        }
    }

    private function pace(int $chatId): void
    {
        if ($this->lastChat === (string) $chatId) {
            $wait = self::SAME_CHAT_GAP - (microtime(true) - $this->lastSentAt);
            if ($wait > 0) {
                Clock::sleep($wait);
            }
        }
    }

    // ───────────────────────── Tiện ích ─────────────────────────

    private function reply(int $chatId, string $html): void
    {
        $this->tg->sendMessage($chatId, $html);
    }

    private function deleteQuietly(int $chatId, int $messageId): void
    {
        if ($messageId > 0) {
            $this->tg->deleteMessage($chatId, $messageId);
        }
    }

    public static function helpText(): string
    {
        return "Lệnh:\n/scan — quét ngay một đợt\n/status — trạng thái quét, đợt kế tiếp, credit còn lại\n/stop — ngừng nhận tin\n\n"
            . 'Bấm <b>🚫 Đánh dấu seller</b> dưới bài để không nhận bài của người đó nữa (áp dụng cho mọi người dùng bot).';
    }

    public static function statusText(): string
    {
        Settings::reload();
        $enabled = Settings::bool('scan_enabled');
        $next = Clock::parse(Settings::get('next_run_at'));
        $groups = (int) Db::val('SELECT COUNT(*) FROM fb_groups WHERE in_list = 1 AND enabled = 1');
        $activeKeys = (int) Db::val("SELECT COUNT(*) FROM api_keys WHERE in_list = 1 AND status = 'active'");
        $lines = [
            '📊 <b>Trạng thái</b>',
            '• Quét: ' . ($enabled ? 'đang bật' : 'đang tắt') . ' · khung giờ ' . MessageFormatter::escape(Settings::get('window_start') . '–' . Settings::get('window_end'))
                . ' · nghỉ ' . Settings::int('rest_minutes') . ' phút',
            '• Đợt hiện tại: #' . Settings::int('cycle_no')
                . ($enabled && $next ? ' · đợt kế tiếp: ~' . $next->format('H:i d/m') : ''),
            '• Group đang quét: ' . $groups,
            '• Credit còn lại: ' . number_format(ApiKeys::totalCredits(), 0, ',', '.') . " ($activeKeys key hoạt động)",
            '• Seller đã chặn: ' . (int) Db::val('SELECT COUNT(*) FROM sellers'),
        ];
        return implode("\n", $lines);
    }
}
