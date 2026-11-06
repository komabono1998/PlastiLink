<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $server,
    ) {
    }

    public static function capture(): self
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = self::basePath();
        $path = ($base !== '' && str_starts_with($uri, $base)) ? substr($uri, strlen($base)) : $uri;
        $path = '/' . trim($path, '/');

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self($method, $path, $_GET, $_POST, $_SERVER);
    }

    /**
     * URL prefix the app is served under, e.g. "/PlastiLink" on XAMPP, "" on a real domain.
     * Handles both the root .htaccess (XAMPP) and a document root pointed at /public (Hostinger).
     */
    public static function basePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = rtrim(dirname($script), '/');
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        if ($base !== '' && !str_starts_with($uri, $base) && str_ends_with($base, '/public')) {
            $base = substr($base, 0, -7);
        }
        return $base === '.' ? '' : $base;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function wantsJson(): bool
    {
        return str_contains($this->server['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_starts_with($this->path, '/api/')
            || $this->path === '/health';
    }
}
