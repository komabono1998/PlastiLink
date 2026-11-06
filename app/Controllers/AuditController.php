<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\AuditLog;

final class AuditController extends Controller
{
    public function index(Request $request): string
    {
        return $this->view('admin/audit', [
            'title' => 'Audit trail',
            'nav'   => DashboardController::nav($request),
            'rows'  => AuditLog::recent(100),
        ], 'layouts/dashboard');
    }
}
