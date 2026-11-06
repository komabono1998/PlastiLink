<?php
declare(strict_types=1);

namespace App\Services\Http;

/**
 * Minimal outbound HTTP (curl). Short timeouts, no redirects, TLS verification always on.
 * In production only https:// URLs are allowed. Never log request headers or bodies from callers: they carry secrets.
 */
final class HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:?string}  status 0 = no answer (timeout / network error)
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 12): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || ($scheme === 'http' && config('app.env') === 'production')) {
            return ['status' => 0, 'body' => '', 'error' => 'Only https URLs are allowed.'];
        }
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $h,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $out === false ? curl_error($ch) : null;
        curl_close($ch);
        return ['status' => $out === false ? 0 : $status, 'body' => $out === false ? '' : (string) $out, 'error' => $err];
    }

    /** @return array<string,mixed> */
    public static function json(string $body): array
    {
        $d = json_decode($body, true);
        return is_array($d) ? $d : [];
    }
}
