<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Helpers\Reference;

final class StockAdjustment
{
    private const SELECT = 'SELECT a.*, h.name AS hub_name, pc.name AS category_name, rb.full_name AS requested_by_name, db.full_name AS decided_by_name
                            FROM stock_adjustments a JOIN collection_hubs h ON h.id = a.hub_id JOIN plastic_categories pc ON pc.id = a.plastic_category_id
                            JOIN users rb ON rb.id = a.requested_by LEFT JOIN users db ON db.id = a.decided_by';

    private static function where(array $f, ?array $scope): array
    {
        $w = [];
        $a = [];
        if ($scope !== null) {
            $w[] = $scope ? 'a.hub_id IN (' . implode(',', array_map('intval', $scope)) . ')' : '1 = 0';
        }
        if (!empty($f['status'])) {
            $w[] = 'a.status = ?';
            $a[] = $f['status'];
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function paginate(array $f, ?array $scope, int $offset, int $limit): array
    {
        [$where, $a] = self::where($f, $scope);
        $s = Database::connection()->prepare(self::SELECT . " $where ORDER BY a.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function count(array $f, ?array $scope): int
    {
        [$where, $a] = self::where($f, $scope);
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM stock_adjustments a $where");
        $s->execute($a);
        return (int) $s->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE a.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function idByToken(string $token): ?int
    {
        $s = Database::connection()->prepare('SELECT id FROM stock_adjustments WHERE submission_token = ?');
        $s->execute([$token]);
        return ($v = $s->fetchColumn()) ? (int) $v : null;
    }

    public static function lock(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM stock_adjustments WHERE id = ? FOR UPDATE');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function insert(array $d): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO stock_adjustments (submission_token, hub_id, plastic_category_id, kind, system_kg, counted_kg, change_kg, reason, requested_by, requested_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$d['token'], $d['hub'], $d['cat'], $d['kind'], $d['system'], $d['counted'], $d['change'], $d['reason'], $d['by']]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE stock_adjustments SET reference = ? WHERE id = ?')->execute([Reference::make('ADJ', $id), $id]);
        return $id;
    }

    public static function decide(int $id, string $status, int $by, ?string $note): void
    {
        Database::connection()->prepare(
            'UPDATE stock_adjustments SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = ? WHERE id = ?'
        )->execute([$status, $by, $note, $id]);
    }

    public static function pendingCount(?array $scope): int
    {
        $w = $scope === null ? '' : ($scope ? 'AND hub_id IN (' . implode(',', array_map('intval', $scope)) . ')' : 'AND 1 = 0');
        return (int) Database::connection()->query("SELECT COUNT(*) FROM stock_adjustments WHERE status = 'pending' $w")->fetchColumn();
    }
}
