<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Identifier;
use App\Models\AuditLog;
use App\Models\LoginAttempt;
use App\Models\Role;
use App\Models\User;

final class AuthService
{
    public const GENERIC_ERROR = 'Invalid phone number, email or password.';

    private static ?array $user = null;
    private static bool $resolved = false;
    private static ?array $permissions = null;

    /**
     * @return array{ok:bool, error:?string}
     */
    public static function attempt(string $identifier, string $password): array
    {
        $ip = Request::ip();
        $ua = Request::userAgent();
        $parsed = Identifier::parse($identifier);
        $key = $parsed['value'] ?? mb_strtolower(mb_substr(trim($identifier), 0, 190));

        $maxAcct = (int) Config::get('app.auth.max_account_failures', 5);
        $maxIp = (int) Config::get('app.auth.max_ip_failures', 20);
        $minutes = (int) Config::get('app.auth.lockout_minutes', 15);

        // 1. Throttle BEFORE checking the password (a correct password must not bypass a lock).
        $wait = max(
            LoginAttempt::accountLockSeconds($key, $maxAcct, $minutes),
            LoginAttempt::ipLockSeconds($ip, $maxIp, $minutes)
        );
        if ($wait > 0) {
            LoginAttempt::record($key, $ip, $ua, false, 'locked', null);
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again in ' . max(1, (int) ceil($wait / 60)) . ' minute(s).'];
        }

        // 2. Verify. A dummy hash is checked for unknown accounts so timing does not reveal them.
        $user = $parsed ? User::findByIdentifier($parsed) : null;
        $valid = password_verify($password, $user['password_hash'] ?? self::dummyHash()) && $user !== null;

        if (!$valid) {
            LoginAttempt::record($key, $ip, $ua, false, 'bad_credentials', $user['id'] ?? null);
            AuditLog::write('auth.login_failed', $user['id'] ?? null, 'user', $user['id'] ?? null, ['identifier' => $key]);
            if (LoginAttempt::accountLockSeconds($key, $maxAcct, $minutes) > 0) {
                AuditLog::write('auth.lockout', $user['id'] ?? null, 'user', $user['id'] ?? null, ['identifier' => $key]);
            }
            return ['ok' => false, 'error' => self::GENERIC_ERROR];
        }

        // 3. Correct password, but the account may not be allowed in.
        if ($user['status'] !== 'active') {
            LoginAttempt::record($key, $ip, $ua, false, 'status_' . $user['status'], (int) $user['id']);
            AuditLog::write('auth.login_blocked', (int) $user['id'], 'user', (int) $user['id'], ['status' => $user['status']]);
            return ['ok' => false, 'error' => $user['status'] === 'pending'
                ? 'Your account is waiting for approval. You will be able to sign in once it is approved.'
                : 'This account has been suspended. Please contact the PlastiLink administrator.'];
        }

        // 4. Success: new session id (prevents session fixation).
        Session::regenerate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['last_activity'] = time();
        $_SESSION['login_at'] = time();
        self::reset();

        User::touchLogin((int) $user['id'], $ip);
        User::rehashIfNeeded((int) $user['id'], $password, $user['password_hash']);
        LoginAttempt::record($key, $ip, $ua, true, null, (int) $user['id']);
        AuditLog::write('auth.login', (int) $user['id'], 'user', (int) $user['id']);

        return ['ok' => true, 'error' => null];
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            AuditLog::write('auth.logout', (int) $user['id'], 'user', (int) $user['id']);
        }
        Session::destroy();
        self::reset();
    }

    /** Current signed-in user (fresh from the DB each request), or null. */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        self::$user = null;

        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) {
            return null;
        }

        $idle = (int) Config::get('app.session.lifetime', 7200);
        if (time() - (int) ($_SESSION['last_activity'] ?? 0) > $idle) {
            Session::destroy();
            Session::flash('warning', 'You were signed out because of inactivity. Please sign in again.');
            return null;
        }

        $user = User::findById((int) $_SESSION['user_id']);
        // Suspension or deactivation takes effect on the very next request.
        if (!$user || $user['status'] !== 'active') {
            Session::destroy();
            return null;
        }

        $_SESSION['last_activity'] = time();
        unset($user['password_hash']);
        return self::$user = $user;
    }

    /** @return string[] */
    public static function permissions(): array
    {
        if (self::$permissions === null) {
            $u = self::user();
            self::$permissions = $u ? Role::permissionSlugs((int) $u['role_id']) : [];
        }
        return self::$permissions;
    }

    public static function can(string $permission): bool
    {
        return in_array($permission, self::permissions(), true);
    }

    public static function hasRole(string ...$slugs): bool
    {
        $u = self::user();
        return $u !== null && in_array($u['role_slug'], $slugs, true);
    }

    /** Where each role lands after sign-in. */
    public static function homeFor(string $roleSlug): string
    {
        return match ($roleSlug) {
            'super_admin', 'project_manager' => '/admin',
            'hub_manager', 'hub_operator'    => '/hub',
            'waste_picker'                   => '/picker',
            'recycler'                       => '/recycler',
            default                          => '/',
        };
    }

    /** Clears per-request caches (used after login/logout and in tests). */
    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
        self::$permissions = null;
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT, ['cost' => 12]);
    }
}
