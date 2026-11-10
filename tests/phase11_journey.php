<?php
declare(strict_types=1);

/**
 * Phase 11 integration test: one complete story through the real web application, then the whole-system checks.
 *   php tests/phase11_journey.php [base-url]
 *
 *  1. A waste picker registers on the public form        6. A recycler buys plastic: order, load, delivery, invoice
 *  2. A hub manager approves the registration            7. Payment recorded by one person, confirmed by another
 *  3. A hub operator weighs and records a collection     8. A collection is reversed
 *  4. Another person approves it: earnings, stock, SMS   9. Reports, map, programme figures agree with the database
 *  5. The picker asks for a payout; approval; payment   10. Ledgers verify; a backup is made and PROVES it can be restored
 *
 * Uses the web interface for what people do and services only for set-up. Creates temporary data (phase11j.*) and removes it afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/_http.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\Hub;
use App\Models\Neighbourhood;
use App\Models\Payment;
use App\Models\Picker;
use App\Models\Price;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\FinanceService;
use App\Services\LedgerService;
use App\Services\MonitoringService;
use App\Services\OrderService;
use App\Services\RecyclerService;
use App\Services\ReportService;
use App\Services\Reports\CollectionsReport;

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase11Journey!pw';
$pass = 0;
$fail = 0;

$cleanup = function () use ($pdo): void {
    $ids = fn(string $sql): string => implode(',', array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    $hub = $ids("SELECT id FROM collection_hubs WHERE name LIKE 'P11JTEST%'");
    $usr = $ids("SELECT id FROM users WHERE email LIKE 'phase11j.%' OR phone_e164 LIKE '+25670008%'");
    $pk = $ids("SELECT id FROM pickers WHERE user_id IN ($usr)");
    $ord = $ids("SELECT id FROM purchase_orders WHERE hub_id IN ($hub)");
    $inv = $ids("SELECT id FROM invoices WHERE order_id IN ($ord)");
    $pay = $ids("SELECT id FROM payments WHERE picker_id IN ($pk) OR invoice_id IN ($inv)");
    $req = $ids("SELECT id FROM payment_requests WHERE picker_id IN ($pk)");
    $dsp = $ids("SELECT id FROM stock_dispatches WHERE hub_id IN ($hub)");
    $col = $ids("SELECT id FROM collection_transactions WHERE hub_id IN ($hub) OR recorded_by IN ($usr)");
    $prc = $ids("SELECT id FROM price_lists WHERE hub_id IN ($hub) OR note LIKE 'P11JTEST%'");
    $rec = $ids("SELECT id FROM recyclers WHERE user_id IN ($usr)");
    $nb = $ids("SELECT id FROM neighbourhoods WHERE name LIKE 'P11JTEST%'");
    $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($usr) OR (entity_type = 'payment' AND entity_id IN ($pay)) OR (entity_type = 'payout_request' AND entity_id IN ($req)) OR (entity_type = 'invoice' AND entity_id IN ($inv))
                OR (entity_type = 'order' AND entity_id IN ($ord)) OR (entity_type = 'dispatch' AND entity_id IN ($dsp)) OR (entity_type = 'collection' AND entity_id IN ($col)) OR (entity_type = 'price' AND entity_id IN ($prc))
                OR (entity_type IN ('user', 'picker') AND entity_id IN ($usr, $pk)) OR (entity_type = 'hub' AND entity_id IN ($hub)) OR (entity_type = 'recycler' AND entity_id IN ($rec)) OR (entity_type = 'neighbourhood' AND entity_id IN ($nb))");
    $pdo->exec("DELETE FROM financial_ledger WHERE (source_type = 'collection' AND source_id IN ($col)) OR (source_type = 'invoice' AND source_id IN ($inv)) OR (source_type = 'payment' AND source_id IN ($pay))");
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
    $pdo->exec("DELETE FROM recyclers WHERE id IN ($rec)");
    $pdo->exec("DELETE FROM collection_hubs WHERE id IN ($hub)");
    $pdo->exec("DELETE FROM neighbourhoods WHERE id IN ($nb)");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase11j%' OR identifier LIKE '%700080%'");
    $pdo->exec("DELETE FROM users WHERE id IN ($usr)");
    $pdo->exec('DELETE FROM sms_outbox WHERE user_id IS NULL');
};
$cleanup();

// ---------------------------------------------------------------- set-up: staff, a hub, a price, a recycler (the people who already exist)
$ids = [];
foreach (['super_admin' => 'sa', 'project_manager' => 'pm', 'hub_manager' => 'hm', 'hub_operator' => 'ho'] as $role => $k) {
    $ids[$k] = User::create(['role' => $role, 'full_name' => "J $k", 'email' => "phase11j.$k@example.test", 'password' => $PW, 'status' => 'active']);
}
$nbId = Neighbourhood::create('P11JTEST Village');
$catId = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code = 'PET'")->fetchColumn();
$hub = Hub::create(['name' => 'P11JTEST Hub', 'neighbourhood_id' => $nbId, 'address_note' => null, 'latitude' => 2.77, 'longitude' => 32.3, 'status' => 'active']);
Hub::assign($hub, $ids['hm'], 'manager');
Hub::assign($hub, $ids['ho'], 'operator');
Price::create($catId, $hub, 1000, 'P11JTEST price', $ids['sa']);
$sa = User::findById($ids['sa']);
$rr = RecyclerService::registerByStaff(['business_name' => 'P11JTEST Recycling', 'contact_person' => 'J Rec', 'email' => 'phase11j.rec@example.test', 'phone' => '0700080201', 'address' => 'Gulu', 'consent' => '1'], $sa);
$recId = (int) $rr['id'];
$ids['rec'] = (int) \App\Models\Recycler::find($recId)['user_id'];
User::updatePassword($ids['rec'], $PW);
$cl = [];
foreach (['sa' => 'sa', 'pm' => 'pm', 'hm' => 'hm', 'ho' => 'ho', 'rec' => 'rec'] as $k => $e) {
    $cl[$k] = newClient();
    $r = login($cl[$k], "phase11j.$e@example.test", $PW);
    check("Set-up: $k signs in", $r['status'] === 303 && loc($r) !== '/login');
}
$tok = fn() => bin2hex(random_bytes(16));
$txt = fn(array $c, array $r) => strip_tags(follow($c, $r)['body']);

// ---------------------------------------------------------------- 1-2: registration and approval
$pk = newClient();
$page = req($pk, 'GET', '/register');
$r = req($pk, 'POST', '/register', ['_csrf' => csrfFrom($page['body']), 'full_name' => 'Journey Picker', 'phone' => '0700080101', 'neighbourhood_id' => (string) $nbId, 'preferred_hub_id' => (string) $hub,
    'national_id' => 'NIN-JOURNEY-1', 'password' => 'Journey!Pass2026', 'password_confirmation' => 'Journey!Pass2026', 'consent' => '1']);
$row = $pdo->query("SELECT u.id AS uid, u.status, p.id AS pid FROM users u JOIN pickers p ON p.user_id = u.id WHERE u.phone_e164 = '+256700080101'")->fetch();
check('1. A picker registers on the public form: the account is created as PENDING', $r['status'] === 303 && $row && $row['status'] === 'pending');
$pid = (int) $row['pid'];
$signIn = login($pk, '0700080101', 'Journey!Pass2026');
check('   ...and cannot sign in until approved', $signIn['status'] === 303 && loc($signIn) === '/login');
$r = postVia($cl['hm'], "/pickers/$pid", "/pickers/$pid/decision", ['decision' => 'approve']);
check('2. The hub manager approves the registration', $r['status'] === 303 && $pdo->query("SELECT status FROM users WHERE id = {$row['uid']}")->fetchColumn() === 'active');
$ids['pk'] = (int) $row['uid'];
$signIn = login($pk, '0700080101', 'Journey!Pass2026');
check('   ...the picker can now sign in and lands on their own dashboard', $signIn['status'] === 303 && str_contains(loc($signIn), 'picker'));
check('   ...and a "registration approved" SMS was queued for them', (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE event = 'registration_approved' AND user_id = {$ids['pk']}")->fetchColumn() === 1);

// ---------------------------------------------------------------- 3-4: collection
$t = $tok();
$r = postVia($cl['ho'], '/collections/new', '/collections', ['token' => $t, 'picker_id' => (string) $pid, 'hub_id' => (string) $hub, 'plastic_category_id' => (string) $catId, 'gross_weight_kg' => '25.5', 'tare_weight_kg' => '1.5', 'notes' => 'Clean PET']);
$col = (int) $pdo->query("SELECT id FROM collection_transactions WHERE submission_token = '$t'")->fetchColumn();
$c = $pdo->query("SELECT * FROM collection_transactions WHERE id = $col")->fetch();
check('3. The hub operator weighs and records the collection: net 24 kg x UGX 1,000 = UGX 24,000, PENDING', $col > 0 && $c['status'] === 'pending' && (float) $c['net_weight_kg'] === 24.0 && (int) $c['total_value_ugx'] === 24000);
check('   ...nothing is earned and no stock exists yet', LedgerService::balance('picker', $pid) === 0 && (float) $pdo->query("SELECT COALESCE(SUM(on_hand_kg), 0) FROM inventory WHERE hub_id = $hub")->fetchColumn() === 0.0);
$self = postVia($cl['ho'], "/collections/$col", "/collections/$col/approve", []);
check('   ...the person who recorded it cannot approve it (four eyes)', $self['status'] === 403 || str_contains($txt($cl['ho'], $self), 'someone else') || $pdo->query("SELECT status FROM collection_transactions WHERE id = $col")->fetchColumn() === 'pending');
$r = postVia($cl['hm'], "/collections/$col", "/collections/$col/approve", []);
check('4. Another person approves it: status approved, receipt issued', $r['status'] === 303 && $pdo->query("SELECT status FROM collection_transactions WHERE id = $col")->fetchColumn() === 'approved' && (int) $pdo->query("SELECT COUNT(*) FROM receipts WHERE collection_id = $col")->fetchColumn() === 1);
check('   ...the picker earned UGX 24,000 and the hub holds 24 kg of stock', LedgerService::balance('picker', $pid) === 24000 && (float) $pdo->query("SELECT COALESCE(SUM(on_hand_kg), 0) FROM inventory WHERE hub_id = $hub")->fetchColumn() === 24.0);
$e1 = req($pk, 'GET', '/earnings'); $e2 = req($pk, 'GET', "/receipts/" . (int) $pdo->query("SELECT id FROM receipts WHERE collection_id = $col")->fetchColumn()); $e3 = req($pk, 'GET', "/collections/$col");
check('   ...the picker sees it on their own pages, with the receipt', str_contains($e1['body'], '24,000') && $e2['status'] === 200 && $e3['status'] === 200, "earnings {$e1['status']} has24k=" . (int) str_contains($e1['body'], '24,000') . " receipt {$e2['status']} collection {$e3['status']}");
check('   ...and was sent a "collection approved" SMS', (bool) $pdo->query("SELECT 1 FROM sms_outbox WHERE event = 'collection_approved' AND user_id = {$ids['pk']} AND body LIKE '%24 kg%'")->fetchColumn());

// ---------------------------------------------------------------- 5: payout
$t = $tok();
postVia($pk, '/earnings', '/payouts', ['token' => $t, 'amount_ugx' => '15000', 'method' => 'cash']);
$rq = (int) $pdo->query("SELECT id FROM payment_requests WHERE submission_token = '$t'")->fetchColumn();
check('5. The picker asks for UGX 15,000 in cash: request pending approval', $rq > 0 && $pdo->query("SELECT status FROM payment_requests WHERE id = $rq")->fetchColumn() === 'pending_approval');
postVia($pk, '/earnings', '/payouts', ['token' => $tok(), 'amount_ugx' => '20000', 'method' => 'cash']);
check('   ...the picker cannot ask for more than is available after that (UGX 9,000 left)', (int) $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE picker_id = $pid")->fetchColumn() === 1);
$r = postVia($cl['pm'], "/payouts/$rq", "/payouts/$rq/decision", ['decision' => 'approve']);
$payId = (int) $pdo->query("SELECT id FROM payments WHERE request_id = $rq")->fetchColumn();
check('   ...the project manager approves it: a payment is created, still nothing paid', $payId > 0 && Payment::find($payId)['status'] === 'pending' && LedgerService::balance('picker', $pid) === 24000);
$r = postVia($cl['hm'], "/payments/$payId", "/payments/$payId/process", ['receipt_note' => 'Paid in cash at the hub, journey test']);
check('   ...the hub pays it: successful, the picker balance falls to UGX 9,000, request closed as paid', Payment::find($payId)['status'] === 'successful' && LedgerService::balance('picker', $pid) === 9000
    && $pdo->query("SELECT status FROM payment_requests WHERE id = $rq")->fetchColumn() === 'paid');
check('   ...and a "payout paid" SMS was queued', (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE event = 'payout_paid' AND user_id = {$ids['pk']}")->fetchColumn() === 1);
$again = postVia($cl['hm'], "/payments/$payId", "/payments/$payId/process", ['receipt_note' => 'again']);
check('   ...paying the same payment again does nothing (still one ledger entry)', (int) $pdo->query("SELECT COUNT(*) FROM financial_ledger WHERE source_type = 'payment' AND source_id = $payId")->fetchColumn() === 1 && LedgerService::balance('picker', $pid) === 9000);

// ---------------------------------------------------------------- 6-7: the recycler buys
$rec = User::findById($ids['rec']);
$hm = User::findById($ids['hm']);
$c = OrderService::create(['token' => $tok(), 'hub_id' => $hub, 'plastic_category_id' => $catId, 'quantity_kg' => '10', 'price_per_kg_ugx' => '1300'], $rec);
$order = (int) $c['id'];
OrderService::accept($order, $hm);
$d = OrderService::dispatch($order, ['token' => $tok(), 'quantity_kg' => '10'], $hm);
check('6. The recycler orders 10 kg at UGX 1,300; the hub accepts and loads it: stock leaves the hub (24 -> 14 kg)', (float) $pdo->query("SELECT COALESCE(SUM(on_hand_kg), 0) FROM inventory WHERE hub_id = $hub")->fetchColumn() === 14.0);
foreach ($pdo->query("SELECT id FROM stock_dispatches WHERE purchase_order_id = $order AND status = 'dispatched'")->fetchAll(PDO::FETCH_COLUMN) as $dsp) {
    OrderService::receive($order, (int) $dsp, ['received_kg' => '10', 'note' => 'All good'], $rec);
}
$inv = (int) $pdo->query("SELECT id FROM invoices WHERE order_id = $order")->fetchColumn();
check('   ...the recycler confirms delivery: an invoice for 10 kg x 1,300 = UGX 13,000 is issued automatically', $inv > 0 && (int) $pdo->query("SELECT amount_ugx FROM invoices WHERE id = $inv")->fetchColumn() === 13000
    && LedgerService::balance('recycler', $recId) === 13000);
check('   ...the recycler can open their invoice; the hub manager can too', req($cl['rec'], 'GET', "/invoices/$inv")['status'] === 200 && req($cl['hm'], 'GET', "/invoices/$inv")['status'] === 200);
$t = $tok();
postVia($cl['pm'], "/invoices/$inv", "/invoices/$inv/payment", ['token' => $t, 'amount_ugx' => '13000', 'method' => 'mobile_money', 'provider_reference' => 'MTNJ' . bin2hex(random_bytes(3)), 'received_on' => date('Y-m-d')]);
$rp = (int) $pdo->query("SELECT id FROM payments WHERE invoice_id = $inv AND direction = 'in'")->fetchColumn();
check('7. A staff member records the recycler\'s UGX 13,000 payment: it is PENDING, the balance owed has not changed', $rp > 0 && Payment::find($rp)['status'] === 'pending' && LedgerService::balance('recycler', $recId) === 13000);
$self = postVia($cl['pm'], "/payments/$rp", "/payments/$rp/receipt-decision", ['decision' => 'confirm']);
check('   ...the person who recorded it cannot confirm it', Payment::find($rp)['status'] === 'pending');
postVia($cl['sa'], "/payments/$rp", "/payments/$rp/receipt-decision", ['decision' => 'confirm']);
check('   ...a different person confirms it: invoice PAID, recycler owes UGX 0', Payment::find($rp)['status'] === 'successful' && $pdo->query("SELECT status FROM invoices WHERE id = $inv")->fetchColumn() === 'paid' && LedgerService::balance('recycler', $recId) === 0);

// ---------------------------------------------------------------- 8: reversal
postVia($cl['pm'], "/collections/$col", "/collections/$col/reverse", ['reason' => 'Journey test: try to reverse plastic that has already been sold']);
check('8. A collection whose plastic has already left the hub (sold) cannot be reversed: nothing changes', $pdo->query("SELECT status FROM collection_transactions WHERE id = $col")->fetchColumn() === 'approved'
    && LedgerService::balance('picker', $pid) === 9000 && (float) $pdo->query("SELECT COALESCE(SUM(on_hand_kg), 0) FROM inventory WHERE hub_id = $hub")->fetchColumn() === 14.0);
$t = $tok();
postVia($cl['ho'], '/collections/new', '/collections', ['token' => $t, 'picker_id' => (string) $pid, 'hub_id' => (string) $hub, 'plastic_category_id' => (string) $catId, 'gross_weight_kg' => '2.5', 'tare_weight_kg' => '0.5']);
$col2 = (int) $pdo->query("SELECT id FROM collection_transactions WHERE submission_token = '$t'")->fetchColumn();
postVia($cl['hm'], "/collections/$col2", "/collections/$col2/approve", []);
check('   ...a small second delivery (2 kg) is recorded and approved: the picker earns UGX 2,000 more, stock is 16 kg', $col2 > 0 && LedgerService::balance('picker', $pid) === 11000
    && (float) $pdo->query("SELECT COALESCE(SUM(on_hand_kg), 0) FROM inventory WHERE hub_id = $hub")->fetchColumn() === 16.0);
postVia($cl['pm'], "/collections/$col2", "/collections/$col2/reverse", ['reason' => 'Journey test: wrong material recorded']);
check('   ...reversing that one (with a reason) takes the UGX 2,000 and the 2 kg back; the history stays', $pdo->query("SELECT status FROM collection_transactions WHERE id = $col2")->fetchColumn() === 'reversed'
    && LedgerService::balance('picker', $pid) === 9000 && (float) $pdo->query("SELECT COALESCE(SUM(on_hand_kg), 0) FROM inventory WHERE hub_id = $hub")->fetchColumn() === 14.0
    && (int) $pdo->query("SELECT COUNT(*) FROM financial_ledger WHERE source_type = 'collection' AND source_id = $col2")->fetchColumn() === 2);

// ---------------------------------------------------------------- 9: reports, map and programme figures
$f = ReportService::filters(['hub_id' => (string) $hub, 'from' => date('Y-m-d', strtotime('-2 days')), 'to' => date('Y-m-d', strtotime('+1 day'))], CollectionsReport::class, $sa);
$run = ReportService::run(CollectionsReport::class, ['hub_id' => (string) $hub, 'from' => $f['from'], 'to' => $f['to']], $sa)['result'];
check('9. The collections report agrees: 1 approved (24 kg), 1 reversed', (int) $run['summary'][0]['value'] === 1 && abs((float) $run['summary'][1]['value'] - 24.0) < 0.01 && (int) $run['summary'][4]['value'] === 1);
$geo = MonitoringService::mapData(ReportService::filters(['hub_id' => (string) $hub], App\Services\Reports\GeographicReport::class, $sa));
check('   ...the map shows the hub with its location and its stock (14 kg)', $geo['hubs'][0]['name'] === 'P11JTEST Hub' && $geo['hubs'][0]['latitude'] === 2.77 && abs((float) $geo['hubs'][0]['on_hand'] - 14.0) < 0.01);
$pages = ['/admin', '/collections', '/payments', '/invoices', '/payouts', '/finance', '/inventory', '/orders', '/reports', '/reports/financial', '/reports/payments', '/reports/recyclers', '/reports/inventory', '/monitoring/map', '/monitoring/programme', '/admin/audit'];
$bad = [];
foreach ($pages as $p) {
    $x = req($cl['sa'], 'GET', $p);
    if ($x['status'] !== 200) {
        $bad[] = "$p {$x['status']}";
    }
}
check('   ...every main administration page renders for the Super Administrator (' . count($pages) . ' pages)', !$bad, implode(', ', $bad));
$exp = req($cl['pm'], 'GET', '/reports/financial/export/csv?from=' . date('Y-m-d', strtotime('-2 days')));
check('   ...a financial CSV export downloads and is audited', $exp['status'] === 200 && str_contains($exp['body'], 'inancial') && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'report.exported' AND user_id = {$ids['pm']}")->fetchColumn() === 1);
$audit = $pdo->query("SELECT GROUP_CONCAT(DISTINCT action) FROM audit_logs WHERE user_id IN (" . implode(',', array_filter([$ids['sa'], $ids['pm'], $ids['hm'], $ids['ho'], $ids['pk'] ?? 0, $ids['rec']])) . ')')->fetchColumn();
foreach (['collection.approved', 'payout.requested', 'payment.successful', 'collection.reversed'] as $a) {
    check("   ...the audit trail recorded $a", str_contains((string) $audit, $a));
}

// ---------------------------------------------------------------- 10: the whole-system checks
check('10. The money ledger check passes for the whole system', FinanceService::verify() === [], json_encode(FinanceService::verify()));
check('    ...the stock ledger check passes for the whole system', StockBalance::mismatches() === [], json_encode(StockBalance::mismatches()));
$tmpDir = sys_get_temp_dir() . '/plbk_' . bin2hex(random_bytes(4));
Config::set('app.backup.directory', $tmpDir);
Config::set('app.backup.key', str_repeat('journey-backup-key-', 3));
$b = null;
try {
    $b = BackupService::run();
} catch (Throwable $e) {
    check('    ...a backup is made', false, $e->getMessage());
}
if ($b) {
    $v = BackupService::verify($b['set']);
    $live = (int) $pdo->query('SELECT COUNT(*) FROM financial_ledger')->fetchColumn();
    check('    ...a backup is made, encrypted, and covers every table (' . count($b['manifest']['tables']) . ' tables)', $b['manifest']['encrypted'] === true && count($b['manifest']['tables']) >= 30 && (int) $b['manifest']['tables']['financial_ledger'] === $live);
    check('    ...and the backup PROVES it can be restored (' . $v['mode'] . '): ' . ($v['ok'] ? 'all counts and totals match' : implode('; ', $v['problems'])), $v['ok']);
    $plain = (string) file_get_contents($tmpDir . '/' . $b['manifest']['sql_file']);
    check('    ...the encrypted file does not contain readable data (no table names or names of people)', !str_contains($plain, 'financial_ledger') && !str_contains($plain, 'Journey Picker') && str_starts_with($plain, 'PLBK1'));
    $wrong = false;
    Config::set('app.backup.key', str_repeat('some-other-key--', 3));
    try { BackupService::sqlFile($b['set']); } catch (Throwable) { $wrong = true; }
    check('    ...a wrong key cannot open it', $wrong);
    foreach (glob($tmpDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
}
Config::set('app.backup.key', '');
Config::set('app.backup.directory', '');

$cleanup();
$left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'phase11j.%' OR phone_e164 LIKE '+25670008%'")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM collection_hubs WHERE name LIKE 'P11JTEST%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM neighbourhoods WHERE name LIKE 'P11JTEST%'")->fetchColumn();
check('Test data cleaned up', $left === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
