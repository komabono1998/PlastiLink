<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Collection
{
    private const SELECT = 'SELECT c.*, p.picker_code, pu.id AS picker_user_id, pu.full_name AS picker_name,
                h.name AS hub_name, h.code AS hub_code, n.name AS neighbourhood,
                pc.name AS category_name, pc.code AS category_code,
                rb.full_name AS recorded_by_name, db.full_name AS decided_by_name, rvb.full_name AS reversed_by_name,
                r.id AS receipt_id, r.receipt_number, r.voided_at AS receipt_voided_at
            FROM collection_transactions c
            JOIN pickers p ON p.id = c.picker_id
            JOIN users pu ON pu.id = p.user_id
            JOIN collection_hubs h ON h.id = c.hub_id
            JOIN neighbourhoods n ON n.id = c.neighbourhood_id
            JOIN plastic_categories pc ON pc.id = c.plastic_category_id
            LEFT JOIN users rb ON rb.id = c.recorded_by
            LEFT JOIN users db ON db.id = c.decided_by
            LEFT JOIN users rvb ON rvb.id = c.reversed_by
            LEFT JOIN receipts r ON r.collection_id = c.id';

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE c.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function idByToken(string $token): ?int
    {
        $s = Database::connection()->prepare('SELECT id FROM collection_transactions WHERE submission_token = ?');
        $s->execute([$token]);
        $v = $s->fetchColumn();
        return $v ? (int) $v : null;
    }

    /** Row lock for state changes (call inside a transaction). */
    public static function lock(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM collection_transactions WHERE id = ? FOR UPDATE');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function insert(array $d): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO collection_transactions
               (submission_token, picker_id, hub_id, neighbourhood_id, plastic_category_id, status, gross_weight_kg, tare_weight_kg,
                net_weight_kg, price_per_kg_ugx, price_list_id, total_value_ugx, notes, recorded_by, recorded_at)
             VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([
            $d['token'], $d['picker_id'], $d['hub_id'], $d['neighbourhood_id'], $d['plastic_category_id'],
            $d['gross'], $d['tare'], $d['net'], $d['price'], $d['price_list_id'], $d['total'], $d['notes'], $d['recorded_by'],
        ]);
        $id = (int) $pdo->lastInsertId();
        $ref = 'COL-' . str_replace('-', '', \App\Helpers\Dates::todayYmd()) . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE collection_transactions SET reference = ? WHERE id = ?')->execute([$ref, $id]);
        return $id;
    }

    public static function insertWeighing(int $id, int $seq, array $w, int $userId, ?string $reason): void
    {
        Database::connection()->prepare(
            'INSERT INTO weighing_records (collection_id, sequence_no, gross_weight_kg, tare_weight_kg, net_weight_kg, price_per_kg_ugx, total_value_ugx, reason, weighed_by, weighed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$id, $seq, $w['gross'], $w['tare'], $w['net'], $w['price'], $w['total'], $reason, $userId]);
    }

    public static function updateWeights(int $id, array $w, ?int $priceListId): void
    {
        Database::connection()->prepare(
            'UPDATE collection_transactions SET gross_weight_kg = ?, tare_weight_kg = ?, net_weight_kg = ?, price_per_kg_ugx = ?, price_list_id = ?, total_value_ugx = ? WHERE id = ?'
        )->execute([$w['gross'], $w['tare'], $w['net'], $w['price'], $priceListId, $w['total'], $id]);
    }

    public static function weighings(int $id): array
    {
        $s = Database::connection()->prepare(
            'SELECT w.*, u.full_name AS weighed_by_name FROM weighing_records w JOIN users u ON u.id = w.weighed_by WHERE w.collection_id = ? ORDER BY w.sequence_no'
        );
        $s->execute([$id]);
        return $s->fetchAll();
    }

    public static function setDecision(int $id, string $status, int $by, ?string $reason): void
    {
        Database::connection()->prepare(
            'UPDATE collection_transactions SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_reason = ? WHERE id = ?'
        )->execute([$status, $by, $reason, $id]);
    }

    public static function setReversal(int $id, int $by, string $reason): void
    {
        Database::connection()->prepare(
            "UPDATE collection_transactions SET status = 'reversed', reversed_by = ?, reversed_at = UTC_TIMESTAMP(), reversal_reason = ? WHERE id = ?"
        )->execute([$by, $reason, $id]);
    }

    /**
     * @param array{q?:string,status?:string,hub_id?:int|string,from?:string,to?:string} $f  (from/to are UTC bounds already)
     * @param int[]|null $hubScope  hub ids visible to hub staff; null = all
     * @param int|null $pickerUserId  when set, only this picker's own collections
     */
    private static function where(array $f, ?array $hubScope, ?int $pickerUserId): array
    {
        $w = [];
        $a = [];
        if ($hubScope !== null) {
            $w[] = $hubScope ? 'c.hub_id IN (' . implode(',', array_map('intval', $hubScope)) . ')' : '1 = 0';
        }
        if ($pickerUserId !== null) {
            $w[] = 'pu.id = ?';
            $a[] = $pickerUserId;
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(c.reference LIKE ? OR pu.full_name LIKE ? OR p.picker_code LIKE ?)';
            array_push($a, $like, $like, $like);
        }
        if (!empty($f['status'])) {
            $w[] = 'c.status = ?';
            $a[] = $f['status'];
        }
        if (!empty($f['hub_id'])) {
            $w[] = 'c.hub_id = ?';
            $a[] = (int) $f['hub_id'];
        }
        if (!empty($f['from'])) {
            $w[] = 'c.recorded_at >= ?';
            $a[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $w[] = 'c.recorded_at < ?';
            $a[] = $f['to'];
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function paginate(array $f, ?array $hubScope, ?int $pickerUserId, int $offset, int $limit): array
    {
        [$where, $a] = self::where($f, $hubScope, $pickerUserId);
        $s = Database::connection()->prepare(self::SELECT . " $where ORDER BY c.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function count(array $f, ?array $hubScope, ?int $pickerUserId): int
    {
        [$where, $a] = self::where($f, $hubScope, $pickerUserId);
        $s = Database::connection()->prepare(
            "SELECT COUNT(*) FROM collection_transactions c JOIN pickers p ON p.id = c.picker_id JOIN users pu ON pu.id = p.user_id $where"
        );
        $s->execute($a);
        return (int) $s->fetchColumn();
    }

    /**
     * Totals for a window (UTC bounds). Only approved collections count towards kg and value.
     * @return array{recorded:int, approved:int, kg:string, value:int, pending:int}
     */
    public static function summary(?array $hubScope, ?int $pickerUserId, ?string $fromUtc = null, ?string $toUtc = null): array
    {
        $f = ['from' => $fromUtc, 'to' => $toUtc];
        [$where, $a] = self::where($f, $hubScope, $pickerUserId);
        $s = Database::connection()->prepare(
            "SELECT COUNT(*) AS recorded,
                    COALESCE(SUM(c.status = 'approved'), 0) AS approved,
                    COALESCE(SUM(CASE WHEN c.status = 'approved' THEN c.net_weight_kg END), 0) AS kg,
                    COALESCE(SUM(CASE WHEN c.status = 'approved' THEN c.total_value_ugx END), 0) AS value
             FROM collection_transactions c JOIN pickers p ON p.id = c.picker_id JOIN users pu ON pu.id = p.user_id $where"
        );
        $s->execute($a);
        $r = $s->fetch();

        [$w2, $a2] = self::where(['status' => 'pending'], $hubScope, $pickerUserId);
        $p = Database::connection()->prepare("SELECT COUNT(*) FROM collection_transactions c JOIN pickers p ON p.id = c.picker_id JOIN users pu ON pu.id = p.user_id $w2");
        $p->execute($a2);

        return ['recorded' => (int) $r['recorded'], 'approved' => (int) $r['approved'], 'kg' => (string) $r['kg'],
                'value' => (int) $r['value'], 'pending' => (int) $p->fetchColumn()];
    }
}
