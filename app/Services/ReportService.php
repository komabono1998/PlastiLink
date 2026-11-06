<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Dates;
use App\Models\Hub;
use App\Models\Neighbourhood;
use App\Services\Reports\CollectionsReport;
use App\Services\Reports\FinancialReport;
use App\Services\Reports\HubPerformanceReport;
use App\Services\Reports\ImpactReport;
use App\Services\Reports\InventoryReport;
use App\Services\Reports\PaymentsReport;
use App\Services\Reports\PickerPerformanceReport;
use App\Services\Reports\RecyclerPurchasingReport;
use App\Services\Reports\Report;

/** Registry of reports, who may open them, filter parsing and running. */
final class ReportService
{
    public const EXPORT_MAX_ROWS = 50000;
    public const HTML_MAX_ROWS = 500;
    private const MAX_RANGE_DAYS = 1826;

    /** @var array<string,class-string<Report>> */
    private const REPORTS = [
        'collections' => CollectionsReport::class,
        'pickers'     => PickerPerformanceReport::class,
        'hubs'        => HubPerformanceReport::class,
        'inventory'   => InventoryReport::class,
        'recyclers'   => RecyclerPurchasingReport::class,
        'financial'   => FinancialReport::class,
        'payments'    => PaymentsReport::class,
        'impact'      => ImpactReport::class,
    ];

    /** @return array<string,class-string<Report>> reports this user may open */
    public static function allowedFor(array $actor): array
    {
        if (!StockInput::can($actor, 'reports.view')) {
            return [];
        }
        return array_filter(self::REPORTS, static fn(string $cls) => in_array($actor['role_slug'], ['super_admin', 'project_manager'], true)
            || ($actor['role_slug'] === 'hub_manager' && $cls::hubManagerAllowed()));
    }

    /** @return class-string<Report>|null */
    public static function find(string $key, array $actor): ?string
    {
        return self::allowedFor($actor)[$key] ?? null;
    }

    /**
     * Turn raw query-string values into safe, validated filters (invalid values fall back to defaults).
     * Hub Managers are always limited to their own hubs, whatever they ask for.
     */
    public static function filters(array $query, string $reportClass, array $actor): array
    {
        $today = Dates::todayYmd();
        $valid = static fn(mixed $d) => is_string($d) && Dates::dayRangeUtc($d) !== null ? $d : null;
        $to = $valid($query['to'] ?? null) ?? $today;
        $from = $valid($query['from'] ?? null) ?? (new \DateTimeImmutable($to))->modify('-29 days')->format('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ((new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > self::MAX_RANGE_DAYS) {
            $from = (new \DateTimeImmutable($to))->modify('-' . self::MAX_RANGE_DAYS . ' days')->format('Y-m-d');
        }

        $scope = HubScope::forUser($actor);
        $hubOptions = Hub::options($scope);
        $hubId = (int) ($query['hub_id'] ?? 0);
        $hubId = isset($hubOptions[$hubId]) ? $hubId : 0;
        $hubs = $hubId > 0 ? [$hubId] : $scope;

        $nbId = (int) ($query['neighbourhood_id'] ?? 0);
        $nbId = array_key_exists($nbId, Neighbourhood::options()) ? $nbId : 0;

        return [
            'from' => $from, 'to' => $to,
            'from_utc' => Dates::dayRangeUtc($from)[0], 'to_utc' => Dates::dayRangeUtc($to)[1],
            'hub_id' => $hubId ?: null, 'hubs' => $hubs, 'neighbourhood_id' => $nbId ?: null,
            'group' => in_array($query['group'] ?? '', ['day', 'week', 'month'], true) ? $query['group'] : 'day',
            'inactive_days' => max(1, min(365, (int) ($query['inactive_days'] ?? 14) ?: 14)),
            'hub_options' => $hubOptions, 'nb_options' => Neighbourhood::options(),
        ];
    }

    /** @return array{result:array, filters:array, meta:array} */
    public static function run(string $reportClass, array $query, array $actor): array
    {
        $f = self::filters($query, $reportClass, $actor);
        $result = (new $reportClass())->run($f);
        return ['result' => $result, 'filters' => $f, 'meta' => self::meta($f, $reportClass)];
    }

    /** Period and filter text used on screen and in exports. */
    public static function meta(array $f, string $reportClass): array
    {
        $parts = [];
        $filters = $reportClass::filters();
        if (in_array('hub', $filters, true)) {
            $parts[] = 'Hub: ' . ($f['hub_id'] ? ($f['hub_options'][$f['hub_id']] ?? '') : ($f['hubs'] === null ? 'All' : 'My hubs'));
        }
        if (in_array('neighbourhood', $filters, true)) {
            $parts[] = 'Neighbourhood: ' . ($f['neighbourhood_id'] ? ($f['nb_options'][$f['neighbourhood_id']] ?? '') : 'All');
        }
        if (in_array('group', $filters, true)) {
            $parts[] = 'Grouped by ' . $f['group'];
        }
        if (in_array('inactive_days', $filters, true)) {
            $parts[] = 'Quiet after ' . $f['inactive_days'] . ' days';
        }
        return [
            'period'    => date('d M Y', strtotime($f['from'])) . ' to ' . date('d M Y', strtotime($f['to'])),
            'filters'   => implode('; ', $parts),
            'generated' => format_datetime(gmdate('Y-m-d H:i:s'), 'd M Y, H:i') . ' EAT',
        ];
    }

    /** Total number of data rows across all tables of a result. */
    public static function rowCount(array $result): int
    {
        return array_sum(array_map(static fn($t) => count($t['rows']), $result['tables']));
    }

    /** Shorten every table to $max rows (exports are capped; screens show a preview). Adds a note when something was cut. */
    public static function limitRows(array $result, int $max, string $what): array
    {
        $cut = false;
        foreach ($result['tables'] as &$t) {
            if (count($t['rows']) > $max) {
                $t['rows'] = array_slice($t['rows'], 0, $max);
                $t['truncated'] = true;
                $cut = true;
            }
        }
        unset($t);
        if ($cut) {
            $result['notes'][] = "Only the first $max rows of each table are included in $what.";
        }
        return $result;
    }
}
