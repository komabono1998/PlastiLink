<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthService;

/** Read configuration with dot notation. */
function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Escape for HTML output (XSS prevention). Use on EVERY dynamic value in views. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute-path URL inside the app, honouring the XAMPP sub-folder. */
function url(string $path = ''): string
{
    return Request::basePath() . '/' . ltrim($path, '/');
}

/** Asset URL with a version stamp (file modification time) so browsers fetch updated CSS/JS after a deploy. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = BASE_PATH . '/public/assets/' . $path;
    $v = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('assets/' . $path) . $v;
}

/** Render a reusable view component (resources/views/components/<name>.php). */
function component(string $name, array $data = []): string
{
    return View::partial('components/' . $name, $data);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Session::csrfToken()) . '">';
}

/** 303 redirect to an in-app path. Returns '' so handlers can `return redirect(...)`. */
function redirect(string $path): string
{
    http_response_code(303);
    header('Location: ' . url($path));
    return '';
}

/** Only allow same-site relative paths (blocks open redirects). */
function safe_path(?string $path, string $fallback = '/'): string
{
    if (!is_string($path) || $path === '' || $path[0] !== '/' || str_starts_with($path, '//')
        || str_contains($path, '\\') || str_contains($path, ':') || preg_match('/[\x00-\x1f]/', $path)) {
        return $fallback;
    }
    return $path;
}

/** The signed-in user (without password hash) or null. */
function auth_user(): ?array
{
    return AuthService::user();
}

function can(string $permission): bool
{
    return AuthService::can($permission);
}

/** Renders and clears queued flash messages. */
function flash_messages(): string
{
    $html = '';
    foreach (Session::pullFlash() as $f) {
        $html .= component('alert', ['type' => $f['type'], 'message' => $f['message']]);
    }
    return $html;
}

/** Previously submitted (non-secret) form value. */
function old(string $key, string $default = ''): string
{
    return Session::old($key, $default);
}

function field_error(string $field): string
{
    return Session::error($field);
}

/** Pagination query string that keeps current filters. */
function page_url(int $page): string
{
    $q = $_GET;
    $q['page'] = $page;
    return '?' . http_build_query($q);
}

/** Roles that see unmasked phone numbers in lists. */
function show_full_phone(): bool
{
    return AuthService::hasRole('super_admin');
}

function display_phone(?string $e164): string
{
    return show_full_phone() ? ($e164 ?? '—') : \App\Helpers\Phone::mask($e164);
}

/** Whole-shilling formatting: UGX 12,500 */
function format_ugx(int|float|string $amount): string
{
    return config('app.currency', 'UGX') . ' ' . number_format((float) $amount, 0, '.', ',');
}

/** Weight formatting: 12.5 kg */
function format_kg(int|float|string $kg): string
{
    return rtrim(rtrim(number_format((float) $kg, 2, '.', ','), '0'), '.') . ' kg';
}

/** Convert a UTC database timestamp to Africa/Kampala for display. */
function format_datetime(?string $utc, string $format = 'd M Y, H:i'): string
{
    if (!$utc) {
        return '—';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone(config('app.timezone')))->format($format);
}
