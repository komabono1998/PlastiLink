<?php
declare(strict_types=1);

/** Shared helpers for HTTP-level tests (cookie-based clients using the curl extension). */

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

function loc(array $r): string
{
    global $basePath;
    $l = (string) $r['location'];
    return str_starts_with($l, $basePath) ? substr($l, strlen($basePath)) : $l;
}

function follow(array $c, array $r): array
{
    $l = loc($r);
    return req($c, 'GET', $l === '' ? '/' : $l);
}

function csrfFrom(string $html): string
{
    preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m);
    return $m[1] ?? '';
}

/** GET a page and use its CSRF token to POST. Returns the POST response (not followed). */
function postVia(array $c, string $formPath, string $postPath, array $data): array
{
    $page = req($c, 'GET', $formPath);
    return req($c, 'POST', $postPath, ['_csrf' => csrfFrom($page['body'])] + $data);
}

function login(array $c, string $ident, string $pw): array
{
    $page = req($c, 'GET', '/login');
    return req($c, 'POST', '/login', ['_csrf' => csrfFrom($page['body']), 'identifier' => $ident, 'password' => $pw]);
}
