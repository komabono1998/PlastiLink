<?php
declare(strict_types=1);

namespace App\Services\Reports;

final class HubPerformanceReport extends Report
{
    public static function key(): string { return 'hubs'; }
    public static function title(): string { return 'Hub performance'; }
    public static function description(): string { return 'Per hub: intake, approval speed, rejections, stock, dispatches and losses.'; }
    public static function filters(): array { return ['range', 'hub']; }
    public static function hubManagerAllowed(): bool { return true; }

    public function run(array $f): array
    {
        $range = [$f['from_utc'], $f['to_utc']];
        $hubs = $this->all('SELECT h.id, h.name, n.name AS neighbourhood FROM collection_hubs h JOIN neighbourhoods n ON n.id = h.neighbourhood_id WHERE 1 = 1' . $this->hubIn($f, 'h.id') . ' ORDER BY h.name');

        $coll = $this->keyed($this->all(
            "SELECT hub_id AS id, SUM(status = 'approved') AS n, COALESCE(SUM(CASE WHEN status = 'approved' THEN net_weight_kg END), 0) AS kg,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN total_value_ugx END), 0) AS val,
                    COUNT(DISTINCT CASE WHEN status = 'approved' THEN picker_id END) AS pickers, SUM(status = 'rejected') AS rej,
                    AVG(CASE WHEN status IN ('approved','reversed') AND decided_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, recorded_at, decided_at) END) AS mins
             FROM collection_transactions WHERE recorded_at >= ? AND recorded_at < ? GROUP BY hub_id", $range));
        $stock = $this->keyed($this->all('SELECT hub_id AS id, SUM(on_hand_kg) AS kg FROM inventory GROUP BY hub_id'));
        $disp = $this->keyed($this->all("SELECT hub_id AS id, -SUM(qty_kg) AS kg FROM stock_movements WHERE movement_type IN ('dispatch_out','dispatch_return') AND created_at >= ? AND created_at < ? GROUP BY hub_id", $range));
        $wo = $this->keyed($this->all("SELECT hub_id AS id, -SUM(qty_kg) AS kg FROM stock_movements WHERE movement_type = 'write_off' AND created_at >= ? AND created_at < ? GROUP BY hub_id", $range));
        $tl = $this->keyed($this->all("SELECT from_hub_id AS id, SUM(qty_sent_kg - qty_received_kg) AS kg FROM stock_transfers WHERE status = 'received' AND received_at >= ? AND received_at < ? GROUP BY from_hub_id", $range));

        $rows = [];
        foreach ($hubs as $h) {
            $id = (int) $h['id'];
            $c = $coll[$id] ?? [];
            $kg = (float) ($c['kg'] ?? 0);
            $n = (int) ($c['n'] ?? 0);
            $rej = (int) ($c['rej'] ?? 0);
            $loss = (float) ($wo[$id]['kg'] ?? 0) + (float) ($tl[$id]['kg'] ?? 0);
            $rows[] = [
                'hub' => $h['name'], 'neighbourhood' => $h['neighbourhood'], 'n' => $n, 'kg' => $kg, 'val' => (int) ($c['val'] ?? 0), 'pickers' => (int) ($c['pickers'] ?? 0),
                'hours' => isset($c['mins']) && $c['mins'] !== null ? (float) $c['mins'] / 60 : null,
                'rej_rate' => $n + $rej > 0 ? $rej / ($n + $rej) * 100 : null,
                'stock' => (float) ($stock[$id]['kg'] ?? 0), 'dispatched' => (float) ($disp[$id]['kg'] ?? 0), 'loss' => $loss, 'loss_rate' => $kg > 0 ? $loss / $kg * 100 : null,
            ];
        }
        $t = $this->sum($rows, ['n', 'kg', 'val', 'stock', 'dispatched', 'loss']);

        return [
            'title'   => 'Hub performance report',
            'summary' => [
                self::stat('Hubs in this report', count($rows), 'int'), self::stat('Approved collections', (int) $t['n'], 'int'), self::stat('Plastic collected', $t['kg'], 'kg'),
                self::stat('Stock on hand now', $t['stock'], 'kg'), self::stat('Dispatched in the period', $t['dispatched'], 'kg'), self::stat('Losses in the period', $t['loss'], 'kg'),
            ],
            'tables'  => [[
                'title' => 'Hubs',
                'columns' => [self::col('hub', 'Hub'), self::col('neighbourhood', 'Neighbourhood'), self::col('n', 'Approved', 'int'), self::col('kg', 'Net weight', 'kg'), self::col('val', 'Value', 'ugx'),
                    self::col('pickers', 'Pickers', 'int'), self::col('hours', 'Avg time to approve', 'hours'), self::col('rej_rate', 'Rejection rate', 'pct'), self::col('stock', 'Stock now', 'kg'),
                    self::col('dispatched', 'Dispatched', 'kg'), self::col('loss', 'Losses', 'kg'), self::col('loss_rate', 'Loss % of intake', 'pct')],
                'rows' => $rows, 'totals' => ['n' => (int) $t['n'], 'kg' => $t['kg'], 'val' => (int) $t['val'], 'stock' => $t['stock'], 'dispatched' => $t['dispatched'], 'loss' => $t['loss']],
            ]],
            'chart'   => ['type' => 'bar', 'title' => 'Net kg collected by hub', 'labels' => array_column($rows, 'hub'),
                          'datasets' => [['label' => 'Net kg', 'data' => array_map(static fn($r) => round($r['kg'], 2), $rows)]]],
            'notes'   => ['Approval time is from recording to a decision. Losses are write-offs plus weight lost in transit between hubs. Rejection rate is rejected / (approved + rejected). Stock is the balance now, not at the end of the period.'],
        ];
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function keyed(array $rows): array
    {
        $o = [];
        foreach ($rows as $r) {
            $o[(int) $r['id']] = $r;
        }
        return $o;
    }
}
