<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\Picker;
use App\Models\Recycler;
use App\Services\AuthService;
use App\Services\HubScope;

final class ApprovalController extends Controller
{
    public function index(Request $request): string
    {
        $user = AuthService::user();
        $pickers = AuthService::can('pickers.manage')
            ? Picker::paginate(['status' => 'pending'], HubScope::forUser($user), 0, 100) : [];
        $recyclers = AuthService::can('recyclers.approve')
            ? Recycler::paginate(['status' => 'pending'], 0, 100) : [];
        return $this->page($request, 'admin/approvals', 'Approvals', ['pickers' => $pickers, 'recyclers' => $recyclers]);
    }
}
