<?php
declare(strict_types=1);

namespace App\Services\Exports;

use App\Helpers\ReportFormat;

/**
 * Dependency-free PDF writer for tabular reports: A4 landscape, Helvetica, header and "Page x of y" footer on every page,
 * repeated table headers, right-aligned numbers, shaded header rows. Text only (no charts or images).
 */
final class PdfExporter
{
    public const MIME = 'application/pdf';

    private const W = 842.0;
    private const H = 595.0;
    private const MARGIN = 36.0;
    private const ROW_H = 13.0;
    private const BOTTOM = 44.0;

    /** Helvetica glyph widths (1/1000 em) for ASCII 32..126. */
    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /** @var string[] page content streams */
    private array $pages = [];
    private string $cur = '';
    private float $y = 0.0;
    private string $title = '';
    private string $metaLine = '';

    public static function build(array $result, array $meta): string
    {
        return (new self())->render($result, $meta);
    }

    private function render(array $result, array $meta): string
    {
        $this->title = $result['title'];
        $this->metaLine = trim(($meta['period'] ?? '') . (!empty($meta['filters']) ? '  |  ' . $meta['filters'] : '') . '  |  Generated ' . ($meta['generated'] ?? ''));
        $this->newPage();

        if (!empty($result['summary'])) {
            $this->heading('Summary');
            foreach ($result['summary'] as $s) {
                $this->need(self::ROW_H);
                $this->text(self::MARGIN, $this->y, $s['label'], 9, false);
                $this->text(self::MARGIN + 260, $this->y, ReportFormat::display($s['value'], $s['type']), 9, true);
                $this->y -= self::ROW_H;
            }
            $this->y -= 8;
        }
        foreach ($result['tables'] as $t) {
            $this->table($t);
        }
        foreach ($result['notes'] ?? [] as $n) {
            $this->need(self::ROW_H * 2);
            $this->text(self::MARGIN, $this->y, 'Note: ' . $n, 8, false);
            $this->y -= self::ROW_H;
        }
        $this->closePage();
        return $this->assemble();
    }

    private function table(array $t): void
    {
        $cols = $t['columns'];
        $rows = $t['rows'];
        $usable = self::W - 2 * self::MARGIN;

        // natural column widths (header + first 200 rows), then shrink to fit the page
        $nat = [];
        foreach ($cols as $i => $c) {
            $w = $this->width($c['label'], 8, true) + 10;
            foreach (array_slice($rows, 0, 200) as $r) {
                $w = max($w, $this->width(ReportFormat::display($r[$c['key']] ?? null, $c['type']), 8, false) + 10);
            }
            $nat[$i] = min($w, 220.0);
        }
        $sum = array_sum($nat);
        $scale = $sum > $usable ? $usable / $sum : 1.0;
        $widths = array_map(static fn($w) => max(28.0, $w * $scale), $nat);

        $this->heading($t['title']);
        $this->tableHeader($cols, $widths);
        $n = 0;
        foreach ($rows as $r) {
            if ($this->y < self::BOTTOM + self::ROW_H) {
                $this->closePage();
                $this->newPage();
                $this->tableHeader($cols, $widths);
            }
            $this->rowLine($cols, $widths, $r, false, $n++ % 2 === 1);
        }
        if (!empty($t['totals'])) {
            $this->need(self::ROW_H);
            $tot = ['__first' => 'Total'] + $t['totals'];
            $this->rowLine($cols, $widths, $tot, true, false, true);
        }
        if (!$rows) {
            $this->need(self::ROW_H);
            $this->text(self::MARGIN, $this->y, 'No data for this selection.', 8, false);
            $this->y -= self::ROW_H;
        }
        $this->y -= 10;
    }

    private function tableHeader(array $cols, array $widths): void
    {
        $this->need(self::ROW_H + 4);
        $x = self::MARGIN;
        $this->cur .= sprintf("0.875 0.953 0.918 rg %.2f %.2f %.2f %.2f re f 0 g\n", self::MARGIN, $this->y - 3.5, array_sum($widths), self::ROW_H + 2);
        foreach ($cols as $i => $c) {
            $this->cell($x, $widths[$i], $c['label'], ReportFormat::isNumeric($c['type']), true);
            $x += $widths[$i];
        }
        $this->y -= self::ROW_H + 2;
    }

    private function rowLine(array $cols, array $widths, array $row, bool $bold, bool $zebra, bool $total = false): void
    {
        if ($zebra) {
            $this->cur .= sprintf("0.97 0.98 0.97 rg %.2f %.2f %.2f %.2f re f 0 g\n", self::MARGIN, $this->y - 3, array_sum($widths), self::ROW_H);
        }
        $x = self::MARGIN;
        foreach ($cols as $i => $c) {
            $val = $total && $i === 0 ? 'Total' : ($total && !array_key_exists($c['key'], $row) ? null : ($row[$c['key']] ?? null));
            $text = $val === null ? ($total ? '' : '-') : ReportFormat::display($val, $c['type']);
            $this->cell($x, $widths[$i], $text === '—' ? '-' : $text, ReportFormat::isNumeric($c['type']), $bold || $total);
            $x += $widths[$i];
        }
        $this->y -= self::ROW_H;
    }

    private function cell(float $x, float $w, string $text, bool $right, bool $bold): void
    {
        $text = $this->fit($text, $w - 8, 8, $bold);
        $tx = $right ? $x + $w - 4 - $this->width($text, 8, $bold) : $x + 4;
        $this->text($tx, $this->y, $text, 8, $bold);
    }

    private function heading(string $s): void
    {
        $this->need(self::ROW_H * 3);
        $this->y -= 4;
        $this->text(self::MARGIN, $this->y, $s, 10, true);
        $this->y -= self::ROW_H + 2;
    }

    private function need(float $h): void
    {
        if ($this->y - $h < self::BOTTOM) {
            $this->closePage();
            $this->newPage();
        }
    }

    private function newPage(): void
    {
        $this->cur = '';
        $this->text(self::MARGIN, self::H - 40, 'PlastiLink', 14, true);
        $this->text(self::MARGIN + 82, self::H - 40, $this->title, 12, true);
        $this->cur .= "0.35 g\n";
        $this->text(self::MARGIN, self::H - 54, $this->fit($this->metaLine, self::W - 2 * self::MARGIN, 8, false), 8, false);
        $this->cur .= "0 g\n";
        $this->cur .= sprintf("0.03 0.42 0.32 RG 1 w %.2f %.2f m %.2f %.2f l S 0 G\n", self::MARGIN, self::H - 60, self::W - self::MARGIN, self::H - 60);
        $this->y = self::H - 76;
    }

    private function closePage(): void
    {
        $this->pages[] = $this->cur;
        $this->cur = '';
    }

    private function text(float $x, float $y, string $s, int $size, bool $bold): void
    {
        $this->cur .= sprintf("BT /%s %d Tf %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, $this->esc($s));
    }

    /** Text as WinAnsi bytes, safe inside a PDF string. */
    private function esc(string $s): string
    {
        $s = strtr($s, ['—' => '-', '–' => '-', '·' => '|', '×' => 'x', '→' => '->', '…' => '...', "\u{00A0}" => ' ', '’' => "'", '‘' => "'", '“' => '"', '”' => '"']);
        $s = (string) mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        $s = preg_replace('/[\x00-\x1F]/', ' ', $s) ?? '';
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private function width(string $s, int $size, bool $bold): float
    {
        $s = strtr($s, ['—' => '-', '–' => '-', '·' => '|', '×' => 'x', '→' => '->', '…' => '...']);
        $w = 0;
        foreach (mb_str_split($s) as $ch) {
            $o = ord($ch[0] ?? ' ');
            $w += ($o >= 32 && $o <= 126 && strlen($ch) === 1) ? self::WIDTHS[$o - 32] : 556;
        }
        return $w * $size / 1000 * ($bold ? 1.05 : 1.0);
    }

    private function fit(string $s, float $max, int $size, bool $bold): string
    {
        if ($this->width($s, $size, $bold) <= $max) {
            return $s;
        }
        while ($s !== '' && $this->width($s . '..', $size, $bold) > $max) {
            $s = mb_substr($s, 0, -1);
        }
        return $s . '..';
    }

    private function assemble(): string
    {
        $n = count($this->pages);
        $objects = [];
        $objects[1] = '<</Type/Catalog/Pages 2 0 R>>';
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (6 + 2 * $i) . ' 0 R';
        }
        $objects[2] = '<</Type/Pages/Kids[' . implode(' ', $kids) . ']/Count ' . $n . '>>';
        $objects[3] = '<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>';
        $objects[4] = '<</Type/Font/Subtype/Type1/BaseFont/Helvetica-Bold/Encoding/WinAnsiEncoding>>';
        $objects[5] = '<</Title(' . $this->esc($this->title) . ')/Creator(PlastiLink)/Producer(PlastiLink)>>';
        foreach ($this->pages as $i => $content) {
            $footer = sprintf("BT /F1 8 Tf 0.35 g %.2f 24 Td (PlastiLink report) Tj ET\nBT /F1 8 Tf %.2f 24 Td (Page %d of %d) Tj ET\n", self::MARGIN, self::W - self::MARGIN - 52, $i + 1, $n);
            $stream = $content . $footer;
            $pageNo = 6 + 2 * $i;
            $objects[$pageNo] = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 842 595]/Resources<</Font<</F1 3 0 R/F2 4 0 R>>>>/Contents ' . ($pageNo + 1) . ' 0 R>>';
            $objects[$pageNo + 1] = '<</Length ' . strlen($stream) . ">>\nstream\n" . $stream . "endstream";
        }

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objects);
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $count = max(array_keys($objects)) + 1;
        $out .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        return $out . "trailer\n<</Size $count/Root 1 0 R/Info 5 0 R>>\nstartxref\n$xref\n%%EOF\n";
    }
}
