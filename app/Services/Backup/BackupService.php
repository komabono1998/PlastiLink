<?php
declare(strict_types=1);

namespace App\Services\Backup;

use App\Core\Config;
use App\Core\Database;
use App\Services\Exports\ZipWriter;

/**
 * Backups: <dir>/plastilink-YYYYmmdd-HHMMSS.sql.gz[.enc], ...-files.zip[.enc] and ...manifest.json.
 * With app.backup.key set, files are encrypted (AES-256-GCM). The manifest holds only table counts, checksums and control totals: no personal data.
 * Whole files are handled in memory, which is fine for a programme of this size; for databases beyond a few hundred MB use the host's own backup tools too.
 */
final class BackupService
{
    private const MAGIC = 'PLBK1';

    public static function directory(): string
    {
        $d = (string) Config::get('app.backup.directory', '');
        $d = $d !== '' ? $d : BASE_PATH . '/storage/backups';
        if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) {
            throw new \RuntimeException('Cannot create the backup directory.');
        }
        return rtrim($d, '/\\');
    }

    private static function key(): ?string
    {
        $k = (string) Config::get('app.backup.key', '');
        if ($k === '') {
            return null;
        }
        if (strlen($k) < 32) {
            throw new \RuntimeException('app.backup.key must be at least 32 characters.');
        }
        return hash('sha256', $k, true);
    }

    public static function encrypt(string $plain, string $rawKey): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return self::MAGIC . $iv . $tag . $cipher;
    }

    public static function decrypt(string $blob, string $rawKey): string
    {
        if (!str_starts_with($blob, self::MAGIC) || strlen($blob) < strlen(self::MAGIC) + 28) {
            throw new \RuntimeException('Not an encrypted PlastiLink backup.');
        }
        $o = strlen(self::MAGIC);
        $plain = openssl_decrypt(substr($blob, $o + 28), 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, substr($blob, $o, 12), substr($blob, $o + 12, 16));
        if ($plain === false) {
            throw new \RuntimeException('Could not decrypt: wrong key, or the file was changed.');
        }
        return $plain;
    }

    /** @return array{set:string, files:list<string>, manifest:array} */
    public static function run(): array
    {
        $dir = self::directory();
        $set = 'plastilink-' . gmdate('Ymd-His');
        $key = self::key();

        $tmp = $dir . '/.' . $set . '.sql.gz.tmp';
        $manifest = SqlDumper::dump(Database::open(null === ($db = Config::get('database.database')) ? null : (string) $db, false), $tmp);
        $sql = $dir . '/' . $set . '.sql.gz' . ($key ? '.enc' : '');
        $key ? file_put_contents($sql, self::encrypt((string) file_get_contents($tmp), $key)) : rename($tmp, $sql);
        @unlink($tmp);

        $zip = new ZipWriter();
        $added = 0;
        foreach ([BASE_PATH . '/storage/receipts' => 'receipts', BASE_PATH . '/public/uploads' => 'uploads'] as $src => $prefix) {
            if (!is_dir($src)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getFilename() !== '.gitkeep' && $f->getSize() < 50 * 1024 * 1024) {
                    $zip->add($prefix . '/' . substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $src)) + 1), (string) file_get_contents($f->getPathname()));
                    $added++;
                }
            }
        }
        $zip->add('README.txt', "PlastiLink uploaded files at " . gmdate('c') . "\n");
        $zipPath = $dir . '/' . $set . '-files.zip' . ($key ? '.enc' : '');
        $zipBytes = $zip->build();
        file_put_contents($zipPath, $key ? self::encrypt($zipBytes, $key) : $zipBytes);

        $manifest += [
            'set' => $set, 'encrypted' => $key !== null, 'files_archived' => $added,
            'sql_file' => basename($sql), 'sql_sha256' => hash_file('sha256', $sql),
            'files_file' => basename($zipPath), 'files_sha256' => hash_file('sha256', $zipPath),
            'migrations' => (int) Database::connection()->query('SELECT COUNT(*) FROM migrations')->fetchColumn(),
        ];
        file_put_contents($dir . '/' . $set . '.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        @chmod($sql, 0640);
        @chmod($zipPath, 0640);
        return ['set' => $set, 'files' => [basename($sql), basename($zipPath), $set . '.manifest.json'], 'manifest' => $manifest];
    }

    /** @return list<string> backup set names, newest first */
    public static function sets(): array
    {
        $names = [];
        foreach (glob(self::directory() . '/plastilink-*.manifest.json') ?: [] as $f) {
            $names[] = basename($f, '.manifest.json');
        }
        rsort($names);
        return $names;
    }

    public static function manifest(string $set): array
    {
        $f = self::directory() . '/' . basename($set) . '.manifest.json';
        $m = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        if (!is_array($m)) {
            throw new \RuntimeException("No manifest for $set.");
        }
        return $m;
    }

    /** Plain gzip file path for a set (decrypting to a temp file when needed). Caller deletes it when $temp is true. @return array{0:string,1:bool} */
    public static function sqlFile(string $set): array
    {
        $m = self::manifest($set);
        $path = self::directory() . '/' . $m['sql_file'];
        if (!is_file($path) || hash_file('sha256', $path) !== $m['sql_sha256']) {
            throw new \RuntimeException('The database backup file is missing or does not match its checksum.');
        }
        if (!$m['encrypted']) {
            return [$path, false];
        }
        $key = self::key();
        if ($key === null) {
            throw new \RuntimeException('This backup is encrypted but app.backup.key is not set.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'plbk');
        file_put_contents($tmp, self::decrypt((string) file_get_contents($path), $key));
        return [$tmp, true];
    }

    /**
     * Prove a backup can be restored: checksum, then a real restore into a throw-away database, then table counts and control totals
     * compared with the manifest. If the database user may not create databases (typical on shared hosting) it falls back to a structural check.
     * @return array{ok:bool, mode:string, problems:list<string>}
     */
    public static function verify(string $set): array
    {
        $m = self::manifest($set);
        $problems = [];
        [$sqlPath, $temp] = self::sqlFile($set);
        try {
            $zipPath = self::directory() . '/' . $m['files_file'];
            if (!is_file($zipPath) || hash_file('sha256', $zipPath) !== $m['files_sha256']) {
                $problems[] = 'The files archive is missing or does not match its checksum.';
            }
            $scratch = 'plastilink_verify_' . bin2hex(random_bytes(4));
            $admin = Database::open(null);
            try {
                $admin->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (\PDOException) {
                return self::structural($m, $sqlPath, $problems);
            }
            try {
                $pdo = Database::open($scratch);
                SqlDumper::restore($pdo, $sqlPath);
                foreach ($m['tables'] as $t => $expected) {
                    $got = (int) $pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
                    if ($got !== (int) $expected) {
                        $problems[] = "Table $t: restored $got rows, expected $expected.";
                    }
                }
                if ((int) $pdo->query('SELECT COALESCE(SUM(amount_ugx), 0) FROM financial_ledger')->fetchColumn() !== (int) $m['ledger_sum']) {
                    $problems[] = 'The money ledger total does not match after restore.';
                }
                if (abs((float) $pdo->query('SELECT COALESCE(SUM(on_hand_kg), 0) FROM inventory')->fetchColumn() - (float) $m['stock_on_hand']) > 0.001) {
                    $problems[] = 'The stock total does not match after restore.';
                }
            } finally {
                $admin->exec("DROP DATABASE IF EXISTS `$scratch`");
            }
            return ['ok' => !$problems, 'mode' => 'full restore into a scratch database', 'problems' => $problems];
        } finally {
            if ($temp) {
                @unlink($sqlPath);
            }
        }
    }

    private static function structural(array $m, string $sqlPath, array $problems): array
    {
        $gz = gzopen($sqlPath, 'rb');
        $creates = [];
        $stmts = 0;
        $end = false;
        while (($line = gzgets($gz)) !== false) {
            $line = rtrim($line);
            if ($line === '-- END') {
                $end = true;
            } elseif ($line !== '' && !str_starts_with($line, '-- ')) {
                $stmts++;
                if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $mm)) {
                    $creates[] = $mm[1];
                }
            }
        }
        gzclose($gz);
        if (!$end) {
            $problems[] = 'The backup has no end marker (incomplete).';
        }
        foreach (array_keys($m['tables']) as $t) {
            if (!in_array($t, $creates, true)) {
                $problems[] = "Table $t is missing from the backup.";
            }
        }
        return ['ok' => !$problems, 'mode' => 'structural check only (this database user cannot create a scratch database)', 'problems' => $problems];
    }

    /** Keep the newest backup of each of the last N days, plus the newest of each of the last M weeks. @return list<string> deleted sets */
    public static function prune(?int $daily = null, ?int $weekly = null): array
    {
        $daily ??= (int) Config::get('app.backup.keep_daily', 14);
        $weekly ??= (int) Config::get('app.backup.keep_weekly', 8);
        $keep = [];
        $days = [];
        $weeks = [];
        foreach (self::sets() as $s) {            // newest first
            if (!preg_match('/^plastilink-(\d{8})-/', $s, $m)) {
                $keep[$s] = true;                 // never touch names we do not recognise
                continue;
            }
            $day = $m[1];
            $week = (new \DateTimeImmutable($day))->format('o-W');
            if (!isset($days[$day]) && count($days) < $daily) {
                $days[$day] = true;
                $keep[$s] = true;
            } elseif (!isset($days[$day]) && !isset($weeks[$week]) && count($weeks) < $weekly) {
                $weeks[$week] = true;
                $keep[$s] = true;
            }
            if (isset($days[$day]) && !isset($keep[$s])) {
                // a second backup on a kept day: drop it
            }
        }
        $deleted = [];
        foreach (self::sets() as $s) {
            if (isset($keep[$s])) {
                continue;
            }
            foreach (glob(self::directory() . '/' . $s . '*') ?: [] as $f) {
                @unlink($f);
            }
            $deleted[] = $s;
        }
        return $deleted;
    }

    /** Put receipts and uploads from a backup's files archive back (existing files with the same name are overwritten). @return int files restored */
    public static function restoreFiles(string $set): int
    {
        $m = self::manifest($set);
        $path = self::directory() . '/' . $m['files_file'];
        if (!is_file($path) || hash_file('sha256', $path) !== $m['files_sha256']) {
            throw new \RuntimeException('The files archive is missing or does not match its checksum.');
        }
        $bytes = (string) file_get_contents($path);
        if ($m['encrypted']) {
            $key = self::key();
            if ($key === null) {
                throw new \RuntimeException('This backup is encrypted but app.backup.key is not set.');
            }
            $bytes = self::decrypt($bytes, $key);
        }
        return ZipReader::extractTo(ZipReader::read($bytes), BASE_PATH . '/storage/receipts', BASE_PATH . '/public/uploads');
    }

    /** Overwrite the configured database with a backup. Destructive: the caller must have asked for confirmation. @return int statements run */
    public static function restoreInto(string $set): int
    {
        [$sqlPath, $temp] = self::sqlFile($set);
        try {
            return SqlDumper::restore(Database::open((string) Config::get('database.database')), $sqlPath);
        } finally {
            if ($temp) {
                @unlink($sqlPath);
            }
        }
    }
}
