<?php
declare(strict_types=1);

/**
 * Performance / load test (not part of run_all: it is heavy).
 *   php tests/perf_load.php [rows=20000] [concurrency=8] [rounds=6] [base-url]
 *
 * Adds temporary rows (collections, ledger entries), signs in several separate sessions, fires pages at the app concurrently
 * (curl_multi; separate sessions so PHP session locking does not serialise them) and reports median / 95th percentile / slowest time per page.
 * Fails if a page's 95th percentile exceeds 3 seconds or any request errors. Local databases only. Removes its data afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/_http.php';

use App\Core\Database;
use App\Models\Hub;
use App\Models\Neighbourhood;
use App\Models\Picker;
use App\Models\Price;
use App\Models\User;
use App\Services\PickerService;

$rows = max(1000, (int) ($argv[1] ?? 20000));
$conc = max(1, min(30, (int) ($argv[2] ?? 8)));
$rounds = max(1, (int) ($argv[3] ?? 6));
$base = rtrim($argv[4] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase11Perf!pw';
$pass = 0;
$fail = 0;

$cleanup = function () use ($pdo): void {
    $ids = fn(string $sql): string => implode(',', array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    $hub = $ids("SELECT id FROM collection_hubs WHERE name LIKE 'PERFTEST%'");
    $usr = $ids("SELECT id FROM users WHERE email LIKE 'perftest.%' OR phone_e164 LIKE '+25670009%'");
    $col = $ids("SELECT id FROM collection_transactions WHERE hub_id IN ($hub)");
    $prc = $ids("SELECT id FROM price_lists WHERE hub_id IN ($hub) OR note LIKE 'PERFTEST%'");
    $nb = $ids("SELECT id FROM neighbourhoods WHERE name LIKE 'PERFTEST%'");
    $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($usr) OR (entity_type IN ('user', 'picker') AND entity_id IN ($usr)) OR (entity_type = 'hub' AND entity_id IN ($hub)) OR (entity_type = 'price' AND entity_id IN ($prc))");
    $pdo->exec("DELETE FROM financial_ledger WHERE source_type = 'collection' AND source_id IN ($col)");
    $pdo->exec("DELETE FROM stock_movements WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM inventory WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM collection_transactions WHERE id IN ($col)");
    $pdo->exec("DELETE FROM price_lists WHERE id IN ($prc)");
    $pdo->exec("DELETE FROM pickers WHERE user_id IN ($usr)");
    $pdo->exec("DELETE FROM collection_hubs WHERE id IN ($hub)");
    $pdo->exec("DELETE FROM neighbourhoods WHERE id IN ($nb)");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%perftest%'");
    $pdo->exec("DELETE FROM users WHERE id IN ($usr)");
    $pdo->exec('DELETE FROM sms_outbox WHERE user_id IS NULL');
};
$cleanup();

$sa = User::findById(User::create(['role' => 'super_admin', 'full_name' => 'Perf SA', 'email' => 'perftest.sa@example.test', 'password' => $PW, 'status' => 'active']));
$ho = User::create(['role' => 'hub_operator', 'full_name' => 'Perf HO', 'email' => 'perftest.ho@example.test', 'password' => $PW, 'status' => 'active']);
$hm = User::create(['role' => 'hub_manager', 'full_name' => 'Perf HM', 'email' => 'perftest.hm@example.test', 'password' => $PW, 'status' => 'active']);
$nb = Neighbourhood::create('PERFTEST Area');
$cat = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code = 'PET'")->fetchColumn();
$hub = Hub::create(['name' => 'PERFTEST Hub', 'neighbourhood_id' => $nb, 'address_note' => null, 'latitude' => 2.77, 'longitude' => 32.3, 'status' => 'active']);
Price::create($cat, $hub, 1000, 'PERFTEST', (int) $sa['id']);
$pickers = [];
for ($i = 0; $i < 40; $i++) {
    $r = PickerService::registerByStaff(['full_name' => "Perf Picker $i", 'phone' => '07000900' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'neighbourhood_id' => $nb, 'preferred_hub_id' => $hub, 'national_id' => "NINPERF$i", 'consent' => '1'], $sa);
    $pickers[] = (int) $r['id'];
}
$t0 = microtime(true);
$pdo->beginTransaction();
$ins = $pdo->prepare("INSERT INTO collection_transactions (reference, submission_token, picker_id, hub_id, neighbourhood_id, plastic_category_id, status, gross_weight_kg, tare_weight_kg, net_weight_kg,
    price_per_kg_ugx, total_value_ugx, recorded_by, recorded_at, decided_by, decided_at) VALUES (?, ?, ?, ?, ?, ?, ?, 10.00, 0.00, 10.00, 1000, 10000, ?, ?, ?, ?)");
$led = $pdo->prepare("INSERT INTO financial_ledger (account_type, account_id, entry_type, amount_ugx, source_type, source_id, reference, created_by, created_at) VALUES ('picker', ?, 'collection_earning', 10000, 'collection', ?, ?, ?, ?)");
for ($i = 0; $i < $rows; $i++) {
    $at = gmdate('Y-m-d H:i:s', time() - ($i % 700) * 86400 - ($i % 24) * 3600);
    $status = $i % 25 === 0 ? 'pending' : 'approved';
    $ref = 'PERF-' . $i . '-' . bin2hex(random_bytes(3));
    $p = $pickers[$i % count($pickers)];
    $ins->execute([$ref, bin2hex(random_bytes(16)), $p, $hub, $nb, $cat, $status, $ho, $at, $status === 'pending' ? null : $hm, $status === 'pending' ? null : $at]);
    if ($status === 'approved') {
        $led->execute([$p, (int) $pdo->lastInsertId(), $ref, $hm, $at]);
    }
}
$pdo->commit();
printf("Seeded %s collections (and their ledger entries) in %.1fs\n", number_format($rows), microtime(true) - $t0);

// separate sessions: one per concurrent worker
$clients = [];
for ($i = 0; $i < $conc; $i++) {
    $c = newClient();
    $r = login($c, 'perftest.sa@example.test', $PW);
    if ($r['status'] !== 303 || loc($r) === '/login') {
        fwrite(STDERR, "Could not sign in a test session.\n");
        $cleanup();
        exit(1);
    }
    $clients[] = $c;
}
$from = date('Y-m-d', strtotime('-720 days'));
$pages = [
    'dashboard' => '/admin', 'collections list' => '/collections', 'collections filter' => '/collections?status=approved&page=3', 'pickers list' => '/pickers', 'users list' => '/users',
    'inventory' => '/inventory', 'movements' => '/inventory/movements', 'finance' => '/finance', 'payments' => '/payments', 'audit trail' => '/admin/audit',
    'report collections' => "/reports/collections?from=$from&group=month", 'report pickers' => "/reports/pickers?from=$from", 'report hubs' => "/reports/hubs?from=$from", 'report financial' => "/reports/financial?from=$from",
    'report impact' => "/reports/impact?from=$from", 'map' => "/monitoring/map?from=$from", 'programme' => '/monitoring/programme?months=24',
    'export csv' => "/reports/collections/export/csv?from=$from", 'export xlsx' => "/reports/collections/export/xlsx?from=$from", 'export pdf' => "/reports/collections/export/pdf?from=$from",
];
$times = array_fill_keys(array_keys($pages), []);
$errors = [];
$queue = [];
for ($r = 0; $r < $rounds; $r++) {
    foreach ($pages as $name => $path) {
        $queue[] = [$name, $path];
    }
}
shuffle($queue);
$wall = microtime(true);
$mh = curl_multi_init();
$active = [];
$next = 0;
$spawn = function (int $slot) use (&$queue, &$next, &$active, $mh, $clients, $base): void {
    if ($next >= count($queue)) {
        return;
    }
    [$name, $path] = $queue[$next++];
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => $clients[$slot]['jar'], CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '']);
    curl_multi_add_handle($mh, $ch);
    $active[(int) $ch] = [$ch, $slot, $name, $path];
};
for ($s = 0; $s < $conc; $s++) {
    $spawn($s);
}
do {
    curl_multi_exec($mh, $running);
    while (($info = curl_multi_info_read($mh)) !== false) {
        [$ch, $slot, $name, $path] = $active[(int) $info['handle']];
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $times[$name][] = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        if ($code !== 200) {
            $errors[] = "$path -> $code";
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($active[(int) $info['handle']]);
        $spawn($slot);
    }
    if ($running) {
        curl_multi_select($mh, 0.2);
    }
} while ($running || $active);
curl_multi_close($mh);
$wall = microtime(true) - $wall;

$pct = function (array $a, float $p): float {
    sort($a);
    return $a ? $a[(int) min(count($a) - 1, floor($p * count($a)))] : 0.0;
};
printf("\n%-22s %5s %8s %8s %8s\n", 'page', 'n', 'median', 'p95', 'max');
$slow = [];
foreach ($times as $name => $t) {
    $p95 = $pct($t, 0.95);
    printf("%-22s %5d %7.0fms %7.0fms %7.0fms%s\n", $name, count($t), $pct($t, 0.5) * 1000, $p95 * 1000, ($t ? max($t) : 0) * 1000, $p95 > 3 ? '   <-- SLOW' : '');
    if ($p95 > 3) {
        $slow[] = $name;
    }
}
printf("\n%d requests, %d concurrent sessions, %.1fs wall time, %.1f requests/second\n\n", count($queue), $conc, $wall, count($queue) / max(0.001, $wall));
check('No request failed (all answered 200)', !$errors, implode('; ', array_slice($errors, 0, 5)));
check('Every page has a 95th percentile under 3 seconds with ' . number_format($rows) . ' collections and ' . $conc . ' users at once', !$slow, implode(', ', $slow));
$cleanup();
check('Test data removed', (int) $pdo->query("SELECT COUNT(*) FROM collection_hubs WHERE name LIKE 'PERFTEST%'")->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
