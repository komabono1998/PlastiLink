<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Helpers\Reference;

final class StockDispatch
{
    private const SELECT = 'SELECT d.*, h.name AS hub_name, r.business_name AS recycler_name, pc.name AS category_name,
                                   db.full_name AS dispatched_by_name, vb.full_name AS delivered_by_name, cb.full_name AS cancelled_by_name
                            FROM stock_dispatches d JOIN collection_hubs h ON h.id = d.hub_id JOIN recyclers r ON r.id = d.recycler_id
                            JOIN plastic_categories pc ON pc.id = d.plastic_category_id JOIN users db ON db.id = d.dispatched_by
                            LEFT JOIN users vb ON vb.id = d.delivered_by LEFT JOIN users cb ON cb.id = d.cancelled_by';

    private static function where(array $f, ?array $scope): array
    {
        $w = [];
        $a = [];
        if ($scope !== null) {
            $w[] = $scope ? 'd.hub_id IN (' . implode(',', array_map('intval', $scope)) . ')' : '1 = 0';
        }
        if (!empty($f['status'])) {
            $w[] = 'd.status = ?';
            $a[] = $f['status'];
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function paginate(array $f, ?array $scope, int $offset, int $limit): array
    {
        [$where, $a] = self::where($f, $scope);
        $s = Database::connection()->prepare(self::SELECT . " $where ORDER BY d.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function count(array $f, ?array $scope): int
    {
        [$where, $a] = self::where($f, $scope);
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM stock_dispatches d $where");
        $s->execute($a);
        return (int) $s->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE d.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function idByToken(string $token): ?int
    {
        $s = Database::connection()->prepare('SELECT id FROM stock_dispatches WHERE submission_token = ?');
        $s->execute([$token]);
        return ($v = $s->fetchColumn()) ? (int) $v : null;
    }

    public static function lock(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM stock_dispatches WHERE id = ? FOR UPDATE');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function insert(array $d): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO stock_dispatches (submission_token, hub_id, recycler_id, purchase_order_id, plastic_category_id, qty_kg, vehicle, driver, notes, dispatched_by, dispatched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$d['token'], $d['hub'], $d['recycler'], $d['order'] ?? null, $d['cat'], $d['qty'], $d['vehicle'], $d['driver'], $d['notes'], $d['by']]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE stock_dispatches SET reference = ? WHERE id = ?')->execute([Reference::make('DSP', $id), $id]);
        return $id;
    }

    public static function markDelivered(int $id, int $by, ?string $note): void
    {
        Database::connection()->prepare(
            "UPDATE stock_dispatches SET status = 'delivered', delivered_by = ?, delivered_at = UTC_TIMESTAMP(), delivery_note = ? WHERE id = ?"
        )->execute([$by, $note, $id]);
    }

    /** The recycler confirms the weight that actually arrived for an order dispatch. */
    public static function markReceived(int $id, int $by, string $qty, ?string $note): void
    {
        Database::connection()->prepare(
            "UPDATE stock_dispatches SET status = 'delivered', delivered_by = ?, delivered_at = UTC_TIMESTAMP(), received_kg = ?, receive_note = ? WHERE id = ?"
        )->execute([$by, $qty, $note, $id]);
    }

    /** @return array<int,array> dispatches made against an order, oldest first */
    public static function forOrder(int $orderId): array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE d.purchase_order_id = ? ORDER BY d.id');
        $s->execute([$orderId]);
        return $s->fetchAll();
    }

    public static function markCancelled(int $id, int $by, string $reason): void
    {
        Database::connection()->prepare(
            "UPDATE stock_dispatches SET status = 'cancelled', cancelled_by = ?, cancelled_at = UTC_TIMESTAMP(), cancel_reason = ? WHERE id = ?"
        )->execute([$by, $reason, $id]);
    }
}
