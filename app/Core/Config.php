<?php
declare(strict_types=1);

namespace App\Core;

/** Loads config/*.php, merging an optional config/<name>.local.php override. */
final class Config
{
    /** @var array<string, array<string, mixed>> */
    private static array $items = [];

    public static function load(string $dir): void
    {
        foreach (['app', 'database'] as $name) {
            $base = is_file("$dir/$name.php") ? require "$dir/$name.php" : [];
            $local = is_file("$dir/$name.local.php") ? require "$dir/$name.local.php" : [];
            self::$items[$name] = array_replace_recursive($base, $local);
        }
    }

    /** Dot notation: Config::get('app.session.name'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /** Test helper. */
    public static function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $ref = &self::$items;
        foreach ($parts as $p) {
            $ref = &$ref[$p];
        }
        $ref = $value;
    }
}
