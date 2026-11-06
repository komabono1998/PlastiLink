<?php
declare(strict_types=1);

/**
 * Phase 7 tests: ledger, picker earnings and payouts, payments, invoices, recycler receipts, reconciliation, ledger check.
 *   php tests/phase7_finance.php [base-url]
 * Creates temporary data (phase7test.*, "P7TEST" hubs) and removes it afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/_http.php';

use App\Core\Config;
use App\Core\Database;
use App\Helpers\Dates;
use App\Helpers\Money;
use App\Models\Hub;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Picker;
use App\Models\Price;
use App\Models\Recycler;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\FinanceService;
use App\Services\InvoiceService;
use App\Services\LedgerService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\PickerService;
use App\Services\RecyclerService;

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase7Test!pw';
$pass = 0;
$fail = 0;

$cleanup = function () use ($pdo): void {
    $ids = fn(string $sql): string => implode(',', array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    $hub = $ids("SELECT id FROM collection_hubs WHERE name LIKE 'P7TEST%'");
    $usr = $ids("SELECT id FROM users WHERE email LIKE 'phase7test.%' OR phone_e164 LIKE '+256700044%'");
    $pk = $ids("SELECT id FROM pickers WHERE user_id IN ($usr)");
    $ord = $ids("SELECT id FROM purchase_orders WHERE hub_id IN ($hub)");
    $inv = $ids("SELECT id FROM invoices WHERE order_id IN ($ord)");
    $pay = $ids("SELECT id FROM payments WHERE picker_id IN ($pk) OR invoice_id IN ($inv)");
    $req = $ids("SELECT id FROM payment_requests WHERE picker_id IN ($pk)");
    $dsp = $ids("SELECT id FROM stock_dispatches WHERE hub_id IN ($hub)");
    $col = $ids("SELECT id FROM collection_transactions WHERE hub_id IN ($hub) OR recorded_by IN ($usr)");
    $prc = $ids("SELECT id FROM price_lists WHERE hub_id IN ($hub) OR note LIKE 'P7TEST%'");
    $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($usr) OR (entity_type = 'payment' AND entity_id IN ($pay)) OR (entity_type = 'payout_request' AND entity_id IN ($req))
                OR (entity_type = 'invoice' AND entity_id IN ($inv)) OR (entity_type = 'order' AND entity_id IN ($ord)) OR (entity_type = 'dispatch' AND entity_id IN ($dsp))
                OR (entity_type = 'collection' AND entity_id IN ($col)) OR (entity_type = 'price' AND entity_id IN ($prc)) OR (entity_type = 'user' AND entity_id IN ($usr))");
    $pdo->exec("DELETE FROM financial_ledger WHERE (source_type = 'collection' AND source_id IN ($col)) OR (source_type = 'invoice' AND source_id IN ($inv))
                OR (source_type = 'payment' AND source_id IN ($pay)) OR source_type = 'p7test'");
    $pdo->exec("DELETE FROM payment_attempts WHERE payment_id IN ($pay)");
    $pdo->exec("DELETE FROM payments WHERE id IN ($pay)");
    $pdo->exec("DELETE FROM payment_requests WHERE id IN ($req)");
    $pdo->exec("DELETE FROM invoices WHERE id IN ($inv)");
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
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase7test%' OR identifier LIKE '%700044%'");
    $pdo->exec("DELETE FROM users WHERE id IN ($usr)");
    $pdo->exec("DELETE FROM sms_outbox WHERE user_id IS NULL");
};
$cleanup();

// ---------------------------------------------------------------- money parsing and ledger rules
check('Money::parseUgx accepts whole shillings in common forms', Money::parseUgx('12500') === 12500 && Money::parseUgx('12,500') === 12500 && Money::parseUgx('UGX 12 500') === 12500 && Money::parseUgx(' 7 ') === 7);
check('Money::parseUgx rejects decimals, negatives, text and empty', Money::parseUgx('12.5') === null && Money::parseUgx('-5') === null && Money::parseUgx('abc') === null && Money::parseUgx('') === null && Money::parseUgx('1e3') === null);
check('Money::signed', Money::signed(1500) === '+UGX 1,500' && Money::signed(-1500) === '-UGX 1,500' && Money::signed(0) === 'UGX 0');
$threw = false;
try { LedgerService::post('picker', 1, 'payout', -100, 'p7test', 1, null, null, null); } catch (LogicException) { $threw = true; }
check('Ledger writes outside a transaction are refused', $threw);
$bad = 0;
foreach ([['picker', 'payout', 100], ['picker', 'collection_earning', -100], ['recycler', 'collection_earning', 100], ['picker', 'collection_earning', 0], ['recycler', 'invoice_payment', 100]] as [$acct, $type, $amt]) {
    try { Database::transaction(fn() => LedgerService::post($acct, 1, $type, $amt, 'p7test', 9, null, null, null)); } catch (InvalidArgumentException) { $bad++; }
}
check('Ledger refuses wrong signs, wrong account types and zero amounts', $bad === 5 && (int) $pdo->query("SELECT COUNT(*) FROM financial_ledger WHERE source_type = 'p7test'")->fetchColumn() === 0);
check('Payment status machine table', PaymentService::canMove('pending', 'processing') && PaymentService::canMove('processing', 'successful') && PaymentService::canMove('failed', 'successful')
    && PaymentService::canMove('successful', 'reversed') && !PaymentService::canMove('reversed', 'successful') && !PaymentService::canMove('pending', 'reversed') && !PaymentService::canMove('successful', 'failed'));

// ---------------------------------------------------------------- setup
$ids = [];
foreach (['super_admin' => 'sa', 'project_manager' => 'pm', 'hub_manager' => 'hmA', 'hub_operator' => 'hoA'] as $role => $k) {
    $ids[$k] = User::create(['role' => $role, 'full_name' => "P7 $k", 'email' => "phase7test.$k@example.test", 'password' => $PW, 'status' => 'active']);
}
$ids['hmB'] = User::create(['role' => 'hub_manager', 'full_name' => 'P7 hmB', 'email' => 'phase7test.hmb@example.test', 'password' => $PW, 'status' => 'active']);
$ids['hoB'] = User::create(['role' => 'hub_operator', 'full_name' => 'P7 hoB', 'email' => 'phase7test.hob@example.test', 'password' => $PW, 'status' => 'active']);
$nbId = (int) $pdo->query('SELECT id FROM neighbourhoods WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$catId = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code = 'PET'")->fetchColumn();
$mkHub = fn(string $n) => Hub::create(['name' => $n, 'neighbourhood_id' => $nbId, 'address_note' => null, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
$hubA = $mkHub('P7TEST Hub A'); $hubB = $mkHub('P7TEST Hub B');
Hub::assign($hubA, $ids['hmA'], 'manager'); Hub::assign($hubA, $ids['hoA'], 'operator');
Hub::assign($hubB, $ids['hmB'], 'manager'); Hub::assign($hubB, $ids['hoB'], 'operator');
foreach ([$hubA, $hubB] as $h) {
    Price::create($catId, $h, 1000, 'P7TEST price', $ids['sa']);
}
$U = function (string $k) use (&$ids): array { return User::findById($ids[$k]); };
$saUser = $U('sa');
$mkPicker = function (string $key, string $name, string $phone, int $hub) use ($saUser, $nbId, $PW, &$ids): int {
    $r = PickerService::registerByStaff(['full_name' => $name, 'phone' => $phone, 'neighbourhood_id' => $nbId, 'preferred_hub_id' => $hub, 'national_id' => 'NIN' . substr($phone, -6) . 'ZZ', 'consent' => '1'], $saUser);
    $p = Picker::find((int) $r['id']);
    User::updatePassword((int) $p['user_id'], $PW);
    $ids[$key] = (int) $p['user_id'];
    return (int) $r['id'];
};
$pkA = $mkPicker('pkA', 'P7 Picker A', '0700044101', $hubA);
$pkB = $mkPicker('pkB', 'P7 Picker B', '0700044102', $hubB);
$rr = RecyclerService::registerByStaff(['business_name' => 'P7TEST Recycling', 'contact_person' => 'P7 Rec', 'email' => 'phase7test.r1@example.test', 'phone' => '0700044201', 'address' => 'Gulu', 'consent' => '1'], $saUser);
$rec1 = (int) $rr['id'];
$ids['r1'] = (int) Recycler::find($rec1)['user_id'];
User::updatePassword($ids['r1'], $PW);
$rr2 = RecyclerService::registerByStaff(['business_name' => 'P7TEST Other Co', 'contact_person' => 'P7 Rec2', 'email' => 'phase7test.r2@example.test', 'phone' => '0700044202', 'address' => 'Gulu', 'consent' => '1'], $saUser);
$ids['r2'] = (int) Recycler::find((int) $rr2['id'])['user_id'];
User::updatePassword($ids['r2'], $PW);

$tok = fn() => bin2hex(random_bytes(16));
$mkCol = function (int $hub, int $picker, string $gross, string $rec, string $appr) use ($U, $catId, $tok): int {
    $r = CollectionService::record(['token' => $tok(), 'picker_id' => $picker, 'hub_id' => $hub, 'plastic_category_id' => $catId, 'gross_weight_kg' => $gross, 'tare_weight_kg' => ''], $U($rec));
    return CollectionService::approve((int) $r['id'], $U($appr))['error'] === null ? (int) $r['id'] : 0;
};
$bal = fn(int $pickerId) => LedgerService::balance('picker', $pickerId);

// ---------------------------------------------------------------- earnings come from approved collections
$cA1 = $mkCol($hubA, $pkA, '20', 'hoA', 'hmA');
$cA2 = $mkCol($hubA, $pkA, '10', 'hoA', 'hmA');
$cB1 = $mkCol($hubB, $pkB, '15', 'hoB', 'hmB');
$cB2 = $mkCol($hubB, $pkB, '10', 'hoB', 'hmB');
check('Setup: four collections approved', $cA1 > 0 && $cA2 > 0 && $cB1 > 0 && $cB2 > 0);
check('Approval credits the picker: A 30,000 and B 25,000 (kg x UGX 1,000)', $bal($pkA) === 30000 && $bal($pkB) === 25000);
$e = $pdo->query("SELECT * FROM financial_ledger WHERE source_type = 'collection' AND source_id = $cA1")->fetch();
check('Ledger row: picker account, collection_earning, +20,000, collection reference, who', $e['account_type'] === 'picker' && (int) $e['account_id'] === $pkA && $e['entry_type'] === 'collection_earning'
    && (int) $e['amount_ugx'] === 20000 && str_starts_with((string) $e['reference'], 'COL-') && (int) $e['created_by'] === $ids['hmA']);
$dupe = false;
try { Database::transaction(fn() => LedgerService::post('picker', $pkA, 'collection_earning', 20000, 'collection', $cA1, 'x', null, null)); } catch (App\Services\FinanceException $x) { $dupe = str_contains($x->getMessage(), 'already'); }
check('The same collection can never be credited twice', $dupe && $bal($pkA) === 30000);
check('Ledger check passes on fresh data', FinanceService::verify() === []);

// ---------------------------------------------------------------- HTTP clients, guests, RBAC
$cl = [];
foreach (['sa' => 'sa', 'pm' => 'pm', 'hmA' => 'hma', 'hoA' => 'hoa', 'hmB' => 'hmb', 'r1' => 'r1', 'r2' => 'r2'] as $k => $e2) {
    $cl[$k] = newClient();
    $r = login($cl[$k], "phase7test.$e2@example.test", $PW);
    check("Setup: $k signs in", $r['status'] === 303 && loc($r) !== '/login', 'loc ' . loc($r));
}
$cl['pkA'] = newClient(); login($cl['pkA'], '0700044101', $PW);
$cl['pkB'] = newClient(); login($cl['pkB'], '0700044102', $PW);

$g = newClient();
foreach (['/earnings', '/payouts', '/payments', '/invoices', '/statement', '/finance', '/finance/reconciliation'] as $p) {
    $r = req($g, 'GET', $p);
    check("Guest -> $p redirects to /login", $r['status'] === 303 && loc($r) === '/login');
}
foreach (['/payouts', '/payouts/1/decision', '/payments/1/process', '/payments/1/reverse', '/invoices/1/payment', '/finance/reconciliation'] as $p) {
    check("POST $p without CSRF token refused", req($cl['hmA'], 'POST', $p, ['x' => '1'])['status'] === 400);
}
$matrix = [
    'pkA' => ['/earnings' => 200, '/earnings/statement' => 200, '/payouts' => 200, '/payments' => 200, '/invoices' => 403, '/finance' => 403, '/finance/reconciliation' => 403, "/earnings/$pkB" => 404],
    'r1'  => ['/earnings' => 404, '/payouts' => 404, '/payments' => 200, '/invoices' => 200, '/statement' => 200, '/finance' => 403, '/finance/reconciliation' => 403],
    'hoA' => ['/earnings' => 403, '/payouts' => 403, '/payments' => 403, '/invoices' => 403, '/finance' => 403],
    'hmA' => ['/payouts' => 200, '/payments' => 200, '/invoices' => 200, '/finance' => 403, '/finance/reconciliation' => 403, "/earnings/$pkA" => 200, "/earnings/$pkB" => 404, '/statement' => 404],
    'pm'  => ['/payouts' => 200, '/payments' => 200, '/invoices' => 200, '/finance' => 200, '/finance/reconciliation' => 200, "/earnings/$pkB" => 200],
    'sa'  => ['/payouts' => 200, '/payments' => 200, '/invoices' => 200, '/finance' => 200, '/finance/reconciliation' => 200, "/earnings/$pkA" => 200],
];
foreach ($matrix as $who => $paths) {
    foreach ($paths as $path => $expected) {
        $got = req($cl[$who], 'GET', $path)['status'];
        check("RBAC: $who GET $path = $expected", $got === $expected, "got $got");
    }
}

// ---------------------------------------------------------------- picker requests a payout
$page = req($cl['pkA'], 'GET', '/earnings')['body'];
check('Picker sees real figures: earned 30,000, available 30,000', str_contains($page, 'UGX 30,000') && str_contains($page, 'Available to pay out'));
$rq = fn(string $who, array $over = [], string $form = '/earnings') => postVia($cl[$who], $form, '/payouts', $over + ['token' => $tok(), 'amount_ugx' => '10000', 'method' => 'cash']);
$txt = fn(string $who, array $r) => strip_tags(follow($cl[$who], $r)['body']);
check('Request: amount 0 refused', str_contains($txt('pkA', $rq('pkA', ['amount_ugx' => '0'])), 'between UGX 1,000'));
check('Request: text amount refused', str_contains($txt('pkA', $rq('pkA', ['amount_ugx' => 'lots'])), 'whole number of shillings'));
check('Request: decimal amount refused', str_contains($txt('pkA', $rq('pkA', ['amount_ugx' => '1500.50'])), 'whole number of shillings'));
check('Request: below the minimum refused', str_contains($txt('pkA', $rq('pkA', ['amount_ugx' => '500'])), 'between UGX 1,000'));
check('Request: more than available refused (30,001)', str_contains($txt('pkA', $rq('pkA', ['amount_ugx' => '30001'])), 'Only UGX 30,000 is available'));
check('Request: no method refused', str_contains($txt('pkA', $rq('pkA', ['method' => ''])), 'Choose how'));
check('Failed requests created nothing', (int) $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE picker_id = $pkA")->fetchColumn() === 0);
Config::set('app.finance.payout_max_ugx', 2000);
$x = PayoutService::request(['token' => $tok(), 'amount_ugx' => '3000', 'method' => 'cash'], $U('pkA'));
Config::set('app.finance.payout_max_ugx', 5000000);
check('The maximum single payout is enforced', isset($x['errors']['amount_ugx']) && str_contains($x['errors']['amount_ugx'], 'between UGX 1,000 and UGX 2,000'));

$t1 = $tok();
$r = $rq('pkA', ['token' => $t1, 'amount_ugx' => '10,000']);
check('Valid cash request ("10,000" accepted) -> request page', preg_match('#^/payouts/(\d+)$#', loc($r), $m) === 1, 'loc ' . loc($r));
$q1 = (int) ($m[1] ?? 0);
$row = $pdo->query("SELECT * FROM payment_requests WHERE id = $q1")->fetch();
check('Request: PRQ reference, pending approval, UGX 10,000, cash, no phone, by the picker', preg_match('/^PRQ-\d{8}-\d{6}$/', (string) $row['reference']) === 1 && $row['status'] === 'pending_approval' && (int) $row['amount_ugx'] === 10000
    && $row['method'] === 'cash' && $row['destination_phone'] === null && (int) $row['requested_by'] === $ids['pkA']);
$pos = PayoutService::position($pkA);
check('Pending request holds the money: balance 30,000, held 10,000, available 20,000', $pos === ['balance' => 30000, 'held' => 10000, 'available' => 20000]);
$r2 = req($cl['pkA'], 'POST', '/payouts', ['_csrf' => csrfFrom(req($cl['pkA'], 'GET', '/earnings')['body']), 'token' => $t1, 'amount_ugx' => '10000', 'method' => 'cash']);
check('Double submit makes no second request', loc($r2) === "/payouts/$q1" && (int) $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE submission_token = '$t1'")->fetchColumn() === 1);
$r = $rq('pkA', ['amount_ugx' => '5000', 'method' => 'mobile_money']);
preg_match('#^/payouts/(\d+)$#', loc($r), $m); $q2 = (int) ($m[1] ?? 0);
check('Mobile money request goes to the registered phone (not chosen by anyone)', $pdo->query("SELECT destination_phone FROM payment_requests WHERE id = $q2")->fetchColumn() === '+256700044101');
check('A picker cannot request for another picker (picker_id ignored)', (function () use ($cl, $pkB, $tok, $pdo, $pkA) {
    $r = postVia($cl['pkA'], '/earnings', '/payouts', ['token' => $tok(), 'picker_id' => $pkB, 'amount_ugx' => '1000', 'method' => 'cash']);
    return (int) $pdo->query("SELECT picker_id FROM payment_requests ORDER BY id DESC LIMIT 1")->fetchColumn() === $pkA;
})());
$pdo->exec("UPDATE payment_requests SET status = 'cancelled' WHERE id = (SELECT m FROM (SELECT MAX(id) AS m FROM payment_requests WHERE picker_id = $pkA) t)");   // tidy: that 1,000 request is not part of the plan
check('Operators and recyclers cannot request payouts (403)', postVia($cl['hoA'], '/inventory', '/payouts', ['x' => '1'])['status'] === 403 && postVia($cl['r1'], '/invoices', '/payouts', ['x' => '1'])['status'] === 403);

// race: the picker and a hub manager each ask for 10,000 when only 15,000 is available
$avail = PayoutService::position($pkA)['available'];
check('Before the race 15,000 is available', $avail === 15000, (string) $avail);
$pagePk = req($cl['pkA'], 'GET', '/earnings');
$pageHm = req($cl['hmA'], 'GET', "/earnings/$pkA");
$jobs = [
    ['c' => $cl['pkA'], 'data' => ['_csrf' => csrfFrom($pagePk['body']), 'token' => $tok(), 'amount_ugx' => '10000', 'method' => 'cash']],
    ['c' => $cl['hmA'], 'data' => ['_csrf' => csrfFrom($pageHm['body']), 'token' => $tok(), 'picker_id' => $pkA, 'amount_ugx' => '10000', 'method' => 'cash']],
];
$mh = curl_multi_init(); $hs = [];
foreach ($jobs as $j) {
    $ch = curl_init($base . '/payouts');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($j['data']),
        CURLOPT_COOKIEFILE => $j['c']['jar'], CURLOPT_COOKIEJAR => $j['c']['jar'], CURLOPT_TIMEOUT => 30]);
    curl_multi_add_handle($mh, $ch);
    $hs[] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.2); } while ($running > 0);
$codes = [];
foreach ($hs as $ch) { $codes[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_multi_remove_handle($mh, $ch); }
curl_multi_close($mh);
$made = (int) $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE picker_id = $pkA AND status = 'pending_approval' AND amount_ugx = 10000 AND id <> $q1")->fetchColumn();
check('Race: of two simultaneous 10,000 requests (15,000 available) exactly ONE is accepted', $made === 1, "made=$made");
check('Race: neither request ended in a server error', $codes === [303, 303], json_encode($codes));
check('Race: money held never exceeds the balance', PayoutService::position($pkA)['held'] <= 30000 && PayoutService::position($pkA)['available'] === 5000);
$qRace = (int) $pdo->query("SELECT id FROM payment_requests WHERE picker_id = $pkA AND status = 'pending_approval' AND amount_ugx = 10000 AND id <> $q1")->fetchColumn();

// ---------------------------------------------------------------- approval (a different person)
check('Hub managers and pickers cannot approve (POST 403)', postVia($cl['hmA'], "/payouts/$q1", "/payouts/$q1/decision", ['decision' => 'approve'])['status'] === 403
    && postVia($cl['pkA'], '/earnings', "/payouts/$q1/decision", ['decision' => 'approve'])['status'] === 403);
$x = PayoutService::request(['token' => $tok(), 'picker_id' => $pkA, 'amount_ugx' => '1000', 'method' => 'cash'], $U('sa'));
$q3 = (int) $x['id'];
$r = postVia($cl['sa'], "/payouts/$q3", "/payouts/$q3/decision", ['decision' => 'approve']);
check('The person who requested cannot approve their own request', str_contains($txt('sa', $r), 'someone else must approve') && $pdo->query("SELECT status FROM payment_requests WHERE id = $q3")->fetchColumn() === 'pending_approval');
check('Own request shows no approve button, only a note', str_contains(req($cl['sa'], 'GET', "/payouts/$q3")['body'], 'must approve it') && !str_contains(req($cl['sa'], 'GET', "/payouts/$q3")['body'], 'name="decision" value="approve"'));
$r = postVia($cl['pm'], "/payouts/$qRace", "/payouts/$qRace/decision", ['decision' => 'reject', 'note' => '']);
check('Rejecting needs a reason', str_contains($txt('pm', $r), 'reason') && $pdo->query("SELECT status FROM payment_requests WHERE id = $qRace")->fetchColumn() === 'pending_approval');
postVia($cl['pm'], "/payouts/$qRace", "/payouts/$qRace/decision", ['decision' => 'reject', 'note' => 'Duplicate of an earlier request']);
check('Rejection stores the note, creates no payment and releases the money', $pdo->query("SELECT status FROM payment_requests WHERE id = $qRace")->fetchColumn() === 'rejected'
    && (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE request_id = $qRace")->fetchColumn() === 0 && PayoutService::position($pkA)['available'] === 14000);
foreach ([$q1, $q2, $q3] as $q) {
    postVia($cl['pm'], "/payouts/$q", "/payouts/$q/decision", ['decision' => 'approve']);
}
$p1 = (int) $pdo->query("SELECT id FROM payments WHERE request_id = $q1")->fetchColumn();
$p2 = (int) $pdo->query("SELECT id FROM payments WHERE request_id = $q2")->fetchColumn();
$p3 = (int) $pdo->query("SELECT id FROM payments WHERE request_id = $q3")->fetchColumn();
$row = $pdo->query("SELECT * FROM payments WHERE id = $p1")->fetch();
check('Approval creates one PAY- payment in "pending", linked to the request, with an idempotency key', preg_match('/^PAY-\d{8}-\d{6}$/', (string) $row['reference']) === 1 && $row['status'] === 'pending'
    && $row['direction'] === 'out' && (int) $row['amount_ugx'] === 10000 && $row['idempotency_key'] === "payout-$q1" && $row['provider'] === 'manual');
check('Approving again is refused (no second payment)', str_contains($txt('pm', postVia($cl['pm'], "/payouts/$q1", "/payouts/$q1/decision", ['decision' => 'approve'])), 'Only requests waiting') && (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE request_id = $q1")->fetchColumn() === 1);
$dupKey = false;
try { $pdo->exec("INSERT INTO payments (idempotency_key, direction, picker_id, request_id, amount_ugx, method, provider, status, created_by, created_at) VALUES ('payout-$q1', 'out', $pkA, $q1, 10000, 'cash', 'manual', 'pending', {$ids['pm']}, UTC_TIMESTAMP())"); } catch (PDOException) { $dupKey = true; }
check('The database refuses a second payment with the same idempotency key', $dupKey);
$chk = false;
try { $pdo->exec("INSERT INTO payments (idempotency_key, direction, amount_ugx, method, provider, status, created_by, created_at) VALUES ('p7-bad', 'out', 100, 'cash', 'manual', 'pending', {$ids['pm']}, UTC_TIMESTAMP())"); } catch (PDOException) { $chk = true; }
check('The database refuses a payout with no picker (CHECK)', $chk);

// ---------------------------------------------------------------- paying (manual provider)
check('Operators and pickers cannot process payments (403)', postVia($cl['hoA'], '/inventory', "/payments/$p1/process", [])['status'] === 403 && postVia($cl['pkA'], '/earnings', "/payments/$p1/process", [])['status'] === 403);
$proc = fn(string $who, int $p, array $data) => postVia($cl[$who], "/payments/$p", "/payments/$p/process", $data);
check('Hub B manager cannot process a hub A payment', str_contains($txt('hmB', postVia($cl['hmB'], '/payments', "/payments/$p1/process", ['receipt_note' => 'sneaky'])), 'not found') && $pdo->query("SELECT status FROM payments WHERE id = $p1")->fetchColumn() === 'pending');
$r = $proc('hmA', $p1, ['receipt_note' => '']);
check('Cash payment needs a receipt note (fails, attempt recorded, can retry)', str_contains($txt('hmA', $r), 'receipt note') && $pdo->query("SELECT status FROM payments WHERE id = $p1")->fetchColumn() === 'failed' && $bal($pkA) === 30000);
$proc('hmA', $p1, ['receipt_note' => '<script>alert(1)</script> paid at hub, witness: Okello']);
$row = $pdo->query("SELECT * FROM payments WHERE id = $p1")->fetch();
check('Retry succeeds: successful, processed by the hub manager, request marked paid', $row['status'] === 'successful' && (int) $row['processed_by'] === $ids['hmA'] && $row['failure_reason'] === null && $pdo->query("SELECT status FROM payment_requests WHERE id = $q1")->fetchColumn() === 'paid');
check('Both attempts are kept (1 failed, 2 successful)', array_column($pdo->query("SELECT attempt_no, status FROM payment_attempts WHERE payment_id = $p1 ORDER BY attempt_no")->fetchAll(), 'status') === ['failed', 'successful']);
check('Ledger: payout -10,000 posted once, balance 20,000', $bal($pkA) === 20000 && (int) $pdo->query("SELECT amount_ugx FROM financial_ledger WHERE source_type = 'payment' AND source_id = $p1 AND entry_type = 'payout'")->fetchColumn() === -10000);
$pg = req($cl['hmA'], 'GET', "/payments/$p1")['body'];
check('Receipt note is escaped on the page (XSS)', str_contains($pg, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($pg, '<script>alert(1)'));
check('A successful payment cannot be processed again', str_contains($txt('hmA', $proc('hmA', $p1, ['receipt_note' => 'again again'])), 'Only pending or failed') && $bal($pkA) === 20000);

$r = $proc('hmA', $p2, ['provider_reference' => 'x']);
check('Mobile money needs a real transaction ID', str_contains($txt('hmA', $r), 'transaction ID') && $pdo->query("SELECT status FROM payments WHERE id = $p2")->fetchColumn() === 'failed');
$proc('hmA', $p2, ['provider_reference' => 'MMTEST0001']);
check('Mobile money payout recorded with its reference; balance 15,000', $pdo->query("SELECT status FROM payments WHERE id = $p2")->fetchColumn() === 'successful' && $pdo->query("SELECT provider_reference FROM payments WHERE id = $p2")->fetchColumn() === 'MMTEST0001' && $bal($pkA) === 15000);
$x = PayoutService::request(['token' => $tok(), 'picker_id' => $pkA, 'amount_ugx' => '2000', 'method' => 'mobile_money'], $U('hmA'));
$q4 = (int) $x['id'];
PayoutService::decide($q4, 'approve', null, $U('pm'));
$p4 = (int) $pdo->query("SELECT id FROM payments WHERE request_id = $q4")->fetchColumn();
$r = $proc('hmA', $p4, ['provider_reference' => 'MMTEST0001']);
check('A transaction reference can only be used once (second use refused and rolled back)', str_contains($txt('hmA', $r), 'already been used') && $pdo->query("SELECT status FROM payments WHERE id = $p4")->fetchColumn() === 'pending' && $bal($pkA) === 15000
    && (int) $pdo->query("SELECT COUNT(*) FROM payment_attempts WHERE payment_id = $p4")->fetchColumn() === 0);
$proc('hmA', $p4, ['provider_reference' => 'MMTEST0002']);
$proc('hmA', $p3, ['receipt_note' => 'Paid 1,000 cash at hub']);
check('Two more payouts recorded: balance 12,000', $bal($pkA) === 12000 && $pdo->query("SELECT status FROM payments WHERE id = $p3")->fetchColumn() === 'successful');

// ---------------------------------------------------------------- reversal
check('Hub managers cannot reverse payments (POST 403)', postVia($cl['hmA'], "/payments/$p4", "/payments/$p4/reverse", ['reason' => 'a long enough reason'])['status'] === 403);
check('Reversal needs a clear reason', str_contains($txt('pm', postVia($cl['pm'], "/payments/$p4", "/payments/$p4/reverse", ['reason' => 'x'])), 'at least 5'));
postVia($cl['pm'], "/payments/$p4", "/payments/$p4/reverse", ['reason' => 'Wrong phone number was paid']);
$row = $pdo->query("SELECT * FROM payments WHERE id = $p4")->fetch();
check('Reversal: status reversed with who, when and why; opposite ledger entry +2,000; balance 14,000', $row['status'] === 'reversed' && (int) $row['reversed_by'] === $ids['pm'] && $row['reversal_reason'] === 'Wrong phone number was paid'
    && $bal($pkA) === 14000 && (int) $pdo->query("SELECT amount_ugx FROM financial_ledger WHERE source_type = 'payment' AND source_id = $p4 AND entry_type = 'payout_reversal'")->fetchColumn() === 2000);
check('A reversed payment cannot be reversed or processed again', str_contains($txt('pm', postVia($cl['pm'], "/payments/$p4", "/payments/$p4/reverse", ['reason' => 'reverse it again'])), 'Only successful') && str_contains($txt('hmA', $proc('hmA', $p4, ['provider_reference' => 'MMTEST0003'])), 'Only pending or failed'));
check('Ledger check still passes after payouts and a reversal', FinanceService::verify() === []);

// ---------------------------------------------------------------- cancelling requests
$r = postVia($cl['pkA'], '/earnings', '/payouts', ['token' => $tok(), 'amount_ugx' => '1500', 'method' => 'cash']);
preg_match('#^/payouts/(\d+)$#', loc($r), $m); $q5 = (int) ($m[1] ?? 0);
check('Cancelling needs a reason', str_contains($txt('pkA', postVia($cl['pkA'], "/payouts/$q5", "/payouts/$q5/cancel", ['reason' => ''])), 'reason'));
postVia($cl['pkA'], "/payouts/$q5", "/payouts/$q5/cancel", ['reason' => 'Asked by mistake']);
check('The picker cancels their own pending request; the money is released', $pdo->query("SELECT status FROM payment_requests WHERE id = $q5")->fetchColumn() === 'cancelled' && PayoutService::position($pkA)['available'] === 14000);
$x = PayoutService::request(['token' => $tok(), 'picker_id' => $pkA, 'amount_ugx' => '1500', 'method' => 'cash'], $U('hmA'));
$q6 = (int) $x['id']; PayoutService::decide($q6, 'approve', null, $U('pm'));
$p6 = (int) $pdo->query("SELECT id FROM payments WHERE request_id = $q6")->fetchColumn();
check("Another picker cannot cancel someone else's request", str_contains($txt('pkB', postVia($cl['pkB'], '/earnings', "/payouts/$q6/cancel", ['reason' => 'sneaky cancel'])), 'not found') && $pdo->query("SELECT status FROM payment_requests WHERE id = $q6")->fetchColumn() === 'approved');
postVia($cl['pm'], "/payouts/$q6", "/payouts/$q6/cancel", ['reason' => 'Picker moved away']);
check('Cancelling an approved request also fails its unpaid payment', $pdo->query("SELECT status FROM payment_requests WHERE id = $q6")->fetchColumn() === 'cancelled' && $pdo->query("SELECT status FROM payments WHERE id = $p6")->fetchColumn() === 'failed');
check('A paid request cannot be cancelled', str_contains($txt('pm', postVia($cl['pm'], "/payouts/$q1", "/payouts/$q1/cancel", ['reason' => 'too late to cancel'])), 'not been paid'));
check('A failed (cancelled) payment cannot be processed because its request is no longer approved', str_contains($txt('hmA', $proc('hmA', $p6, ['receipt_note' => 'trying again'])), 'no longer approved'));

// ---------------------------------------------------------------- scoping and privacy
check('Hub B manager cannot see picker A: earnings, requests, payments (404)', req($cl['hmB'], 'GET', "/earnings/$pkA")['status'] === 404 && req($cl['hmB'], 'GET', "/payouts/$q1")['status'] === 404 && req($cl['hmB'], 'GET', "/payments/$p1")['status'] === 404);
$ref1 = (string) $pdo->query("SELECT reference FROM payment_requests WHERE id = $q1")->fetchColumn();
check('Lists are scoped: hub A sees the request, hub B does not; picker B does not', str_contains(req($cl['hmA'], 'GET', '/payouts')['body'], $ref1) && !str_contains(req($cl['hmB'], 'GET', '/payouts')['body'], $ref1) && !str_contains(req($cl['pkB'], 'GET', '/payouts')['body'], $ref1));
check('Picker B cannot open picker A\'s request or payment (404)', req($cl['pkB'], 'GET', "/payouts/$q1")['status'] === 404 && req($cl['pkB'], 'GET', "/payments/$p1")['status'] === 404);
check('Picker A can open their own request and payment', req($cl['pkA'], 'GET', "/payouts/$q1")['status'] === 200 && req($cl['pkA'], 'GET', "/payments/$p1")['status'] === 200);
$show = req($cl['hmA'], 'GET', "/payouts/$q2")['body'];
check('Phone numbers are masked for hub managers and shown in full to the super admin', !str_contains($show, '+256700044101') && str_contains($show, '+256 70* ***101') && str_contains(req($cl['sa'], 'GET', "/payouts/$q2")['body'], '+256700044101'));
$stm = req($cl['pkA'], 'GET', '/earnings/statement')['body'];
check('Income statement: shows the balance and entries, never a phone number or national ID', str_contains($stm, 'Income statement') && str_contains($stm, 'UGX 14,000') && !str_contains($stm, '700044101') && !str_contains($stm, 'NIN') && str_contains($stm, 'window.print()'));
check('Staff can print a picker statement for their pickers only', req($cl['hmA'], 'GET', "/earnings/$pkA/statement")['status'] === 200 && req($cl['hmB'], 'GET', "/earnings/$pkA/statement")['status'] === 404);
$today = Dates::todayYmd();
$stmP = req($cl['pkA'], 'GET', "/earnings/statement?from=$today&to=$today")['body'];
check('Statement period filter works (opening balance + movements = closing)', str_contains($stmP, 'Closing balance') && str_contains($stmP, 'UGX 14,000'));
check('Garbage statement dates are ignored safely', req($cl['pkA'], 'GET', '/earnings/statement?from=zzz&to=2026-13-40')['status'] === 200);

// ---------------------------------------------------------------- mock provider + polling (picker B, in-process)
Config::set('app.finance.provider', 'mock');
$x = PayoutService::request(['token' => $tok(), 'picker_id' => $pkB, 'amount_ugx' => '2000', 'method' => 'cash'], $U('hmB'));
$qm1 = (int) $x['id']; PayoutService::decide($qm1, 'approve', null, $U('pm'));
$pm1 = (int) $pdo->query("SELECT id FROM payments WHERE request_id = $qm1")->fetchColumn();
check('With the mock provider the payment is created for the "mock" provider', $pdo->query("SELECT provider FROM payments WHERE id = $pm1")->fetchColumn() === 'mock');
$r = PaymentService::process($pm1, [], $U('hmB'));
check('Mock: processing leaves the payment "processing" with a provider reference and no ledger entry yet', $r['error'] === null && $pdo->query("SELECT status FROM payments WHERE id = $pm1")->fetchColumn() === 'processing'
    && str_starts_with((string) $pdo->query("SELECT provider_reference FROM payments WHERE id = $pm1")->fetchColumn(), 'MOCK-') && $bal($pkB) === 25000);
check('A processing payment cannot be processed again', PaymentService::process($pm1, [], $U('hmB'))['error'] !== null);
$x = PayoutService::request(['token' => $tok(), 'picker_id' => $pkB, 'amount_ugx' => '1300', 'method' => 'cash'], $U('hmB'));
$qm2 = (int) $x['id']; PayoutService::decide($qm2, 'approve', null, $U('pm'));
$pm2 = (int) $pdo->query("SELECT id FROM payments WHERE request_id = $qm2")->fetchColumn();
PaymentService::process($pm2, [], $U('hmB'));
$n = PaymentService::pollProcessing();
check('Polling settles both: 2,000 succeeds, 1,300 (multiple of 13) fails in the simulator', $n === 2 && $pdo->query("SELECT status FROM payments WHERE id = $pm1")->fetchColumn() === 'successful' && $pdo->query("SELECT status FROM payments WHERE id = $pm2")->fetchColumn() === 'failed');
check('Polled success posted to the ledger (balance 23,000); the failure posted nothing and its request stays approved', $bal($pkB) === 23000 && $pdo->query("SELECT status FROM payment_requests WHERE id = $qm2")->fetchColumn() === 'approved');
check('Polling again changes nothing (no double posting)', PaymentService::pollProcessing() === 0 && $bal($pkB) === 23000 && (int) $pdo->query("SELECT COUNT(*) FROM financial_ledger WHERE source_type = 'payment' AND source_id = $pm1")->fetchColumn() === 1);
check('The job command works (php jobs.php poll-payments)', trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/jobs.php') . ' poll-payments')) === 'Updated 0 processing payment(s).');
PayoutService::cancel($qm2, 'Simulator failure, request withdrawn', $U('pm'));
Config::set('app.finance.provider', 'manual');

// ---------------------------------------------------------------- reversing a collection after the picker was paid (negative balance, recovered later)
$xq = PayoutService::request(['token' => $tok(), 'picker_id' => $pkB, 'amount_ugx' => '20000', 'method' => 'cash'], $U('hmB'));
PayoutService::decide((int) $xq['id'], 'approve', null, $U('pm'));
$pb = (int) $pdo->query("SELECT id FROM payments WHERE request_id = {$xq['id']}")->fetchColumn();
PaymentService::process($pb, ['receipt_note' => 'Paid 20,000 cash at hub B'], $U('hmB'));
check('Picker B paid 20,000 in cash: balance 3,000', $bal($pkB) === 3000);
$rv = CollectionService::reverse($cB1, 'Picker delivered the wrong material', $U('pm'));
check('Reversing a collection takes the earnings back (-15,000) and the balance goes negative (-12,000)', $rv['error'] === null && $bal($pkB) === -12000);
check('The reversal warns that the picker was already paid and will be recovered from future earnings', str_contains((string) $rv['message'], 'WARNING') && str_contains((string) $rv['message'], 'recovered from their future earnings'));
check('A negative balance leaves nothing available to pay out and blocks new requests', PayoutService::position($pkB)['available'] === 0
    && isset(PayoutService::request(['token' => $tok(), 'picker_id' => $pkB, 'amount_ugx' => '1000', 'method' => 'cash'], $U('hmB'))['errors']['amount_ugx']));
check('The picker sees the negative balance explained', str_contains(req($cl['pkB'], 'GET', '/earnings')['body'], 'recovered from future earnings'));
check('Finance summary counts the negative account', FinanceService::summary()['negative_accounts'] >= 1);
$mkCol($hubB, $pkB, '20', 'hoB', 'hmB');
check('New earnings recover it: -12,000 + 20,000 = 8,000', $bal($pkB) === 8000);
$xq2 = PayoutService::request(['token' => $tok(), 'picker_id' => $pkB, 'amount_ugx' => '6000', 'method' => 'cash'], $U('hmB'));
PayoutService::decide((int) $xq2['id'], 'approve', null, $U('pm'));
$pg2 = (int) $pdo->query("SELECT id FROM payments WHERE request_id = {$xq2['id']}")->fetchColumn();
CollectionService::reverse($cB2, 'Duplicate entry, recorded twice', $U('pm'));
check('Balance now -2,000 (a collection was reversed while a payout was pending)', $bal($pkB) === -2000);
$r = PaymentService::process($pg2, ['receipt_note' => 'trying to pay anyway'], $U('hmB'));
check('Processing is refused when the balance no longer covers the payment', $r['error'] !== null && str_contains($r['error'], 'no longer covers') && $pdo->query("SELECT status FROM payments WHERE id = $pg2")->fetchColumn() === 'pending');
$r = PayoutService::decide((int) $xq2['id'], 'approve', null, $U('pm'));
PayoutService::cancel((int) $xq2['id'], 'Balance no longer covers it', $U('pm'));
check('Ledger check passes after reversals and negative balances', FinanceService::verify() === []);

// ---------------------------------------------------------------- invoices: orders for recycler 1 at hub A (30 kg in stock)
$rec = $U('r1'); $hmA = $U('hmA');
$order = function (string $kg, string $price) use ($rec, $hmA, $hubA, $catId, $tok): int {
    $c = OrderService::create(['token' => $tok(), 'hub_id' => $hubA, 'plastic_category_id' => $catId, 'quantity_kg' => $kg, 'price_per_kg_ugx' => $price], $rec);
    OrderService::accept((int) $c['id'], $hmA);
    return (int) $c['id'];
};
$load = fn(int $o, string $kg) => OrderService::dispatch($o, ['token' => $tok(), 'quantity_kg' => $kg], $hmA);
$recv = function (int $o, string $kg, string $note = '') use ($rec, $pdo): array {
    $res = [];
    foreach ($pdo->query("SELECT id FROM stock_dispatches WHERE purchase_order_id = $o AND status = 'dispatched' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $res = OrderService::receive($o, (int) $d, ['received_kg' => $kg, 'note' => $note], $rec);
    }
    return $res;
};
$o1 = $order('10', '1250');
$load($o1, '10');
check('No invoice exists while the order is still being delivered', (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id = $o1")->fetchColumn() === 0);
$res1 = $recv($o1, '9.5', 'One sack split open');
check('Completing the order issues the invoice automatically', str_contains((string) $res1['message'], 'invoiced') && (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id = $o1")->fetchColumn() === 1);
$inv1 = (int) $pdo->query("SELECT id FROM invoices WHERE order_id = $o1")->fetchColumn();
$row = $pdo->query("SELECT * FROM invoices WHERE id = $inv1")->fetch();
check('Invoice = CONFIRMED received kg x agreed price: 9.5 x 1,250 = UGX 11,875', $row['received_kg'] === '9.50' && (int) $row['price_per_kg_ugx'] === 1250 && (int) $row['amount_ugx'] === 11875 && $row['status'] === 'issued');
$due = (new DateTimeImmutable(Dates::todayYmd()))->modify('+14 days')->format('Y-m-d');
check('Invoice number format and 14-day payment terms', preg_match('/^INV-\d{8}-\d{6}$/', (string) $row['invoice_number']) === 1 && $row['due_date'] === $due);
$led = $pdo->query("SELECT * FROM financial_ledger WHERE source_type = 'invoice' AND source_id = $inv1")->fetch();
check('Ledger: recycler account +11,875 (invoice_issued); balance owed 11,875', $led['account_type'] === 'recycler' && $led['entry_type'] === 'invoice_issued' && (int) $led['amount_ugx'] === 11875 && LedgerService::balance('recycler', $rec1) === 11875);
check('An order is invoiced at most once (job finds nothing; unique key in the database)', InvoiceService::generateMissing() === 0 && InvoiceService::tryIssue($o1, null) === null);
$dupInv = false;
try { $pdo->exec("INSERT INTO invoices (order_id, recycler_id, hub_id, received_kg, price_per_kg_ugx, amount_ugx, issued_at, due_date) VALUES ($o1, $rec1, $hubA, 1, 1, 1, UTC_TIMESTAMP(), '2030-01-01')"); } catch (PDOException) { $dupInv = true; }
check('The database refuses a second invoice for the same order', $dupInv);

// closed short: only what arrived is billed
$o2 = $order('12', '1000'); $load($o2, '8'); $recv($o2, '8');
check('Order 2 (12 kg agreed, 8 sent and confirmed) is not invoiced until it completes', (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id = $o2")->fetchColumn() === 0);
OrderService::closeShort($o2, 'Recycler cannot take more', $hmA);
$inv2 = (int) $pdo->query("SELECT id FROM invoices WHERE order_id = $o2")->fetchColumn();
check('Closing short invoices only what arrived: 8 kg x 1,000 = UGX 8,000', (int) $pdo->query("SELECT amount_ugx FROM invoices WHERE id = $inv2")->fetchColumn() === 8000);
// closed short while a load is still unconfirmed: invoice waits for the confirmation
$o3 = $order('8', '900'); $load($o3, '5');
OrderService::closeShort($o3, 'Rest will not be delivered', $hmA);
check('Closed short with an unconfirmed load: no invoice yet', (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE order_id = $o3")->fetchColumn() === 0);
$recv($o3, '5');
$inv3 = (int) $pdo->query("SELECT id FROM invoices WHERE order_id = $o3")->fetchColumn();
check('...the invoice is issued as soon as the recycler confirms the last load: 5 kg x 900 = UGX 4,500', $inv3 > 0 && (int) $pdo->query("SELECT amount_ugx FROM invoices WHERE id = $inv3")->fetchColumn() === 4500);
check('Recycler balance = sum of invoices (11,875 + 8,000 + 4,500 = 24,375)', LedgerService::balance('recycler', $rec1) === 24375);

// who can see invoices
check('Recycler 1 opens their invoice; recycler 2 and hub B get 404; hub A manager, project manager and super admin can open it', req($cl['r1'], 'GET', "/invoices/$inv1")['status'] === 200 && req($cl['r2'], 'GET', "/invoices/$inv1")['status'] === 404
    && req($cl['hmB'], 'GET', "/invoices/$inv1")['status'] === 404 && req($cl['hmA'], 'GET', "/invoices/$inv1")['status'] === 200 && req($cl['pm'], 'GET', "/invoices/$inv1")['status'] === 200 && req($cl['sa'], 'GET', "/invoices/$inv1")['status'] === 200);
$invNo1 = (string) $row['invoice_number'];
check('Invoice lists are scoped', str_contains(req($cl['r1'], 'GET', '/invoices')['body'], $invNo1) && !str_contains(req($cl['r2'], 'GET', '/invoices')['body'], $invNo1) && !str_contains(req($cl['hmB'], 'GET', '/invoices')['body'], $invNo1));
$ip = req($cl['r1'], 'GET', "/invoices/$inv1")['body'];
check('Invoice page shows kg, price, total and no recording/void forms for the recycler', str_contains($ip, '9.5 kg') && str_contains($ip, 'UGX 11,875') && !str_contains($ip, 'Record money received') && !str_contains($ip, 'Void this invoice'));
check('Recycler cannot record payments or void (POST 403)', postVia($cl['r1'], "/invoices/$inv1", "/invoices/$inv1/payment", ['x' => '1'])['status'] === 403 && postVia($cl['r1'], "/invoices/$inv1", "/invoices/$inv1/void", ['reason' => 'void it please'])['status'] === 403);
check('Hub managers cannot record or void either (POST 403)', postVia($cl['hmA'], "/invoices/$inv1", "/invoices/$inv1/payment", ['x' => '1'])['status'] === 403);

// ---------------------------------------------------------------- recycler payments (recorded by one person, confirmed by another)
$rcp = fn(string $who, int $inv, array $over = []) => postVia($cl[$who], "/invoices/$inv", "/invoices/$inv/payment", $over + ['token' => $tok(), 'amount_ugx' => '5000', 'method' => 'mobile_money', 'provider_reference' => 'MTNREF0001', 'received_on' => $today]);
check('Receipt: zero amount refused', str_contains($txt('pm', $rcp('pm', $inv1, ['amount_ugx' => '0'])), 'whole number'));
check('Receipt: more than the invoice refused', str_contains($txt('pm', $rcp('pm', $inv1, ['amount_ugx' => '11876'])), 'Only UGX 11,875 is still open'));
check('Receipt: no method refused', str_contains($txt('pm', $rcp('pm', $inv1, ['method' => ''])), 'how the money was received'));
check('Receipt: mobile money without a reference refused', str_contains($txt('pm', $rcp('pm', $inv1, ['provider_reference' => ''])), 'reference'));
check('Receipt: future date refused', str_contains($txt('pm', $rcp('pm', $inv1, ['received_on' => '2099-01-01'])), 'cannot be in the future'));
check('Receipt: invalid date refused', str_contains($txt('pm', $rcp('pm', $inv1, ['received_on' => 'yesterday'])), 'Enter the date'));
$rk = $tok();
$r = $rcp('pm', $inv1, ['token' => $rk]);
check('Valid partial receipt recorded as pending', preg_match('#^/payments/(\d+)$#', loc($r), $m) === 1);
$pr1 = (int) ($m[1] ?? 0);
$row = $pdo->query("SELECT * FROM payments WHERE id = $pr1")->fetch();
check('Receipt: RCP reference, direction in, pending, UGX 5,000, linked to the invoice and recycler', preg_match('/^RCP-\d{8}-\d{6}$/', (string) $row['reference']) === 1 && $row['direction'] === 'in' && $row['status'] === 'pending' && (int) $row['amount_ugx'] === 5000
    && (int) $row['invoice_id'] === $inv1 && (int) $row['recycler_id'] === $rec1);
check('A pending receipt does not count: invoice still issued, ledger unchanged', $pdo->query("SELECT status FROM invoices WHERE id = $inv1")->fetchColumn() === 'issued' && LedgerService::balance('recycler', $rec1) === 24375);
$r2 = req($cl['pm'], 'POST', "/invoices/$inv1/payment", ['_csrf' => csrfFrom(req($cl['pm'], 'GET', "/invoices/$inv1")['body']), 'token' => $rk, 'amount_ugx' => '5000', 'method' => 'mobile_money', 'provider_reference' => 'MTNREF0001', 'received_on' => $today]);
check('Double submit records nothing twice', loc($r2) === "/payments/$pr1" && (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE invoice_id = $inv1 AND direction = 'in'")->fetchColumn() === 1);
check('Pending receipts count against what is still open (7,000 refused: only 6,875 open)', str_contains($txt('pm', $rcp('pm', $inv1, ['amount_ugx' => '7000', 'provider_reference' => 'MTNREF0002'])), 'Only UGX 6,875 is still open'));
check('The same transaction reference cannot be recorded twice', str_contains($txt('sa', $rcp('sa', $inv2, ['amount_ugx' => '1000'])), 'already been used'));
$rc = fn(string $who, int $p, array $data) => postVia($cl[$who], "/payments/$p", "/payments/$p/receipt-decision", $data);
check('The person who recorded it cannot confirm it', str_contains($txt('pm', $rc('pm', $pr1, ['decision' => 'confirm'])), 'someone else must confirm') && $pdo->query("SELECT status FROM payments WHERE id = $pr1")->fetchColumn() === 'pending');
check('Hub managers cannot confirm (POST 403)', postVia($cl['hmA'], "/payments/$pr1", "/payments/$pr1/receipt-decision", ['decision' => 'confirm'])['status'] === 403);
$rc('sa', $pr1, ['decision' => 'confirm']);
$row = $pdo->query("SELECT * FROM payments WHERE id = $pr1")->fetch();
check('A different person confirms: successful, confirmed by them', $row['status'] === 'successful' && (int) $row['confirmed_by'] === $ids['sa'] && $row['confirmed_at'] !== null);
check('Invoice becomes partially paid; ledger -5,000; recycler owes 19,375 in total (6,875 on this invoice)', $pdo->query("SELECT status FROM invoices WHERE id = $inv1")->fetchColumn() === 'partially_paid' && LedgerService::balance('recycler', $rec1) === 19375
    && (int) $pdo->query("SELECT amount_ugx FROM financial_ledger WHERE source_type = 'payment' AND source_id = $pr1")->fetchColumn() === -5000);
$r = $rcp('sa', $inv1, ['amount_ugx' => '6875', 'method' => 'cash', 'provider_reference' => '']);
preg_match('#^/payments/(\d+)$#', loc($r), $m); $pr2 = (int) ($m[1] ?? 0);
$rc('pm', $pr2, ['decision' => 'confirm']);
check('Final cash receipt (6,875) confirmed: invoice PAID, nothing outstanding on it', $pdo->query("SELECT status FROM invoices WHERE id = $inv1")->fetchColumn() === 'paid' && LedgerService::balance('recycler', $rec1) === 12500);
check('A paid invoice accepts no more payments and cannot be voided', str_contains($txt('pm', $rcp('pm', $inv1, ['amount_ugx' => '100', 'provider_reference' => 'MTNREF0009'])), 'open invoice') && str_contains($txt('pm', postVia($cl['pm'], "/invoices/$inv1", "/invoices/$inv1/void", ['reason' => 'void the paid invoice'])), 'Only an unpaid'));
// rejecting a receipt
$r = $rcp('pm', $inv2, ['amount_ugx' => '3000', 'method' => 'bank', 'provider_reference' => 'BANKREF2001']);
preg_match('#^/payments/(\d+)$#', loc($r), $m); $pr3 = (int) ($m[1] ?? 0);
check('Rejecting a receipt needs a reason', str_contains($txt('sa', $rc('sa', $pr3, ['decision' => 'reject', 'note' => ''])), 'reason'));
$rc('sa', $pr3, ['decision' => 'reject', 'note' => 'Not on the bank statement']);
check('A rejected receipt does not count and the invoice is unchanged', $pdo->query("SELECT status FROM payments WHERE id = $pr3")->fetchColumn() === 'failed' && $pdo->query("SELECT status FROM invoices WHERE id = $inv2")->fetchColumn() === 'issued' && LedgerService::balance('recycler', $rec1) === 12500);
// reversing a received payment
check('Hub managers cannot reverse a receipt (POST 403)', postVia($cl['hmA'], "/payments/$pr1", "/payments/$pr1/reverse", ['reason' => 'a long enough reason'])['status'] === 403);
postVia($cl['pm'], "/payments/$pr1", "/payments/$pr1/reverse", ['reason' => 'Bounced: the transfer was recalled']);
check('Reversing a received payment: invoice goes back to partially paid, ledger +5,000, balance 17,500', $pdo->query("SELECT status FROM payments WHERE id = $pr1")->fetchColumn() === 'reversed' && $pdo->query("SELECT status FROM invoices WHERE id = $inv1")->fetchColumn() === 'partially_paid' && LedgerService::balance('recycler', $rec1) === 17500);
// voiding an unpaid invoice
check('Void needs a clear reason and the invoice must be unpaid', str_contains($txt('pm', postVia($cl['pm'], "/invoices/$inv3", "/invoices/$inv3/void", ['reason' => 'x'])), 'at least 5'));
check('Hub managers cannot void (POST 403)', postVia($cl['hmA'], "/invoices/$inv3", "/invoices/$inv3/void", ['reason' => 'void this please'])['status'] === 403);
postVia($cl['pm'], "/invoices/$inv3", "/invoices/$inv3/void", ['reason' => 'Order was invoiced to the wrong company']);
check('Voided: status void, ledger -4,500, balance 13,000, no payments possible', $pdo->query("SELECT status FROM invoices WHERE id = $inv3")->fetchColumn() === 'void' && LedgerService::balance('recycler', $rec1) === 13000
    && str_contains($txt('pm', $rcp('pm', $inv3, ['amount_ugx' => '100', 'provider_reference' => 'MTNREF0010'])), 'open invoice'));
check('Ledger check passes after invoices, receipts, rejection, reversal and void', FinanceService::verify() === [], implode(' | ', FinanceService::verify()));

// ---------------------------------------------------------------- overdue, aging, account statement
$pdo->exec("UPDATE invoices SET due_date = '" . (new DateTimeImmutable(Dates::todayYmd()))->modify('-40 days')->format('Y-m-d') . "' WHERE id = $inv2");
$sum = FinanceService::summary();
check('Overdue: 8,000 sits in the 31-60 days bucket; summary counts it', $sum['aging']['d31_60'] >= 8000 && $sum['overdue'] >= 8000);
check('Invoice list can filter overdue invoices and shows the overdue badge', str_contains(req($cl['pm'], 'GET', '/invoices?status=overdue')['body'], (string) $pdo->query("SELECT invoice_number FROM invoices WHERE id = $inv2")->fetchColumn()) && str_contains(req($cl['r1'], 'GET', '/invoices')['body'], 'Overdue'));
check('Recycler dashboard shows what they owe and overdue invoices', str_contains(req($cl['r1'], 'GET', '/recycler')['body'], 'UGX 13,000') && str_contains(req($cl['r1'], 'GET', '/recycler')['body'], 'Overdue invoices'));
$st = req($cl['r1'], 'GET', '/statement')['body'];
check('Account statement shows the balance from the ledger and its entries', str_contains($st, 'UGX 13,000') && str_contains($st, 'Invoice issued') && str_contains($st, 'Payment received') && str_contains($st, 'Payment reversed') && str_contains($st, 'Invoice voided'));

// ---------------------------------------------------------------- reconciliation
$rcn = fn(string $who, array $data) => postVia($cl[$who], '/finance/reconciliation', '/finance/reconciliation', $data);
$recPage = req($cl['pm'], 'GET', '/finance/reconciliation')['body'];
check('Reconciliation lists successful, unreconciled payments (not failed or reversed ones)', str_contains($recPage, (string) $pdo->query("SELECT reference FROM payments WHERE id = $p1")->fetchColumn()) && !str_contains($recPage, (string) $pdo->query("SELECT reference FROM payments WHERE id = $p4")->fetchColumn()));
check('Hub managers cannot reconcile (POST 403)', postVia($cl['hmA'], '/payouts', '/finance/reconciliation', ['payment_ids' => [$p1], 'statement_reference' => 'STMT-1'])['status'] === 403);
check('A statement reference is required', str_contains($txt('pm', $rcn('pm', ['payment_ids' => [$p1], 'statement_reference' => ''])), 'statement reference'));
check('Ticking nothing is refused', str_contains($txt('pm', $rcn('pm', ['statement_reference' => 'STMT-2026-11'])), 'Tick at least one'));
check('Only successful payments can be reconciled (pending/failed/reversed are skipped)', str_contains($txt('pm', $rcn('pm', ['payment_ids' => [$p4, $p6, $pr3], 'statement_reference' => 'STMT-2026-11'])), 'None of the ticked payments'));
$sumBefore = App\Services\ReconciliationService::summary(null, null);
$r = $rcn('pm', ['payment_ids' => [$p1, $p2, $pr2], 'statement_reference' => 'MTN STMT 2026-11']);
$row = $pdo->query("SELECT * FROM payments WHERE id = $p1")->fetch();
check('Three payments reconciled with who, when and the statement reference', str_contains($txt('pm', $r), '3 payment(s) reconciled') && (int) $row['reconciled_by'] === $ids['pm'] && $row['reconciled_at'] !== null && $row['statement_reference'] === 'MTN STMT 2026-11');
$sumAfter = App\Services\ReconciliationService::summary(null, null);
check('Reconciliation summary moves 3 payments from unreconciled to reconciled', $sumAfter['out']['reconciled'][0] + $sumAfter['in']['reconciled'][0] === $sumBefore['out']['reconciled'][0] + $sumBefore['in']['reconciled'][0] + 3);
check('Reconciling again skips already-reconciled payments', str_contains($txt('pm', $rcn('pm', ['payment_ids' => [$p1], 'statement_reference' => 'MTN STMT 2026-11'])), 'None of the ticked payments'));
check('Reconciled items leave the default (unreconciled) view but show under "Reconciled"', !str_contains(req($cl['pm'], 'GET', '/finance/reconciliation')['body'], 'MTN STMT 2026-11') && str_contains(req($cl['pm'], 'GET', '/finance/reconciliation?reconciled=1')['body'], 'MTN STMT 2026-11'));

// ---------------------------------------------------------------- finance summary and the ledger check
$s = FinanceService::summary();
$earnedDb = (int) $pdo->query("SELECT COALESCE(SUM(total_value_ugx),0) FROM collection_transactions WHERE status = 'approved'")->fetchColumn();
check('Finance summary equals the database: earned = approved collections; paid = successful payouts', $s['earned'] === $earnedDb && $s['paid'] === (int) $pdo->query("SELECT COALESCE(SUM(amount_ugx),0) FROM payments WHERE direction = 'out' AND status = 'successful'")->fetchColumn());
check('Owed to pickers = earned - paid; owed by recyclers = invoiced - received', $s['owed'] === $s['earned'] - $s['paid'] && $s['outstanding'] === $s['invoiced'] - $s['received']);
$fp = req($cl['pm'], 'GET', '/finance')['body'];
check('Finance page: ledger check passed, figures and aging shown', str_contains($fp, 'Ledger check passed') && str_contains($fp, 'Receivables aging') && str_contains($fp, 'Still owed to pickers') && str_contains($fp, 'Earned by pickers'));
$pdo->exec("INSERT INTO financial_ledger (account_type, account_id, entry_type, amount_ugx, source_type, source_id, created_at) VALUES ('picker', $pkA, 'collection_earning', 500, 'p7test', 1, UTC_TIMESTAMP())");
check('A bogus ledger entry is detected by the ledger check and shown as a warning', count(FinanceService::verify()) === 1 && str_contains(req($cl['pm'], 'GET', '/finance')['body'], 'Ledger check FAILED'));
$pdo->exec("DELETE FROM financial_ledger WHERE source_type = 'p7test'");
check('The warning clears once the ledger agrees again', FinanceService::verify() === [] && str_contains(req($cl['pm'], 'GET', '/finance')['body'], 'Ledger check passed'));

// ---------------------------------------------------------------- dashboards, navigation, audit
check('Picker dashboard shows the available balance', str_contains(req($cl['pkA'], 'GET', '/picker')['body'], 'Available to pay out') && str_contains(req($cl['pkA'], 'GET', '/picker')['body'], 'UGX 14,000'));
check('Admin dashboard shows the money section', str_contains(req($cl['pm'], 'GET', '/admin')['body'], 'Owed to pickers') && str_contains(req($cl['pm'], 'GET', '/admin')['body'], 'Finance summary and ledger check'));
check('Sidebar: picker sees My earnings; project manager sees Finance and Reconciliation; recycler sees Invoices and Account statement; hub manager sees no Finance',
    str_contains(req($cl['pkA'], 'GET', '/picker')['body'], '>My earnings<') && str_contains(req($cl['pm'], 'GET', '/admin')['body'], '>Reconciliation<') && str_contains(req($cl['r1'], 'GET', '/recycler')['body'], '>Account statement<')
    && !str_contains(req($cl['hmA'], 'GET', '/hub')['body'], '>Finance<'));
foreach (['payout.requested', 'payout.approved', 'payout.rejected', 'payout.cancelled', 'payment.successful', 'payment.failed', 'payment.reversed', 'invoice.issued', 'invoice.voided',
          'receipt.recorded', 'receipt.confirmed', 'receipt.rejected', 'payment.reconciled', 'payment.processing'] as $a) {
    check("Audit logged: $a", (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = '$a'")->fetchColumn() >= 1);
}
check('Audit trail never contains passwords, tokens or transaction IDs in full', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE details LIKE '%$PW%' OR details LIKE '%MTNREF0001%' OR details LIKE '%MMTEST0001%'")->fetchColumn() === 0);

// ---------------------------------------------------------------- teardown
$cleanup();
check('Cleanup left no test data and the ledger still agrees', (int) $pdo->query("SELECT COUNT(*) FROM collection_hubs WHERE name LIKE 'P7TEST%'")->fetchColumn() === 0 && FinanceService::verify() === [] && App\Models\StockBalance::mismatches() === []);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
