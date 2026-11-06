<?php
declare(strict_types=1);

/**
 * Phase 10 tests: coordinates, map data and hotspots, programme targets and monthly impact, scoping, privacy, exports.
 *   php tests/phase10_monitoring.php [base-url]
 * Creates temporary data (phase10test.*, "P10TEST" hubs and neighbourhoods) and removes it afterwards. Programme targets are restored.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/_http.php';

use App\Core\Database;
use App\Helpers\Geo;
use App\Models\Hub;
use App\Models\Neighbourhood;
use App\Models\Picker;
use App\Models\Price;
use App\Models\Recycler;
use App\Models\Setting;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\HubService;
use App\Services\MonitoringService;
use App\Services\PickerService;
use App\Services\RecyclerService;
use App\Services\ReportService;
use App\Services\Reports\GeographicReport;

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase10Test!pw';
$pass = 0;
$fail = 0;
$origTargets = MonitoringService::targetValues();

$cleanup = function () use ($pdo, $origTargets): void {
    $ids = fn(string $sql): string => implode(',', array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    $hub = $ids("SELECT id FROM collection_hubs WHERE name LIKE 'P10TEST%'");
    $usr = $ids("SELECT id FROM users WHERE email LIKE 'phase10test.%' OR phone_e164 LIKE '+25670006%'");
    $col = $ids("SELECT id FROM collection_transactions WHERE hub_id IN ($hub) OR recorded_by IN ($usr)");
    $prc = $ids("SELECT id FROM price_lists WHERE hub_id IN ($hub) OR note LIKE 'P10TEST%'");
    $rec = $ids("SELECT id FROM recyclers WHERE user_id IN ($usr)");
    $nb = $ids("SELECT id FROM neighbourhoods WHERE name LIKE 'P10TEST%'");
    $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($usr) OR (entity_type = 'collection' AND entity_id IN ($col)) OR (entity_type = 'price' AND entity_id IN ($prc))
                OR (entity_type = 'user' AND entity_id IN ($usr)) OR (entity_type = 'hub' AND entity_id IN ($hub)) OR (entity_type = 'neighbourhood' AND entity_id IN ($nb)) OR (entity_type = 'recycler' AND entity_id IN ($rec))");
    $pdo->exec("DELETE FROM financial_ledger WHERE source_type = 'collection' AND source_id IN ($col)");
    $pdo->exec("DELETE FROM stock_movements WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM inventory WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM receipts WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM weighing_records WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM collection_transactions WHERE id IN ($col)");
    $pdo->exec("DELETE FROM price_lists WHERE id IN ($prc)");
    $pdo->exec("DELETE FROM pickers WHERE user_id IN ($usr)");
    $pdo->exec("DELETE FROM recyclers WHERE id IN ($rec)");
    $pdo->exec("DELETE FROM collection_hubs WHERE id IN ($hub)");
    $pdo->exec("DELETE FROM neighbourhoods WHERE id IN ($nb)");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase10test%' OR identifier LIKE '%700060%'");
    $pdo->exec("DELETE FROM users WHERE id IN ($usr)");
    $pdo->exec('DELETE FROM sms_outbox WHERE user_id IS NULL');
    foreach ($origTargets as $k => $v) {   // programme targets are put back exactly as they were
        $pdo->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?')->execute([(string) $v, $k]);
    }
};
$cleanup();

// ---------------------------------------------------------------- coordinate validation
[$a, $b, $e] = Geo::parse('', '');
check('No coordinates at all is allowed (location not set)', $a === null && $b === null && $e === null);
check('Only one of the two is refused', Geo::parse('2.77', '')[2] !== null && Geo::parse('', '32.29')[2] !== null);
check('Valid Gulu coordinates are accepted and rounded to 6 decimals', Geo::parse('2.7746', '32.2990') === [2.7746, 32.299, null] && Geo::parse(' 2.7746123456 ', '32.1')[0] === 2.774612);
check('Uganda corners are accepted, just outside is refused', Geo::parse('-1.5', '29.5')[2] === null && Geo::parse('4.3', '35.1')[2] === null && Geo::parse('4.31', '32')[2] !== null && Geo::parse('2', '29.4')[2] !== null);
check('Coordinates outside Uganda are refused (London, 0/0)', Geo::parse('51.5', '-0.12')[2] !== null && Geo::parse('0', '0')[2] !== null);
check('Swapped latitude and longitude are refused with a hint', str_contains((string) Geo::parse('32.299', '2.7746')[2], 'swapped'));
check('Non-numeric, exponent, comma and injection-like input are refused', Geo::parse('abc', '32')[2] !== null && Geo::parse('1e1', '32')[2] !== null && Geo::parse('2,77', '32,2')[2] !== null && Geo::parse("2.7'; DROP TABLE hubs;--", '32')[2] !== null && Geo::parse('NaN', 'INF')[2] !== null);

// ---------------------------------------------------------------- setup
$ids = [];
foreach (['super_admin' => 'sa', 'project_manager' => 'pm', 'hub_manager' => 'hmA', 'hub_operator' => 'hoA'] as $role => $k) {
    $ids[$k] = User::create(['role' => $role, 'full_name' => "P10 $k", 'email' => "phase10test.$k@example.test", 'password' => $PW, 'status' => 'active']);
}
$ids['hmB'] = User::create(['role' => 'hub_manager', 'full_name' => 'P10 hmB', 'email' => 'phase10test.hmb@example.test', 'password' => $PW, 'status' => 'active']);
$nbA = Neighbourhood::create('P10TEST Area A');
$nbB = Neighbourhood::create('P10TEST Area B');
$catId = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code = 'PET'")->fetchColumn();
$mkHub = fn(string $n, int $nb) => Hub::create(['name' => $n, 'neighbourhood_id' => $nb, 'address_note' => null, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
$hubA = $mkHub('P10TEST Hub A', $nbA);
$hubB = $mkHub('P10TEST Hub B', $nbB);
Hub::assign($hubA, $ids['hmA'], 'manager'); Hub::assign($hubA, $ids['hoA'], 'operator');
Hub::assign($hubB, $ids['hmB'], 'manager');
foreach ([$hubA, $hubB] as $h) {
    Price::create($catId, $h, 1000, 'P10TEST price', $ids['sa']);
}
$U = function (string $k) use (&$ids): array { return User::findById($ids[$k]); };
$sa = $U('sa'); $pm = $U('pm'); $hmA = $U('hmA'); $hoA = $U('hoA'); $hmB = $U('hmB');
$mkPicker = function (string $key, string $name, string $phone, int $hub, int $nb) use ($sa, $PW, &$ids): int {
    $r = PickerService::registerByStaff(['full_name' => $name, 'phone' => $phone, 'neighbourhood_id' => $nb, 'preferred_hub_id' => $hub, 'national_id' => 'NIN' . substr($phone, -6) . 'ZZ', 'consent' => '1'], $sa);
    $p = Picker::find((int) $r['id']);
    User::updatePassword((int) $p['user_id'], $PW);
    $ids[$key] = (int) $p['user_id'];
    return (int) $r['id'];
};
$pkA = $mkPicker('pkA', 'P10 Picker A', '0700060101', $hubA, $nbA);
$pkB = $mkPicker('pkB', 'P10 Picker B', '0700060102', $hubB, $nbB);
$tok = fn() => bin2hex(random_bytes(16));
$mkCol = function (int $hub, int $picker, string $gross, string $rec, string $appr) use ($U, $catId, $tok): int {
    $r = CollectionService::record(['token' => $tok(), 'picker_id' => $picker, 'hub_id' => $hub, 'plastic_category_id' => $catId, 'gross_weight_kg' => $gross, 'tare_weight_kg' => ''], $U($rec));
    return CollectionService::approve((int) $r['id'], $U($appr))['error'] === null ? (int) $r['id'] : 0;
};
$ids['hoB'] = User::create(['role' => 'hub_operator', 'full_name' => 'P10 hoB', 'email' => 'phase10test.hob@example.test', 'password' => $PW, 'status' => 'active']);
Hub::assign($hubB, $ids['hoB'], 'operator');
$cA1 = $mkCol($hubA, $pkA, '20', 'hoA', 'hmA');
$cA2 = $mkCol($hubA, $pkA, '10', 'hoA', 'hmA');
$cB1 = $mkCol($hubB, $pkB, '15', 'hoB', 'hmB');
check('Setup: three collections approved in two neighbourhoods', $cA1 > 0 && $cA2 > 0 && $cB1 > 0);
$rr = RecyclerService::registerByStaff(['business_name' => 'P10TEST Recycling Ltd', 'contact_person' => 'P10 Rec', 'email' => 'phase10test.r1@example.test', 'phone' => '0700060201', 'address' => 'Gulu Industrial Area', 'consent' => '1'], $sa);
$recId = (int) $rr['id'];

$near = fn($x, $y) => abs((float) $x - (float) $y) < 0.005;
$run = fn(array $q, array $actor) => ReportService::filters($q, GeographicReport::class, $actor);
$byName = fn(array $rows, string $name) => (array_values(array_filter($rows, fn($r) => $r['name'] === $name))[0] ?? null);

// ---------------------------------------------------------------- locations missing: the page still works
$cl = [];
foreach (['sa', 'pm', 'hma', 'hoa', 'hmb'] as $k) {
    $cl[$k] = newClient();
    $r = login($cl[$k], "phase10test.$k@example.test", $PW);
    check("Setup: $k signs in", $r['status'] === 303 && loc($r) !== '/login');
}
$cl['pkA'] = newClient(); login($cl['pkA'], '0700060101', $PW);
$noLoc = req($cl['sa'], 'GET', '/monitoring/map?hub_id=' . $hubA);
check('Without coordinates the map page says so, and still lists hub and neighbourhood with "location not set"', $noLoc['status'] === 200 && str_contains($noLoc['body'], 'Nothing here has coordinates')
    && str_contains($noLoc['body'], 'P10TEST Hub A') && str_contains($noLoc['body'], 'location not set'));

// ---------------------------------------------------------------- coordinates saved through the forms and services
$bad = HubService::save($hubA, ['name' => 'P10TEST Hub A', 'neighbourhood_id' => $nbA, 'status' => 'active', 'latitude' => '51.5', 'longitude' => '-0.12'], $sa);
check('A hub cannot be saved with coordinates outside Uganda', isset($bad['errors']['latitude']) && $pdo->query("SELECT latitude FROM collection_hubs WHERE id = $hubA")->fetchColumn() === null);
$okHub = HubService::save($hubA, ['name' => 'P10TEST Hub A', 'neighbourhood_id' => $nbA, 'status' => 'active', 'latitude' => '2.7746', 'longitude' => '32.2990'], $sa);
HubService::save($hubB, ['name' => 'P10TEST Hub B', 'neighbourhood_id' => $nbB, 'status' => 'active', 'latitude' => '2.8000', 'longitude' => '32.3200'], $sa);
check('A hub with valid coordinates is saved', $okHub['errors'] === [] && (float) $pdo->query("SELECT latitude FROM collection_hubs WHERE id = $hubA")->fetchColumn() === 2.7746);

$post = fn(array $c, string $form, string $path, array $d) => postVia($c, $form, $path, $d);
$r = $post($cl['sa'], '/neighbourhoods', "/neighbourhoods/$nbA", ['action' => 'location', 'latitude' => '2.7700', 'longitude' => '32.2900']);
$row = $pdo->query("SELECT latitude, longitude FROM neighbourhoods WHERE id = $nbA")->fetch();
check('Neighbourhood location saves and is audited with the values', $r['status'] === 303 && (float) $row['latitude'] === 2.77 && (float) $row['longitude'] === 32.29
    && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'neighbourhood.location_set' AND entity_id = $nbA")->fetchColumn() === 1);
$r = $post($cl['sa'], '/neighbourhoods', "/neighbourhoods/$nbA", ['action' => 'location', 'latitude' => '48.85', 'longitude' => '2.35']);
$row2 = $pdo->query("SELECT latitude, longitude FROM neighbourhoods WHERE id = $nbA")->fetch();
check('A neighbourhood location outside Uganda is refused and the old one is kept', (float) $row2['latitude'] === 2.77 && str_contains(strip_tags(follow($cl['sa'], $r)['body']), 'outside Uganda'));
$r = $post($cl['sa'], '/neighbourhoods', "/neighbourhoods/$nbB", ['action' => 'location', 'latitude' => '2.8100', 'longitude' => '32.3000']);
$r = $post($cl['sa'], '/neighbourhoods', "/neighbourhoods/$nbB", ['action' => 'location', 'latitude' => '', 'longitude' => '']);
check('Clearing both fields removes the location', $pdo->query("SELECT latitude FROM neighbourhoods WHERE id = $nbB")->fetchColumn() === null);
$r = $post($cl['sa'], '/neighbourhoods', "/neighbourhoods/$nbB", ['action' => 'location', 'latitude' => '2.8100', 'longitude' => '32.3000']);
check('The neighbourhoods page needs neighbourhoods.manage (Hub Manager gets 403)', req($cl['hma'], 'GET', '/neighbourhoods')['status'] === 403);
$hmaPost = postVia($cl['hma'], '/hubs', "/neighbourhoods/$nbA", ['action' => 'location', 'latitude' => '2.5', 'longitude' => '32.5']);
check('A Hub Manager cannot change a neighbourhood location', $hmaPost['status'] === 403 && (float) $pdo->query("SELECT latitude FROM neighbourhoods WHERE id = $nbA")->fetchColumn() === 2.77);

$r = $post($cl['sa'], "/recyclers/$recId", "/recyclers/$recId/location", ['latitude' => '2.7800', 'longitude' => '32.3100']);
check('Recycler location saves (and is audited)', $r['status'] === 303 && (float) Recycler::find($recId)['latitude'] === 2.78 && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'recycler.location_set' AND entity_id = $recId")->fetchColumn() === 1);
$r = $post($cl['sa'], "/recyclers/$recId", "/recyclers/$recId/location", ['latitude' => '99', 'longitude' => '32.3100']);
check('A bad recycler location is refused and the old one kept', (float) Recycler::find($recId)['latitude'] === 2.78);
check('A Hub Manager cannot change a recycler location', postVia($cl['hma'], '/hubs', "/recyclers/$recId/location", ['latitude' => '2.5', 'longitude' => '32.5'])['status'] === 403);
check('Recycler page offers the location form to administrators', str_contains(req($cl['sa'], 'GET', "/recyclers/$recId")['body'], 'Location on the map'));

// ---------------------------------------------------------------- map data against the database
$fA = $run(['hub_id' => (string) $hubA], $sa);
$d = MonitoringService::mapData($fA);
[$n, $kg, $val] = $pdo->query("SELECT COUNT(*), SUM(net_weight_kg), SUM(total_value_ugx) FROM collection_transactions WHERE hub_id = $hubA AND status = 'approved'")->fetch(PDO::FETCH_NUM);
$hA = $byName($d['hubs'], 'P10TEST Hub A');
check('Hub A on the map: collections, kg and value equal direct SQL (2, 30 kg, UGX 30,000)', $hA && (int) $hA['collections'] === (int) $n && $near($hA['kg'], $kg) && (int) $hA['val'] === (int) $val && (int) $n === 2 && $near($kg, 30));
check('Hub A has its coordinates and stock on hand equal to inventory (30 kg)', $hA['latitude'] === 2.7746 && $hA['longitude'] === 32.299 && $near($hA['on_hand'], $pdo->query("SELECT SUM(on_hand_kg) FROM inventory WHERE hub_id = $hubA")->fetchColumn()) && $near($hA['on_hand'], 30));
$sp = $byName($d['hotspots'], 'P10TEST Area A');
check('Hotspot Area A: 2 collections, 30 kg, 1 picker, 100% share when only hub A is chosen', count($d['hotspots']) === 1 && (int) $sp['collections'] === 2 && $near($sp['kg'], 30) && (int) $sp['pickers'] === 1 && $near($sp['share'], 100));
$all = MonitoringService::mapData($run(['from' => date('Y-m-d', strtotime('-60 days')), 'to' => date('Y-m-d', strtotime('+1 day'))], $sa));
[$tk] = $pdo->query("SELECT COALESCE(SUM(net_weight_kg), 0) FROM collection_transactions WHERE status = 'approved' AND recorded_at >= UTC_TIMESTAMP() - INTERVAL 61 DAY")->fetch(PDO::FETCH_NUM);
check('System-wide: hotspot total kg equals direct SQL, and shares add up to 100%', $near($all['totals']['kg'], $tk) && (abs(array_sum(array_map(fn($r) => (float) $r['share'], $all['hotspots'])) - 100) < 0.01 || $all['totals']['kg'] == 0));
$nbAll = $byName($all['hotspots'], 'P10TEST Area A');
$nbBAll = $byName($all['hotspots'], 'P10TEST Area B');
check('System-wide: Area A 30 kg and Area B 15 kg, both with their own picker counts', $near($nbAll['kg'], 30) && $near($nbBAll['kg'], 15) && (int) $nbBAll['pickers'] === 1);
$recRow = $byName($all['recyclers'], 'P10TEST Recycling Ltd');
check('Approved recyclers appear for the national view with coordinates', $recRow && $recRow['latitude'] === 2.78 && $recRow['longitude'] === 32.31);

$pts = MonitoringService::mapPoints($all);
$json = json_encode($pts);
check('Map points hold only places: hubs, hotspots and recyclers with coordinates', count(array_filter($pts['hubs'], fn($h) => $h['name'] === 'P10TEST Hub A')) === 1
    && count(array_filter($pts['hotspots'], fn($h) => $h['name'] === 'P10TEST Area A')) === 1 && count(array_filter($pts['recyclers'], fn($h) => $h['name'] === 'P10TEST Recycling Ltd')) === 1);
check('No picker name, picker code, national ID or phone number is in the map data', !preg_match('/P10 Picker|NIN\d{6}ZZ|0700060|\+256700060|picker_id|national_id/', $json));
$pdo->exec("UPDATE neighbourhoods SET latitude = NULL, longitude = NULL WHERE id = $nbB");
$all2 = MonitoringService::mapData($run(['from' => date('Y-m-d', strtotime('-60 days')), 'to' => date('Y-m-d', strtotime('+1 day'))], $sa));
$p2 = MonitoringService::mapPoints($all2);
check('A neighbourhood without coordinates is left off the map but stays in the table and the "without coordinates" count', count(array_filter($p2['hotspots'], fn($h) => $h['name'] === 'P10TEST Area B')) === 0
    && $byName($all2['hotspots'], 'P10TEST Area B') !== null && $all2['totals']['unlocated'] >= 1);
$pdo->exec("UPDATE neighbourhoods SET latitude = 2.81, longitude = 32.30 WHERE id = $nbB");

// time zone: 21:30 UTC is 00:30 the next day in East Africa
$pdo->exec("UPDATE collection_transactions SET recorded_at = '2026-03-10 21:30:00' WHERE id = $cA2");
$on11 = MonitoringService::mapData($run(['hub_id' => (string) $hubA, 'from' => '2026-03-11', 'to' => '2026-03-11'], $sa));
$on10 = MonitoringService::mapData($run(['hub_id' => (string) $hubA, 'from' => '2026-03-10', 'to' => '2026-03-10'], $sa));
check('East Africa Time day boundaries apply to the map period', (int) $byName($on11['hotspots'], 'P10TEST Area A')['collections'] === 1 && count($on10['hotspots']) === 0);
$pdo->exec("UPDATE collection_transactions SET recorded_at = UTC_TIMESTAMP() WHERE id = $cA2");
check('An empty period gives empty hotspots and zero totals, not an error', ($e0 = MonitoringService::mapData($run(['hub_id' => (string) $hubA, 'from' => '2001-01-01', 'to' => '2001-01-02'], $sa))) && $e0['hotspots'] === [] && $near($e0['totals']['kg'], 0));

// ---------------------------------------------------------------- Hub Manager scoping
$mA = MonitoringService::mapData($run(['hub_id' => (string) $hubB], $hmA));
check('Hub Manager A asking for hub B gets only hub A, only Area A, no recyclers', count($mA['hubs']) === 1 && $mA['hubs'][0]['name'] === 'P10TEST Hub A' && count($mA['hotspots']) === 1
    && $mA['hotspots'][0]['name'] === 'P10TEST Area A' && $mA['recyclers'] === []);
$mAll = MonitoringService::mapData($run(['from' => date('Y-m-d', strtotime('-60 days'))], $hmA));
check('Hub Manager totals cover only their own hub (30 kg), not the whole system', $near($mAll['totals']['kg'], 30) && count($mAll['hubs']) === 1);

// ---------------------------------------------------------------- programme: targets and monthly figures
$tp = MonitoringService::targetProgress();
$pickersNow = (int) $pdo->query("SELECT COUNT(*) FROM pickers p JOIN users u ON u.id = p.user_id WHERE u.status = 'active'")->fetchColumn();
$hubsNow = (int) $pdo->query("SELECT COUNT(*) FROM collection_hubs WHERE status = 'active'")->fetchColumn();
$recNow = (int) $pdo->query("SELECT COUNT(*) FROM recyclers WHERE status = 'approved'")->fetchColumn();
check('Target progress uses live counts (pickers, hubs, recyclers) from the database', (int) $tp[0]['actual'] === $pickersNow && (int) $tp[2]['actual'] === $hubsNow && (int) $tp[3]['actual'] === $recNow && count($tp) === 6);
check('Progress is a percentage capped at 100', array_reduce($tp, fn($ok, $r) => $ok && ($r['progress'] === null || ($r['progress'] >= 0 && $r['progress'] <= 100)), true));
$months = MonitoringService::monthly(date('Y-m-01', strtotime('-11 months')), date('Y-m-d'));
check('Monthly trend has 12 consecutive months, oldest first, ending this month', count($months) === 12 && $months[11]['m'] === date('Y-m') && $months[0]['m'] === date('Y-m', strtotime(date('Y-m-01') . ' -11 months')));
$thisMonth = $months[11];
$off = 3 * 3600;
$sqlKg = (float) $pdo->query("SELECT COALESCE(SUM(net_weight_kg), 0) FROM collection_transactions WHERE status = 'approved' AND DATE_FORMAT(DATE_ADD(recorded_at, INTERVAL $off SECOND), '%Y-%m') = '" . date('Y-m') . "'")->fetchColumn();
$sqlN = (int) $pdo->query("SELECT COUNT(DISTINCT picker_id) FROM collection_transactions WHERE status = 'approved' AND DATE_FORMAT(DATE_ADD(recorded_at, INTERVAL $off SECOND), '%Y-%m') = '" . date('Y-m') . "'")->fetchColumn();
check('This month: kg and number of pickers earning equal direct SQL (at least our 45 kg and 2 pickers)', $near($thisMonth['kg'], $sqlKg) && $thisMonth['pickers'] === $sqlN && $thisMonth['kg'] >= 45 && $thisMonth['pickers'] >= 2);
$joined = (int) $pdo->query("SELECT COUNT(*) FROM pickers p JOIN users u ON u.id = p.user_id WHERE DATE_FORMAT(DATE_ADD(u.created_at, INTERVAL $off SECOND), '%Y-%m') = '" . date('Y-m') . "'")->fetchColumn();
check('New pickers this month equal direct SQL (includes our 2)', $thisMonth['joined'] === $joined && $joined >= 2);
$zero = MonitoringService::monthly('2001-01-15', '2001-03-02');
check('Months with no data are listed with zeros (Jan to Mar 2001)', count($zero) === 3 && array_sum(array_column($zero, 'kg')) == 0 && array_sum(array_column($zero, 'paid')) === 0 && $zero[0]['label'] === 'Jan 2001');
check('A reversed range is handled and a huge range is capped at 36 months', count(MonitoringService::monthly('2026-03-01', '2026-01-01')) === 3 && count(MonitoringService::monthly('1990-01-01', '2026-01-01')) === MonitoringService::MAX_MONTHS);
$pdo->exec("UPDATE collection_transactions SET recorded_at = '2026-01-31 22:00:00' WHERE id = $cA1");   // 01:00 on 1 Feb in East Africa
$jan = MonitoringService::monthly('2026-01-01', '2026-02-28');
$sqlFeb = (float) $pdo->query("SELECT COALESCE(SUM(net_weight_kg), 0) FROM collection_transactions WHERE status = 'approved' AND DATE_FORMAT(DATE_ADD(recorded_at, INTERVAL $off SECOND), '%Y-%m') = '2026-02'")->fetchColumn();
check('A collection at 22:00 UTC on 31 January belongs to February in East Africa Time', $near($jan[1]['kg'], $sqlFeb) && $jan[1]['kg'] >= 20);
$pdo->exec("UPDATE collection_transactions SET recorded_at = UTC_TIMESTAMP() WHERE id = $cA1");

// ---------------------------------------------------------------- editing targets
$orig = $origTargets['target.pickers'];
$r1 = MonitoringService::setTarget('target.pickers', '1,500', $sa);
check('Super Administrator can change a target (commas allowed) and it is audited with old and new value', $r1['error'] === null && $r1['changed'] === true && MonitoringService::targetValues()['target.pickers'] === 1500
    && ($ad = json_decode((string) $pdo->query("SELECT details FROM audit_logs WHERE action = 'target.changed' AND user_id = {$ids['sa']} ORDER BY id DESC LIMIT 1")->fetchColumn(), true)) && $ad['old'] === $orig && $ad['new'] === 1500 && $ad['key'] === 'target.pickers');
$cnt = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'target.changed' AND user_id = {$ids['sa']}")->fetchColumn();
$r2 = MonitoringService::setTarget('target.pickers', '1500', $sa);
check('Saving the same value changes nothing and writes no audit entry', $r2['error'] === null && $r2['changed'] === false && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'target.changed' AND user_id = {$ids['sa']}")->fetchColumn() === $cnt);
$bads = 0;
foreach (['0', '-5', 'abc', '1e3', '100000001', '', '12.5', "5; DROP TABLE system_settings", '9999999999'] as $v) {
    $bads += MonitoringService::setTarget('target.pickers', $v, $sa)['error'] !== null ? 1 : 0;
}
check('Zero, negative, text, decimals, huge and empty targets are refused', $bads === 9 && MonitoringService::targetValues()['target.pickers'] === 1500);
check('Unknown target keys are refused', MonitoringService::setTarget('app.name', '5', $sa)['error'] !== null && Setting::get('app.name') === 'PlastiLink');
check('Project Manager and Hub Manager cannot change targets', MonitoringService::setTarget('target.pickers', '50', $pm)['error'] !== null && MonitoringService::setTarget('target.pickers', '50', $hmA)['error'] !== null && MonitoringService::targetValues()['target.pickers'] === 1500);
$prog = MonitoringService::targetProgress();
check('Progress reflects the new target (actual / 1,500, capped)', $near($prog[0]['target'], 1500) && $near($prog[0]['progress'], min(100, $prog[0]['actual'] / 1500 * 100)));
$r = postVia($cl['sa'], '/monitoring/programme', '/monitoring/targets', ['target_pickers' => '1600', 'target_hubs' => '4']);
check('Targets can be saved from the programme page', $r['status'] === 303 && MonitoringService::targetValues()['target.pickers'] === 1600 && MonitoringService::targetValues()['target.hubs'] === 4);
$r = postVia($cl['sa'], '/monitoring/programme', '/monitoring/targets', ['target_pickers' => 'lots']);
check('An invalid value from the form is refused with a message and nothing changes', MonitoringService::targetValues()['target.pickers'] === 1600 && str_contains(strip_tags(follow($cl['sa'], $r)['body']), 'must be a whole number'));
check('Project Manager cannot post targets (403); guests are sent to sign in', req($cl['pm'], 'GET', '/monitoring/programme')['status'] === 200 && postVia($cl['pm'], '/monitoring/programme', '/monitoring/targets', ['target_pickers' => '9'])['status'] === 403
    && MonitoringService::targetValues()['target.pickers'] === 1600 && ($gr = req(newClient(), 'POST', '/monitoring/targets', [])) && in_array($gr['status'], [303, 400], true));

// ---------------------------------------------------------------- pages and access
$g = newClient();
foreach (['/monitoring/map', '/monitoring/programme'] as $p) {
    $r = req($g, 'GET', $p);
    check("Guest -> $p redirects to /login", $r['status'] === 303 && loc($r) === '/login');
}
check('Hub Operator and waste picker get 403 on both pages', req($cl['hoa'], 'GET', '/monitoring/map')['status'] === 403 && req($cl['hoa'], 'GET', '/monitoring/programme')['status'] === 403
    && req($cl['pkA'], 'GET', '/monitoring/map')['status'] === 403 && req($cl['pkA'], 'GET', '/monitoring/programme')['status'] === 403);
$sm = req($cl['sa'], 'GET', '/monitoring/map?hub_id=' . $hubA);
check('Map page: shows the map button, layer switches, hub A and its figures', $sm['status'] === 200 && str_contains($sm['body'], 'data-map-toggle') && str_contains($sm['body'], 'data-layer="hotspots"') && str_contains($sm['body'], 'P10TEST Hub A') && str_contains($sm['body'], 'P10TEST Area A'));
check('Map is lazy: Leaflet script and tile server are not referenced as loaded resources until "Show map" is clicked', !preg_match('#<script[^>]+leaflet#i', $sm['body']) && !preg_match('#<(img|link|script)[^>]+tile\.openstreetmap#i', $sm['body']) && str_contains($sm['body'], 'vendor/leaflet/leaflet.js'));
check('Map page carries attribution text and the points as data, with no picker names', str_contains($sm['body'], 'OpenStreetMap contributors') && !preg_match('/P10 Picker|NIN\d{6}ZZ|0700060/', $sm['body']));
$shm = req($cl['hma'], 'GET', '/monitoring/map');
check('Hub Manager A sees their hub and neighbourhood only, no recyclers, no other hub', $shm['status'] === 200 && str_contains($shm['body'], 'P10TEST Hub A') && !str_contains($shm['body'], 'P10TEST Hub B') && !str_contains($shm['body'], 'P10TEST Area B') && !str_contains($shm['body'], 'P10TEST Recycling Ltd'));
check('Hub Manager gets 404 for the programme page and its report; Project Manager and Super Administrator see it', req($cl['hma'], 'GET', '/monitoring/programme')['status'] === 404 && req($cl['hma'], 'GET', '/reports/programme')['status'] === 404
    && req($cl['pm'], 'GET', '/monitoring/programme')['status'] === 200 && req($cl['sa'], 'GET', '/monitoring/programme')['status'] === 200);
$sp = req($cl['sa'], 'GET', '/monitoring/programme');
check('Programme page: targets with progress bars, edit form for the Super Administrator, monthly table', str_contains($sp['body'], 'progress-bar') && str_contains($sp['body'], 'name="target_pickers"') && str_contains($sp['body'], 'Month by month'));
$pp = req($cl['pm'], 'GET', '/monitoring/programme');
check('Project Manager sees the same progress but no edit form', $pp['status'] === 200 && str_contains($pp['body'], 'progress-bar') && !str_contains($pp['body'], 'name="target_pickers"'));
check('Hostile or odd query strings do not break the pages or echo markup', ($h = req($cl['sa'], 'GET', '/monitoring/map?from=%3Cscript%3Ealert(1)%3C/script%3E&to=zz&hub_id=-9')) && $h['status'] === 200 && !str_contains($h['body'], '<script>alert')
    && req($cl['sa'], 'GET', '/monitoring/programme?months=999999')['status'] === 200 && req($cl['sa'], 'GET', '/monitoring/programme?months[]=1')['status'] === 200);
check('Navigation: administrators see both monitoring links, Hub Managers only the map', str_contains($sp['body'], '/monitoring/map') && str_contains($sp['body'], '/monitoring/programme') && str_contains($shm['body'], '/monitoring/map') && !str_contains($shm['body'], '/monitoring/programme'));
$formPage = req($cl['sa'], 'GET', "/hubs/$hubA/edit");
check('Hub form offers "Pick on map" and loads no map code until clicked', str_contains($formPage['body'], 'data-geo-pick') && !preg_match('#<script[^>]+leaflet#i', $formPage['body']));
foreach (['leaflet.js', 'leaflet.css', 'images/marker-icon.png'] as $asset) {
    $a = req($g, 'GET', "/assets/vendor/leaflet/$asset");
    check("Bundled Leaflet file is served locally: $asset", $a['status'] === 200 && strlen($a['body']) > 500);
}

// ---------------------------------------------------------------- reports and exports
check('Hub Manager can open the geographic report (own hubs); Super Administrator can open geographic and programme', isset(ReportService::allowedFor($hmA)['geographic']) && !isset(ReportService::allowedFor($hmA)['programme'])
    && isset(ReportService::allowedFor($sa)['geographic'], ReportService::allowedFor($sa)['programme']));
$gx = req($cl['hma'], 'GET', '/reports/geographic/export/csv');
check('Geographic CSV (Hub Manager A): hub A and Area A only, with coordinates, totals', $gx['status'] === 200 && str_contains($gx['body'], 'P10TEST Hub A') && str_contains($gx['body'], 'P10TEST Area A') && str_contains($gx['body'], '2.774600')
    && !str_contains($gx['body'], 'P10TEST Hub B') && !str_contains($gx['body'], 'P10TEST Area B') && !str_contains($gx['body'], 'Recycler locations'));
$gs = req($cl['sa'], 'GET', '/reports/geographic/export/csv?from=' . date('Y-m-d', strtotime('-60 days')));
check('Geographic CSV (Super Administrator): both areas, the recycler table with its coordinates', str_contains($gs['body'], 'P10TEST Area B') && str_contains($gs['body'], 'Recycler locations') && str_contains($gs['body'], 'P10TEST Recycling Ltd') && str_contains($gs['body'], '2.780000'));
$px = req($cl['sa'], 'GET', '/reports/programme/export/csv');
check('Programme CSV has the targets and the month table; figures are plain numbers', $px['status'] === 200 && str_contains($px['body'], 'Progress against programme targets') && str_contains($px['body'], 'By month') && str_contains($px['body'], '1600'));
check('Programme and geographic report screens and XLSX/PDF downloads work', req($cl['sa'], 'GET', '/reports/programme')['status'] === 200 && req($cl['sa'], 'GET', '/reports/geographic')['status'] === 200
    && str_starts_with(req($cl['sa'], 'GET', '/reports/programme/export/pdf')['body'], '%PDF-') && str_starts_with(req($cl['sa'], 'GET', '/reports/geographic/export/xlsx')['body'], 'PK'));
$leak = false;
foreach (['geographic', 'programme'] as $k) {
    foreach (['sa', 'pm'] as $who) {
        foreach (['csv', 'pdf'] as $fmt) {
            if (preg_match('/NIN\d{6}ZZ|0700060\d{3}|\+256700060\d{3}|P10 Picker/', req($cl[$who], 'GET', "/reports/$k/export/$fmt?from=" . date('Y-m-d', strtotime('-400 days')))['body'])) {
                $leak = true;
            }
        }
    }
}
check('No monitoring export contains a picker name, national ID or phone number', !$leak);
check('Exports of the new reports are audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'report.exported' AND user_id = {$ids['sa']} AND details LIKE '%programme%'")->fetchColumn() >= 1);

// ---------------------------------------------------------------- performance with a few thousand collections
$pdo->beginTransaction();
$ins = $pdo->prepare("INSERT INTO collection_transactions (reference, submission_token, picker_id, hub_id, neighbourhood_id, plastic_category_id, status, gross_weight_kg, tare_weight_kg, net_weight_kg,
    price_per_kg_ugx, total_value_ugx, recorded_by, recorded_at, decided_by, decided_at) VALUES (?, ?, ?, ?, ?, ?, 'approved', 10.00, 0.00, 10.00, 1000, 10000, ?, ?, ?, ?)");
for ($i = 0; $i < 4000; $i++) {
    $at = gmdate('Y-m-d H:i:s', time() - ($i % 330) * 86400 - ($i % 24) * 3600);
    $ins->execute(['P10T-' . $i . '-' . bin2hex(random_bytes(4)), bin2hex(random_bytes(16)), $i % 2 ? $pkA : $pkB, $i % 2 ? $hubA : $hubB, $i % 2 ? $nbA : $nbB, $catId, $ids['hoA'], $at, $ids['hmA'], $at]);
}
$pdo->commit();
$t0 = microtime(true);
$big = MonitoringService::mapData($run(['from' => date('Y-m-d', strtotime('-400 days')), 'to' => date('Y-m-d', strtotime('+1 day'))], $sa));
$ms1 = (microtime(true) - $t0) * 1000;
$bA = $byName($big['hotspots'], 'P10TEST Area A');
check('Map data with 4,000 more collections: Area A total exact (2,000 x 10 kg + 30 kg) and fast (< 1.5 s)', $near($bA['kg'], 20030) && (int) $bA['collections'] === 2002 && $ms1 < 1500, sprintf('%.0f ms, kg %s', $ms1, $bA['kg']));
$t0 = microtime(true);
$bm = MonitoringService::monthly(date('Y-m-01', strtotime('-23 months')), date('Y-m-d'));
$ms2 = (microtime(true) - $t0) * 1000;
$sumKg = array_sum(array_column($bm, 'kg'));
$sqlAll = (float) $pdo->query("SELECT COALESCE(SUM(net_weight_kg), 0) FROM collection_transactions WHERE status = 'approved' AND recorded_at >= '" . gmdate('Y-m-d H:i:s', strtotime(date('Y-m-01', strtotime('-23 months')) . ' 00:00:00') - 3 * 3600) . "'")->fetchColumn();
check('24 monthly rows with 4,000 more collections: total equals direct SQL and is fast (< 1.5 s)', $near($sumKg, $sqlAll) && count($bm) === 24 && $ms2 < 1500, sprintf('%.0f ms, report %s vs sql %s', $ms2, $sumKg, $sqlAll));
$t0 = microtime(true);
$page = req($cl['sa'], 'GET', '/monitoring/map?from=' . date('Y-m-d', strtotime('-400 days')));
$page2 = req($cl['sa'], 'GET', '/monitoring/programme?months=24');
check('Both pages with 4,000 more collections load in under 3 seconds each', $page['status'] === 200 && $page2['status'] === 200 && (microtime(true) - $t0) < 6, sprintf('%.0f ms', (microtime(true) - $t0) * 1000));

// ---------------------------------------------------------------- finish
$cleanup();
$left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'phase10test.%'")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM collection_hubs WHERE name LIKE 'P10TEST%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM neighbourhoods WHERE name LIKE 'P10TEST%'")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM recyclers WHERE business_name LIKE 'P10TEST%'")->fetchColumn();
check('Test data cleaned up', $left === 0);
check('Programme targets are back to their original values', MonitoringService::targetValues() === $origTargets);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
