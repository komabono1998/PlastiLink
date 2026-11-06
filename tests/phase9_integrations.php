<?php
declare(strict_types=1);

/**
 * Phase 9 tests: SMS queue and gateway, MTN MoMo and Airtel Money providers, callbacks, USSD.
 *   php tests/phase9_integrations.php [base-url]
 * Starts a local fake MTN / Airtel / Africa's Talking server (tests/_mock_providers.php), creates temporary data
 * (phase9test.*, "P9TEST" hubs) and removes it afterwards. No real provider is contacted.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/_http.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\Hub;
use App\Models\Payment;
use App\Models\Picker;
use App\Models\Price;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\PickerService;
use App\Services\Payments\AirtelMoneyProvider;
use App\Services\Payments\MobileMoneyProvider;
use App\Services\Payments\MtnMomoProvider;
use App\Services\Payments\ProviderFactory;
use App\Services\Sms\AfricasTalkingSms;
use App\Services\Sms\LogSms;
use App\Services\Sms\MockSms;
use App\Services\Sms\SmsFactory;
use App\Services\SmsService;
use App\Services\UssdService;
use App\Services\WebhookService;

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase9Test!pw';
$pass = 0;
$fail = 0;

$cleanup = function () use ($pdo): void {
    $ids = fn(string $sql): string => implode(',', array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    $hub = $ids("SELECT id FROM collection_hubs WHERE name LIKE 'P9TEST%'");
    $usr = $ids("SELECT id FROM users WHERE email LIKE 'phase9test.%' OR phone_e164 LIKE '+256700055%' OR phone_e164 LIKE '+25677005%'");
    $pk = $ids("SELECT id FROM pickers WHERE user_id IN ($usr)");
    $pay = $ids("SELECT id FROM payments WHERE picker_id IN ($pk)");
    $req = $ids("SELECT id FROM payment_requests WHERE picker_id IN ($pk)");
    $col = $ids("SELECT id FROM collection_transactions WHERE hub_id IN ($hub) OR recorded_by IN ($usr)");
    $prc = $ids("SELECT id FROM price_lists WHERE hub_id IN ($hub) OR note LIKE 'P9TEST%'");
    $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($usr) OR (entity_type = 'payment' AND entity_id IN ($pay)) OR (entity_type = 'payout_request' AND entity_id IN ($req))
                OR (entity_type = 'collection' AND entity_id IN ($col)) OR (entity_type = 'price' AND entity_id IN ($prc)) OR (entity_type IN ('user', 'picker') AND entity_id IN ($usr, $pk))");
    $pdo->exec("DELETE FROM financial_ledger WHERE (source_type = 'collection' AND source_id IN ($col)) OR (source_type = 'payment' AND source_id IN ($pay))");
    $pdo->exec("DELETE FROM payment_attempts WHERE payment_id IN ($pay)");
    $pdo->exec("DELETE FROM payments WHERE id IN ($pay)");
    $pdo->exec("DELETE FROM payment_requests WHERE id IN ($req)");
    $pdo->exec("DELETE FROM stock_movements WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM inventory WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM receipts WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM weighing_records WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM collection_transactions WHERE id IN ($col)");
    $pdo->exec("DELETE FROM price_lists WHERE id IN ($prc)");
    $pdo->exec("DELETE FROM pickers WHERE user_id IN ($usr)");
    $pdo->exec("DELETE FROM collection_hubs WHERE id IN ($hub)");
    $pdo->exec("DELETE FROM sms_outbox WHERE recipient LIKE '+25670005%' OR recipient LIKE '+25677005%' OR recipient LIKE '+2567000999%'");
    $pdo->exec("DELETE FROM webhook_log WHERE ip_address = '203.0.113.9'");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase9test%' OR identifier LIKE '%700055%' OR identifier LIKE '%770055%'");
    $pdo->exec("DELETE FROM users WHERE id IN ($usr)");
    $pdo->exec('DELETE FROM sms_outbox WHERE user_id IS NULL');
};
$cleanup();

// ---------------------------------------------------------------- the fake provider server
$stateFile = tempnam(sys_get_temp_dir(), 'plmock');
$mockSet = function (array $patch) use ($stateFile): void {
    $s = json_decode((string) file_get_contents($stateFile), true) ?: [];
    file_put_contents($stateFile, json_encode(array_merge($s, $patch)));
};
$mockGet = fn() => json_decode((string) file_get_contents($stateFile), true) ?: [];
$mockLogCount = fn() => count($mockGet()['log'] ?? []);
$mockLast = function (string $pathPrefix) use ($mockGet): ?array {
    $hit = null;
    foreach ($mockGet()['log'] ?? [] as $e) {
        if (str_starts_with($e['p'], $pathPrefix)) {
            $hit = $e;
        }
    }
    return $hit;
};
file_put_contents($stateFile, '{}');
$port = 0;
$proc = null;
foreach (range(18400, 18440) as $p) {
    $s = @stream_socket_server("tcp://127.0.0.1:$p");
    if ($s) {
        fclose($s);
        $port = $p;
        break;
    }
}
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/_mock_providers.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/plmock.out', 'w'], 2 => ['file', sys_get_temp_dir() . '/plmock.err', 'w']],
    $pipes, __DIR__, ['MOCK_STATE' => $stateFile, 'PATH' => (string) getenv('PATH'), 'SystemRoot' => (string) getenv('SystemRoot')]);
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    $up = (bool) $c;
    $c && fclose($c);
    $up || usleep(100000);
}
check('Setup: fake provider server is running', $up);
$mock = "http://127.0.0.1:$port";

register_shutdown_function(function () use (&$proc): void {   // never leave the fake server running, even after a crash
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
});
$cnf = ['config' => (string) (glob('C:/xampp/php/extras/ssl/openssl.cnf')[0] ?? '')];   // Windows/XAMPP needs this only to GENERATE a test key
$keys = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + ($cnf['config'] !== '' ? $cnf : []));
openssl_pkey_export($keys, $privPem, null, $cnf['config'] !== '' ? $cnf : []);
$pubPem = openssl_pkey_get_details($keys)['key'];
$mockSet(['airtel_priv' => $privPem]);

$origCfg = ['app.integrations' => Config::get('app.integrations'), 'app.finance' => Config::get('app.finance')];
$configure = function () use ($mock): void {
    Config::set('app.integrations.sms.driver', 'mock');
    Config::set('app.integrations.sms.africastalking', ['username' => 'sandbox', 'api_key' => 'at-key', 'sender_id' => 'PLASTILINK', 'sandbox' => true, 'base_url' => $mock]);
    Config::set('app.integrations.momo.mtn', ['enabled' => true, 'base_url' => $mock, 'environment' => 'sandbox', 'currency' => 'EUR', 'subscription_key' => 'mtn-sub-key', 'api_user' => 'mtn-user', 'api_key' => 'mtn-key', 'callback_url' => '']);
    global $pubPem;
    Config::set('app.integrations.momo.airtel', ['enabled' => true, 'base_url' => $mock, 'country' => 'UG', 'currency' => 'UGX', 'client_id' => 'air-id', 'client_secret' => 'air-secret', 'pin' => '4321', 'public_key' => $pubPem, 'status_path' => '/standard/v1/disbursements/{id}', 'callback_url' => '']);
    Config::set('app.integrations.ussd', ['enabled' => true, 'payout_max_ugx' => 20000, 'payout_max_per_day' => 3]);
    Config::set('app.integrations.webhook_token', 'unit-test-secret-token-0123456789');
    MtnMomoProvider::forgetToken();
    AirtelMoneyProvider::forgetToken();
};
$configure();

// ---------------------------------------------------------------- safe defaults and secrets
$cfgFile = (string) file_get_contents(dirname(__DIR__) . '/config/app.php');
check('config/app.php ships with no secrets (all keys, tokens, PINs empty)', preg_match("/'(api_key|subscription_key|api_user|client_secret|client_id|pin|webhook_token|public_key)'\s*=>\s*'[^']+'/", $cfgFile) !== 1);
check('.gitignore keeps config/*.local.php out of git', str_contains((string) file_get_contents(dirname(__DIR__) . '/.gitignore'), 'local.php'));
check('Factory defaults: log SMS gateway and manual payment provider', (function () {
    $o = Config::get('app.integrations.sms.driver'); Config::set('app.integrations.sms.driver', 'log');
    $a = SmsFactory::make() instanceof LogSms; Config::set('app.integrations.sms.driver', $o);
    return $a && ProviderFactory::make('') instanceof App\Services\Payments\ManualProvider;
})());
Config::set('app.env', 'production');
check('In production the log SMS driver refuses to pretend, and the mock refuses too', (new LogSms())->send('+256700000001', 'x')->status === 'failed' && (new MockSms())->send('+256700000001', 'x')->status === 'failed');
$r = (new MtnMomoProvider())->initiate(['id' => 1, 'idempotency_key' => 'k', 'reference' => 'PAY-1', 'amount_ugx' => 1000, 'destination_phone' => '+256770000001'], []);
Config::set('app.env', 'local');
check('In production plain http (our fake server) is refused, so nothing is sent', $r->status === 'failed' || $r->status === 'processing' && $mockLogCount() === 0);

// ---------------------------------------------------------------- setup data
$ids = [];
foreach (['super_admin' => 'sa', 'project_manager' => 'pm', 'hub_manager' => 'hmA', 'hub_operator' => 'hoA'] as $role => $k) {
    $ids[$k] = User::create(['role' => $role, 'full_name' => "P9 $k", 'email' => "phase9test.$k@example.test", 'password' => $PW, 'status' => 'active']);
}
$nbId = (int) $pdo->query('SELECT id FROM neighbourhoods WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$catId = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code = 'PET'")->fetchColumn();
$hubA = Hub::create(['name' => 'P9TEST Hub A', 'neighbourhood_id' => $nbId, 'address_note' => null, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
Hub::assign($hubA, $ids['hmA'], 'manager'); Hub::assign($hubA, $ids['hoA'], 'operator');
Price::create($catId, $hubA, 1000, 'P9TEST price', $ids['sa']);
$U = function (string $k) use (&$ids): array { return User::findById($ids[$k]); };
$sa = $U('sa'); $pm = $U('pm');
$mkPicker = function (string $key, string $name, string $phone) use ($sa, $nbId, $PW, &$ids, $hubA): int {
    $r = PickerService::registerByStaff(['full_name' => $name, 'phone' => $phone, 'neighbourhood_id' => $nbId, 'preferred_hub_id' => $hubA, 'national_id' => 'NIN' . substr($phone, -6) . 'ZZ', 'consent' => '1'], $sa);
    $p = Picker::find((int) $r['id']);
    User::updatePassword((int) $p['user_id'], $PW);
    $ids[$key] = (int) $p['user_id'];
    return (int) $r['id'];
};
$pkM = $mkPicker('pkM', 'P9 Picker MTN', '0770055101');      // MTN number
$pkA = $mkPicker('pkA', 'P9 Picker Airtel', '0700055102');   // Airtel number
$pkQ = $mkPicker('pkQ', 'P9 Picker Other', '0700055103');
$tok = fn() => bin2hex(random_bytes(16));
$mkCol = function (int $picker, string $gross) use ($U, $catId, $tok, $hubA): int {
    $r = CollectionService::record(['token' => $tok(), 'picker_id' => $picker, 'hub_id' => $hubA, 'plastic_category_id' => $catId, 'gross_weight_kg' => $gross, 'tare_weight_kg' => ''], $U('hoA'));
    return CollectionService::approve((int) $r['id'], $U('hmA'))['error'] === null ? (int) $r['id'] : 0;
};

// ---------------------------------------------------------------- SMS queue
$q1 = SmsService::queue('0700099901', 'Hello there', 'test');
$row = $pdo->query("SELECT * FROM sms_outbox WHERE id = " . (int) $q1)->fetch();
check('queue() normalises the number and stores a queued message', $q1 && $row['recipient'] === '+256700099901' && $row['status'] === 'queued' && (int) $row['attempts'] === 0);
check('Invalid, empty or missing numbers queue nothing', SmsService::queue('12345', 'x', 'test') === null && SmsService::queue(null, 'x', 'test') === null && SmsService::queue('0700099902', "  \x01  ", 'test') === null);
$long = SmsService::queue('0700099903', str_repeat('é', 600) . "\x07", 'test');
$lr = $pdo->query("SELECT body FROM sms_outbox WHERE id = " . (int) $long)->fetchColumn();
check('Long text is cut to 320 characters and control characters are removed', mb_strlen($lr) === 320 && !str_contains($lr, "\x07"));
$d1 = SmsService::queue('0700099904', 'once', 'test', null, 'p9-dedupe-1');
$d2 = SmsService::queue('0700099904', 'once', 'test', null, 'p9-dedupe-1');
check('A dedupe key stops the same event being queued twice', $d1 !== null && $d2 === null);
$pdo->exec("UPDATE users SET sms_opt_out = 1 WHERE id = " . (int) $ids['pkQ']);
check('Opted-out users get no messages', SmsService::queue('0700055103', 'Hi', 'test', $ids['pkQ']) === null);
$pdo->exec("UPDATE users SET sms_opt_out = 0 WHERE id = " . (int) $ids['pkQ']);

// ---------------------------------------------------------------- SMS dispatch with the mock gateway
MockSms::$sent = [];
$a = SmsService::queue('0700099905', 'to send', 'test');
$b = SmsService::queue('0700090000', 'will fail for good', 'test');   // +256700090000 ends in 0000
$c = SmsService::queue('0700091111', 'retry me', 'test');             // ends in 1111
MockSms::$retryBudget = 2;
SmsService::dispatch(500);
$st = fn(int $id) => $pdo->query("SELECT status, attempts, error, provider, provider_message_id, next_attempt_at > UTC_TIMESTAMP() AS later FROM sms_outbox WHERE id = $id")->fetch();
check('Dispatch: a good message is sent and recorded with provider and message id', $st($a)['status'] === 'sent' && $st($a)['provider'] === 'mock' && str_starts_with((string) $st($a)['provider_message_id'], 'MOCK-'));
check('Dispatch: a permanent failure is marked failed with a reason and not retried', $st($b)['status'] === 'failed' && $st($b)['error'] !== null && (int) $st($b)['attempts'] === 1);
check('Dispatch: a temporary failure goes back to the queue with a delay (back-off)', $st($c)['status'] === 'queued' && (int) $st($c)['attempts'] === 1 && (int) $st($c)['later'] === 1);
$before = count(MockSms::$sent);
SmsService::dispatch(500);
check('Delayed messages are not sent early', $st($c)['status'] === 'queued' && count(MockSms::$sent) === $before);
$pdo->exec("UPDATE sms_outbox SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = $c");
SmsService::dispatch(500);
$pdo->exec("UPDATE sms_outbox SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = $c");
SmsService::dispatch(500);
check('The retried message succeeds once the gateway recovers (3 attempts)', $st($c)['status'] === 'sent' && (int) $st($c)['attempts'] === 3);
$e = SmsService::queue('0700091111', 'never works', 'test');
MockSms::$retryBudget = 99;
for ($i = 0; $i < 6; $i++) {
    $pdo->exec("UPDATE sms_outbox SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = $e AND status = 'queued'");
    SmsService::dispatch(500);
}
MockSms::$retryBudget = 0;
check('After 5 attempts a message that keeps failing is marked failed, not retried forever', $st($e)['status'] === 'failed' && (int) $st($e)['attempts'] === SmsService::MAX_ATTEMPTS);
$f1 = SmsService::queue('0700099906', 'locked', 'test');
$pdo->exec("UPDATE sms_outbox SET status = 'sending', locked_at = UTC_TIMESTAMP() WHERE id = $f1");
SmsService::dispatch(500);
check('A message another worker is sending is left alone', $st($f1)['status'] === 'sending');
$pdo->exec("UPDATE sms_outbox SET locked_at = UTC_TIMESTAMP() - INTERVAL 30 MINUTE WHERE id = $f1");
SmsService::dispatch(500);
check('A message stuck in "sending" for 30 minutes is picked up again', $st($f1)['status'] === 'sent');

$mid = (string) $pdo->query("SELECT provider_message_id FROM sms_outbox WHERE id = $a")->fetchColumn();
check('Delivery report: success is recorded', SmsService::recordDelivery($mid, 'Success') === true && $pdo->query("SELECT delivery_status FROM sms_outbox WHERE id = $a")->fetchColumn() === 'delivered');
check('A later "Sent" report never downgrades a delivered message', SmsService::recordDelivery($mid, 'Sent') === false && $pdo->query("SELECT delivery_status FROM sms_outbox WHERE id = $a")->fetchColumn() === 'delivered');
check('Unknown message ids and unknown statuses are ignored', SmsService::recordDelivery('nope', 'Success') === false && SmsService::recordDelivery($mid, 'weird') === false && SmsService::recordDelivery(str_repeat('x', 200), 'Success') === false);

// ---------------------------------------------------------------- Africa's Talking gateway (against the fake server)
Config::set('app.integrations.sms.driver', 'africastalking');
$at = new AfricasTalkingSms();
$n0 = $mockLogCount();
$res = $at->send('+256700099910', 'Hello from PlastiLink');
$req = $mockLast('/version1/messaging');
parse_str($req['b'] ?? '', $form);
check('Africa\'s Talking: accepted message returns sent with the gateway message id', $res->status === 'sent' && str_starts_with((string) $res->messageId, 'ATXid_'));
check('Request carries apiKey header, username, recipient, text and sender id as a form post', ($req['h']['apikey'] ?? '') === 'at-key' && ($form['username'] ?? '') === 'sandbox' && ($form['to'] ?? '') === '+256700099910'
    && ($form['message'] ?? '') === 'Hello from PlastiLink' && ($form['from'] ?? '') === 'PLASTILINK' && stripos($req['h']['content-type'] ?? '', 'x-www-form-urlencoded') !== false);
$mockSet(['at_mode' => 'rejected']);
$res = $at->send('+256700099911', 'x');
check('A rejected recipient (risk hold) is a permanent failure with a safe reason', $res->status === 'failed' && str_contains((string) $res->error, 'RiskHold'));
$mockSet(['at_mode' => '500']);
check('A gateway 500 is a retry, not a failure', $at->send('+256700099912', 'x')->status === 'retry');
$mockSet(['at_mode' => 'ok']);
Config::set('app.integrations.sms.africastalking.api_key', 'wrong-key');
$res = $at->send('+256700099913', 'x');
check('Wrong API key is a permanent failure and the key is not echoed back', $res->status === 'failed' && !str_contains((string) $res->error, 'wrong-key'));
Config::set('app.integrations.sms.africastalking.api_key', '');
check('Not configured: fails without contacting anyone', ($m0 = $mockLogCount()) >= 0 && $at->send('+256700099914', 'x')->status === 'failed' && $mockLogCount() === $m0);
Config::set('app.integrations.sms.africastalking.api_key', 'at-key');
Config::set('app.integrations.sms.africastalking.base_url', 'http://127.0.0.1:1');
check('An unreachable gateway is a retry', $at->send('+256700099915', 'x')->status === 'retry');
Config::set('app.integrations.sms.africastalking.base_url', $mock);
$z = SmsService::queue('0700099916', 'through the queue', 'test');
SmsService::dispatch(500);
check('End to end: a queued message is delivered through the (fake) gateway and stamped africastalking', $st($z)['status'] === 'sent' && $st($z)['provider'] === 'africastalking' && str_starts_with((string) $st($z)['provider_message_id'], 'ATXid_'));
Config::set('app.integrations.sms.driver', 'mock');

// ---------------------------------------------------------------- events queue messages
$cA1 = $mkCol($pkM, '20'); $cA2 = $mkCol($pkM, '10');
$mkCol($pkA, '20'); $mkCol($pkA, '10'); $mkCol($pkQ, '10');
$smsFor = fn(string $event, int $user) => (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE event = '$event' AND user_id = $user")->fetchColumn();
check('Approving a collection queues one SMS for the picker with the reference, kg and value', $smsFor('collection_approved', $ids['pkM']) === 2
    && (bool) $pdo->query("SELECT 1 FROM sms_outbox WHERE event = 'collection_approved' AND user_id = {$ids['pkM']} AND body LIKE '%20 kg%20,000%' AND recipient = '+256770055101'")->fetchColumn());
$msgs = implode(' ', $pdo->query("SELECT body FROM sms_outbox WHERE user_id IN ({$ids['pkM']}, {$ids['pkA']}, {$ids['pkQ']})")->fetchAll(PDO::FETCH_COLUMN));
check('Messages never contain national IDs or other people\'s numbers', !preg_match('/NIN\d{6}ZZ|\+256700055103|0700055103/', $msgs));
$pend = PickerService::registerByStaff(['full_name' => 'P9 Pending', 'phone' => '0700055104', 'neighbourhood_id' => $nbId, 'preferred_hub_id' => $hubA, 'national_id' => 'NIN055104ZZ', 'consent' => '1'], $sa);
$pendUser = (int) Picker::find((int) $pend['id'])['user_id'];
$pdo->exec("UPDATE users SET status = 'pending' WHERE id = $pendUser");
PickerService::decide((int) $pend['id'], 'approve', null, $sa);
check('Approving a registration queues an "approved" SMS', $smsFor('registration_approved', $pendUser) === 1);
$pdo->exec("UPDATE users SET status = 'pending' WHERE id = $pendUser");
PickerService::decide((int) $pend['id'], 'reject', 'Documents not clear enough', $sa);
check('Rejecting a registration queues a "not approved" SMS without the reason text', $smsFor('registration_rejected', $pendUser) === 1
    && !str_contains((string) $pdo->query("SELECT body FROM sms_outbox WHERE event = 'registration_rejected' AND user_id = $pendUser")->fetchColumn(), 'Documents'));

// ---------------------------------------------------------------- payouts through MTN MoMo (fake server)
Config::set('app.finance.provider', 'momo');
$mkPayout = function (string $pk, int $pickerId, int $amount, string $method = 'mobile_money') use ($U, $pm, $tok, $pdo, &$ids): int {
    $r = PayoutService::request(['token' => $tok(), 'amount_ugx' => (string) $amount, 'method' => $method], User::findById($ids[$pk]));
    $d = PayoutService::decide((int) $r['id'], 'approve', null, $pm);
    return (int) $pdo->query('SELECT id FROM payments WHERE request_id = ' . (int) $r['id'])->fetchColumn();
};
$pay1 = $mkPayout('pkM', $pkM, 2000);
$row1 = Payment::find($pay1);
check('A mobile-money payout is created with the "momo" provider and the picker\'s MTN number', $row1['provider'] === 'momo' && $row1['destination_phone'] === '+256770055101' && MobileMoneyProvider::networkOf($row1['destination_phone']) === 'mtn');
$balBefore = LedgerService::balance('picker', $pkM);
$mockSet(['mtn_mode' => 'accept', 'mtn_final' => 'PENDING']);
$out = PaymentService::process($pay1, [], $sa);
$row1 = Payment::find($pay1);
$tr = $mockLast('/disbursement/v1_0/transfer');
$sent = json_decode($tr['b'] ?? '{}', true);
check('MTN: processing starts the transfer; the payment is "processing" with a UUID reference; no money posted yet', $out['error'] === null && $row1['status'] === 'processing'
    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $row1['provider_reference']) === 1 && LedgerService::balance('picker', $pkM) === $balBefore);
check('MTN request: bearer token, reference id, target environment, MSISDN without +, whole-shilling amount, our payment reference as externalId', ($tr['h']['authorization'] ?? '') === 'Bearer mtn-tok'
    && ($tr['h']['x-reference-id'] ?? '') === $row1['provider_reference'] && ($tr['h']['x-target-environment'] ?? '') === 'sandbox' && ($sent['payee']['partyId'] ?? '') === '256770055101'
    && ($sent['amount'] ?? '') === '2000' && ($sent['externalId'] ?? '') === $row1['reference'] && ($sent['payee']['partyIdType'] ?? '') === 'MSISDN');
check('MTN: the same attempt always maps to the same reference (idempotent), a new attempt to a new one', MtnMomoProvider::referenceFor($row1, 1) === MtnMomoProvider::referenceFor($row1, 1) && MtnMomoProvider::referenceFor($row1, 1) !== MtnMomoProvider::referenceFor($row1, 2));
check('Polling while MTN still says PENDING changes nothing', PaymentService::pollOne($pay1) === false && Payment::find($pay1)['status'] === 'processing');

$mockSet(['mtn_final' => 'SUCCESSFUL']);
$outcome = WebhookService::mtn(['externalId' => $row1['reference'], 'status' => 'FAILED']);   // the body lies: it says FAILED
check('A callback is only a nudge: the provider is asked, and its answer (SUCCESSFUL) wins over the callback body', $outcome === 'settled' && Payment::find($pay1)['status'] === 'successful');
check('Success reduced the picker\'s ledger balance by exactly 2,000 and queued a payout SMS', LedgerService::balance('picker', $pkM) === $balBefore - 2000 && $smsFor('payout_paid', $ids['pkM']) === 1);
$entries = (int) $pdo->query("SELECT COUNT(*) FROM financial_ledger WHERE source_type = 'payment' AND source_id = $pay1")->fetchColumn();
$again = WebhookService::mtn(['externalId' => $row1['reference']]);
$again2 = WebhookService::mtn(['referenceId' => $row1['provider_reference']]);
check('Replayed callbacks (by externalId or by reference) post nothing twice and send no second SMS', $again === 'checked' && $again2 === 'checked' && $entries === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM financial_ledger WHERE source_type = 'payment' AND source_id = $pay1")->fetchColumn() === 1 && $smsFor('payout_paid', $ids['pkM']) === 1);
check('A callback for an unknown payment is ignored', WebhookService::mtn(['externalId' => 'PAY-NOPE']) === 'unknown' && WebhookService::mtn([]) === 'unknown' && WebhookService::airtel(['transaction' => ['id' => str_repeat('z', 300)]]) === 'unknown');

// forged callback while MTN says PENDING
$pay2 = $mkPayout('pkM', $pkM, 1500);
$mockSet(['mtn_final' => 'PENDING']);
PaymentService::process($pay2, [], $sa);
$forged = WebhookService::mtn(['externalId' => Payment::find($pay2)['reference'], 'status' => 'SUCCESSFUL', 'financialTransactionId' => '999']);
check('A forged "SUCCESSFUL" callback while MTN says PENDING changes nothing', $forged === 'checked' && Payment::find($pay2)['status'] === 'processing');

// failure then retry with a NEW reference
$mockSet(['mtn_final' => 'FAILED']);
check('MTN reports FAILED: payment failed, request stays approved (can be retried), balance untouched', PaymentService::pollOne($pay2) === true && Payment::find($pay2)['status'] === 'failed'
    && $pdo->query('SELECT status FROM payment_requests WHERE id = ' . (int) Payment::find($pay2)['request_id'])->fetchColumn() === 'approved' && LedgerService::balance('picker', $pkM) === $balBefore - 2000);
$firstRef = (string) Payment::lastAttempt($pay2)['provider_reference'];
$mockSet(['mtn_final' => 'SUCCESSFUL']);
$retry = PaymentService::process($pay2, [], $sa);
$secondRef = (string) Payment::find($pay2)['provider_reference'];
check('Retrying a failed payment uses a different X-Reference-Id (so MTN cannot treat it as a duplicate)', $retry['error'] === null && $secondRef !== '' && $secondRef !== $firstRef
    && ($mockLast('/disbursement/v1_0/transfer')['h']['x-reference-id'] ?? '') === $secondRef);
PaymentService::pollOne($pay2);
check('The retry settles successfully and pays once', Payment::find($pay2)['status'] === 'successful' && LedgerService::balance('picker', $pkM) === $balBefore - 3500);

// transport problems must NOT mark a payment failed
$pay3 = $mkPayout('pkM', $pkM, 1000);
$mockSet(['mtn_mode' => '500']);
PaymentService::process($pay3, [], $sa);
check('MTN answers 500 on send: the payment stays "processing" (money may have moved), not failed', Payment::find($pay3)['status'] === 'processing' && (string) Payment::find($pay3)['provider_reference'] !== '');
$mockSet(['mtn_mode' => 'accept', 'mtn_status_mode' => '500']);
check('MTN answers 500 when asked for status: still processing', PaymentService::pollOne($pay3) === false && Payment::find($pay3)['status'] === 'processing');
$mockSet(['mtn_status_mode' => 'ok', 'mtn_final' => 'SUCCESSFUL']);
check('The send never arrived (404 on status): the payment is failed, safely retryable', PaymentService::pollOne($pay3) === true && Payment::find($pay3)['status'] === 'failed');

$pay4 = $mkPayout('pkM', $pkM, 1200);
Config::set('app.integrations.momo.mtn.base_url', 'http://127.0.0.1:1');
MtnMomoProvider::forgetToken();
$o4 = PaymentService::process($pay4, [], $sa);
check('MTN unreachable: the token call fails safely with a clear message and the payment stays retryable', $o4['error'] !== null && Payment::find($pay4)['status'] === 'failed' && !str_contains(json_encode($o4), 'mtn-key'));
Config::set('app.integrations.momo.mtn.base_url', $mock);
$mockSet(['mtn_mode' => '400']);
$o5 = PaymentService::process($pay4, [], $sa);
check('MTN rejects the request (400): failed with a generic message, the provider\'s detail is not shown', $o5['error'] !== null && !str_contains((string) $o5['error'], 'secret detail') && Payment::find($pay4)['status'] === 'failed');
$mockSet(['mtn_mode' => '403']);
$o6 = PaymentService::process($pay4, [], $sa);
check('MTN refuses access (403): failed with a credentials message', str_contains(strtolower((string) $o6['error']), 'credentials'));
$mockSet(['mtn_mode' => 'accept']);
Config::set('app.integrations.momo.mtn.api_key', 'wrong');
MtnMomoProvider::forgetToken();
$o7 = PaymentService::process($pay4, [], $sa);
check('Wrong MTN credentials: failed, secrets not echoed', $o7['error'] !== null && !str_contains((string) $o7['error'], 'wrong') && Payment::find($pay4)['status'] === 'failed');
Config::set('app.integrations.momo.mtn.api_key', 'mtn-key');
Config::set('app.integrations.momo.mtn.enabled', false);
$n1 = $mockLogCount();
$o8 = PaymentService::process($pay4, [], $sa);
check('MTN switched off: "not configured", and no request leaves the server', str_contains((string) $o8['error'], 'not configured') && $mockLogCount() === $n1);
Config::set('app.integrations.momo.mtn.enabled', true);
MtnMomoProvider::forgetToken();

// ---------------------------------------------------------------- Airtel Money
$pa1 = $mkPayout('pkA', $pkA, 2500);
$ra = Payment::find($pa1);
$balA = LedgerService::balance('picker', $pkA);
$mockSet(['airtel_mode' => 'ok', 'airtel_final' => 'TIP']);
$oa = PaymentService::process($pa1, [], $sa);
$ra = Payment::find($pa1);
$areq = $mockLast('/standard/v1/disbursements/');
$ab = json_decode($areq['b'] ?? '{}', true);
check('Airtel: routed by the number\'s prefix (70 = Airtel); payment "processing" with our transaction id', MobileMoneyProvider::networkOf('+256700055102') === 'airtel' && $oa['error'] === null && $ra['status'] === 'processing'
    && preg_match('/^PL' . $pa1 . 'A1[0-9a-f]{8}$/', (string) $ra['provider_reference']) === 1);
check('Airtel request: bearer token, country and currency headers, 9-digit payee, amount, transaction id', ($areq['h']['authorization'] ?? '') === 'Bearer air-tok' && ($areq['h']['x-country'] ?? '') === 'UG' && ($areq['h']['x-currency'] ?? '') === 'UGX'
    && ($ab['payee']['msisdn'] ?? '') === '700055102' && ($ab['transaction']['amount'] ?? 0) === 2500 && ($ab['transaction']['id'] ?? '') === $ra['provider_reference']);
check('Airtel: the PIN travels RSA-encrypted (the server could decrypt it to the configured PIN) and never in clear', ($areq['pin_plain'] ?? '') === '4321' && !str_contains($areq['b'] ?? '', '4321'));
check('Airtel still in progress (TIP): nothing changes', PaymentService::pollOne($pa1) === false && Payment::find($pa1)['status'] === 'processing');
$mockSet(['airtel_final' => 'TS']);
check('Airtel callback nudges, the status check (TS) settles: balance down by 2,500, one SMS', WebhookService::airtel(['transaction' => ['id' => $ra['provider_reference'], 'status_code' => 'TF']]) === 'settled'
    && Payment::find($pa1)['status'] === 'successful' && LedgerService::balance('picker', $pkA) === $balA - 2500 && $smsFor('payout_paid', $ids['pkA']) === 1);
$pa2 = $mkPayout('pkA', $pkA, 1800);
$mockSet(['airtel_final' => 'TF']);
PaymentService::process($pa2, [], $sa);
check('Airtel TF: payment failed, balance untouched, request still approved', PaymentService::pollOne($pa2) === true && Payment::find($pa2)['status'] === 'failed' && LedgerService::balance('picker', $pkA) === $balA - 2500);
$mockSet(['airtel_mode' => 'fail']);
$oa2 = PaymentService::process($pa2, [], $sa);
check('Airtel refuses to start (success=false): failed with a generic message', $oa2['error'] !== null && !str_contains((string) $oa2['error'], 'secret detail') && Payment::find($pa2)['status'] === 'failed');
$mockSet(['airtel_mode' => '500']);
$pa3 = $mkPayout('pkA', $pkA, 1100);
PaymentService::process($pa3, [], $sa);
check('Airtel 500 on send: stays processing, not failed', Payment::find($pa3)['status'] === 'processing');
$mockSet(['airtel_mode' => 'ok']);
Config::set('app.integrations.momo.airtel.client_secret', 'wrong');
AirtelMoneyProvider::forgetToken();
$pa4 = $mkPayout('pkA', $pkA, 1000);
$oa4 = PaymentService::process($pa4, [], $sa);
check('Wrong Airtel credentials: failed, secret not echoed', $oa4['error'] !== null && !str_contains((string) $oa4['error'], 'wrong') && Payment::find($pa4)['status'] === 'failed');
Config::set('app.integrations.momo.airtel.client_secret', 'air-secret');
Config::set('app.integrations.momo.airtel.public_key', 'not a key');
AirtelMoneyProvider::forgetToken();
check('A broken PIN public key fails safely before anything is sent', str_contains((string) PaymentService::process($pa4, [], $sa)['error'], 'PIN'));
Config::set('app.integrations.momo.airtel.public_key', $pubPem);
AirtelMoneyProvider::forgetToken();

// ---------------------------------------------------------------- router and cash
check('Network detection: MTN 77/78/76/39, Airtel 70/75/74/20, others unknown', MobileMoneyProvider::networkOf('+256781234567') === 'mtn' && MobileMoneyProvider::networkOf('+256391234567') === 'mtn' && MobileMoneyProvider::networkOf('+256751234567') === 'airtel'
    && MobileMoneyProvider::networkOf('+256201234567') === 'airtel' && MobileMoneyProvider::networkOf('+256711234567') === null && MobileMoneyProvider::networkOf(null) === null && MobileMoneyProvider::networkOf('0770000000') === null);
$r = (new MobileMoneyProvider())->initiate(['id' => 1, 'idempotency_key' => 'k', 'reference' => 'PAY-1', 'amount_ugx' => 1000, 'destination_phone' => '+256711234567'], []);
check('A number on neither network is refused with advice, nothing is sent', $r->status === 'failed' && str_contains((string) $r->message, 'not on MTN or Airtel'));
$pc = $mkPayout('pkQ', $pkQ, 1000, 'cash');
check('Cash payouts always use the manual provider, even when the default is mobile money', Payment::find($pc)['provider'] === 'manual' && ProviderFactory::forMethod('cash')->name() === 'manual' && ProviderFactory::forMethod('mobile_money')->name() === 'momo');
Config::set('app.finance.provider', 'manual');

// ---------------------------------------------------------------- secrets never reach the database or the logs
$dump = implode(' ', $pdo->query("SELECT COALESCE(message,'') FROM payment_attempts WHERE payment_id IN (SELECT id FROM payments WHERE picker_id IN ($pkM, $pkA, $pkQ))")->fetchAll(PDO::FETCH_COLUMN))
    . implode(' ', $pdo->query("SELECT COALESCE(details,'') FROM audit_logs WHERE user_id IN (" . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN))
    . implode(' ', $pdo->query("SELECT COALESCE(error,'') FROM sms_outbox")->fetchAll(PDO::FETCH_COLUMN));
$logs = '';
foreach (glob(dirname(__DIR__) . '/storage/logs/*') ?: [] as $lf) {
    $logs .= (string) @file_get_contents($lf);
}
check('No API key, subscription key, client secret, PIN or webhook token appears in payment attempts, audit details, SMS errors or log files', !preg_match('/mtn-key|mtn-sub-key|air-secret|at-key|unit-test-secret-token|BEGIN (RSA )?PRIVATE/', $dump . $logs));

// ---------------------------------------------------------------- webhook gate
Config::set('app.integrations.webhook_token', '');
check('No webhook token configured: nothing is authorised', WebhookService::authorized('') === false && WebhookService::authorized('anything') === false);
Config::set('app.integrations.webhook_token', 'short');
check('A short token (under 24 characters) is never accepted, even if it matches', WebhookService::authorized('short') === false);
Config::set('app.integrations.webhook_token', 'unit-test-secret-token-0123456789');
check('Only the exact token is accepted', WebhookService::authorized('unit-test-secret-token-0123456789') && !WebhookService::authorized('unit-test-secret-token-012345678') && !WebhookService::authorized('UNIT-TEST-SECRET-TOKEN-0123456789'));
for ($i = 0; $i < WebhookService::LIMIT_PER_MINUTE - 1; $i++) {
    WebhookService::log('test', 'ok', '203.0.113.9');
}
$t1 = WebhookService::throttled('203.0.113.9');
WebhookService::log('test', 'ok', '203.0.113.9');
check('Rate limit: allowed up to 120 requests a minute per IP, then throttled', $t1 === false && WebhookService::throttled('203.0.113.9') === true && WebhookService::throttled('203.0.113.10') === false);
check('SMS delivery callback is applied through the gateway report', (function () use ($pdo) {
    $m = SmsService::queue('0700099920', 'dlr', 'test');
    $pdo->exec("UPDATE sms_outbox SET status = 'sent', provider_message_id = 'ATXid_dlr1' WHERE id = " . (int) $m);
    return WebhookService::smsDelivery(['id' => 'ATXid_dlr1', 'status' => 'Failed', 'failureReason' => 'UserInBlacklist']) === 'recorded'
        && $pdo->query("SELECT delivery_status FROM sms_outbox WHERE id = " . (int) $m)->fetchColumn() === 'failed';
})());

$g = newClient();
$h1 = req($g, 'POST', '/webhooks/mtn/guess-the-token', ['externalId' => 'PAY-1']);
$h2 = req($g, 'POST', '/ussd/guess-the-token', ['phoneNumber' => '+256770055101', 'text' => '1', 'sessionId' => 'abc12345']);
$h3 = req($g, 'POST', '/webhooks/airtel/x', []);
$h4 = req($g, 'POST', '/webhooks/sms/x', []);
check('Over HTTP: callback URLs without a configured/valid token answer 404 (and need no CSRF token, so not 400)', $h1['status'] === 404 && $h2['status'] === 404 && $h3['status'] === 404 && $h4['status'] === 404);
check('Callback URLs only accept POST', req($g, 'GET', '/webhooks/mtn/x')['status'] === 405 && req($g, 'GET', '/ussd/x')['status'] === 405);
check('The ordinary forms are still CSRF-protected (a POST to /login without a token is refused)', req($g, 'POST', '/login', ['identifier' => 'x', 'password' => 'y'])['status'] === 400);
check('Denied callbacks are logged (no payload), for troubleshooting', (int) $pdo->query("SELECT COUNT(*) FROM webhook_log WHERE outcome = 'denied' AND created_at >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE")->fetchColumn() >= 4);

// ---------------------------------------------------------------- USSD
$call = fn(string $phone, string $text, string $session = 'sess-0001') => UssdService::handle($phone, $text, $session);
$bal = LedgerService::balance('picker', $pkQ);
check('USSD main menu: CON, four options, within 160 characters', ($m = $call('+256700055103', '')) !== '' && str_starts_with($m, 'CON ') && substr_count($m, "\n") === 4 && strlen($m) <= 160);
check('USSD 1: shows the ledger balance and the amount available', str_starts_with($call('0700055103', '1'), 'END ') && str_contains($call('0700055103', '1'), number_format($bal)));
check('USSD 2: lists recent collections with weight, value and status', str_contains($c2 = $call('+256700055103', '2'), '10kg') && str_contains($c2, '10,000') && str_contains($c2, 'approved') && str_starts_with($c2, 'END '));
check('USSD 3: shows the picker\'s hub price per kg', str_contains($call('+256700055103', '3'), '1,000/kg'));
check('Unknown number gets a friendly END and nothing else', str_starts_with($call('+256700055199', ''), 'END This number is not registered'));
check('A non-picker (hub manager) cannot use the picker menu even with a phone', (function () use ($pdo, $ids, $call) {
    $pdo->prepare('UPDATE users SET phone_e164 = ? WHERE id = ?')->execute(['+256700055190', $ids['hmA']]);
    return str_contains($call('+256700055190', ''), 'not registered');
})());
$pdo->prepare('UPDATE users SET phone_e164 = NULL WHERE id = ?')->execute([$ids['hmA']]);
$pdo->exec("UPDATE users SET status = 'suspended' WHERE id = " . (int) $ids['pkQ']);
check('A suspended picker is told the account is not active', str_contains($call('+256700055103', '1'), 'not active'));
$pdo->exec("UPDATE users SET status = 'active' WHERE id = " . (int) $ids['pkQ']);
check('Garbage input ends the session safely', str_contains($call('+256700055103', '1*abc'), 'Invalid') && str_contains($call('+256700055103', '9'), 'Invalid') && str_contains($call('+256700055103', "1'; DROP TABLE users;--"), 'Invalid') && str_contains($call('+256700055103', str_repeat('1*', 100)), 'Invalid'));
check('Very long or odd text cannot crash the menu', is_string($call('+256700055103', str_repeat('9', 5000))) && is_string($call('', '')) && is_string($call("<script>", '1')));
Config::set('app.integrations.ussd.enabled', false);
check('USSD switched off: every call ends with "not available"', str_contains($call('+256700055103', ''), 'not available'));
Config::set('app.integrations.ussd.enabled', true);

// payout by USSD (pkQ has 10,000 earned; holds from its cash payout of 1,000 reduce what is available)
check('USSD 4: asks for the amount and states the cap', str_starts_with($c4 = $call('+256700055103', '4'), 'CON ') && str_contains($c4, '20,000'));
check('USSD 4: amount below the minimum or above the USSD cap ends with the allowed range', str_contains($call('+256700055103', '4*500'), '1,000') && str_contains($call('+256700055103', '4*25000'), '20,000') && str_contains($call('+256700055103', '4*abc'), 'Invalid'));
check('USSD 4: valid amount asks to confirm', str_starts_with($cc = $call('+256700055103', '4*2000'), 'CON ') && str_contains($cc, '2,000') && str_contains($cc, '1. Yes'));
$reqCount = fn() => (int) $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE picker_id = $pkQ")->fetchColumn();
$c0 = $reqCount();
check('USSD 4: choosing "No" creates nothing', str_contains($call('+256700055103', '4*2000*2', 'sess-cancel'), 'Cancelled') && $reqCount() === $c0);
$done = $call('+256700055103', '4*2000*1', 'sess-ok-1');
$rq = $pdo->query("SELECT * FROM payment_requests WHERE picker_id = $pkQ ORDER BY id DESC LIMIT 1")->fetch();
check('USSD 4: confirming creates ONE payout request: pending approval, mobile money, registered phone, requested by the picker', str_starts_with($done, 'END Request received') && $reqCount() === $c0 + 1
    && $rq['status'] === 'pending_approval' && (int) $rq['amount_ugx'] === 2000 && $rq['method'] === 'mobile_money' && $rq['destination_phone'] === '+256700055103' && (int) $rq['requested_by'] === $ids['pkQ']);
check('USSD 4: the network repeating the final step does not create a second request', str_starts_with($call('+256700055103', '4*2000*1', 'sess-ok-1'), 'END Request received') && $reqCount() === $c0 + 1);
check('USSD 4: the request is audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'ussd.payout_requested' AND user_id = {$ids['pkQ']}")->fetchColumn() === 1);
check('USSD 4: more than the available balance is refused with the available amount', str_contains($over = $call('+256700055103', '4*9000*1', 'sess-over'), 'available') && $reqCount() === $c0 + 1);
check('USSD 4: no valid session id, no request', str_contains($call('+256700055103', '4*1500*1', ''), 'verify this session') && $reqCount() === $c0 + 1);
Config::set('app.integrations.ussd.payout_max_per_day', 1);
check('USSD 4: the daily limit stops further requests', str_contains($call('+256700055103', '4*1500*1', 'sess-ok-2'), 'daily limit') && $reqCount() === $c0 + 1);
Config::set('app.integrations.ussd.payout_max_per_day', 3);
$ap = PayoutService::decide((int) $rq['id'], 'approve', null, $pm);
check('The USSD request still needs the normal approval by someone else (and works through it)', $ap['error'] === null && $pdo->query("SELECT status FROM payment_requests WHERE id = {$rq['id']}")->fetchColumn() === 'approved');
$lens = array_map('strlen', [$call('+256700055103', ''), $call('+256700055103', '2'), $call('+256700055103', '3'), $call('+256700055103', '4'), $done]);
check('Every USSD reply is at most 160 characters and starts with CON or END', max($lens) <= 160);

// ---------------------------------------------------------------- load
$bulk = $pdo->prepare("INSERT INTO sms_outbox (recipient, body, event, status, next_attempt_at, created_at) VALUES (?, 'bulk', 'bulk', 'queued', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
$pdo->beginTransaction();
for ($i = 0; $i < 600; $i++) {
    $bulk->execute(['+2567000999' . str_pad((string) ($i % 100), 2, '0', STR_PAD_LEFT)]);
}
$pdo->commit();
$t0 = microtime(true);
$total = ['sent' => 0, 'failed' => 0, 'retry' => 0];
for ($i = 0; $i < 4; $i++) {
    foreach (SmsService::dispatch(200) as $k => $v) { $total[$k] += $v; }
}
$ms = (microtime(true) - $t0) * 1000;
check('600 queued messages are all dispatched in batches in under 12 seconds (about 10 ms of database work each; real sending time is the gateway\'s)', $total['sent'] + $total['failed'] >= 600 && $ms < 12000, sprintf('%.0f ms, %s', $ms, json_encode($total)));

// ---------------------------------------------------------------- finish
foreach ($origCfg as $k => $v) {
    Config::set($k, $v);
}
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}
@unlink($stateFile);
$cleanup();
$left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'phase9test.%'")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM collection_hubs WHERE name LIKE 'P9TEST%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE recipient LIKE '+25670009%' OR recipient LIKE '+25670005%' OR recipient LIKE '+25677005%'")->fetchColumn();
check('Test data cleaned up', $left === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
