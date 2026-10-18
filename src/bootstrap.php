<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$appConfig = require APP_ROOT . '/config.php';
date_default_timezone_set($appConfig['timezone']);
mb_internal_encoding('UTF-8');

require __DIR__ . '/Errors.php';
require __DIR__ . '/Http.php';

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Db::configure($appConfig['db']);
