<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Models\AuditLog;
use App\Models\Hub;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;

final class UserController extends Controller
{
    private const ROLE_OPTIONS = [
        'super_admin' => 'Super Administrator', 'project_manager' => 'Project Manager',
        'hub_manager' => 'Hub Manager', 'hub_operator' => 'Hub Operator',
        'waste_picker' => 'Waste Picker', 'recycler' => 'Recycler',
    ];

    public function index(Request $request): string
    {
        $f = [
            'q'      => trim((string) ($request->query['q'] ?? '')),
            'role'   => array_key_exists($request->query['role'] ?? '', self::ROLE_OPTIONS) ? $request->query['role'] : '',
            'status' => in_array($request->query['status'] ?? '', ['pending', 'active', 'suspended'], true) ? $request->query['status'] : '',
        ];
        $pg = Paginator::make(User::count($f), $request->query['page'] ?? 1);
        return $this->page($request, 'admin/users/index', 'Users', [
            'users' => User::paginate($f, $pg['offset'], $pg['per']), 'pg' => $pg, 'f' => $f, 'roles' => self::ROLE_OPTIONS,
        ]);
    }

    public function create(Request $request): string
    {
        return $this->page($request, 'admin/users/form', 'Add staff account', [
            'user' => null, 'roles' => array_intersect_key(self::ROLE_OPTIONS, array_flip(UserService::STAFF_ROLES)), 'isStaff' => true,
        ]);
    }

    public function store(Request $request): string
    {
        $actor = AuthService::user();
        $r = UserService::createStaff($request->body, $actor);
        if ($r['errors']) {
            return $this->invalid($r['errors'], $request->body, '/users/create');
        }
        Session::putOnce('temp_user_' . $r['id'], $r['temp']);
        Session::flash('success', 'Staff account created.');
        return redirect('/users/' . $r['id']);
    }

    public function show(Request $request, string $id): string
    {
        $u = User::findById((int) $id);
        if (!$u) {
            return $this->notFound($request);
        }
        $hubs = array_intersect_key(Hub::options(), array_flip(Hub::idsForUser((int) $u['id'])));
        return $this->page($request, 'admin/users/show', 'User: ' . $u['full_name'], [
            'u' => $u, 'hubs' => $hubs, 'events' => AuditLog::aboutUser((int) $u['id']),
            'temp' => Session::pullOnce('temp_user_' . $u['id']), 'self' => (int) $u['id'] === (int) AuthService::user()['id'],
        ]);
    }

    public function edit(Request $request, string $id): string
    {
        $u = User::findById((int) $id);
        if (!$u) {
            return $this->notFound($request);
        }
        $isStaff = in_array($u['role_slug'], UserService::STAFF_ROLES, true);
        return $this->page($request, 'admin/users/form', 'Edit user', [
            'user' => $u, 'isStaff' => $isStaff,
            'roles' => array_intersect_key(self::ROLE_OPTIONS, array_flip(UserService::STAFF_ROLES)),
        ]);
    }

    public function update(Request $request, string $id): string
    {
        $r = UserService::update((int) $id, $request->body, AuthService::user());
        if ($r['errors']) {
            if (isset($r['errors']['_'])) {
                return $this->notFound($request);
            }
            return $this->invalid($r['errors'], $request->body, "/users/$id/edit");
        }
        Session::flash('success', 'User updated.');
        return redirect("/users/$id");
    }

    public function status(Request $request, string $id): string
    {
        $err = UserService::setStatus((int) $id, (string) $request->input('status', ''), AuthService::user());
        Session::flash($err ? 'danger' : 'success', $err ?? 'Account status updated.');
        return redirect("/users/$id");
    }

    public function resetPassword(Request $request, string $id): string
    {
        $r = UserService::resetPassword((int) $id, AuthService::user());
        if ($r['error']) {
            Session::flash('danger', $r['error']);
        } else {
            Session::putOnce('temp_user_' . $id, $r['temp']);
            Session::flash('success', 'A new temporary password was created. The person must change it at next sign-in.');
        }
        return redirect("/users/$id");
    }
}
