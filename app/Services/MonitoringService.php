<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Dates;
use App\Models\AuditLog;
use App\Models\Setting;

/**
 * Geographic and programme monitoring. Everything is calculated from recorded data when asked; nothing is hard-coded.
 * Privacy: locations are shown only for hubs, recyclers (businesses) and neighbourhood centres. Individual pickers and
 * individual collections are never placed on a map; plastic recovery is counted per neighbourhood.
 */
final class MonitoringService
{
    /** Editable programme targets: setting key => label. */
    public const TARGETS = [
        'target.pilot_pickers' => 'Pilot waste picker target',
        'target.pickers'       => '12-month registered waste picker target',
        'target.hubs'          => '12-month collection hub target',
        'target.recyclers'     => 'Minimum recycling company target',
        'target.kg_12_months'  => '12-month plastic collected and sold (kg)',
    ];
    public const TARGET_MAX = 100000000;
    public const MAX_MONTHS = 36;

    private static function hubIn(?array $hubs, string $col): string
    {
        if ($hubs === null) {
            return '';
        }
        return $hubs ? " AND $col IN (" . implode(',', array_map('intval', $hubs)) . ')' : ' AND 1 = 0';
    }

    private static function rows(string $sql, array $args = []): array
    {
        $s = Database::connection()->prepare($sql);
        $s->execute($args);
        return $s->fetchAll();
    }

    private static function coord(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    /**
     * Everything the map and the geographic report need, for the period and hubs in $f (see ReportService::filters).
     * @return array{hubs:array, hotspots:array, recyclers:array, totals:array}
     */
    public static function mapData(array $f): array
    {
        $range = [$f['from_utc'], $f['to_utc']];
        $hubs = self::rows(
            "SELECT h.id, h.name, h.status, h.latitude, h.longitude, n.name AS neighbourhood, COALESCE(inv.on_hand, 0) AS on_hand,
                    COALESCE(col.n, 0) AS collections, COALESCE(col.kg, 0) AS kg, COALESCE(col.val, 0) AS val
             FROM collection_hubs h JOIN neighbourhoods n ON n.id = h.neighbourhood_id
             LEFT JOIN (SELECT hub_id, SUM(on_hand_kg) AS on_hand FROM inventory GROUP BY hub_id) inv ON inv.hub_id = h.id
             LEFT JOIN (SELECT hub_id, COUNT(*) AS n, SUM(net_weight_kg) AS kg, SUM(total_value_ugx) AS val FROM collection_transactions
                        WHERE status = 'approved' AND recorded_at >= ? AND recorded_at < ? GROUP BY hub_id) col ON col.hub_id = h.id
             WHERE 1 = 1" . self::hubIn($f['hubs'], 'h.id') . ' ORDER BY kg DESC, h.name', $range);
        foreach ($hubs as &$h) {
            $h['latitude'] = self::coord($h['latitude']);
            $h['longitude'] = self::coord($h['longitude']);
        }
        unset($h);

        $hotspots = self::rows(
            "SELECT n.id, n.name, n.latitude, n.longitude, COUNT(c.id) AS collections, COALESCE(SUM(c.net_weight_kg), 0) AS kg,
                    COALESCE(SUM(c.total_value_ugx), 0) AS val, COUNT(DISTINCT c.picker_id) AS pickers
             FROM neighbourhoods n LEFT JOIN collection_transactions c ON c.neighbourhood_id = n.id AND c.status = 'approved'
                  AND c.recorded_at >= ? AND c.recorded_at < ?" . self::hubIn($f['hubs'], 'c.hub_id') . '
             WHERE n.is_active = 1 GROUP BY n.id, n.name, n.latitude, n.longitude ' . ($f['hubs'] === null ? '' : 'HAVING collections > 0 ') . 'ORDER BY kg DESC, n.name', $range);
        $totalKg = array_sum(array_map(static fn($r) => (float) $r['kg'], $hotspots));
        foreach ($hotspots as &$x) {
            $x['latitude'] = self::coord($x['latitude']);
            $x['longitude'] = self::coord($x['longitude']);
            $x['share'] = $totalKg > 0 ? (float) $x['kg'] / $totalKg * 100 : null;
        }
        unset($x);

        // Recycling companies are businesses; shown to administrators and project managers only (a Hub Manager's view is limited to their hubs)
        $recyclers = $f['hubs'] === null
            ? self::rows("SELECT id, business_name AS name, address, latitude, longitude FROM recyclers WHERE status = 'approved' ORDER BY business_name")
            : [];
        foreach ($recyclers as &$r) {
            $r['latitude'] = self::coord($r['latitude']);
            $r['longitude'] = self::coord($r['longitude']);
        }
        unset($r);

        return [
            'hubs' => $hubs, 'hotspots' => $hotspots, 'recyclers' => $recyclers,
            'totals' => ['kg' => $totalKg, 'collections' => array_sum(array_column($hotspots, 'collections')),
                'unlocated' => count(array_filter($hubs, static fn($h) => $h['latitude'] === null)) + count(array_filter($recyclers, static fn($r) => $r['latitude'] === null))
                    + count(array_filter($hotspots, static fn($n) => $n['latitude'] === null && (int) $n['collections'] > 0))],
        ];
    }

    /** Points the browser map draws. Only places (hubs, recyclers, neighbourhood centres) with coordinates; no people. */
    public static function mapPoints(array $data): array
    {
        $pt = static fn(array $r, array $extra) => ['name' => (string) $r['name'], 'lat' => $r['latitude'], 'lng' => $r['longitude']] + $extra;
        return [
            'hubs' => array_values(array_map(fn($h) => $pt($h, ['status' => $h['status'], 'kg' => (float) $h['kg'], 'stock' => (float) $h['on_hand']]),
                array_filter($data['hubs'], static fn($h) => $h['latitude'] !== null))),
            'recyclers' => array_values(array_map(fn($r) => $pt($r, []), array_filter($data['recyclers'], static fn($r) => $r['latitude'] !== null))),
            'hotspots' => array_values(array_map(fn($n) => $pt($n, ['kg' => (float) $n['kg'], 'collections' => (int) $n['collections'], 'pickers' => (int) $n['pickers']]),
                array_filter($data['hotspots'], static fn($n) => $n['latitude'] !== null && (int) $n['collections'] > 0))),
        ];
    }

    /** Progress against the programme targets: the last 12 months and today's registrations. */
    public static function targetProgress(): array
    {
        $s = Setting::all();
        $pdo = Database::connection();
        $today = Dates::todayYmd();
        $from = Dates::dayRangeUtc((new \DateTimeImmutable($today))->modify('-364 days')->format('Y-m-d'))[0];
        $to = Dates::dayRangeUtc($today)[1];
        $one = static function (string $sql, array $a = []) use ($pdo) {
            $q = $pdo->prepare($sql);
            $q->execute($a);
            return $q->fetchColumn();
        };
        $kgYear = (float) $one("SELECT COALESCE(SUM(net_weight_kg), 0) FROM collection_transactions WHERE status = 'approved' AND recorded_at >= ? AND recorded_at < ?", [$from, $to]);
        $soldYear = (float) $one("SELECT COALESCE(SUM(COALESCE(received_kg, qty_kg)), 0) FROM stock_dispatches WHERE status = 'delivered' AND delivered_at >= ? AND delivered_at < ?", [$from, $to]);
        $pickers = (int) $one("SELECT COUNT(*) FROM pickers p JOIN users u ON u.id = p.user_id WHERE u.status = 'active'");
        $hubs = (int) $one("SELECT COUNT(*) FROM collection_hubs WHERE status = 'active'");
        $recyclers = (int) $one("SELECT COUNT(*) FROM recyclers WHERE status = 'approved'");
        $rows = [
            ['key' => 'target.pickers', 'label' => 'Registered waste pickers (active), 12-month target', 'actual' => $pickers, 'target' => (int) ($s['target.pickers'] ?? 0)],
            ['key' => 'target.pilot_pickers', 'label' => 'Registered waste pickers (active), pilot target', 'actual' => $pickers, 'target' => (int) ($s['target.pilot_pickers'] ?? 0)],
            ['key' => 'target.hubs', 'label' => 'Active collection hubs', 'actual' => $hubs, 'target' => (int) ($s['target.hubs'] ?? 0)],
            ['key' => 'target.recyclers', 'label' => 'Approved recycling companies', 'actual' => $recyclers, 'target' => (int) ($s['target.recyclers'] ?? 0)],
            ['key' => 'target.kg_12_months', 'label' => 'Plastic collected, last 12 months (kg)', 'actual' => $kgYear, 'target' => (int) ($s['target.kg_12_months'] ?? 0)],
            ['key' => 'target.kg_12_months', 'label' => 'Plastic sold and delivered, last 12 months (kg)', 'actual' => $soldYear, 'target' => (int) ($s['target.kg_12_months'] ?? 0)],
        ];
        foreach ($rows as &$r) {
            $r['progress'] = $r['target'] > 0 ? min(100.0, $r['actual'] / $r['target'] * 100) : null;
        }
        return $rows;
    }

    /** Current value of each editable target. @return array<string,int> */
    public static function targetValues(): array
    {
        $s = Setting::all();
        $out = [];
        foreach (self::TARGETS as $k => $_) {
            $out[$k] = (int) ($s[$k] ?? 0);
        }
        return $out;
    }

    /** @return array{error:?string, changed?:bool} */
    public static function setTarget(string $key, string $raw, array $actor): array
    {
        if (!StockInput::can($actor, 'targets.manage')) {
            return ['error' => 'You do not have permission to change programme targets.'];
        }
        if (!isset(self::TARGETS[$key])) {
            return ['error' => 'Unknown target.'];
        }
        $raw = trim(str_replace(',', '', $raw));
        if (!preg_match('/^\d{1,9}$/', $raw) || (int) $raw < 1 || (int) $raw > self::TARGET_MAX) {
            return ['error' => self::TARGETS[$key] . ' must be a whole number between 1 and ' . number_format(self::TARGET_MAX) . '.'];
        }
        $new = (int) $raw;
        $old = (int) (Setting::get($key, 0));
        if ($new === $old) {
            return ['error' => null, 'changed' => false];
        }
        Database::transaction(function () use ($key, $new, $old, $actor): void {
            Database::connection()->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?')->execute([(string) $new, $key]);
            AuditLog::write('target.changed', (int) $actor['id'], 'setting', null, ['key' => $key, 'old' => $old, 'new' => $new]);
        });
        return ['error' => null, 'changed' => true];
    }

    /**
     * One row per calendar month (East Africa Time) from the month of $fromYmd to the month of $toYmd, measured from recorded data.
     * @return list<array<string,mixed>>
     */
    public static function monthly(string $fromYmd, string $toYmd): array
    {
        $first = (new \DateTimeImmutable($fromYmd))->modify('first day of this month');
        $last = (new \DateTimeImmutable($toYmd))->modify('first day of this month');
        if ($first > $last) {
            [$first, $last] = [$last, $first];
        }
        if ($first->diff($last)->m + $first->diff($last)->y * 12 >= self::MAX_MONTHS) {
            $first = $last->modify('-' . (self::MAX_MONTHS - 1) . ' months');
        }
        $start = Dates::dayRangeUtc($first->format('Y-m-d'))[0];
        $end = Dates::dayRangeUtc($last->modify('last day of this month')->format('Y-m-d'))[1];
        $off = (new \DateTimeZone((string) config('app.timezone', 'Africa/Kampala')))->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $m = static fn(string $col) => "DATE_FORMAT(DATE_ADD($col, INTERVAL $off SECOND), '%Y-%m')";

        $by = static function (array $rows): array {
            $o = [];
            foreach ($rows as $r) {
                $o[$r['m']] = $r;
            }
            return $o;
        };
        $col = $by(self::rows("SELECT {$m('recorded_at')} AS m, COUNT(*) AS n, COALESCE(SUM(net_weight_kg), 0) AS kg, COALESCE(SUM(total_value_ugx), 0) AS val,
                COUNT(DISTINCT picker_id) AS pickers, COUNT(DISTINCT hub_id) AS hubs
             FROM collection_transactions WHERE status = 'approved' AND recorded_at >= ? AND recorded_at < ? GROUP BY m", [$start, $end]));
        $sold = $by(self::rows("SELECT {$m('delivered_at')} AS m, COALESCE(SUM(COALESCE(received_kg, qty_kg)), 0) AS kg FROM stock_dispatches
             WHERE status = 'delivered' AND delivered_at >= ? AND delivered_at < ? GROUP BY m", [$start, $end]));
        $paid = $by(self::rows("SELECT {$m('processed_at')} AS m, COALESCE(SUM(amount_ugx), 0) AS ugx FROM payments
             WHERE direction = 'out' AND status = 'successful' AND processed_at >= ? AND processed_at < ? GROUP BY m", [$start, $end]));
        $joined = $by(self::rows("SELECT {$m('u.created_at')} AS m, COUNT(*) AS n FROM pickers p JOIN users u ON u.id = p.user_id
             WHERE u.created_at >= ? AND u.created_at < ? GROUP BY m", [$start, $end]));

        $out = [];
        for ($d = $first; $d <= $last; $d = $d->modify('+1 month')) {
            $k = $d->format('Y-m');
            $out[] = [
                'm' => $k, 'label' => $d->format('M Y'),
                'kg' => (float) ($col[$k]['kg'] ?? 0), 'val' => (int) ($col[$k]['val'] ?? 0), 'collections' => (int) ($col[$k]['n'] ?? 0),
                'pickers' => (int) ($col[$k]['pickers'] ?? 0), 'hubs' => (int) ($col[$k]['hubs'] ?? 0), 'sold' => (float) ($sold[$k]['kg'] ?? 0),
                'paid' => (int) ($paid[$k]['ugx'] ?? 0), 'joined' => (int) ($joined[$k]['n'] ?? 0),
            ];
        }
        return $out;
    }
}
