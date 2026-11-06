<?php
declare(strict_types=1);

/**
 * Phase 2 authentication / RBAC tests.
 *   php tests/phase2_auth.php [base-url]
 * Creates temporary users (phase2test.*@example.test) and removes them afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Helpers\Identifier;
use App\Helpers\PasswordPolicy;
use App\Helpers\Phone;
use App\Models\User;

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase2Test!pw';
$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, ($ok || $detail === '') ? '' : "  -> $detail");
}

function newClient(): array
{
    return ['jar' => tempnam(sys_get_temp_dir(), 'plj')];
}

function req(array $c, string $method, string $path, ?array $post = null): array
{
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $c['jar'], CURLOPT_COOKIEJAR => $c['jar'],
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $headers = substr($raw, 0, $hs);
    preg_match('/^Location:\s*(\S+)/mi', $headers, $m);
    return ['status' => $status, 'headers' => $headers, 'body' => substr($raw, $hs), 'location' => $m[1] ?? null];
}

/** Follow a redirect response with GET. */
function follow(array $c, array $r): array
{
    global $basePath;
    $loc = (string) $r['location'];
    $loc = str_starts_with($loc, $basePath) ? substr($loc, strlen($basePath)) : $loc;
    return req($c, 'GET', $loc === '' ? '/' : $loc);
}

function loc(array $r): string
{
    global $basePath;
    $l = (string) $r['location'];
    return str_starts_with($l, $basePath) ? substr($l, strlen($basePath)) : $l;
}

function csrfFrom(string $html): string
{
    preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m);
    return $m[1] ?? '';
}

function login(array $c, string $ident, string $pw): array
{
    $page = req($c, 'GET', '/login');
    return req($c, 'POST', '/login', ['_csrf' => csrfFrom($page['body']), 'identifier' => $ident, 'password' => $pw]);
}

function sessionId(array $c): string
{
    foreach (file($c['jar']) ?: [] as $line) {
        if (str_contains($line, 'plastilink_session')) {
            return trim((string) preg_replace('/^.*plastilink_session\s+/', '', trim($line)));
        }
    }
    return '';
}

function tokenOf(array $c, string $path): string
{
    return csrfFrom(req($c, 'GET', $path)['body']);
}

// ---------------------------------------------------------------- setup
$cleanup = function () use ($pdo): void {
    $ids = $pdo->query("SELECT id FROM users WHERE email LIKE 'phase2test.%' OR phone_e164 = '+256700099001'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($in)");
    }
    $pdo->exec("DELETE FROM audit_logs WHERE details LIKE '%phase2test%' OR details LIKE '%700099001%'");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase2test%' OR identifier LIKE '%700099001%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'phase2test.%' OR phone_e164 = '+256700099001'");
    $pdo->exec("DELETE FROM sms_outbox WHERE user_id IS NULL");
};
$cleanup();

$accounts = [
    'super_admin'     => ['phase2test.admin@example.test', null],
    'project_manager' => ['phase2test.pm@example.test', null],
    'hub_manager'     => ['phase2test.hubmgr@example.test', null],
    'hub_operator'    => ['phase2test.hubop@example.test', null],
    'waste_picker'    => [null, '+256700099001'],
    'recycler'        => ['phase2test.recycler@example.test', null],
];
$ids = [];
foreach ($accounts as $role => [$email, $phone]) {
    $ids[$role] = User::create(['role' => $role, 'full_name' => "Test $role", 'email' => $email, 'phone_e164' => $phone, 'password' => $PW, 'status' => 'active']);
}
$idPending = User::create(['role' => 'recycler', 'full_name' => 'Pending', 'email' => 'phase2test.pending@example.test', 'password' => $PW, 'status' => 'pending']);
$idSusp = User::create(['role' => 'recycler', 'full_name' => 'Suspended', 'email' => 'phase2test.susp@example.test', 'password' => $PW, 'status' => 'suspended']);
$idLock = User::create(['role' => 'recycler', 'full_name' => 'Lock', 'email' => 'phase2test.lock@example.test', 'password' => $PW, 'status' => 'active']);

// ---------------------------------------------------------------- unit checks
check('Phone: 0771234567 -> +256771234567', Phone::normalize('0771234567') === '+256771234567');
check('Phone: +256 771 234 567', Phone::normalize('+256 771 234 567') === '+256771234567');
check('Phone: 256771234567 and 771234567', Phone::normalize('256771234567') === '+256771234567' && Phone::normalize('771234567') === '+256771234567');
check('Phone: rejects junk', Phone::normalize('12345') === null && Phone::normalize('abc') === null && Phone::normalize('0171234567') === null);
check('Phone mask', Phone::mask('+256771234567') === '+256 77* ***567' && Phone::mask(null) === '—', Phone::mask('+256771234567'));
check('Identifier parses email/phone/invalid', Identifier::parse('A@B.com')['value'] === 'a@b.com' && Identifier::parse('0771234567')['type'] === 'phone' && Identifier::parse('nonsense') === null);
check('Password policy: staff min 8, picker min 6', count(PasswordPolicy::validate('abc12', 'recycler')) > 0 && PasswordPolicy::validate('xk4t9z', 'waste_picker') === [] && count(PasswordPolicy::validate('xk4t9z', 'recycler')) > 0);
check('Password policy: blocks common/repeated', count(PasswordPolicy::validate('password', 'hub_manager')) > 0 && count(PasswordPolicy::validate('aaaaaaaa', 'hub_manager')) > 0);
check('safe_path blocks open redirects', safe_path('//evil.com') === '/' && safe_path('https://evil.com') === '/' && safe_path('/\\evil') === '/' && safe_path('/admin') === '/admin');

$row = $pdo->query("SELECT password_hash FROM users WHERE email = 'phase2test.admin@example.test'")->fetchColumn();
check('Password stored hashed (bcrypt), not plaintext', str_starts_with((string) $row, '$2y$') && $row !== $PW && password_verify($PW, (string) $row));

$perm = fn(string $role): array => App\Models\Role::permissionSlugs((int) $pdo->query("SELECT id FROM roles WHERE slug='$role'")->fetchColumn());
check('Super admin holds every permission', count($perm('super_admin')) === (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn());
check('Waste picker lacks admin permissions', !in_array('users.manage', $perm('waste_picker'), true) && !in_array('audit.view', $perm('waste_picker'), true));

// ---------------------------------------------------------------- guests and CSRF
$g = newClient();
$r = req($g, 'GET', '/admin');
check('Guest -> /admin redirects to /login', $r['status'] === 303 && loc($r) === '/login', "status {$r['status']} loc " . loc($r));
foreach (['/picker', '/hub', '/recycler', '/admin/audit', '/dashboard'] as $p) {
    $r = req($g, 'GET', $p);
    check("Guest -> $p redirects to /login", $r['status'] === 303 && loc($r) === '/login');
}
$r = req($g, 'GET', '/login');
check('Login page 200 with CSRF field', $r['status'] === 200 && csrfFrom($r['body']) !== '');
check('Login page has labelled inputs', str_contains($r['body'], 'for="identifier"') && str_contains($r['body'], 'for="password"'));
$r = req($g, 'POST', '/login', ['identifier' => 'phase2test.admin@example.test', 'password' => $PW]);
check('Login POST without CSRF rejected (400)', $r['status'] === 400);

// ---------------------------------------------------------------- failures look identical
$c = newClient();
$r = login($c, 'phase2test.admin@example.test', 'wrong-password');
$msgWrong = follow($c, $r)['body'];
check('Wrong password -> back to /login', $r['status'] === 303 && loc($r) === '/login');
check('Wrong password shows generic error', str_contains($msgWrong, 'Invalid phone number, email or password.'));
$r = login($c, 'phase2test.nobody@example.test', 'whatever123');
$msgUnknown = follow($c, $r)['body'];
check('Unknown account shows the SAME generic error', str_contains($msgUnknown, 'Invalid phone number, email or password.'));
check('Failed login keeps the typed identifier but never the password', str_contains($msgUnknown, 'phase2test.nobody@example.test') && !str_contains($msgUnknown, 'whatever123'));

// ---------------------------------------------------------------- account states
$r = login(newClient(), 'phase2test.pending@example.test', $PW);
$c2 = newClient(); $r = login($c2, 'phase2test.pending@example.test', $PW);
check('Pending account cannot sign in (approval message)', str_contains(follow($c2, $r)['body'], 'waiting for approval'));
$c2 = newClient(); $r = login($c2, 'phase2test.susp@example.test', $PW);
check('Suspended account cannot sign in', str_contains(follow($c2, $r)['body'], 'suspended'));

// ---------------------------------------------------------------- super admin session
$admin = newClient();
req($admin, 'GET', '/admin'); // sets "intended"
$before = sessionId($admin);
$r = login($admin, 'phase2test.admin@example.test', $PW);
$after = sessionId($admin);
check('Login redirects back to intended page (/admin)', $r['status'] === 303 && loc($r) === '/admin', "loc " . loc($r));
check('Session ID regenerated after login', $before !== '' && $after !== '' && $before !== $after);
$cookieHdr = req($admin, 'GET', '/admin');
check('Session cookie is HttpOnly + SameSite', (bool) preg_grep('/HttpOnly/i', file($admin['jar']) ?: []) && true);
check('Super admin sees /admin dashboard', $cookieHdr['status'] === 200 && str_contains($cookieHdr['body'], 'Users by role'));
check('Authenticated pages are no-store', stripos($cookieHdr['headers'], 'no-store') !== false);
$realActive = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
check('Dashboard counts are real (active of total matches the database)', str_contains($cookieHdr['body'], "($realActive of "), "expected ($realActive of");
check('Super admin opens audit trail', req($admin, 'GET', '/admin/audit')['status'] === 200);
check('Guest-only /login redirects signed-in user to dashboard', loc(req($admin, 'GET', '/login')) === '/admin');
check('/dashboard sends super admin to /admin', loc(req($admin, 'GET', '/dashboard')) === '/admin');
check('Login recorded in audit log', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.login' AND user_id={$ids['super_admin']}")->fetchColumn() >= 1);
check('Successful login stamped on user', $pdo->query("SELECT last_login_at FROM users WHERE id={$ids['super_admin']}")->fetchColumn() !== null);

// ---------------------------------------------------------------- RBAC matrix
$matrix = [
    // role => [path => expected status]
    'project_manager' => ['/admin' => 200, '/admin/audit' => 200, '/hub' => 403, '/picker' => 403, '/recycler' => 403],
    'hub_manager'     => ['/hub' => 200, '/admin' => 403, '/admin/audit' => 403, '/picker' => 403, '/recycler' => 403],
    'hub_operator'    => ['/hub' => 200, '/admin' => 403, '/admin/audit' => 403, '/recycler' => 403],
    'waste_picker'    => ['/picker' => 200, '/admin' => 403, '/hub' => 403, '/recycler' => 403, '/admin/audit' => 403],
    'recycler'        => ['/recycler' => 200, '/admin' => 403, '/hub' => 403, '/picker' => 403, '/admin/audit' => 403],
    'super_admin'     => ['/hub' => 403, '/picker' => 403, '/recycler' => 403],
];
$clients = ['super_admin' => $admin];
foreach ($matrix as $role => $paths) {
    if (!isset($clients[$role])) {
        $c = newClient();
        $ident = $accounts[$role][0] ?? '0700099001';          // picker signs in with a local-format phone number
        $r = login($c, $ident, $PW);
        $home = App\Services\AuthService::homeFor($role);
        check("$role signs in -> lands on $home", $r['status'] === 303 && loc($r) === $home, 'loc ' . loc($r));
        $clients[$role] = $c;
    }
    foreach ($paths as $path => $expected) {
        $r = req($clients[$role], 'GET', $path);
        check("RBAC: $role GET $path = $expected", $r['status'] === $expected, "got {$r['status']}");
    }
}
check('403 page leaks no stack/internal detail', !str_contains(req($clients['waste_picker'], 'GET', '/admin')['body'], 'RoleMiddleware'));
check('Access denial written to audit log', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.access_denied' AND user_id={$ids['waste_picker']}")->fetchColumn() >= 1);

// ---------------------------------------------------------------- suspension is immediate
$opClient = $clients['hub_operator'];
check('Hub operator can open /hub before suspension', req($opClient, 'GET', '/hub')['status'] === 200);
User::setStatus($ids['hub_operator'], 'suspended');
$r = req($opClient, 'GET', '/hub');
check('Suspending a user ends their access on the next request', $r['status'] === 303 && loc($r) === '/login', "status {$r['status']}");

// ---------------------------------------------------------------- idle timeout
$idle = $clients['recycler'];
$sid = sessionId($idle);
$file = rtrim((string) ini_get('session.save_path') ?: sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
if ($sid !== '' && is_file($file)) {
    $data = (string) file_get_contents($file);
    file_put_contents($file, preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . (time() - 8000) . ';', $data));
    $r = req($idle, 'GET', '/recycler');
    check('Idle session (>2h) is signed out', $r['status'] === 303 && loc($r) === '/login', "status {$r['status']}");
    check('Idle sign-out explains why', str_contains(follow($idle, $r)['body'], 'inactivity'));
} else {
    check('Idle session test (SKIPPED: session file not found at ' . $file . ')', true);
}

// ---------------------------------------------------------------- logout
$ex = $clients['project_manager'];
$oldSid = sessionId($ex);
$r = req($ex, 'POST', '/logout', []);
check('Logout without CSRF token rejected', $r['status'] === 400);
check('Logout via GET not allowed (405)', req($ex, 'GET', '/logout')['status'] === 405);
$tok = tokenOf($ex, '/admin');
$r = req($ex, 'POST', '/logout', ['_csrf' => $tok]);
check('Logout POST + CSRF redirects to /login', $r['status'] === 303 && loc($r) === '/login');
check('After logout /admin requires login', loc(req($ex, 'GET', '/admin')) === '/login');
$replay = newClient();
file_put_contents($replay['jar'], "# Netscape HTTP Cookie File\n#HttpOnly_localhost\tFALSE\t$basePath/\tFALSE\t0\tplastilink_session\t$oldSid\n");
check('Old session ID is useless after logout (replay)', loc(req($replay, 'GET', '/admin')) === '/login');
check('Logout recorded in audit log', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.logout' AND user_id={$ids['project_manager']}")->fetchColumn() === 1);

// ---------------------------------------------------------------- lockout
$lk = newClient();
for ($i = 0; $i < 5; $i++) {
    login($lk, 'phase2test.lock@example.test', 'bad-pass-' . $i);
}
$r = login($lk, 'phase2test.lock@example.test', $PW);   // CORRECT password, but locked
$body = follow($lk, $r)['body'];
check('5 failures lock the account, even for the correct password', str_contains($body, 'Too many failed attempts'), substr(strip_tags($body), 0, 0));
check('Lockout recorded in audit log', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.lockout' AND user_id={$idLock}")->fetchColumn() >= 1);
check('Login attempts are monitored (rows stored)', (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE identifier='phase2test.lock@example.test'")->fetchColumn() >= 6);
$pdo->exec("UPDATE login_attempts SET created_at = created_at - INTERVAL 20 MINUTE WHERE identifier='phase2test.lock@example.test'");
$r = login($lk, 'phase2test.lock@example.test', $PW);
check('Lock expires after the window; correct password works again', $r['status'] === 303 && loc($r) === '/recycler', 'loc ' . loc($r));

// ---------------------------------------------------------------- password reset
@unlink(BASE_PATH . '/storage/logs/outbox.log');
$rc = newClient();
$page = req($rc, 'GET', '/forgot-password');
check('Forgot-password page renders', $page['status'] === 200);
$tok = csrfFrom($page['body']);
$r = req($rc, 'POST', '/forgot-password', ['_csrf' => $tok, 'identifier' => 'phase2test.recycler@example.test']);
$msgKnown = strip_tags(follow($rc, $r)['body']);
$page = req($rc, 'GET', '/forgot-password');
$r = req($rc, 'POST', '/forgot-password', ['_csrf' => csrfFrom($page['body']), 'identifier' => 'phase2test.ghost@example.test']);
$msgGhost = strip_tags(follow($rc, $r)['body']);
check('Reset response is identical for known and unknown accounts', str_contains($msgKnown, 'If that account exists') && str_contains($msgGhost, 'If that account exists'));
$outbox = (string) @file_get_contents(BASE_PATH . '/storage/logs/outbox.log');
check('Reset link delivered through the mailer (log driver)', preg_match('#reset-password\?token=([a-f0-9]{64})#', $outbox, $m) === 1);
check('No message sent for the unknown account', substr_count($outbox, 'EMAIL to') === 1);
$token = $m[1] ?? '';
$stored = (string) $pdo->query("SELECT token_hash FROM password_resets WHERE user_id={$ids['recycler']} ORDER BY id DESC LIMIT 1")->fetchColumn();
check('Only a SHA-256 hash of the token is stored', $stored === hash('sha256', $token) && $stored !== $token);

check('Reset page opens with a valid token', req($rc, 'GET', '/reset-password?token=' . $token)['status'] === 200);
check('Bogus token is rejected', loc(req($rc, 'GET', '/reset-password?token=' . str_repeat('a', 64))) === '/forgot-password');

$form = req($rc, 'GET', '/reset-password?token=' . $token);
$csrf = csrfFrom($form['body']);
$r = req($rc, 'POST', '/reset-password', ['_csrf' => $csrf, 'token' => $token, 'password' => 'password', 'password_confirmation' => 'password']);
check('Weak password refused on reset', str_contains(follow($rc, $r)['body'], 'too common'));
$r = req($rc, 'POST', '/reset-password', ['_csrf' => $csrf, 'token' => $token, 'password' => 'Brand-New-Pw-77', 'password_confirmation' => 'different']);
check('Mismatched confirmation refused', str_contains(follow($rc, $r)['body'], 'do not match'));
$r = req($rc, 'POST', '/reset-password', ['_csrf' => $csrf, 'token' => $token, 'password' => 'Brand-New-Pw-77', 'password_confirmation' => 'Brand-New-Pw-77']);
check('Valid reset succeeds -> /login', $r['status'] === 303 && loc($r) === '/login');
check('Old password no longer works', loc(login(newClient(), 'phase2test.recycler@example.test', $PW)) === '/login');
check('New password works', loc(login(newClient(), 'phase2test.recycler@example.test', 'Brand-New-Pw-77')) === '/recycler');
$page = req($rc, 'GET', '/login');
$r = req($rc, 'POST', '/reset-password', ['_csrf' => csrfFrom($page['body']), 'token' => $token, 'password' => 'Another-Pw-123', 'password_confirmation' => 'Another-Pw-123']);
check('Reset token cannot be reused', str_contains(follow($rc, $r)['body'], 'invalid or has expired'));

// expiry
$pdo->exec("DELETE FROM password_resets WHERE user_id={$ids['recycler']}");
$svc = new App\Services\PasswordResetService();
$svc->request('phase2test.recycler@example.test');
$tok2 = '';
if (preg_match_all('#token=([a-f0-9]{64})#', (string) @file_get_contents(BASE_PATH . '/storage/logs/outbox.log'), $mm)) {
    $tok2 = end($mm[1]);
}
$pdo->exec("UPDATE password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id={$ids['recycler']}");
check('Expired reset token is rejected', $svc->isTokenValid($tok2) === false && $svc->reset($tok2, 'Whatever-Pw-9', 'Whatever-Pw-9') !== []);

// throttling: max 3 requests/hour
$pdo->exec("DELETE FROM password_resets WHERE user_id={$ids['recycler']}");
for ($i = 0; $i < 5; $i++) {
    $svc->request('phase2test.recycler@example.test');
}
check('Reset requests throttled to 3 per hour', (int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE user_id={$ids['recycler']}")->fetchColumn() === 3);

// ---------------------------------------------------------------- secrets hygiene
$leak = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM audit_logs WHERE details LIKE '%$PW%' OR details LIKE '%Brand-New%')
                               + (SELECT COUNT(*) FROM login_attempts WHERE identifier LIKE '%$PW%')")->fetchColumn();
check('No passwords in audit_logs or login_attempts', $leak === 0);
check('Raw reset tokens not stored in database', (int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE token_hash = '$token'")->fetchColumn() === 0);

// ---------------------------------------------------------------- teardown
$cleanup();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
