<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/main'): string
    {
        return View::render($view, $data, $layout);
    }

    protected function json(array $data, int $status = 200): array
    {
        http_response_code($status);
        return $data;
    }
}
