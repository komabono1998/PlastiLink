<?php
declare(strict_types=1);

namespace App\Helpers;

final class Reference
{
    /** TRF-20261106-000123 (date in Africa/Kampala). */
    public static function make(string $prefix, int $id): string
    {
        return $prefix . '-' . str_replace('-', '', Dates::todayYmd()) . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
