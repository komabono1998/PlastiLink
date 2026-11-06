<?php
declare(strict_types=1);

/**
 * Phase 4 tests: prices, collections (record/weigh/approve/reject/reverse), receipts, scoping.
 *   php tests/phase4_collections.php [base-url]
 * Creates temporary data (phase4test.*, "P4TEST" hubs) and removes it afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/_http.php';

use App\Core\Database;
use App\Helpers\Dates;
use App\Helpers\Weight;
use App\Models\Collection;
use App\Models\Hub;
use App\Models\Price;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\HubScope;
use App\Services\PickerService;

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase4Test!pw';
$pass = 0;
$fail = 0;

$cleanup = function () use ($pdo): void {
    $hubs = $pdo->query("SELECT id FROM collection_hubs WHERE name LIKE 'P4TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    $users = $pdo->query("SELECT id FROM users WHERE email LIKE 'phase4test.%' OR phone_e164 LIKE '+256700077%'")->fetchAll(PDO::FETCH_COLUMN);
    $hubIn = $hubs ? implode(',', array_map('intval', $hubs)) : '0';
    $userIn = $users ? implode(',', array_map('intval', $users)) : '0';
    $cols = $pdo->query("SELECT id FROM collection_transactions WHERE hub_id IN ($hubIn) OR recorded_by IN ($userIn)")->fetchAll(PDO::FETCH_COLUMN);
    $colIn = $cols ? implode(',', array_map('intval', $cols)) : '0';
    $prices = $pdo->query("SELECT id FROM price_lists WHERE hub_id IN ($hubIn) OR note LIKE 'P4TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    $priceIn = $prices ? implode(',', array_map('intval', $prices)) : '0';

    $pdo->exec("DELETE FROM audit_logs WHERE (entity_type = 'collection' AND entity_id IN ($colIn)) OR (entity_type = 'price' AND entity_id IN ($priceIn))
                OR user_id IN ($userIn) OR (entity_type = 'user' AND entity_id IN ($userIn))");
    $pdo->exec("DELETE FROM receipts WHERE collection_id IN ($colIn)");
    $pdo->exec("DELETE FROM weighing_records WHERE collection_id IN ($colIn)");
    $pdo->exec("DELETE FROM collection_transactions WHERE id IN ($colIn)");
    $pdo->exec("DELETE FROM price_lists WHERE id IN ($priceIn)");
    $pdo->exec("DELETE FROM pickers WHERE user_id IN ($userIn)");
    $pdo->exec("DELETE FROM collection_hubs WHERE id IN ($hubIn)");
    $pdo->exec("DELETE FROM plastic_categories WHERE code = 'P4X'");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase4test%' OR identifier LIKE '%700077%'");
    $pdo->exec("DELETE FROM users WHERE id IN ($userIn)");
};
$cleanup();

// ---------------------------------------------------------------- arithmetic (pure functions)
check('Weight::parse accepts 12.5 / 12,5 / 12 / 0.05', Weight::parse('12.5') === 1250 && Weight::parse('12,5') === 1250 && Weight::parse('12') === 1200 && Weight::parse('0.05') === 5 && Weight::parse(' 7.1 ') === 710);
check('Weight::parse rejects negatives, text, 3 decimals, empty, huge', Weight::parse('-1') === null && Weight::parse('abc') === null && Weight::parse('1.234') === null && Weight::parse('') === null && Weight::parse('123456') === null && Weight::parse('1e3') === null);
check('Weight::toDecimal', Weight::toDecimal(1250) === '12.50' && Weight::toDecimal(5) === '0.05' && Weight::toDecimal(100000) === '1000.00');
check('Value: 12.5 kg x 1000 = 12,500', Weight::value(1250, 1000) === 12500);
check('Value rounds half-up to whole UGX (3.33 kg x 750 = 2,497.5 -> 2,498)', Weight::value(333, 750) === 2498);
check('Value rounding edge cases', Weight::value(1, 1) === 0 && Weight::value(50, 1) === 1 && Weight::value(1, 99) === 1 && Weight::value(1, 49) === 0);
check('No float drift: 0.3 - 0.1 kg is exactly 0.20', Weight::parse('0.3') - Weight::parse('0.1') === 20 && Weight::value(20, 1000) === 200);
[$w, $e] = CollectionService::readWeights(['gross_weight_kg' => '10', 'tare_weight_kg' => '10']);
check('Tare equal to gross refused', $w === null && isset($e['tare_weight_kg']));
[$w, $e] = CollectionService::readWeights(['gross_weight_kg' => '10', 'tare_weight_kg' => '']);
check('Empty tare means 0', $w === ['gross' => 1000, 'tare' => 0, 'net' => 1000] && $e === []);
[$w, $e] = CollectionService::readWeights(['gross_weight_kg' => '5001', 'tare_weight_kg' => '']);
check('Weights above the 5,000 kg sanity cap refused', $w === null && isset($e['gross_weight_kg']));
check('State machine table', CollectionService::canMove('pending', 'approved') && CollectionService::canMove('pending', 'rejected') && CollectionService::canMove('approved', 'reversed')
    && !CollectionService::canMove('pending', 'reversed') && !CollectionService::canMove('approved', 'approved') && !CollectionService::canMove('rejected', 'approved')
    && !CollectionService::canMove('reversed', 'approved') && !CollectionService::canMove('approved', 'rejected'));

// ---------------------------------------------------------------- setup
$ids = [];
foreach (['super_admin' => 'sa', 'project_manager' => 'pm', 'hub_manager' => 'hmA', 'hub_operator' => 'hoA'] as $role => $k) {
    $ids[$k] = User::create(['role' => $role, 'full_name' => "P4 $k", 'email' => "phase4test.$k@example.test", 'password' => $PW, 'status' => 'active']);
}
$ids['hmB'] = User::create(['role' => 'hub_manager', 'full_name' => 'P4 hmB', 'email' => 'phase4test.hmb@example.test', 'password' => $PW, 'status' => 'active']);
$ids['hoB'] = User::create(['role' => 'hub_operator', 'full_name' => 'P4 hoB', 'email' => 'phase4test.hob@example.test', 'password' => $PW, 'status' => 'active']);
$nbId = (int) $pdo->query('SELECT id FROM neighbourhoods WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$catId = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code = 'PET'")->fetchColumn();
$hubA = Hub::create(['name' => 'P4TEST Hub A', 'neighbourhood_id' => $nbId, 'address_note' => null, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
$hubB = Hub::create(['name' => 'P4TEST Hub B', 'neighbourhood_id' => $nbId, 'address_note' => null, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
Hub::assign($hubA, $ids['hmA'], 'manager'); Hub::assign($hubA, $ids['hoA'], 'operator');
Hub::assign($hubB, $ids['hmB'], 'manager'); Hub::assign($hubB, $ids['hoB'], 'operator');
Price::create($catId, $hubA, 1000, 'P4TEST price A', $ids['sa']);
Price::create($catId, $hubB, 1000, 'P4TEST price B', $ids['sa']);

$saUser = User::findById($ids['sa']);
$mk = function (string $name, string $phone, int $hub) use ($saUser, $nbId, $PW): array {
    $r = PickerService::registerByStaff(['full_name' => $name, 'phone' => $phone, 'neighbourhood_id' => $nbId, 'preferred_hub_id' => $hub, 'national_id' => 'NIN' . substr($phone, -6) . 'XY', 'consent' => '1'], $saUser);
    $p = App\Models\Picker::find($r['id']);
    User::updatePassword((int) $p['user_id'], $PW);   // known password, clears the temp-password flag
    return ['pid' => (int) $r['id'], 'uid' => (int) $p['user_id'], 'phone' => $phone];
};
$pkA = $mk('P4 Picker Alpha', '0700077101', $hubA);
$pkB = $mk('P4 Picker Bravo', '0700077102', $hubB);

$cl = [];
foreach (['sa' => 'phase4test.sa@example.test', 'pm' => 'phase4test.pm@example.test', 'hmA' => 'phase4test.hma@example.test', 'hoA' => 'phase4test.hoa@example.test',
          'hmB' => 'phase4test.hmb@example.test', 'hoB' => 'phase4test.hob@example.test', 'pkA' => '0700077101', 'pkB' => '0700077102'] as $k => $ident) {
    $cl[$k] = newClient();
    $r = login($cl[$k], $ident, $PW);
    check("Setup: $k signs in", $r['status'] === 303 && loc($r) !== '/login', 'loc ' . loc($r));
}

// ---------------------------------------------------------------- guests / CSRF
$g = newClient();
foreach (['/collections', '/collections/new', '/collections/1', '/prices', '/receipts/1', '/pickers/search?q=ab'] as $p) {
    $r = req($g, 'GET', $p);
    check("Guest -> $p redirects to /login", $r['status'] === 303 && loc($r) === '/login', "status {$r['status']}");
}
check('POST /collections without CSRF token refused', req($cl['hoA'], 'POST', '/collections', ['gross_weight_kg' => '1'])['status'] === 400);
check('POST approve without CSRF token refused', req($cl['hmA'], 'POST', '/collections/1/approve', [])['status'] === 400);

// ---------------------------------------------------------------- RBAC
$matrix = [
    'sa'  => ['/collections' => 200, '/collections/new' => 200, '/prices' => 200],
    'pm'  => ['/collections' => 200, '/collections/new' => 403, '/prices' => 200],
    'hmA' => ['/collections' => 200, '/collections/new' => 200, '/prices' => 200],
    'hoA' => ['/collections' => 200, '/collections/new' => 200, '/prices' => 200],
    'pkA' => ['/collections' => 200, '/collections/new' => 403, '/prices' => 403, '/pickers/search?q=ab' => 403],
];
foreach ($matrix as $who => $paths) {
    foreach ($paths as $path => $expected) {
        $r = req($cl[$who], 'GET', $path);
        check("RBAC: $who GET $path = $expected", $r['status'] === $expected, "got {$r['status']}");
    }
}
check('RBAC: hub manager cannot set prices (POST 403)', postVia($cl['hmA'], '/prices', '/prices', ['plastic_category_id' => $catId, 'price_per_kg_ugx' => '999'])['status'] === 403);
check('RBAC: hub operator cannot set prices (POST 403)', postVia($cl['hoA'], '/prices', '/prices', ['plastic_category_id' => $catId, 'price_per_kg_ugx' => '999'])['status'] === 403);
check('RBAC: picker cannot record (POST 403)', postVia($cl['pkA'], '/collections', '/collections', ['x' => '1'])['status'] === 403);
check('Price rows cannot be edited or deleted through the web (no such routes)', in_array(req($cl['pm'], 'POST', '/prices/1', ['_csrf' => csrfFrom(req($cl['pm'], 'GET', '/prices')['body'])])['status'], [404, 405], true));

// ---------------------------------------------------------------- prices
$pricePage = '/prices';
$r = postVia($cl['pm'], $pricePage, '/prices', ['plastic_category_id' => $catId, 'price_per_kg_ugx' => '0']);
check('Price of 0 refused', str_contains(strip_tags(follow($cl['pm'], $r)['body']), 'between 1 and'));
$r = postVia($cl['pm'], $pricePage, '/prices', ['plastic_category_id' => $catId, 'price_per_kg_ugx' => 'abc']);
check('Non-numeric price refused', str_contains(strip_tags(follow($cl['pm'], $r)['body']), 'whole number'));
$r = postVia($cl['pm'], $pricePage, '/prices', ['plastic_category_id' => $catId, 'price_per_kg_ugx' => '5000000']);
check('Absurd price refused', str_contains(strip_tags(follow($cl['pm'], $r)['body']), 'between 1 and'));
$r = postVia($cl['pm'], $pricePage, '/prices', ['plastic_category_id' => '9999', 'price_per_kg_ugx' => '900']);
check('Unknown material refused on the price form', str_contains(strip_tags(follow($cl['pm'], $r)['body']), 'Choose a material'));
$before = (int) $pdo->query('SELECT COUNT(*) FROM price_lists')->fetchColumn();
$r = postVia($cl['pm'], $pricePage, '/prices', ['plastic_category_id' => $catId, 'hub_id' => $hubA, 'price_per_kg_ugx' => '1100', 'note' => 'P4TEST raise']);
check('Project manager sets a hub price', loc($r) === '/prices' && (int) $pdo->query('SELECT COUNT(*) FROM price_lists')->fetchColumn() === $before + 1);
check('Old price rows are kept (history grows, nothing overwritten)', (int) $pdo->query("SELECT COUNT(*) FROM price_lists WHERE hub_id = $hubA")->fetchColumn() === 2);
check('Current price is the newest one', (int) Price::current($catId, $hubA)['price_per_kg_ugx'] === 1100);
$ph = req($cl['hoA'], 'GET', '/prices')['body'];
check('Prices page shows history and current price; operator sees no "set price" form', str_contains($ph, 'P4TEST raise') && str_contains($ph, 'UGX 1,100') && !str_contains($ph, 'Set a new price'));
postVia($cl['pm'], $pricePage, '/prices', ['plastic_category_id' => $catId, 'hub_id' => $hubA, 'price_per_kg_ugx' => '1000', 'note' => 'P4TEST back']);   // back to 1000 for the next tests
check('Price change is audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'price.set' AND user_id = {$ids['pm']}")->fetchColumn() >= 2);

// ---------------------------------------------------------------- picker search
$s = json_decode(req($cl['hoA'], 'GET', '/pickers/search?q=P4+Picker')['body'], true);
$names = array_column($s['results'] ?? [], 'name');
check('Picker search (hub A operator) finds only hub A pickers', $names === ['P4 Picker Alpha'], json_encode($names));
check('Picker search returns no full phone numbers or IDs', !str_contains(json_encode($s), '0700077101') && !str_contains(json_encode($s), '+256700077101') && !str_contains(json_encode($s), 'NIN'));
check('Picker search needs 2+ characters', json_decode(req($cl['hoA'], 'GET', '/pickers/search?q=P')['body'], true)['results'] === []);
check('Hub B operator finds Bravo, not Alpha', array_column(json_decode(req($cl['hoB'], 'GET', '/pickers/search?q=P4+Picker')['body'], true)['results'], 'name') === ['P4 Picker Bravo']);
check('Super admin search sees both', count(json_decode(req($cl['sa'], 'GET', '/pickers/search?q=P4+Picker')['body'], true)['results']) === 2);

// ---------------------------------------------------------------- recording
$form = req($cl['hoA'], 'GET', '/collections/new');
check('Record form: token present, hub A selected, price shown', preg_match('/name="token" value="([a-f0-9]{32})"/', $form['body'], $tm) === 1 && str_contains($form['body'], 'P4TEST Hub A') && !str_contains($form['body'], 'P4TEST Hub B') && str_contains($form['body'], 'UGX 1,000/kg'));
$tok = $tm[1] ?? '';
$good = ['token' => $tok, 'picker_id' => $pkA['pid'], 'hub_id' => $hubA, 'plastic_category_id' => $catId, 'gross_weight_kg' => '12.5', 'tare_weight_kg' => '0.5', 'notes' => ''];
$try = function (array $over) use ($cl, $good): string {
    $r = postVia($cl['hoA'], '/collections/new', '/collections', $over + ['token' => bin2hex(random_bytes(16))] + $good);
    return strip_tags(follow($cl['hoA'], $r)['body']);
};
check('No picker chosen', str_contains($try(['picker_id' => '']), 'Choose a registered picker'));
check('Picker out of scope (hub B picker) refused', str_contains($try(['picker_id' => $pkB['pid']]), 'Choose a registered picker'));
check('Missing gross weight', str_contains($try(['gross_weight_kg' => '']), 'Gross weight is required'));
check('Text gross weight', str_contains($try(['gross_weight_kg' => 'abc']), 'must be a number'));
check('Zero gross weight', str_contains($try(['gross_weight_kg' => '0']), 'more than zero'));
check('Negative gross weight', str_contains($try(['gross_weight_kg' => '-5']), 'must be a number'));
check('Tare above gross', str_contains($try(['gross_weight_kg' => '5', 'tare_weight_kg' => '6']), 'less than the gross'));
check('Absurd weight', str_contains($try(['gross_weight_kg' => '9999']), 'too high'));
check('Other hub (B) refused for a hub A operator', str_contains($try(['hub_id' => $hubB]), 'Choose one of your active hubs'));
check('Unknown material refused on the collection form (no crash)', str_contains($try(['plastic_category_id' => '999']), 'Choose the material'));
$pdo->exec("INSERT INTO plastic_categories (code, name) VALUES ('P4X', 'P4TEST unpriced material')");
$x = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code='P4X'")->fetchColumn();
check('Material with no price set is refused with guidance', str_contains($try(['plastic_category_id' => $x]), 'No price has been set'));
check('Nothing was saved by any failed attempt', (int) $pdo->query("SELECT COUNT(*) FROM collection_transactions WHERE hub_id = $hubA")->fetchColumn() === 0);

$r = req($cl['hoA'], 'POST', '/collections', ['_csrf' => csrfFrom($form['body'])] + $good);
check('Valid collection recorded -> its page', preg_match('#^/collections/(\d+)$#', loc($r), $m) === 1, 'loc ' . loc($r));
$c1 = (int) ($m[1] ?? 0);
$row = $pdo->query("SELECT * FROM collection_transactions WHERE id = $c1")->fetch();
check('Reference format COL-YYYYMMDD-######', preg_match('/^COL-\d{8}-\d{6}$/', (string) $row['reference']) === 1 && str_contains($row['reference'], str_replace('-', '', Dates::todayYmd())));
check('Net = gross - tare (12.50 - 0.50 = 12.00); value = 12,000', $row['gross_weight_kg'] === '12.50' && $row['tare_weight_kg'] === '0.50' && $row['net_weight_kg'] === '12.00' && (int) $row['total_value_ugx'] === 12000);
check('Price snapshot stored and linked to the price row', (int) $row['price_per_kg_ugx'] === 1000 && (int) $pdo->query("SELECT price_per_kg_ugx FROM price_lists WHERE id = {$row['price_list_id']}")->fetchColumn() === 1000);
check('Starts pending, recorded by the operator, neighbourhood snapshot kept', $row['status'] === 'pending' && (int) $row['recorded_by'] === $ids['hoA'] && (int) $row['neighbourhood_id'] === $nbId && (int) $row['hub_id'] === $hubA);
check('First weighing stored', (int) $pdo->query("SELECT COUNT(*) FROM weighing_records WHERE collection_id = $c1 AND sequence_no = 1 AND net_weight_kg = 12.00")->fetchColumn() === 1);

// double submit
$r2 = req($cl['hoA'], 'POST', '/collections', ['_csrf' => csrfFrom(req($cl['hoA'], 'GET', '/collections/new')['body'])] + $good);
check('Double submit (same token) goes to the same record', loc($r2) === "/collections/$c1" && str_contains(follow($cl['hoA'], $r2)['body'], 'already recorded'));
check('Double submit created no second record or weighing', (int) $pdo->query("SELECT COUNT(*) FROM collection_transactions WHERE submission_token = '$tok'")->fetchColumn() === 1 && (int) $pdo->query("SELECT COUNT(*) FROM weighing_records WHERE collection_id = $c1")->fetchColumn() === 1);
$res = CollectionService::record($good, User::findById($ids['hoA']));
check('Service-level duplicate token is idempotent', !empty($res['duplicate']) && $res['id'] === $c1);
$threw = false;
try { $pdo->exec("INSERT INTO collection_transactions (submission_token, picker_id, hub_id, neighbourhood_id, plastic_category_id, gross_weight_kg, tare_weight_kg, net_weight_kg, price_per_kg_ugx, total_value_ugx, recorded_by, recorded_at)
                  VALUES ('$tok', {$pkA['pid']}, $hubA, $nbId, $catId, 5, 1, 4, 1000, 4000, {$ids['hoA']}, UTC_TIMESTAMP())"); } catch (PDOException) { $threw = true; }
check('Database rejects a duplicate submission token', $threw);
$threw = false;
try { $pdo->exec("INSERT INTO collection_transactions (submission_token, picker_id, hub_id, neighbourhood_id, plastic_category_id, gross_weight_kg, tare_weight_kg, net_weight_kg, price_per_kg_ugx, total_value_ugx, recorded_by, recorded_at)
                  VALUES ('" . bin2hex(random_bytes(16)) . "', {$pkA['pid']}, $hubA, $nbId, $catId, 1, 5, -4, 1000, 0, {$ids['hoA']}, UTC_TIMESTAMP())"); } catch (PDOException) { $threw = true; }
check('Database CHECK rejects tare above gross / non-positive net', $threw);

// picker typed by code; inactive picker
$r = postVia($cl['hoB'], '/collections/new', '/collections', ['token' => bin2hex(random_bytes(16)), 'picker_id' => '', 'picker_code' => $pdo->query("SELECT picker_code FROM pickers WHERE id = {$pkB['pid']}")->fetchColumn(),
    'hub_id' => $hubB, 'plastic_category_id' => $catId, 'gross_weight_kg' => '3.33', 'tare_weight_kg' => '']);
check('Picker can be entered by picker ID (no JavaScript needed)', preg_match('#^/collections/(\d+)$#', loc($r), $m) === 1);
$c2 = (int) ($m[1] ?? 0);
check('3.33 kg x 1000 = 3,330 exactly', (int) $pdo->query("SELECT total_value_ugx FROM collection_transactions WHERE id = $c2")->fetchColumn() === 3330);
User::setStatus($pkB['uid'], 'suspended');
$r = postVia($cl['hoB'], '/collections/new', '/collections', ['token' => bin2hex(random_bytes(16)), 'picker_id' => $pkB['pid'], 'hub_id' => $hubB, 'plastic_category_id' => $catId, 'gross_weight_kg' => '2']);
check('Suspended picker cannot have collections recorded', str_contains(strip_tags(follow($cl['hoB'], $r)['body']), 'not active'));
User::setStatus($pkB['uid'], 'active');

// ---------------------------------------------------------------- re-weighing a pending collection
check('Re-weigh needs a reason', str_contains(strip_tags(follow($cl['hoA'], postVia($cl['hoA'], "/collections/$c1", "/collections/$c1/reweigh", ['gross_weight_kg' => '13', 'tare_weight_kg' => '0.5', 'reason' => '']))['body']), 'Say why'));
postVia($cl['pm'], '/prices', '/prices', ['plastic_category_id' => $catId, 'hub_id' => $hubA, 'price_per_kg_ugx' => '1500', 'note' => 'P4TEST rise']);
$r = postVia($cl['hoA'], "/collections/$c1", "/collections/$c1/reweigh", ['gross_weight_kg' => '13', 'tare_weight_kg' => '1', 'reason' => 'Scale re-checked']);
$row = $pdo->query("SELECT * FROM collection_transactions WHERE id = $c1")->fetch();
check('Re-weigh updates weights and re-snapshots the current price (13 - 1 = 12 kg x 1,500)', loc($r) === "/collections/$c1" && $row['net_weight_kg'] === '12.00' && (int) $row['price_per_kg_ugx'] === 1500 && (int) $row['total_value_ugx'] === 18000);
$wr = $pdo->query("SELECT sequence_no, net_weight_kg, price_per_kg_ugx, total_value_ugx, reason FROM weighing_records WHERE collection_id = $c1 ORDER BY sequence_no")->fetchAll();
check('Weighing history keeps both weighings (first one untouched)', count($wr) === 2 && (int) $wr[0]['price_per_kg_ugx'] === 1000 && (int) $wr[0]['total_value_ugx'] === 12000 && $wr[1]['reason'] === 'Scale re-checked');
check('Weighing history is shown on the page', str_contains(req($cl['hoA'], 'GET', "/collections/$c1")['body'], 'Scale re-checked'));
postVia($cl['pm'], '/prices', '/prices', ['plastic_category_id' => $catId, 'hub_id' => $hubA, 'price_per_kg_ugx' => '1000', 'note' => 'P4TEST back to 1000']);

// ---------------------------------------------------------------- approval rules
check('Operators cannot approve (POST 403)', postVia($cl['hoA'], "/collections/$c1", "/collections/$c1/approve", [])['status'] === 403);
$r = postVia($cl['hmA'], '/collections/new', '/collections', ['token' => bin2hex(random_bytes(16)), 'picker_id' => $pkA['pid'], 'hub_id' => $hubA, 'plastic_category_id' => $catId, 'gross_weight_kg' => '20', 'tare_weight_kg' => '']);
preg_match('#^/collections/(\d+)$#', loc($r), $m); $c3 = (int) ($m[1] ?? 0);
check('Hub manager can record a collection', $c3 > 0);
$r = postVia($cl['hmA'], "/collections/$c3", "/collections/$c3/approve", []);
check('Four-eyes: the recorder cannot approve their own collection', str_contains(strip_tags(follow($cl['hmA'], $r)['body']), 'someone else must approve') && $pdo->query("SELECT status FROM collection_transactions WHERE id = $c3")->fetchColumn() === 'pending');
check('Own-record page offers no approve button, only a note', !str_contains(req($cl['hmA'], 'GET', "/collections/$c3")['body'], 'Approve and issue receipt') && str_contains(req($cl['hmA'], 'GET', "/collections/$c3")['body'], 'another person must approve'));
check('A hub B manager cannot approve a hub A collection', str_contains(strip_tags(follow($cl['hmB'], postVia($cl['hmB'], '/collections', "/collections/$c1/approve", []))['body']), 'not found') && $pdo->query("SELECT status FROM collection_transactions WHERE id = $c1")->fetchColumn() === 'pending');
$r = postVia($cl['hmA'], "/collections/$c1", "/collections/$c1/approve", []);
check('Another hub A person (manager) approves the operator\'s collection', str_contains(strip_tags(follow($cl['hmA'], $r)['body']), 'receipt issued') && $pdo->query("SELECT status FROM collection_transactions WHERE id = $c1")->fetchColumn() === 'approved');
$row = $pdo->query("SELECT * FROM collection_transactions WHERE id = $c1")->fetch();
check('Approval stamps who and when', (int) $row['decided_by'] === $ids['hmA'] && $row['decided_at'] !== null);
$r = postVia($cl['hmA'], "/collections/$c1", "/collections/$c1/approve", []);
check('Cannot approve twice (no second receipt)', str_contains(strip_tags(follow($cl['hmA'], $r)['body']), 'Only pending') && (int) $pdo->query("SELECT COUNT(*) FROM receipts WHERE collection_id = $c1")->fetchColumn() === 1);
$r = postVia($cl['hoA'], "/collections/$c1", "/collections/$c1/reweigh", ['gross_weight_kg' => '99', 'reason' => 'trying to change after approval']);
check('Approved collections are locked (no re-weigh)', str_contains(strip_tags(follow($cl['hoA'], $r)['body']), 'Only pending') && (int) $pdo->query("SELECT COUNT(*) FROM weighing_records WHERE collection_id = $c1")->fetchColumn() === 2 && $pdo->query("SELECT net_weight_kg FROM collection_transactions WHERE id = $c1")->fetchColumn() === '12.00');
$r = postVia($cl['hmA'], "/collections/$c1", "/collections/$c1/reject", ['reason' => 'too late']);
check('Cannot reject an approved collection', str_contains(strip_tags(follow($cl['hmA'], $r)['body']), 'Only pending'));

// price snapshot immutability: price changes later, approved total stays
postVia($cl['pm'], '/prices', '/prices', ['plastic_category_id' => $catId, 'hub_id' => $hubA, 'price_per_kg_ugx' => '2500', 'note' => 'P4TEST big change']);
$row = $pdo->query("SELECT price_per_kg_ugx, total_value_ugx FROM collection_transactions WHERE id = $c1")->fetch();
check('A later price change does not alter an approved collection (snapshot)', (int) $row['price_per_kg_ugx'] === 1500 && (int) $row['total_value_ugx'] === 18000);
postVia($cl['pm'], '/prices', '/prices', ['plastic_category_id' => $catId, 'hub_id' => $hubA, 'price_per_kg_ugx' => '1000', 'note' => 'P4TEST restore']);

// rejection
$r = postVia($cl['hmB'], '/collections', "/collections/$c2/reject", ['reason' => '']);
check('Rejection needs a reason', str_contains(strip_tags(follow($cl['hmB'], $r)['body']), 'reason'));
postVia($cl['hmB'], "/collections/$c2", "/collections/$c2/reject", ['reason' => 'Bottles contaminated']);
$row = $pdo->query("SELECT status, decision_reason FROM collection_transactions WHERE id = $c2")->fetch();
check('Rejected collection stores the reason and has no receipt', $row['status'] === 'rejected' && $row['decision_reason'] === 'Bottles contaminated' && (int) $pdo->query("SELECT COUNT(*) FROM receipts WHERE collection_id = $c2")->fetchColumn() === 0);
$r = postVia($cl['hmB'], "/collections/$c2", "/collections/$c2/approve", []);
check('Rejected collection cannot be approved afterwards', str_contains(strip_tags(follow($cl['hmB'], $r)['body']), 'Only pending'));

// ---------------------------------------------------------------- receipts
$rid = (int) $pdo->query("SELECT id FROM receipts WHERE collection_id = $c1")->fetchColumn();
$rc = req($cl['hmA'], 'GET', "/receipts/$rid");
check('Receipt number format RCT-YYYYMMDD-######', preg_match('/^RCT-\d{8}-\d{6}$/', (string) $pdo->query("SELECT receipt_number FROM receipts WHERE id = $rid")->fetchColumn()) === 1);
check('Receipt shows reference, weights, price, total, picker, approver', $rc['status'] === 200 && str_contains($rc['body'], $row0 = $pdo->query("SELECT reference FROM collection_transactions WHERE id = $c1")->fetchColumn())
    && str_contains($rc['body'], 'UGX 18,000') && str_contains($rc['body'], '12 kg') && str_contains($rc['body'], 'UGX 1,500') && str_contains($rc['body'], 'P4 Picker Alpha') && str_contains($rc['body'], 'P4 hmA'));
$snap = (string) $pdo->query("SELECT snapshot FROM receipts WHERE id = $rid")->fetchColumn();
check('Receipt snapshot holds no phone number or national ID', !str_contains($snap, '0700077101') && !str_contains($snap, '700077101') && !str_contains($snap, 'NIN') && !str_contains($rc['body'], '+256700077101'));
$pdo->exec("UPDATE users SET full_name = 'P4 Renamed Alpha' WHERE id = {$pkA['uid']}");
check('Issued receipt is immutable (picker renamed later)', str_contains(req($cl['hmA'], 'GET', "/receipts/$rid")['body'], 'P4 Picker Alpha') && !str_contains(req($cl['hmA'], 'GET', "/receipts/$rid")['body'], 'P4 Renamed Alpha'));
$pdo->exec("UPDATE users SET full_name = 'P4 Picker Alpha' WHERE id = {$pkA['uid']}");
check('Print stylesheet and Print button present', str_contains($rc['body'], 'window.print()') && str_contains(file_get_contents(BASE_PATH . '/public/assets/css/plastilink.css'), '@media print'));

// ---------------------------------------------------------------- scoping
check('Hub B manager gets 404 for a hub A collection and receipt', req($cl['hmB'], 'GET', "/collections/$c1")['status'] === 404 && req($cl['hmB'], 'GET', "/receipts/$rid")['status'] === 404);
$ref1 = (string) $pdo->query("SELECT reference FROM collection_transactions WHERE id = $c1")->fetchColumn();
$ref2 = (string) $pdo->query("SELECT reference FROM collection_transactions WHERE id = $c2")->fetchColumn();
$listA = req($cl['hoA'], 'GET', '/collections')['body'];
check('Hub A list shows hub A collections only', str_contains($listA, $ref1) && !str_contains($listA, $ref2));
$listB = req($cl['hoB'], 'GET', '/collections')['body'];
check('Hub B list shows hub B collections only', str_contains($listB, $ref2) && !str_contains($listB, $ref1));
$listSA = req($cl['sa'], 'GET', '/collections?q=P4')['body'];
check('Super admin and project manager see all hubs', str_contains($listSA, $ref1) && str_contains($listSA, $ref2) && req($cl['pm'], 'GET', "/collections/$c2")['status'] === 200);
check('Picker sees own collection and receipt', req($cl['pkA'], 'GET', "/collections/$c1")['status'] === 200 && req($cl['pkA'], 'GET', "/receipts/$rid")['status'] === 200);
check("Picker cannot see another picker's collection or receipt (404)", req($cl['pkB'], 'GET', "/collections/$c1")['status'] === 404 && req($cl['pkB'], 'GET', "/receipts/$rid")['status'] === 404);
$pl = req($cl['pkA'], 'GET', '/collections')['body'];
check("Picker's list shows only their own collections", str_contains($pl, $ref1) && !str_contains($pl, $ref2) && str_contains($pl, 'My collections'));
check('Picker value card is labelled "not yet paid"', str_contains($pl, 'not yet paid'));
check('Picker dashboard shows own totals from real records', str_contains(req($cl['pkA'], 'GET', '/picker')['body'], 'UGX 18,000'));
check('Picker approve/reverse attempts are 403', postVia($cl['pkA'], '/collections', "/collections/$c1/approve", [])['status'] === 403 && postVia($cl['pkA'], '/collections', "/collections/$c1/reverse", ['reason' => 'nope nope'])['status'] === 403);

// ---------------------------------------------------------------- filters
$st = req($cl['sa'], 'GET', '/collections?q=P4&status=approved')['body'];
check('Status filter', str_contains($st, $ref1) && !str_contains($st, $ref2));
$today = Dates::todayYmd();
check('Date filter: today includes, a future date excludes', str_contains(req($cl['sa'], 'GET', "/collections?q=P4&from=$today&to=$today")['body'], $ref1) && !str_contains(req($cl['sa'], 'GET', '/collections?q=P4&from=2999-01-01')['body'], $ref1));
check('Garbage dates are ignored safely', req($cl['sa'], 'GET', '/collections?from=notadate&to=2026-13-45')['status'] === 200);
check('Search by reference', str_contains(req($cl['sa'], 'GET', '/collections?q=' . urlencode($ref1))['body'], $ref1));
check('Day range uses Africa/Kampala (UTC+3)', Dates::dayRangeUtc('2026-01-02') === ['2026-01-01 21:00:00', '2026-01-02 21:00:00']);

// ---------------------------------------------------------------- reversal
check('Hub manager cannot reverse (POST 403)', postVia($cl['hmA'], "/collections/$c1", "/collections/$c1/reverse", ['reason' => 'a good reason'])['status'] === 403);
$r = postVia($cl['pm'], "/collections/$c1", "/collections/$c1/reverse", ['reason' => 'x']);
check('Reversal needs a clear reason', str_contains(strip_tags(follow($cl['pm'], $r)['body']), 'at least 5'));
$r = postVia($cl['pm'], "/collections/$c3", "/collections/$c3/reverse", ['reason' => 'Trying a pending one']);
check('Only approved collections can be reversed', str_contains(strip_tags(follow($cl['pm'], $r)['body']), 'Only approved'));
postVia($cl['pm'], "/collections/$c1", "/collections/$c1/reverse", ['reason' => 'Wrong picker was selected']);
$row = $pdo->query("SELECT status, reversed_by, reversal_reason FROM collection_transactions WHERE id = $c1")->fetch();
check('Reversal recorded (status, who, why); record is not deleted', $row['status'] === 'reversed' && (int) $row['reversed_by'] === $ids['pm'] && $row['reversal_reason'] === 'Wrong picker was selected');
check('Receipt voided with reason', $pdo->query("SELECT voided_at IS NOT NULL AND void_reason = 'Wrong picker was selected' FROM receipts WHERE id = $rid")->fetchColumn() == 1 && str_contains(req($cl['pkA'], 'GET', "/receipts/$rid")['body'], 'VOID'));
$r = postVia($cl['pm'], "/collections/$c1", "/collections/$c1/reverse", ['reason' => 'reverse again please']);
check('A reversed collection cannot be reversed or approved again', str_contains(strip_tags(follow($cl['pm'], $r)['body']), 'Only approved') && str_contains(strip_tags(follow($cl['hmA'], postVia($cl['hmA'], "/collections/$c1", "/collections/$c1/approve", []))['body']), 'Only pending'));
check('Reversal is audited with the reason', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'collection.reversed' AND entity_id = $c1 AND details LIKE '%Wrong picker%'")->fetchColumn() === 1);
check('Reversed value drops out of the picker totals (approved value is now 0)', Collection::summary(null, $pkA['uid'])['value'] === 0 && (int) Collection::summary(null, $pkA['uid'])['approved'] === 0);

// ---------------------------------------------------------------- summaries come from real records
[$from, $to] = Dates::dayRangeUtc($today);
$hubScopeA = [$hubA];
$sum = Collection::summary($hubScopeA, null, $from, $to);
$db = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(CASE WHEN status='approved' THEN total_value_ugx END),0) v, COALESCE(SUM(CASE WHEN status='approved' THEN net_weight_kg END),0) kg
                   FROM collection_transactions WHERE hub_id = $hubA AND recorded_at >= '$from' AND recorded_at < '$to'")->fetch();
check('Hub summary equals a direct database query (recorded, value, kg)', $sum['recorded'] === (int) $db['c'] && $sum['value'] === (int) $db['v'] && (float) $sum['kg'] === (float) $db['kg'], json_encode([$sum, $db]));
check('Hub dashboard renders the real "recorded today" count', str_contains(req($cl['hmA'], 'GET', '/hub')['body'], 'Collections recorded today') && str_contains(req($cl['sa'], 'GET', '/admin')['body'], 'Total collected'));

// ---------------------------------------------------------------- XSS + navigation + audit
$r = postVia($cl['hoA'], '/collections/new', '/collections', ['token' => bin2hex(random_bytes(16)), 'picker_id' => $pkA['pid'], 'hub_id' => $hubA, 'plastic_category_id' => $catId, 'gross_weight_kg' => '2', 'notes' => '<script>alert(1)</script>']);
$xss = follow($cl['hoA'], $r)['body'];
check('Notes are escaped (XSS)', str_contains($xss, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($xss, '<script>alert(1)'));
check('Sidebar: picker sees "My collections", operator sees Collections and Prices', str_contains(req($cl['pkA'], 'GET', '/picker')['body'], 'My collections') && str_contains(req($cl['hoA'], 'GET', '/hub')['body'], '>Prices<'));
foreach (['collection.recorded', 'collection.reweighed', 'collection.approved', 'collection.rejected', 'collection.reversed', 'price.set'] as $a) {
    check("Audit logged: $a", (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = '$a'")->fetchColumn() >= 1);
}

// ---------------------------------------------------------------- teardown
$cleanup();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
