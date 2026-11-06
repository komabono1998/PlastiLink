<?php
declare(strict_types=1);

/**
 * PlastiLink seeder (CLI only).
 *
 *   php seed.php demo-users           Create one DEMO account per role (skips existing)
 *   php seed.php demo-users --reset   Also give existing demo accounts a new random password
 *
 * Passwords are random, printed once here, and never stored in the repository.
 * Refuses to run when app.env is "production".
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/app/bootstrap.php';

use App\Models\User;
use App\Core\Database;

if (config('app.env') === 'production') {
    fwrite(STDERR, "Refusing to seed demo data in production.\n");
    exit(1);
}

$command = $argv[1] ?? '';
$reset = in_array('--reset', $argv, true);

if ($command === 'demo-data') {
    // Demonstration hubs, pickers and recyclers (clearly labelled DEMO). Safe to re-run.
    $pdo = Database::connection();
    $nb = $pdo->query('SELECT id, name FROM neighbourhoods ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $nbIds = array_keys($nb);
    $uid = static function (string $col, string $val) use ($pdo): ?int {
        $s = $pdo->prepare("SELECT id FROM users WHERE $col = ?");
        $s->execute([$val]);
        return ($v = $s->fetchColumn()) ? (int) $v : null;
    };

    $hubIds = [];
    foreach ([['DEMO Hub Walbiongo', $nbIds[0]], ['DEMO Hub Olailong', $nbIds[1] ?? $nbIds[0]]] as [$name, $nid]) {
        $s = $pdo->prepare('SELECT id FROM collection_hubs WHERE name = ?');
        $s->execute([$name]);
        $hubIds[] = (int) ($s->fetchColumn() ?: App\Models\Hub::create([
            'name' => $name, 'neighbourhood_id' => $nid, 'address_note' => 'Demo hub (not a real location)',
            'latitude' => null, 'longitude' => null, 'status' => 'active',
        ]));
    }
    if ($m = $uid('email', 'demo.hubmanager@example.test')) {
        App\Models\Hub::assign($hubIds[0], $m, 'manager');
    }
    if ($o = $uid('email', 'demo.hubop@example.test')) {
        App\Models\Hub::assign($hubIds[0], $o, 'operator');
    }

    $pw = static fn() => App\Helpers\Secret::tempPassword(14);   // random, never printed or stored elsewhere
    // Profile for the demo picker login created by "demo-users"
    if (($pu = $uid('phone_e164', '+256700000001')) && !$pdo->query("SELECT COUNT(*) FROM pickers WHERE user_id = $pu")->fetchColumn()) {
        App\Models\Picker::create(['user_id' => $pu, 'neighbourhood_id' => $nbIds[0], 'preferred_hub_id' => $hubIds[0], 'national_id' => null,
            'consent_version' => App\Services\PickerService::CONSENT_VERSION, 'registered_by' => null]);
    }
    $names = ['Akello Grace', 'Opio Moses', 'Atim Sarah', 'Okello Denis', 'Auma Rose'];
    foreach ($names as $i => $n) {
        $phone = '+2567000001' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
        $id = $uid('phone_e164', $phone) ?? App\Models\User::create(['role' => 'waste_picker', 'full_name' => "DEMO $n", 'phone_e164' => $phone,
            'password' => $pw(), 'status' => $i === 4 ? 'pending' : 'active']);
        if ($pdo->query("SELECT COUNT(*) FROM pickers WHERE user_id = $id")->fetchColumn()) {
            continue;   // profile already there
        }
        App\Models\Picker::create(['user_id' => $id, 'neighbourhood_id' => $nbIds[$i % count($nbIds)], 'preferred_hub_id' => $hubIds[$i % 2],
            'national_id' => null, 'consent_version' => App\Services\PickerService::CONSENT_VERSION, 'registered_by' => null]);
    }
    if (($ru = $uid('email', 'demo.recycler@example.test')) && !$pdo->query("SELECT COUNT(*) FROM recyclers WHERE user_id = $ru")->fetchColumn()) {
        App\Models\Recycler::create(['user_id' => ($ru), 'business_name' => 'DEMO Recycling Ltd', 'registration_number' => null,
            'contact_person' => 'DEMO Recycler', 'address' => 'Demo address, Gulu', 'materials' => 'PET bottles', 'status' => 'approved']);
    }
    $id = $uid('email', 'demo.pending.recycler@example.test') ?? App\Models\User::create(['role' => 'recycler', 'full_name' => 'DEMO Pending Contact',
        'email' => 'demo.pending.recycler@example.test', 'password' => $pw(), 'status' => 'pending']);
    if (!$pdo->query("SELECT COUNT(*) FROM recyclers WHERE user_id = $id")->fetchColumn()) {
        App\Models\Recycler::create(['user_id' => $id, 'business_name' => 'DEMO Pending Plastics Co', 'registration_number' => null,
            'contact_person' => 'DEMO Pending Contact', 'address' => 'Demo address, Gulu', 'materials' => 'PET bottles', 'status' => 'pending']);
    }
    // Demo price + collections (deterministic tokens make this idempotent). The price is a DEMO value, not a real price.
    $saId = $uid('email', 'demo.superadmin@example.test');
    $petId = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code = 'PET'")->fetchColumn();
    if ($saId && $petId && !App\Models\Price::current($petId, null)) {
        App\Models\Price::create($petId, null, 1000, 'DEMO price (not a real price)', $saId);
    }
    $opId = $uid('email', 'demo.hubop@example.test');
    $mgrId = $uid('email', 'demo.hubmanager@example.test');
    if ($opId && $mgrId && $petId) {
        $op = App\Models\User::findById($opId);
        $mgr = App\Models\User::findById($mgrId);
        $demoPickers = $pdo->query("SELECT p.id FROM pickers p JOIN users u ON u.id = p.user_id
                                    WHERE u.status = 'active' AND p.preferred_hub_id = {$hubIds[0]} ORDER BY p.id")->fetchAll(PDO::FETCH_COLUMN);
        $weights = [['12.5', '0.5'], ['8', ''], ['20.25', '1'], ['5.5', '0.25'], ['15', '0.5'], ['9.75', '0.75'], ['30', '2'], ['4.2', '']];
        foreach ($weights as $i => [$gross, $tare]) {
            if (!$demoPickers) {
                break;
            }
            $res = App\Services\CollectionService::record([
                'token' => md5('demo-collection-' . $i), 'picker_id' => $demoPickers[$i % count($demoPickers)], 'hub_id' => $hubIds[0],
                'plastic_category_id' => $petId, 'gross_weight_kg' => $gross, 'tare_weight_kg' => $tare, 'notes' => 'DEMO collection',
            ], $op);
            if (!empty($res['id']) && empty($res['duplicate']) && $i < 6) {
                App\Services\CollectionService::approve($res['id'], $mgr);   // the last two stay pending
            }
        }
    }
    // Demo stock movements (deterministic tokens: safe to re-run).
    if ($mgrId && $petId && !empty($mgr)) {
        $recId = (int) $pdo->query("SELECT r.id FROM recyclers r JOIN users u ON u.id = r.user_id WHERE u.email = 'demo.recycler@example.test' AND r.status = 'approved'")->fetchColumn();
        if ($recId) {
            App\Services\DispatchService::create(['token' => md5('demo-dispatch-1'), 'hub_id' => $hubIds[0], 'recycler_id' => $recId, 'plastic_category_id' => $petId,
                'quantity_kg' => '20', 'vehicle' => 'DEMO UAA 000A', 'driver' => 'DEMO Driver', 'notes' => 'DEMO dispatch'], $mgr);
        }
        App\Services\TransferService::send(['token' => md5('demo-transfer-1'), 'from_hub_id' => $hubIds[0], 'to_hub_id' => $hubIds[1], 'plastic_category_id' => $petId,
            'quantity_kg' => '5', 'notes' => 'DEMO transfer (waiting to be received)'], $mgr);
        App\Services\AdjustmentService::request(['token' => md5('demo-adjustment-1'), 'kind' => 'count', 'hub_id' => $hubIds[0], 'plastic_category_id' => $petId,
            'quantity_kg' => '40', 'reason' => 'DEMO physical count (waiting for approval)'], $mgr);
    }
    // Demo marketplace: one order being delivered (a load waiting for the recycler to confirm) and one request waiting for the hub.
    $recUser = $uid('email', 'demo.recycler@example.test');
    if ($recUser && $mgrId && $petId && !empty($mgr)) {
        $rec = App\Services\OrderService::class;
        $recActor = App\Models\User::findById($recUser);
        $o1 = $rec::create(['token' => md5('demo-order-1'), 'hub_id' => $hubIds[0], 'plastic_category_id' => $petId, 'quantity_kg' => '10',
            'price_per_kg_ugx' => '1200', 'note' => 'DEMO offer'], $recActor);
        if (!empty($o1['id']) && empty($o1['duplicate'])) {
            $rec::counter((int) $o1['id'], ['quantity_kg' => '10', 'price_per_kg_ugx' => '1300', 'note' => 'DEMO counter-offer'], $mgr);
            $rec::accept((int) $o1['id'], $recActor);
            $rec::dispatch((int) $o1['id'], ['token' => md5('demo-order-1-load'), 'quantity_kg' => '4', 'vehicle' => 'DEMO UAA 001B', 'driver' => 'DEMO Driver'], $mgr);
            // The recycler confirms the 4 kg; the hub closes the rest short. The completed order is invoiced automatically (4 kg x 1,300).
            foreach (App\Models\StockDispatch::forOrder((int) $o1['id']) as $ld) {
                $rec::receive((int) $o1['id'], (int) $ld['id'], ['received_kg' => '4'], $recActor);
            }
            $rec::closeShort((int) $o1['id'], 'DEMO: the rest will not be delivered', $mgr);
        }
        $rec::create(['token' => md5('demo-order-2'), 'hub_id' => $hubIds[0], 'plastic_category_id' => $petId, 'quantity_kg' => '5',
            'price_per_kg_ugx' => '1100', 'note' => 'DEMO offer waiting for the hub'], $recActor);
    }
    // Demo money: a cash payout that went through request -> approval -> payment, a mobile money payout waiting for approval,
    // and a recycler payment waiting for a second person to confirm it.
    $pmId = $uid('email', 'demo.projectmanager@example.test');
    $demoPicker = $pdo->query("SELECT p.id FROM pickers p JOIN users u ON u.id = p.user_id WHERE u.phone_e164 = '+256700000001'")->fetchColumn();
    if ($pmId && $mgrId && $demoPicker && !empty($mgr)) {
        $pm = App\Models\User::findById($pmId);
        $r1 = App\Services\PayoutService::request(['token' => md5('demo-payout-1'), 'picker_id' => $demoPicker, 'amount_ugx' => '5000', 'method' => 'cash'], $mgr);
        if (!empty($r1['id']) && empty($r1['duplicate'])) {
            App\Services\PayoutService::decide((int) $r1['id'], 'approve', null, $pm);
            $payId = (int) $pdo->query("SELECT id FROM payments WHERE request_id = {$r1['id']}")->fetchColumn();
            App\Services\PaymentService::process($payId, ['receipt_note' => 'DEMO cash paid at hub, witnessed by hub operator'], $mgr);
        }
        App\Services\PayoutService::request(['token' => md5('demo-payout-2'), 'picker_id' => $demoPicker, 'amount_ugx' => '3000', 'method' => 'mobile_money'], $mgr);
    }
    App\Services\InvoiceService::generateMissing();
    $demoInvoice = (int) $pdo->query('SELECT id FROM invoices ORDER BY id LIMIT 1')->fetchColumn();
    if ($demoInvoice && $pmId && !$pdo->query("SELECT COUNT(*) FROM payments WHERE direction = 'in' AND invoice_id = $demoInvoice")->fetchColumn()) {
        App\Services\InvoiceService::recordReceipt($demoInvoice, ['token' => md5('demo-receipt-1'), 'amount_ugx' => '2000', 'method' => 'mobile_money',
            'provider_reference' => 'DEMO-MM-0001', 'received_on' => App\Helpers\Dates::todayYmd()], App\Models\User::findById($pmId));
    }
    echo "Demo hubs, pickers, recyclers, a DEMO price and collections are in place (all labelled DEMO). Run 'demo-users' first for sign-in accounts.\n";
    exit(0);
}

if ($command !== 'demo-users') {
    fwrite(STDERR, "Usage: php seed.php demo-users [--reset] | demo-data\n");
    exit(1);
}

$demo = [
    ['super_admin',     'DEMO Super Administrator', 'demo.superadmin@example.test', null],
    ['project_manager', 'DEMO Project Manager',     'demo.projectmanager@example.test', null],
    ['hub_manager',     'DEMO Hub Manager',         'demo.hubmanager@example.test', null],
    ['hub_operator',    'DEMO Hub Operator',        'demo.hubop@example.test', null],
    ['waste_picker',    'DEMO Waste Picker',        null, '+256700000001'],
    ['recycler',        'DEMO Recycler',            'demo.recycler@example.test', null],
];

$pdo = Database::connection();
$alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$random = static function (int $len) use ($alphabet): string {
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
};

echo "DEMO accounts (shown once; passwords are not stored anywhere else):\n\n";
printf("%-26s %-34s %s\n", 'Role', 'Sign in with', 'Password');
foreach ($demo as [$role, $name, $email, $phone]) {
    $col = $email ? 'email' : 'phone_e164';
    $val = $email ?? $phone;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE $col = ?");
    $stmt->execute([$val]);
    $existing = $stmt->fetchColumn();

    if ($existing && !$reset) {
        printf("%-26s %-34s %s\n", $role, $val, '(exists; use --reset for a new password)');
        continue;
    }
    $password = $random(12);
    if ($existing) {
        User::updatePassword((int) $existing, $password);
        User::setStatus((int) $existing, 'active');
    } else {
        User::create([
            'role' => $role, 'full_name' => $name, 'email' => $email,
            'phone_e164' => $phone, 'password' => $password, 'status' => 'active',
        ]);
    }
    printf("%-26s %-34s %s\n", $role, $val, $password);
}
echo "\nThese are demonstration accounts. Delete them before real use.\n";
