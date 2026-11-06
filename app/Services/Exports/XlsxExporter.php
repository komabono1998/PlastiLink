<?php
declare(strict_types=1);

namespace App\Services\Exports;

use App\Helpers\ReportFormat;

/**
 * Real .xlsx (Office Open XML) workbook: a "Summary" sheet and one sheet per table. Numbers are numeric cells with number
 * formats (shillings #,##0 · kilograms #,##0.00 · percentages 0.0), headers are bold and frozen, text is stored as text.
 */
final class XlsxExporter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    // cellXfs indexes in styles.xml
    private const S_DEFAULT = 0, S_BOLD = 1, S_INT = 2, S_DEC = 3, S_PCT = 4, S_BOLD_INT = 5, S_BOLD_DEC = 6, S_BOLD_PCT = 7, S_TITLE = 8;

    public static function build(array $result, array $meta): string
    {
        $sheets = [];
        $sheets[] = ['name' => 'Summary', 'xml' => self::summarySheet($result, $meta), 'widths' => [34, 40]];
        $used = ['Summary' => true];
        foreach ($result['tables'] as $i => $t) {
            $name = self::sheetName($t['title'], $used, $i + 1);
            [$xml, $widths] = self::tableSheet($t, $result, $meta);
            $sheets[] = ['name' => $name, 'xml' => $xml, 'widths' => $widths];
        }

        $zip = new ZipWriter();
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($sheets as $i => $_) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->add('[Content_Types].xml', $ct . '</Types>');
        $zip->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheets as $i => $s) {
            $n = $i + 1;
            $wb .= '<sheet name="' . self::x($s['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $zip->add('xl/worksheets/sheet' . $n . '.xml', self::withCols($s['xml'], $s['widths']));
        }
        $rels .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->add('xl/workbook.xml', $wb . '</sheets></workbook>');
        $zip->add('xl/_rels/workbook.xml.rels', $rels);
        $zip->add('xl/styles.xml', self::styles());
        return $zip->build();
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0"/></numFmts>'
            . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF086B52"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDFF3EA"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="9">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="3" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="4" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs></styleSheet>';
    }

    private static function summarySheet(array $result, array $meta): string
    {
        $rows = [[['t', $result['title'], self::S_TITLE]], [['t', 'Period'], ['t', (string) ($meta['period'] ?? '')]]];
        if (!empty($meta['filters'])) {
            $rows[] = [['t', 'Filters'], ['t', (string) $meta['filters']]];
        }
        $rows[] = [['t', 'Generated'], ['t', (string) ($meta['generated'] ?? '')]];
        $rows[] = [];
        foreach ($result['summary'] ?? [] as $s) {
            $rows[] = [['t', $s['label'], self::S_BOLD], self::cell($s['value'], $s['type'])];
        }
        foreach ($result['notes'] ?? [] as $n) {
            $rows[] = [];
            $rows[] = [['t', 'Note: ' . $n]];
        }
        return self::sheetXml($rows, 0);
    }

    /** @return array{0:string,1:array<int,float>} */
    private static function tableSheet(array $t, array $result, array $meta): array
    {
        $rows = [[['t', $t['title'], self::S_TITLE]], [['t', $result['title'] . ' · ' . ($meta['period'] ?? '')]], []];
        $head = [];
        foreach ($t['columns'] as $c) {
            $head[] = ['t', $c['label'], self::S_BOLD];
        }
        $rows[] = $head;
        $widths = array_map(static fn($c) => max(10.0, mb_strlen($c['label']) + 3.0), $t['columns']);
        foreach ($t['rows'] as $r) {
            $line = [];
            foreach ($t['columns'] as $i => $c) {
                $line[] = self::cell($r[$c['key']] ?? null, $c['type']);
                $len = mb_strlen(ReportFormat::display($r[$c['key']] ?? null, $c['type'])) + 2;
                $widths[$i] = min(60.0, max($widths[$i], (float) $len));
            }
            $rows[] = $line;
        }
        if (!empty($t['totals'])) {
            $line = [];
            foreach ($t['columns'] as $i => $c) {
                $line[] = $i === 0 ? ['t', 'Total', self::S_BOLD] : (array_key_exists($c['key'], $t['totals']) ? self::cell($t['totals'][$c['key']], $c['type'], true) : []);
            }
            $rows[] = $line;
        }
        return [self::sheetXml($rows, 4), $widths];
    }

    /** @return array{0:string,1:string|float|int|null,2?:int} */
    private static function cell(mixed $v, string $type, bool $bold = false): array
    {
        if ($v === null || $v === '') {
            return [];
        }
        return match ($type) {
            'int', 'ugx' => ['n', (int) $v, $bold ? self::S_BOLD_INT : self::S_INT],
            'kg'         => ['n', round((float) $v, 2), $bold ? self::S_BOLD_DEC : self::S_DEC],
            'num'        => ['n', round((float) $v, 2), $bold ? self::S_BOLD_DEC : self::S_DEC],
            'pct', 'hours' => ['n', round((float) $v, 1), $bold ? self::S_BOLD_PCT : self::S_PCT],
            'datetime'   => ['t', format_datetime((string) $v, 'Y-m-d H:i'), self::S_DEFAULT],
            default      => ['t', (string) $v, self::S_DEFAULT],
        };
    }

    /** @param array<int,array<int,array>> $rows each cell: [] (empty) | ['t'|'n', value, styleIndex?] */
    private static function sheetXml(array $rows, int $freezeRows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($freezeRows > 0) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $freezeRows . '" topLeftCell="A' . ($freezeRows + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        $xml .= '<!--COLS--><sheetData>';
        foreach ($rows as $ri => $cells) {
            $xml .= '<row r="' . ($ri + 1) . '">';
            foreach ($cells as $ci => $c) {
                if (!$c) {
                    continue;
                }
                $ref = self::col($ci) . ($ri + 1);
                $style = $c[2] ?? self::S_DEFAULT;
                $xml .= $c[0] === 'n'
                    ? '<c r="' . $ref . '" s="' . $style . '"><v>' . $c[1] . '</v></c>'
                    : '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::x((string) $c[1]) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    }

    /** @param array<int,float> $widths */
    private static function withCols(string $xml, array $widths): string
    {
        $cols = '<cols>';
        foreach ($widths as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . round($w, 1) . '" customWidth="1"/>';
        }
        return str_replace('<!--COLS-->', $cols . '</cols>', $xml);
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function sheetName(string $title, array &$used, int $n): string
    {
        $name = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $title) ?? '') ?: "Sheet $n";
        $name = mb_substr($name, 0, 31);
        $base = $name;
        for ($k = 2; isset($used[mb_strtolower($name)]); $k++) {
            $name = mb_substr($base, 0, 28) . " $k";
        }
        $used[mb_strtolower($name)] = true;
        return $name;
    }

    /** XML-escape and strip characters XML forbids. */
    private static function x(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
