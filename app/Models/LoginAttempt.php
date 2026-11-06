<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class LoginAttempt
{
    public static function record(string $identifier, string $ip, string $ua, bool $ok, ?string $reason, ?int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO login_attempts (identifier, ip_address, user_agent, successful, reason, user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([mb_substr($identifier, 0, 190), $ip, $ua, $ok ? 1 : 0, $reason, $userId]);
    }

    /**
     * Seconds until the account may try again, or 0 if not locked.
     * Counts failures in the window that came after the last successful login.
     */
    public static function accountLockSeconds(string $identifier, int $max, int $minutes): int
    {
        $s = Database::connection()->prepare(
            "SELECT COUNT(*) AS n,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), MIN(created_at) + INTERVAL $minutes MINUTE) AS left_s
             FROM login_attempts
             WHERE identifier = :id AND successful = 0 AND reason <> 'locked'
               AND created_at > GREATEST(
                     UTC_TIMESTAMP() - INTERVAL $minutes MINUTE,
                     COALESCE((SELECT MAX(created_at) FROM login_attempts WHERE identifier = :id2 AND successful = 1), '1970-01-01'))"
        );
        $s->execute(['id' => $identifier, 'id2' => $identifier]);
        $r = $s->fetch();
        return ((int) $r['n'] >= $max) ? max(1, (int) $r['left_s']) : 0;
    }

    public static function ipLockSeconds(string $ip, int $max, int $minutes): int
    {
        $s = Database::connection()->prepare(
            "SELECT COUNT(*) AS n,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), MIN(created_at) + INTERVAL $minutes MINUTE) AS left_s
             FROM login_attempts
             WHERE ip_address = ? AND successful = 0 AND reason <> 'locked'
               AND created_at > UTC_TIMESTAMP() - INTERVAL $minutes MINUTE"
        );
        $s->execute([$ip]);
        $r = $s->fetch();
        return ((int) $r['n'] >= $max) ? max(1, (int) $r['left_s']) : 0;
    }

    public static function recentSuccessful(int $limit = 5): array
    {
        $s = Database::connection()->prepare(
            'SELECT la.created_at, la.ip_address, u.full_name FROM login_attempts la
             LEFT JOIN users u ON u.id = la.user_id
             WHERE la.successful = 1 ORDER BY la.id DESC LIMIT ' . max(1, min(50, $limit))
        );
        $s->execute();
        return $s->fetchAll();
    }

    public static function failedSince(string $interval = '24 HOUR'): int
    {
        return (int) Database::connection()->query(
            "SELECT COUNT(*) FROM login_attempts WHERE successful = 0 AND reason <> 'locked'
             AND created_at > UTC_TIMESTAMP() - INTERVAL $interval"
        )->fetchColumn();
    }
}
