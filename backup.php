<?php
declare(strict_types=1);

/**
 * PlastiLink backups (CLI only). Run from cron / Task Scheduler.
 *
 *   php backup.php run                    Back up the database and uploaded files, then prune old backups
 *   php backup.php list                   Show the backups on this machine
 *   php backup.php verify [set]           Prove a backup restores (default: the newest). Uses a scratch database when allowed
 *   php backup.php prune                  Apply the retention rules (app.backup.keep_daily / keep_weekly)
 *   php backup.php restore <set> --yes-overwrite=<database name>
 *                                         DESTRUCTIVE: replace the live database with the backup. Take a fresh backup first.
 *
 * Backups go to storage/backups (not web-accessible) unless app.backup.directory is set. Copy them OFF the server regularly.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/app/bootstrap.php';

use App\Services\Backup\BackupService;

$cmd = $argv[1] ?? 'run';
try {
    switch ($cmd) {
        case 'run':
            $r = BackupService::run();
            $tables = count($r['manifest']['tables']);
            $rows = array_sum($r['manifest']['tables']);
            echo "Backup {$r['set']} written ($tables tables, $rows rows" . ($r['manifest']['encrypted'] ? ', encrypted' : ', NOT encrypted: set app.backup.key') . ").\n";
            $v = BackupService::verify($r['set']);
            echo ($v['ok'] ? 'Verified: ' : 'VERIFY FAILED: ') . $v['mode'] . "\n";
            foreach ($v['problems'] as $p) {
                echo "  - $p\n";
            }
            $gone = BackupService::prune();
            echo $gone ? 'Pruned ' . count($gone) . " old backup(s).\n" : '';
            exit($v['ok'] ? 0 : 2);

        case 'list':
            foreach (BackupService::sets() as $s) {
                $m = BackupService::manifest($s);
                printf("%s  %d tables  %s  %s\n", $s, count($m['tables']), $m['encrypted'] ? 'encrypted' : 'plain', $m['created_at']);
            }
            break;

        case 'verify':
            $set = $argv[2] ?? (BackupService::sets()[0] ?? null);
            if ($set === null) {
                fwrite(STDERR, "No backups found.\n");
                exit(1);
            }
            $v = BackupService::verify($set);
            echo ($v['ok'] ? "OK" : "FAILED") . " ($set): {$v['mode']}\n";
            foreach ($v['problems'] as $p) {
                echo "  - $p\n";
            }
            exit($v['ok'] ? 0 : 2);

        case 'prune':
            echo 'Pruned ' . count(BackupService::prune()) . " backup(s).\n";
            break;

        case 'restore':
            $set = $argv[2] ?? '';
            $want = '';
            foreach ($argv as $a) {
                if (str_starts_with($a, '--yes-overwrite=')) {
                    $want = substr($a, 16);
                }
            }
            if ($set === '' || $want !== (string) config('database.database')) {
                fwrite(STDERR, "Refusing: pass a backup set and --yes-overwrite=" . config('database.database') . " to confirm that the live database will be replaced.\n");
                exit(1);
            }
            $v = BackupService::verify($set);
            if (!$v['ok']) {
                fwrite(STDERR, "Refusing: the backup does not verify.\n");
                exit(2);
            }
            echo 'Restored ' . BackupService::restoreInto($set) . " statements into the live database.\n";
            echo 'Restored ' . BackupService::restoreFiles($set) . " uploaded file(s).\n";
            break;

        default:
            fwrite(STDERR, "Usage: php backup.php run | list | verify [set] | prune | restore <set> --yes-overwrite=<db>\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup error: ' . $e->getMessage() . "\n");
    exit(1);
}
