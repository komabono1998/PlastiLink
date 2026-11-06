<?php
declare(strict_types=1);

/**
 * Phase 6 tests: marketplace, offers, negotiation, purchase orders, reservation, fulfilment, scoping.
 *   php tests/phase6_marketplace.php [base-url]
 * Creates temporary data (phase6test.*, "P6TEST" hubs) and removes it afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/_http.php';

use App\Core\Database;
use App\Helpers\Dates;
use App\Helpers\Weight;
use App\Models\Hub;
use App\Models\Price;
use App\Models\PurchaseOrder;
use App\Models\Recycler;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\OrderService;
use App\Services\PickerService;
use App\Services\RecyclerService;

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase6Test!pw';
$pass = 0;
$fail = 0;

$cleanup = function () use ($pdo): void {
    $ids = fn(string $sql): string => implode(',', array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    $hub = $ids("SELECT id FROM collection_hubs WHERE name LIKE 'P6TEST%'");
    $usr = $ids("SELECT id FROM users WHERE email LIKE 'phase6test.%' OR phone_e164 LIKE '+256700055%'");
    $ord = $ids("SELECT id FROM purchase_orders WHERE hub_id IN ($hub)");
    $dsp = $ids("SELECT id FROM stock_dispatches WHERE hub_id IN ($hub)");
    $col = $ids("SELECT id FROM collection_transactions WHERE hub_id IN ($hub) OR recorded_by IN ($usr)");
    $prc = $ids("SELECT id FROM price_lists WHERE hub_id IN ($hub) OR note LIKE 'P6TEST%'");
    $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($usr) OR (entity_type = 'order' AND entity_id IN ($ord)) OR (entity_type = 'dispatch' AND entity_id IN ($dsp))
                OR (entity_type = 'collection' AND entity_id IN ($col)) OR (entity_type = 'price' AND entity_id IN ($prc)) OR (entity_type = 'user' AND entity_id IN ($usr))");
    $pdo->exec("DELETE FROM stock_movements WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM stock_dispatches WHERE id IN ($dsp)");
    $pdo->exec("DELETE FROM order_offers WHERE order_id IN ($ord)");
    $pdo->exec("DELETE FROM purchase_order_items WHERE order_id IN ($ord)");
    $pdo->exec("DELETE FROM purchase_orders WHERE id IN ($ord)");
    $pdo->exec("DELETE FROM inventory WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM receipts WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM weighing_records WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM collection_transactions WHERE id IN ($col)");
    $pdo->exec("DELETE FROM price_lists WHERE id IN ($prc)");
    $pdo->exec("DELETE FROM pickers WHERE user_id IN ($usr)");
    $pdo->exec("DELETE FROM recyclers WHERE user_id IN ($usr)");
    $pdo->exec("DELETE FROM collection_hubs WHERE id IN ($hub)");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase6test%'");
    $pdo->exec("DELETE FROM users WHERE id IN ($usr)");
};
$cleanup();

// ---------------------------------------------------------------- pure arithmetic
check('Offer total: 12.5 kg x 1,250 = 15,625', Weight::value(1250, 1250) === 15625);
check('Offer total rounds half-up: 3.33 kg x 750 = 2,498', Weight::value(333, 750) === 2498);
check('Offer total: 40 kg x 1,300 = 52,000', Weight::value(4000, 1300) === 52000);

// ---------------------------------------------------------------- setup
$ids = [];
foreach (['super_admin' => 'sa', 'project_manager' => 'pm', 'hub_manager' => 'hmA', 'hub_operator' => 'hoA'] as $role => $k) {
    $ids[$k] = User::create(['role' => $role, 'full_name' => "P6 $k", 'email' => "phase6test.$k@example.test", 'password' => $PW, 'status' => 'active']);
}
$ids['hmA2'] = User::create(['role' => 'hub_manager', 'full_name' => 'P6 hmA2', 'email' => 'phase6test.hma2@example.test', 'password' => $PW, 'status' => 'active']);
$ids['hmB'] = User::create(['role' => 'hub_manager', 'full_name' => 'P6 hmB', 'email' => 'phase6test.hmb@example.test', 'password' => $PW, 'status' => 'active']);
$ids['hoB'] = User::create(['role' => 'hub_operator', 'full_name' => 'P6 hoB', 'email' => 'phase6test.hob@example.test', 'password' => $PW, 'status' => 'active']);
$nbId = (int) $pdo->query('SELECT id FROM neighbourhoods WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$catId = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code = 'PET'")->fetchColumn();
$mkHub = fn(string $n) => Hub::create(['name' => $n, 'neighbourhood_id' => $nbId, 'address_note' => null, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
$hubA = $mkHub('P6TEST Hub A'); $hubB = $mkHub('P6TEST Hub B');
Hub::assign($hubA, $ids['hmA'], 'manager'); Hub::assign($hubA, $ids['hmA2'], 'manager'); Hub::assign($hubA, $ids['hoA'], 'operator');
Hub::assign($hubB, $ids['hmB'], 'manager'); Hub::assign($hubB, $ids['hoB'], 'operator');
foreach ([$hubA, $hubB] as $h) {
    Price::create($catId, $h, 1000, 'P6TEST price', $ids['sa']);
}
$U = function (string $k) use (&$ids): array { return User::findById($ids[$k]); };
$saUser = $U('sa');

$mkRec = function (string $key, string $name, string $phone, string $status = 'approved') use ($saUser, $PW, &$ids): int {
    if ($status === 'approved') {
        $r = RecyclerService::registerByStaff(['business_name' => $name, 'contact_person' => "$name contact", 'email' => "phase6test.$key@example.test", 'phone' => $phone, 'address' => 'Gulu', 'consent' => '1'], $saUser);
        $rec = Recycler::find((int) $r['id']);
        User::updatePassword((int) $rec['user_id'], $PW);
        $ids[$key] = (int) $rec['user_id'];
        return (int) $r['id'];
    }
    $uid = User::create(['role' => 'recycler', 'full_name' => "$name contact", 'email' => "phase6test.$key@example.test", 'password' => $PW, 'status' => 'pending']);
    $ids[$key] = $uid;
    return Recycler::create(['user_id' => $uid, 'business_name' => $name, 'registration_number' => null, 'contact_person' => 'x', 'address' => 'x', 'materials' => 'PET', 'status' => 'pending']);
};
$rec1 = $mkRec('r1', 'P6TEST Recycler One', '0700055101');
$rec2 = $mkRec('r2', 'P6TEST Recycler Two', '0700055102');
$rec3 = $mkRec('r3', 'P6TEST Pending Co', '0700055103', 'pending');
$rec4 = $mkRec('r4', 'P6TEST Recycler Four', '0700055104');

$mkPicker = function (string $name, string $phone, int $hub) use ($saUser, $nbId): int {
    return (int) PickerService::registerByStaff(['full_name' => $name, 'phone' => $phone, 'neighbourhood_id' => $nbId, 'preferred_hub_id' => $hub, 'consent' => '1'], $saUser)['id'];
};
$pkA = $mkPicker('P6 Picker A', '0700055201', $hubA);
$pkB = $mkPicker('P6 Picker B', '0700055202', $hubB);
$tok = fn() => bin2hex(random_bytes(16));
$mkCol = function (int $hub, int $picker, string $gross, string $rec, string $appr) use ($U, $catId, $tok): int {
    $r = CollectionService::record(['token' => $tok(), 'picker_id' => $picker, 'hub_id' => $hub, 'plastic_category_id' => $catId, 'gross_weight_kg' => $gross, 'tare_weight_kg' => ''], $U($rec));
    return CollectionService::approve((int) $r['id'], $U($appr))['error'] === null ? (int) $r['id'] : 0;
};
$mkCol($hubA, $pkA, '60', 'hoA', 'hmA'); $mkCol($hubA, $pkA, '40', 'hoA', 'hmA'); $mkCol($hubB, $pkB, '30', 'hoB', 'hmB');
$lvl = fn(int $h) => (string) $pdo->query("SELECT on_hand_kg FROM inventory WHERE hub_id = $h")->fetchColumn();
$res = fn(int $h) => (string) $pdo->query("SELECT reserved_kg FROM inventory WHERE hub_id = $h")->fetchColumn();
check('Setup stock: hub A 100 kg, hub B 30 kg, nothing reserved', $lvl($hubA) === '100.00' && $lvl($hubB) === '30.00' && $res($hubA) === '0.00');

$cl = [];
foreach (['sa' => 'sa', 'pm' => 'pm', 'hmA' => 'hma', 'hmA2' => 'hma2', 'hoA' => 'hoa', 'hmB' => 'hmb', 'r1' => 'r1', 'r2' => 'r2', 'r4' => 'r4'] as $k => $e) {
    $cl[$k] = newClient();
    $r = login($cl[$k], "phase6test.$e@example.test", $PW);
    check("Setup: $k signs in", $r['status'] === 303 && loc($r) !== '/login', 'loc ' . loc($r));
}
$c3 = newClient();
check('A pending recycler cannot sign in', str_contains(follow($c3, login($c3, 'phase6test.r3@example.test', $PW))['body'], 'waiting for approval'));
$e = OrderService::create(['token' => $tok(), 'hub_id' => $hubA, 'plastic_category_id' => $catId, 'quantity_kg' => '1', 'price_per_kg_ugx' => '1000'], $U('r3'));
check('A pending recycler cannot place offers even through the service', $e['error'] !== null && str_contains($e['error'], 'approved recycling companies'));
$e = OrderService::create(['token' => $tok(), 'hub_id' => $hubA, 'plastic_category_id' => $catId, 'quantity_kg' => '1', 'price_per_kg_ugx' => '1000'], $saUser);
check('Administrators cannot place offers as a recycler', $e['error'] !== null);

// ---------------------------------------------------------------- guests / CSRF / RBAC
$g = newClient();
foreach (['/marketplace', '/orders', '/orders/new', '/orders/1'] as $p) {
    $r = req($g, 'GET', $p);
    check("Guest -> $p redirects to /login", $r['status'] === 303 && loc($r) === '/login');
}
foreach (['/orders', '/orders/1/accept', '/orders/1/counter', '/orders/1/dispatch', '/orders/1/receive/1'] as $p) {
    check("POST $p without CSRF token refused", req($cl['r1'], 'POST', $p, ['x' => '1'])['status'] === 400);
}
$matrix = [
    'r1'  => ['/marketplace' => 200, '/orders' => 200, '/orders/new' => 200, '/recycler' => 200],
    'hmA' => ['/orders' => 200, '/marketplace' => 403, '/orders/new' => 403],
    'pm'  => ['/orders' => 200, '/marketplace' => 403, '/orders/new' => 403],
    'sa'  => ['/orders' => 200, '/marketplace' => 403, '/orders/new' => 403],
    'hoA' => ['/orders' => 403, '/marketplace' => 403, '/orders/new' => 403],
];
foreach ($matrix as $who => $paths) {
    foreach ($paths as $path => $expected) {
        check("RBAC: $who GET $path = $expected", req($cl[$who], 'GET', $path)['status'] === $expected);
    }
}

// ---------------------------------------------------------------- marketplace browse
$mp = req($cl['r1'], 'GET', '/marketplace')['body'];
check('Marketplace lists available plastic per hub (A 100 kg, B 30 kg)', str_contains($mp, 'P6TEST Hub A') && str_contains($mp, '100 kg') && str_contains($mp, 'P6TEST Hub B') && str_contains($mp, '30 kg'));
check('Marketplace shows no other recycler or order information', !str_contains($mp, 'P6TEST Recycler Two') && !str_contains($mp, 'PO-'));

// ---------------------------------------------------------------- creating an offer
$tomorrow = (new DateTimeImmutable(Dates::todayYmd()))->modify('+1 day')->format('Y-m-d');
$offer = fn(string $who, array $over = []) => postVia($cl[$who], '/orders/new', '/orders', $over + ['token' => $tok(), 'hub_id' => $hubA, 'plastic_category_id' => $catId, 'quantity_kg' => '40', 'price_per_kg_ugx' => '1200', 'delivery_date' => $tomorrow, 'note' => '']);
$txt = fn(string $who, array $r) => strip_tags(follow($cl[$who], $r)['body']);
check('Offer: no hub chosen', str_contains($txt('r1', $offer('r1', ['hub_id' => ''])), 'Choose a hub'));
check('Offer: zero quantity', str_contains($txt('r1', $offer('r1', ['quantity_kg' => '0'])), 'more than zero'));
check('Offer: quantity above what is available', str_contains($txt('r1', $offer('r1', ['quantity_kg' => '101'])), 'Only 100 kg is available'));
check('Offer: zero price', str_contains($txt('r1', $offer('r1', ['price_per_kg_ugx' => '0'])), 'between 1 and'));
check('Offer: text price', str_contains($txt('r1', $offer('r1', ['price_per_kg_ugx' => 'cheap'])), 'whole number'));
check('Offer: absurd price', str_contains($txt('r1', $offer('r1', ['price_per_kg_ugx' => '99999999'])), 'between 1 and'));
check('Offer: date in the past', str_contains($txt('r1', $offer('r1', ['delivery_date' => '2020-01-01'])), 'cannot be in the past'));
check('Offer: invalid date', str_contains($txt('r1', $offer('r1', ['delivery_date' => '2026-02-30'])), 'valid date'));
check('Offer: date too far away', str_contains($txt('r1', $offer('r1', ['delivery_date' => '2099-01-01'])), 'within the next 180 days'));
check('No failed attempt created an order', (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE hub_id IN ($hubA, $hubB)")->fetchColumn() === 0);

$t1 = $tok();
$r = $offer('r1', ['token' => $t1, 'note' => '<script>alert(1)</script> bulk buy']);
check('Valid offer creates a negotiation', preg_match('#^/orders/(\d+)$#', loc($r), $m) === 1, 'loc ' . loc($r));
$o1 = (int) ($m[1] ?? 0);
$row = $pdo->query("SELECT * FROM purchase_orders WHERE id = $o1")->fetch();
check('Order: PO reference, negotiating, recycler turn done, round 1', preg_match('/^PO-\d{8}-\d{6}$/', (string) $row['reference']) === 1 && $row['status'] === 'negotiating' && $row['last_offer_by'] === 'recycler' && (int) $row['round_count'] === 1 && (int) $row['recycler_id'] === $rec1);
$hrs = (strtotime($row['expires_at'] . ' UTC') - time()) / 3600;
check('Offer expires in about 72 hours', $hrs > 71 && $hrs < 73, (string) $hrs);
$of = $pdo->query("SELECT * FROM order_offers WHERE order_id = $o1")->fetch();
check('Round 1 offer: 40.00 kg x 1,200 = 48,000 UGX, open', $of['qty_kg'] === '40.00' && (int) $of['price_per_kg_ugx'] === 1200 && (int) $of['total_ugx'] === 48000 && $of['status'] === 'open' && $of['offered_by_side'] === 'recycler');
check('Nothing is reserved while negotiating', $res($hubA) === '0.00');
$r2 = req($cl['r1'], 'POST', '/orders', ['_csrf' => csrfFrom(req($cl['r1'], 'GET', '/orders/new')['body']), 'token' => $t1, 'hub_id' => $hubA, 'plastic_category_id' => $catId, 'quantity_kg' => '40', 'price_per_kg_ugx' => '1200']);
check('Double submit does not create a second order', loc($r2) === "/orders/$o1" && (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE submission_token = '$t1'")->fetchColumn() === 1);
$page = req($cl['r1'], 'GET', "/orders/$o1")['body'];
check('Note text is escaped (XSS)', str_contains($page, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($page, '<script>alert(1)'));

// ---------------------------------------------------------------- who can see an order
check("Another recycler gets 404 for someone else's order", req($cl['r2'], 'GET', "/orders/$o1")['status'] === 404);
check('Hub B manager gets 404 for a hub A order; hub A manager and administrators can open it', req($cl['hmB'], 'GET', "/orders/$o1")['status'] === 404
    && req($cl['hmA'], 'GET', "/orders/$o1")['status'] === 200 && req($cl['sa'], 'GET', "/orders/$o1")['status'] === 200 && req($cl['pm'], 'GET', "/orders/$o1")['status'] === 200);
$ref1 = (string) $row['reference'];
check('Lists are scoped: owner and hub A see it; recycler Two and hub B do not', str_contains(req($cl['r1'], 'GET', '/orders')['body'], $ref1) && str_contains(req($cl['hmA'], 'GET', '/orders')['body'], $ref1)
    && !str_contains(req($cl['r2'], 'GET', '/orders')['body'], $ref1) && !str_contains(req($cl['hmB'], 'GET', '/orders')['body'], $ref1));
check('Hub A manager sees the request waiting; page offers accept and counter', str_contains(req($cl['hmA'], 'GET', "/orders/$o1")['body'], 'Make a counter-offer') && str_contains(req($cl['hmA'], 'GET', "/orders/$o1")['body'], 'Accept 40 kg'));
check('The recycler cannot act as the hub (counter/accept/schedule/dispatch denied)',
    str_contains(strip_tags(follow($cl['r1'], postVia($cl['r1'], "/orders/$o1", "/orders/$o1/accept", []))['body']), 'You made the latest offer')
    && str_contains(strip_tags(follow($cl['r1'], postVia($cl['r1'], "/orders/$o1", "/orders/$o1/schedule", ['delivery_date' => $tomorrow]))['body']), 'not found')
    && str_contains(strip_tags(follow($cl['r1'], postVia($cl['r1'], "/orders/$o1", "/orders/$o1/dispatch", ['token' => $tok(), 'quantity_kg' => '5']))['body']), 'permission'));
check('Hub B manager cannot accept a hub A order', str_contains(strip_tags(follow($cl['hmB'], postVia($cl['hmB'], '/orders', "/orders/$o1/accept", []))['body']), 'not found') && $pdo->query("SELECT status FROM purchase_orders WHERE id = $o1")->fetchColumn() === 'negotiating');

// ---------------------------------------------------------------- negotiation
$counter = fn(string $who, int $id, array $over = []) => postVia($cl[$who], "/orders/$id", "/orders/$id/counter", $over + ['quantity_kg' => '40', 'price_per_kg_ugx' => '1400', 'delivery_date' => $tomorrow, 'note' => '']);
check('Counter: quantity above available refused', str_contains($txt('hmA', $counter('hmA', $o1, ['quantity_kg' => '500'])), 'Only 100 kg is available'));
check('Counter: bad price refused', str_contains($txt('hmA', $counter('hmA', $o1, ['price_per_kg_ugx' => '-5'])), 'whole number'));
$counter('hmA', $o1, ['price_per_kg_ugx' => '1400', 'note' => 'Our price is 1,400']);
$row = $pdo->query("SELECT * FROM purchase_orders WHERE id = $o1")->fetch();
check('Hub counter: round 2, last offer by the seller, expiry reset', (int) $row['round_count'] === 2 && $row['last_offer_by'] === 'seller' && $pdo->query("SELECT status FROM order_offers WHERE order_id = $o1 AND round_no = 1")->fetchColumn() === 'superseded');
check('The hub cannot counter its own offer again', str_contains($txt('hmA', $counter('hmA', $o1)), 'You made the latest offer') && (int) $pdo->query("SELECT round_count FROM purchase_orders WHERE id = $o1")->fetchColumn() === 2);
check('Recycler sees it is their turn', str_contains(req($cl['r1'], 'GET', '/orders')['body'], 'Your turn') && str_contains(req($cl['r1'], 'GET', "/orders/$o1")['body'], 'Make a counter-offer'));
check('Recycler dashboard counts the offer waiting for their answer', str_contains(req($cl['r1'], 'GET', '/recycler')['body'], 'Waiting for your answer'));
postVia($cl['r1'], "/orders/$o1", "/orders/$o1/counter", ['quantity_kg' => '40', 'price_per_kg_ugx' => '1300', 'delivery_date' => $tomorrow, 'note' => 'Meet at 1,300']);
check('Recycler counter: round 3 at 1,300', (int) $pdo->query("SELECT round_count FROM purchase_orders WHERE id = $o1")->fetchColumn() === 3 && (int) $pdo->query("SELECT price_per_kg_ugx FROM order_offers WHERE order_id = $o1 AND round_no = 3")->fetchColumn() === 1300);
check('Full negotiation history is kept (3 rounds)', (int) $pdo->query("SELECT COUNT(*) FROM order_offers WHERE order_id = $o1")->fetchColumn() === 3 && str_contains(req($cl['hmA'], 'GET', "/orders/$o1")['body'], 'Meet at 1,300'));

// ---------------------------------------------------------------- acceptance reserves stock; terms locked
$r = postVia($cl['hmA'], "/orders/$o1", "/orders/$o1/accept", []);
check('Hub accepts the recycler\'s latest offer', str_contains(strip_tags(follow($cl['hmA'], $r)['body']), 'now reserved') && $pdo->query("SELECT status FROM purchase_orders WHERE id = $o1")->fetchColumn() === 'confirmed');
$item = $pdo->query("SELECT * FROM purchase_order_items WHERE order_id = $o1")->fetch();
check('Agreed terms locked: 40.00 kg x 1,300 = 52,000 UGX', $item['agreed_qty_kg'] === '40.00' && (int) $item['price_per_kg_ugx'] === 1300 && (int) $item['line_total_ugx'] === 52000 && $item['dispatched_kg'] === '0.00' && $item['received_kg'] === '0.00');
check('Reservation: 40 kg reserved at hub A, on hand unchanged, available 60', $res($hubA) === '40.00' && $lvl($hubA) === '100.00' && (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE hub_id = $hubA AND movement_type = 'reserve' AND source_type = 'order' AND source_id = $o1")->fetchColumn() === 1);
check('Last offer marked accepted; delivery date carried over', $pdo->query("SELECT status FROM order_offers WHERE order_id = $o1 AND round_no = 3")->fetchColumn() === 'accepted' && $pdo->query("SELECT scheduled_for FROM purchase_orders WHERE id = $o1")->fetchColumn() === $tomorrow);
check('After acceptance the terms cannot be negotiated or accepted again', str_contains($txt('hmA', $counter('hmA', $o1)), 'no longer open') && str_contains(strip_tags(follow($cl['hmA'], postVia($cl['hmA'], "/orders/$o1", "/orders/$o1/accept", []))['body']), 'no longer open'));
check('The marketplace now shows only the unreserved 60 kg', str_contains(req($cl['r2'], 'GET', '/marketplace')['body'], '60 kg') && !str_contains(req($cl['r2'], 'GET', '/marketplace')['body'], '100 kg'));
check('Reserved stock appears on the inventory screen', str_contains(req($cl['hmA'], 'GET', '/inventory')['body'], '40 kg'));

// ---------------------------------------------------------------- overselling
check('A second recycler cannot ask for more than the unreserved stock (70 > 60)', str_contains($txt('r2', $offer('r2', ['quantity_kg' => '70'])), 'Only 60 kg is available'));
$r = $offer('r2', ['quantity_kg' => '50', 'price_per_kg_ugx' => '1100']);
preg_match('#^/orders/(\d+)$#', loc($r), $m); $o2 = (int) ($m[1] ?? 0);
postVia($cl['hmA2'], "/orders/$o2", "/orders/$o2/accept", []);
check('Second order accepted: reserved 90 kg, available 10 (another hub A manager can respond)', $res($hubA) === '90.00' && $pdo->query("SELECT status FROM purchase_orders WHERE id = $o2")->fetchColumn() === 'confirmed');
check('Nobody can request more than the remaining 10 kg', str_contains($txt('r1', $offer('r1', ['quantity_kg' => '11'])), 'Only 10 kg is available'));

// race: two recyclers, both want the last 10 kg, accepted at the same instant by two different sellers
$rx = $offer('r1', ['quantity_kg' => '10', 'price_per_kg_ugx' => '1000']); preg_match('#^/orders/(\d+)$#', loc($rx), $m); $ox = (int) ($m[1] ?? 0);
$ry = $offer('r2', ['quantity_kg' => '10', 'price_per_kg_ugx' => '1000']); preg_match('#^/orders/(\d+)$#', loc($ry), $m); $oy = (int) ($m[1] ?? 0);
$jobs = [['c' => $cl['hmA'], 'path' => "/orders/$ox/accept", 'csrf' => csrfFrom(req($cl['hmA'], 'GET', "/orders/$ox")['body'])],
         ['c' => $cl['sa'], 'path' => "/orders/$oy/accept", 'csrf' => csrfFrom(req($cl['sa'], 'GET', "/orders/$oy")['body'])]];
$mh = curl_multi_init(); $hs = [];
foreach ($jobs as $j) {
    $ch = curl_init($base . $j['path']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['_csrf' => $j['csrf']]),
        CURLOPT_COOKIEFILE => $j['c']['jar'], CURLOPT_COOKIEJAR => $j['c']['jar'], CURLOPT_TIMEOUT => 30]);
    curl_multi_add_handle($mh, $ch);
    $hs[] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.2); } while ($running > 0);
foreach ($hs as $ch) { curl_multi_remove_handle($mh, $ch); }
curl_multi_close($mh);
$st = [$pdo->query("SELECT status FROM purchase_orders WHERE id = $ox")->fetchColumn(), $pdo->query("SELECT status FROM purchase_orders WHERE id = $oy")->fetchColumn()];
sort($st);
check('Race: exactly ONE of two simultaneous acceptances for the last 10 kg succeeds', $st === ['confirmed', 'negotiating'], json_encode($st));
check('Race: reserved is exactly 100 (never above stock on hand), ledger agrees', $res($hubA) === '100.00' && $lvl($hubA) === '100.00' && StockBalance::mismatches() === []);
$oWin = $st && $pdo->query("SELECT status FROM purchase_orders WHERE id = $ox")->fetchColumn() === 'confirmed' ? $ox : $oy;
$oLose = $oWin === $ox ? $oy : $ox;
$loserSide = $oLose === $ox ? 'hmA' : 'sa';
$r = postVia($cl[$loserSide], "/orders/$oLose", "/orders/$oLose/accept", []);
check('The loser is told the plastic is no longer available and stays negotiating', str_contains(strip_tags(follow($cl[$loserSide], $r)['body']), 'no longer available') && $pdo->query("SELECT status FROM purchase_orders WHERE id = $oLose")->fetchColumn() === 'negotiating');
$loserOwner = $oLose === $ox ? 'r1' : 'r2';
postVia($cl[$loserOwner], "/orders/$oLose", "/orders/$oLose/end", ['reason' => 'Not needed any more']);
check('Recycler withdraws the losing offer', $pdo->query("SELECT status FROM purchase_orders WHERE id = $oLose")->fetchColumn() === 'withdrawn' && $pdo->query("SELECT status FROM order_offers WHERE order_id = $oLose AND round_no = 1")->fetchColumn() === 'withdrawn');
check('A withdrawn negotiation cannot be countered', str_contains($txt('hmA', $counter('hmA', $oLose)), 'no longer open'));

// ---------------------------------------------------------------- reject, cancel confirmed
$r = $offer('r1', ['hub_id' => $hubB, 'quantity_kg' => '5']); preg_match('#^/orders/(\d+)$#', loc($r), $m); $oRej = (int) ($m[1] ?? 0);
check('Rejecting needs a reason', str_contains(strip_tags(follow($cl['hmB'], postVia($cl['hmB'], "/orders/$oRej", "/orders/$oRej/end", ['reason' => '']))['body']), 'reason'));
postVia($cl['hmB'], "/orders/$oRej", "/orders/$oRej/end", ['reason' => 'Stock promised elsewhere']);
check('Hub B rejects: order rejected with the reason, stock untouched', $pdo->query("SELECT status FROM purchase_orders WHERE id = $oRej")->fetchColumn() === 'rejected' && $pdo->query("SELECT end_note FROM purchase_orders WHERE id = $oRej")->fetchColumn() === 'Stock promised elsewhere' && $res($hubB) === '0.00');

$resBefore = $res($hubA);
check('Cancel needs a reason', str_contains(strip_tags(follow($cl[$oWin === $ox ? 'r1' : 'r2'], postVia($cl[$oWin === $ox ? 'r1' : 'r2'], "/orders/$oWin", "/orders/$oWin/cancel", ['reason' => '']))['body']), 'reason'));
$winOwner = $oWin === $ox ? 'r1' : 'r2';
check("Another recycler cannot cancel someone else's order", str_contains(strip_tags(follow($cl['r4'], postVia($cl['r4'], '/orders', "/orders/$oWin/cancel", ['reason' => 'sneaky cancel']))['body']), 'not found') && $pdo->query("SELECT status FROM purchase_orders WHERE id = $oWin")->fetchColumn() === 'confirmed');
postVia($cl[$winOwner], "/orders/$oWin", "/orders/$oWin/cancel", ['reason' => 'We found another supplier']);
check('Recycler cancels a confirmed order with nothing dispatched: reservation released (100 -> 90)', $pdo->query("SELECT status FROM purchase_orders WHERE id = $oWin")->fetchColumn() === 'cancelled' && $res($hubA) === '90.00' && $resBefore === '100.00'
    && (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE source_type = 'order' AND source_id = $oWin AND movement_type = 'release'")->fetchColumn() === 1);

// ---------------------------------------------------------------- scheduling
check('Schedule: past date refused', str_contains($txt('hmA', postVia($cl['hmA'], "/orders/$o1", "/orders/$o1/schedule", ['delivery_date' => '2020-01-01'])), 'cannot be in the past'));
$later = (new DateTimeImmutable(Dates::todayYmd()))->modify('+5 days')->format('Y-m-d');
postVia($cl['hmA'], "/orders/$o1", "/orders/$o1/schedule", ['delivery_date' => $later, 'note' => 'Morning, gate 2']);
check('Hub schedules the delivery; the recycler sees it', $pdo->query("SELECT scheduled_for FROM purchase_orders WHERE id = $o1")->fetchColumn() === $later && str_contains(req($cl['r1'], 'GET', "/orders/$o1")['body'], 'Morning, gate 2'));

// ---------------------------------------------------------------- fulfilment: dispatch against an order
$dsp = fn(string $who, int $id, array $over = []) => postVia($cl[$who], "/orders/$id", "/orders/$id/dispatch", $over + ['token' => $tok(), 'quantity_kg' => '15', 'vehicle' => 'UAB 777Z', 'driver' => 'Opio']);
check('Operators cannot dispatch against an order (no access to orders)', req($cl['hoA'], 'POST', "/orders/$o1/dispatch", ['x' => '1'])['status'] === 400 && postVia($cl['hoA'], '/inventory', "/orders/$o1/dispatch", ['x' => '1'])['status'] === 403);
check('Dispatch above what remains is refused', str_contains($txt('hmA', $dsp('hmA', $o1, ['quantity_kg' => '41'])), 'Only 40 kg of this order is still to be dispatched'));
check('Dispatch: text weight refused', str_contains($txt('hmA', $dsp('hmA', $o1, ['quantity_kg' => 'lots'])), 'must be a number'));
$dk = $tok();
$r = $dsp('hmA', $o1, ['token' => $dk]);
$d1 = (int) $pdo->query("SELECT id FROM stock_dispatches WHERE purchase_order_id = $o1 ORDER BY id LIMIT 1")->fetchColumn();
check('Dispatch of 15 kg against the order created', $d1 > 0 && $pdo->query("SELECT qty_kg FROM stock_dispatches WHERE id = $d1")->fetchColumn() === '15.00' && (int) $pdo->query("SELECT recycler_id FROM stock_dispatches WHERE id = $d1")->fetchColumn() === $rec1);
check('Stock out AND reservation consumed together: on hand 85, reserved 75', $lvl($hubA) === '85.00' && $res($hubA) === '75.00');
$mv = $pdo->query("SELECT qty_kg, reserved_delta_kg, balance_after_kg, reserved_after_kg FROM stock_movements WHERE source_type = 'dispatch' AND source_id = $d1 AND movement_type = 'dispatch_out'")->fetch();
check('One ledger row: -15.00 on hand and -15.00 reserved', $mv && $mv['qty_kg'] === '-15.00' && $mv['reserved_delta_kg'] === '-15.00' && $mv['balance_after_kg'] === '85.00' && $mv['reserved_after_kg'] === '75.00');
$row = $pdo->query("SELECT status FROM purchase_orders WHERE id = $o1")->fetch();
check('Order moves to in_delivery; item dispatched 15.00', $row['status'] === 'in_delivery' && $pdo->query("SELECT dispatched_kg FROM purchase_order_items WHERE order_id = $o1")->fetchColumn() === '15.00');
$r2 = req($cl['hmA'], 'POST', "/orders/$o1/dispatch", ['_csrf' => csrfFrom(req($cl['hmA'], 'GET', "/orders/$o1")['body']), 'token' => $dk, 'quantity_kg' => '15']);
check('Double submit dispatches nothing twice', $lvl($hubA) === '85.00' && (int) $pdo->query("SELECT COUNT(*) FROM stock_dispatches WHERE purchase_order_id = $o1")->fetchColumn() === 1);
check('Cancelling the order is no longer possible once a load has left', str_contains(strip_tags(follow($cl['hmA'], postVia($cl['hmA'], "/orders/$o1", "/orders/$o1/cancel", ['reason' => 'trying to cancel']))['body']), 'nothing dispatched') && $res($hubA) === '75.00');
$r = postVia($cl['hmA'], "/inventory/dispatches/$d1", "/inventory/dispatches/$d1/delivered", ['note' => 'forced']);
check('The hub cannot mark an order load delivered (only the recycler confirms)', str_contains(strip_tags(follow($cl['hmA'], $r)['body']), 'confirmed by the recycler') && $pdo->query("SELECT status FROM stock_dispatches WHERE id = $d1")->fetchColumn() === 'dispatched');

// cancel that dispatch from the Phase 5 screen: stock AND reservation come back, order counters roll back
postVia($cl['hmA'], "/inventory/dispatches/$d1", "/inventory/dispatches/$d1/cancel", ['reason' => 'Truck broke down']);
check('Cancelling an order load restores stock (100) and the reservation (90) and rolls the order back', $lvl($hubA) === '100.00' && $res($hubA) === '90.00'
    && $pdo->query("SELECT dispatched_kg FROM purchase_order_items WHERE order_id = $o1")->fetchColumn() === '0.00' && $pdo->query("SELECT status FROM purchase_orders WHERE id = $o1")->fetchColumn() === 'confirmed'
    && StockBalance::mismatches() === []);
$dsp('hmA', $o1, ['quantity_kg' => '15']);
$d2 = (int) $pdo->query("SELECT id FROM stock_dispatches WHERE purchase_order_id = $o1 AND status = 'dispatched' ORDER BY id DESC LIMIT 1")->fetchColumn();

// ---------------------------------------------------------------- recycler confirms receipt
$rcv = fn(string $who, int $id, int $d, array $data) => postVia($cl[$who], "/orders/$id", "/orders/$id/receive/$d", $data);
check("Another recycler cannot confirm someone else's load", str_contains(strip_tags(follow($cl['r2'], postVia($cl['r2'], '/orders', "/orders/$o1/receive/$d2", ['received_kg' => '15']))['body']), 'not found') && $pdo->query("SELECT status FROM stock_dispatches WHERE id = $d2")->fetchColumn() === 'dispatched');
check('The hub cannot confirm receipt on the recycler\'s behalf', str_contains(strip_tags(follow($cl['hmA'], $rcv('hmA', $o1, $d2, ['received_kg' => '15']))['body']), 'not found'));
check('Receiving more than was dispatched is refused', str_contains(strip_tags(follow($cl['r1'], $rcv('r1', $o1, $d2, ['received_kg' => '16']))['body']), 'cannot be more than'));
check('Receiving less without a note is refused', str_contains(strip_tags(follow($cl['r1'], $rcv('r1', $o1, $d2, ['received_kg' => '14', 'note' => '']))['body']), 'Say what happened'));
check('Zero or text weight refused', str_contains(strip_tags(follow($cl['r1'], $rcv('r1', $o1, $d2, ['received_kg' => '0']))['body']), 'more than zero') && str_contains(strip_tags(follow($cl['r1'], $rcv('r1', $o1, $d2, ['received_kg' => 'x']))['body']), 'must be a number'));
$rcv('r1', $o1, $d2, ['received_kg' => '14', 'note' => 'One sack was wet']);
$drow = $pdo->query("SELECT status, received_kg, receive_note, delivered_by FROM stock_dispatches WHERE id = $d2")->fetch();
check('Recycler confirms 14 of 15 kg: load delivered, note and who stored', $drow['status'] === 'delivered' && $drow['received_kg'] === '14.00' && $drow['receive_note'] === 'One sack was wet' && (int) $drow['delivered_by'] === $ids['r1']);
check('Item received 14.00; order still in delivery', $pdo->query("SELECT received_kg FROM purchase_order_items WHERE order_id = $o1")->fetchColumn() === '14.00' && $pdo->query("SELECT status FROM purchase_orders WHERE id = $o1")->fetchColumn() === 'in_delivery');
check('A confirmed load cannot be confirmed twice or cancelled', str_contains(strip_tags(follow($cl['r1'], $rcv('r1', $o1, $d2, ['received_kg' => '14']))['body']), 'not waiting')
    && str_contains(strip_tags(follow($cl['hmA'], postVia($cl['hmA'], "/inventory/dispatches/$d2", "/inventory/dispatches/$d2/cancel", ['reason' => 'too late now']))['body']), 'not yet delivered'));
$dsp('hmA', $o1, ['quantity_kg' => '25']);
$d3 = (int) $pdo->query("SELECT id FROM stock_dispatches WHERE purchase_order_id = $o1 AND status = 'dispatched' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('Final 25 kg dispatched: order fully dispatched (15 + 25 = 40), reservation for it used up', $pdo->query("SELECT dispatched_kg FROM purchase_order_items WHERE order_id = $o1")->fetchColumn() === '40.00' && $res($hubA) === '50.00' && $lvl($hubA) === '60.00');
check('Order is not complete until the recycler confirms the last load', $pdo->query("SELECT status FROM purchase_orders WHERE id = $o1")->fetchColumn() === 'in_delivery');
$r = $rcv('r1', $o1, $d3, ['received_kg' => '25']);
check('Recycler confirms the last load: order COMPLETED', str_contains(strip_tags(follow($cl['r1'], $r)['body']), 'now complete') && $pdo->query("SELECT status FROM purchase_orders WHERE id = $o1")->fetchColumn() === 'completed' && $pdo->query("SELECT completed_at FROM purchase_orders WHERE id = $o1")->fetchColumn() !== null);
check('Confirmed received 39 kg of the agreed 40 (billing will use 39)', $pdo->query("SELECT received_kg FROM purchase_order_items WHERE order_id = $o1")->fetchColumn() === '39.00');
check('A completed order accepts no further dispatches', str_contains($txt('hmA', $dsp('hmA', $o1, ['quantity_kg' => '1'])), 'not open for dispatch') && $lvl($hubA) === '60.00');

// ---------------------------------------------------------------- close short (order 2: 50 kg agreed)
$dsp('hmA2', $o2, ['quantity_kg' => '20']);
$d4 = (int) $pdo->query("SELECT id FROM stock_dispatches WHERE purchase_order_id = $o2 ORDER BY id DESC LIMIT 1")->fetchColumn();
check('Order 2: 20 kg dispatched, in delivery, reserved 30 left for it', $pdo->query("SELECT status FROM purchase_orders WHERE id = $o2")->fetchColumn() === 'in_delivery' && $res($hubA) === '30.00' && $lvl($hubA) === '40.00');
check('Close short needs a note', str_contains(strip_tags(follow($cl['hmA2'], postVia($cl['hmA2'], "/orders/$o2", "/orders/$o2/close", ['note' => '']))['body']), 'Say why'));
check('The recycler cannot close an order short', str_contains(strip_tags(follow($cl['r2'], postVia($cl['r2'], "/orders/$o2", "/orders/$o2/close", ['note' => 'I want to close it']))['body']), 'not found') && $pdo->query("SELECT status FROM purchase_orders WHERE id = $o2")->fetchColumn() === 'in_delivery');
$rcv('r2', $o2, $d4, ['received_kg' => '20']);
postVia($cl['hmA2'], "/orders/$o2", "/orders/$o2/close", ['note' => 'Recycler cannot take more this month']);
$row = $pdo->query("SELECT status, short_closed_kg, end_note, ended_by FROM purchase_orders WHERE id = $o2")->fetch();
check('Closed short: completed, 30.00 kg short, note and who stored', $row['status'] === 'completed' && $row['short_closed_kg'] === '30.00' && $row['end_note'] === 'Recycler cannot take more this month' && (int) $row['ended_by'] === $ids['hmA2']);
check('The undelivered 30 kg is released and available again (reserved 0)', $res($hubA) === '0.00' && $lvl($hubA) === '40.00' && str_contains(req($cl['r1'], 'GET', '/marketplace')['body'], '40 kg'));
check('Ledger and balances agree after all fulfilment', StockBalance::mismatches() === []);

// ---------------------------------------------------------------- expiry and rounds
$r = $offer('r4', ['hub_id' => $hubB, 'quantity_kg' => '5']); preg_match('#^/orders/(\d+)$#', loc($r), $m); $oExp = (int) ($m[1] ?? 0);
$pdo->exec("UPDATE purchase_orders SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = $oExp");
$page = req($cl['hmB'], 'GET', "/orders/$oExp")['body'];
check('An unanswered offer past its validity expires when opened', $pdo->query("SELECT status FROM purchase_orders WHERE id = $oExp")->fetchColumn() === 'expired' && $pdo->query("SELECT status FROM order_offers WHERE order_id = $oExp")->fetchColumn() === 'expired' && str_contains($page, 'Expired'));
check('An expired offer cannot be accepted or countered', str_contains(strip_tags(follow($cl['hmB'], postVia($cl['hmB'], "/orders/$oExp", "/orders/$oExp/accept", []))['body']), 'no longer open') && str_contains($txt('hmB', postVia($cl['hmB'], "/orders/$oExp", "/orders/$oExp/counter", ['quantity_kg' => '5', 'price_per_kg_ugx' => '900'])), 'no longer open'));
$r = $offer('r4', ['hub_id' => $hubB, 'quantity_kg' => '5']); preg_match('#^/orders/(\d+)$#', loc($r), $m); $oExp2 = (int) ($m[1] ?? 0);
$pdo->exec("UPDATE purchase_orders SET expires_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE id = $oExp2");
check('The scheduled job expires stale negotiations (php jobs.php expire-orders)', trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/jobs.php') . ' expire-orders')) === 'Expired 1 negotiation(s).' && $pdo->query("SELECT status FROM purchase_orders WHERE id = $oExp2")->fetchColumn() === 'expired');

$r = $offer('r4', ['hub_id' => $hubB, 'quantity_kg' => '5', 'price_per_kg_ugx' => '900']); preg_match('#^/orders/(\d+)$#', loc($r), $m); $oRounds = (int) ($m[1] ?? 0);
$sellerTurn = true;
for ($i = 2; $i <= OrderService::MAX_ROUNDS; $i++) {
    $res2 = OrderService::counter($oRounds, ['quantity_kg' => '5', 'price_per_kg_ugx' => (string) (900 + $i * 10)], $sellerTurn ? $U('hmB') : $U('r4'));
    $sellerTurn = !$sellerTurn;
    if ($res2['error'] || $res2['errors']) { break; }
}
check('Six rounds are allowed', (int) $pdo->query("SELECT round_count FROM purchase_orders WHERE id = $oRounds")->fetchColumn() === 6);
$next = OrderService::counter($oRounds, ['quantity_kg' => '5', 'price_per_kg_ugx' => '999'], $sellerTurn ? $U('hmB') : $U('r4'));
check('A seventh round is refused ("final round")', $next['error'] !== null && str_contains($next['error'], 'final round') && (int) $pdo->query("SELECT round_count FROM purchase_orders WHERE id = $oRounds")->fetchColumn() === 6);
$acc = OrderService::accept($oRounds, $sellerTurn ? $U('hmB') : $U('r4'));
check('In the final round the other side can still accept', $acc['error'] === null && $pdo->query("SELECT status FROM purchase_orders WHERE id = $oRounds")->fetchColumn() === 'confirmed' && $res($hubB) === '5.00');
OrderService::cancel($oRounds, 'Test cleanup', $U('hmB'));

// ---------------------------------------------------------------- open-negotiation limit
$made = 0;
$preexisting = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE recycler_id = $rec4 AND status = 'negotiating'")->fetchColumn();
for ($i = 0; $i < OrderService::MAX_OPEN_PER_RECYCLER + 1; $i++) {
    $x = OrderService::create(['token' => $tok(), 'hub_id' => $hubB, 'plastic_category_id' => $catId, 'quantity_kg' => '0.01', 'price_per_kg_ugx' => '1000'], $U('r4'));
    if ($x['error']) { $limitMsg = $x['error']; break; }
    $made++;
}
check('A recycler is limited to 10 open negotiations (the 11th is refused)', isset($limitMsg) && str_contains($limitMsg, 'open negotiations') && $preexisting + $made === OrderService::MAX_OPEN_PER_RECYCLER
    && (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE recycler_id = $rec4 AND status = 'negotiating'")->fetchColumn() === 10);

// ---------------------------------------------------------------- dashboards and totals come from real records
$st = PurchaseOrder::stats('recycler', $rec1, null);
check('Recycler stats equal the database (received 39 kg, value 50,700 at 1,300/kg)', (float) $st['received_kg'] === 39.0 && $st['received_value'] === 50700 && $st['completed'] === 1);
check('Recycler dashboard shows the confirmed weight', str_contains(req($cl['r1'], 'GET', '/recycler')['body'], '39 kg') && str_contains(req($cl['r1'], 'GET', '/recycler')['body'], 'UGX 50,700'));
check('Hub and admin dashboards show marketplace figures', str_contains(req($cl['sa'], 'GET', '/admin')['body'], 'Marketplace') && str_contains(req($cl['hmA'], 'GET', '/hub')['body'], 'Stock at my hubs'));
check('Sidebar: recycler sees Marketplace and My orders; hub manager sees Purchase orders but no Marketplace', str_contains(req($cl['r1'], 'GET', '/recycler')['body'], '>Marketplace<') && str_contains(req($cl['r1'], 'GET', '/recycler')['body'], '>My orders<')
    && str_contains(req($cl['hmA'], 'GET', '/hub')['body'], '>Purchase orders<') && !str_contains(req($cl['hmA'], 'GET', '/hub')['body'], '>Marketplace<'));

// ---------------------------------------------------------------- audit and integrity
foreach (['order.requested', 'order.countered', 'order.accepted', 'order.rejected', 'order.withdrawn', 'order.cancelled', 'order.scheduled', 'order.dispatched', 'order.received', 'order.completed', 'order.closed_short'] as $a) {
    check("Audit logged: $a", (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = '$a'")->fetchColumn() >= 1);
}
check('Final stock check passes and reserved never exceeds on-hand anywhere', StockBalance::mismatches() === [] && (int) $pdo->query('SELECT COUNT(*) FROM inventory WHERE reserved_kg > on_hand_kg OR on_hand_kg < 0')->fetchColumn() === 0);

// ---------------------------------------------------------------- teardown
$cleanup();
check('Cleanup left nothing behind', (int) $pdo->query("SELECT COUNT(*) FROM collection_hubs WHERE name LIKE 'P6TEST%'")->fetchColumn() === 0 && StockBalance::mismatches() === []);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
