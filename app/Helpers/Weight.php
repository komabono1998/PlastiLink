<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Weight and money arithmetic in integers only (no floats):
 *  - weights are held as hundredths of a kilogram ("12.5" kg = 1250),
 *  - values are whole UGX, rounded half-up.
 */
final class Weight
{
    public const MAX_HUNDREDTHS = 500000;   // sanity cap: 5,000 kg per transaction

    /** "12.5", "12,5", "12" -> hundredths of a kg; null if not a plain non-negative number with up to 2 decimals. */
    public static function parse(string $input): ?int
    {
        $s = str_replace(',', '.', trim($input));
        if (!preg_match('/^(\d{1,5})(?:\.(\d{1,2}))?$/', $s, $m)) {
            return null;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** 1250 -> "12.50" (for DECIMAL columns). */
    public static function toDecimal(int $hundredths): string
    {
        return intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Signed hundredths -> "-12.50" / "12.50". */
    public static function toSignedDecimal(int $hundredths): string
    {
        return ($hundredths < 0 ? '-' : '') . self::toDecimal(abs($hundredths));
    }

    /** A DECIMAL column value ("12.50", "-3.00") -> signed hundredths. No floats. */
    public static function fromDecimal(string $decimal): int
    {
        $neg = str_starts_with($decimal, '-');
        [$i, $f] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, '0');
        $v = (int) $i * 100 + (int) str_pad(substr($f, 0, 2), 2, '0');
        return $neg ? -$v : $v;
    }

    /** Whole-UGX value = net kg x price per kg, rounded half-up. */
    public static function value(int $netHundredths, int $pricePerKg): int
    {
        return intdiv($netHundredths * $pricePerKg + 50, 100);
    }
}
