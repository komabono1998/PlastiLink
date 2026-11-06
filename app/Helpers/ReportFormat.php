<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Column types used by reports: text, int, kg, ugx, pct, hours, date, datetime.
 * Reports return RAW values; these helpers turn them into display text (screen, PDF) or typed export values (CSV, Excel).
 */
final class ReportFormat
{
    /** Human text for screens and PDF. */
    public static function display(mixed $v, string $type): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        return match ($type) {
            'int'      => number_format((int) $v),
            'kg'       => format_kg($v),
            'num'      => rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.'),
            'ugx'      => format_ugx((int) $v),
            'pct'      => number_format((float) $v, 1) . '%',
            'hours'    => number_format((float) $v, 1) . ' h',
            'date'     => date('d M Y', strtotime((string) $v)),
            'datetime' => format_datetime((string) $v),
            default    => (string) $v,
        };
    }

    /** Value for CSV: plain numbers (no thousands separators), dates as ISO, EAT for datetimes. */
    public static function csv(mixed $v, string $type): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        return match ($type) {
            'int', 'ugx' => (string) (int) $v,
            'kg'         => number_format((float) $v, 2, '.', ''),
            'num'        => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'),
            'pct', 'hours' => number_format((float) $v, 1, '.', ''),
            'datetime'   => format_datetime((string) $v, 'Y-m-d H:i'),
            default      => (string) $v,
        };
    }

    public static function isNumeric(string $type): bool
    {
        return in_array($type, ['int', 'kg', 'num', 'ugx', 'pct', 'hours'], true);
    }

    /** Stop spreadsheets treating text as a formula (CSV injection). */
    public static function safeText(string $s): string
    {
        return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
    }
}
