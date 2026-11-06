<?php
declare(strict_types=1);

namespace App\Services\Reports;

final class CollectionsReport extends Report
{
    public static function key(): string { return 'collections'; }
    public static function title(): string { return 'Collections'; }
    public static function description(): string { return 'Plastic collected by day, week or month, with kilograms and value by hub and neighbourhood.'; }
    public static function filters(): array { return ['range', 'hub', 'neighbourhood', 'group']; }
    public static function hubManagerAllowed(): bool { return true; }

    public function run(array $f): array
    {
        $local = 'DATE_ADD(c.recorded_at, INTERVAL ' . self::offset() . ' SECOND)';
        $period = match ($f['group']) {
            'month' => "DATE_FORMAT($local, '%Y-%m')",
            'week'  => "DATE_SUB(DATE($local), INTERVAL WEEKDAY($local) DAY)",
            default => "DATE($local)",
        };
        $w = 'c.recorded_at >= ? AND c.recorded_at < ?' . $this->hubIn($f, 'c.hub_id');
        $a = [$f['from_utc'], $f['to_utc']];
        if ($f['neighbourhood_id']) {
            $w .= ' AND c.neighbourhood_id = ?';
            $a[] = $f['neighbourhood_id'];
        }
        $agg = "SUM(c.status = 'approved') AS approved,
                COALESCE(SUM(CASE WHEN c.status = 'approved' THEN c.net_weight_kg END), 0) AS kg,
                COALESCE(SUM(CASE WHEN c.status = 'approved' THEN c.total_value_ugx END), 0) AS val,
                SUM(c.status = 'rejected') AS rejected, SUM(c.status = 'reversed') AS reversed";

        $byPeriod = $this->all("SELECT $period AS p, $agg FROM collection_transactions c WHERE $w GROUP BY p ORDER BY p", $a);
        foreach ($byPeriod as &$r) {
            $r['label'] = match ($f['group']) {
                'month' => date('M Y', strtotime($r['p'] . '-01')),
                'week'  => 'Week of ' . date('d M Y', strtotime($r['p'])),
                default => date('D d M Y', strtotime($r['p'])),
            };
        }
        unset($r);
        $byHub = $this->all("SELECT h.name AS label, $agg FROM collection_transactions c JOIN collection_hubs h ON h.id = c.hub_id WHERE $w GROUP BY h.id, h.name ORDER BY kg DESC", $a);
        $byNb = $this->all("SELECT n.name AS label, $agg FROM collection_transactions c JOIN neighbourhoods n ON n.id = c.neighbourhood_id WHERE $w GROUP BY n.id, n.name ORDER BY kg DESC", $a);

        $cols = fn(string $first) => [self::col('label', $first), self::col('approved', 'Approved', 'int'), self::col('kg', 'Net weight', 'kg'),
            self::col('val', 'Value', 'ugx'), self::col('rejected', 'Rejected', 'int'), self::col('reversed', 'Reversed', 'int')];
        $tot = fn(array $rows) => $this->sum($rows, ['approved', 'kg', 'val', 'rejected', 'reversed']);
        $t = $tot($byPeriod);

        return [
            'title'   => 'Collections report',
            'summary' => [
                self::stat('Approved collections', (int) $t['approved'], 'int'), self::stat('Plastic collected (approved)', $t['kg'], 'kg'),
                self::stat('Value of approved collections', (int) $t['val'], 'ugx'), self::stat('Rejected collections', (int) $t['rejected'], 'int'),
                self::stat('Reversed collections', (int) $t['reversed'], 'int'),
                self::stat('Average per approved collection', $t['approved'] > 0 ? $t['kg'] / $t['approved'] : null, 'kg'),
            ],
            'tables'  => [
                ['title' => 'By ' . $f['group'], 'columns' => $cols(ucfirst($f['group'])), 'rows' => $byPeriod, 'totals' => $t],
                ['title' => 'By hub', 'columns' => $cols('Hub'), 'rows' => $byHub, 'totals' => $tot($byHub)],
                ['title' => 'By neighbourhood', 'columns' => $cols('Neighbourhood'), 'rows' => $byNb, 'totals' => $tot($byNb)],
            ],
            'chart'   => ['type' => 'bar', 'title' => 'Net kg collected per ' . $f['group'], 'labels' => array_column($byPeriod, 'label'),
                          'datasets' => [['label' => 'Net kg', 'data' => array_map(static fn($r) => round((float) $r['kg'], 2), $byPeriod)]]],
            'notes'   => ['Only approved collections count towards kilograms and value. Dates are in East Africa Time.'],
        ];
    }
}
