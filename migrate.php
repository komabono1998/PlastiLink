<?php
declare(strict_types=1);

/**
 * PlastiLink migration runner (CLI only).
 *
 *   php migrate.php migrate         Run pending migrations
 *   php migrate.php status          Show applied / pending
 *   php migrate.php rollback [n]    Roll back the last n batches (default 1)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/app/bootstrap.php';

use App\Core\Database;

$pdo = Database::connection();
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS migrations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration   VARCHAR(190) NOT NULL,
    batch       INT UNSIGNED NOT NULL,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_migrations_name (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$dir = __DIR__ . '/database/migrations';
$files = glob($dir . '/*.php') ?: [];
sort($files);
$name = static fn(string $f): string => basename($f, '.php');
$applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

$command = $argv[1] ?? 'status';

switch ($command) {
    case 'migrate':
        $pending = array_filter($files, static fn($f) => !in_array($name($f), $applied, true));
        if (!$pending) {
            echo "Nothing to migrate.\n";
            break;
        }
        $batch = (int) $pdo->query('SELECT COALESCE(MAX(batch),0)+1 FROM migrations')->fetchColumn();
        foreach ($pending as $file) {
            $m = require $file;
            echo 'Migrating:  ' . $name($file) . "\n";
            $m['up']($pdo);
            $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)')->execute([$name($file), $batch]);
            echo 'Migrated:   ' . $name($file) . "\n";
        }
        break;

    case 'rollback':
        $steps = max(1, (int) ($argv[2] ?? 1));
        for ($i = 0; $i < $steps; $i++) {
            $batch = (int) $pdo->query('SELECT COALESCE(MAX(batch),0) FROM migrations')->fetchColumn();
            if ($batch === 0) {
                echo "Nothing to roll back.\n";
                break;
            }
            $stmt = $pdo->prepare('SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC');
            $stmt->execute([$batch]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $migration) {
                $m = require "$dir/$migration.php";
                echo "Rolling back: $migration\n";
                $m['down']($pdo);
                $pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$migration]);
                echo "Rolled back:  $migration\n";
            }
        }
        break;

    case 'status':
        foreach ($files as $f) {
            printf("[%s] %s\n", in_array($name($f), $applied, true) ? 'x' : ' ', $name($f));
        }
        if (!$files) {
            echo "No migration files.\n";
        }
        break;

    default:
        fwrite(STDERR, "Unknown command '$command'. Use: migrate | status | rollback [n]\n");
        exit(1);
}
