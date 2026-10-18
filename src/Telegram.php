<?php
declare(strict_types=1);

/** Client Bot API tối giản. Mọi hàm trả về mảng JSON của Telegram ({ok, result} hoặc {ok:false, error_code, description}). */
final class Telegram
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $token,
    ) {
    }

    public function call(string $method, array $params = [], int $timeout = 30): array
    {
        $res = $this->http->request(
            'POST',
            'https://api.telegram.org/bot' . $this->token . '/' . $method,
            ['Content-Type: application/json'],
            json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $timeout
        );
        $data = $res->json();
        if (is_array($data) && array_key_exists('ok', $data)) {
            return $data;
        }
        return [
            'ok' => false,
            'error_code' => $res->status,
            'description' => $res->status === 0 ? 'Lỗi mạng: ' . ($res->error ?? '') : 'HTTP ' . $res->status,
        ];
    }

    public function getMe(): array
    {
        return $this->call('getMe');
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    public function getUpdates(int $offset, int $timeout): array
    {
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ], $timeout + 15);
    }

    public function sendMessage(int $chatId, string $html, ?array $replyMarkup = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }
        return $this->call('sendMessage', $params);
    }

    /** Gửi 1 ảnh theo URL (Telegram tự tải); có chú thích HTML và nút nếu truyền vào. */
    public function sendPhoto(int $chatId, string $photoUrl, ?string $captionHtml = null, ?array $replyMarkup = null): array
    {
        $params = ['chat_id' => $chatId, 'photo' => $photoUrl];
        if ($captionHtml !== null) {
            $params['caption'] = $captionHtml;
            $params['parse_mode'] = 'HTML';
        }
        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }
        return $this->call('sendPhoto', $params, 60);
    }

    /** Album 2–10 ảnh theo URL (album không gắn được nút). */
    public function sendMediaGroup(int $chatId, array $photoUrls): array
    {
        $media = array_map(static fn(string $url) => ['type' => 'photo', 'media' => $url], array_values($photoUrls));
        return $this->call('sendMediaGroup', ['chat_id' => $chatId, 'media' => $media], 90);
    }

    public function editMessageReplyMarkup(int $chatId, int $messageId, ?array $replyMarkup): array
    {
        return $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup ?? ['inline_keyboard' => []],
        ]);
    }

    public function answerCallbackQuery(string $callbackId, string $text, bool $alert = false): array
    {
        return $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text, 'show_alert' => $alert]);
    }

    public function deleteMessage(int $chatId, int $messageId): array
    {
        return $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }
}
