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
    echo "Demo hubs, pickers and recyclers are in place (all labelled DEMO). Run 'demo-users' first for sign-in accounts.\n";
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
