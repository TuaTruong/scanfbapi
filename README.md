# ScanPost Bot

Web quản lý Telegram bot quét bài Facebook group qua ScrapeCreators. Đặc tả đầy đủ ở `KE_HOACH.md`.

Gồm 3 phần dùng chung DB MariaDB `scanpostfbapi` (XAMPP, cổng **3307**):

| Phần | File | Chạy bằng |
|---|---|---|
| Web quản trị | `public/` | Apache (Alias `/scanpostfbapi`) |
| Scanner (quét định kỳ) | `bin/scanner.php` | launchd `com.scanpostfbapi.scanner` |
| Bot Telegram (long polling + gửi tin) | `bin/bot.php` | launchd `com.scanpostfbapi.bot` |

Trong tài liệu này `php` là PHP của XAMPP: `/Applications/XAMPP/xamppfiles/bin/php`.

## Chạy

1. Bật **MySQL** và **Apache** trong XAMPP Manager (`/Applications/XAMPP/manager-osx.app`).
   Alias đã có sẵn trong `xamppfiles/etc/httpd.conf`, bản sao lưu là `httpd.conf.bak-scanpostfbapi-*`.
2. Mở <http://localhost/scanpostfbapi/> và đăng nhập bằng mật khẩu admin.
3. Vào **Cài đặt**, nhập danh sách group, API key, khung giờ, thời gian nghỉ, bot token và mật khẩu bot (mặc định `cloudcheap`).
4. Mỗi người dùng nhắn `/start` cho bot rồi nhập mật khẩu bot.
5. Ở **Dashboard**, bấm **▶️ Bật quét**.

Mọi thay đổi trên web có hiệu lực ở vòng lặp kế tiếp của worker (≤ 10 giây), **không cần khởi động lại**.

Khi chưa bật Apache, có thể mở tạm bằng: `php -S 127.0.0.1:8099 -t public` rồi vào <http://127.0.0.1:8099/>.

## Đổi cài đặt bằng dòng lệnh (tuỳ chọn)

```bash
php bin/setup.php --bot-token=123:ABC --api-keys=KEY1,KEY2
php bin/setup.php --groups="https://www.facebook.com/groups/abc\n742354120555345"
php bin/setup.php --window=06:00-22:00 --rest=30 --scan-enabled=1
php bin/migrate.php --admin-password=MAT_KHAU_MOI   # đặt lại mật khẩu admin web
```

Bí mật (API key, bot token) chỉ lưu trong DB, không nằm trong file nào.

## Xem log

```bash
tail -f storage/logs/scanner.log storage/logs/bot.log
```

Trên web: **Nhật ký** (các đợt quét, 100 bài gần nhất, lỗi API kèm nguyên văn phản hồi).

## Dừng, bật, khởi động lại worker

```bash
launchctl list | grep scanpostfbapi                                   # trạng thái (cột 1 = PID)
launchctl kickstart -k gui/$(id -u)/com.scanpostfbapi.scanner         # khởi động lại (sau khi sửa code)
launchctl kickstart -k gui/$(id -u)/com.scanpostfbapi.bot
launchctl unload ~/Library/LaunchAgents/com.scanpostfbapi.scanner.plist   # dừng hẳn
launchctl load   ~/Library/LaunchAgents/com.scanpostfbapi.scanner.plist   # bật lại
```

Chỉ tạm ngừng quét thì bấm **⏸ Tắt quét** trên web, không cần dừng worker.
Bản gốc của file plist nằm trong `launchd/`.

**Mac ngủ thì bot dừng.** Muốn chạy 24/7: tắt sleep (System Settings → Energy, hoặc `caffeinate -s`), hoặc chuyển lên VPS.

## Kiểm thử

```bash
php bin/test.php                 # toàn bộ test, chạy trên DB riêng scanpostfbapi_test, không gọi mạng
php bin/test.php Bot             # lọc theo tên
php bin/scan-once.php https://www.facebook.com/groups/abc --pages=2 --dry-run   # quét thật 1 group, không gửi Telegram
```

`--dry-run` vẫn lưu bài (trạng thái "baseline") để lần quét sau không gửi lại.

## Chuyển lên VPS Linux

1. Cài PHP 8.2+ (`pdo_mysql`, `curl`, `mbstring`, `pcntl`), MariaDB/MySQL, Nginx hoặc Apache.
2. Copy thư mục dự án, sửa `config.php` (host, cổng, user, mật khẩu DB). Chỉ public thư mục `public/`.
3. `php bin/migrate.php --admin-password=...`. Muốn mang dữ liệu sang thì dump DB `scanpostfbapi` từ máy cũ.
4. Thay launchd bằng systemd, mỗi worker một service:

```ini
# /etc/systemd/system/scanpost-scanner.service  (bot: đổi scanner → bot)
[Service]
ExecStart=/usr/bin/php /opt/scanpostfbapi/bin/scanner.php
WorkingDirectory=/opt/scanpostfbapi
Restart=always
RestartSec=10
[Install]
WantedBy=multi-user.target
```

Code không cần sửa.

## Ghi chú vận hành

- 1 lần gọi API = 1 credit, trang đầu ≈ 4 bài, các trang sau ≈ 3 bài. Scanner dừng ngay khi gặp ≥ 2 bài đã thấy, nên group ít bài thường chỉ tốn 1 credit mỗi lần quét.
- ⚠️ **Có thể sót bài** ở trang Groups nghĩa là lần quét đã chạm trần số trang mà trang cuối vẫn toàn bài mới. Khi gặp, tăng số trang hoặc giảm N cho group đó.
- **Bỏ qua bài đăng cũ hơn N giờ** (mặc định 72, ở trang Cài đặt). Khi test thật, API từng trả nhầm cả một trang bài đăng từ 3–4 tuần trước, và hệ thống đã gửi chúng như bài mới. Bộ lọc này ghi nhận các bài đó nhưng không gửi. Group nào admin duyệt bài chậm hơn 3 ngày thì tăng số giờ lên.
- **Chống gửi lại bài trùng.** Có 2 lớp:
  - Theo ID bài: một bài đã thấy thì không bao giờ gửi lại.
  - Theo nội dung: khi bài được đăng lại hoặc đăng sang group khác, Facebook tạo ID mới; nếu nội dung đã xuất hiện ở lần quét trước thì không gửi. Khi so nội dung, bot bỏ qua dấu cách, emoji, ký hiệu và chữ hoa. Bài ngắn dưới 30 chữ cái chỉ tính là trùng khi cùng người đăng. Khoảng thời gian chống trùng chỉnh ở trang Cài đặt (mặc định: không bao giờ gửi lại).
- **Gửi kèm ảnh** (bật/tắt ở trang Cài đặt):
  - Bài 1 ảnh, chữ ngắn: một tin có ảnh, chữ làm chú thích, kèm nút.
  - Bài nhiều ảnh (tối đa 10) hoặc chữ dài hơn 1.024 ký tự: album ảnh trước, rồi tin chữ có nút.
  - Bài video: gửi ảnh bìa kèm ghi chú "Bài có video".
  - Link ảnh lấy từ máy chủ ảnh của Facebook và hết hạn sau khoảng 4 ngày. Nếu Telegram không tải được ảnh, bot vẫn gửi phần chữ.
- Key hết credit được tự kiểm tra lại mỗi 6 giờ (miễn phí). Nạp thêm tiền rồi bấm **Kiểm tra credit** ở trang API keys để dùng lại ngay.
- ID tác giả có thể là số hoặc chuỗi `pfbid…` dài 64 ký tự. Khi đó nút seller dùng id bài trong DB (`sp:<id>`) cho vừa giới hạn 64 byte của Telegram.
