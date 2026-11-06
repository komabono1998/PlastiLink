<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Models\Hub;
use App\Models\Neighbourhood;
use App\Models\User;
use App\Services\AuthService;
use App\Services\HubScope;
use App\Services\HubService;

final class HubController extends Controller
{
    public function index(Request $request): string
    {
        return $this->page($request, 'hubs/index', 'Collection hubs', ['hubs' => Hub::list(HubScope::forUser(AuthService::user()))]);
    }

    public function create(Request $request): string
    {
        return $this->page($request, 'hubs/form', 'Register a hub', ['hub' => null, 'neighbourhoods' => Neighbourhood::options()]);
    }

    public function store(Request $request): string
    {
        $r = HubService::save(null, $request->body, AuthService::user());
        if ($r['errors']) {
            return $this->invalid($r['errors'], $request->body, '/hubs/create');
        }
        Session::flash('success', 'Hub registered. Now assign its manager and operators.');
        return redirect('/hubs/' . $r['id']);
    }

    public function show(Request $request, string $id): string
    {
        $hub = $this->accessible((int) $id);
        if (!$hub) {
            return $this->notFound($request);
        }
        $staff = Hub::staff((int) $id);
        $assigned = array_column($staff, 'user_id');
        $candidates = [];
        foreach (['manager' => 'hub_manager', 'operator' => 'hub_operator'] as $hr => $role) {
            foreach (User::activeByRole($role) as $u) {
                if (!in_array($u['id'], $assigned, false)) {
                    $candidates[$hr][$u['id']] = $u['full_name'];
                }
            }
        }
        return $this->page($request, 'hubs/show', $hub['name'], ['hub' => $hub, 'staff' => $staff, 'candidates' => $candidates]);
    }

    public function edit(Request $request, string $id): string
    {
        $hub = Hub::find((int) $id);
        return $hub
            ? $this->page($request, 'hubs/form', 'Edit hub', ['hub' => $hub, 'neighbourhoods' => Neighbourhood::options()])
            : $this->notFound($request);
    }

    public function update(Request $request, string $id): string
    {
        $r = HubService::save((int) $id, $request->body, AuthService::user());
        if ($r['errors']) {
            return isset($r['errors']['_']) ? $this->notFound($request) : $this->invalid($r['errors'], $request->body, "/hubs/$id/edit");
        }
        Session::flash('success', 'Hub updated.');
        return redirect("/hubs/$id");
    }

    public function assign(Request $request, string $id): string
    {
        $err = HubService::assign((int) $id, (int) $request->input('user_id', 0), (string) $request->input('hub_role', ''), AuthService::user());
        Session::flash($err ? 'danger' : 'success', $err ?? 'Staff member assigned.');
        return redirect("/hubs/$id");
    }

    public function unassign(Request $request, string $id): string
    {
        $err = HubService::unassign((int) $id, (int) $request->input('user_id', 0), AuthService::user());
        Session::flash($err ? 'danger' : 'success', $err ?? 'Staff member removed from this hub.');
        return redirect("/hubs/$id");
    }

    private function accessible(int $id): ?array
    {
        $hub = Hub::find($id);
        return $hub && HubScope::allows(HubScope::forUser(AuthService::user()), $id) ? $hub : null;
    }
}
