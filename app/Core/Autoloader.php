<?php
declare(strict_types=1);

namespace App\Core;

/** Minimal PSR-4 autoloader: App\Foo\Bar => app/Foo/Bar.php (no Composer needed). */
final class Autoloader
{
    public static function register(string $appDir): void
    {
        spl_autoload_register(static function (string $class) use ($appDir): void {
            $prefix = 'App\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $file = $appDir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
