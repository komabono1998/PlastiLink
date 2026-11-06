<?php
declare(strict_types=1);

namespace App\Services\Reports;

use App\Core\Database;

/**
 * A report: filters in, a structured result out. Result shape (raw values; formatting happens at display/export time):
 *   title, summary[ {label,value,type} ], tables[ {title, columns[{key,label,type}], rows[], totals?} ], chart?, notes[]
 * Column / summary types: text, int, kg, ugx, pct, hours, date, datetime.
 */
abstract class Report
{
    abstract public static function key(): string;

    abstract public static function title(): string;

    abstract public static function description(): string;

    /** Which filters this report understands: range, hub, neighbourhood, group, inactive_days. */
    abstract public static function filters(): array;

    /** Hub Managers may open this report (limited to their own hubs). All reports are open to Super Administrators and Project Managers. */
    public static function hubManagerAllowed(): bool
    {
        return false;
    }

    abstract public function run(array $f): array;

    /** Seconds the app time zone is ahead of UTC (Kampala: 10800). East Africa has no daylight saving. */
    protected static function offset(): int
    {
        return (new \DateTimeZone((string) config('app.timezone', 'Africa/Kampala')))->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /** SQL fragment limiting a hub column to the effective hubs (empty when unrestricted). */
    protected function hubIn(array $f, string $col): string
    {
        if ($f['hubs'] === null) {
            return '';
        }
        return $f['hubs'] ? " AND $col IN (" . implode(',', array_map('intval', $f['hubs'])) . ')' : ' AND 1 = 0';
    }

    /** @return array<int,array<string,mixed>> */
    protected function all(string $sql, array $args = []): array
    {
        $s = Database::connection()->prepare($sql);
        $s->execute($args);
        return $s->fetchAll();
    }

    protected function one(string $sql, array $args = []): mixed
    {
        $s = Database::connection()->prepare($sql);
        $s->execute($args);
        return $s->fetchColumn();
    }

    /** @param array<int,array<string,mixed>> $rows @param string[] $keys */
    protected function sum(array $rows, array $keys): array
    {
        $t = [];
        foreach ($keys as $k) {
            $t[$k] = array_sum(array_map(static fn($r) => (float) ($r[$k] ?? 0), $rows));
        }
        return $t;
    }

    protected static function col(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    protected static function stat(string $label, mixed $value, string $type): array
    {
        return ['label' => $label, 'value' => $value, 'type' => $type];
    }
}
