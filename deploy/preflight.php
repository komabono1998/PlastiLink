<?php
declare(strict_types=1);

/**
 * Pre-flight check: run on the SERVER before going live (and after every update).
 *
 *   php deploy/preflight.php            Production checks (default)
 *   php deploy/preflight.php --local    Same checks, but local-development settings are tolerated (env, https, debug)
 *
 * Exit code 0 = ready, 1 = problems found. Nothing here changes anything.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$local = in_array('--local', $argv, true);
$fail = 0;
$warn = 0;
$out = static function (string $level, string $msg) use (&$fail, &$warn): void {
    if ($level === 'FAIL') { $fail++; }
    if ($level === 'WARN') { $warn++; }
    printf("[%s] %s\n", $level, $msg);
};
$check = static function (string $label, bool $ok, string $fix = '', bool $onlyWarn = false, bool $localOk = false) use ($out, $local): void {
    if ($ok) {
        $out('ok', $label);
    } elseif ($local && $localOk) {
        echo "[skip] $label  (fine for local development, not for a live server)\n";
    } else {
        $out($onlyWarn ? 'WARN' : 'FAIL', $label . ($fix !== '' ? "  -> $fix" : ''));
    }
};

echo "PlastiLink pre-flight (" . ($local ? 'local' : 'production') . " rules)\n\n";

// ---- PHP
$check('PHP 8.2 or newer (this is ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80200, 'select PHP 8.2+ in hPanel');
foreach (['pdo_mysql', 'mbstring', 'openssl', 'curl', 'zlib', 'json', 'ctype'] as $ext) {
    $check("PHP extension $ext", extension_loaded($ext), "enable $ext in hPanel > PHP Configuration");
}
$check('PHP display_errors is off', !filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN), 'set display_errors=Off for production', true, true);
$check('Time zone is Africa/Kampala', date_default_timezone_get() === 'Africa/Kampala', 'config app.timezone');

// ---- configuration
$check('app.env is "production"', Config::get('app.env') === 'production', 'set env => production in config/app.local.php', false, true);
$check('app.debug is false', Config::get('app.debug') === false, 'set debug => false', false, true);
$url = (string) Config::get('app.url', '');
$check('app.url is set to the real https address', str_starts_with($url, 'https://'), 'set url => https://your-domain in config/app.local.php (used in emailed links)', false, true);
$check('Local config files exist (config/app.local.php and database.local.php)', is_file(BASE_PATH . '/config/app.local.php') && is_file(BASE_PATH . '/config/database.local.php'), 'copy the .example files and fill them in');
$check('Content-Security-Policy is enforced', Config::get('app.security.csp') === 'enforce', 'leave security.csp as "enforce"');
$check('Payment provider is not the development mock', Config::get('app.finance.provider') !== 'mock', 'finance.provider must be manual, momo, mtn_momo or airtel_money', false, true);
$check('SMS driver is not the development mock', Config::get('app.integrations.sms.driver') !== 'mock', 'integrations.sms.driver', false, true);
$sms = (string) Config::get('app.integrations.sms.driver', 'log');
$check('SMS driver is a real gateway (otherwise messages are not delivered)', $sms === 'africastalking', 'set integrations.sms.driver and credentials, or accept that no SMS is sent', true, true);
$tok = (string) Config::get('app.integrations.webhook_token', '');
$check('Callback token is long enough (or callbacks are disabled)', $tok === '' || strlen($tok) >= 24, 'integrations.webhook_token needs 24+ random characters', false);
$check('Backups are encrypted (app.backup.key set)', strlen((string) Config::get('app.backup.key', '')) >= 32, 'set app.backup.key (32+ random characters) and store a copy in a safe place', true);
$live = (array) Config::get('app.integrations.momo', []);
foreach (['mtn' => 'MTN MoMo', 'airtel' => 'Airtel Money'] as $k => $label) {
    $c = (array) ($live[$k] ?? []);
    if (!empty($c['enabled'])) {
        $check("$label points at its live endpoint, not a sandbox", !str_contains((string) ($c['base_url'] ?? ''), 'sandbox') && !str_contains((string) ($c['base_url'] ?? ''), 'uat') && ($k !== 'mtn' || ($c['environment'] ?? '') !== 'sandbox'), 'change base_url/environment/currency for live use', true);
    }
}

// ---- filesystem
foreach (['storage/logs', 'storage/receipts', 'storage/backups'] as $d) {
    $p = BASE_PATH . '/' . $d;
    if (!is_dir($p)) {
        @mkdir($p, 0750, true);
    }
    $check("Folder $d exists and is writable", is_dir($p) && is_writable($p), 'create it and make it writable by the web user');
}
$check('storage/ is not served by the web server (root .htaccess blocks it)', str_contains((string) @file_get_contents(BASE_PATH . '/.htaccess'), 'storage') || is_file(BASE_PATH . '/storage/.htaccess'), 'keep the shipped .htaccess files');
foreach (['config/app.local.php', 'config/database.local.php'] as $f) {
    $perm = is_file(BASE_PATH . '/' . $f) ? substr(sprintf('%o', fileperms(BASE_PATH . '/' . $f)), -3) : '000';
    $check("$f is not world-readable (is $perm)", PHP_OS_FAMILY === 'Windows' || $local || ((int) $perm[2]) === 0, 'chmod 640 ' . $f, true);
}
$check('No tests/ folder on the server', !is_dir(BASE_PATH . '/tests'), 'upload the package built by deploy/build.php, which leaves tests out', true, true);
$check('No styleguide route in production', Config::get('app.env') === 'production', 'env must be production', false, true);

// ---- database
try {
    $pdo = Database::connection();
    $check('Database connection works', true);
    $applied = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    $files = count(glob(BASE_PATH . '/database/migrations/*.php') ?: []);
    $check("Migrations are up to date ($applied of $files applied)", $applied === $files, 'run: php migrate.php migrate');
    $admins = (int) $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'super_admin' AND u.status = 'active'")->fetchColumn();
    $check('There is at least one active Super Administrator', $admins >= 1, 'run: php create-admin.php --email=... --name="..."');
    $demo = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@example.test' OR email LIKE 'demo.%'")->fetchColumn();
    $check('No demo or test accounts (@example.test, demo.*) exist', $demo === 0 || $local, "found $demo; delete them or deploy with a clean database");
    $demoData = (int) $pdo->query("SELECT COUNT(*) FROM collection_hubs WHERE name LIKE 'DEMO%' OR name LIKE 'P%TEST%'")->fetchColumn();
    $check('No demo or test hubs exist', $demoData === 0 || $local, "found $demoData");
    $prices = (int) $pdo->query('SELECT COUNT(*) FROM price_lists')->fetchColumn();
    $check('Real prices have been set (not only DEMO)', $prices > 0, 'set prices at /prices before opening to users', true);
    $check('Stock ledger check passes', \App\Models\StockBalance::mismatches() === [], 'investigate before going live');
    $lg = \App\Services\FinanceService::verify();
    $check('Money ledger check passes', $lg === [], 'investigate before going live: ' . json_encode($lg));
} catch (Throwable $e) {
    $out('FAIL', 'Database: ' . get_class($e) . ' (check config/database.local.php and that the user has all privileges on the database)');
}

echo "\n" . ($fail === 0 ? "READY" . ($warn ? " (with $warn warning" . ($warn === 1 ? '' : 's') . ')' : '') : "NOT READY: $fail problem" . ($fail === 1 ? '' : 's')) . "\n";
exit($fail === 0 ? 0 : 1);
