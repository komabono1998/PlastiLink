<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Helpers\Reference;
use PDO;

/** Purchase orders, their agreed item and the negotiation rounds. Rules live in App\Services\OrderService. */
final class PurchaseOrder
{
    private const SELECT = 'SELECT o.*, r.business_name, ru.id AS recycler_user_id, h.name AS hub_name, n.name AS neighbourhood, pc.name AS category_name,
                it.id AS item_id, it.agreed_qty_kg, it.price_per_kg_ugx AS item_price, it.line_total_ugx, it.dispatched_kg, it.received_kg,
                lo.qty_kg AS offer_qty_kg, lo.price_per_kg_ugx AS offer_price, lo.total_ugx AS offer_total, lo.delivery_date AS offer_date
            FROM purchase_orders o
            JOIN recyclers r ON r.id = o.recycler_id JOIN users ru ON ru.id = r.user_id
            JOIN collection_hubs h ON h.id = o.hub_id JOIN neighbourhoods n ON n.id = h.neighbourhood_id
            JOIN plastic_categories pc ON pc.id = o.plastic_category_id
            LEFT JOIN purchase_order_items it ON it.order_id = o.id
            LEFT JOIN order_offers lo ON lo.order_id = o.id AND lo.round_no = o.round_count';

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE o.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function idByToken(string $token): ?int
    {
        $s = Database::connection()->prepare('SELECT id FROM purchase_orders WHERE submission_token = ?');
        $s->execute([$token]);
        return ($v = $s->fetchColumn()) ? (int) $v : null;
    }

    /** Row lock (call inside a transaction). */
    public static function lock(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function lockItem(int $orderId): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM purchase_order_items WHERE order_id = ? FOR UPDATE');
        $s->execute([$orderId]);
        return $s->fetch() ?: null;
    }

    public static function insert(array $d): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO purchase_orders (submission_token, recycler_id, hub_id, plastic_category_id, status, last_offer_by, round_count, expires_at, created_by, created_at)
             VALUES (?, ?, ?, ?, \'negotiating\', \'recycler\', 1, UTC_TIMESTAMP() + INTERVAL ? HOUR, ?, UTC_TIMESTAMP())'
        )->execute([$d['token'], $d['recycler'], $d['hub'], $d['cat'], $d['hours'], $d['by']]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE purchase_orders SET reference = ? WHERE id = ?')->execute([Reference::make('PO', $id), $id]);
        return $id;
    }

    public static function insertOffer(int $orderId, int $round, string $side, int $by, string $qty, int $price, int $total, ?string $date, ?string $note): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_offers (order_id, round_no, offered_by_side, offered_by, qty_kg, price_per_kg_ugx, total_ugx, delivery_date, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$orderId, $round, $side, $by, $qty, $price, $total, $date, $note]);
    }

    /** Close the currently open offer with a final state. */
    public static function closeOpenOffer(int $orderId, string $status): void
    {
        Database::connection()->prepare(
            "UPDATE order_offers SET status = ?, responded_at = UTC_TIMESTAMP() WHERE order_id = ? AND status = 'open'"
        )->execute([$status, $orderId]);
    }

    public static function offers(int $orderId): array
    {
        $s = Database::connection()->prepare(
            'SELECT f.*, u.full_name AS by_name FROM order_offers f JOIN users u ON u.id = f.offered_by WHERE f.order_id = ? ORDER BY f.round_no'
        );
        $s->execute([$orderId]);
        return $s->fetchAll();
    }

    public static function setCounter(int $id, int $round, string $side, int $hours): void
    {
        Database::connection()->prepare(
            'UPDATE purchase_orders SET round_count = ?, last_offer_by = ?, expires_at = UTC_TIMESTAMP() + INTERVAL ? HOUR WHERE id = ?'
        )->execute([$round, $side, $hours, $id]);
    }

    public static function insertItem(int $orderId, int $catId, string $qty, int $price, int $total): void
    {
        Database::connection()->prepare(
            'INSERT INTO purchase_order_items (order_id, plastic_category_id, agreed_qty_kg, price_per_kg_ugx, line_total_ugx) VALUES (?, ?, ?, ?, ?)'
        )->execute([$orderId, $catId, $qty, $price, $total]);
    }

    public static function confirm(int $id, int $by, ?string $date): void
    {
        Database::connection()->prepare(
            "UPDATE purchase_orders SET status = 'confirmed', agreed_at = UTC_TIMESTAMP(), accepted_by = ?, scheduled_for = ?, expires_at = NULL WHERE id = ?"
        )->execute([$by, $date, $id]);
    }

    /** End the order (rejected / withdrawn / cancelled / expired) with who, when and why. */
    public static function end(int $id, string $status, ?int $by, ?string $note): void
    {
        Database::connection()->prepare(
            'UPDATE purchase_orders SET status = ?, ended_by = ?, ended_at = UTC_TIMESTAMP(), end_note = ?, expires_at = NULL WHERE id = ?'
        )->execute([$status, $by, $note, $id]);
    }

    public static function complete(int $id, string $shortKg, ?int $by, ?string $note): void
    {
        Database::connection()->prepare(
            "UPDATE purchase_orders SET status = 'completed', completed_at = UTC_TIMESTAMP(), short_closed_kg = ?, ended_by = ?, end_note = COALESCE(?, end_note) WHERE id = ?"
        )->execute([$shortKg, $by, $note, $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::connection()->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    public static function setSchedule(int $id, ?string $date, ?string $note): void
    {
        Database::connection()->prepare('UPDATE purchase_orders SET scheduled_for = ?, schedule_note = ? WHERE id = ?')->execute([$date, $note, $id]);
    }

    public static function setItemQuantities(int $orderId, string $dispatched, string $received): void
    {
        Database::connection()->prepare('UPDATE purchase_order_items SET dispatched_kg = ?, received_kg = ? WHERE order_id = ?')->execute([$dispatched, $received, $orderId]);
    }

    /** Move stale negotiations to "expired". Returns how many orders changed. */
    public static function expireStale(): int
    {
        $pdo = Database::connection();
        $ids = $pdo->query("SELECT id FROM purchase_orders WHERE status = 'negotiating' AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $pdo->prepare("UPDATE order_offers SET status = 'expired', responded_at = UTC_TIMESTAMP() WHERE order_id = ? AND status = 'open'")->execute([$id]);
            $pdo->prepare("UPDATE purchase_orders SET status = 'expired', ended_at = UTC_TIMESTAMP(), end_note = 'No response within the offer validity period', expires_at = NULL WHERE id = ? AND status = 'negotiating'")->execute([$id]);
        }
        return count($ids);
    }

    public static function openNegotiations(int $recyclerId): int
    {
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM purchase_orders WHERE recycler_id = ? AND status = 'negotiating'");
        $s->execute([$recyclerId]);
        return (int) $s->fetchColumn();
    }

    private static function where(array $f, ?int $recyclerId, ?array $hubScope): array
    {
        $w = [];
        $a = [];
        if ($recyclerId !== null) {
            $w[] = 'o.recycler_id = ?';
            $a[] = $recyclerId;
        }
        if ($hubScope !== null) {
            $w[] = $hubScope ? 'o.hub_id IN (' . implode(',', array_map('intval', $hubScope)) . ')' : '1 = 0';
        }
        if (!empty($f['status'])) {
            $w[] = 'o.status = ?';
            $a[] = $f['status'];
        }
        if (!empty($f['awaiting'])) {   // negotiating and the last offer came from the OTHER side
            $w[] = "o.status = 'negotiating' AND o.last_offer_by = ?";
            $a[] = $f['awaiting'] === 'recycler' ? 'seller' : 'recycler';
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(o.reference LIKE ? OR r.business_name LIKE ?)';
            array_push($a, $like, $like);
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function paginate(array $f, ?int $recyclerId, ?array $hubScope, int $offset, int $limit): array
    {
        [$where, $a] = self::where($f, $recyclerId, $hubScope);
        $s = Database::connection()->prepare(self::SELECT . " $where ORDER BY o.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function count(array $f, ?int $recyclerId, ?array $hubScope): int
    {
        [$where, $a] = self::where($f, $recyclerId, $hubScope);
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM purchase_orders o JOIN recyclers r ON r.id = o.recycler_id $where");
        $s->execute($a);
        return (int) $s->fetchColumn();
    }

    /**
     * Dashboard figures. $side = 'recycler' (their own orders) or 'seller' (hubs in scope).
     * @return array{awaiting:int, negotiating:int, active:int, completed:int, received_kg:string, received_value:int}
     */
    public static function stats(string $side, ?int $recyclerId, ?array $hubScope): array
    {
        [$where, $a] = self::where([], $recyclerId, $hubScope);
        $s = Database::connection()->prepare(
            "SELECT
               COALESCE(SUM(o.status = 'negotiating' AND o.last_offer_by = ?), 0) AS awaiting,
               COALESCE(SUM(o.status = 'negotiating'), 0) AS negotiating,
               COALESCE(SUM(o.status IN ('confirmed','in_delivery')), 0) AS active,
               COALESCE(SUM(o.status = 'completed'), 0) AS completed,
               COALESCE(SUM(it.received_kg), 0) AS received_kg,
               COALESCE(SUM(FLOOR((it.received_kg * 100 * it.price_per_kg_ugx + 50) / 100)), 0) AS received_value
             FROM purchase_orders o JOIN recyclers r ON r.id = o.recycler_id LEFT JOIN purchase_order_items it ON it.order_id = o.id $where"
        );
        $s->execute(array_merge([$side === 'recycler' ? 'seller' : 'recycler'], $a));
        $r = $s->fetch();
        return ['awaiting' => (int) $r['awaiting'], 'negotiating' => (int) $r['negotiating'], 'active' => (int) $r['active'],
                'completed' => (int) $r['completed'], 'received_kg' => (string) $r['received_kg'], 'received_value' => (int) $r['received_value']];
    }

    /** Plastic available to buy: on hand minus reserved, per active hub and material. */
    public static function available(): array
    {
        return Database::connection()->query(
            "SELECT i.hub_id, i.plastic_category_id, h.name AS hub_name, n.name AS neighbourhood, pc.name AS category_name,
                    (i.on_hand_kg - i.reserved_kg) AS available_kg
             FROM inventory i JOIN collection_hubs h ON h.id = i.hub_id JOIN neighbourhoods n ON n.id = h.neighbourhood_id
             JOIN plastic_categories pc ON pc.id = i.plastic_category_id
             WHERE h.status = 'active' AND pc.is_active = 1 AND (i.on_hand_kg - i.reserved_kg) > 0 ORDER BY h.name, pc.name"
        )->fetchAll();
    }
}
