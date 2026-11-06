<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;

final class HealthController extends Controller
{
    /** Never reveals connection details or exception messages. */
    public function index(Request $request): array
    {
        $dbOk = false;
        try {
            $dbOk = (int) Database::connection()->query('SELECT 1')->fetchColumn() === 1;
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::log($e);
        }

        return $this->json([
            'status'   => $dbOk ? 'ok' : 'degraded',
            'app'      => config('app.name'),
            'database' => $dbOk ? 'up' : 'down',
            'time'     => gmdate('c'),
        ], $dbOk ? 200 : 503);
    }
}
