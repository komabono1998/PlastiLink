<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Response headers sent with every page. The Content-Security-Policy only allows scripts from our own origin (no inline
 * scripts, no inline event handlers), so an injected <script> or onclick= cannot run even if some output were ever left unescaped.
 * Inline style attributes are still allowed (style-src 'unsafe-inline'): they cannot execute code.
 */
final class SecurityHeaders
{
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function policy(): string
    {
        // Map tiles come from the configured tile server only (the browser asks for them when someone opens a map)
        $tiles = '';
        $url = (string) Config::get('app.integrations.maps.tile_url', '');
        if ($url !== '' && ($p = parse_url(str_replace(['{s}', '{z}', '{x}', '{y}'], ['a', '0', '0', '0'], $url))) && isset($p['scheme'], $p['host']) && in_array($p['scheme'], ['http', 'https'], true)) {
            $host = preg_replace('#^a\.#', '*.', (string) $p['host']) ?? $p['host'];
            $tiles = ' ' . $p['scheme'] . '://' . $p['host'] . ($host !== $p['host'] ? ' ' . $p['scheme'] . '://' . $host : '');
        }
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:" . $tiles,
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }

    public static function send(): void
    {
        header_remove('X-Powered-By');   // do not advertise the PHP version
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        if (self::isHttps() && Config::get('app.env') === 'production' && Config::get('app.security.hsts', true)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        $mode = (string) Config::get('app.security.csp', 'enforce');
        if ($mode === 'enforce') {
            header('Content-Security-Policy: ' . self::policy());
        } elseif ($mode === 'report-only') {
            header('Content-Security-Policy-Report-Only: ' . self::policy());
        }
    }
}
