<?php
declare(strict_types=1);

namespace App\Services\Reports;

final class PickerPerformanceReport extends Report
{
    public static function key(): string { return 'pickers'; }
    public static function title(): string { return 'Picker performance'; }
    public static function description(): string { return 'Each active picker: deliveries, kilograms, earnings, last delivery, and who has gone quiet.'; }
    public static function filters(): array { return ['range', 'hub', 'neighbourhood', 'inactive_days']; }
    public static function hubManagerAllowed(): bool { return true; }

    public function run(array $f): array
    {
        $w = "u.status = 'active'" . $this->hubIn($f, 'p.preferred_hub_id');
        $a = [$f['from_utc'], $f['to_utc']];
        if ($f['neighbourhood_id']) {
            $w .= ' AND p.neighbourhood_id = ?';
            $a[] = $f['neighbourhood_id'];
        }
        $rows = $this->all(
            "SELECT p.picker_code, u.full_name AS name, n.name AS neighbourhood, h.name AS hub, p.verification_status AS verification,
                    COALESCE(a.cnt, 0) AS cnt, COALESCE(a.kg, 0) AS kg, COALESCE(a.val, 0) AS val, l.last_at
             FROM pickers p JOIN users u ON u.id = p.user_id JOIN neighbourhoods n ON n.id = p.neighbourhood_id
             LEFT JOIN collection_hubs h ON h.id = p.preferred_hub_id
             LEFT JOIN (SELECT picker_id, COUNT(*) AS cnt, SUM(net_weight_kg) AS kg, SUM(total_value_ugx) AS val FROM collection_transactions
                        WHERE status = 'approved' AND recorded_at >= ? AND recorded_at < ? GROUP BY picker_id) a ON a.picker_id = p.id
             LEFT JOIN (SELECT picker_id, MAX(recorded_at) AS last_at FROM collection_transactions WHERE status = 'approved' GROUP BY picker_id) l ON l.picker_id = p.id
             WHERE $w ORDER BY kg DESC, u.full_name",
            $a
        );
        $now = time();
        $inactive = [];
        $delivering = 0;
        foreach ($rows as &$r) {
            $r['avg'] = (int) $r['cnt'] > 0 ? (float) $r['kg'] / (int) $r['cnt'] : null;
            $r['days'] = $r['last_at'] ? (int) floor(($now - strtotime($r['last_at'] . ' UTC')) / 86400) : null;
            $r['last'] = $r['last_at'];
            $delivering += (int) $r['cnt'] > 0 ? 1 : 0;
            if ($r['days'] === null || $r['days'] >= $f['inactive_days']) {
                $inactive[] = $r;
            }
        }
        unset($r);
        usort($inactive, static fn($x, $y) => ($y['days'] ?? PHP_INT_MAX) <=> ($x['days'] ?? PHP_INT_MAX));
        $t = $this->sum($rows, ['cnt', 'kg', 'val']);

        $cols = [self::col('picker_code', 'Picker ID'), self::col('name', 'Name'), self::col('neighbourhood', 'Neighbourhood'), self::col('hub', 'Preferred hub'),
            self::col('cnt', 'Deliveries', 'int'), self::col('kg', 'Net weight', 'kg'), self::col('val', 'Earned', 'ugx'), self::col('avg', 'Avg per delivery', 'kg'),
            self::col('last', 'Last delivery', 'datetime'), self::col('days', 'Days since', 'int')];
        $top = array_slice(array_filter($rows, static fn($r) => (float) $r['kg'] > 0), 0, 10);

        return [
            'title'   => 'Picker performance report',
            'summary' => [
                self::stat('Active pickers', count($rows), 'int'), self::stat('Pickers who delivered in the period', $delivering, 'int'),
                self::stat('Net weight delivered', $t['kg'], 'kg'), self::stat('Earned in the period', (int) $t['val'], 'ugx'),
                self::stat('Average per delivering picker', $delivering > 0 ? $t['kg'] / $delivering : null, 'kg'),
                self::stat('Quiet pickers (no delivery for ' . $f['inactive_days'] . '+ days)', count($inactive), 'int'),
            ],
            'tables'  => [
                ['title' => 'Pickers', 'columns' => $cols, 'rows' => $rows, 'totals' => ['cnt' => (int) $t['cnt'], 'kg' => $t['kg'], 'val' => (int) $t['val']]],
                ['title' => 'Quiet pickers (no delivery for ' . $f['inactive_days'] . '+ days)',
                 'columns' => [self::col('picker_code', 'Picker ID'), self::col('name', 'Name'), self::col('neighbourhood', 'Neighbourhood'), self::col('hub', 'Preferred hub'),
                               self::col('last', 'Last delivery', 'datetime'), self::col('days', 'Days since', 'int')], 'rows' => $inactive],
            ],
            'chart'   => ['type' => 'bar', 'title' => 'Top pickers by net kg', 'labels' => array_map(static fn($r) => $r['name'], $top),
                          'datasets' => [['label' => 'Net kg', 'data' => array_map(static fn($r) => round((float) $r['kg'], 2), $top)]]],
            'notes'   => ['Deliveries and kilograms count approved collections recorded in the period. "Last delivery" looks at all time. Contact details and national IDs are never included.'],
        ];
    }
}
