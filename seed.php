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

if ($command !== 'demo-users') {
    fwrite(STDERR, "Usage: php seed.php demo-users [--reset]\n");
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
