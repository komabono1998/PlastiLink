<?php
declare(strict_types=1);

namespace App\Core;

use App\Middleware\Middleware;

final class Router
{
    /** @var array<int, array{method:string, regex:string, handler:callable|array, middleware:array}> */
    private array $routes = [];

    /** @param Middleware[] $middleware */
    public function get(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /** @param Middleware[] $middleware */
    public function post(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /** @param Middleware[] $middleware */
    public function add(string $method, string $pattern, callable|array $handler, array $middleware = []): void
    {
        // {name} matches one segment; {name:regex} matches a custom pattern, e.g. {id:\d+}
        $p = rtrim($pattern, '/') ?: '/';
        $p = preg_replace('#\{(\w+):([^}]+)\}#', '(?P<$1>$2)', $p);
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $p) . '$#';
        $this->routes[] = [
            'method' => strtoupper($method), 'regex' => $regex, 'pattern' => $pattern,
            'handler' => $handler, 'middleware' => $middleware,
        ];
    }

    /** Every registered route (used by the security tests to check that none is left unprotected by accident). */
    public function routes(): array
    {
        return $this->routes;
    }

    /** Returns the response body (string) after setting status/headers. */
    public function dispatch(Request $request): string
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== $request->method && !($request->method === 'HEAD' && $route['method'] === 'GET')) {
                $allowed[] = $route['method'];
                continue;
            }

            // Backend-enforced access control: middleware runs before the handler.
            foreach ($route['middleware'] as $mw) {
                $short = $mw->handle($request);
                if ($short !== null) {
                    return $short;
                }
            }

            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $this->invoke($route['handler'], $request, $params);
        }

        if ($allowed) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            return ErrorHandler::renderError(405, $request);
        }
        return ErrorHandler::renderError(404, $request);
    }

    private function invoke(callable|array $handler, Request $request, array $params): string
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $handler = [new $class(), $method];
        }
        $result = $handler($request, ...$params);

        if (is_array($result)) {
            header('Content-Type: application/json; charset=utf-8');
            return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        return (string) $result;
    }
}
