<?php
declare(strict_types=1);

namespace App\Core;

/** Secure session bootstrap, CSRF tokens and flash messages. */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) Config::get('app.session.lifetime', 7200));
        session_name((string) Config::get('app.session.name', 'plastilink_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => Request::basePath() . '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Old input / validation errors set on the previous request are readable for this one only.
        $_SESSION['_old_now'] = $_SESSION['_old'] ?? [];
        $_SESSION['_errors_now'] = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_old'], $_SESSION['_errors']);
    }

    /** @param array<string,string> $errors */
    public static function flashErrors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    public static function error(string $field): string
    {
        return (string) ($_SESSION['_errors_now'][$field] ?? '');
    }

    public static function hasErrors(): bool
    {
        return !empty($_SESSION['_errors_now']);
    }

    /** One-time secret (e.g. a temporary password): readable once, then gone. */
    public static function putOnce(string $key, string $value): void
    {
        $_SESSION['_once'][$key] = $value;
    }

    public static function pullOnce(string $key): ?string
    {
        $v = $_SESSION['_once'][$key] ?? null;
        unset($_SESSION['_once'][$key]);
        return $v;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token) && $token !== ''
            && isset($_SESSION['_csrf'])
            && hash_equals($_SESSION['_csrf'], $token);
    }

    /** Fresh session id (call after login/privilege change) while keeping data. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Wipe all session data and issue a new, empty session. */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int, array{type:string, message:string}> */
    public static function pullFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    public static function flashInput(array $input): void
    {
        $_SESSION['_old'] = $input;
    }

    public static function old(string $key, string $default = ''): string
    {
        return (string) ($_SESSION['_old_now'][$key] ?? $default);
    }

    public static function clearOld(): void
    {
        unset($_SESSION['_old']);
    }
}
