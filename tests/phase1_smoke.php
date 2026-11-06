<?php
declare(strict_types=1);

/**
 * Phase 1 smoke tests. Run:  php tests/phase1_smoke.php [base-url]
 * Default base URL: http://localhost/PlastiLink
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, ($ok || $detail === '') ? '' : "  -> $detail");
}

/** @return array{0:int,1:string,2:string} status, body, headers */
function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => implode("\r\n", $headers), 'content' => $body ?? '',
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $hdrs = $http_response_header ?? [];
    preg_match('#HTTP/\S+ (\d{3})#', $hdrs[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), (string) $resp, implode("\n", $hdrs)];
}

// --- Routes ---
[$s, $b] = http('GET', "$base/");
check('GET / returns 200', $s === 200, "status $s");
check('Landing shows tagline', str_contains($b, 'Turning Plastic Waste into Income.'));
check('Landing targets come from DB (30,000 kg)', str_contains($b, '30,000'));
check('Landing links local Bootstrap (no CDN)', str_contains($b, 'vendor/bootstrap/bootstrap.min.css') && !str_contains($b, 'cdn.jsdelivr'));

[$s, $b, $h] = http('GET', "$base/health");
$j = json_decode($b, true);
check('GET /health returns 200 JSON ok', $s === 200 && ($j['status'] ?? '') === 'ok' && ($j['database'] ?? '') === 'up', "status $s body $b");
check('Health leaks no credentials', !preg_match('/password|plastilink@|root/i', $b));

[$s, $b] = http('GET', "$base/does-not-exist");
check('Unknown route returns 404 page', $s === 404 && str_contains($b, 'Page not found'), "status $s");

[$s, $b, $h] = http('POST', "$base/health", ['Content-Type: application/x-www-form-urlencoded'], 'a=1');
check('POST without CSRF token is rejected (400)', $s === 400, "status $s");

[$s] = http('GET', "$base/styleguide");
check('GET /styleguide available in local env', $s === 200, "status $s");

[$s, , $h] = http('GET', "$base/");
check('Security headers present', stripos($h, 'X-Content-Type-Options: nosniff') !== false && stripos($h, 'X-Frame-Options') !== false);

// --- Static assets ---
[$s] = http('GET', "$base/assets/css/plastilink.css");
check('CSS asset served', $s === 200, "status $s");
[$s] = http('GET', "$base/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2");
check('Icon font served', $s === 200, "status $s");

// --- Protected paths ---
foreach (['config/database.local.php', 'config/app.php', 'app/bootstrap.php', 'storage/logs/', 'database/migrations/0001_create_system_settings_table.php', 'migrate.php', '.git/config', 'public/uploads/x.php'] as $p) {
    [$s, $b] = http('GET', "$base/$p");
    check("Blocked: /$p", in_array($s, [403, 404], true) && !str_contains($b, 'password'), "status $s");
}

// --- Unit checks (in-process) ---
require dirname(__DIR__) . '/app/bootstrap.php';
check('Timezone is Africa/Kampala', date_default_timezone_get() === 'Africa/Kampala');
check('format_ugx', format_ugx(12500) === 'UGX 12,500');
check('format_kg', format_kg(12.5) === '12.5 kg' && format_kg(12) === '12 kg');
check('format_datetime UTC -> EAT (+3h)', format_datetime('2026-01-01 09:00:00', 'H:i') === '12:00');
check('e() escapes XSS', e('<script>"x"</script>') === '&lt;script&gt;&quot;x&quot;&lt;/script&gt;');
check('Config dot notation', config('app.currency') === 'UGX' && config('nope.x', 'd') === 'd');

$pdo = App\Core\Database::connection();
check('DB reachable via PDO with prepared statements', (int) $pdo->query('SELECT 1')->fetchColumn() === 1);
check('PDO emulated prepares disabled', $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false || $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === 0);
check('Migrations table tracks 0001', (int) $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration LIKE '0001%'")->fetchColumn() === 1);

$tx = false;
try {
    App\Core\Database::transaction(function (PDO $p): void {
        $p->exec("UPDATE system_settings SET setting_value='999' WHERE setting_key='target.hubs'");
        throw new RuntimeException('force rollback');
    });
} catch (RuntimeException) {
    $tx = (int) $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='target.hubs'")->fetchColumn() === 3;
}
check('Transaction helper rolls back on failure', $tx);

$router = new App\Core\Router();
$router->get('/items/{id}', fn($r, $id) => "item-$id");
ob_start();
$out = $router->dispatch(new App\Core\Request('GET', '/items/42', [], [], []));
ob_end_clean();
check('Router passes route parameters', $out === 'item-42');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
