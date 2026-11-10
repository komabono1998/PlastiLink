<?php
declare(strict_types=1);

namespace App\Services\Backup;

use PDO;

/**
 * Pure-PHP database dump and restore (shared hosting often has no mysqldump). Output is a gzip file with ONE SQL statement per line,
 * so a restore can read it line by line without a SQL parser. The dump runs inside a consistent-snapshot transaction: every table and the
 * control totals in the manifest describe the same instant, even while the application keeps working.
 */
final class SqlDumper
{
    private const BATCH = 200;

    /**
     * @return array{tables:array<string,int>, statements:int, ledger_sum:int, stock_on_hand:string, created_at:string}
     */
    public static function dump(PDO $pdo, string $gzPath): array
    {
        $gz = gzopen($gzPath, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Cannot write the backup file.');
        }
        $stmts = 0;
        $write = function (string $line) use ($gz, &$stmts): void {
            gzwrite($gz, $line . "\n");
            $stmts++;
        };
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            gzwrite($gz, "-- PlastiLink backup, one statement per line\n");
            $write('SET NAMES utf8mb4');
            $write('SET FOREIGN_KEY_CHECKS = 0');
            $write('SET UNIQUE_CHECKS = 0');
            $write("SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            $counts = [];
            foreach ($tables as [$t]) {
                $t = (string) $t;
                $create = (string) $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`')->fetch(PDO::FETCH_NUM)[1];
                $write('DROP TABLE IF EXISTS `' . $t . '`');
                $write(str_replace(["\r", "\n"], ' ', $create));
                $counts[$t] = self::dumpRows($pdo, $t, $write);
            }
            $write('SET FOREIGN_KEY_CHECKS = 1');
            $manifest = [
                'tables' => $counts, 'statements' => $stmts + 1,
                'ledger_sum' => (int) self::scalar($pdo, 'SELECT COALESCE(SUM(amount_ugx), 0) FROM financial_ledger', $counts),
                'stock_on_hand' => (string) self::scalar($pdo, 'SELECT COALESCE(SUM(on_hand_kg), 0) FROM inventory', $counts),
                'created_at' => gmdate('c'),
            ];
            gzwrite($gz, "-- END\n");
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            gzclose($gz);
            @unlink($gzPath);
            throw $e;
        }
        gzclose($gz);
        return $manifest;
    }

    private static function scalar(PDO $pdo, string $sql, array $existing): mixed
    {
        preg_match('/FROM (\w+)/', $sql, $m);
        return isset($existing[$m[1] ?? '']) ? $pdo->query($sql)->fetchColumn() : 0;
    }

    private static function dumpRows(PDO $pdo, string $table, callable $write): int
    {
        $q = '`' . str_replace('`', '``', $table) . '`';
        $st = $pdo->query("SELECT * FROM $q");
        $cols = null;
        $blob = [];
        $batch = [];
        $n = 0;
        $flush = function () use (&$batch, $q, &$cols, $write): void {
            if ($batch) {
                $write("INSERT INTO $q ($cols) VALUES " . implode(',', $batch));
                $batch = [];
            }
        };
        while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
            if ($cols === null) {
                $names = [];
                for ($i = 0; $i < $st->columnCount(); $i++) {
                    $meta = $st->getColumnMeta($i);
                    $names[] = '`' . str_replace('`', '``', (string) $meta['name']) . '`';
                    $blob[$i] = in_array('blob', (array) ($meta['flags'] ?? []), true) && ($meta['native_type'] ?? '') !== 'VAR_STRING' && ($meta['charsetnr'] ?? 0) === 63;
                }
                $cols = implode(',', $names);
            }
            $vals = [];
            foreach ($row as $i => $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif ($blob[$i]) {
                    $vals[] = $v === '' ? "''" : '0x' . bin2hex((string) $v);
                } else {
                    $vals[] = str_replace(["\r", "\n", "\x1a"], ['\\r', '\\n', '\\Z'], $pdo->quote((string) $v));
                }
            }
            $batch[] = '(' . implode(',', $vals) . ')';
            $n++;
            if (count($batch) >= self::BATCH) {
                $flush();
            }
        }
        $flush();
        return $n;
    }

    /** Run a dump file against $pdo (which should point at an empty or disposable database). @return int statements executed */
    public static function restore(PDO $pdo, string $gzPath): int
    {
        $gz = gzopen($gzPath, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('Cannot read the backup file.');
        }
        $n = 0;
        $sawEnd = false;
        while (($line = gzgets($gz)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '' || str_starts_with($line, '-- ')) {
                $sawEnd = $sawEnd || $line === '-- END';
                continue;
            }
            $pdo->exec($line);
            $n++;
        }
        gzclose($gz);
        if (!$sawEnd) {
            throw new \RuntimeException('The backup file is incomplete (no end marker).');
        }
        return $n;
    }
}
