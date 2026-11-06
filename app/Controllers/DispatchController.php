<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Models\Hub;
use App\Models\PlasticCategory;
use App\Models\Recycler;
use App\Models\StockDispatch;
use App\Services\AuthService;
use App\Services\DispatchService;
use App\Services\HubScope;

final class DispatchController extends Controller
{
    public function index(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        $f = ['status' => in_array($request->query['status'] ?? '', ['dispatched', 'delivered', 'cancelled'], true) ? $request->query['status'] : ''];
        $pg = Paginator::make(StockDispatch::count($f, $scope), $request->query['page'] ?? 1);
        return $this->page($request, 'inventory/dispatches/index', 'Dispatches to recyclers', [
            'rows' => StockDispatch::paginate($f, $scope, $pg['offset'], $pg['per']), 'pg' => $pg, 'f' => $f, 'tab' => 'dispatches',
        ]);
    }

    public function create(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        return $this->page($request, 'inventory/dispatches/new', 'Dispatch stock to a recycler', [
            'hubs' => Hub::options($scope), 'cats' => PlasticCategory::options(), 'recyclers' => Recycler::approvedOptions(),
            'token' => bin2hex(random_bytes(16)), 'tab' => 'dispatches',
        ]);
    }

    public function store(Request $request): string
    {
        $r = DispatchService::create($request->body, AuthService::user());
        if (!empty($r['duplicate'])) {
            Session::flash('info', 'This dispatch was already recorded, so no second one was made.');
            return redirect('/inventory/dispatches/' . $r['id']);
        }
        if ($r['errors']) {
            if (isset($r['errors']['_'])) {
                Session::flash('danger', $r['errors']['_']);
                return redirect('/inventory/dispatches/new');
            }
            return $this->invalid($r['errors'], $request->body, '/inventory/dispatches/new');
        }
        Session::flash('success', 'Dispatch recorded and the stock has left the hub.');
        return redirect('/inventory/dispatches/' . $r['id']);
    }

    public function show(Request $request, string $id): string
    {
        $d = DispatchService::findAccessible((int) $id, AuthService::user());
        if (!$d) {
            return $this->notFound($request);
        }
        return $this->page($request, 'inventory/dispatches/show', $d['reference'] ?? 'Dispatch', ['d' => $d, 'tab' => 'dispatches']);
    }

    public function delivered(Request $request, string $id): string
    {
        return $this->done(DispatchService::markDelivered((int) $id, (string) $request->input('note', ''), AuthService::user()), (int) $id);
    }

    public function cancel(Request $request, string $id): string
    {
        return $this->done(DispatchService::cancel((int) $id, (string) $request->input('reason', ''), AuthService::user()), (int) $id);
    }

    private function done(array $r, int $id): string
    {
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? (string) $r['message']);
        return redirect('/inventory/dispatches/' . $id);
    }
}
