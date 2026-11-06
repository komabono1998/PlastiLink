<?php
declare(strict_types=1);

namespace App\Services\Exports;

use App\Helpers\ReportFormat;

/**
 * CSV: UTF-8 with a byte-order mark (so Excel reads accents and shillings correctly). Every table of the report is written
 * one after another, each preceded by its title and followed by a blank line. Text that starts with = + - @ is neutralised.
 */
final class CsvExporter
{
    public const MIME = 'text/csv; charset=utf-8';

    /** @param resource $out */
    public static function write($out, array $result, array $meta): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        self::line($out, [$result['title']]);
        self::line($out, ['Period', $meta['period'] ?? '']);
        if (!empty($meta['filters'])) {
            self::line($out, ['Filters', $meta['filters']]);
        }
        self::line($out, ['Generated', $meta['generated'] ?? '']);
        self::line($out, []);
        if (!empty($result['summary'])) {
            self::line($out, ['Summary']);
            foreach ($result['summary'] as $s) {
                self::line($out, [$s['label'], ReportFormat::csv($s['value'], $s['type'])], [1 => $s['type']]);
            }
            self::line($out, []);
        }
        foreach ($result['tables'] as $t) {
            self::line($out, [$t['title']]);
            self::line($out, array_column($t['columns'], 'label'));
            $types = array_column($t['columns'], 'type');
            foreach ($t['rows'] as $row) {
                $cells = [];
                foreach ($t['columns'] as $c) {
                    $cells[] = ReportFormat::csv($row[$c['key']] ?? null, $c['type']);
                }
                self::line($out, $cells, $types);
            }
            if (!empty($t['totals'])) {
                $cells = [];
                foreach ($t['columns'] as $i => $c) {
                    $cells[] = $i === 0 ? 'Total' : (array_key_exists($c['key'], $t['totals']) ? ReportFormat::csv($t['totals'][$c['key']], $c['type']) : '');
                }
                self::line($out, $cells, $types);
            }
            self::line($out, []);
        }
        foreach ($result['notes'] ?? [] as $n) {
            self::line($out, ['Note', $n]);
        }
    }

    public static function build(array $result, array $meta): string
    {
        $h = fopen('php://memory', 'w+b');
        self::write($h, $result, $meta);
        rewind($h);
        $s = (string) stream_get_contents($h);
        fclose($h);
        return $s;
    }

    /**
     * @param resource $out
     * @param array<int,string> $cells
     * @param array<int,string> $types column types (numeric cells are written as-is; text is made formula-safe)
     */
    private static function line($out, array $cells, array $types = []): void
    {
        $cells = array_map(static fn($c) => (string) $c, $cells);
        foreach ($cells as $i => $c) {
            if (!ReportFormat::isNumeric($types[$i] ?? 'text')) {
                $cells[$i] = ReportFormat::safeText($c);
            }
        }
        fputcsv($out, $cells, ',', '"', '\\', "\r\n");
    }
}
