<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    Auth::logout();
}
redirect('login.php');
