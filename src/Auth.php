<?php
declare(strict_types=1);

/** Đăng nhập admin web (mật khẩu riêng, lưu hash trong settings) + CSRF. */
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('scanpost_admin');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['admin']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function attempt(string $password): bool
    {
        $hash = (string) Settings::fresh('admin_password_hash');
        if ($hash === '' || !password_verify($password, $hash)) {
            $_SESSION['login_failures'] = (int) ($_SESSION['login_failures'] ?? 0) + 1;
            sleep(min(5, (int) $_SESSION['login_failures'])); // làm chậm dò mật khẩu
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['login_failures'] = 0;
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::csrfToken() . '">';
    }

    public static function verifyCsrf(): void
    {
        $sent = (string) ($_POST['csrf'] ?? '');
        if ($sent === '' || !hash_equals(self::csrfToken(), $sent)) {
            http_response_code(400);
            exit('Phiên làm việc đã hết hạn hoặc CSRF token sai. Tải lại trang rồi thử lại.');
        }
    }
}
