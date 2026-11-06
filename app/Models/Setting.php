<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Key/value system settings stored in the system_settings table. */
final class Setting
{
    /** @return array<string, int|bool|string|array|null> */
    public static function all(): array
    {
        $rows = Database::connection()
            ->query('SELECT setting_key, setting_value, value_type FROM system_settings')
            ->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[$r['setting_key']] = self::cast($r['setting_value'], $r['value_type']);
        }
        return $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $stmt = Database::connection()->prepare(
            'SELECT setting_value, value_type FROM system_settings WHERE setting_key = :k LIMIT 1'
        );
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row ? self::cast($row['setting_value'], $row['value_type']) : $default;
    }

    private static function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'int'   => (int) $value,
            'bool'  => $value === '1' || $value === 'true',
            'json'  => $value === null ? null : json_decode($value, true),
            default => $value,
        };
    }
}
