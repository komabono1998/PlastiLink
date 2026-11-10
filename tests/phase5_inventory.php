<?php
declare(strict_types=1);

/**
 * Phase 5 tests: stock ledger, transfers, dispatches, adjustments, reservations, scoping, integrity.
 *   php tests/phase5_inventory.php [base-url]
 * Creates temporary data (phase5test.*, "P5TEST" hubs) and removes it afterwards.
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
use App\Models\Recycler;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\PickerService;
use App\Services\StockException;
use App\Services\StockService;

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase5Test!pw';
$pass = 0;
$fail = 0;

$cleanup = function () use ($pdo): void {
    $hubs = $pdo->query("SELECT id FROM collection_hubs WHERE name LIKE 'P5TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    $users = $pdo->query("SELECT id FROM users WHERE email LIKE 'phase5test.%' OR phone_e164 LIKE '+256700066%'")->fetchAll(PDO::FETCH_COLUMN);
    $hubIn = $hubs ? implode(',', array_map('intval', $hubs)) : '0';
    $userIn = $users ? implode(',', array_map('intval', $users)) : '0';
    $ids = fn(string $sql): string => implode(',', array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    $tr = $ids("SELECT id FROM stock_transfers WHERE from_hub_id IN ($hubIn) OR to_hub_id IN ($hubIn)");
    $dp = $ids("SELECT id FROM stock_dispatches WHERE hub_id IN ($hubIn)");
    $ad = $ids("SELECT id FROM stock_adjustments WHERE hub_id IN ($hubIn)");
    $col = $ids("SELECT id FROM collection_transactions WHERE hub_id IN ($hubIn) OR recorded_by IN ($userIn)");
    $pr = $ids("SELECT id FROM price_lists WHERE hub_id IN ($hubIn) OR note LIKE 'P5TEST%'");

    $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($userIn) OR (entity_type = 'transfer' AND entity_id IN ($tr)) OR (entity_type = 'dispatch' AND entity_id IN ($dp))
                OR (entity_type = 'adjustment' AND entity_id IN ($ad)) OR (entity_type = 'collection' AND entity_id IN ($col)) OR (entity_type = 'price' AND entity_id IN ($pr))
                OR (entity_type = 'user' AND entity_id IN ($userIn))");
    $pdo->exec("DELETE FROM financial_ledger WHERE source_type = 'collection' AND source_id IN ($col)");
    $pdo->exec("DELETE FROM stock_movements WHERE hub_id IN ($hubIn)");
    $pdo->exec("DELETE FROM stock_adjustments WHERE id IN ($ad)");
    $pdo->exec("DELETE FROM stock_dispatches WHERE id IN ($dp)");
    $pdo->exec("DELETE FROM stock_transfers WHERE id IN ($tr)");
    $pdo->exec("DELETE FROM inventory WHERE hub_id IN ($hubIn)");
    $pdo->exec("DELETE FROM receipts WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM weighing_records WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM collection_transactions WHERE id IN ($col)");
    $pdo->exec("DELETE FROM price_lists WHERE id IN ($pr)");
    $pdo->exec("DELETE FROM pickers WHERE user_id IN ($userIn)");
    $pdo->exec("DELETE FROM recyclers WHERE user_id IN ($userIn)");
    $pdo->exec("DELETE FROM collection_hubs WHERE id IN ($hubIn)");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase5test%'");
    $pdo->exec("DELETE FROM users WHERE id IN ($userIn)");
    $pdo->exec("DELETE FROM sms_outbox WHERE user_id IS NULL");
};
$cleanup();

// ---------------------------------------------------------------- setup
$ids = [];
foreach (['super_admin' => 'sa', 'project_manager' => 'pm', 'hub_manager' => 'hmA', 'hub_operator' => 'hoA'] as $role => $k) {
    $ids[$k] = User::create(['role' => $role, 'full_name' => "P5 $k", 'email' => "phase5test.$k@example.test", 'password' => $PW, 'status' => 'active']);
}
$ids['hmA2'] = User::create(['role' => 'hub_manager', 'full_name' => 'P5 hmA2', 'email' => 'phase5test.hma2@example.test', 'password' => $PW, 'status' => 'active']);
$ids['hmB'] = User::create(['role' => 'hub_manager', 'full_name' => 'P5 hmB', 'email' => 'phase5test.hmb@example.test', 'password' => $PW, 'status' => 'active']);
$ids['hoB'] = User::create(['role' => 'hub_operator', 'full_name' => 'P5 hoB', 'email' => 'phase5test.hob@example.test', 'password' => $PW, 'status' => 'active']);
$nbId = (int) $pdo->query('SELECT id FROM neighbourhoods WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$catId = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code = 'PET'")->fetchColumn();
$mkHub = fn(string $n) => Hub::create(['name' => $n, 'neighbourhood_id' => $nbId, 'address_note' => null, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
$hubA = $mkHub('P5TEST Hub A'); $hubB = $mkHub('P5TEST Hub B'); $hubC = $mkHub('P5TEST Hub C');
Hub::assign($hubA, $ids['hmA'], 'manager'); Hub::assign($hubA, $ids['hmA2'], 'manager'); Hub::assign($hubA, $ids['hoA'], 'operator');
Hub::assign($hubB, $ids['hmB'], 'manager'); Hub::assign($hubB, $ids['hoB'], 'operator');
foreach ([$hubA, $hubB, $hubC] as $h) {
    Price::create($catId, $h, 1000, 'P5TEST price', $ids['sa']);
}
$U = fn(string $k): array => User::findById($ids[$k]);
$saUser = $U('sa');
$mkPicker = function (string $name, string $phone, int $hub) use ($saUser, $nbId): int {
    $r = PickerService::registerByStaff(['full_name' => $name, 'phone' => $phone, 'neighbourhood_id' => $nbId, 'preferred_hub_id' => $hub, 'consent' => '1'], $saUser);
    return (int) $r['id'];
};
$pkA = $mkPicker('P5 Picker A', '0700066101', $hubA);
$pkB = $mkPicker('P5 Picker B', '0700066102', $hubB);

// approved recycler (via staff registration) and a pending one
$rr = App\Services\RecyclerService::registerByStaff(['business_name' => 'P5TEST Recycling', 'contact_person' => 'P5 Rec', 'email' => 'phase5test.rec@example.test', 'phone' => '0700066201',
    'address' => 'Gulu', 'consent' => '1'], $saUser);
$recOk = (int) $rr['id'];
$pu = User::create(['role' => 'recycler', 'full_name' => 'P5 Pending Rec', 'email' => 'phase5test.recp@example.test', 'password' => $PW, 'status' => 'pending']);
$recPending = Recycler::create(['user_id' => $pu, 'business_name' => 'P5TEST Pending Co', 'registration_number' => null, 'contact_person' => 'P5', 'address' => 'x', 'materials' => 'PET', 'status' => 'pending']);

$tok = fn() => bin2hex(random_bytes(16));
$n = 0;
$mkCollection = function (int $hub, int $picker, string $gross, string $tare, string $recorder, string $approver) use ($U, $catId, $tok, &$n): int {
    $r = CollectionService::record(['token' => $tok(), 'picker_id' => $picker, 'hub_id' => $hub, 'plastic_category_id' => $catId,
        'gross_weight_kg' => $gross, 'tare_weight_kg' => $tare], $U($recorder));
    $a = CollectionService::approve((int) $r['id'], $U($approver));
    return $a['error'] === null ? (int) $r['id'] : 0;
};
$lvl = fn(int $hub): string => (string) ($pdo->query("SELECT on_hand_kg FROM inventory WHERE hub_id = $hub AND plastic_category_id = $catId")->fetchColumn() ?: '0.00');
$res = fn(int $hub): string => (string) ($pdo->query("SELECT reserved_kg FROM inventory WHERE hub_id = $hub AND plastic_category_id = $catId")->fetchColumn() ?: '0.00');
$ledger = fn(int $hub): string => (string) $pdo->query("SELECT COALESCE(SUM(qty_kg),0) FROM stock_movements WHERE hub_id = $hub AND plastic_category_id = $catId")->fetchColumn();
$movs = fn(int $hub, string $type): int => (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE hub_id = $hub AND movement_type = '$type'")->fetchColumn();

// ---------------------------------------------------------------- pure helpers
check('Weight::fromDecimal / toSignedDecimal round-trip', Weight::fromDecimal('12.50') === 1250 && Weight::fromDecimal('-2.50') === -250 && Weight::fromDecimal('0.05') === 5
    && Weight::toSignedDecimal(-250) === '-2.50' && Weight::toSignedDecimal(5) === '0.05' && Weight::fromDecimal(Weight::toSignedDecimal(-12345)) === -12345);
$threw = false;
try { StockService::post('adjustment_in', $hubA, $catId, 100, 0, 'test', 1, null, null, null); } catch (LogicException) { $threw = true; }
check('Ledger writes outside a transaction are refused', $threw);

// ---------------------------------------------------------------- stock from approved collections
$c1 = $mkCollection($hubA, $pkA, '50', '', 'hoA', 'hmA');
$c2 = $mkCollection($hubA, $pkA, '31', '1', 'hoA', 'hmA');
$c3 = $mkCollection($hubB, $pkB, '20', '', 'hoB', 'hmB');
check('Collections approved', $c1 > 0 && $c2 > 0 && $c3 > 0);
check('Approved collections add to hub stock (50 + 30 = 80; hub B 20)', $lvl($hubA) === '80.00' && $lvl($hubB) === '20.00');
$m = $pdo->query("SELECT movement_type, qty_kg, balance_after_kg, reference, source_type FROM stock_movements WHERE hub_id = $hubA ORDER BY id")->fetchAll();
check('Ledger rows: type, signed qty, balance after, reference, source', count($m) === 2 && $m[0]['movement_type'] === 'collection_in' && $m[0]['qty_kg'] === '50.00' && $m[0]['balance_after_kg'] === '50.00'
    && $m[1]['balance_after_kg'] === '80.00' && str_starts_with($m[0]['reference'], 'COL-') && $m[0]['source_type'] === 'collection');
check('Balance equals the sum of the ledger', $lvl($hubA) === $ledger($hubA) && $lvl($hubB) === $ledger($hubB));
$dupe = false;
try { Database::transaction(fn() => StockService::post('collection_in', $hubA, $catId, 100, 0, 'collection', $c1, 'x', null, null)); } catch (StockException $e) { $dupe = str_contains($e->getMessage(), 'already'); }
check('The same event cannot be posted to the ledger twice', $dupe && $lvl($hubA) === '80.00');
$threw = 0;
foreach (["UPDATE inventory SET on_hand_kg = -1 WHERE hub_id = $hubA", "UPDATE inventory SET reserved_kg = on_hand_kg + 1 WHERE hub_id = $hubA", "UPDATE inventory SET reserved_kg = -1 WHERE hub_id = $hubA"] as $sql) {
    try { $pdo->exec($sql); } catch (PDOException) { $threw++; }
}
check('Database CHECK refuses negative stock and reserved above on-hand', $threw === 3 && $lvl($hubA) === '80.00');

// ---------------------------------------------------------------- HTTP clients
$cl = [];
foreach (['sa' => 'sa', 'pm' => 'pm', 'hmA' => 'hma', 'hmA2' => 'hma2', 'hoA' => 'hoa', 'hmB' => 'hmb', 'hoB' => 'hob'] as $k => $e) {
    $cl[$k] = newClient();
    $r = login($cl[$k], "phase5test.$e@example.test", $PW);
    check("Setup: $k signs in", $r['status'] === 303 && loc($r) !== '/login', 'loc ' . loc($r));
}
$cl['pkA'] = newClient();
$pkAuser = (int) $pdo->query("SELECT user_id FROM pickers WHERE id = $pkA")->fetchColumn();
User::updatePassword($pkAuser, $PW);
login($cl['pkA'], '0700066101', $PW);

// ---------------------------------------------------------------- guests / CSRF / RBAC
$g = newClient();
foreach (['/inventory', '/inventory/movements', '/inventory/transfers', '/inventory/transfers/new', '/inventory/dispatches', '/inventory/adjustments', '/inventory/adjustments/1'] as $p) {
    $r = req($g, 'GET', $p);
    check("Guest -> $p redirects to /login", $r['status'] === 303 && loc($r) === '/login');
}
foreach (['/inventory/transfers', '/inventory/dispatches', '/inventory/adjustments', '/inventory/transfers/1/receive', '/inventory/dispatches/1/cancel', '/inventory/adjustments/1/decision'] as $p) {
    check("POST $p without CSRF token refused", req($cl['hmA'], 'POST', $p, ['x' => '1'])['status'] === 400);
}
$matrix = [
    'sa'  => ['/inventory' => 200, '/inventory/movements' => 200, '/inventory/transfers' => 200, '/inventory/transfers/new' => 200, '/inventory/dispatches/new' => 200, '/inventory/adjustments/new' => 200],
    'pm'  => ['/inventory' => 200, '/inventory/adjustments' => 200, '/inventory/transfers/new' => 403, '/inventory/dispatches/new' => 403, '/inventory/adjustments/new' => 403],
    'hmA' => ['/inventory' => 200, '/inventory/transfers/new' => 200, '/inventory/dispatches/new' => 200, '/inventory/adjustments/new' => 200],
    'hoA' => ['/inventory' => 200, '/inventory/movements' => 200, '/inventory/transfers/new' => 403, '/inventory/dispatches/new' => 403, '/inventory/adjustments/new' => 403],
    'pkA' => ['/inventory' => 403, '/inventory/movements' => 403, '/inventory/transfers' => 403],
];
foreach ($matrix as $who => $paths) {
    foreach ($paths as $path => $expected) {
        check("RBAC: $who GET $path = $expected", req($cl[$who], 'GET', $path)['status'] === $expected);
    }
}
check('RBAC: operator cannot send, dispatch or request (POST 403)',
    postVia($cl['hoA'], '/inventory', '/inventory/transfers', ['x' => '1'])['status'] === 403
    && postVia($cl['hoA'], '/inventory', '/inventory/dispatches', ['x' => '1'])['status'] === 403
    && postVia($cl['hoA'], '/inventory', '/inventory/adjustments', ['x' => '1'])['status'] === 403);
check('RBAC: project manager cannot send or dispatch (POST 403)', postVia($cl['pm'], '/inventory', '/inventory/transfers', ['x' => '1'])['status'] === 403 && postVia($cl['pm'], '/inventory', '/inventory/dispatches', ['x' => '1'])['status'] === 403);

$bal = req($cl['hmA'], 'GET', '/inventory')['body'];
check('Balances page: own hub only, 80 kg, stock check passed', str_contains($bal, 'P5TEST Hub A') && !str_contains($bal, 'P5TEST Hub B') && str_contains($bal, '80 kg') && str_contains($bal, 'Stock check passed'));
check('Hub B staff see hub B only', str_contains(req($cl['hmB'], 'GET', '/inventory')['body'], '20 kg') && !str_contains(req($cl['hoB'], 'GET', '/inventory')['body'], 'P5TEST Hub A'));

// ---------------------------------------------------------------- transfers
$send = fn(string $who, array $over = []) => postVia($cl[$who], '/inventory/transfers/new', '/inventory/transfers', $over + ['token' => $tok(), 'from_hub_id' => $hubA, 'to_hub_id' => $hubB, 'plastic_category_id' => $catId, 'quantity_kg' => '10', 'notes' => '']);
$msg = fn(string $who, array $r) => strip_tags(follow($cl[$who], $r)['body']);
check('Transfer: same hub refused', str_contains($msg('hmA', $send('hmA', ['to_hub_id' => $hubA])), 'different hub'));
check('Transfer: zero quantity refused', str_contains($msg('hmA', $send('hmA', ['quantity_kg' => '0'])), 'more than zero'));
check('Transfer: text quantity refused', str_contains($msg('hmA', $send('hmA', ['quantity_kg' => 'lots'])), 'must be a number'));
check('Transfer: more than the stock refused', str_contains($msg('hmA', $send('hmA', ['quantity_kg' => '500'])), 'not enough stock'));
check('Transfer: sending from another hub refused', str_contains($msg('hmA', $send('hmA', ['from_hub_id' => $hubB, 'to_hub_id' => $hubA])), 'Choose one of your hubs'));
check('Failed transfers changed nothing', $lvl($hubA) === '80.00' && (int) $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE from_hub_id = $hubA")->fetchColumn() === 0);
$tk = $tok();
$r = $send('hmA', ['token' => $tk]);
check('Transfer sent', preg_match('#^/inventory/transfers/(\d+)$#', loc($r), $m) === 1);
$t1 = (int) ($m[1] ?? 0);
$row = $pdo->query("SELECT * FROM stock_transfers WHERE id = $t1")->fetch();
check('Transfer: reference TRF-YYYYMMDD-######, in transit, 10.00 kg', preg_match('/^TRF-\d{8}-\d{6}$/', (string) $row['reference']) === 1 && $row['status'] === 'in_transit' && $row['qty_sent_kg'] === '10.00');
check('Stock left the sender at once; receiver unchanged; in-transit visible', $lvl($hubA) === '70.00' && $lvl($hubB) === '20.00' && $movs($hubA, 'transfer_out') === 1
    && str_contains(req($cl['hmB'], 'GET', '/inventory')['body'], 'Arriving'));
$r2 = req($cl['hmA'], 'POST', '/inventory/transfers', ['_csrf' => csrfFrom(req($cl['hmA'], 'GET', '/inventory/transfers/new')['body']), 'token' => $tk, 'from_hub_id' => $hubA, 'to_hub_id' => $hubB, 'plastic_category_id' => $catId, 'quantity_kg' => '10']);
check('Double submit does not send twice', loc($r2) === "/inventory/transfers/$t1" && $lvl($hubA) === '70.00' && (int) $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE submission_token = '$tk'")->fetchColumn() === 1);
check('Sender cannot confirm receipt of its own transfer', str_contains(strip_tags(follow($cl['hmA'], postVia($cl['hmA'], "/inventory/transfers/$t1", "/inventory/transfers/$t1/receive", ['received_kg' => '10']))['body']), 'Only the receiving hub') && $lvl($hubB) === '20.00');
check('Operators cannot confirm receipt (POST 403)', postVia($cl['hoB'], "/inventory/transfers/$t1", "/inventory/transfers/$t1/receive", ['received_kg' => '10'])['status'] === 403);
check('Receiving more than was sent is refused', str_contains(strip_tags(follow($cl['hmB'], postVia($cl['hmB'], "/inventory/transfers/$t1", "/inventory/transfers/$t1/receive", ['received_kg' => '10.5']))['body']), 'cannot be more than'));
check('Receiving less without a note is refused', str_contains(strip_tags(follow($cl['hmB'], postVia($cl['hmB'], "/inventory/transfers/$t1", "/inventory/transfers/$t1/receive", ['received_kg' => '9.5', 'note' => '']))['body']), 'Say what happened'));
postVia($cl['hmB'], "/inventory/transfers/$t1", "/inventory/transfers/$t1/receive", ['received_kg' => '9.5', 'note' => 'One sack split open']);
$row = $pdo->query("SELECT * FROM stock_transfers WHERE id = $t1")->fetch();
check('Receipt: status received, 9.50 kg, by hub B manager, note kept', $row['status'] === 'received' && $row['qty_received_kg'] === '9.50' && (int) $row['received_by'] === $ids['hmB'] && $row['receive_note'] === 'One sack split open');
check('Receiver gained 9.5 kg; the 0.5 kg difference is a recorded transit loss', $lvl($hubB) === '29.50' && $lvl($hubA) === '70.00' && str_contains(req($cl['hmA'], 'GET', "/inventory/transfers/$t1")['body'], '0.5 kg lost in transit'));
check('Cannot receive or cancel a received transfer', str_contains(strip_tags(follow($cl['hmB'], postVia($cl['hmB'], "/inventory/transfers/$t1", "/inventory/transfers/$t1/receive", ['received_kg' => '9.5']))['body']), 'in transit')
    && str_contains(strip_tags(follow($cl['hmA'], postVia($cl['hmA'], "/inventory/transfers/$t1", "/inventory/transfers/$t1/cancel", ['reason' => 'too late']))['body']), 'in transit'));

// cancel flow + scoping via a transfer to hub C (no staff)
$r = $send('hmA', ['to_hub_id' => $hubC, 'quantity_kg' => '5']);
preg_match('#^/inventory/transfers/(\d+)$#', loc($r), $m); $t2 = (int) ($m[1] ?? 0);
check('Transfer to hub C sent (A = 65)', $lvl($hubA) === '65.00');
check('Hub B manager gets 404 for a transfer between other hubs', req($cl['hmB'], 'GET', "/inventory/transfers/$t2")['status'] === 404 && req($cl['hmA'], 'GET', "/inventory/transfers/$t2")['status'] === 200);
check('Hub B list does not show it; hub A list does', !str_contains(req($cl['hmB'], 'GET', '/inventory/transfers')['body'], $pdo->query("SELECT reference FROM stock_transfers WHERE id = $t2")->fetchColumn()) && str_contains(req($cl['hmA'], 'GET', '/inventory/transfers')['body'], $pdo->query("SELECT reference FROM stock_transfers WHERE id = $t2")->fetchColumn()));
check('A different hub cannot cancel it', str_contains(strip_tags(follow($cl['hmB'], postVia($cl['hmB'], '/inventory', "/inventory/transfers/$t2/cancel", ['reason' => 'nope nope']))['body']), 'not found') && $lvl($hubA) === '65.00');
check('Cancel needs a reason', str_contains(strip_tags(follow($cl['hmA'], postVia($cl['hmA'], "/inventory/transfers/$t2", "/inventory/transfers/$t2/cancel", ['reason' => '']))['body']), 'reason'));
postVia($cl['hmA'], "/inventory/transfers/$t2", "/inventory/transfers/$t2/cancel", ['reason' => 'Wrong hub chosen']);
check('Cancelled transfer returns the stock to the sender (A = 70)', $pdo->query("SELECT status FROM stock_transfers WHERE id = $t2")->fetchColumn() === 'cancelled' && $lvl($hubA) === '70.00' && $movs($hubA, 'transfer_return') === 1);

// ---------------------------------------------------------------- dispatches
$disp = fn(string $who, array $over = []) => postVia($cl[$who], '/inventory/dispatches/new', '/inventory/dispatches', $over + ['token' => $tok(), 'hub_id' => $hubA, 'recycler_id' => $recOk, 'plastic_category_id' => $catId, 'quantity_kg' => '20', 'vehicle' => 'UAB 123X', 'driver' => 'Okello', 'notes' => '']);
check('Dispatch: pending recycler refused', str_contains($msg('hmA', $disp('hmA', ['recycler_id' => $recPending])), 'approved recycler'));
check('Dispatch: more than stock refused (nothing saved)', str_contains($msg('hmA', $disp('hmA', ['quantity_kg' => '999'])), 'not enough stock') && (int) $pdo->query("SELECT COUNT(*) FROM stock_dispatches WHERE hub_id = $hubA")->fetchColumn() === 0 && $lvl($hubA) === '70.00');
check('Dispatch: other hub refused', str_contains($msg('hmA', $disp('hmA', ['hub_id' => $hubB])), 'Choose one of your hubs'));
$dk = $tok();
$r = $disp('hmA', ['token' => $dk]);
check('Dispatch recorded', preg_match('#^/inventory/dispatches/(\d+)$#', loc($r), $m) === 1);
$d1 = (int) ($m[1] ?? 0);
$row = $pdo->query("SELECT * FROM stock_dispatches WHERE id = $d1")->fetch();
check('Dispatch: DSP- reference, dispatched, 20.00 kg; stock A = 50', preg_match('/^DSP-\d{8}-\d{6}$/', (string) $row['reference']) === 1 && $row['status'] === 'dispatched' && $row['qty_kg'] === '20.00' && $lvl($hubA) === '50.00' && $movs($hubA, 'dispatch_out') === 1);
$page = req($cl['hmA'], 'GET', "/inventory/dispatches/$d1");
check('Delivery note shows hub, recycler, weight, vehicle and has a print button', str_contains($page['body'], 'P5TEST Recycling') && str_contains($page['body'], '20 kg') && str_contains($page['body'], 'UAB 123X') && str_contains($page['body'], 'data-print'));
$r2 = req($cl['hmA'], 'POST', '/inventory/dispatches', ['_csrf' => csrfFrom(req($cl['hmA'], 'GET', '/inventory/dispatches/new')['body']), 'token' => $dk, 'hub_id' => $hubA, 'recycler_id' => $recOk, 'plastic_category_id' => $catId, 'quantity_kg' => '20']);
check('Dispatch double submit sends nothing twice', loc($r2) === "/inventory/dispatches/$d1" && $lvl($hubA) === '50.00');
check('Hub B manager gets 404 for a hub A dispatch', req($cl['hmB'], 'GET', "/inventory/dispatches/$d1")['status'] === 404);
check('Operators cannot mark delivered (POST 403)', postVia($cl['hoA'], "/inventory/dispatches/$d1", "/inventory/dispatches/$d1/delivered", [])['status'] === 403);
postVia($cl['hmA'], "/inventory/dispatches/$d1", "/inventory/dispatches/$d1/delivered", ['note' => 'Received by gate']);
check('Dispatch marked delivered', $pdo->query("SELECT status FROM stock_dispatches WHERE id = $d1")->fetchColumn() === 'delivered');
check('A delivered dispatch cannot be cancelled (stock stays out)', str_contains(strip_tags(follow($cl['hmA'], postVia($cl['hmA'], "/inventory/dispatches/$d1", "/inventory/dispatches/$d1/cancel", ['reason' => 'changed my mind']))['body']), 'not yet delivered') && $lvl($hubA) === '50.00');
$r = $disp('hmA', ['quantity_kg' => '10']);
preg_match('#^/inventory/dispatches/(\d+)$#', loc($r), $m); $d2 = (int) ($m[1] ?? 0);
check('Second dispatch (A = 40)', $lvl($hubA) === '40.00');
postVia($cl['hmA'], "/inventory/dispatches/$d2", "/inventory/dispatches/$d2/cancel", ['reason' => 'Truck did not arrive']);
check('Cancelled dispatch returns its stock (A = 50)', $pdo->query("SELECT status FROM stock_dispatches WHERE id = $d2")->fetchColumn() === 'cancelled' && $lvl($hubA) === '50.00' && $movs($hubA, 'dispatch_return') === 1);

// ---------------------------------------------------------------- adjustments (second-person approval)
$adj = fn(string $who, array $over = []) => postVia($cl[$who], '/inventory/adjustments/new', '/inventory/adjustments', $over + ['token' => $tok(), 'kind' => 'manual_entry', 'hub_id' => $hubA, 'plastic_category_id' => $catId, 'quantity_kg' => '25', 'reason' => 'Opening balance from the old register']);
check('Adjustment: reason is required', str_contains($msg('hmA', $adj('hmA', ['reason' => 'x'])), 'clear reason'));
check('Adjustment: unknown kind refused', str_contains($msg('hmA', $adj('hmA', ['kind' => 'magic'])), 'what kind'));
check('Write-off above available stock refused at request time', str_contains($msg('hmA', $adj('hmA', ['kind' => 'write_off', 'quantity_kg' => '500'])), 'cannot write off more'));
$r = $adj('hmA', ['reason' => '<script>alert(1)</script> opening balance']);
preg_match('#^/inventory/adjustments/(\d+)$#', loc($r), $m); $a1 = (int) ($m[1] ?? 0);
check('Manual entry request created as pending; stock unchanged', $a1 > 0 && $pdo->query("SELECT status FROM stock_adjustments WHERE id = $a1")->fetchColumn() === 'pending' && $lvl($hubA) === '50.00');
$pg = follow($cl['hmA'], $r)['body'];
check('Reason text is escaped (XSS)', str_contains($pg, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($pg, '<script>alert(1)'));
check('Requester cannot approve their own request', str_contains(strip_tags(follow($cl['hmA'], postVia($cl['hmA'], "/inventory/adjustments/$a1", "/inventory/adjustments/$a1/decision", ['decision' => 'approve']))['body']), 'someone else must approve') && $lvl($hubA) === '50.00');
check('Own request page offers no approve button', !str_contains(req($cl['hmA'], 'GET', "/inventory/adjustments/$a1")['body'], 'Approve and post to stock'));
check('Operator cannot approve (POST 403)', postVia($cl['hoA'], "/inventory/adjustments/$a1", "/inventory/adjustments/$a1/decision", ['decision' => 'approve'])['status'] === 403);
check('Hub B manager cannot see or decide a hub A request', req($cl['hmB'], 'GET', "/inventory/adjustments/$a1")['status'] === 404 && str_contains(strip_tags(follow($cl['hmB'], postVia($cl['hmB'], '/inventory', "/inventory/adjustments/$a1/decision", ['decision' => 'approve']))['body']), 'not found') && $lvl($hubA) === '50.00');
postVia($cl['hmA2'], "/inventory/adjustments/$a1", "/inventory/adjustments/$a1/decision", ['decision' => 'approve']);
check('Another hub A manager approves: stock +25 (A = 75) with an adjustment_in movement', $lvl($hubA) === '75.00' && $movs($hubA, 'adjustment_in') === 1 && $pdo->query("SELECT status FROM stock_adjustments WHERE id = $a1")->fetchColumn() === 'approved');
check('Cannot approve twice', str_contains(strip_tags(follow($cl['hmA2'], postVia($cl['hmA2'], "/inventory/adjustments/$a1", "/inventory/adjustments/$a1/decision", ['decision' => 'approve']))['body']), 'Only pending') && $lvl($hubA) === '75.00');

$r = $adj('hmA', ['kind' => 'write_off', 'quantity_kg' => '5', 'reason' => 'Five kg contaminated with oil']);
preg_match('#^/inventory/adjustments/(\d+)$#', loc($r), $m); $a2 = (int) ($m[1] ?? 0);
postVia($cl['pm'], "/inventory/adjustments/$a2", "/inventory/adjustments/$a2/decision", ['decision' => 'approve']);
check('Project manager approves a write-off (A = 70); movement type write_off, negative', $lvl($hubA) === '70.00' && $pdo->query("SELECT qty_kg FROM stock_movements WHERE source_type='adjustment' AND source_id = $a2")->fetchColumn() === '-5.00' && $movs($hubA, 'write_off') === 1);

check('Count equal to the system balance is refused', str_contains($msg('hmA2', $adj('hmA2', ['kind' => 'count', 'quantity_kg' => '70', 'reason' => 'Monthly count'])), 'No adjustment is needed'));
$r = $adj('hmA2', ['kind' => 'count', 'quantity_kg' => '67.5', 'reason' => 'Monthly physical count']);
preg_match('#^/inventory/adjustments/(\d+)$#', loc($r), $m); $a3 = (int) ($m[1] ?? 0);
$row = $pdo->query("SELECT * FROM stock_adjustments WHERE id = $a3")->fetch();
check('Count stores system 70.00, counted 67.50 and change -2.50', $row['system_kg'] === '70.00' && $row['counted_kg'] === '67.50' && $row['change_kg'] === '-2.50' && $lvl($hubA) === '70.00');
$c4 = $mkCollection($hubA, $pkA, '10', '', 'hoA', 'hmA');   // stock moves on before the count is approved
check('Stock moved on before approval (A = 80)', $lvl($hubA) === '80.00');
postVia($cl['hmA'], "/inventory/adjustments/$a3", "/inventory/adjustments/$a3/decision", ['decision' => 'approve']);
check('Count variance is applied as a delta (80 - 2.5 = 77.5)', $lvl($hubA) === '77.50' && $movs($hubA, 'adjustment_out') === 1);

$r = $adj('hmA', ['kind' => 'write_off', 'quantity_kg' => '20', 'reason' => 'Twenty kg water damaged']);
preg_match('#^/inventory/adjustments/(\d+)$#', loc($r), $m); $a4 = (int) ($m[1] ?? 0);
check('Rejection needs a note', str_contains(strip_tags(follow($cl['hmA2'], postVia($cl['hmA2'], "/inventory/adjustments/$a4", "/inventory/adjustments/$a4/decision", ['decision' => 'reject', 'note' => '']))['body']), 'reason'));
// make the write-off impossible by moving stock out, then try to approve it
$rd = $disp('hmA', ['quantity_kg' => '70']);
preg_match('#^/inventory/dispatches/(\d+)$#', loc($rd), $m); $d3 = (int) ($m[1] ?? 0);
check('Large dispatch leaves 7.5 kg', $lvl($hubA) === '7.50');
$r = postVia($cl['hmA2'], "/inventory/adjustments/$a4", "/inventory/adjustments/$a4/decision", ['decision' => 'approve']);
check('Approval refused when stock is no longer sufficient; stays pending; nothing posted', str_contains(strip_tags(follow($cl['hmA2'], $r)['body']), 'can no longer be applied') && $pdo->query("SELECT status FROM stock_adjustments WHERE id = $a4")->fetchColumn() === 'pending' && $lvl($hubA) === '7.50');
postVia($cl['hmA2'], "/inventory/adjustments/$a4", "/inventory/adjustments/$a4/decision", ['decision' => 'reject', 'note' => 'Stock was dispatched first']);
check('Rejected request posts nothing', $pdo->query("SELECT status FROM stock_adjustments WHERE id = $a4")->fetchColumn() === 'rejected' && $lvl($hubA) === '7.50' && $movs($hubA, 'write_off') === 1);
postVia($cl['hmA'], "/inventory/dispatches/$d3", "/inventory/dispatches/$d3/cancel", ['reason' => 'Truck cancelled']);
check('Stock restored by cancelling the big dispatch (A = 77.5)', $lvl($hubA) === '77.50');

// ---------------------------------------------------------------- reservations (used by purchase orders in Phase 6)
$A = fn() => StockService::levels($hubA, $catId);
StockService::reserve($hubA, $catId, 2000, 'order', 9001, $ids['sa']);
check('Reserve 20 kg: on hand 77.5, reserved 20, available 57.5', $A() === ['on_hand' => 7750, 'reserved' => 2000, 'available' => 5750] && $res($hubA) === '20.00');
$r = $disp('hmA', ['quantity_kg' => '60']);
check('Dispatch above the AVAILABLE stock is refused (reserved stock is protected)', str_contains($msg('hmA', $r), 'reserved') && $lvl($hubA) === '77.50');
$e = null; try { StockService::reserve($hubA, $catId, 10000, 'order', 9002, $ids['sa']); } catch (StockException $x) { $e = $x->getMessage(); }
check('Cannot reserve more than is available', $e !== null && str_contains($e, 'Available') && $res($hubA) === '20.00');
$e = null; try { StockService::release($hubA, $catId, 3000, 'order', 9001, $ids['sa']); } catch (StockException $x) { $e = $x->getMessage(); }
check('Cannot release more than is reserved', $e !== null && $res($hubA) === '20.00');
$e = null; try { StockService::reserve($hubA, $catId, 100, 'order', 9001, $ids['sa']); } catch (StockException $x) { $e = $x->getMessage(); }
check('The same reservation cannot be made twice', $e !== null && str_contains($e, 'already'));
StockService::release($hubA, $catId, 2000, 'order', 9001, $ids['sa']);
check('Release returns the stock to available (reserved 0)', $res($hubA) === '0.00' && $A()['available'] === 7750 && $movs($hubA, 'release') === 1);

// ---------------------------------------------------------------- collection reversal vs stock
$pmUser = $U('pm');
$receiptOf = fn(int $c) => $pdo->query("SELECT voided_at FROM receipts WHERE collection_id = $c")->fetchColumn();
StockService::reserve($hubA, $catId, 7000, 'order', 9005, $ids['sa']);
$r = CollectionService::reverse($c1, 'Wrong picker was selected', $pmUser);
check('Reversal is refused while the plastic is reserved for an order', $r['error'] !== null && str_contains($r['error'], 'no longer available')
    && $pdo->query("SELECT status FROM collection_transactions WHERE id = $c1")->fetchColumn() === 'approved' && $receiptOf($c1) === null && $lvl($hubA) === '77.50');
StockService::release($hubA, $catId, 7000, 'order', 9005, $ids['sa']);

$rd = $disp('hmA', ['quantity_kg' => '60']);
preg_match('#^/inventory/dispatches/(\d+)$#', loc($rd), $m); $d4 = (int) ($m[1] ?? 0);
check('Dispatch of 60 kg leaves 17.5 kg', $lvl($hubA) === '17.50');
$r = CollectionService::reverse($c1, 'Wrong picker was selected', $pmUser);
check('Reversal is refused when its stock has already left the hub (message explains what to do)', $r['error'] !== null && str_contains($r['error'], 'Return or adjust that stock first'));
check('The refused reversal rolled back completely (still approved, receipt valid, no movement)', $pdo->query("SELECT status FROM collection_transactions WHERE id = $c1")->fetchColumn() === 'approved'
    && $receiptOf($c1) === null && $movs($hubA, 'collection_reversal') === 0 && $lvl($hubA) === '17.50');
postVia($cl['hmA'], "/inventory/dispatches/$d4", "/inventory/dispatches/$d4/cancel", ['reason' => 'Plan changed']);
check('Stock back after cancelling the dispatch (77.5)', $lvl($hubA) === '77.50');
$r = CollectionService::reverse($c1, 'Wrong picker was selected', $pmUser);
check('Reversal now succeeds and takes 50 kg out of stock (27.5)', $r['error'] === null && $lvl($hubA) === '27.50' && $movs($hubA, 'collection_reversal') === 1
    && $pdo->query("SELECT qty_kg FROM stock_movements WHERE source_type = 'collection' AND source_id = $c1 AND movement_type = 'collection_reversal'")->fetchColumn() === '-50.00' && $receiptOf($c1) !== null);

// ---------------------------------------------------------------- concurrency: two managers dispatch ALL the stock at the same moment
$pageA = req($cl['hmA'], 'GET', '/inventory/dispatches/new');
$pageB = req($cl['hmA2'], 'GET', '/inventory/dispatches/new');
$tokA = $tok(); $tokB = $tok();
$mk = static fn(array $c, string $csrf, string $t) => ['c' => $c, 'data' => ['_csrf' => $csrf, 'token' => $t, 'hub_id' => $GLOBALS['hubA'], 'recycler_id' => $GLOBALS['recOk'], 'plastic_category_id' => $GLOBALS['catId'], 'quantity_kg' => '27.5']];
$jobs = [$mk($cl['hmA'], csrfFrom($pageA['body']), $tokA), $mk($cl['hmA2'], csrfFrom($pageB['body']), $tokB)];
$mh = curl_multi_init();
$handles = [];
foreach ($jobs as $i => $j) {
    $ch = curl_init($base . '/inventory/dispatches');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($j['data']),
        CURLOPT_COOKIEFILE => $j['c']['jar'], CURLOPT_COOKIEJAR => $j['c']['jar'], CURLOPT_TIMEOUT => 30]);
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.2); } while ($running > 0);
$locs = [];
$codes = [];
foreach ($handles as $ch) {
    $codes[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $raw = (string) curl_multi_getcontent($ch);
    preg_match('/^Location:\s*(\S+)/mi', $raw, $lm);
    $locs[] = $lm[1] ?? '';
    curl_multi_remove_handle($mh, $ch);
}
curl_multi_close($mh);
check('Race: neither simultaneous request ended in a server error (both got a normal redirect)', $codes === [303, 303], json_encode($codes));
$made = (int) $pdo->query("SELECT COUNT(*) FROM stock_dispatches WHERE submission_token IN ('$tokA', '$tokB')")->fetchColumn();
check('Race: of two simultaneous full-stock dispatches exactly ONE is created', $made === 1, "made=$made locs=" . json_encode($locs));
check('Race: stock is exactly 0 (never negative) and the ledger agrees', $lvl($hubA) === '0.00' && $ledger($hubA) === '0.00');

// ---------------------------------------------------------------- integrity check and back-fill safety
check('Ledger and balances agree everywhere', StockBalance::mismatches() === []);
$pdo->exec("UPDATE inventory SET on_hand_kg = on_hand_kg + 1 WHERE hub_id = $hubB");
check('A tampered balance is detected and shown as a warning', count(StockBalance::mismatches()) === 1 && str_contains(req($cl['sa'], 'GET', '/inventory')['body'], 'Stock check failed'));
$pdo->exec("UPDATE inventory SET on_hand_kg = on_hand_kg - 1 WHERE hub_id = $hubB");
check('Warning clears once the balance is correct', StockBalance::mismatches() === [] && str_contains(req($cl['sa'], 'GET', '/inventory')['body'], 'Stock check passed'));
$before = [(int) $pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn(), $lvl($hubA), $lvl($hubB), (string) $pdo->query('SELECT COALESCE(SUM(on_hand_kg),0) FROM inventory')->fetchColumn()];
$migration = require BASE_PATH . '/database/migrations/0013_backfill_stock_from_collections.php';
$migration['up']($pdo);
$after = [(int) $pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn(), $lvl($hubA), $lvl($hubB), (string) $pdo->query('SELECT COALESCE(SUM(on_hand_kg),0) FROM inventory')->fetchColumn()];
check('Re-running the back-fill changes nothing (no double count, later movements kept)', $before === $after && StockBalance::mismatches() === [], json_encode([$before, $after]));

// ---------------------------------------------------------------- ledger screen, filters, dashboards
$ref = (string) $pdo->query("SELECT reference FROM stock_transfers WHERE id = $t1")->fetchColumn();
$mv = req($cl['hmB'], 'GET', '/inventory/movements?type=transfer_in')['body'];
check('Movement ledger: type filter works and shows hub B only', str_contains($mv, $ref) && str_contains($mv, 'Transfer received (in)') && !str_contains($mv, 'P5TEST Hub A'));
$today = Dates::todayYmd();
check('Movement ledger: date filter (today includes, future excludes); garbage ignored', str_contains(req($cl['sa'], 'GET', "/inventory/movements?from=$today&to=$today&type=transfer_in")['body'], $ref)
    && !str_contains(req($cl['sa'], 'GET', '/inventory/movements?from=2999-01-01&type=transfer_in')['body'], $ref) && req($cl['sa'], 'GET', '/inventory/movements?type=bogus&from=zzz')['status'] === 200);
check('Hub B operator cannot see hub A movements', !str_contains(req($cl['hoB'], 'GET', '/inventory/movements')['body'], 'P5TEST Hub A'));
$totals = StockBalance::totals([$hubB]);
check('Dashboard stock totals equal the database (hub B: 29.5 kg)', (float) $totals[0]['on_hand_kg'] === 29.5 && str_contains(req($cl['hmB'], 'GET', '/hub')['body'], '29.5 kg') && str_contains(req($cl['hmB'], 'GET', '/hub')['body'], 'Stock at my hubs'));
check('Admin dashboard shows stock on hand', str_contains(req($cl['sa'], 'GET', '/admin')['body'], 'Stock on hand'));
check('Sidebar: operator sees Inventory, picker does not', str_contains(req($cl['hoA'], 'GET', '/hub')['body'], '>Inventory<') && !str_contains(req($cl['pkA'], 'GET', '/picker')['body'], '>Inventory<'));

foreach (['stock.transfer_sent', 'stock.transfer_received', 'stock.transfer_cancelled', 'stock.dispatch_created', 'stock.dispatch_delivered', 'stock.dispatch_cancelled',
          'stock.adjustment_requested', 'stock.adjustment_approved', 'stock.adjustment_rejected'] as $a) {
    check("Audit logged: $a", (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = '$a'")->fetchColumn() >= 1);
}

// ---------------------------------------------------------------- teardown
$cleanup();
check('Cleanup left no test hubs, users or stock rows behind', (int) $pdo->query("SELECT COUNT(*) FROM collection_hubs WHERE name LIKE 'P5TEST%'")->fetchColumn() === 0 && StockBalance::mismatches() === []);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
