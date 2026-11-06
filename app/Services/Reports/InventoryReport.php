<?php
declare(strict_types=1);

namespace App\Services\Reports;

final class InventoryReport extends Report
{
    public static function key(): string { return 'inventory'; }
    public static function title(): string { return 'Inventory'; }
    public static function description(): string { return 'Stock balances now, and for a period: opening stock + movements = closing stock.'; }
    public static function filters(): array { return ['range', 'hub']; }
    public static function hubManagerAllowed(): bool { return true; }

    public function run(array $f): array
    {
        $balances = $this->all(
            "SELECT h.name AS hub, pc.name AS material, i.on_hand_kg AS on_hand, i.reserved_kg AS reserved, (i.on_hand_kg - i.reserved_kg) AS available,
                    (SELECT COALESCE(SUM(t.qty_sent_kg),0) FROM stock_transfers t WHERE t.status = 'in_transit' AND t.from_hub_id = i.hub_id AND t.plastic_category_id = i.plastic_category_id) AS leaving,
                    (SELECT COALESCE(SUM(t.qty_sent_kg),0) FROM stock_transfers t WHERE t.status = 'in_transit' AND t.to_hub_id = i.hub_id AND t.plastic_category_id = i.plastic_category_id) AS arriving
             FROM inventory i JOIN collection_hubs h ON h.id = i.hub_id JOIN plastic_categories pc ON pc.id = i.plastic_category_id
             WHERE 1 = 1" . $this->hubIn($f, 'i.hub_id') . ' ORDER BY h.name, pc.name'
        );

        $m =$this->all(
            "SELECT h.name AS hub, pc.name AS material,
                    COALESCE(SUM(CASE WHEN m.created_at < ? THEN m.qty_kg END), 0) AS opening,
                    COALESCE(SUM(CASE WHEN m.created_at >= ? AND m.created_at < ? AND m.movement_type = 'collection_in' THEN m.qty_kg END), 0) AS collections,
                    COALESCE(SUM(CASE WHEN m.created_at >= ? AND m.created_at < ? AND m.movement_type = 'collection_reversal' THEN m.qty_kg END), 0) AS reversals,
                    COALESCE(SUM(CASE WHEN m.created_at >= ? AND m.created_at < ? AND m.movement_type IN ('transfer_in','transfer_return') THEN m.qty_kg END), 0) AS transfers_in,
                    COALESCE(SUM(CASE WHEN m.created_at >= ? AND m.created_at < ? AND m.movement_type = 'transfer_out' THEN m.qty_kg END), 0) AS transfers_out,
                    COALESCE(SUM(CASE WHEN m.created_at >= ? AND m.created_at < ? AND m.movement_type IN ('dispatch_out','dispatch_return') THEN m.qty_kg END), 0) AS dispatched,
                    COALESCE(SUM(CASE WHEN m.created_at >= ? AND m.created_at < ? AND m.movement_type IN ('adjustment_in','adjustment_out') THEN m.qty_kg END), 0) AS adjustments,
                    COALESCE(SUM(CASE WHEN m.created_at >= ? AND m.created_at < ? AND m.movement_type = 'write_off' THEN m.qty_kg END), 0) AS write_offs,
                    COALESCE(SUM(CASE WHEN m.created_at < ? THEN m.qty_kg END), 0) AS closing
             FROM stock_movements m JOIN collection_hubs h ON h.id = m.hub_id JOIN plastic_categories pc ON pc.id = m.plastic_category_id
             WHERE 1 = 1" . $this->hubIn($f, 'm.hub_id') . ' GROUP BY m.hub_id, m.plastic_category_id, h.name, pc.name ORDER BY h.name, pc.name',
            [$f['from_utc'], $f['from_utc'], $f['to_utc'], $f['from_utc'], $f['to_utc'], $f['from_utc'], $f['to_utc'], $f['from_utc'], $f['to_utc'], $f['from_utc'], $f['to_utc'],
             $f['from_utc'], $f['to_utc'], $f['from_utc'], $f['to_utc'], $f['to_utc']]
        );
        $live = [];
        foreach ($balances as $b) {
            $live[$b['hub'] . '|' . $b['material']] = (float) $b['on_hand'];
        }
        $reachesNow = $f['to_utc'] > gmdate('Y-m-d H:i:s');
        foreach ($m as &$r) {
            $r['live'] = $live[$r['hub'] . '|' . $r['material']] ?? 0.0;
            $r['matches'] = $reachesNow ? (abs((float) $r['closing'] - $r['live']) < 0.005 ? 'Yes' : 'NO') : 'n/a';
        }
        unset($r);
        $bt = $this->sum($balances, ['on_hand', 'reserved', 'available', 'leaving', 'arriving']);
        $mt = $this->sum($m, ['opening', 'collections', 'reversals', 'transfers_in', 'transfers_out', 'dispatched', 'adjustments', 'write_offs', 'closing']);
        $bad = count(array_filter($m, static fn($r) => $r['matches'] === 'NO'));

        return [
            'title'   => 'Inventory report',
            'summary' => [
                self::stat('Stock on hand now', $bt['on_hand'], 'kg'), self::stat('Reserved for orders', $bt['reserved'], 'kg'), self::stat('Available to sell', $bt['available'], 'kg'),
                self::stat('Opening stock (start of period)', $mt['opening'], 'kg'), self::stat('Closing stock (end of period)', $mt['closing'], 'kg'),
                self::stat('Closing stock matches live balance', $reachesNow ? ($bad === 0 ? 'Yes' : "NO ($bad)") : 'n/a (period ends in the past)', 'text'),
            ],
            'tables'  => [
                ['title' => 'Stock balances now', 'columns' => [self::col('hub', 'Hub'), self::col('material', 'Material'), self::col('on_hand', 'On hand', 'kg'), self::col('reserved', 'Reserved', 'kg'),
                    self::col('available', 'Available', 'kg'), self::col('leaving', 'Leaving (in transit)', 'kg'), self::col('arriving', 'Arriving (in transit)', 'kg')],
                 'rows' => $balances, 'totals' => $bt],
                ['title' => 'Stock movement reconciliation', 'columns' => [self::col('hub', 'Hub'), self::col('material', 'Material'), self::col('opening', 'Opening', 'kg'),
                    self::col('collections', 'Collections in', 'kg'), self::col('reversals', 'Collection reversals', 'kg'), self::col('transfers_in', 'Transfers in', 'kg'),
                    self::col('transfers_out', 'Transfers out', 'kg'), self::col('dispatched', 'Dispatched', 'kg'), self::col('adjustments', 'Adjustments', 'kg'),
                    self::col('write_offs', 'Write-offs', 'kg'), self::col('closing', 'Closing', 'kg'), self::col('matches', 'Matches live balance')],
                 'rows' => $m, 'totals' => $mt],
            ],
            'chart'   => ['type' => 'bar', 'title' => 'Stock on hand by hub (kg)', 'labels' => array_map(static fn($b) => $b['hub'] . ' · ' . $b['material'], $balances),
                          'datasets' => [['label' => 'On hand', 'data' => array_map(static fn($b) => round((float) $b['on_hand'], 2), $balances)], ['label' => 'Reserved', 'data' => array_map(static fn($b) => round((float) $b['reserved'], 2), $balances)]]],
            'notes'   => ['Opening + collections + reversals + transfers + dispatches + adjustments + write-offs = closing. Dispatch and write-off movements are negative. Movements are read from the stock ledger.'],
        ];
    }
}
