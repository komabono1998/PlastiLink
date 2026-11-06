<?php
declare(strict_types=1);

namespace App\Helpers;

/** Whole-shilling money parsing. No floats anywhere. */
final class Money
{
    /** "12,500", "12500", "UGX 12 500" -> 12500; null if not a plain whole number of shillings. */
    public static function parseUgx(string $input): ?int
    {
        $s = preg_replace('/(?i)\bugx\b|[\s,]/', '', trim($input)) ?? '';
        return preg_match('/^\d{1,12}$/', $s) ? (int) $s : null;
    }

    /** Signed UGX for display: +12,500 / -12,500. */
    public static function signed(int $ugx): string
    {
        return ($ugx > 0 ? '+' : ($ugx < 0 ? '-' : '')) . 'UGX ' . number_format(abs($ugx));
    }
}
