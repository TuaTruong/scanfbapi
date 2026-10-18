<?php
// Nhập cấu hình từ dòng lệnh (thay cho trang Cài đặt trên web). Bí mật chỉ được lưu vào DB.
// Dùng:
//   php bin/setup.php --bot-token=123:ABC --api-keys=KEY1,KEY2 --groups="link1
// link2" [--scan-enabled=1] [--window=06:00-22:00] [--rest=30]
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$o = getopt('', ['bot-token:', 'api-keys:', 'groups:', 'scan-enabled:', 'window:', 'rest:']);
if (!$o) {
    fwrite(STDERR, "Dùng: php bin/setup.php [--bot-token=...] [--api-keys=K1,K2] [--groups=\"link1\\nlink2\"] [--scan-enabled=0|1] [--window=06:00-22:00] [--rest=30]\n");
    exit(1);
}

if (isset($o['bot-token'])) {
    $token = trim($o['bot-token']);
    $me = (new Telegram(new CurlHttpClient(), $token))->getMe();
    if (empty($me['ok'])) {
        fwrite(STDERR, 'Bot token không hợp lệ: ' . ($me['description'] ?? '?') . "\n");
        exit(1);
    }
    if ($token !== Settings::get('bot_token')) {
        Settings::set('tg_offset', 0);
    }
    Settings::set('bot_token', $token);
    Settings::set('bot_username', (string) $me['result']['username']);
    echo '• Bot token: ' . InputParser::mask($token) . ' → @' . $me['result']['username'] . "\n";
}

if (isset($o['api-keys'])) {
    $s = ApiKeys::syncFromText(str_replace(',', "\n", $o['api-keys']));
    $counts = (new ScrapeCreators(new CurlHttpClient()))->refreshKeys(false);
    echo "• API key: thêm {$s['added']}, bỏ {$s['removed']}, trùng {$s['duplicates']} · kiểm tra: " . json_encode($counts) . "\n";
    foreach ($s['errors'] as $e) {
        echo "  ! $e\n";
    }
}

if (isset($o['groups'])) {
    $s = Groups::syncFromText(str_replace('\n', "\n", $o['groups']));
    echo "• Group: thêm {$s['added']}, bỏ {$s['removed']}, thêm lại {$s['reactivated']}, trùng {$s['duplicates']}\n";
    foreach ($s['errors'] as $e) {
        echo "  ! $e\n";
    }
}

if (isset($o['window'])) {
    if (!preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $o['window'], $m)) {
        fwrite(STDERR, "--window phải có dạng 06:00-22:00\n");
        exit(1);
    }
    Settings::set('window_start', $m[1]);
    Settings::set('window_end', $m[2]);
    echo "• Khung giờ: {$m[1]} → {$m[2]}\n";
}

if (isset($o['rest'])) {
    Settings::set('rest_minutes', max(0, (int) $o['rest']));
    echo '• Nghỉ giữa các đợt: ' . (int) $o['rest'] . " phút\n";
}

if (isset($o['scan-enabled'])) {
    Settings::set('scan_enabled', $o['scan-enabled'] === '1' ? '1' : '0');
    echo '• Quét: ' . ($o['scan-enabled'] === '1' ? 'bật' : 'tắt') . "\n";
}
