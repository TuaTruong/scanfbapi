<?php
// Vòng lặp quét: khung giờ, thời gian nghỉ, đợt N, xoay key. Chạy nền bằng launchd.
// Dùng: /Applications/XAMPP/xamppfiles/bin/php bin/scanner.php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

const NAME = 'scanner';
Worker::boot(NAME);

$log = static fn(string $m) => Worker::log(NAME, $m);
$api = new ScrapeCreators(new CurlHttpClient());
$scanner = new GroupScanner($api, static fn() => Worker::heartbeat('heartbeat_scanner'));
$scheduler = new Scheduler($scanner, $log);
$lastAction = null;

while (!Worker::shouldStop()) {
    if (!Worker::waitForDb(NAME)) {
        break;
    }
    try {
        Settings::reload();
        Worker::heartbeat('heartbeat_scanner');

        $revived = $api->reviveIfDue();
        if ($revived !== null) {
            $log('Kiểm tra lại key hết credit: ' . json_encode($revived));
        }

        $action = $scheduler->tick();
        Settings::set('scanner_state', $action);
        if ($action !== $lastAction && !in_array($action, ['run', 'run_now'], true)) {
            $log(match ($action) {
                'disabled' => 'Đang tắt quét (bật trên web)',
                'outside_window' => 'Ngoài khung giờ ' . Settings::get('window_start') . '–' . Settings::get('window_end') . ', chờ',
                'wait' => 'Đang nghỉ, đợt kế tiếp lúc ' . Settings::get('next_run_at'),
                'no_keys' => 'Không còn API key dùng được, tạm dừng quét',
                default => $action,
            });
        }
        $lastAction = $action;

        Worker::sleep(match ($action) {
            'run', 'run_now' => 1,
            'no_keys' => 30,
            'wait' => (int) max(1, min(10, (Clock::parse(Settings::get('next_run_at'))?->getTimestamp() ?? 0) - time())),
            default => 10,
        });
    } catch (Throwable $e) {
        if (Db::isConnectionError($e)) {
            Db::disconnect();
        }
        $log('Lỗi: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        Worker::sleep(10);
    }
}
$log('Đã dừng');
