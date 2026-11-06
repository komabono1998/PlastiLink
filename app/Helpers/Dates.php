<?php
declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;
use DateTimeZone;

/** Database values are UTC; users think in Africa/Kampala days. */
final class Dates
{
    /** @return array{0:string,1:string}|null [startUtc, endUtc) for a Y-m-d day in app timezone */
    public static function dayRangeUtc(string $ymd): ?array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return null;
        }
        $tz = new DateTimeZone((string) config('app.timezone', 'Africa/Kampala'));
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, $tz);
        if (!$d || $d->format('Y-m-d') !== $ymd) {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        return [$d->setTimezone($utc)->format('Y-m-d H:i:s'), $d->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    public static function todayYmd(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone((string) config('app.timezone', 'Africa/Kampala'))))->format('Y-m-d');
    }
}
