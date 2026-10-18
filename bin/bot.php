<?php
// Vòng lặp bot Telegram: long polling getUpdates + gửi tin từ outbox. Chạy nền bằng launchd.
// Dùng: /Applications/XAMPP/xamppfiles/bin/php bin/bot.php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

const NAME = 'bot';
Worker::boot(NAME);

$log = static fn(string $m) => Worker::log(NAME, $m);
$http = new CurlHttpClient();
$token = null;
$tg = null;
$bot = null;
$badToken = null;

while (!Worker::shouldStop()) {
    if (!Worker::waitForDb(NAME)) {
        break;
    }
    try {
        Settings::reload();
        Worker::heartbeat('heartbeat_bot');

        $current = trim((string) Settings::get('bot_token'));
        if ($current === '' || $current === $badToken) {
            if ($current === '' && $token !== null) {
                $log('Bot token đã bị xoá, chờ token mới');
            }
            $token = null;
            Worker::sleep(10);
            continue;
        }
        if ($current !== $token) {
            $tg = new Telegram($http, $current);
            $me = $tg->getMe();
            if (empty($me['ok'])) {
                $log('Bot token không dùng được: ' . ($me['description'] ?? 'lỗi không rõ'));
                if ((int) ($me['error_code'] ?? 0) === 401 || (int) ($me['error_code'] ?? 0) === 404) {
                    $badToken = $current; // chờ người dùng nhập token khác
                }
                Worker::sleep(15);
                continue;
            }
            $tg->deleteWebhook();
            $tg->call('setMyCommands', ['commands' => [
                ['command' => 'scan', 'description' => 'Quét ngay một đợt'],
                ['command' => 'status', 'description' => 'Trạng thái quét, credit còn lại'],
                ['command' => 'stop', 'description' => 'Ngừng nhận tin'],
                ['command' => 'start', 'description' => 'Đăng nhập / bắt đầu nhận tin'],
            ]]);
            $token = $current;
            $badToken = null;
            $bot = new Bot($tg);
            Settings::set('bot_username', (string) ($me['result']['username'] ?? ''));
            $log('Đã kết nối bot @' . ($me['result']['username'] ?? '?'));
        }

        $offset = Settings::int('tg_offset');
        $res = $tg->getUpdates($offset, Outbox::hasReady() ? 1 : 20);
        if (!empty($res['ok'])) {
            foreach ($res['result'] as $update) {
                try {
                    $bot->handleUpdate($update);
                    if (isset($update['callback_query'])) {
                        $cq = $update['callback_query'];
                        $log('Nút ' . ($cq['data'] ?? '?') . ' từ chat ' . ($cq['from']['id'] ?? '?'));
                    }
                } catch (Throwable $e) {
                    if (Db::isConnectionError($e)) {
                        throw $e; // xử lý lại update này sau khi nối lại DB
                    }
                    $log('Lỗi xử lý update ' . ($update['update_id'] ?? '?') . ': ' . $e->getMessage());
                }
                Settings::set('tg_offset', (int) $update['update_id'] + 1);
            }
        } else {
            $code = (int) ($res['error_code'] ?? 0);
            $log('getUpdates lỗi: ' . ($res['description'] ?? $code));
            if ($code === 401 || $code === 404) {
                $badToken = $current;
                $token = null;
            } elseif ($code === 409) {
                $tg->deleteWebhook();
            }
            Worker::sleep(5);
        }

        $sent = $bot->sendOutbox(20);
        if ($sent > 0) {
            $log("Đã gửi $sent tin");
        }
    } catch (Throwable $e) {
        if (Db::isConnectionError($e)) {
            Db::disconnect();
        }
        $log('Lỗi: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        Worker::sleep(5);
    }
}
$log('Đã dừng');
