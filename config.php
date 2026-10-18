<?php
// Cấu hình kết nối DB. File này nằm ngoài public/ nên không lộ ra web.
// Không ghi API key hay bot token ở đây: các bí mật đó chỉ nằm trong DB.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3307, // MariaDB của XAMPP trên máy này chạy cổng 3307 (xem xamppfiles/etc/my.cnf)
        'name' => getenv('SCANPOST_DB') ?: 'scanpostfbapi',
        'user' => 'root',
        'pass' => '',
    ],
    'timezone' => 'Asia/Ho_Chi_Minh',
];
