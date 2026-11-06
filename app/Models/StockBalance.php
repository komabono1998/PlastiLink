<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/** Balances (inventory table) and the movement ledger. Writes go through App\Services\StockService only. */
final class StockBalance
{
    /** Row-lock the balance for a hub/material (creating it at zero on first use). Must run inside a transaction. */
    public static function lock(int $hubId, int $catId): array
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT IGNORE INTO inventory (hub_id, plastic_category_id) VALUES (?, ?)')->execute([$hubId, $catId]);
        $s = $pdo->prepare('SELECT * FROM inventory WHERE hub_id = ? AND plastic_category_id = ? FOR UPDATE');
        $s->execute([$hubId, $catId]);
        return $s->fetch();
    }

    public static function find(int $hubId, int $catId): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM inventory WHERE hub_id = ? AND plastic_category_id = ?');
        $s->execute([$hubId, $catId]);
        return $s->fetch() ?: null;
    }

    public static function update(int $id, string $onHand, string $reserved): void
    {
        Database::connection()->prepare('UPDATE inventory SET on_hand_kg = ?, reserved_kg = ? WHERE id = ?')->execute([$onHand, $reserved, $id]);
    }

    public static function insertMovement(array $m): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO stock_movements (hub_id, plastic_category_id, movement_type, qty_kg, reserved_delta_kg, balance_after_kg, reserved_after_kg,
                                           source_type, source_id, reference, reason, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$m['hub'], $m['cat'], $m['type'], $m['qty'], $m['res_delta'], $m['balance'], $m['reserved'],
                    $m['source_type'], $m['source_id'], $m['reference'], $m['reason'], $m['user']]);
        return (int) $pdo->lastInsertId();
    }

    private static function scopeSql(?array $scope, string $col): string
    {
        return $scope === null ? '' : ($scope ? "$col IN (" . implode(',', array_map('intval', $scope)) . ')' : '1 = 0');
    }

    /** @param int[]|null $scope */
    public static function balances(?array $scope): array
    {
        $w = self::scopeSql($scope, 'i.hub_id');
        return Database::connection()->query(
            "SELECT i.*, h.name AS hub_name, pc.name AS category_name,
                    (SELECT COALESCE(SUM(t.qty_sent_kg), 0) FROM stock_transfers t WHERE t.status = 'in_transit' AND t.from_hub_id = i.hub_id AND t.plastic_category_id = i.plastic_category_id) AS transit_out_kg,
                    (SELECT COALESCE(SUM(t.qty_sent_kg), 0) FROM stock_transfers t WHERE t.status = 'in_transit' AND t.to_hub_id = i.hub_id AND t.plastic_category_id = i.plastic_category_id) AS transit_in_kg,
                    (SELECT COALESCE(SUM(-m.qty_kg), 0) FROM stock_movements m WHERE m.hub_id = i.hub_id AND m.plastic_category_id = i.plastic_category_id AND m.movement_type = 'write_off') AS written_off_kg
             FROM inventory i JOIN collection_hubs h ON h.id = i.hub_id JOIN plastic_categories pc ON pc.id = i.plastic_category_id
             " . ($w ? "WHERE $w" : '') . ' ORDER BY h.name, pc.name'
        )->fetchAll();
    }

    /** Totals per material across the hubs the viewer may see. */
    public static function totals(?array $scope): array
    {
        $w = self::scopeSql($scope, 'i.hub_id');
        return Database::connection()->query(
            "SELECT pc.name AS category_name, SUM(i.on_hand_kg) AS on_hand_kg, SUM(i.reserved_kg) AS reserved_kg
             FROM inventory i JOIN plastic_categories pc ON pc.id = i.plastic_category_id " . ($w ? "WHERE $w " : '') . 'GROUP BY pc.id, pc.name ORDER BY pc.name'
        )->fetchAll();
    }

    /** Ledger vs balances: returns rows where they disagree (should be empty). */
    public static function mismatches(): array
    {
        return Database::connection()->query(
            'SELECT i.id, i.hub_id, i.plastic_category_id, i.on_hand_kg, i.reserved_kg,
                    COALESCE(SUM(m.qty_kg), 0) AS ledger_on_hand, COALESCE(SUM(m.reserved_delta_kg), 0) AS ledger_reserved
             FROM inventory i LEFT JOIN stock_movements m ON m.hub_id = i.hub_id AND m.plastic_category_id = i.plastic_category_id
             GROUP BY i.id, i.hub_id, i.plastic_category_id, i.on_hand_kg, i.reserved_kg
             HAVING ledger_on_hand <> i.on_hand_kg OR ledger_reserved <> i.reserved_kg'
        )->fetchAll();
    }

    private static function movementWhere(array $f, ?array $scope): array
    {
        $w = [];
        $a = [];
        if ($scope !== null) {
            $w[] = $scope ? 'm.hub_id IN (' . implode(',', array_map('intval', $scope)) . ')' : '1 = 0';
        }
        if (!empty($f['hub_id'])) {
            $w[] = 'm.hub_id = ?';
            $a[] = (int) $f['hub_id'];
        }
        if (!empty($f['type'])) {
            $w[] = 'm.movement_type = ?';
            $a[] = $f['type'];
        }
        if (!empty($f['from'])) {
            $w[] = 'm.created_at >= ?';
            $a[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $w[] = 'm.created_at < ?';
            $a[] = $f['to'];
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function movements(array $f, ?array $scope, int $offset, int $limit): array
    {
        [$where, $a] = self::movementWhere($f, $scope);
        $s = Database::connection()->prepare(
            "SELECT m.*, h.name AS hub_name, pc.name AS category_name, u.full_name AS by_name
             FROM stock_movements m JOIN collection_hubs h ON h.id = m.hub_id JOIN plastic_categories pc ON pc.id = m.plastic_category_id
             LEFT JOIN users u ON u.id = m.created_by $where ORDER BY m.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function countMovements(array $f, ?array $scope): int
    {
        [$where, $a] = self::movementWhere($f, $scope);
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM stock_movements m $where");
        $s->execute($a);
        return (int) $s->fetchColumn();
    }
}
