<?php
declare(strict_types=1);

/**
 * Create a Super Administrator (CLI only). This is how the FIRST account is made on a new server; the demo seeder must never run in production.
 *
 *   php create-admin.php --email=name@example.org --name="Full Name" [--phone=0772123456]
 *
 * A random temporary password is printed once. The person must change it at first sign-in.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/app/bootstrap.php';

use App\Helpers\Phone;
use App\Helpers\Secret;
use App\Models\AuditLog;
use App\Models\User;

$o = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(email|name|phone)=(.*)$/s', $a, $m)) {
        $o[$m[1]] = trim($m[2]);
    }
}
$email = strtolower($o['email'] ?? '');
$name = $o['name'] ?? '';
$phone = ($o['phone'] ?? '') !== '' ? Phone::normalize($o['phone']) : null;

$errors = [];
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    $errors[] = '--email must be a valid email address.';
}
if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
    $errors[] = '--name must be 2 to 120 characters.';
}
if (($o['phone'] ?? '') !== '' && $phone === null) {
    $errors[] = '--phone is not a valid Ugandan number.';
}
if (!$errors && User::emailTaken($email)) {
    $errors[] = 'That email address is already used.';
}
if (!$errors && $phone !== null && User::phoneTaken($phone)) {
    $errors[] = 'That phone number is already used.';
}
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\nUsage: php create-admin.php --email=name@example.org --name=\"Full Name\" [--phone=0772123456]\n");
    exit(1);
}

$password = Secret::tempPassword(16);
$id = User::create(['role' => 'super_admin', 'full_name' => $name, 'email' => $email, 'phone_e164' => $phone, 'password' => $password, 'status' => 'active']);
User::setMustChange($id, true);
AuditLog::write('user.created_by_cli', null, 'user', $id, ['role' => 'super_admin']);

echo "Super Administrator created.\n  Sign in with: $email\n  Temporary password (shown once): $password\n  They must choose a new password at first sign-in.\n";
