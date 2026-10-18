<?php
// Tạo DB, bảng và settings mặc định. Chạy lại nhiều lần không lỗi.
// Dùng: php bin/migrate.php [--admin-password=MẬT_KHẨU]
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('', ['admin-password:']);
try {
    foreach (Migrator::run($opts['admin-password'] ?? null) as $line) {
        echo "• $line\n";
    }
    echo "Xong.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Lỗi migrate: ' . $e->getMessage() . "\n");
    exit(1);
}
