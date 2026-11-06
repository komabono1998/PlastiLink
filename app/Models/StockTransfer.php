<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Helpers\Reference;

final class StockTransfer
{
    private const SELECT = 'SELECT t.*, fh.name AS from_hub, th.name AS to_hub, pc.name AS category_name,
                                   sb.full_name AS sent_by_name, rb.full_name AS received_by_name, cb.full_name AS cancelled_by_name
                            FROM stock_transfers t
                            JOIN collection_hubs fh ON fh.id = t.from_hub_id JOIN collection_hubs th ON th.id = t.to_hub_id
                            JOIN plastic_categories pc ON pc.id = t.plastic_category_id
                            JOIN users sb ON sb.id = t.sent_by LEFT JOIN users rb ON rb.id = t.received_by LEFT JOIN users cb ON cb.id = t.cancelled_by';

    private static function where(array $f, ?array $scope): array
    {
        $w = [];
        $a = [];
        if ($scope !== null) {
            $in = implode(',', array_map('intval', $scope));
            $w[] = $scope ? "(t.from_hub_id IN ($in) OR t.to_hub_id IN ($in))" : '1 = 0';
        }
        if (!empty($f['status'])) {
            $w[] = 't.status = ?';
            $a[] = $f['status'];
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function paginate(array $f, ?array $scope, int $offset, int $limit): array
    {
        [$where, $a] = self::where($f, $scope);
        $s = Database::connection()->prepare(self::SELECT . " $where ORDER BY t.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function count(array $f, ?array $scope): int
    {
        [$where, $a] = self::where($f, $scope);
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM stock_transfers t $where");
        $s->execute($a);
        return (int) $s->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE t.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function idByToken(string $token): ?int
    {
        $s = Database::connection()->prepare('SELECT id FROM stock_transfers WHERE submission_token = ?');
        $s->execute([$token]);
        return ($v = $s->fetchColumn()) ? (int) $v : null;
    }

    public static function lock(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM stock_transfers WHERE id = ? FOR UPDATE');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function insert(array $d): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO stock_transfers (submission_token, from_hub_id, to_hub_id, plastic_category_id, qty_sent_kg, notes, sent_by, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$d['token'], $d['from'], $d['to'], $d['cat'], $d['qty'], $d['notes'], $d['by']]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE stock_transfers SET reference = ? WHERE id = ?')->execute([Reference::make('TRF', $id), $id]);
        return $id;
    }

    public static function markReceived(int $id, string $qty, int $by, ?string $note): void
    {
        Database::connection()->prepare(
            "UPDATE stock_transfers SET status = 'received', qty_received_kg = ?, received_by = ?, received_at = UTC_TIMESTAMP(), receive_note = ? WHERE id = ?"
        )->execute([$qty, $by, $note, $id]);
    }

    public static function markCancelled(int $id, int $by, string $reason): void
    {
        Database::connection()->prepare(
            "UPDATE stock_transfers SET status = 'cancelled', cancelled_by = ?, cancelled_at = UTC_TIMESTAMP(), cancel_reason = ? WHERE id = ?"
        )->execute([$by, $reason, $id]);
    }

    /** In-transit transfers waiting to be received at the given hubs (null = all). */
    public static function pendingReceipts(?array $scope): int
    {
        $w = $scope === null ? '' : ($scope ? 'AND to_hub_id IN (' . implode(',', array_map('intval', $scope)) . ')' : 'AND 1 = 0');
        return (int) Database::connection()->query("SELECT COUNT(*) FROM stock_transfers WHERE status = 'in_transit' $w")->fetchColumn();
    }
}
