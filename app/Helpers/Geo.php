<?php
declare(strict_types=1);

namespace App\Helpers;

/** Coordinate handling. PlastiLink only works inside Uganda, so anything outside it is almost certainly a typing mistake (e.g. swapped values). */
final class Geo
{
    public const LAT_MIN = -1.5;
    public const LAT_MAX = 4.3;
    public const LNG_MIN = 29.5;
    public const LNG_MAX = 35.1;

    /**
     * Validate a latitude/longitude pair typed by a person. Both empty = "no location".
     * @return array{0:?float, 1:?float, 2:?string} latitude, longitude, error message
     */
    public static function parse(?string $lat, ?string $lng): array
    {
        $lat = trim((string) $lat);
        $lng = trim((string) $lng);
        if ($lat === '' && $lng === '') {
            return [null, null, null];
        }
        if (($lat === '') !== ($lng === '')) {
            return [null, null, 'Enter both latitude and longitude, or leave both empty.'];
        }
        $dec = '/^-?\d{1,3}(\.\d{1,10})?$/';
        if (!preg_match($dec, $lat) || !preg_match($dec, $lng)) {
            return [null, null, 'Latitude and longitude must be decimal numbers, for example 2.7746 and 32.2990.'];
        }
        $la = (float) $lat;
        $lo = (float) $lng;
        if (!self::inUganda($la, $lo)) {
            $swapped = self::inUganda($lo, $la);
            return [null, null, 'These coordinates are outside Uganda' . ($swapped ? ' (latitude and longitude look swapped)' : '')
                . '. Latitude must be between ' . self::LAT_MIN . ' and ' . self::LAT_MAX . ', longitude between ' . self::LNG_MIN . ' and ' . self::LNG_MAX . '.'];
        }
        return [round($la, 6), round($lo, 6), null];
    }

    public static function inUganda(float $lat, float $lng): bool
    {
        return $lat >= self::LAT_MIN && $lat <= self::LAT_MAX && $lng >= self::LNG_MIN && $lng <= self::LNG_MAX;
    }
}
