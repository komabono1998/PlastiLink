<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Global error handling. Full details go to storage/logs; users only see a
 * friendly page. Technical details appear only when app.debug is true.
 */
final class ErrorHandler
{
    private const TITLES = [
        403 => 'Access denied',
        404 => 'Page not found',
        405 => 'Method not allowed',
        400 => 'Session expired',
        500 => 'Something went wrong',
        503 => 'Service unavailable',
    ];

    private const MESSAGES = [
        403 => 'You do not have permission to open this page.',
        404 => 'The page you are looking for does not exist or has moved.',
        405 => 'That action is not allowed on this page.',
        400 => 'Your form expired. Please go back, refresh the page and try again.',
        500 => 'We hit an unexpected problem. It has been logged and we are looking into it.',
        503 => 'The service is temporarily unavailable. Please try again shortly.',
    ];

    public static function register(): void
    {
        $debug = (bool) Config::get('app.debug', false);
        error_reporting(E_ALL);
        ini_set('display_errors', '0');          // never print raw errors to visitors
        ini_set('log_errors', '1');
        ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e) use ($debug): void {
            self::log($e);
            self::emit(500, $debug ? $e : null);
        });

        register_shutdown_function(static function () use ($debug): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::log(new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']));
                if (!headers_sent()) {
                    self::emit(500, $debug ? new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']) : null);
                }
            }
        });
    }

    public static function log(\Throwable $e): void
    {
        $dir = BASE_PATH . '/storage/logs';
        $line = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** Render an error body for the given status (sets the HTTP status code). */
    public static function renderError(int $status, ?Request $request = null, ?\Throwable $debug = null): string
    {
        http_response_code($status);
        $title = self::TITLES[$status] ?? 'Error';
        $message = self::MESSAGES[$status] ?? 'An error occurred.';

        if ($request?->wantsJson() ?? self::clientWantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            $payload = ['ok' => false, 'error' => $title, 'status' => $status];
            if ($debug) {
                $payload['debug'] = $debug->getMessage();
            }
            return json_encode($payload);
        }

        try {
            return View::render('errors/error', [
                'title'   => $title,
                'status'  => $status,
                'message' => $message,
                'debug'   => $debug,
            ]);
        } catch (\Throwable) {
            header('Content-Type: text/plain; charset=utf-8');
            return "$status $title";
        }
    }

    private static function emit(int $status, ?\Throwable $debug): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo self::renderError($status, null, $debug);
    }

    private static function clientWantsJson(): bool
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_ends_with($path, '/health')
            || str_contains($path, '/api/');
    }
}
