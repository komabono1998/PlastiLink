<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Helpers\Reference;
use PDO;

final class Payment
{
    private const SELECT = 'SELECT pay.*, p.picker_code, pu.full_name AS picker_name, pu.id AS picker_user_id, p.preferred_hub_id,
                                   r.business_name, i.invoice_number, i.hub_id AS invoice_hub_id,
                                   cb.full_name AS created_by_name, pb.full_name AS processed_by_name, fb.full_name AS confirmed_by_name,
                                   vb.full_name AS reversed_by_name, rb.full_name AS reconciled_by_name
                            FROM payments pay
                            LEFT JOIN pickers p ON p.id = pay.picker_id LEFT JOIN users pu ON pu.id = p.user_id
                            LEFT JOIN recyclers r ON r.id = pay.recycler_id LEFT JOIN invoices i ON i.id = pay.invoice_id
                            LEFT JOIN users cb ON cb.id = pay.created_by LEFT JOIN users pb ON pb.id = pay.processed_by
                            LEFT JOIN users fb ON fb.id = pay.confirmed_by LEFT JOIN users vb ON vb.id = pay.reversed_by
                            LEFT JOIN users rb ON rb.id = pay.reconciled_by';

    private static function where(array $f, ?array $hubScope, ?int $pickerId, ?int $recyclerId): array
    {
        $w = [];
        $a = [];
        if ($pickerId !== null) {
            $w[] = 'pay.picker_id = ?';
            $a[] = $pickerId;
        }
        if ($recyclerId !== null) {
            $w[] = 'pay.recycler_id = ?';
            $a[] = $recyclerId;
        }
        if ($hubScope !== null) {
            $in = $hubScope ? implode(',', array_map('intval', $hubScope)) : '0';
            $w[] = "((pay.direction = 'out' AND p.preferred_hub_id IN ($in)) OR (pay.direction = 'in' AND i.hub_id IN ($in)))";
        }
        foreach (['status', 'direction'] as $k) {
            if (!empty($f[$k])) {
                $w[] = "pay.$k = ?";
                $a[] = $f[$k];
            }
        }
        if (!empty($f['from'])) {
            $w[] = 'pay.created_at >= ?';
            $a[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $w[] = 'pay.created_at < ?';
            $a[] = $f['to'];
        }
        if (isset($f['reconciled']) && $f['reconciled'] !== '') {
            $w[] = $f['reconciled'] === '1' ? 'pay.reconciled_at IS NOT NULL' : 'pay.reconciled_at IS NULL';
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(pay.reference LIKE ? OR pay.provider_reference LIKE ? OR pu.full_name LIKE ? OR r.business_name LIKE ?)';
            array_push($a, $like, $like, $like, $like);
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function paginate(array $f, ?array $hubScope, ?int $pickerId, ?int $recyclerId, int $offset, int $limit): array
    {
        [$where, $a] = self::where($f, $hubScope, $pickerId, $recyclerId);
        $s = Database::connection()->prepare(self::SELECT . " $where ORDER BY pay.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function count(array $f, ?array $hubScope, ?int $pickerId, ?int $recyclerId): int
    {
        [$where, $a] = self::where($f, $hubScope, $pickerId, $recyclerId);
        $s = Database::connection()->prepare(
            "SELECT COUNT(*) FROM payments pay LEFT JOIN pickers p ON p.id = pay.picker_id LEFT JOIN users pu ON pu.id = p.user_id
             LEFT JOIN recyclers r ON r.id = pay.recycler_id LEFT JOIN invoices i ON i.id = pay.invoice_id $where"
        );
        $s->execute($a);
        return (int) $s->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE pay.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function lock(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function idByKey(string $key): ?int
    {
        $s = Database::connection()->prepare('SELECT id FROM payments WHERE idempotency_key = ?');
        $s->execute([$key]);
        return ($v = $s->fetchColumn()) ? (int) $v : null;
    }

    public static function insert(array $d): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO payments (idempotency_key, direction, picker_id, recycler_id, request_id, invoice_id, amount_ugx, method, provider, provider_reference,
                                   destination_phone, status, received_on, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$d['key'], $d['direction'], $d['picker'] ?? null, $d['recycler'] ?? null, $d['request'] ?? null, $d['invoice'] ?? null, $d['amount'], $d['method'],
                    $d['provider'], $d['reference'] ?? null, $d['phone'] ?? null, $d['status'] ?? 'pending', $d['received_on'] ?? null, $d['by']]);
        $id = (int) $pdo->lastInsertId();
        $prefix = $d['direction'] === 'out' ? 'PAY' : 'RCP';
        $pdo->prepare('UPDATE payments SET reference = ? WHERE id = ?')->execute([Reference::make($prefix, $id), $id]);
        return $id;
    }

    public static function update(int $id, array $set): void
    {
        $cols = [];
        $args = [];
        foreach ($set as $k => $v) {
            if (!preg_match('/^[a-z_]+$/', $k)) {
                throw new \InvalidArgumentException('bad column');
            }
            $cols[] = "$k = ?";
            $args[] = $v;
        }
        $args[] = $id;
        Database::connection()->prepare('UPDATE payments SET ' . implode(', ', $cols) . ' WHERE id = ?')->execute($args);
    }

    public static function addAttempt(int $paymentId, string $provider, string $status, ?string $reference, ?string $message, ?int $userId): int
    {
        $pdo = Database::connection();
        $s = $pdo->prepare('SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM payment_attempts WHERE payment_id = ?');
        $s->execute([$paymentId]);
        $no = (int) $s->fetchColumn();
        $pdo->prepare(
            'INSERT INTO payment_attempts (payment_id, attempt_no, provider, status, provider_reference, message, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$paymentId, $no, $provider, $status, $reference, $message !== null ? mb_substr($message, 0, 255) : null, $userId]);
        return $no;
    }

    public static function attempts(int $paymentId): array
    {
        $s = Database::connection()->prepare(
            'SELECT a.*, u.full_name AS by_name FROM payment_attempts a LEFT JOIN users u ON u.id = a.created_by WHERE a.payment_id = ? ORDER BY a.attempt_no'
        );
        $s->execute([$paymentId]);
        return $s->fetchAll();
    }

    public static function lastAttempt(int $paymentId): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM payment_attempts WHERE payment_id = ? ORDER BY attempt_no DESC LIMIT 1');
        $s->execute([$paymentId]);
        return $s->fetch() ?: null;
    }

    /** Sum of successful money received against an invoice. */
    public static function receivedForInvoice(int $invoiceId): int
    {
        $s = Database::connection()->prepare("SELECT COALESCE(SUM(amount_ugx), 0) FROM payments WHERE invoice_id = ? AND direction = 'in' AND status = 'successful'");
        $s->execute([$invoiceId]);
        return (int) $s->fetchColumn();
    }

    /** Receipts recorded but not yet confirmed. */
    public static function pendingForInvoice(int $invoiceId): int
    {
        $s = Database::connection()->prepare("SELECT COALESCE(SUM(amount_ugx), 0) FROM payments WHERE invoice_id = ? AND direction = 'in' AND status = 'pending'");
        $s->execute([$invoiceId]);
        return (int) $s->fetchColumn();
    }

    /** @return int[] payment ids still "processing" (for the polling job) */
    public static function processingIds(): array
    {
        return array_map('intval', Database::connection()->query("SELECT id FROM payments WHERE status = 'processing' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,int> status => count */
    public static function countByStatus(): array
    {
        return Database::connection()->query('SELECT status, COUNT(*) FROM payments GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
