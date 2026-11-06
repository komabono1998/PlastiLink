<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Helpers\Reference;

final class Invoice
{
    private const SELECT = "SELECT i.*, r.business_name, h.name AS hub_name, o.reference AS order_reference,
                                   COALESCE((SELECT SUM(p.amount_ugx) FROM payments p WHERE p.invoice_id = i.id AND p.direction = 'in' AND p.status = 'successful'), 0) AS paid_ugx,
                                   COALESCE((SELECT SUM(p.amount_ugx) FROM payments p WHERE p.invoice_id = i.id AND p.direction = 'in' AND p.status = 'pending'), 0) AS pending_ugx
                            FROM invoices i JOIN recyclers r ON r.id = i.recycler_id JOIN collection_hubs h ON h.id = i.hub_id JOIN purchase_orders o ON o.id = i.order_id";

    private static function where(array $f, ?int $recyclerId, ?array $hubScope): array
    {
        $w = [];
        $a = [];
        if ($recyclerId !== null) {
            $w[] = 'i.recycler_id = ?';
            $a[] = $recyclerId;
        }
        if ($hubScope !== null) {
            $w[] = $hubScope ? 'i.hub_id IN (' . implode(',', array_map('intval', $hubScope)) . ')' : '1 = 0';
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'overdue') {
                $w[] = "i.status IN ('issued','partially_paid') AND i.due_date < ?";
                $a[] = \App\Helpers\Dates::todayYmd();
            } else {
                $w[] = 'i.status = ?';
                $a[] = $f['status'];
            }
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(i.invoice_number LIKE ? OR r.business_name LIKE ? OR o.reference LIKE ?)';
            array_push($a, $like, $like, $like);
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function paginate(array $f, ?int $recyclerId, ?array $hubScope, int $offset, int $limit): array
    {
        [$where, $a] = self::where($f, $recyclerId, $hubScope);
        $s = Database::connection()->prepare(self::SELECT . " $where ORDER BY i.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function count(array $f, ?int $recyclerId, ?array $hubScope): int
    {
        [$where, $a] = self::where($f, $recyclerId, $hubScope);
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM invoices i JOIN recyclers r ON r.id = i.recycler_id JOIN purchase_orders o ON o.id = i.order_id $where");
        $s->execute($a);
        return (int) $s->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE i.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function lock(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function insert(array $d): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO invoices (order_id, recycler_id, hub_id, received_kg, price_per_kg_ugx, amount_ugx, issued_at, due_date) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)'
        )->execute([$d['order'], $d['recycler'], $d['hub'], $d['kg'], $d['price'], $d['amount'], $d['due']]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE invoices SET invoice_number = ? WHERE id = ?')->execute([Reference::make('INV', $id), $id]);
        return $id;
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::connection()->prepare('UPDATE invoices SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    public static function markVoid(int $id, int $by, string $reason): void
    {
        Database::connection()->prepare("UPDATE invoices SET status = 'void', voided_by = ?, voided_at = UTC_TIMESTAMP(), void_reason = ? WHERE id = ?")->execute([$by, $reason, $id]);
    }

    /** Completed orders that received plastic but have no invoice yet. @return int[] */
    public static function ordersMissingInvoice(): array
    {
        return array_map('intval', Database::connection()->query(
            "SELECT o.id FROM purchase_orders o JOIN purchase_order_items it ON it.order_id = o.id LEFT JOIN invoices i ON i.order_id = o.id
             WHERE o.status = 'completed' AND it.received_kg > 0 AND i.id IS NULL ORDER BY o.id"
        )->fetchAll(\PDO::FETCH_COLUMN));
    }
}
