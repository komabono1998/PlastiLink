<?php
declare(strict_types=1);

namespace App\Helpers;

/** Ugandan phone number handling (E.164). */
final class Phone
{
    /** Accepts 07XXXXXXXX, 7XXXXXXXX, 2567XXXXXXXX, +2567XXXXXXXX. Returns +256XXXXXXXXX or null. */
    public static function normalize(string $input): ?string
    {
        $n = preg_replace('/[\s\-().]/', '', trim($input)) ?? '';
        if (str_starts_with($n, '+256')) {
            // already international
        } elseif (str_starts_with($n, '256')) {
            $n = '+' . $n;
        } elseif (str_starts_with($n, '0')) {
            $n = '+256' . substr($n, 1);
        } elseif (preg_match('/^[37]\d{8}$/', $n)) {
            $n = '+256' . $n;
        }
        return preg_match('/^\+256[37]\d{8}$/', $n) ? $n : null;
    }

    /** +256 77* ***123 style masking for lists. */
    public static function mask(?string $e164): string
    {
        if (!$e164 || !preg_match('/^\+256(\d{2})\d{4}(\d{3})$/', $e164, $m)) {
            return '—';
        }
        return '+256 ' . $m[1] . '* ***' . $m[2];
    }
}
