<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Request;

final class AuditLog
{
    /** Append-only audit record. Never put passwords, tokens or full credentials in $details. */
    public static function write(
        string $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = []
    ): void {
        Database::connection()->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $action, $entityType, $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            Request::ip(), Request::userAgent(),
        ]);
    }

    public static function recent(int $limit = 50): array
    {
        $s = Database::connection()->prepare(
            'SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $s->execute();
        return $s->fetchAll();
    }
}
