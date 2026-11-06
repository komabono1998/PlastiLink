<?php
declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\MonitoringService;

final class GeographicReport extends Report
{
    public static function key(): string { return 'geographic'; }
    public static function title(): string { return 'Geographic'; }
    public static function description(): string { return 'Where plastic is recovered: hubs with locations and performance, recovery by neighbourhood (hotspots) and recycler locations.'; }
    public static function filters(): array { return ['range', 'hub']; }
    public static function hubManagerAllowed(): bool { return true; }

    public function run(array $f): array
    {
        $d = MonitoringService::mapData($f);
        $loc = static fn($v) => $v === null ? null : number_format((float) $v, 6, '.', '');
        $hubs = array_map(static fn($h) => $h + ['lat' => $loc($h['latitude']), 'lng' => $loc($h['longitude'])], $d['hubs']);
        $spots = array_map(static fn($n) => $n + ['lat' => $loc($n['latitude']), 'lng' => $loc($n['longitude'])], $d['hotspots']);
        $rec = array_map(static fn($r) => $r + ['lat' => $loc($r['latitude']), 'lng' => $loc($r['longitude'])], $d['recyclers']);

        $tables = [
            ['title' => 'Recovery by neighbourhood (hotspots)', 'columns' => [self::col('name', 'Neighbourhood'), self::col('collections', 'Collections', 'int'), self::col('kg', 'Net weight', 'kg'),
                self::col('share', 'Share of total', 'pct'), self::col('pickers', 'Pickers collecting', 'int'), self::col('val', 'Value', 'ugx'), self::col('lat', 'Latitude'), self::col('lng', 'Longitude')],
             'rows' => $spots, 'totals' => $this->sum($spots, ['collections', 'kg', 'val'])],
            ['title' => 'Hubs by location', 'columns' => [self::col('name', 'Hub'), self::col('neighbourhood', 'Neighbourhood'), self::col('status', 'Status'), self::col('collections', 'Collections', 'int'),
                self::col('kg', 'Net weight', 'kg'), self::col('on_hand', 'Stock on hand', 'kg'), self::col('lat', 'Latitude'), self::col('lng', 'Longitude')],
             'rows' => $hubs, 'totals' => $this->sum($hubs, ['collections', 'kg', 'on_hand'])],
        ];
        if ($f['hubs'] === null) {
            $tables[] = ['title' => 'Recycler locations', 'columns' => [self::col('name', 'Recycler'), self::col('address', 'Address'), self::col('lat', 'Latitude'), self::col('lng', 'Longitude')], 'rows' => $rec];
        }
        $top = $spots[0] ?? null;
        return [
            'title'   => 'Geographic report',
            'summary' => [
                self::stat('Plastic recovered (approved)', $d['totals']['kg'], 'kg'), self::stat('Approved collections', (int) $d['totals']['collections'], 'int'),
                self::stat('Neighbourhoods with collections', count(array_filter($spots, static fn($n) => (int) $n['collections'] > 0)), 'int'),
                self::stat('Top neighbourhood', $top && (float) $top['kg'] > 0 ? $top['name'] : null, 'text'),
                self::stat('Places without coordinates', (int) $d['totals']['unlocated'], 'int'),
            ],
            'tables'  => $tables,
            'chart'   => ['type' => 'bar', 'title' => 'Net kg recovered per neighbourhood', 'labels' => array_column($spots, 'name'),
                          'datasets' => [['label' => 'Net kg', 'data' => array_map(static fn($n) => round((float) $n['kg'], 2), $spots)]]],
            'notes'   => ['Recovery is counted per neighbourhood (the picker\'s home neighbourhood); individual pickers and collections are never given a location.',
                          'Places without coordinates appear in the tables but not on the map. Only approved collections count.'],
        ];
    }
}
