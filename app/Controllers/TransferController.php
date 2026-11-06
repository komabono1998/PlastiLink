<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Models\Hub;
use App\Models\PlasticCategory;
use App\Models\StockTransfer;
use App\Services\AuthService;
use App\Services\HubScope;
use App\Services\TransferService;

final class TransferController extends Controller
{
    public function index(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        $f = ['status' => in_array($request->query['status'] ?? '', ['in_transit', 'received', 'cancelled'], true) ? $request->query['status'] : ''];
        $pg = Paginator::make(StockTransfer::count($f, $scope), $request->query['page'] ?? 1);
        return $this->page($request, 'inventory/transfers/index', 'Stock transfers', [
            'rows' => StockTransfer::paginate($f, $scope, $pg['offset'], $pg['per']), 'pg' => $pg, 'f' => $f, 'tab' => 'transfers', 'scope' => $scope,
        ]);
    }

    public function create(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        return $this->page($request, 'inventory/transfers/new', 'Send stock to another hub', [
            'fromHubs' => Hub::options($scope), 'toHubs' => Hub::options(), 'cats' => PlasticCategory::options(), 'token' => bin2hex(random_bytes(16)), 'tab' => 'transfers',
        ]);
    }

    public function store(Request $request): string
    {
        $r = TransferService::send($request->body, AuthService::user());
        if (!empty($r['duplicate'])) {
            Session::flash('info', 'This transfer was already recorded, so no second one was made.');
            return redirect('/inventory/transfers/' . $r['id']);
        }
        if ($r['errors']) {
            if (isset($r['errors']['_'])) {
                Session::flash('danger', $r['errors']['_']);
                return redirect('/inventory/transfers/new');
            }
            return $this->invalid($r['errors'], $request->body, '/inventory/transfers/new');
        }
        Session::flash('success', 'Transfer recorded. The stock has left your hub and is in transit.');
        return redirect('/inventory/transfers/' . $r['id']);
    }

    public function show(Request $request, string $id): string
    {
        $user = AuthService::user();
        $t = TransferService::findAccessible((int) $id, $user);
        if (!$t) {
            return $this->notFound($request);
        }
        $scope = HubScope::forUser($user);
        return $this->page($request, 'inventory/transfers/show', $t['reference'] ?? 'Transfer', [
            't' => $t, 'tab' => 'transfers',
            'canReceive' => $t['status'] === 'in_transit' && can('inventory.manage') && HubScope::allows($scope, (int) $t['to_hub_id']),
            'canCancel' => $t['status'] === 'in_transit' && can('inventory.manage') && HubScope::allows($scope, (int) $t['from_hub_id']),
        ]);
    }

    public function receive(Request $request, string $id): string
    {
        return $this->done(TransferService::receive((int) $id, $request->body, AuthService::user()), (int) $id);
    }

    public function cancel(Request $request, string $id): string
    {
        return $this->done(TransferService::cancel((int) $id, (string) $request->input('reason', ''), AuthService::user()), (int) $id);
    }

    private function done(array $r, int $id): string
    {
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? (string) $r['message']);
        return redirect('/inventory/transfers/' . $id);
    }
}
