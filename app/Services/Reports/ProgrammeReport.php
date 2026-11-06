<?php
declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\MonitoringService;

final class ProgrammeReport extends Report
{
    public static function key(): string { return 'programme'; }
    public static function title(): string { return 'Programme progress and monthly impact'; }
    public static function description(): string { return 'Progress against the programme targets and a month-by-month record of plastic recovered, people earning, income paid and hubs working. Measured figures only.'; }
    public static function filters(): array { return ['range']; }

    public function run(array $f): array
    {
        $months = MonitoringService::monthly($f['from'], $f['to']);
        $targets = MonitoringService::targetProgress();
        $tot = $this->sum($months, ['kg', 'val', 'collections', 'sold', 'paid', 'joined']);
        $tot['pickers'] = $months ? max(array_column($months, 'pickers')) : 0;

        $summary = [
            self::stat('Plastic collected (approved)', $tot['kg'], 'kg'), self::stat('Plastic sold and delivered', $tot['sold'], 'kg'),
            self::stat('Income earned by pickers', (int) $tot['val'], 'ugx'), self::stat('Income paid out to pickers', (int) $tot['paid'], 'ugx'),
            self::stat('New pickers registered', (int) $tot['joined'], 'int'), self::stat('Most pickers earning in one month', (int) $tot['pickers'], 'int'),
        ];
        $notes = ['Months are East Africa Time calendar months. "Pickers earning" counts pickers with at least one approved collection that month.',
                  'Targets and their progress cover the last 12 months and today, whatever dates are chosen. Targets are set by the Super Administrator.'];
        $imp = (array) config('app.impact', []);
        if (!empty($imp['bottles_per_kg']) || !empty($imp['co2e_kg_per_kg'])) {
            $src = trim((string) ($imp['source'] ?? '')) ?: 'source not stated';
            if (!empty($imp['bottles_per_kg'])) {
                $summary[] = self::stat('Estimated PET bottles collected (' . $imp['bottles_per_kg'] . ' per kg)', (int) round($tot['kg'] * (float) $imp['bottles_per_kg']), 'int');
            }
            if (!empty($imp['co2e_kg_per_kg'])) {
                $summary[] = self::stat('Estimated CO2e avoided, kg (' . $imp['co2e_kg_per_kg'] . ' per kg sold)', round($tot['sold'] * (float) $imp['co2e_kg_per_kg'], 1), 'num');
            }
            $notes[] = 'Estimates use the conversion factors configured by the programme. Source: ' . $src . '.';
        }

        return [
            'title'   => 'Programme progress and monthly impact report',
            'summary' => $summary,
            'tables'  => [
                ['title' => 'Progress against programme targets', 'columns' => [self::col('label', 'Indicator'), self::col('actual', 'Actual', 'num'), self::col('target', 'Target', 'num'), self::col('progress', 'Progress', 'pct')], 'rows' => $targets],
                ['title' => 'By month', 'columns' => [self::col('label', 'Month'), self::col('collections', 'Collections', 'int'), self::col('kg', 'Net weight', 'kg'), self::col('sold', 'Sold and delivered', 'kg'),
                    self::col('pickers', 'Pickers earning', 'int'), self::col('joined', 'New pickers', 'int'), self::col('hubs', 'Hubs collecting', 'int'), self::col('val', 'Earned', 'ugx'), self::col('paid', 'Paid out', 'ugx')],
                 'rows' => $months, 'totals' => $this->sum($months, ['collections', 'kg', 'sold', 'joined', 'val', 'paid'])],
            ],
            'chart'   => ['type' => 'bar', 'title' => 'Net kg collected per month', 'labels' => array_column($months, 'label'),
                          'datasets' => [['label' => 'Net kg', 'data' => array_map(static fn($r) => round((float) $r['kg'], 2), $months)]]],
            'notes'   => $notes,
        ];
    }
}
