<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;

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

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
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
