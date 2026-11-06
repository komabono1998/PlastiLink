<?php
declare(strict_types=1);

namespace App\Services\Reports;

use App\Helpers\Dates;
use App\Models\Setting;

final class ImpactReport extends Report
{
    public static function key(): string { return 'impact'; }
    public static function title(): string { return 'Environmental and programme impact'; }
    public static function description(): string { return 'Plastic collected and sold, people earning, income paid and progress against the programme targets. Measured figures only.'; }
    public static function filters(): array { return ['range', 'hub', 'neighbourhood']; }

    public function run(array $f): array
    {
        $r = [$f['from_utc'], $f['to_utc']];
        $cw = 'c.status = \'approved\' AND c.recorded_at >= ? AND c.recorded_at < ?' . $this->hubIn($f, 'c.hub_id');
        $ca = $r;
        if ($f['neighbourhood_id']) {
            $cw .= ' AND c.neighbourhood_id = ?';
            $ca[] = $f['neighbourhood_id'];
        }
        $c = $this->all("SELECT COUNT(*) AS n, COALESCE(SUM(c.net_weight_kg), 0) AS kg, COALESCE(SUM(c.total_value_ugx), 0) AS val, COUNT(DISTINCT c.picker_id) AS pickers FROM collection_transactions c WHERE $cw", $ca)[0];
        $sold = (float) $this->one("SELECT COALESCE(SUM(COALESCE(d.received_kg, d.qty_kg)), 0) FROM stock_dispatches d WHERE d.status = 'delivered' AND d.delivered_at >= ? AND d.delivered_at < ?" . $this->hubIn($f, 'd.hub_id'), $r);

        $pw = "y.direction = 'out' AND y.status = 'successful' AND y.processed_at >= ? AND y.processed_at < ?" . $this->hubIn($f, 'p.preferred_hub_id');
        $pa = $r;
        if ($f['neighbourhood_id']) {
            $pw .= ' AND p.neighbourhood_id = ?';
            $pa[] = $f['neighbourhood_id'];
        }
        $paid = (int) $this->one("SELECT COALESCE(SUM(y.amount_ugx), 0) FROM payments y JOIN pickers p ON p.id = y.picker_id WHERE $pw", $pa);

        $byNb = $this->all(
            "SELECT n.name AS label, COUNT(*) AS n, COALESCE(SUM(c.net_weight_kg), 0) AS kg, COUNT(DISTINCT c.picker_id) AS pickers, COALESCE(SUM(c.total_value_ugx), 0) AS val
             FROM collection_transactions c JOIN neighbourhoods n ON n.id = c.neighbourhood_id WHERE $cw GROUP BY n.id, n.name ORDER BY kg DESC", $ca);
        $off = self::offset();
        $trend = $this->all(
            "SELECT DATE_FORMAT(DATE_ADD(c.recorded_at, INTERVAL $off SECOND), '%Y-%m') AS m, COALESCE(SUM(c.net_weight_kg), 0) AS kg, COUNT(DISTINCT c.picker_id) AS pickers
             FROM collection_transactions c WHERE $cw GROUP BY m ORDER BY m", $ca);
        foreach ($trend as &$x) {
            $x['label'] = date('M Y', strtotime($x['m'] . '-01'));
        }
        unset($x);

        // Programme targets (from settings) against the last 12 months and today's registrations, independent of the filters
        $s = Setting::all();
        $today = Dates::todayYmd();
        $yearAgo = Dates::dayRangeUtc((new \DateTimeImmutable($today))->modify('-364 days')->format('Y-m-d'));
        $endRange = Dates::dayRangeUtc($today);
        $kgYear = (float) $this->one("SELECT COALESCE(SUM(net_weight_kg), 0) FROM collection_transactions WHERE status = 'approved' AND recorded_at >= ? AND recorded_at < ?", [$yearAgo[0], $endRange[1]]);
        $soldYear = (float) $this->one("SELECT COALESCE(SUM(COALESCE(received_kg, qty_kg)), 0) FROM stock_dispatches WHERE status = 'delivered' AND delivered_at >= ? AND delivered_at < ?", [$yearAgo[0], $endRange[1]]);
        $pickersNow = (int) $this->one("SELECT COUNT(*) FROM pickers p JOIN users u ON u.id = p.user_id WHERE u.status = 'active'");
        $hubsNow = (int) $this->one("SELECT COUNT(*) FROM collection_hubs WHERE status = 'active'");
        $recNow = (int) $this->one("SELECT COUNT(*) FROM recyclers WHERE status = 'approved'");
        $pct = static fn($actual, $target) => $target ? min(100.0, $actual / $target * 100) : null;
        $targets = [
            ['label' => 'Registered waste pickers (active), 12-month target', 'actual' => $pickersNow, 'target' => (int) ($s['target.pickers'] ?? 0)],
            ['label' => 'Registered waste pickers (active), pilot target', 'actual' => $pickersNow, 'target' => (int) ($s['target.pilot_pickers'] ?? 0)],
            ['label' => 'Active collection hubs', 'actual' => $hubsNow, 'target' => (int) ($s['target.hubs'] ?? 0)],
            ['label' => 'Approved recycling companies', 'actual' => $recNow, 'target' => (int) ($s['target.recyclers'] ?? 0)],
            ['label' => 'Plastic collected, last 12 months (kg)', 'actual' => $kgYear, 'target' => (int) ($s['target.kg_12_months'] ?? 0)],
            ['label' => 'Plastic sold and delivered, last 12 months (kg)', 'actual' => $soldYear, 'target' => (int) ($s['target.kg_12_months'] ?? 0)],
        ];
        foreach ($targets as &$t) {
            $t['progress'] = $pct($t['actual'], $t['target']);
        }
        unset($t);

        $summary = [
            self::stat('Plastic collected (approved)', $c['kg'], 'kg'), self::stat('Plastic sold and delivered', $sold, 'kg'), self::stat('Approved collections', (int) $c['n'], 'int'),
            self::stat('Pickers who earned income', (int) $c['pickers'], 'int'), self::stat('Income earned by pickers', (int) $c['val'], 'ugx'), self::stat('Income paid out to pickers', $paid, 'ugx'),
        ];
        $notes = ['Only measured figures are shown: kilograms from approved collections, kilograms confirmed delivered, people and shillings from the ledger.',
                  'Programme targets and progress are for the last 12 months and today, regardless of the filters above.'];
        $imp = (array) config('app.impact', []);
        if (!empty($imp['bottles_per_kg']) || !empty($imp['co2e_kg_per_kg'])) {
            $src = trim((string) ($imp['source'] ?? '')) ?: 'source not stated';
            if (!empty($imp['bottles_per_kg'])) {
                $summary[] = self::stat('Estimated PET bottles collected (' . $imp['bottles_per_kg'] . ' per kg)', (int) round((float) $c['kg'] * (float) $imp['bottles_per_kg']), 'int');
            }
            if (!empty($imp['co2e_kg_per_kg'])) {
                $summary[] = self::stat('Estimated CO2e avoided, kg (' . $imp['co2e_kg_per_kg'] . ' per kg sold)', round($sold * (float) $imp['co2e_kg_per_kg'], 1), 'num');
            }
            $notes[] = 'Estimates use the conversion factors configured by the programme. Source: ' . $src . '.';
        }

        return [
            'title'   => 'Environmental and programme impact report',
            'summary' => $summary,
            'tables'  => [
                ['title' => 'By neighbourhood', 'columns' => [self::col('label', 'Neighbourhood'), self::col('n', 'Collections', 'int'), self::col('kg', 'Net weight', 'kg'), self::col('pickers', 'Pickers', 'int'), self::col('val', 'Earned', 'ugx')],
                 'rows' => $byNb, 'totals' => $this->sum($byNb, ['n', 'kg', 'val'])],
                ['title' => 'By month', 'columns' => [self::col('label', 'Month'), self::col('kg', 'Net weight', 'kg'), self::col('pickers', 'Pickers earning', 'int')], 'rows' => $trend, 'totals' => $this->sum($trend, ['kg'])],
                ['title' => 'Progress against programme targets', 'columns' => [self::col('label', 'Indicator'), self::col('actual', 'Actual', 'num'), self::col('target', 'Target', 'num'), self::col('progress', 'Progress', 'pct')], 'rows' => $targets],
            ],
            'chart'   => ['type' => 'bar', 'title' => 'Net kg collected per month', 'labels' => array_column($trend, 'label'),
                          'datasets' => [['label' => 'Net kg', 'data' => array_map(static fn($r) => round((float) $r['kg'], 2), $trend)]]],
            'notes'   => $notes,
        ];
    }
}
