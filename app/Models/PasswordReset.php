<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class PasswordReset
{
    public static function create(int $userId, string $tokenHash, int $minutes, string $ip): void
    {
        Database::connection()->prepare(
            "INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address)
             VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL $minutes MINUTE, ?)"
        )->execute([$userId, $tokenHash, $ip]);
    }

    public static function findValid(string $tokenHash): ?array
    {
        $s = Database::connection()->prepare(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1'
        );
        $s->execute([$tokenHash]);
        return $s->fetch() ?: null;
    }

    public static function markUsed(int $id): void
    {
        Database::connection()->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }

    public static function invalidateOpen(int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL'
        )->execute([$userId]);
    }

    public static function countLastHour(int $userId): int
    {
        $s = Database::connection()->prepare(
            'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR'
        );
        $s->execute([$userId]);
        return (int) $s->fetchColumn();
    }
}
