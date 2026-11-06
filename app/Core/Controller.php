<?php
declare(strict_types=1);

namespace App\Core;

use App\Controllers\DashboardController;

abstract class Controller
{
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/main'): string
    {
        return View::render($view, $data, $layout);
    }

    /** Authenticated page inside the dashboard layout (sidebar built for the current user). */
    protected function page(Request $request, string $view, string $title, array $data = []): string
    {
        return View::render($view, ['title' => $title, 'nav' => DashboardController::nav($request)] + $data, 'layouts/dashboard');
    }

    protected function json(array $data, int $status = 200): array
    {
        http_response_code($status);
        return $data;
    }

    /** Post/Redirect/Get after failed validation: keeps typed values (never passwords) and errors. */
    protected function invalid(array $errors, array $input, string $path): string
    {
        foreach (['password', 'password_confirmation', 'current_password', '_csrf'] as $secret) {
            unset($input[$secret]);
        }
        Session::flashErrors($errors);
        Session::flashInput($input);
        Session::flash('danger', 'Please fix the highlighted fields and try again.');
        return redirect($path);
    }

    protected function notFound(Request $request): string
    {
        return ErrorHandler::renderError(404, $request);
    }
}
