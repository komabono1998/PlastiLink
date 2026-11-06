<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** Single shared PDO connection. Prepared statements only; exceptions on error. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('database');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'],
                (int) $c['port'],
                $c['database'],
                $c['charset']
            );
            self::$pdo = new PDO($dsn, (string) $c['username'], (string) $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$c['charset']} COLLATE {$c['collation']}, time_zone = '+00:00'",
            ]);
        }
        return self::$pdo;
    }

    /**
     * Run a callback inside a transaction; rolls back and rethrows on failure.
     * A database deadlock (SQLSTATE 40001) rolls everything back and the callback is retried a few times, because the
     * callbacks only touch the database and are safe to run again. Other errors are never retried.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();
        for ($attempt = 1; ; $attempt++) {
            $pdo->beginTransaction();
            try {
                $result = $fn($pdo);
                $pdo->commit();
                return $result;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $deadlock = $e instanceof \PDOException && ($e->getCode() === '40001' || str_contains($e->getMessage(), 'Deadlock'));
                if (!$deadlock || $attempt >= 4) {
                    throw $e;
                }
                usleep(random_int(20000, 90000));   // back off briefly so the other transaction can finish
            }
        }
    }

    /** Run inside the current transaction if there is one, otherwise open a new one. */
    public static function atomic(callable $fn): mixed
    {
        return self::connection()->inTransaction() ? $fn(self::connection()) : self::transaction($fn);
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
