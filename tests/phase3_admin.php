<?php
declare(strict_types=1);

/**
 * Phase 3 tests: administration, user management, registrations, approvals, hub scoping.
 *   php tests/phase3_admin.php [base-url]
 * Creates temporary data (phase3test.*, "P3TEST ..." hubs) and removes it afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/_http.php';

use App\Core\Database;
use App\Helpers\Paginator;
use App\Helpers\Validator;
use App\Models\Hub;
use App\Models\User;
use App\Services\PickerService;
use App\Services\UserService;

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase3Test!pw';
$pass = 0;
$fail = 0;

$cleanup = function () use ($pdo): void {
    $ids = $pdo->query("SELECT id FROM users WHERE email LIKE 'phase3test.%' OR phone_e164 LIKE '+256700088%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($in) OR (entity_type = 'user' AND entity_id IN ($in))");
        $pdo->exec("DELETE FROM pickers WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM recyclers WHERE user_id IN ($in)");
    }
    $pdo->exec("DELETE FROM audit_logs WHERE details LIKE '%phase3test%' OR details LIKE '%P3TEST%'");
    $pdo->exec("DELETE FROM audit_logs WHERE entity_type IN ('hub','neighbourhood') AND entity_id IN (SELECT id FROM collection_hubs WHERE name LIKE 'P3TEST%')");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase3test%' OR identifier LIKE '%700088%'");
    $pdo->exec("DELETE FROM collection_hubs WHERE name LIKE 'P3TEST%'");
    $pdo->exec("DELETE FROM neighbourhoods WHERE name LIKE 'P3TEST%'");
    if ($ids) {
        $pdo->exec("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')');
        $pdo->exec("DELETE FROM sms_outbox WHERE user_id IS NULL");
    }
};
$cleanup();

// ---------------------------------------------------------------- setup
$staff = [];
foreach (['super_admin' => 'sa', 'project_manager' => 'pm', 'hub_manager' => 'hmA', 'hub_operator' => 'hoA'] as $role => $k) {
    $staff[$k] = User::create(['role' => $role, 'full_name' => "P3 $k", 'email' => "phase3test.$k@example.test", 'password' => $PW, 'status' => 'active']);
}
$staff['hmB'] = User::create(['role' => 'hub_manager', 'full_name' => 'P3 hmB', 'email' => 'phase3test.hmb@example.test', 'password' => $PW, 'status' => 'active']);
$nbId = (int) $pdo->query('SELECT id FROM neighbourhoods WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$hubA = Hub::create(['name' => 'P3TEST Hub A', 'neighbourhood_id' => $nbId, 'address_note' => null, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
$hubB = Hub::create(['name' => 'P3TEST Hub B', 'neighbourhood_id' => $nbId, 'address_note' => null, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
Hub::assign($hubA, $staff['hmA'], 'manager');
Hub::assign($hubA, $staff['hoA'], 'operator');
Hub::assign($hubB, $staff['hmB'], 'manager');

$sa = newClient(); $pm = newClient(); $hmA = newClient(); $hoA = newClient(); $hmB = newClient();
$logins = ['sa' => $sa, 'pm' => $pm, 'hmA' => $hmA, 'hoA' => $hoA, 'hmB' => $hmB];
foreach ($logins as $k => $c) {
    $r = login($c, $k === 'hmB' ? 'phase3test.hmb@example.test' : "phase3test.$k@example.test", $PW);
    check("Setup: $k signs in", $r['status'] === 303 && loc($r) !== '/login', 'loc ' . loc($r));
}

// ---------------------------------------------------------------- helpers: validator / paginator
check('Validator: required/email/phone/in/between', Validator::validate(
    ['a' => '', 'b' => 'nope', 'c' => '123', 'd' => 'x', 'e' => '999'],
    ['a' => ['A', 'required'], 'b' => ['B', 'email'], 'c' => ['C', 'phone'], 'd' => ['D', 'in:p,q'], 'e' => ['E', 'between:-90,90']]
) === [
    'a' => 'A is required.', 'b' => 'Enter a valid email address for B.',
    'c' => 'C must be a valid Ugandan phone number, for example 0771234567.', 'd' => 'Choose a valid option for D.', 'e' => 'E must be between -90 and 90.',
]);
check('Paginator clamps pages', Paginator::make(45, 99, 20)['page'] === 3 && Paginator::make(0, 1, 20)['pages'] === 1 && Paginator::make(45, 2, 20)['offset'] === 20);

// ---------------------------------------------------------------- everything requires sign-in
$g = newClient();
foreach (['/users', '/users/create', '/users/1', '/neighbourhoods', '/hubs', '/hubs/1', '/pickers', '/pickers/register', '/pickers/1', '/recyclers', '/recyclers/create', '/approvals', '/account/password'] as $p) {
    $r = req($g, 'GET', $p);
    check("Guest -> $p redirects to /login", $r['status'] === 303 && loc($r) === '/login', "status {$r['status']}");
}
foreach (['/users', '/users/1/status', '/pickers/1/decision', '/recyclers/1/decision', '/hubs', '/neighbourhoods', '/hubs/1/assign'] as $p) {
    $r = req($g, 'POST', $p, ['x' => '1']);
    check("Guest POST $p blocked (no CSRF token)", $r['status'] === 400, "status {$r['status']}");
}
check('Non-numeric id is a 404', req($sa, 'GET', '/users/abc')['status'] === 404 && req($sa, 'GET', '/users/999999')['status'] === 404);

// ---------------------------------------------------------------- RBAC on the new screens
$matrix = [
    'sa'  => ['/users' => 200, '/users/create' => 200, '/neighbourhoods' => 200, '/hubs' => 200, '/hubs/create' => 200, '/pickers' => 200, '/pickers/register' => 200, '/recyclers' => 200, '/recyclers/create' => 200, '/approvals' => 200],
    'pm'  => ['/users' => 200, '/users/create' => 403, '/neighbourhoods' => 403, '/hubs' => 200, '/hubs/create' => 403, '/pickers' => 200, '/pickers/register' => 403, '/recyclers' => 200, '/recyclers/create' => 200, '/approvals' => 200],
    'hmA' => ['/users' => 403, '/users/create' => 403, '/neighbourhoods' => 403, '/hubs' => 200, '/hubs/create' => 403, '/pickers' => 200, '/pickers/register' => 200, '/recyclers' => 403, '/recyclers/create' => 403, '/approvals' => 200],
    'hoA' => ['/users' => 403, '/neighbourhoods' => 403, '/hubs' => 403, '/hubs/create' => 403, '/pickers' => 200, '/pickers/register' => 200, '/recyclers' => 403, '/approvals' => 403],
];
foreach ($matrix as $who => $paths) {
    foreach ($paths as $path => $expected) {
        $r = req($logins[$who], 'GET', $path);
        check("RBAC: $who GET $path = $expected", $r['status'] === $expected, "got {$r['status']}");
    }
}
$r = postVia($pm, '/users', '/users', ['full_name' => 'Nope', 'email' => 'phase3test.nope@example.test', 'role' => 'hub_operator']);
check('RBAC: project manager cannot create users (POST 403)', $r['status'] === 403);
$r = postVia($hoA, '/pickers', '/pickers/1/decision', ['decision' => 'approve']);
check('RBAC: hub operator cannot approve pickers (POST 403)', $r['status'] === 403);
$r = postVia($hmA, '/pickers', '/recyclers/1/decision', ['decision' => 'approve']);
check('RBAC: hub manager cannot decide recyclers (POST 403)', $r['status'] === 403);

// ---------------------------------------------------------------- user management
$r = postVia($sa, '/users/create', '/users', ['full_name' => '', 'email' => 'bad', 'phone' => '', 'role' => 'hub_operator']);
$page = strip_tags(follow($sa, $r)['body']);
check('Invalid staff form returns to the form with plain-language errors', loc($r) === '/users/create' && str_contains($page, 'Full name is required.') && str_contains($page, 'Enter a valid email address'));
$r = postVia($sa, '/users/create', '/users', ['full_name' => 'P3 New Operator', 'email' => 'phase3test.newop@example.test', 'phone' => '', 'role' => 'hub_operator']);
check('Valid staff account created -> user page', $r['status'] === 303 && preg_match('#^/users/(\d+)$#', loc($r), $m) === 1);
$newId = (int) ($m[1] ?? 0);
$page = follow($sa, $r);
preg_match('#font-monospace user-select-all">([A-Za-z0-9]{10})<#', $page['body'], $tm);
$temp = $tm[1] ?? '';
check('Temporary password shown once on creation', $temp !== '');
check('Temporary password NOT shown on reload', !str_contains(req($sa, 'GET', "/users/$newId")['body'], 'font-monospace user-select-all'));
$row = $pdo->query("SELECT status, must_change_password, password_hash FROM users WHERE id = $newId")->fetch();
check('New account: active, must change password, hashed', $row['status'] === 'active' && (int) $row['must_change_password'] === 1 && password_verify($temp, $row['password_hash']) && $row['password_hash'] !== $temp);
$r = postVia($sa, '/users/create', '/users', ['full_name' => 'Dup', 'email' => 'PHASE3TEST.newop@example.test', 'role' => 'hub_operator']);
check('Duplicate email (any case) refused', str_contains(strip_tags(follow($sa, $r)['body']), 'already uses this email'));
$r = postVia($sa, '/users/create', '/users', ['full_name' => 'Bad role', 'email' => 'phase3test.badrole@example.test', 'role' => 'waste_picker']);
check('Cannot create a picker through the staff form', str_contains(strip_tags(follow($sa, $r)['body']), 'Choose a valid option for Role'));

// forced password change
$nc = newClient();
$r = login($nc, 'phase3test.newop@example.test', $temp);
check('Temp-password login lands on /account/password', loc($r) === '/hub' || loc($r) === '/account/password');
check('Everything else redirects to /account/password until changed', loc(req($nc, 'GET', '/hub')) === '/account/password' && loc(req($nc, 'GET', '/pickers')) === '/account/password');
$r = postVia($nc, '/account/password', '/account/password', ['current_password' => 'wrong', 'password' => 'Fresh-Pass-88', 'password_confirmation' => 'Fresh-Pass-88']);
check('Wrong current password refused', str_contains(strip_tags(follow($nc, $r)['body']), 'current password is not correct'));
$r = postVia($nc, '/account/password', '/account/password', ['current_password' => $temp, 'password' => 'password', 'password_confirmation' => 'password']);
check('Weak new password refused', str_contains(strip_tags(follow($nc, $r)['body']), 'too common'));
$r = postVia($nc, '/account/password', '/account/password', ['current_password' => $temp, 'password' => 'Fresh-Pass-88', 'password_confirmation' => 'Fresh-Pass-88']);
check('Password changed -> dashboard', $r['status'] === 303 && loc($r) === '/hub');
check('Flag cleared and access restored', (int) $pdo->query("SELECT must_change_password FROM users WHERE id = $newId")->fetchColumn() === 0 && req($nc, 'GET', '/pickers')['status'] === 200);

// edit / safeguards
$r = postVia($sa, "/users/$newId/edit", "/users/$newId", ['full_name' => 'P3 Renamed Operator', 'email' => 'phase3test.newop@example.test', 'phone' => '0700088111', 'role' => 'hub_operator']);
check('Edit user saves (phone normalised)', loc($r) === "/users/$newId" && $pdo->query("SELECT phone_e164 FROM users WHERE id = $newId")->fetchColumn() === '+256700088111');
$r = postVia($sa, "/users/{$staff['sa']}/edit", "/users/{$staff['sa']}", ['full_name' => 'P3 sa', 'email' => 'phase3test.sa@example.test', 'role' => 'hub_manager']);
check('Cannot change your own role', str_contains(strip_tags(follow($sa, $r)['body']), 'cannot change your own role'));
$r = postVia($sa, "/users/{$staff['sa']}", "/users/{$staff['sa']}/status", ['status' => 'suspended']);
check('Cannot suspend yourself', str_contains(strip_tags(follow($sa, $r)['body']), 'cannot change the status of your own account'));

// last super-admin guard (inside a transaction that is rolled back)
$pdo->beginTransaction();
$pdo->exec("UPDATE users u JOIN roles r ON r.id = u.role_id SET u.status = 'suspended' WHERE r.slug = 'super_admin' AND u.email NOT LIKE 'phase3test.%'");
$other = User::create(['role' => 'super_admin', 'full_name' => 'P3 sa2', 'email' => 'phase3test.sa2@example.test', 'password' => $PW, 'status' => 'active']);
$sa1 = User::findById($staff['sa']);
// two active admins (sa, sa2): suspending sa2 is allowed; then sa is the last one
check('Can suspend a super admin while another remains', UserService::setStatus($other, 'suspended', ['id' => $staff['sa']]) === null);
User::decide($other, 'active', null);
User::decide($staff['sa'], 'suspended', null);
$err = UserService::setStatus($other, 'suspended', ['id' => $staff['pm']]);
check('Last active Super Administrator cannot be suspended', $err !== null && str_contains($err, 'last active Super Administrator'), (string) $err);
$errs = UserService::update($other, ['full_name' => 'x', 'email' => 'phase3test.sa2@example.test', 'role' => 'hub_manager'], ['id' => $staff['pm']]);
check('Last active Super Administrator cannot be demoted', isset($errs['errors']['role']));
$pdo->rollBack();
check('Guard test rolled back cleanly', (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'phase3test.sa2@example.test'")->fetchColumn() === 0);

// status + suspension takes effect
$r = postVia($sa, "/users/{$staff['hmB']}", "/users/{$staff['hmB']}/status", ['status' => 'suspended']);
check('Suspend a user', $pdo->query("SELECT status FROM users WHERE id = {$staff['hmB']}")->fetchColumn() === 'suspended');
check('Suspended user is locked out on next request', loc(req($hmB, 'GET', '/hubs')) === '/login');
$c = newClient();
check('Suspended user cannot sign in', str_contains(follow($c, login($c, 'phase3test.hmb@example.test', $PW))['body'], 'suspended'));
postVia($sa, "/users/{$staff['hmB']}", "/users/{$staff['hmB']}/status", ['status' => 'active']);
$hmB = newClient(); login($hmB, 'phase3test.hmb@example.test', $PW);
check('Reactivated user can sign in again', req($hmB, 'GET', '/hubs')['status'] === 200);
check('Status changes are audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.status_changed' AND entity_id = {$staff['hmB']}")->fetchColumn() === 2);

// admin password reset
$r = postVia($sa, "/users/{$staff['hoA']}", "/users/{$staff['hoA']}/reset-password", []);
$p = follow($sa, $r);
preg_match('#font-monospace user-select-all">([A-Za-z0-9]{10})<#', $p['body'], $tm2);
check('Admin reset shows a one-time temporary password', ($tm2[1] ?? '') !== '' && (int) $pdo->query("SELECT must_change_password FROM users WHERE id = {$staff['hoA']}")->fetchColumn() === 1);
$c = newClient();
check('Old password stops working after admin reset', loc(login($c, 'phase3test.hoa@example.test', $PW)) === '/login');
// restore hoA for later tests
User::updatePassword($staff['hoA'], $PW);
$hoA = newClient(); login($hoA, 'phase3test.hoa@example.test', $PW);

// ---------------------------------------------------------------- neighbourhoods
$r = postVia($sa, '/neighbourhoods', '/neighbourhoods', ['name' => 'P3TEST Area']);
check('Add neighbourhood', loc($r) === '/neighbourhoods' && str_contains(req($sa, 'GET', '/neighbourhoods')['body'], 'P3TEST Area'));
$r = postVia($sa, '/neighbourhoods', '/neighbourhoods', ['name' => 'p3test area']);
check('Duplicate neighbourhood refused (case-insensitive)', str_contains(strip_tags(follow($sa, $r)['body']), 'already exists'));
$nid = (int) $pdo->query("SELECT id FROM neighbourhoods WHERE name = 'P3TEST Area'")->fetchColumn();
postVia($sa, '/neighbourhoods', "/neighbourhoods/$nid", ['action' => 'toggle']);
check('Deactivated neighbourhood disappears from registration options', !str_contains(req($sa, 'GET', '/pickers/register')['body'], 'P3TEST Area'));
postVia($sa, '/neighbourhoods', "/neighbourhoods/$nid", ['action' => 'toggle']);
check('Reactivated neighbourhood is selectable', str_contains(req($sa, 'GET', '/pickers/register')['body'], 'P3TEST Area'));
check('Seeded pilot neighbourhoods exist', (int) $pdo->query("SELECT COUNT(*) FROM neighbourhoods WHERE name IN ('Walbiongo','Olailong','Kabedo Opong')")->fetchColumn() === 3);

// ---------------------------------------------------------------- hubs
$r = postVia($sa, '/hubs/create', '/hubs', ['name' => '', 'neighbourhood_id' => '', 'status' => 'active', 'latitude' => '2.77', 'longitude' => '']);
$t = strip_tags(follow($sa, $r)['body']);
check('Hub form validation messages', str_contains($t, 'Hub name is required.') && str_contains($t, 'Choose a neighbourhood') || str_contains($t, 'Neighbourhood is required.'));
$r = postVia($sa, '/hubs/create', '/hubs', ['name' => 'P3TEST Hub C', 'neighbourhood_id' => $nbId, 'status' => 'active', 'latitude' => '2.77', 'longitude' => '']);
check('Latitude without longitude refused', str_contains(strip_tags(follow($sa, $r)['body']), 'both latitude and longitude'));
$r = postVia($sa, '/hubs/create', '/hubs', ['name' => 'P3TEST Hub C', 'neighbourhood_id' => $nbId, 'address_note' => 'Near the market', 'latitude' => '2.7746', 'longitude' => '32.2980', 'status' => 'active']);
check('Hub registered with generated code', preg_match('#^/hubs/(\d+)$#', loc($r), $m) === 1 && preg_match('/^HUB-\d{3}$/', (string) $pdo->query('SELECT code FROM collection_hubs WHERE id = ' . (int) $m[1])->fetchColumn()) === 1);
$hubC = (int) ($m[1] ?? 0);
$r = postVia($sa, '/hubs/create', '/hubs', ['name' => 'p3test hub c', 'neighbourhood_id' => $nbId, 'status' => 'active']);
check('Duplicate hub name refused', str_contains(strip_tags(follow($sa, $r)['body']), 'already exists'));
$r = postVia($sa, "/hubs/$hubC", "/hubs/$hubC/assign", ['user_id' => $staff['hoA'], 'hub_role' => 'manager']);
check('Role mismatch on assignment refused', (int) $pdo->query("SELECT COUNT(*) FROM hub_users WHERE hub_id = $hubC")->fetchColumn() === 0);
postVia($sa, "/hubs/$hubC", "/hubs/$hubC/assign", ['user_id' => $staff['hoA'], 'hub_role' => 'operator']);
check('Operator assigned to hub', (int) $pdo->query("SELECT COUNT(*) FROM hub_users WHERE hub_id = $hubC AND user_id = {$staff['hoA']}")->fetchColumn() === 1);
postVia($sa, "/hubs/$hubC", "/hubs/$hubC/unassign", ['user_id' => $staff['hoA']]);
check('Operator removed from hub', (int) $pdo->query("SELECT COUNT(*) FROM hub_users WHERE hub_id = $hubC")->fetchColumn() === 0);
postVia($sa, "/hubs/$hubC/edit", "/hubs/$hubC", ['name' => 'P3TEST Hub C', 'neighbourhood_id' => $nbId, 'status' => 'inactive']);
check('Hub can be deactivated and drops out of picker options', $pdo->query("SELECT status FROM collection_hubs WHERE id = $hubC")->fetchColumn() === 'inactive' && !str_contains(req($sa, 'GET', '/pickers/register')['body'], 'P3TEST Hub C'));

// ---------------------------------------------------------------- hub data scoping
$body = req($hmA, 'GET', '/hubs')['body'];
check('Hub manager sees only their own hub in the list', str_contains($body, 'P3TEST Hub A') && !str_contains($body, 'P3TEST Hub B'));
check('Hub manager gets 404 for another hub by ID', req($hmA, 'GET', "/hubs/$hubB")['status'] === 404 && req($hmA, 'GET', "/hubs/$hubA")['status'] === 200);
check('Super admin sees both hubs', str_contains(req($sa, 'GET', '/hubs')['body'], 'P3TEST Hub B'));

// ---------------------------------------------------------------- picker registration by staff
$form = '/pickers/register';
$data = ['full_name' => 'P3 Staff Picker', 'phone' => '0700088201', 'neighbourhood_id' => $nbId, 'preferred_hub_id' => $hubA, 'national_id' => 'cm12345678abc', 'consent' => '1'];
$r = postVia($hoA, $form, '/pickers', ['preferred_hub_id' => $hubB] + $data);
check('Hub operator cannot register a picker for another hub', str_contains(strip_tags(follow($hoA, $r)['body']), 'only register pickers for your own hub'));
$r = postVia($hoA, $form, '/pickers', ['preferred_hub_id' => ''] + $data);
check('Hub staff must choose a hub', str_contains(strip_tags(follow($hoA, $r)['body']), 'Choose the hub'));
$r = postVia($hoA, $form, '/pickers', ['consent' => ''] + $data);
check('Consent is required', str_contains(strip_tags(follow($hoA, $r)['body']), 'must agree to the data-use agreement'));
$r = postVia($hoA, $form, '/pickers', ['phone' => '12345'] + $data);
check('Invalid phone refused', str_contains(strip_tags(follow($hoA, $r)['body']), 'valid Ugandan phone number'));
$r = postVia($hoA, $form, '/pickers', $data);
check('Hub operator registers a picker', preg_match('#^/pickers/(\d+)$#', loc($r), $m) === 1, 'loc ' . loc($r));
$pickA = (int) ($m[1] ?? 0);
$pg = follow($hoA, $r);
preg_match('#font-monospace user-select-all">([A-Za-z0-9]{8})<#', $pg['body'], $tm3);
$tempPicker = $tm3[1] ?? '';
$prow = $pdo->query("SELECT p.*, u.status, u.must_change_password FROM pickers p JOIN users u ON u.id = p.user_id WHERE p.id = $pickA")->fetch();
check('Picker ID format PLP-######', preg_match('/^PLP-\d{6}$/', (string) $prow['picker_code']) === 1);
check('Staff-registered picker is active, must change password, temp password shown once', $prow['status'] === 'active' && (int) $prow['must_change_password'] === 1 && $tempPicker !== '');
check('Consent version, time and registrar stored', $prow['consent_version'] === PickerService::CONSENT_VERSION && $prow['consented_at'] !== null && (int) $prow['registered_by'] === $staff['hoA']);
check('National ID stored upper-cased', $prow['national_id'] === 'CM12345678ABC');
check('Hub operator cannot see the national ID', !str_contains($pg['body'], 'CM12345678ABC'));
check('Hub manager can see the national ID', str_contains(req($hmA, 'GET', "/pickers/$pickA")['body'], 'CM12345678ABC'));
check('Super admin can see the national ID', str_contains(req($sa, 'GET', "/pickers/$pickA")['body'], 'CM12345678ABC'));
check('National ID never appears in lists', !str_contains(req($sa, 'GET', '/pickers?q=P3')['body'], 'CM12345678ABC'));
$r = postVia($hoA, $form, '/pickers', $data);
check('Duplicate phone refused', str_contains(strip_tags(follow($hoA, $r)['body']), 'already registered'));
$pc = newClient();
check('New picker signs in with a local-format phone number and is forced to change password', loc(login($pc, '0700088201', $tempPicker)) !== '/login' && loc(req($pc, 'GET', '/picker')) === '/account/password');
$r = postVia($pc, '/account/password', '/account/password', ['current_password' => $tempPicker, 'password' => 'abc999', 'password_confirmation' => 'abc999']);
check('Picker may use a 6-character password', loc($r) === '/picker' && req($pc, 'GET', '/picker')['status'] === 200);

// a picker at hub B (made by the super admin through the service)
$resB = PickerService::registerByStaff(['full_name' => 'P3 Other Hub Picker', 'phone' => '0700088202', 'neighbourhood_id' => $nbId, 'preferred_hub_id' => $hubB, 'consent' => '1'], User::findById($staff['sa']) + ['role_slug' => 'super_admin']);
$pickB = (int) ($resB['id'] ?? 0);
check('Super admin can register a picker for any hub', $pickB > 0, json_encode($resB['errors']));
$list = req($hoA, 'GET', '/pickers')['body'];
check('Hub operator list shows only own-hub pickers', str_contains($list, 'P3 Staff Picker') && !str_contains($list, 'P3 Other Hub Picker'));
check('Hub operator gets 404 for a picker at another hub', req($hoA, 'GET', "/pickers/$pickB")['status'] === 404 && req($hmA, 'GET', "/pickers/$pickB")['status'] === 404);
check('Hub B manager can open it', req($hmB, 'GET', "/pickers/$pickB")['status'] === 200);
check('Super admin and project manager see both', str_contains(req($sa, 'GET', '/pickers')['body'], 'P3 Other Hub Picker') && req($pm, 'GET', "/pickers/$pickB")['status'] === 200);
check('Phones: super admin sees full number, others masked', str_contains(req($sa, 'GET', '/pickers?q=P3')['body'], '+256700088201') && !str_contains(req($hoA, 'GET', '/pickers')['body'], '+256700088201') && str_contains(req($hoA, 'GET', '/pickers')['body'], '+256 70* ***201'));

$r = postVia($hoA, $form, '/pickers', ['full_name' => 'P3 Staff Picker', 'phone' => '0700088203'] + $data);
check('Possible duplicate (same name + neighbourhood) triggers a warning', str_contains(strip_tags(follow($hoA, $r)['body']), 'same name already exists'));

$r = postVia($hoA, $form, '/pickers', ['full_name' => '<script>alert(1)</script>', 'phone' => '0700088204', 'national_id' => ''] + $data);
$xssPage = follow($hoA, $r)['body'];
check('User text is escaped (XSS)', str_contains($xssPage, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($xssPage, '<script>alert(1)'));

// ---------------------------------------------------------------- public picker registration + approval
$pub = newClient();
check('Public picker registration page opens', req($pub, 'GET', '/register')['status'] === 200);
$pdata = ['full_name' => 'P3 Public Picker', 'phone' => '0700088301', 'neighbourhood_id' => $nbId, 'preferred_hub_id' => $hubA, 'password' => 'secret77', 'password_confirmation' => 'secret77', 'consent' => '1'];
$r = postVia($pub, '/register', '/register', ['password_confirmation' => 'different'] + $pdata);
check('Password confirmation must match', str_contains(strip_tags(follow($pub, $r)['body']), 'do not match'));
$r = postVia($pub, '/register', '/register', ['password' => '123', 'password_confirmation' => '123'] + $pdata);
check('Too-short picker password refused', str_contains(strip_tags(follow($pub, $r)['body']), 'at least 6'));
$r = postVia($pub, '/register', '/register', ['preferred_hub_id' => '99999'] + $pdata);
check('Invalid hub refused', str_contains(strip_tags(follow($pub, $r)['body']), 'Choose a valid hub'));
$r = postVia($pub, '/register', '/register', $pdata);
check('Public registration succeeds -> /login with message', loc($r) === '/login' && str_contains(follow($pub, $r)['body'], 'registration was received'));
$pu = $pdo->query("SELECT u.id, u.status, p.id AS pid FROM users u JOIN pickers p ON p.user_id = u.id WHERE u.phone_e164 = '+256700088301'")->fetch();
check('Self-registered picker starts as pending (password never stored in plaintext)', $pu && $pu['status'] === 'pending'
    && $pdo->query("SELECT password_hash FROM users WHERE id = {$pu['id']}")->fetchColumn() !== 'secret77');
$c = newClient();
check('Pending picker cannot sign in', str_contains(follow($c, login($c, '0700088301', 'secret77'))['body'], 'waiting for approval'));
check('Pending picker appears in hub A manager queue only', str_contains(req($hmA, 'GET', '/approvals')['body'], 'P3 Public Picker') && !str_contains(req($hmB, 'GET', '/approvals')['body'], 'P3 Public Picker'));
check('Project manager does not get the picker queue', !str_contains(req($pm, 'GET', '/approvals')['body'], 'Waste picker registrations'));
$r = postVia($hmB, '/approvals', "/pickers/{$pu['pid']}/decision", ['decision' => 'approve']);
check('Hub B manager cannot approve a hub A picker', $pdo->query("SELECT status FROM users WHERE id = {$pu['id']}")->fetchColumn() === 'pending' && str_contains(strip_tags(follow($hmB, $r)['body']), 'Picker not found'));
$r = postVia($hmA, '/approvals', "/pickers/{$pu['pid']}/decision", ['decision' => 'approve', 'back' => '//evil.example']);
check('Open redirect via "back" is ignored', !str_contains((string) $r['location'], 'evil'));
check('Hub A manager approves', $pdo->query("SELECT status FROM users WHERE id = {$pu['id']}")->fetchColumn() === 'active');
$c = newClient();
check('Approved picker can sign in', loc(login($c, '0700088301', 'secret77')) === '/picker');
$r = postVia($hmA, '/approvals', "/pickers/{$pu['pid']}/decision", ['decision' => 'approve']);
check('Cannot approve twice', str_contains(strip_tags(follow($hmA, $r)['body']), 'Only pending'));
postVia($hmA, "/pickers/{$pu['pid']}", "/pickers/{$pu['pid']}/decision", ['decision' => 'verify']);
check('Picker can be marked verified', $pdo->query("SELECT verification_status FROM pickers WHERE id = {$pu['pid']}")->fetchColumn() === 'verified');
postVia($hmA, "/pickers/{$pu['pid']}", "/pickers/{$pu['pid']}/decision", ['decision' => 'suspend']);
check('Suspended picker is locked out and reactivation works', loc(req($c, 'GET', '/picker')) === '/login' && (function () use ($hmA, $pu, $pdo) {
    postVia($hmA, "/pickers/{$pu['pid']}", "/pickers/{$pu['pid']}/decision", ['decision' => 'reactivate']);
    return $pdo->query("SELECT status FROM users WHERE id = {$pu['id']}")->fetchColumn() === 'active';
})());

// rejection
$r = postVia(newClient(), '/register', '/register', ['full_name' => 'P3 Rejected Picker', 'phone' => '0700088302'] + $pdata);
$ru = $pdo->query("SELECT u.id, p.id AS pid FROM users u JOIN pickers p ON p.user_id = u.id WHERE u.phone_e164 = '+256700088302'")->fetch();
$r = postVia($hmA, '/approvals', "/pickers/{$ru['pid']}/decision", ['decision' => 'reject', 'reason' => '']);
check('Rejection requires a reason', str_contains(strip_tags(follow($hmA, $r)['body']), 'reason'));
postVia($hmA, '/approvals', "/pickers/{$ru['pid']}/decision", ['decision' => 'reject', 'reason' => 'Not a Gulu resident']);
check('Rejection stores the reason and blocks sign-in', $pdo->query("SELECT review_note FROM pickers WHERE id = {$ru['pid']}")->fetchColumn() === 'Not a Gulu resident'
    && str_contains(follow($c = newClient(), login($c, '0700088302', 'secret77'))['body'], 'suspended'));

// registration throttle (5 per hour per IP)
$made = 0; $blocked = false;
for ($i = 0; $i < 8; $i++) {
    $t = newClient();
    $r = postVia($t, '/register', '/register', ['full_name' => "P3 Throttle $i", 'phone' => '07000885' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)] + $pdata);
    $msg = follow($t, $r)['body'];
    if (str_contains($msg, 'Too many registrations')) { $blocked = true; break; }
    $made++;
}
check('Public registration is throttled per IP (max 5/hour)', $blocked && $made <= 5, "made=$made");

// ---------------------------------------------------------------- recyclers
$rec = ['business_name' => 'P3TEST Plastics Ltd', 'registration_number' => 'URSB-123', 'contact_person' => 'P3 Contact', 'email' => 'phase3test.recycler@example.test',
        'phone' => '0700088401', 'address' => 'Plot 1, Gulu', 'materials' => 'PET bottles', 'password' => 'Recycle-Pass-1', 'password_confirmation' => 'Recycle-Pass-1', 'consent' => '1'];
$pubR = newClient();
check('Public recycler registration page opens', req($pubR, 'GET', '/register/recycler')['status'] === 200);
// the throttle above may already apply to this IP: clear this test's registration audit rows
$pdo->exec("DELETE FROM audit_logs WHERE action IN ('picker.self_registered','recycler.self_registered') AND user_id IN (SELECT id FROM users WHERE phone_e164 LIKE '+25670008850%')");
$r = postVia($pubR, '/register/recycler', '/register/recycler', ['email' => 'nope', 'business_name' => ''] + $rec);
$t = strip_tags(follow($pubR, $r)['body']);
check('Recycler form validation', str_contains($t, 'Business name is required.') && str_contains($t, 'valid email'));
$r = postVia($pubR, '/register/recycler', '/register/recycler', $rec);
check('Recycler registration succeeds as pending', loc($r) === '/login' && $pdo->query("SELECT r.status FROM recyclers r JOIN users u ON u.id = r.user_id WHERE u.email = 'phase3test.recycler@example.test'")->fetchColumn() === 'pending');
$rid = (int) $pdo->query("SELECT r.id FROM recyclers r JOIN users u ON u.id = r.user_id WHERE u.email = 'phase3test.recycler@example.test'")->fetchColumn();
$c = newClient();
check('Pending recycler cannot sign in', str_contains(follow($c, login($c, 'phase3test.recycler@example.test', 'Recycle-Pass-1'))['body'], 'waiting for approval'));
check('Project manager sees the recycler in the queue', str_contains(req($pm, 'GET', '/approvals')['body'], 'P3TEST Plastics Ltd'));
check('Hub manager does not see recyclers in the queue', !str_contains(req($hmA, 'GET', '/approvals')['body'], 'P3TEST Plastics Ltd'));
check('Hub manager cannot open recycler details', req($hmA, 'GET', "/recyclers/$rid")['status'] === 403);
$r = postVia($pm, '/approvals', "/recyclers/$rid/decision", ['decision' => 'reject', 'reason' => '']);
check('Recycler rejection requires a reason', str_contains(strip_tags(follow($pm, $r)['body']), 'reason'));
postVia($pm, '/approvals', "/recyclers/$rid/decision", ['decision' => 'reject', 'reason' => 'Documents missing']);
check('Recycler rejected', $pdo->query("SELECT status FROM recyclers WHERE id = $rid")->fetchColumn() === 'rejected');
$c = newClient();
check('Rejected recycler cannot sign in', str_contains(follow($c, login($c, 'phase3test.recycler@example.test', 'Recycle-Pass-1'))['body'], 'suspended'));
postVia($pm, "/recyclers/$rid", "/recyclers/$rid/decision", ['decision' => 'approve']);
check('Recycler approved (after earlier rejection)', $pdo->query("SELECT status FROM recyclers WHERE id = $rid")->fetchColumn() === 'approved');
$c = newClient();
check('Approved recycler signs in to /recycler', loc(login($c, 'phase3test.recycler@example.test', 'Recycle-Pass-1')) === '/recycler');
postVia($pm, "/recyclers/$rid", "/recyclers/$rid/decision", ['decision' => 'suspend']);
check('Suspended recycler is locked out', loc(req($c, 'GET', '/recycler')) === '/login');
postVia($pm, "/recyclers/$rid", "/recyclers/$rid/decision", ['decision' => 'reactivate']);
check('Reactivated recycler works again', $pdo->query("SELECT status FROM recyclers WHERE id = $rid")->fetchColumn() === 'approved' && loc(login(newClient(), 'phase3test.recycler@example.test', 'Recycle-Pass-1')) === '/recycler');
$r = postVia($pm, "/recyclers/$rid", "/recyclers/$rid/decision", ['decision' => 'approve']);
check('Cannot approve an already approved recycler', str_contains(strip_tags(follow($pm, $r)['body']), 'cannot be'));

$r = postVia($pm, '/recyclers/create', '/recyclers', $rec);   // same email and phone as the recycler registered above
$t = strip_tags(follow($pm, $r)['body']);
check('Duplicate email and phone refused on staff-registered recycler', str_contains($t, 'email address is already registered') && str_contains($t, 'phone number is already registered'));
$r = postVia($pm, '/recyclers/create', '/recyclers', ['business_name' => 'P3TEST Staff Recycler', 'email' => 'phase3test.staffrec@example.test', 'phone' => '0700088402', 'contact_person' => 'P3 Staff Contact', 'address' => 'Gulu', 'consent' => '1']);
check('Staff register an approved recycler with a one-time temporary password', preg_match('#^/recyclers/(\d+)$#', loc($r), $m) === 1
    && preg_match('#font-monospace user-select-all">([A-Za-z0-9]{10})<#', follow($pm, $r)['body']) === 1
    && $pdo->query('SELECT status FROM recyclers WHERE id = ' . (int) $m[1])->fetchColumn() === 'approved');

// ---------------------------------------------------------------- audit trail
foreach (['user.created', 'user.updated', 'user.status_changed', 'user.password_reset_by_staff', 'auth.password_changed', 'neighbourhood.created', 'hub.created', 'hub.user_assigned', 'hub.user_removed',
          'picker.registered', 'picker.self_registered', 'picker.approve', 'picker.reject', 'picker.verify', 'picker.suspend', 'picker.reactivate',
          'recycler.self_registered', 'recycler.approve', 'recycler.reject', 'recycler.suspend', 'recycler.registered', 'auth.access_denied'] as $action) {
    check("Audit logged: $action", (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = '$action'")->fetchColumn() >= 1);
}
check('Audit trail holds no passwords or temp passwords', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE details LIKE '%$temp%' OR details LIKE '%Recycle-Pass%' OR details LIKE '%secret77%'")->fetchColumn() === 0);

// ---------------------------------------------------------------- navigation reflects permissions
$nav = req($hoA, 'GET', '/pickers')['body'];
check('Hub operator sidebar hides links they cannot open', !str_contains($nav, '>Users<') && !str_contains($nav, 'Neighbourhoods') && str_contains($nav, 'Waste pickers'));

// ---------------------------------------------------------------- teardown
$cleanup();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
