<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Helpers\Reference;

final class PaymentRequest
{
    private const SELECT = 'SELECT q.*, p.picker_code, pu.full_name AS picker_name, pu.id AS picker_user_id, p.preferred_hub_id,
                                   rb.full_name AS requested_by_name, db.full_name AS decided_by_name,
                                   pay.id AS payment_id, pay.status AS payment_status
                            FROM payment_requests q JOIN pickers p ON p.id = q.picker_id JOIN users pu ON pu.id = p.user_id
                            JOIN users rb ON rb.id = q.requested_by LEFT JOIN users db ON db.id = q.decided_by
                            LEFT JOIN payments pay ON pay.request_id = q.id';

    private static function where(array $f, ?array $hubScope, ?int $pickerId): array
    {
        $w = [];
        $a = [];
        if ($pickerId !== null) {
            $w[] = 'q.picker_id = ?';
            $a[] = $pickerId;
        }
        if ($hubScope !== null) {
            $w[] = $hubScope ? 'p.preferred_hub_id IN (' . implode(',', array_map('intval', $hubScope)) . ')' : '1 = 0';
        }
        if (!empty($f['status'])) {
            $w[] = 'q.status = ?';
            $a[] = $f['status'];
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(q.reference LIKE ? OR pu.full_name LIKE ? OR p.picker_code LIKE ?)';
            array_push($a, $like, $like, $like);
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function paginate(array $f, ?array $hubScope, ?int $pickerId, int $offset, int $limit): array
    {
        [$where, $a] = self::where($f, $hubScope, $pickerId);
        $s = Database::connection()->prepare(self::SELECT . " $where ORDER BY q.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function count(array $f, ?array $hubScope, ?int $pickerId): int
    {
        [$where, $a] = self::where($f, $hubScope, $pickerId);
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM payment_requests q JOIN pickers p ON p.id = q.picker_id JOIN users pu ON pu.id = p.user_id $where");
        $s->execute($a);
        return (int) $s->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE q.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function lock(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM payment_requests WHERE id = ? FOR UPDATE');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function idByToken(string $token): ?int
    {
        $s = Database::connection()->prepare('SELECT id FROM payment_requests WHERE submission_token = ?');
        $s->execute([$token]);
        return ($v = $s->fetchColumn()) ? (int) $v : null;
    }

    public static function insert(array $d): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO payment_requests (submission_token, picker_id, amount_ugx, method, destination_phone, requested_by, requested_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$d['token'], $d['picker'], $d['amount'], $d['method'], $d['phone'], $d['by']]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE payment_requests SET reference = ? WHERE id = ?')->execute([Reference::make('PRQ', $id), $id]);
        return $id;
    }

    public static function decide(int $id, string $status, int $by, ?string $note): void
    {
        Database::connection()->prepare(
            'UPDATE payment_requests SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = ? WHERE id = ?'
        )->execute([$status, $by, $note, $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::connection()->prepare('UPDATE payment_requests SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    /** Money already promised to a picker: requests waiting for approval or approved but not yet paid. */
    public static function holds(int $pickerId): int
    {
        $s = Database::connection()->prepare("SELECT COALESCE(SUM(amount_ugx), 0) FROM payment_requests WHERE picker_id = ? AND status IN ('pending_approval','approved')");
        $s->execute([$pickerId]);
        return (int) $s->fetchColumn();
    }

    public static function pendingApprovalCount(?array $hubScope = null): int
    {
        $w = $hubScope === null ? '' : ($hubScope ? 'AND p.preferred_hub_id IN (' . implode(',', array_map('intval', $hubScope)) . ')' : 'AND 1 = 0');
        return (int) Database::connection()->query(
            "SELECT COUNT(*) FROM payment_requests q JOIN pickers p ON p.id = q.picker_id WHERE q.status = 'pending_approval' $w"
        )->fetchColumn();
    }
}
