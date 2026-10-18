<?php
// Nạp chung cho mọi trang web: bootstrap, session, kiểm tra DB, hàm giao diện.
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

Auth::start();

try {
    Db::run('SELECT 1 FROM settings LIMIT 1');
} catch (Throwable $e) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Lỗi DB</title><body style="font-family:system-ui;padding:2rem">';
    echo '<h2>Không kết nối được cơ sở dữ liệu</h2><p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>Bật MySQL trong XAMPP Manager, rồi chạy <code>/Applications/XAMPP/xamppfiles/bin/php bin/migrate.php</code> nếu chưa tạo DB.</p>';
    exit;
}

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function fmt_time(?string $dt, string $format = 'H:i d/m'): string
{
    $t = Clock::parse($dt);
    return $t ? $t->format($format) : '—';
}

function fmt_num(float|int|null $n, int $decimals = 0): string
{
    return $n === null ? '—' : number_format((float) $n, $decimals, ',', '.');
}

/** "3 phút trước" */
function ago(?string $dt): string
{
    $t = Clock::parse($dt);
    if (!$t) {
        return 'chưa có';
    }
    $s = max(0, time() - $t->getTimestamp());
    return match (true) {
        $s < 60 => $s . ' giây trước',
        $s < 3600 => intdiv($s, 60) . ' phút trước',
        $s < 86400 => intdiv($s, 3600) . ' giờ trước',
        default => intdiv($s, 86400) . ' ngày trước',
    };
}

function heartbeat_ok(string $key): bool
{
    $t = Clock::parse(Settings::get($key));
    return $t !== null && time() - $t->getTimestamp() < 120;
}

function pages_label(int $p): string
{
    return $p . ' trang · ≈' . Groups::postsForPages($p) . ' bài · ≤' . $p . ' credit';
}

function page_header(string $title, string $active): void
{
    $nav = [
        'index' => ['index.php', 'Dashboard'],
        'settings' => ['settings.php', 'Cài đặt'],
        'groups' => ['groups.php', 'Groups'],
        'keys' => ['keys.php', 'API keys'],
        'sellers' => ['sellers.php', 'Sellers'],
        'users' => ['users.php', 'Người dùng TG'],
        'logs' => ['logs.php', 'Nhật ký'],
    ];
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    ?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · ScanPost Bot</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php">📡 ScanPost Bot</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <?php foreach ($nav as $key => [$href, $label]): ?>
          <li class="nav-item"><a class="nav-link<?= $key === $active ? ' active' : '' ?>" href="<?= $href ?>"><?= h($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php if (Auth::check()): ?>
      <form method="post" action="logout.php" class="d-flex"><?= Auth::csrfField() ?><button class="btn btn-sm btn-outline-light">Đăng xuất</button></form>
      <?php endif; ?>
    </div>
  </div>
</nav>
<main class="container-fluid px-4 pb-5">
<?php foreach ($flashes as [$type, $msg]): ?>
  <div class="alert alert-<?= h($type) ?> alert-dismissible fade show" role="alert"><?= $msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>
    <?php
}

function page_footer(): void
{
    ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
    <?php
}
