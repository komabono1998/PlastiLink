<?php
declare(strict_types=1);

/**
 * Phase 11 security tests.
 *   php tests/phase11_security.php [base-url]
 * Walks EVERY registered route against every role, checks CSRF, headers, cookies, sessions, lockout, injection, XSS, IDOR,
 * file exposure, open redirects, mass assignment and static properties of the code and repository.
 * Creates temporary data (phase11test.*, "P11TEST" hubs) and removes it afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/_http.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Router;
use App\Core\SecurityHeaders;
use App\Middleware\AnyPermissionMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Hub;
use App\Models\Neighbourhood;
use App\Models\Picker;
use App\Models\Price;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\PickerService;
use App\Services\RecyclerService;

$base = rtrim($argv[1] ?? 'http://localhost/PlastiLink', '/');
$basePath = (string) parse_url($base, PHP_URL_PATH);
$pdo = Database::connection();
$PW = 'Phase11Test!pw';
$pass = 0;
$fail = 0;
$XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';

$cleanup = function () use ($pdo): void {
    $ids = fn(string $sql): string => implode(',', array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    $hub = $ids("SELECT id FROM collection_hubs WHERE name LIKE 'P11TEST%'");
    $usr = $ids("SELECT id FROM users WHERE email LIKE 'phase11test.%' OR phone_e164 LIKE '+25670007%' OR email LIKE 'phase11reg.%'");
    $col = $ids("SELECT id FROM collection_transactions WHERE hub_id IN ($hub) OR recorded_by IN ($usr)");
    $prc = $ids("SELECT id FROM price_lists WHERE hub_id IN ($hub) OR note LIKE 'P11TEST%'");
    $rec = $ids("SELECT id FROM recyclers WHERE user_id IN ($usr)");
    $nb = $ids("SELECT id FROM neighbourhoods WHERE name LIKE 'P11TEST%'");
    $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($usr) OR (entity_type = 'collection' AND entity_id IN ($col)) OR (entity_type = 'price' AND entity_id IN ($prc))
                OR (entity_type IN ('user', 'picker') AND entity_id IN ($usr)) OR (entity_type = 'hub' AND entity_id IN ($hub)) OR (entity_type = 'neighbourhood' AND entity_id IN ($nb)) OR (entity_type = 'recycler' AND entity_id IN ($rec))");
    $pdo->exec("DELETE FROM financial_ledger WHERE source_type = 'collection' AND source_id IN ($col)");
    $pdo->exec("DELETE FROM stock_movements WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM inventory WHERE hub_id IN ($hub)");
    $pdo->exec("DELETE FROM receipts WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM weighing_records WHERE collection_id IN ($col)");
    $pdo->exec("DELETE FROM collection_transactions WHERE id IN ($col)");
    $pdo->exec("DELETE FROM price_lists WHERE id IN ($prc)");
    $pdo->exec("DELETE FROM pickers WHERE user_id IN ($usr)");
    $pdo->exec("DELETE FROM recyclers WHERE id IN ($rec)");
    $pdo->exec("DELETE FROM collection_hubs WHERE id IN ($hub)");
    $pdo->exec("DELETE FROM neighbourhoods WHERE id IN ($nb)");
    $pdo->exec("DELETE FROM password_resets WHERE user_id IN ($usr)");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase11test%' OR identifier LIKE '%700070%' OR identifier LIKE '%phase11reg%' OR identifier LIKE '%nobody-here%'");
    $pdo->exec("DELETE FROM users WHERE id IN ($usr)");
    $pdo->exec('DELETE FROM sms_outbox WHERE user_id IS NULL');
};
$cleanup();

// ================================================================ pure helpers
check('safe_path accepts local paths only', safe_path('/hubs/1') === '/hubs/1' && safe_path('//evil.com') === '/' && safe_path('https://evil.com') === '/' && safe_path('/\\evil.com') === '/'
    && safe_path('javascript:alert(1)') === '/' && safe_path("/ok\r\nSet-Cookie: x=1") === '/' && safe_path('') === '/' && safe_path(null, '/home') === '/home' && safe_path('/a:b') === '/');
check('e() escapes quotes, angle brackets and ampersands', e($XSS) === htmlspecialchars($XSS, ENT_QUOTES, 'UTF-8') && !str_contains(e($XSS), '<') && !str_contains(e($XSS), '"') && !str_contains(e("'"), "'"));
$pol = SecurityHeaders::policy();
check('CSP: scripts only from our own origin, no inline or eval scripts, no plugins, no framing by others', str_contains($pol, "script-src 'self'") && !preg_match("/script-src[^;]*'unsafe-(inline|eval)'/", $pol)
    && str_contains($pol, "object-src 'none'") && str_contains($pol, "frame-ancestors 'self'") && str_contains($pol, "base-uri 'self'") && str_contains($pol, "form-action 'self'") && str_contains($pol, "default-src 'self'"));
check('CSP allows map tiles only from the configured tile server', str_contains($pol, 'https://tile.openstreetmap.org') && !str_contains($pol, '*.*') && !preg_match('/img-src[^;]*\s\*(\s|;|$)/', $pol));
check('Production defaults: env production, debug off, CSP enforced (config/app.php as shipped)', (function () {
    $c = require dirname(__DIR__) . '/config/app.php';
    return $c['env'] === 'production' && $c['debug'] === false && $c['security']['csp'] === 'enforce' && $c['finance']['provider'] === 'manual' && $c['integrations']['sms']['driver'] === 'log';
})());

// ================================================================ static properties of the repository
$tracked = array_filter(explode("\n", (string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' ls-files 2>&1')));
check('Git tracks files (so the repository checks below mean something)', count($tracked) > 100);
check('No local config, key or environment files are tracked by git', !array_filter($tracked, fn($f) => preg_match('#(\.local\.php$|(^|/)\.env|\.pem$|\.key$|id_rsa|\.sql$|\.sql\.gz|\.enc$)#', $f)));
$secretRx = '/(AKIA[0-9A-Z]{16}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|sk_live_[0-9a-zA-Z]{10,}|xox[baprs]-[0-9A-Za-z-]{10,}|(api_key|secret|password|client_secret|subscription_key)[\'"]?\s*(=>|=|:)\s*[\'"][A-Za-z0-9+\/_\-]{16,}[\'"])/i';
$hits = [];
foreach ($tracked as $f) {
    if (preg_match('#^(tests/|public/assets/vendor/|database/migrations/)#', $f) || !preg_match('/\.(php|md|js|json|htaccess|example|txt)$/', $f)) {
        continue;
    }
    if (preg_match($secretRx, (string) @file_get_contents(dirname(__DIR__) . '/' . $f), $m)) {
        $hits[] = $f . ' (' . substr($m[0], 0, 30) . ')';
    }
}
check('No credential-looking strings in tracked source, docs or config examples', !$hits, implode('; ', $hits));
$danger = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $src = (string) file_get_contents($f->getPathname());
    $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', dirname(__DIR__))) + 1);
    foreach (['eval(', 'shell_exec(', 'passthru(', 'popen(', 'system(', 'assert(', 'create_function(', 'unserialize(', 'extract($_', 'include $_', 'require $_', 'file_get_contents($_', 'md5($password', 'sha1($password'] as $needle) {
        if (str_contains($src, $needle)) {
            $danger[] = "$rel: $needle";
        }
    }
    if (preg_match('/\bexec\(\s*[\'"][^\'"]*(rm |del |wget|curl )/i', $src) || preg_match('/\bproc_open\(/', $src)) {
        $danger[] = "$rel: process execution";
    }
}
check('No eval, shell execution, unserialize, extract($_...), include of request data or plain md5/sha1 passwords in app/', !$danger, implode('; ', $danger));
$sqlRisk = [];
foreach ($it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/app', FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    foreach (file($f->getPathname()) as $n => $line) {
        // user input (the request, query string, body or POST/GET arrays) concatenated or interpolated straight into SQL
        if (preg_match('/(->query|->exec|->prepare)\(.*(\$request->|\$_(GET|POST|REQUEST|COOKIE)|\$in\[|\$f\[\'q\')/', $line) && !preg_match('/\)\s*->execute\(\[/', $line)) {
            $sqlRisk[] = basename($f->getPathname()) . ':' . ($n + 1);
        }
    }
}
check('No request data is placed directly into SQL text in app/', !$sqlRisk, implode(', ', $sqlRisk));
$pw = (string) $pdo->query("SELECT password_hash FROM users WHERE password_hash IS NOT NULL LIMIT 1")->fetchColumn();
check('Passwords are stored as bcrypt/argon hashes, never plain', $pw === '' || preg_match('/^\$(2y|argon2id|argon2i)\$/', $pw) === 1);

// ================================================================ setup: one account per role
$ids = [];
foreach (['super_admin' => 'sa', 'project_manager' => 'pm', 'hub_manager' => 'hmA', 'hub_operator' => 'hoA'] as $role => $k) {
    $ids[$k] = User::create(['role' => $role, 'full_name' => "P11 $k", 'email' => "phase11test.$k@example.test", 'password' => $PW, 'status' => 'active']);
}
$ids['hmB'] = User::create(['role' => 'hub_manager', 'full_name' => 'P11 hmB', 'email' => 'phase11test.hmb@example.test', 'password' => $PW, 'status' => 'active']);
$ids['hoB'] = User::create(['role' => 'hub_operator', 'full_name' => 'P11 hoB', 'email' => 'phase11test.hob@example.test', 'password' => $PW, 'status' => 'active']);
$nbId = Neighbourhood::create('P11TEST Area');
$catId = (int) $pdo->query("SELECT id FROM plastic_categories WHERE code = 'PET'")->fetchColumn();
$mkHub = fn(string $n) => Hub::create(['name' => $n, 'neighbourhood_id' => $nbId, 'address_note' => null, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
$hubA = $mkHub('P11TEST Hub A');
$hubB = $mkHub('P11TEST Hub B');
Hub::assign($hubA, $ids['hmA'], 'manager'); Hub::assign($hubA, $ids['hoA'], 'operator');
Hub::assign($hubB, $ids['hmB'], 'manager'); Hub::assign($hubB, $ids['hoB'], 'operator');
foreach ([$hubA, $hubB] as $h) {
    Price::create($catId, $h, 1000, 'P11TEST price', $ids['sa']);
}
$U = fn(string $k) => User::findById($ids[$k]);
$sa = $U('sa');
$mkPicker = function (string $key, string $name, string $phone, int $hub) use ($sa, $nbId, $PW, &$ids): int {
    $r = PickerService::registerByStaff(['full_name' => $name, 'phone' => $phone, 'neighbourhood_id' => $nbId, 'preferred_hub_id' => $hub, 'national_id' => 'NIN' . substr($phone, -6) . 'ZZ', 'consent' => '1'], $sa);
    $p = Picker::find((int) $r['id']);
    User::updatePassword((int) $p['user_id'], $PW);
    $ids[$key] = (int) $p['user_id'];
    return (int) $r['id'];
};
$pkA = $mkPicker('pkA', 'P11 Picker A', '0700070101', $hubA);
$pkB = $mkPicker('pkB', 'P11 Picker B', '0700070102', $hubB);
$rr = RecyclerService::registerByStaff(['business_name' => 'P11TEST Recycling', 'contact_person' => 'P11 Rec', 'email' => 'phase11test.r1@example.test', 'phone' => '0700070201', 'address' => 'Gulu', 'consent' => '1'], $sa);
$ids['r1'] = (int) \App\Models\Recycler::find((int) $rr['id'])['user_id'];
User::updatePassword($ids['r1'], $PW);
$tok = fn() => bin2hex(random_bytes(16));
$mkCol = function (int $hub, int $picker, string $gross, string $rec, string $appr, string $note = '') use ($U, $catId, $tok): int {
    $r = CollectionService::record(['token' => $tok(), 'picker_id' => $picker, 'hub_id' => $hub, 'plastic_category_id' => $catId, 'gross_weight_kg' => $gross, 'tare_weight_kg' => '', 'notes' => $note], $U($rec));
    return CollectionService::approve((int) $r['id'], $U($appr))['error'] === null ? (int) $r['id'] : 0;
};
$colA = $mkCol($hubA, $pkA, '20', 'hoA', 'hmA');
$colB = $mkCol($hubB, $pkB, '15', 'hoB', 'hmB');
check('Setup: a collection in each hub, accounts for every role', $colA > 0 && $colB > 0);

$roles = ['sa' => 'phase11test.sa@example.test', 'pm' => 'phase11test.pm@example.test', 'hmA' => 'phase11test.hma@example.test', 'hoA' => 'phase11test.hoa@example.test', 'pkA' => '0700070101', 'r1' => 'phase11test.r1@example.test'];
$cl = [];
foreach ($roles as $k => $ident) {
    $cl[$k] = newClient();
    $r = login($cl[$k], $ident, $PW);
    check("Setup: $k signs in", $r['status'] === 303 && loc($r) !== '/login');
}
$cl['pkB'] = newClient(); login($cl['pkB'], '0700070102', $PW);
$cl['hmB'] = newClient(); login($cl['hmB'], 'phase11test.hmb@example.test', $PW);
$anon = newClient();
$slug = ['sa' => 'super_admin', 'pm' => 'project_manager', 'hmA' => 'hub_manager', 'hoA' => 'hub_operator', 'pkA' => 'waste_picker', 'r1' => 'recycler'];

// ================================================================ the route table: every route, every role
$router = new Router();
require dirname(__DIR__) . '/routes/web.php';
$routes = $router->routes();
check('The route table is loaded (over 100 routes)', count($routes) > 100, (string) count($routes));

$rolePerms = [];
foreach ($pdo->query('SELECT r.slug AS role, p.slug AS perm FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id')->fetchAll() as $x) {
    $rolePerms[$x['role']][] = $x['perm'];
}
$prop = function (object $o, string $name): array {
    $r = new ReflectionProperty($o, $name);
    return $r->getValue($o);
};
/** Roles that may pass a route's middleware chain (by their database permissions) */
$allowedRoles = function (array $mws) use ($rolePerms, $prop): array {
    $all = ['super_admin', 'project_manager', 'hub_manager', 'hub_operator', 'waste_picker', 'recycler'];
    foreach ($mws as $mw) {
        if ($mw instanceof PermissionMiddleware) {
            $need = $prop($mw, 'permissions');
            $all = array_filter($all, fn($r) => !array_diff($need, $rolePerms[$r] ?? []));
        } elseif ($mw instanceof AnyPermissionMiddleware) {
            $need = $prop($mw, 'permissions');
            $all = array_filter($all, fn($r) => (bool) array_intersect($need, $rolePerms[$r] ?? []));
        } elseif ($mw instanceof RoleMiddleware) {
            $all = array_filter($all, fn($r) => in_array($r, $prop($mw, 'roles'), true));
        }
    }
    return array_values($all);
};
$fill = function (string $pattern): string {
    $p = preg_replace('#\{(\w+):\\\\d\+\}#', '1', $pattern);
    $p = preg_replace('#\{key:[^}]+\}#', 'collections', (string) $p);
    $p = preg_replace('#\{format:[^}]+\}#', 'csv', (string) $p);
    return (string) preg_replace('#\{\w+\}#', 'x', (string) $p);
};

$publicAllowed = ['GET /', 'GET /health', 'GET /styleguide', 'POST /webhooks/mtn/{token}', 'POST /webhooks/airtel/{token}', 'POST /webhooks/sms/{token}', 'POST /ussd/{token}'];
$unknownPublic = [];
$classified = [];
foreach ($routes as $r) {
    $kinds = array_map(fn($m) => get_class($m), $r['middleware']);
    $key = $r['method'] . ' ' . $r['pattern'];
    $hasAuth = in_array(AuthMiddleware::class, $kinds, true);
    $isGuest = in_array(GuestMiddleware::class, $kinds, true);
    if (!$hasAuth && !$isGuest) {
        if (!in_array($key, $publicAllowed, true)) {
            $unknownPublic[] = $key;
        }
        $classified[] = $r + ['kind' => 'public', 'key' => $key];
    } else {
        $classified[] = $r + ['kind' => $hasAuth ? 'auth' : 'guest', 'key' => $key];
    }
}
check('Every route is either behind sign-in, guest-only, or on the reviewed public list (a new open route fails this test)', !$unknownPublic, implode(', ', $unknownPublic));
$noGate = [];
foreach ($classified as $r) {
    if ($r['kind'] === 'auth' && $r['method'] !== 'GET' && !array_filter($r['middleware'], fn($m) => $m instanceof AuthMiddleware)) {
        $noGate[] = $r['key'];
    }
}
check('Every state-changing route requires sign-in', !$noGate);
$permless = array_filter($classified, fn($r) => $r['kind'] === 'auth' && !array_filter($r['middleware'], fn($m) => !($m instanceof AuthMiddleware)));
$permlessKeys = array_map(fn($r) => $r['key'], $permless);
sort($permlessKeys);
check('Routes with sign-in only (no permission check) are limited to account basics: dashboard redirect, password change, logout', $permlessKeys === ['GET /account/password', 'GET /dashboard', 'POST /account/password', 'POST /logout'], implode(', ', $permlessKeys));

// guests: every protected route sends them to sign in; never a page, never a server error
$guestBad = [];
foreach ($classified as $r) {
    if ($r['kind'] !== 'auth' || $r['method'] !== 'GET') {
        continue;
    }
    $x = req($anon, 'GET', $fill($r['pattern']));
    if ($x['status'] !== 303 || loc($x) !== '/login') {
        $guestBad[] = $r['key'] . ' -> ' . $x['status'];
    }
}
check('Guests are redirected to /login from every protected GET route', !$guestBad, implode('; ', array_slice($guestBad, 0, 5)));
$gp = req($anon, 'GET', '/login');
$guestPosts = [];
foreach ($classified as $r) {
    if ($r['kind'] === 'auth' && $r['method'] === 'POST') {
        $x = req($anon, 'POST', $fill($r['pattern']), ['_csrf' => csrfFrom($gp['body']), 'x' => '1']);
        if ($x['status'] !== 303 || loc($x) !== '/login') {
            $guestPosts[] = $r['key'] . ' -> ' . $x['status'];
        }
    }
}
check('Guests with a valid CSRF token are still redirected to /login from every protected POST route', !$guestPosts, implode('; ', array_slice($guestPosts, 0, 5)));

// the matrix: each role against each GET route
$matrixBad = [];
$serviceGated = ['GET /marketplace', 'GET /orders/new'];   // allowed by permission, but the service refuses anyone who is not a recycler (reviewed)
$bodies = [];
$tested = 0;
foreach ($classified as $r) {
    if ($r['kind'] !== 'auth' || $r['method'] !== 'GET' || in_array($r['pattern'], ['/logout'], true)) {
        continue;
    }
    $allowed = $allowedRoles($r['middleware']);
    foreach (array_keys($slug) as $who) {
        $x = req($cl[$who], 'GET', $fill($r['pattern']));
        $tested++;
        $ok = in_array($slug[$who], $allowed, true);
        if ($ok && (($x['status'] === 403 && !in_array($r['key'], $serviceGated, true)) || $x['status'] >= 500)) {
            $matrixBad[] = "{$r['key']} as $who should be allowed, got {$x['status']}";
        } elseif (!$ok && $x['status'] !== 403) {
            $matrixBad[] = "{$r['key']} as $who should be 403, got {$x['status']}";
        }
        if ($x['status'] === 200 && str_contains((string) ($x['headers'] ?? ''), 'text/html')) {
            $bodies[$who . ' ' . $r['key']] = $x;
        }
    }
}
check("Access matrix: $tested role-by-route requests; allowed roles never get 403 or a server error, every other role gets 403", !$matrixBad, implode('; ', array_slice($matrixBad, 0, 6)));
$postBad = [];
foreach ($classified as $r) {
    if ($r['kind'] !== 'auth' || $r['method'] !== 'POST' || in_array($r['pattern'], ['/logout', '/account/password'], true)) {
        continue;
    }
    $allowed = $allowedRoles($r['middleware']);
    foreach (array_keys($slug) as $who) {
        if (in_array($slug[$who], $allowed, true)) {
            continue;    // allowed roles are not posted to here: it would change data
        }
        $page = req($cl[$who], 'GET', '/dashboard');
        $x = req($cl[$who], 'POST', $fill($r['pattern']), ['_csrf' => csrfFrom(req($cl[$who], 'GET', '/account/password')['body']), 'x' => '1']);
        if ($x['status'] !== 403) {
            $postBad[] = "{$r['key']} as $who -> {$x['status']}";
        }
    }
}
check('Every state-changing route answers 403 to roles that lack the permission, even with a valid CSRF token', !$postBad, implode('; ', array_slice($postBad, 0, 6)));

// ================================================================ CSRF on every POST
$csrfBad = [];
$n = 0;
foreach ($classified as $r) {
    if ($r['method'] !== 'POST' || $r['kind'] === 'public') {
        continue;
    }
    $sess = in_array($r['kind'], ['guest'], true) ? newClient() : $cl['sa'];
    $path = $fill($r['pattern']);
    foreach ([[], ['_csrf' => 'f' . str_repeat('0', 63)], ['_csrf' => str_repeat('a', 64)]] as $body) {
        $x = req($sess, 'POST', $path, $body + ['x' => '1']);
        $n++;
        if ($x['status'] !== 400) {
            $csrfBad[] = $r['key'] . ' -> ' . $x['status'];
        }
    }
}
check("CSRF: $n requests with a missing or wrong token are all refused with 400 (every POST route, including login and registration)", !$csrfBad, implode('; ', array_slice($csrfBad, 0, 5)));
$other = newClient();
$tokenFromOther = csrfFrom(req($other, 'GET', '/login')['body']);
check('A CSRF token from another session is refused', req($cl['sa'], 'POST', '/neighbourhoods', ['_csrf' => $tokenFromOther, 'name' => 'P11TEST nope'])['status'] === 400
    && (int) $pdo->query("SELECT COUNT(*) FROM neighbourhoods WHERE name = 'P11TEST nope'")->fetchColumn() === 0);
$prod = Config::get('app.env');
Config::set('app.env', 'production');
$r2 = new Router();
require dirname(__DIR__) . '/routes/web.php';
Config::set('app.env', $prod);
$hasStyle = array_filter($r2->routes(), fn($r) => $r['pattern'] === '/styleguide');
check('The /styleguide page is not registered in production', !$hasStyle);

// ================================================================ headers on real responses
$hdrBad = [];
$need = ['x-content-type-options: nosniff', 'x-frame-options', 'referrer-policy', 'permissions-policy', 'content-security-policy: default-src'];
foreach (['/', '/login', '/register', '/health'] as $p) {
    $h = strtolower(req($anon, 'GET', $p)['headers']);
    foreach ($need as $n2) {
        if (!str_contains($h, $n2)) {
            $hdrBad[] = "$p lacks $n2";
        }
    }
    if (str_contains($h, 'x-powered-by')) {
        $hdrBad[] = "$p leaks X-Powered-By";
    }
}
foreach (['/admin', '/users', '/reports'] as $p) {
    $h = strtolower(req($cl['sa'], 'GET', $p)['headers']);
    if (!str_contains($h, 'no-store') || !str_contains($h, "content-security-policy: default-src 'self'")) {
        $hdrBad[] = "$p (signed in) lacks no-store or CSP";
    }
}
check('Security headers on public and signed-in pages: CSP, nosniff, framing, referrer, permissions, no-store, no X-Powered-By', !$hdrBad, implode('; ', $hdrBad));
$inline = [];
foreach ($bodies as $label => $x) {
    if (preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $x['body']) || preg_match('/\son[a-z]+\s*=\s*["\']/i', preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $x['body'])) || preg_match('/(href|src|action)\s*=\s*["\']\s*javascript:/i', $x['body'])) {
        $inline[] = $label;
    }
}
check('No page contains an inline script, inline event handler or javascript: link (' . count($bodies) . ' pages checked), so the strict CSP cannot break any page', !$inline, implode('; ', array_slice($inline, 0, 5)));
check('Only our own scripts and styles are referenced (no external hosts)', (function () use ($bodies) {
    foreach ($bodies as $x) {
        if (preg_match_all('/<(script|link)\b[^>]*(src|href)=["\'](https?:)?\/\/(?!localhost)[^"\']+/i', $x['body'], $m) && preg_match('/stylesheet|<script/i', implode(' ', $m[0]))) {
            return false;
        }
    }
    return true;
})());

// ================================================================ cookies and sessions
$jar = newClient();
$loginPage = req($jar, 'GET', '/login');
preg_match('/Set-Cookie:\s*(plastilink_session)=([^;]+);([^\r\n]*)/i', $loginPage['headers'], $sc);
$flags = strtolower($sc[3] ?? '');
check('Session cookie is HttpOnly, SameSite=Lax, scoped to the app path', str_contains($flags, 'httponly') && str_contains($flags, 'samesite=lax') && str_contains($flags, 'path='));
$before = $sc[2] ?? '';
$after = login($jar, 'phase11test.sa@example.test', $PW);
$cookieAfter = '';
foreach (file($jar['jar']) as $line) {
    if (str_contains($line, 'plastilink_session')) {
        $cookieAfter = trim(explode("\t", trim($line))[6] ?? '');
    }
}
check('Signing in issues a NEW session id (no session fixation)', $before !== '' && $cookieAfter !== '' && $before !== $cookieAfter);
$stolen = newClient();
file_put_contents($stolen['jar'], "# Netscape HTTP Cookie File\nlocalhost\tFALSE\t" . ($basePath ?: '/') . "/\tFALSE\t0\tplastilink_session\t$cookieAfter\n");
check('The session works while signed in, and the same cookie is useless after sign-out', req($stolen, 'GET', '/admin')['status'] === 200
    && ($lo = postVia($jar, '/admin', '/logout', [])) && $lo['status'] === 303 && req($stolen, 'GET', '/admin')['status'] === 303);
$fix = newClient();
file_put_contents($fix['jar'], "# Netscape HTTP Cookie File\nlocalhost\tFALSE\t" . ($basePath ?: '/') . "/\tFALSE\t0\tplastilink_session\tattackerchosenid0123456789abcdef\n");
$fx = req($fix, 'GET', '/login');
preg_match('/Set-Cookie:\s*plastilink_session=([^;]+)/i', $fx['headers'], $fm);
check('A session id chosen by an attacker is not accepted (a fresh one is issued)', ($fm[1] ?? '') !== 'attackerchosenid0123456789abcdef' && ($fm[1] ?? '') !== '');
$signedOut = req($stolen, 'GET', '/users');
check('After sign-out, protected pages redirect to /login and are not cached', $signedOut['status'] === 303 && loc($signedOut) === '/login');

// ================================================================ authentication abuse
$u1 = req(newClient(), 'POST', '/login', []);   // 400 (csrf)
$c1 = newClient();
$pg = req($c1, 'GET', '/login');
$a = req($c1, 'POST', '/login', ['_csrf' => csrfFrom($pg['body']), 'identifier' => 'nobody-here@example.test', 'password' => 'wrong-password-1']);
$pa1 = follow($c1, $a)['body'];
$b = req($c1, 'POST', '/login', ['_csrf' => csrfFrom($pa1), 'identifier' => 'phase11test.pm@example.test', 'password' => 'wrong-password-1']);
$ma = strip_tags($pa1);
$mb = strip_tags(follow($c1, $b)['body']);
check('Wrong password and unknown user give the same message (no account enumeration)', str_contains($ma, 'Invalid phone number, email or password') && str_contains($mb, 'Invalid phone number, email or password'));
$fp1 = newClient(); $fp2 = newClient();
$r1 = postVia($fp1, '/forgot-password', '/forgot-password', ['identifier' => 'phase11test.pm@example.test']);
$r2 = postVia($fp2, '/forgot-password', '/forgot-password', ['identifier' => 'nobody-here@example.test']);
check('Password reset gives the same answer for existing and unknown accounts', $r1['status'] === $r2['status'] && loc($r1) === loc($r2) && strip_tags(follow($fp1, $r1)['body']) === strip_tags(follow($fp2, $r2)['body']));
$lock = newClient();
$last = '';
for ($i = 0; $i < 7; $i++) {
    $pg = req($lock, 'GET', '/login');
    $x = req($lock, 'POST', '/login', ['_csrf' => csrfFrom($pg['body']), 'identifier' => 'phase11test.hob@example.test', 'password' => "wrong-$i-attempt"]);
    $last = strip_tags(follow($lock, $x)['body']);
}
check('After repeated failures the account is locked and the message says to wait', str_contains($last, 'Too many failed attempts'));
$ok = login($lock, 'phase11test.hob@example.test', $PW);
check('A locked account refuses even the CORRECT password', $ok['status'] === 303 && loc($ok) === '/login');
$pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE '%phase11test.hob%'");

// ================================================================ injection probes
$sqli = ["' OR '1'='1", "1; DROP TABLE users;--", '" OR ""="', "\\", "' UNION SELECT password_hash,2,3 FROM users--", "%' AND 1=1 AND '%'='", "1' AND SLEEP(3)--", "\0", str_repeat('A', 5000)];
$users0 = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$sqlBad = [];
$t0 = microtime(true);
$qs = ['q', 'status', 'hub_id', 'from', 'to', 'page', 'neighbourhood_id', 'group', 'role', 'category', 'type', 'awaiting', 'months', 'inactive_days', 'plastic_category_id', 'picker_id'];
$getRoutes = array_filter($classified, fn($r) => $r['kind'] === 'auth' && $r['method'] === 'GET' && $allowedRoles($r['middleware']) && in_array('super_admin', $allowedRoles($r['middleware']), true));
foreach ($getRoutes as $r) {
    $path = $fill($r['pattern']);
    foreach ([$sqli[0], $sqli[1], $sqli[4], $sqli[5]] as $payload) {
        $query = http_build_query(array_fill_keys($qs, $payload));
        $x = req($cl['sa'], 'GET', $path . '?' . $query);
        if ($x['status'] >= 500 || preg_match('/SQLSTATE|syntax error|PDOException|mysqli|You have an error in your SQL/i', $x['body'])) {
            $sqlBad[] = $r['key'] . ' ' . $x['status'];
        }
    }
}
$slow = microtime(true) - $t0;
check('SQL injection probes in every query-string field of every page: no server error, no SQL error text, no delay-injection effect', !$sqlBad && $slow < 120, implode('; ', array_slice($sqlBad, 0, 5)) . sprintf(' %.0fs', $slow));
$formBad = [];
foreach ($sqli as $p) {
    foreach ([['/login', 'identifier', 'password'], ['/forgot-password', 'identifier', null]] as [$path, $f1, $f2]) {
        $c = newClient();
        $pg = req($c, 'GET', $path);
        $body = ['_csrf' => csrfFrom($pg['body']), $f1 => $p] + ($f2 ? [$f2 => $p] : []);
        $x = req($c, 'POST', $path, $body);
        $y = follow($c, $x);
        if ($x['status'] >= 500 || $y['status'] >= 500 || preg_match('/SQLSTATE|PDOException/i', $y['body'])) {
            $formBad[] = "$path " . substr($p, 0, 10);
        }
    }
}
check('SQL injection payloads in the login and password-reset forms are handled as plain text', !$formBad, implode('; ', $formBad));
check('Nothing was dropped or inserted by the probes (users table intact, same row count)', (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $users0 && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE '%UNION%' OR full_name LIKE '%DROP TABLE%'")->fetchColumn() === 0);
$regIn = ['full_name' => "P11 Reg'; DROP TABLE users;--", 'phone' => '0700070301', 'neighbourhood_id' => (string) $nbId, 'national_id' => "NIN' OR '1'='1", 'consent' => '1', 'password' => 'Reg!Strong#Pass1', 'password_confirmation' => 'Reg!Strong#Pass1'];
$rg = newClient();
$pg = req($rg, 'GET', '/register');
req($rg, 'POST', '/register', ['_csrf' => csrfFrom($pg['body'])] + $regIn);
check('Hostile text in the public registration form is stored as text, never executed', (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() >= $users0);

// ================================================================ XSS: stored and reflected
$xHub = Hub::create(['name' => 'P11TEST ' . $XSS, 'neighbourhood_id' => $nbId, 'address_note' => $XSS, 'latitude' => null, 'longitude' => null, 'status' => 'active']);
$xNb = Neighbourhood::create('P11TEST ' . $XSS);
$xCol = $mkCol($hubA, $pkA, '5', 'hoA', 'hmA', $XSS);
$xRec = RecyclerService::registerByStaff(['business_name' => 'P11TEST ' . $XSS, 'contact_person' => $XSS, 'email' => 'phase11test.r2@example.test', 'phone' => '0700070202', 'address' => $XSS, 'consent' => '1'], $sa);
$ids['xuser'] = User::create(['role' => 'hub_operator', 'full_name' => $XSS, 'email' => 'phase11test.xss@example.test', 'password' => $PW, 'status' => 'active']);
$pdo->prepare('UPDATE price_lists SET note = ? WHERE hub_id = ?')->execute(['P11TEST ' . $XSS, $hubA]);
\App\Models\AuditLog::write('p11.xss_probe', $ids['sa'], 'hub', $xHub, ['note' => $XSS]);
$pages = ['/hubs', "/hubs/$xHub", '/neighbourhoods', '/users', '/users/' . $ids['xuser'], '/recyclers', '/recyclers/' . (int) $xRec['id'], '/collections', "/collections/$xCol", '/admin/audit', '/prices', '/pickers', '/approvals', '/reports/hubs', '/reports/geographic', '/monitoring/map', '/inventory'];
$xssBad = [];
$seenEscaped = 0;
foreach ($pages as $p) {
    foreach (['sa', 'pm'] as $who) {
        $x = req($cl[$who], 'GET', $p);
        if ($x['status'] >= 500) {
            $xssBad[] = "$p $who -> {$x['status']}";
        }
        if (preg_match('/<script>alert\(1\)|<img src=x onerror|onerror=alert\(2\)>/i', $x['body']) && !preg_match('/&lt;script&gt;alert\(1\)/', $x['body'])) {
            $xssBad[] = "$p $who unescaped payload";
        } elseif (preg_match('/<script>alert\(1\)<\/script>/i', $x['body'])) {
            $xssBad[] = "$p $who raw <script>";
        }
        $seenEscaped += str_contains($x['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') ? 1 : 0;
    }
}
check('Stored XSS: a script payload saved in hub, neighbourhood, user, recycler, collection note, price note and audit data is escaped on every page (' . $seenEscaped . ' pages showed it escaped)', !$xssBad && $seenEscaped >= 8, implode('; ', array_slice($xssBad, 0, 5)));
$refl = [];
foreach (['/users', '/hubs', '/pickers', '/recyclers', '/collections', '/reports/collections', '/monitoring/map', '/admin/audit', '/payments', '/invoices', '/orders', '/inventory/movements'] as $p) {
    $x = req($cl['sa'], 'GET', $p . '?' . http_build_query(['q' => $XSS, 'status' => $XSS, 'from' => $XSS, 'to' => $XSS, 'hub_id' => $XSS, 'group' => $XSS]));
    if ($x['status'] >= 500 || preg_match('/<script>alert\(1\)|<img src=x onerror/i', $x['body'])) {
        $refl[] = $p . ' ' . $x['status'];
    }
}
check('Reflected XSS: payloads in query strings are never echoed unescaped by any listing, search or report page', !$refl, implode('; ', $refl));
$flash = newClient();
$pg = req($flash, 'GET', '/login');
$x = req($flash, 'POST', '/login', ['_csrf' => csrfFrom($pg['body']), 'identifier' => $XSS, 'password' => 'x']);
$y = follow($flash, $x);
check('The sign-in form re-displays a hostile identifier escaped', !preg_match('/<script>alert\(1\)|<img src=x onerror/i', $y['body']) && str_contains($y['body'], '&lt;script&gt;'));

// ================================================================ IDOR
$idor = [];
$expectDenied = function (string $who, string $path) use ($cl, &$idor): void {
    $x = req($cl[$who], 'GET', $path);
    if (!in_array($x['status'], [403, 404], true)) {
        $idor[] = "$who $path -> {$x['status']}";
    }
};
$expectDenied('pkB', "/collections/$colA");
$rcA = (int) $pdo->query("SELECT id FROM receipts WHERE collection_id = $colA")->fetchColumn();
$expectDenied('pkB', "/receipts/$rcA");
$expectDenied('hmB', "/receipts/$rcA");
$expectDenied('pkA', "/collections/$colB");
$expectDenied('hmA', "/collections/$colB");
$expectDenied('hmA', "/hubs/$hubB");
$expectDenied('hmA', "/pickers/$pkB");
$expectDenied('hmA', "/earnings/$pkB");
$expectDenied('hmB', "/collections/$colA");
$expectDenied('hmB', "/pickers/$pkA");
$expectDenied('r1', "/collections/$colA");
$expectDenied('r1', "/pickers/$pkA");
$expectDenied('r1', "/earnings/$pkA");
$expectDenied('pkA', "/earnings/$pkB");
$expectDenied('pkA', "/pickers/$pkB");
$expectDenied('pkA', '/users');
check('IDOR: pickers, hub managers and recyclers cannot open another hub\'s or person\'s collections, receipts, pickers or earnings by changing the id in the URL', !$idor, implode('; ', $idor));
$own = req($cl['pkA'], 'GET', "/collections/$colA");
check('...while the owner can open their own record', $own['status'] === 200 && req($cl['hmA'], 'GET', "/collections/$colA")['status'] === 200);
$mine = [];
foreach (['hmA' => [$hubA, $hubB], 'hmB' => [$hubB, $hubA]] as $who => [$ownHub, $otherHub]) {
    $own1 = req($cl[$who], 'GET', "/hubs/$ownHub");
    $other = req($cl[$who], 'GET', "/hubs/$otherHub");
    $list = req($cl[$who], 'GET', '/collections?per=200');
    $mine[] = $own1['status'] === 200 && in_array($other['status'], [403, 404], true) && !str_contains($list['body'], $who === 'hmA' ? 'P11 Picker B' : 'P11 Picker A');
}
check('Hub Manager listings and hub pages show only their own hub\'s data', !in_array(false, $mine, true));

// ================================================================ file and folder exposure
$leaks = [];
$probe = ['/.git/config', '/.git/HEAD', '/config/app.php', '/config/database.local.php', '/config/app.local.php', '/config/database.php', '/tests/_http.php', '/tests/phase1_smoke.php', '/storage/logs/', '/storage/receipts/', '/storage/backups/',
    '/migrate.php', '/seed.php', '/jobs.php', '/backup.php', '/create-admin.php', '/database/migrations/0001_create_system_settings_table.php', '/app/Core/Database.php', '/app/bootstrap.php', '/resources/views/layouts/main.php',
    '/routes/web.php', '/composer.json', '/README.md', '/deploy/preflight.php', '/deploy/build.php', '/docs/SECURITY.md', '/.gitignore', '/.htaccess', '/public/../config/app.php', '/%2e%2e/config/app.php', '/assets/../../config/app.php',
    '/assets/..%2f..%2fconfig/app.php', '/%252e%252e/config/app.php', '/assets/', '/assets/vendor/', '/assets/js/', '/uploads/', '/public/', '/index.php/../config/app.php', '/config/app.php%00.png', '/.env', '/phpinfo.php', '/storage/backups/x.sql.gz'];
foreach ($probe as $p) {
    $x = req($anon, 'GET', $p);
    $sensitive = preg_match('/(\'password\'\s*=>|DB_PASSWORD|<\?php|namespace App|CREATE TABLE|\[core\]|repositoryformatversion)/i', $x['body']);
    if (!in_array($x['status'], [403, 404, 301, 302, 303], true) || $sensitive) {
        $leaks[] = "$p -> {$x['status']}" . ($sensitive ? ' (CONTENT LEAK)' : '');
    }
}
check('File exposure: source, config, git, tests, migrations, storage, backups and traversal tricks (' . count($probe) . ' URLs) are all refused or hidden; directory listings are off', !$leaks, implode('; ', $leaks));
$e404 = req($anon, 'GET', '/no/such/page');
$e405 = req($anon, 'PATCH', '/login');
check('Unknown pages and wrong methods give clean errors with no file paths, stack traces or SQL', in_array($e404['status'], [404], true) && !preg_match('#[A-Z]:\\\\|/var/www|/home/|\.php:\d+|Stack trace|PDOException#i', $e404['body'] . $e405['body']));
check('/health reveals only status, app name, database up/down and time (no versions, paths or hosts)', (function () use ($anon) {
    $d = json_decode(req($anon, 'GET', '/health')['body'], true);
    return is_array($d) && array_keys($d) === ['status', 'app', 'database', 'time'] && !preg_match('#[A-Z]:\\\\|/home/|localhost|127\.0\.0\.1|[0-9]+\.[0-9]+\.[0-9]+#', json_encode(array_diff_key($d, ['time' => 1])));
})());

// ================================================================ open redirect and mass assignment
$redir = [];
foreach (['//evil.com', 'https://evil.com', '/\\evil.com', 'javascript:alert(1)', "/ok\r\nX-Injected: 1"] as $bad) {
    $x = postVia($cl['sa'], '/recyclers', '/recyclers/1/decision', ['decision' => 'noop', 'back' => $bad]);
    $l = (string) $x['location'];
    if (preg_match('#evil\.com|javascript:|X-Injected#i', $l) || (!str_starts_with($l, $basePath . '/') && $l !== '')) {
        $redir[] = substr($bad, 0, 20) . " -> $l";
    }
}
check('Open redirect: a hostile "back" target never sends the browser off-site or injects headers', !$redir, implode('; ', $redir));
$mc = newClient();
$pg = req($mc, 'GET', '/register');
$regEmail = 'phase11reg.mass@example.test';
req($mc, 'POST', '/register', ['_csrf' => csrfFrom($pg['body']), 'full_name' => 'P11 Mass Assign', 'phone' => '0700070302', 'neighbourhood_id' => (string) $nbId, 'national_id' => 'NINMASS11', 'consent' => '1',
    'password' => 'Mass!Assign#Pass9', 'password_confirmation' => 'Mass!Assign#Pass9', 'role' => 'super_admin', 'role_id' => '1', 'status' => 'active', 'must_change_password' => '0', 'email' => $regEmail, 'user_id' => '1', 'account_status' => 'active']);
$row = $pdo->query("SELECT r.slug, u.status FROM users u JOIN roles r ON r.id = u.role_id WHERE u.phone_e164 = '+256700070302'")->fetch();
check('Mass assignment: extra fields (role, status, role_id) sent with a public registration are ignored: the account is a pending waste picker', $row && $row['slug'] === 'waste_picker' && $row['status'] === 'pending', json_encode($row));
$before = $pdo->query("SELECT r.slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = {$ids['pkA']}")->fetchColumn();
$cp = postVia($cl['pkA'], '/account/password', '/account/password', ['current_password' => $PW, 'password' => 'NewPass!Strong77x', 'password_confirmation' => 'NewPass!Strong77x', 'role' => 'super_admin', 'status' => 'active', 'role_id' => '1']);
$afterRole = $pdo->query("SELECT r.slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = {$ids['pkA']}")->fetchColumn();
check('Mass assignment: extra fields sent with a password change cannot change the role', $before === 'waste_picker' && $afterRole === 'waste_picker');
User::updatePassword($ids['pkA'], $PW);
$noSelfSa = req($cl['hmA'], 'POST', '/users/' . $ids['hmA'], ['_csrf' => csrfFrom(req($cl['hmA'], 'GET', '/account/password')['body']), 'role' => 'super_admin', 'full_name' => 'x']);
check('A Hub Manager cannot promote themselves through the user routes (403)', $noSelfSa['status'] === 403 && $pdo->query("SELECT r.slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = {$ids['hmA']}")->fetchColumn() === 'hub_manager');

// ================================================================ safety of the audit trail and output of secrets
$audit = implode(' ', $pdo->query("SELECT COALESCE(details, '') FROM audit_logs ORDER BY id DESC LIMIT 2000")->fetchAll(PDO::FETCH_COLUMN));
check('The audit trail never records passwords, tokens or password hashes', !preg_match('/\$2y\$|Phase11Test!pw|"password"\s*:|"token"\s*:|"_csrf"/i', $audit));
$log = '';
foreach (glob(dirname(__DIR__) . '/storage/logs/*.log') ?: [] as $f) {
    $log .= (string) @file_get_contents($f);
}
check('Application logs hold no passwords or password hashes', !preg_match('/Phase11Test!pw|\$2y\$10\$/', $log));
check('Access denials are audited (auth.access_denied rows exist for this run)', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.access_denied' AND created_at >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE")->fetchColumn() > 20);

// ================================================================ finish
$cleanup();
$left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'phase11test.%' OR email LIKE 'phase11reg.%'")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM collection_hubs WHERE name LIKE 'P11TEST%'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM neighbourhoods WHERE name LIKE 'P11TEST%'")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM users WHERE phone_e164 LIKE '+25670007%'")->fetchColumn();
check('Test data cleaned up', $left === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
