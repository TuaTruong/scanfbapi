<?php
declare(strict_types=1);

/** Tiện ích chung cho tiến trình nền: khoá một bản, log, heartbeat, dừng êm khi nhận SIGTERM. */
final class Worker
{
    private static bool $stop = false;
    /** @var resource|null */
    private static $lock = null;

    public static function boot(string $name): void
    {
        $dir = APP_ROOT . '/storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        self::$lock = fopen("$dir/$name.lock", 'c');
        if (!self::$lock || !flock(self::$lock, LOCK_EX | LOCK_NB)) {
            self::log($name, 'Đã có một bản đang chạy, thoát.');
            exit(0);
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $handler = static function () use ($name): void {
                self::$stop = true;
                self::log($name, 'Nhận tín hiệu dừng, sẽ thoát sau bước hiện tại');
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }
        self::log($name, 'Khởi động (PID ' . getmypid() . ', PHP ' . PHP_VERSION . ')');
    }

    public static function shouldStop(): bool
    {
        return self::$stop;
    }

    public static function log(string $name, string $message): void
    {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] [$name] $message\n");
    }

    public static function heartbeat(string $key): void
    {
        Settings::set($key, Clock::str());
    }

    /** Ngủ theo từng giây để dừng nhanh khi có tín hiệu. */
    public static function sleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !self::$stop; $i++) {
            sleep(1);
        }
    }

    public static function waitForDb(string $name): bool
    {
        return Db::waitUntilReady(static fn(string $m) => self::log($name, $m), 10, static fn() => self::$stop);
    }
}
