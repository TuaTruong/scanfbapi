<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (Auth::check()) {
    redirect('index.php');
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    if (Auth::attempt((string) ($_POST['password'] ?? ''))) {
        redirect('index.php');
    }
    $error = 'Sai mật khẩu.';
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Đăng nhập · ScanPost Bot</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh">
<form method="post" class="card shadow-sm p-4" style="width:100%;max-width:360px">
  <h1 class="h4 mb-3">📡 ScanPost Bot</h1>
  <?= Auth::csrfField() ?>
  <label class="form-label" for="password">Mật khẩu admin</label>
  <input class="form-control mb-3" type="password" id="password" name="password" autofocus required autocomplete="current-password">
  <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
  <button class="btn btn-primary w-100">Đăng nhập</button>
</form>
</body>
</html>
