<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Native PHP session with safe cookie flags, plus the signed-in user,
 * one-time flash messages and the session-backed CSRF token.
 * Session IDs and tokens are never logged.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('nested_forum');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,                 // not readable from JavaScript
            'samesite' => 'Lax',                // not sent on cross-site POSTs
            'secure' => self::isHttps(),        // Secure flag whenever served over HTTPS
        ]);
        session_start();
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    // ---- authentication ---------------------------------------------------

    public static function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        return is_int($id) && $id > 0 ? $id : null;
    }

    public static function username(): string
    {
        return is_string($_SESSION['username'] ?? null) ? $_SESSION['username'] : '';
    }

    public static function logIn(int $userId, string $username): void
    {
        session_regenerate_id(true);   // prevent session fixation
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        self::rotateCsrf();
    }

    public static function logOut(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::rotateCsrf();
    }

    // ---- CSRF -------------------------------------------------------------

    public static function csrfToken(): string
    {
        if (!is_string($_SESSION['csrf'] ?? null)) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(mixed $submitted): bool
    {
        $expected = $_SESSION['csrf'] ?? null;
        return is_string($expected) && is_string($submitted) && hash_equals($expected, $submitted);
    }

    public static function rotateCsrf(): void
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    // ---- flash messages ---------------------------------------------------

    public static function flash(string $key, string $message): void
    {
        $_SESSION['flash'][$key] = $message;
    }

    public static function takeFlash(string $key): string
    {
        $message = $_SESSION['flash'][$key] ?? '';
        unset($_SESSION['flash'][$key]);
        return is_string($message) ? $message : '';
    }
}
