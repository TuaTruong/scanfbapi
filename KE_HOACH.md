# Kế hoạch: Web quản lý Telegram Bot quét bài Facebook Group

Dự án: `scanpostfbapi` · Thư mục: `/Applications/XAMPP/scanpostfbapi` · Lập ngày: 18/10/2026

> Tài liệu này là đặc tả để **một agent code làm trọn từ A đến Z trong một lượt**, không chia giai đoạn. Cách làm và tiêu chí hoàn thành nằm ở **mục 9**.

---

## 1. Mục tiêu

Một trang web quản trị (chạy trên XAMPP) để cấu hình và điều khiển một Telegram bot. Bot có nhiệm vụ:

1. Quét định kỳ bài viết mới trong nhiều Facebook group qua API ScrapeCreators.
2. Gửi nội dung và link bài viết lên Telegram cho những người đã nhập đúng mật khẩu bot.
3. Cho phép bấm nút **đánh dấu seller**. Từ lần quét sau, bài của người đó không được gửi nữa.
4. Mỗi group có thể cài **quét sau mỗi N đợt**, dành cho những group mà admin chỉ duyệt bài 1–2 lần mỗi ngày.
5. Mỗi group có thể cài **số trang lấy mỗi lần quét**, vì có group nhiều bài, có group ít bài. Mặc định 2 trang (≈7 bài).
6. Dùng **nhiều API key**. Key nào hết credit thì tự chuyển sang key kế tiếp.

---

## 2. Những điều đã kiểm chứng về API (test ngày 17–18/10/2026)

| Hạng mục | Kết quả thực tế |
|---|---|
| Endpoint | `GET https://api.scrapecreators.com/v1/facebook/group/posts`, header `x-api-key` |
| Tham số | `url` hoặc `group_id`, `sort_by`, `cursor` (không có tham số chỉnh số lượng bài) |
| Số bài mỗi lần gọi | Trang đầu **4 bài**, các trang sau **3 bài** |
| Chi phí | **1 credit cho mỗi lần gọi** |
| Thời gian | Khoảng 4–6 giây mỗi lần gọi |
| Phân trang | `cursor` nối tiếp, 6 trang ra 19 bài, không trùng |
| `sort_by` | `CHRONOLOGICAL` (mặc định), `TOP_POSTS`, `RECENT_ACTIVITY` chạy được. `CHRONOLOGICAL_LISTINGS` trả **500** sau ~30s nên **không dùng** |
| Key sai hoặc thiếu | HTTP **401** `{"success":false,"message":"Invalid API key"}` |
| Xem số credit | `GET /v1/account/credit-balance` trả `{"creditCount": N}`. **Không tốn credit** (gọi 2 lần, số dư không đổi) |
| Hết credit | **Chưa kiểm chứng được** (phải xài cạn key mới thấy). Xử lý phòng thủ, xem mục 6 |
| Dữ liệu mỗi bài | `id`, `text` (có thể `null`), `url`, `permalink`, `author{id,name}`, `publishTime` (unix), `reactionCount`, `commentCount`, `topComments` |
| PHP của XAMPP | PHP 8.2.4 gọi được HTTPS tới ScrapeCreators và Telegram. Đã có `pdo_mysql`, `curl`, `mbstring`. MariaDB 10.4.28 |

Hệ quả cho thiết kế: muốn biết có bài mới thì ít nhất phải gọi 1 lần (1 credit) cho mỗi group mỗi đợt quét. Vì vậy cài đặt **"quét sau mỗi N đợt"** là cách chính để tiết kiệm credit.

---

## 3. Kiến trúc tổng thể

```
                ┌───────────────────────────── MariaDB (XAMPP) ─────────────────────────────┐
                │ settings · fb_groups · api_keys · posts · sellers · tg_users · outbox · logs │
                └──────▲──────────────────────▲───────────────────────────▲─────────────────┘
                       │ đọc/ghi cấu hình      │ ghi bài mới + hàng chờ     │ đọc hàng chờ, ghi seller
┌──────────────────────┴───┐   ┌───────────────┴─────────────┐   ┌─────────┴──────────────────────┐
│  Web admin (Apache+PHP)  │   │  bin/scanner.php (CLI loop)  │   │  bin/bot.php (CLI loop)        │
│  - đăng nhập admin       │   │  - khung giờ, nghỉ, đợt N    │   │  - getUpdates (long polling)   │
│  - form cài đặt          │   │  - gọi ScrapeCreators        │   │  - /start → hỏi mật khẩu       │
│  - bảng group/key/seller │   │  - xoay vòng API key         │   │  - nút đánh dấu/bỏ seller      │
│  - log, ước tính credit  │   │  - lọc seller, chống trùng   │   │  - gửi tin từ outbox (rate     │
│  - nút Bật/Tắt, Quét ngay│   │  - đẩy tin vào outbox        │   │    limit, retry, 429)          │
└──────────────────────────┘   └──────────────▲───────────────┘   └───────────────▲────────────────┘
                                              │ launchd giữ chạy (KeepAlive)      │
                                              └──────────── macOS ────────────────┘
```

**Lý do tách 3 phần:**
- **Web** chỉ ghi cấu hình vào DB, không chạy tác vụ dài, nên không bị timeout PHP.
- **Scanner** và **Bot** là hai tiến trình riêng. Một đợt quét có thể mất vài phút; nếu chạy chung, nút bấm trên Telegram sẽ bị treo trong lúc quét.
- Scanner không gửi Telegram trực tiếp mà ghi vào bảng **`outbox`**, Bot đọc ra rồi gửi. Nhờ vậy Telegram lỗi hoặc giới hạn tốc độ (429) thì không mất tin, có thể gửi lại.
- Chạy trên máy local nên **dùng long polling (`getUpdates`)**, không dùng webhook vì webhook cần HTTPS công khai. Khi khởi động, bot gọi `deleteWebhook` một lần.

**Công nghệ:** PHP 8.2 thuần + PDO + MariaDB, giao diện Bootstrap 5. Không cần framework hay Composer để chạy thẳng trên XAMPP. Có thể tái dùng CSS của template Velzon có sẵn trong `/Applications/XAMPP` nếu muốn giao diện đẹp hơn.

**Múi giờ:** `Asia/Ho_Chi_Minh` cho cả PHP (`date_default_timezone_set`) lẫn phiên MySQL (`SET time_zone = '+07:00'`).

---

## 4. Cấu trúc thư mục

```
/Applications/XAMPP/scanpostfbapi/
├── KE_HOACH.md
├── config.php                 # Thông tin kết nối DB (nằm NGOÀI public)
├── public/                    # Apache Alias trỏ vào đây, chỉ thư mục này lộ ra web
│   ├── index.php              # Dashboard: trạng thái bot, credit, ước tính
│   ├── login.php / logout.php
│   ├── settings.php           # Form cài đặt chung (các ô input chính)
│   ├── groups.php             # Bảng group + cài đặt từng group
│   ├── keys.php               # Bảng API key + kiểm tra credit
│   ├── sellers.php            # Danh sách seller, bỏ đánh dấu
│   ├── users.php              # Người dùng Telegram đã đăng nhập
│   ├── logs.php               # Nhật ký các đợt quét, bài đã gửi
│   ├── actions.php            # POST: bật/tắt, quét ngay, test token...
│   └── assets/
├── src/
│   ├── Db.php                 # PDO singleton, tự kết nối lại khi mất kết nối
│   ├── Settings.php           # Đọc/ghi bảng settings
│   ├── Auth.php               # Đăng nhập admin, CSRF
│   ├── InputParser.php        # Chuẩn hoá link/UID group, tách dòng key
│   ├── ScrapeCreators.php     # HTTP client + KeyPool (xoay vòng key)
│   ├── GroupScanner.php       # Quét 1 group: phân trang, chống trùng, lọc seller
│   ├── Scheduler.php          # Khung giờ, thời gian nghỉ, đếm đợt, nhóm đến hạn
│   ├── Telegram.php           # sendMessage, editMessageReplyMarkup, answerCallbackQuery...
│   └── MessageFormatter.php   # Dựng nội dung tin + bàn phím inline
├── bin/
│   ├── migrate.php            # Tạo bảng từ sql/schema.sql, tạo mật khẩu admin
│   ├── scanner.php            # Vòng lặp quét
│   ├── bot.php                # Vòng lặp bot + gửi outbox
│   └── scan-once.php          # Test: quét 1 group, in kết quả, không gửi Telegram
├── sql/schema.sql
├── launchd/
│   ├── com.scanpostfbapi.scanner.plist
│   └── com.scanpostfbapi.bot.plist
└── storage/logs/              # scanner.log, bot.log
```

**Cấu hình Apache:** thư mục dự án nằm ngoài `htdocs`, nên thêm vào `/Applications/XAMPP/xamppfiles/etc/httpd.conf`:

```apache
Alias /scanpostfbapi "/Applications/XAMPP/scanpostfbapi/public"
<Directory "/Applications/XAMPP/scanpostfbapi/public">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require local          # chỉ máy này truy cập; muốn dùng trong LAN thì đổi thành "Require ip 192.168.1"
</Directory>
```

Truy cập tại `http://localhost/scanpostfbapi/`.

---

## 5. Cơ sở dữ liệu (MariaDB)

```sql
-- Cấu hình dạng key-value
settings (k VARCHAR(64) PK, v TEXT, updated_at)
--  scan_enabled (0/1), window_start '06:00', window_end '22:00', rest_minutes 30,
--  max_pages_default 2 (số trang gán cho group mới thêm), bot_token,
--  bot_password_hash (mật khẩu chung cho mọi người dùng bot, migrate tạo sẵn hash của 'cloudcheap'), admin_password_hash,
--  cycle_no, next_run_at, run_now (0/1), heartbeat_scanner, heartbeat_bot,
--  all_keys_exhausted_notified (0/1)

fb_groups (
  id INT PK AI,
  input_raw VARCHAR(500),           -- dòng gốc người dùng nhập
  fb_ref VARCHAR(255) UNIQUE,       -- giá trị chuẩn hoá: 'vpsmincom' hoặc '742354120555345'
  ref_type ENUM('url','group_id'),  -- số thuần thì gọi bằng group_id, còn lại gọi bằng url
  name VARCHAR(255) NULL,           -- tên hiển thị (người dùng sửa được)
  in_list TINYINT DEFAULT 1,        -- 0 = đã xoá khỏi textarea (giữ lịch sử, không quét)
  enabled TINYINT DEFAULT 1,        -- tạm tắt riêng group này
  every_n_cycles INT DEFAULT 1,     -- quét sau mỗi N đợt
  max_pages TINYINT NOT NULL DEFAULT 2,  -- số trang tối đa mỗi lần quét, 1–10 (1 trang = 1 credit ≈ 3–4 bài)
  cap_hits INT DEFAULT 0,           -- số lần quét chạm trần số trang mà trang cuối vẫn toàn bài mới (nguy cơ sót bài)
  last_cap_hit_at DATETIME NULL,
  last_scanned_cycle INT NULL,
  last_scanned_at DATETIME NULL,
  consecutive_errors INT DEFAULT 0,
  last_error TEXT NULL,
  created_at, updated_at
)

api_keys (
  id INT PK AI,
  api_key VARCHAR(128) UNIQUE,
  priority INT,                     -- theo thứ tự dòng trong textarea
  in_list TINYINT DEFAULT 1,
  status ENUM('active','exhausted','invalid') DEFAULT 'active',
  credits_remaining INT NULL,       -- lấy từ response hoặc từ credit-balance
  last_used_at, last_checked_at, last_error TEXT NULL
)

posts (
  id BIGINT PK AI,
  group_id INT, fb_post_id VARCHAR(64),
  author_id VARCHAR(64) NULL, author_name VARCHAR(255) NULL,
  text MEDIUMTEXT NULL, url VARCHAR(500), publish_time INT NULL,
  first_seen_at DATETIME,
  status ENUM('queued','skipped_seller','baseline'),
  UNIQUE (group_id, fb_post_id),
  INDEX (author_id)
)

sellers (
  author_id VARCHAR(64) PK, author_name VARCHAR(255),
  marked_by_chat_id BIGINT NULL, marked_at DATETIME, note VARCHAR(255) NULL
)

tg_users (
  chat_id BIGINT PK, username VARCHAR(64) NULL, first_name VARCHAR(128) NULL,
  state ENUM('new','awaiting_password','authorized','revoked') DEFAULT 'new',
  failed_attempts INT DEFAULT 0, locked_until DATETIME NULL,
  authorized_at DATETIME NULL, blocked_bot TINYINT DEFAULT 0
)

outbox (
  id BIGINT PK AI, chat_id BIGINT, post_id BIGINT NULL,
  kind ENUM('post','alert'),
  payload JSON,                     -- text + reply_markup đã dựng sẵn
  status ENUM('pending','sent','failed') DEFAULT 'pending',
  attempts INT DEFAULT 0, not_before DATETIME NULL,   -- dùng cho retry_after khi bị 429
  tg_message_id BIGINT NULL, last_error TEXT NULL,
  created_at, sent_at
)

scan_runs (
  id INT PK AI, cycle_no INT, started_at, ended_at,
  groups_due INT, groups_scanned INT, api_calls INT, credits_used INT,
  new_posts INT, queued_posts INT, skipped_seller INT, errors INT
)

api_calls (                          -- nhật ký từng lần gọi, phục vụ debug và thống kê credit
  id BIGINT PK AI, key_id INT, group_id INT, http_code INT,
  credits_charged INT NULL, duration_ms INT, posts_returned INT,
  error VARCHAR(500) NULL, called_at DATETIME
)
```

---

## 6. Logic lõi

### 6.1 Chuẩn hoá danh sách group (textarea)

Mỗi dòng là một group. Chấp nhận các dạng sau:

| Người dùng nhập | `fb_ref` | Gọi API bằng |
|---|---|---|
| `https://www.facebook.com/groups/vpsmincom` | `vpsmincom` | `url=https://www.facebook.com/groups/vpsmincom` |
| `https://facebook.com/groups/742354120555345/permalink/123/` | `742354120555345` | `group_id=742354120555345` |
| `742354120555345` | `742354120555345` | `group_id` |
| `m.facebook.com/groups/abc?ref=share` | `abc` | `url` |

Quy tắc khi bấm Lưu:
- Bỏ dòng trống, bỏ khoảng trắng, bỏ dòng trùng (so theo `fb_ref`).
- Dòng mới thì **thêm** group với cài đặt mặc định: số trang = `max_pages_default` (2), N = 1.
- Dòng bị xoá khỏi textarea thì `in_list = 0`. **Không xoá dữ liệu**, để giữ lịch sử bài đã thấy. Khi thêm lại, group không bị gửi trùng bài cũ.
- Cài đặt riêng từng group (N đợt, tên, số trang tối đa, bật/tắt) chỉnh ở **bảng bên dưới textarea**, không ghi trong textarea.

API key cũng theo cách tương tự: mỗi dòng một key, **thứ tự dòng là thứ tự ưu tiên**.

### 6.2 Lịch quét: khung giờ, thời gian nghỉ, đợt N

```
loop mãi mãi (scanner.php):
    ghi heartbeat_scanner = now
    nạp lại settings (để đổi cấu hình trên web có hiệu lực ngay)
    nếu scan_enabled = 0                → ngủ 15s, quay lại
    nếu run_now = 1                     → bỏ qua kiểm tra giờ, chạy đợt ngay, đặt run_now = 0
    nếu ngoài khung giờ                 → ngủ 30s, quay lại
    nếu now < next_run_at               → ngủ min(30s, còn lại), quay lại
    chạy_đợt()
    next_run_at = thời điểm KẾT THÚC đợt + rest_minutes
```

- **Khung giờ** hỗ trợ cả trường hợp qua nửa đêm, ví dụ `20:00 → 02:00`:
  `trong_khung = start <= end ? (start <= t < end) : (t >= start || t < end)`.
  Đợt quét chỉ **bắt đầu** trong khung giờ. Nếu đợt bắt đầu lúc 21:58 thì vẫn chạy cho xong.
- **Thời gian nghỉ** tính từ lúc đợt trước kết thúc, nên các đợt không bao giờ chồng lên nhau.
- **Đợt N cho từng group:**
  ```
  chạy_đợt():
      cycle_no += 1
      group đến hạn = in_list=1 AND enabled=1 AND
                      (last_scanned_cycle IS NULL OR cycle_no - last_scanned_cycle >= every_n_cycles)
                      AND không đang tạm hoãn do lỗi
      với mỗi group đến hạn (tuần tự): GroupScanner.scan(group)
      ghi scan_runs
  ```
  Ví dụ: nghỉ 30 phút, một đợt mất ~3 phút, tức khoảng 33 phút một đợt. Group đặt `N=6` sẽ được quét khoảng 3,3 giờ một lần, tức 4–5 lần mỗi ngày trong khung 6h–22h.
  Bảng group hiển thị cột **"Lần quét tới: đợt #X (~HH:MM)"** để dễ hình dung.
- `cycle_no` đếm liên tục qua các ngày, không reset.

### 6.3 Phân trang theo từng group

Mỗi group có ô **"Số trang mỗi lần quét"** (`max_pages`, từ 1 đến 10). **Mặc định 2 trang ≈ 7 bài.**

| Số trang | Số bài tối đa lấy được | Credit tối đa mỗi lần quét group |
|---|---|---|
| 1 | ≈ 4 | 1 |
| **2 (mặc định)** | **≈ 7** | **2** |
| 3 | ≈ 10 | 3 |
| 5 | ≈ 16 | 5 |
| 10 | ≈ 31 | 10 |

Bảng trên dựa theo số đo thực tế ở mục 2: trang đầu trả 4 bài, mỗi trang sau 3 bài.

- Đây là **mức trần**, không phải lần nào cũng gọi đủ số trang. Scanner dừng ngay khi đã đuổi kịp bài cũ (mục 6.4). Vì vậy group ít bài thường chỉ tốn 1 credit mỗi lần quét, dù đặt 2 hay 5 trang.
- Gợi ý cài đặt:
  - **Group ít bài** (vài bài mỗi ngày): 1–2 trang.
  - **Group nhiều bài:** tăng số trang **hoặc** giảm N để quét dày hơn. Quét dày thì mỗi lần có ít bài mới hơn, thường rẻ hơn tăng trang.
  - **Group duyệt bài 1–2 lần/ngày:** N lớn kèm số trang lớn (ví dụ N=6, 5 trang), vì mỗi lần admin duyệt sẽ đổ ra nhiều bài cùng lúc.
- **Cảnh báo sót bài:** nếu lần quét chạm trần số trang mà trang cuối vẫn toàn bài mới, rất có thể còn bài chưa lấy. Khi đó scanner tăng `cap_hits` và web hiện ⚠️ "Có thể sót bài" ở group đó, kèm gợi ý tăng số trang hoặc giảm N. Hệ thống **không tự tăng** số trang, để tránh đốt credit ngoài ý muốn.
- Group mới thêm nhận số trang theo `max_pages_default` trong trang Cài đặt (mặc định 2). Bảng Groups cho phép chọn nhiều group rồi đổi số trang hoặc N hàng loạt.

### 6.4 Quét một group (`GroupScanner`)

```
scan(group):
    lần_đầu = group chưa có bài nào trong bảng posts
    max_pages = lần_đầu ? 1 : group.max_pages
    cursor = null; trang = 0
    lặp:
        trang++
        res = ScrapeCreators.groupPosts(group, cursor)      # đã gồm xoay key + retry
        nếu lỗi → ghi last_error, consecutive_errors++, dừng
        số_bài_cũ = 0
        với mỗi post trong res.posts:
            nếu (group_id, post.id) đã có trong posts → số_bài_cũ++, bỏ qua
            nếu post.author.id nằm trong sellers → lưu status='skipped_seller'
            ngược lại                             → lưu status='queued', tạo 1 dòng outbox cho mỗi tg_user authorized
        nếu số_bài_cũ >= 2 hoặc số_bài_cũ == số bài trong trang → dừng      # đã đuổi kịp bài cũ
        nếu không có res.cursor → dừng
        nếu trang == max_pages:
            nếu số_bài_cũ == 0 → cap_hits++, last_cap_hit_at = now          # có thể sót bài
            dừng
        cursor = res.cursor
    cập nhật last_scanned_cycle, last_scanned_at, consecutive_errors = 0
```

- **Lần quét đầu** của một group chỉ lấy 1 trang (3–4 bài) và gửi luôn, bất kể cài bao nhiêu trang, để tránh dội hàng chục bài cũ lên Telegram.
- **Điều kiện dừng "đã đuổi kịp":** trong một trang gặp từ 2 bài đã thấy trở lên. Chỉ có 1 bài cũ thì chưa dừng, vì đó có thể là bài ghim. Nhờ vậy group chỉ có 1–2 bài mới vẫn chỉ tốn 1 credit. Không dựa vào `publishTime`, vì bài được admin duyệt muộn có thể mang thời gian đăng cũ (xem rủi ro R2).
- **Group lỗi liên tiếp** (private, không tồn tại, link sai): sau 3 lần lỗi thì tạm hoãn theo cấp số (1 đợt, 2 đợt, 4 đợt, tối đa 24h) và hiện đỏ trên web. Như vậy không đốt credit vô ích.
- **Gửi theo thứ tự cũ đến mới** (đảo ngược mảng trước khi đưa vào outbox) để đọc trên Telegram cho xuôi.

### 6.5 Xoay vòng API key (`KeyPool`)

```
gọi_api(params):
    key = key active có priority nhỏ nhất (in_list=1)
    nếu không còn key active → ném lỗi AllKeysExhausted
    gọi API (timeout 60s)
    ├─ 200 + success=true    → lưu credits_remaining từ body; nếu credits_remaining <= 0 → đánh dấu exhausted
    ├─ 401                   → status='invalid', thử lại ngay bằng key kế tiếp
    ├─ 402 / 403 / body có chữ "credit" → status='exhausted', thử lại ngay bằng key kế tiếp
    ├─ 429                   → chờ 10s rồi thử lại 1 lần, cùng key
    └─ 5xx / timeout / lỗi mạng → thử lại tối đa 2 lần (chờ 5s, 15s), CÙNG key (lỗi phía server, không phải lỗi key)
    luôn ghi 1 dòng api_calls (kèm raw body khi lỗi để xác định đúng định dạng "hết credit")
```

- **Chưa biết chính xác** API trả gì khi hết credit, nên bắt rộng như trên, đồng thời đánh dấu exhausted **chủ động** khi `credits_remaining` về 0. Lần đầu thực sự gặp lỗi hết credit thì xem `api_calls.error` để siết lại điều kiện.
- **Khi tất cả key hết:** scanner tự tạm dừng quét, gửi **một** tin cảnh báo lên Telegram ("Tất cả API key đã hết credit"), web hiện banner đỏ. Có cờ `all_keys_exhausted_notified` để không spam.
- **Hồi sinh key:** mỗi 6 giờ (và khi bấm nút "Kiểm tra credit" trên web) gọi `/v1/account/credit-balance` cho các key `exhausted`, việc này **miễn phí**. Nếu `creditCount > 0` thì key trở lại `active`. Nạp thêm tiền vào key là bot tự dùng lại.
- **Không phân tán** lượt gọi qua nhiều key: dùng cạn key ưu tiên cao rồi mới chuyển key, đúng như yêu cầu.

### 6.6 Telegram bot (`bot.php`)

**Vòng lặp:**
```
loop:
    ghi heartbeat_bot; nạp lại settings (nếu bot_token đổi → khởi tạo lại client, gọi deleteWebhook)
    updates = getUpdates(offset, timeout = có outbox pending ? 1 : 20)
    xử lý từng update (message / callback_query)
    gửi tối đa 20 tin pending trong outbox (not_before <= now), mỗi tin cách ~1,1s nếu cùng chat
```

**Luồng mật khẩu (`/start`):** tất cả người dùng dùng **một mật khẩu chung**, mặc định `cloudcheap`, đổi được trên trang Cài đặt.
1. Người dùng gửi `/start`. Nếu đã `authorized` thì bot báo "Bạn đã đăng nhập" kèm trạng thái. Nếu chưa, bot trả "🔒 Nhập mật khẩu để sử dụng bot:" và đặt `state = awaiting_password`.
2. Người dùng gửi mật khẩu. Bot so bằng `password_verify` với `bot_password_hash`, sau đó **xoá tin nhắn chứa mật khẩu** (`deleteMessage`).
   - Đúng: `authorized`, trả "✅ Đăng nhập thành công. Bot sẽ gửi bài mới tại đây."
   - Sai: `failed_attempts++`. Sai 5 lần thì khoá 15 phút.
3. Người chưa đăng nhập gửi bất cứ gì cũng chỉ nhận lời nhắc nhập mật khẩu, không thấy nội dung nào.
4. Lệnh khác: `/stop` để ngừng nhận tin, `/status` để xem trạng thái quét, đợt kế tiếp, tổng credit còn lại.
5. Đổi mật khẩu trên web **không** tự đá người cũ ra. Trang `users.php` có nút "Thu hồi" từng người và "Thu hồi tất cả".

**Định dạng một tin (parse_mode HTML, mọi nội dung đều escape):**
```
📌 <b>VPS MIN – Proxy, VPS giá rẻ</b>
👤 Nguyễn Văn A · 🕒 14:05 18/10
────────────
Cần thuê 150–200 proxy tĩnh hoặc datacenter Việt, us share giá...
(cắt ở ~3500 ký tự + "…", vì Telegram giới hạn 4096)
```
Nếu `text = null` (bài chỉ có ảnh/video) thì hiện `(Bài không có chữ — mở link để xem)`.

**Bàn phím inline dưới mỗi tin:**
```
[ 🔗 Mở bài viết ]                                           ← nút URL
[ 🚫 Đánh dấu seller ]                                       ← nút callback
```
- `callback_data` tối đa 64 byte, nên dùng dạng ngắn: `s:<author_id>` (đánh dấu), `us:<author_id>` (bỏ đánh dấu).
- **Bấm "Đánh dấu seller"**: thêm vào `sellers`, `answerCallbackQuery("Đã chặn bài của Nguyễn Văn A")`, rồi sửa nút của tin đó thành `[↩️ Bỏ seller]` để có thể hoàn tác nếu bấm nhầm. Đồng thời huỷ các dòng `outbox` còn `pending` của tác giả này.
- Bài không có `author.id` (ẩn danh) thì **không hiện nút seller**.
- Danh sách seller **dùng chung cho toàn bộ bot.** Bất kỳ người dùng nào đánh dấu thì bài của seller đó bị chặn với tất cả mọi người, và ai cũng bỏ đánh dấu được. Người khác bấm nút seller trên tin cũ của người đã bị đánh dấu thì bot chỉ báo "Đã là seller (do X đánh dấu)", không tạo trùng.

**Xử lý lỗi gửi Telegram:**
- `429`: đặt `not_before = now + retry_after`, gửi lại sau.
- `403` (người dùng chặn bot): `blocked_bot = 1`, bỏ các tin pending của chat đó.
- Lỗi khác: retry tối đa 3 lần, sau đó `failed`.

### 6.7 Ước tính credit (hiện trên dashboard và từng dòng trong bảng Groups)

```
số_đợt/ngày            ≈ (độ dài khung giờ) / (rest_minutes + thời gian 1 đợt)
lượt_quét/ngày (group) = số_đợt/ngày / every_n_cycles
credit/ngày (group)    ≈ lượt_quét/ngày × số_trang_thực_dùng_TB của group
                          (lấy từ api_calls 7 ngày gần nhất của chính group đó;
                           chưa có dữ liệu thì tạm tính 1,3; không vượt quá max_pages)
trần credit/ngày       = Σ_group lượt_quét/ngày × max_pages          ← trường hợp lần nào cũng chạm trần
credit/ngày (tổng)     = Σ_group credit/ngày (group)
số_ngày_còn_dùng       = Σ credits_remaining của key active / credit/ngày (tổng)
```
Ví dụ: khung 6h–22h, nghỉ 30 phút, khoảng 29 đợt/ngày.
- 10 group ít bài, N=1, 2 trang: 290 lượt × 1,3 trang ≈ 377 credit.
- 10 group duyệt bài, N=6, 5 trang: khoảng 50 lượt × 3 trang ≈ 150 credit.
- Tổng **≈ 530 credit/ngày**. Trần (lần nào cũng gọi đủ trang): 290×2 + 50×5 = **830 credit/ngày**.

---

## 7. Giao diện web

| Trang | Nội dung |
|---|---|
| **Đăng nhập** | Mật khẩu admin riêng (khác mật khẩu bot). Session + CSRF token cho mọi form |
| **Dashboard** | Đèn trạng thái Scanner/Bot (theo heartbeat: xanh < 2 phút, đỏ > 2 phút), Bật/Tắt quét, **Quét ngay**, đợt hiện tại, đợt kế tiếp lúc mấy giờ, tổng credit còn lại, credit/ngày ước tính, số ngày còn dùng, số bài gửi hôm nay |
| **Cài đặt** | Các ô chính (xem bảng dưới) |
| **Groups** | Bảng: Tên (sửa được) · Link · Quét mỗi N đợt · **Số trang mỗi lần quét** (dropdown 1–10, ghi kèm "≈ X bài · tối đa X credit") · Bật/Tắt · Lần quét cuối · Lần quét tới · Trang thực dùng TB · Credit/ngày ước tính · ⚠️ Có thể sót bài · Số bài đã gửi · Lỗi gần nhất. Có ô chọn nhiều group để đổi số trang hoặc N hàng loạt |
| **API keys** | Key (che giữa: `nX5F…CW2`) · Ưu tiên · Trạng thái · Credit còn · Lần dùng cuối · Nút "Kiểm tra credit" |
| **Sellers** | Tên · FB ID (link tới profile) · Người đánh dấu · Thời gian · Nút "Bỏ seller" · Ô thêm thủ công bằng FB ID |
| **Người dùng Telegram** | Chat ID · Tên · Trạng thái · Ngày đăng nhập · Nút Thu hồi |
| **Nhật ký** | Bảng `scan_runs` (mỗi đợt quét bao nhiêu group, gọi bao nhiêu lần, tốn bao nhiêu credit, bao nhiêu bài mới) + 100 bài gần nhất kèm trạng thái gửi/bỏ qua |

**Form Cài đặt:**

| Ô | Kiểu | Ghi chú |
|---|---|---|
| Danh sách group (link/UID) | `textarea` | Mỗi dòng 1 group. Sau khi lưu, hiện "Đã thêm X, bỏ Y, trùng Z" |
| API key ScrapeCreators | `textarea` | Mỗi dòng 1 key, thứ tự = ưu tiên. Khi lưu, tự gọi credit-balance (miễn phí) để kiểm tra key hợp lệ |
| Khung giờ quét | 2 ô `time` (Từ / Đến) | Mặc định 06:00 → 22:00, cho phép qua nửa đêm |
| Thời gian nghỉ giữa các đợt | `number` (phút) | Tối thiểu 5 |
| Số trang mặc định cho group mới | `select` 1–10 | Mặc định **2** (≈7 bài). Chỉnh riêng từng group ở trang Groups. Có nút "Áp dụng cho tất cả group" |
| Bot token | `password` + nút "Kiểm tra" | Gọi `getMe`, hiện @username của bot nếu đúng |
| Mật khẩu bot | `password` | Một mật khẩu chung cho mọi người dùng, mặc định `cloudcheap`. Lưu dạng hash; để trống thì giữ mật khẩu cũ |

Mọi thay đổi có hiệu lực **ở vòng lặp kế tiếp** của worker, không cần khởi động lại.

---

## 8. Chạy nền trên macOS (launchd)

`~/Library/LaunchAgents/com.scanpostfbapi.scanner.plist` (bot tương tự, đổi tên file và script):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>com.scanpostfbapi.scanner</string>
  <key>ProgramArguments</key><array>
    <string>/Applications/XAMPP/xamppfiles/bin/php</string>
    <string>/Applications/XAMPP/scanpostfbapi/bin/scanner.php</string>
  </array>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>StandardOutPath</key><string>/Applications/XAMPP/scanpostfbapi/storage/logs/scanner.log</string>
  <key>StandardErrorPath</key><string>/Applications/XAMPP/scanpostfbapi/storage/logs/scanner.log</string>
</dict></plist>
```

Nạp bằng `launchctl load ~/Library/LaunchAgents/com.scanpostfbapi.scanner.plist`.

- Dùng **PHP của XAMPP** (`/Applications/XAMPP/xamppfiles/bin/php`), không dùng `/opt/homebrew/bin/php`, để chắc chắn có đủ extension.
- Worker phải **tự kết nối lại DB** khi MySQL của XAMPP chưa bật hoặc vừa khởi động lại (thử lại mỗi 10s, không thoát).
- Dùng file lock (`flock`) để mỗi worker chỉ chạy **một bản**.
- **Mac ngủ thì bot dừng.** Muốn chạy 24/7 thì tắt sleep (`caffeinate -s` hoặc cài đặt Energy), hoặc sau này chuyển lên VPS Linux, thay launchd bằng systemd. Code không cần đổi.

---

## 9. Hướng dẫn cho agent: làm trọn A→Z trong một lượt

### 9.1 Nguyên tắc

- Làm **toàn bộ** mục 3–8 trong một lượt liên tục. Không chia giai đoạn, không dừng sau từng phần để chờ duyệt, không bàn giao khi còn dở. Chỉ hỏi người dùng đúng **một lần ở đầu** (mục 9.2), sau đó làm liền mạch đến khi đạt mục 9.6.
- Tài liệu này là đặc tả. Chi tiết nào không ghi thì tự chọn theo hướng đơn giản và nhất quán với tài liệu, rồi liệt kê lại trong báo cáo cuối.
- PHP 8.2 thuần, không Composer, không framework. Luôn chạy bằng `/Applications/XAMPP/xamppfiles/bin/php`.
- Không ghi API key hay bot token vào code hoặc file trong thư mục dự án. Các bí mật này chỉ nằm trong DB (nhập qua web hoặc lệnh setup). Ngoại lệ duy nhất là mật khẩu bot mặc định `cloudcheap`, do `migrate.php` đặt sẵn.
- **Ngân sách credit khi tự test với API thật: tối đa 30 credit.** Mọi logic còn lại test bằng dữ liệu giả (fixture dựng theo response thật ở mục 2).

### 9.2 Đầu vào: hỏi gom một lần ngay từ đầu

| Cần có | Ghi chú / nếu không có |
|---|---|
| MySQL của XAMPP đang chạy | Lúc lập kế hoạch, MySQL và Apache **đang tắt**. Agent thử kết nối trước. Nếu không được, nhờ người dùng bật bằng XAMPP Manager (`/Applications/XAMPP/manager-osx.app`) hoặc gõ `! sudo /Applications/XAMPP/xamppfiles/xampp startmysql` |
| 1 API key ScrapeCreators để test thật | Không có thì test bằng fixture và ghi rõ trong báo cáo |
| Bot token Telegram | Không có thì vẫn làm hết code, test bot bằng client Telegram giả, ghi rõ phần chưa test thật |
| Mật khẩu admin web | Người dùng không đưa thì tạo ngẫu nhiên, in ra một lần ở báo cáo cuối |

Hai việc agent **không tự làm được** vì cần sudo, sẽ nhờ người dùng ở cuối:
- Khởi động lại Apache sau khi thêm Alias. File `httpd.conf` ghi được không cần sudo (đã kiểm tra), nên agent tự thêm Alias.
- Bật/tắt MySQL.

### 9.3 Thứ tự dựng (theo phụ thuộc, làm nối tiếp nhau)

1. **Khung dự án:** cây thư mục mục 4. `config.php` dùng mặc định XAMPP (`127.0.0.1`, user `root`, mật khẩu rỗng, DB `scanpostfbapi`, `utf8mb4`). `sql/schema.sql` theo mục 5. `bin/migrate.php` phải **chạy lại nhiều lần không lỗi, không mất dữ liệu**: tự tạo DB nếu chưa có, seed settings mặc định ở mục 12, mật khẩu bot `cloudcheap`.
2. **Lõi:** `src/Db` (tự kết nối lại khi mất kết nối), `Settings`, `Auth`, `InputParser`.
3. **`src/ScrapeCreators` + KeyPool** (mục 6.5). Tầng HTTP tách thành interface để thay bằng bản giả khi test.
4. **`src/GroupScanner`** (mục 6.3–6.4) và `bin/scan-once.php` với tham số `--pages=N` và `--dry-run` (không ghi outbox).
5. **`src/Scheduler`** (mục 6.2) và `bin/scanner.php`: `flock`, heartbeat, tự nối lại DB, cảnh báo hết key.
6. **`src/Telegram`, `MessageFormatter`, `bin/bot.php`** (mục 6.6). Tầng HTTP Telegram cũng thay được bằng bản giả.
7. **Web `public/`:** đủ các trang và form ở mục 7, CSRF, ước tính credit (mục 6.7), heartbeat.
8. **`bin/test.php`:** bộ test ở mục 9.4.
9. **Triển khai:** thêm Alias vào `/Applications/XAMPP/xamppfiles/etc/httpd.conf` (sao lưu file trước, không thêm trùng nếu đã có). Tạo 2 plist trong `launchd/`. Sau khi test pass thì copy vào `~/Library/LaunchAgents` và `launchctl load`.
10. **`README.md` ngắn:** cách chạy, đổi cài đặt, xem log, dừng/bật worker, chuyển lên VPS.

### 9.4 Tự kiểm thử: `php bin/test.php` phải pass 100%

Test runner tự viết (không PHPUnit), chạy trên DB riêng `scanpostfbapi_test`, mỗi ca dọn dữ liệu của mình.

| Nhóm | Các ca bắt buộc |
|---|---|
| InputParser | 4 dạng link ở mục 6.1; dòng trống; dòng trùng; link sai báo lỗi rõ; xoá dòng thì `in_list=0` và giữ lịch sử |
| Scheduler | Trong/ngoài khung giờ; khung qua nửa đêm (20:00→02:00); `next_run_at` tính từ lúc đợt **kết thúc**; group N=3 chỉ đến hạn ở đợt 1, 4, 7; `run_now` bỏ qua khung giờ; `scan_enabled=0` thì không chạy |
| GroupScanner (API giả) | Lần đầu chỉ 1 trang; dừng khi trang có ≥2 bài cũ; chỉ 1 bài cũ (bài ghim) thì chưa dừng; chạm trần mà trang cuối toàn bài mới thì `cap_hits+1`; bài seller thành `skipped_seller` và không có outbox; quét lại không trùng; group lỗi 3 lần liên tiếp thì bị tạm hoãn |
| KeyPool (HTTP giả) | 401 thì `invalid` và chuyển key; 402 hoặc body có "credit" thì `exhausted` và chuyển key; `credits_remaining=0` thì `exhausted`; 5xx thì retry trên cùng key; hết key thì ném `AllKeysExhausted` và chỉ tạo **1** cảnh báo |
| MessageFormatter | Escape HTML; cắt khi >3500 ký tự; `text=null`; `callback_data` ≤ 64 byte; không có nút seller khi `author.id` null |
| Bot (Telegram giả) | `/start` thì hỏi mật khẩu; sai 5 lần thì khoá; đúng `cloudcheap` thì `authorized` và tin mật khẩu bị xoá; người chưa đăng nhập không nhận outbox; hai người cùng bấm seller không tạo trùng; bỏ seller được; 429 thì đặt `not_before`; 403 thì `blocked_bot=1` |
| Db | Kill kết nối hiện tại (`KILL CONNECTION_ID()` từ kết nối thứ hai) rồi truy vấn tiếp, Db phải tự nối lại |

### 9.5 Kiểm thử với dịch vụ thật (nếu có key/token, trong ngân sách 30 credit)

1. `scan-once.php vpsmincom --pages=2 --dry-run` in ra bài. Chạy lại thì báo 0 bài mới, chỉ 1 lần gọi API.
2. Có bot token: nhắn `/start`, nhập `cloudcheap`, chạy `scan-once` không `--dry-run`, chạy `bot.php` và thấy tin về Telegram. Bấm thử nút đánh dấu và bỏ seller. Phần này cần người dùng thao tác trên Telegram; nếu không có người thao tác thì kiểm bằng `getMe` và `sendMessage` tới chat đã đăng nhập.
3. Chạy `scanner.php` khoảng 5 phút: khung giờ bao giờ hiện tại, nghỉ 1 phút, 2 group (N=1 và N=2). Kiểm tra `scan_runs` và `api_calls` khớp lịch.
4. Gọi các trang web bằng `php -S 127.0.0.1:8099 -t public` (không cần đợi restart Apache): đăng nhập, lưu cài đặt, thấy worker áp dụng ở vòng lặp kế tiếp.

### 9.6 Tiêu chí hoàn thành

- [ ] `migrate.php` chạy 2 lần liên tiếp không lỗi.
- [ ] `php bin/test.php` pass toàn bộ.
- [ ] Mọi ô và bảng ở mục 7 có trên web, lưu được, có hiệu lực ở vòng lặp kế tiếp của worker mà không cần khởi động lại.
- [ ] Đã chạy các bước ở mục 9.5 có thể chạy, trong ngân sách credit.
- [ ] Alias Apache đã thêm (có bản sao lưu), 2 plist launchd đã nạp, `launchctl list | grep scanpostfbapi` thấy cả hai, heartbeat trên dashboard xanh.
- [ ] `README.md` có đủ nội dung ở bước 10.

### 9.7 Báo cáo cuối của agent

- Danh sách file đã tạo.
- Kết quả `bin/test.php` (số ca pass/fail).
- Số credit đã dùng khi test thật.
- Phần **chưa test được với dịch vụ thật** và lý do.
- Các lựa chọn agent tự đưa ra ngoài tài liệu.
- Việc người dùng cần làm: restart Apache, mở `http://localhost/scanpostfbapi/`, nhập group, API key, bot token; mật khẩu admin nếu được tạo ngẫu nhiên.
- Việc người dùng tự theo dõi sau bàn giao: chạy 24h với group thật, so credit thực tế với ước tính, kiểm rủi ro R2.

---

## 10. Rủi ro và điểm cần kiểm chứng

| # | Rủi ro | Cách xử lý |
|---|---|---|
| R1 | Chưa biết định dạng lỗi khi **hết credit** | Bắt rộng (402/403/"credit"), đánh dấu exhausted chủ động khi `credits_remaining = 0`, lưu raw body vào `api_calls` để siết lại sau |
| R2 | Group **duyệt bài**: bài được duyệt muộn có thể hiện với `publishTime` cũ, nằm sâu trong feed CHRONOLOGICAL, bị bỏ sót nếu dừng sớm | Agent không tự test được vì cần đăng bài chờ duyệt. Sau bàn giao, người dùng đăng thử 1 bài vào một group có duyệt bài, chờ duyệt, rồi xem bài nằm ở trang nào. Nếu bị đẩy sâu thì tăng `max_pages` cho riêng group đó và đổi điều kiện dừng thành "2 trang liên tiếp toàn bài cũ" |
| R3 | Trang đầu trả 4 bài, có thể gồm bài **ghim** cũ | Không ảnh hưởng vì chống trùng theo `post.id`; bài ghim chỉ gửi lần đầu |
| R4 | Facebook đổi cấu trúc, API trả rỗng hoặc lỗi hàng loạt | Group lỗi liên tiếp thì tạm hoãn + cảnh báo đỏ; nếu > 50% group lỗi trong 1 đợt thì gửi 1 cảnh báo Telegram |
| R5 | Giới hạn Telegram (~1 tin/giây mỗi chat, 30 tin/giây tổng) | Outbox + giãn cách + tôn trọng `retry_after` |
| R6 | Lộ bot token / API key vì web chứa chúng | `Require local`, đăng nhập admin, CSRF, che key khi hiển thị, `config.php` nằm ngoài `public/` |
| R7 | Seller đổi nick, hoặc đăng ẩn danh | Không chặn được bằng `author.id`; xem tuỳ chọn T1 bên dưới |
| R8 | Mac ngủ hoặc tắt máy | Heartbeat đỏ trên web; khuyến nghị tắt sleep hoặc lên VPS |

---

## 11. Tuỳ chọn nên cân nhắc (ngoài yêu cầu, agent KHÔNG làm trong lần này)

- **T1. Chống bài trùng nội dung:** seller thường dán cùng một nội dung vào nhiều group. Bỏ qua bài có cùng `author_id` + cùng hash nội dung đã gửi trong 24 giờ.
- **T2. Lọc theo từ khoá:** chỉ gửi bài chứa (hoặc không chứa) từ khoá, ví dụ "cần mua", "cần thuê".
- **T3. Gửi vào một Telegram group chung** thay vì từng người, tiện cho cả nhóm cùng theo dõi. Khi đó mật khẩu nhập trong chat riêng với bot, rồi dùng lệnh `/bind` trong group.

---

## 12. Mặc định đã chọn (đổi được nếu cần)

| Quyết định | Mặc định |
|---|---|
| Ai nhận tin | Mọi chat Telegram đã nhập đúng mật khẩu, mỗi người nhận một bản |
| Mật khẩu bot | **Đã chốt:** một mật khẩu chung cho mọi người dùng, mặc định `cloudcheap` |
| Danh sách seller | **Đã chốt:** dùng chung cho toàn bộ bot |
| Số trang mỗi lần quét | 2 trang/group (≈7 bài, tối đa 2 credit), chỉnh riêng từng group |
| Lần quét đầu một group | Lấy 1 trang (3–4 bài) và gửi |
| Thời gian nghỉ | Tính từ lúc đợt trước **kết thúc** |
| Sort | `CHRONOLOGICAL` |
| Web | Chỉ truy cập từ máy local, có đăng nhập admin |
