<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\AuthService;

final class DashboardController extends Controller
{
    /** /dashboard: send each role to its own home. */
    public function index(Request $request): string
    {
        return redirect(AuthService::homeFor(AuthService::user()['role_slug']));
    }

    public function admin(Request $request): string
    {
        $byStatus = User::countByStatus();
        $data = [
            'title'        => 'Dashboard',
            'nav'          => self::nav($request),
            'usersTotal'   => array_sum($byStatus),
            'usersActive'  => $byStatus['active'] ?? 0,
            'usersPending' => $byStatus['pending'] ?? 0,
            'usersSuspended' => $byStatus['suspended'] ?? 0,
            'byRole'       => User::countByRole(),
            'failed24h'    => LoginAttempt::failedSince('24 HOUR'),
            'recentLogins' => LoginAttempt::recentSuccessful(5),
        ];
        return $this->view('admin/dashboard', $data, 'layouts/dashboard');
    }

    public function hub(Request $request): string
    {
        return $this->view('hubs/dashboard', ['title' => 'Hub dashboard', 'nav' => self::nav($request)], 'layouts/dashboard');
    }

    public function picker(Request $request): string
    {
        return $this->view('picker/dashboard', ['title' => 'My dashboard', 'nav' => self::nav($request)], 'layouts/dashboard');
    }

    public function recycler(Request $request): string
    {
        return $this->view('recyclers/dashboard', ['title' => 'Recycler dashboard', 'nav' => self::nav($request)], 'layouts/dashboard');
    }

    /** Sidebar for the current user: only links that exist and that they may open. */
    public static function nav(Request $request): array
    {
        $user = AuthService::user();
        $home = AuthService::homeFor($user['role_slug']);
        $items = [['href' => url($home), 'icon' => 'speedometer2', 'label' => 'Dashboard', 'active' => $request->path === $home]];
        if (AuthService::can('audit.view')) {
            $items[] = ['href' => url('/admin/audit'), 'icon' => 'journal-text', 'label' => 'Audit trail', 'active' => $request->path === '/admin/audit'];
        }
        return $items;
    }
}
