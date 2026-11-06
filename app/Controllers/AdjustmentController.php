<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Models\Hub;
use App\Models\PlasticCategory;
use App\Models\StockAdjustment;
use App\Services\AdjustmentService;
use App\Services\AuthService;
use App\Services\HubScope;

final class AdjustmentController extends Controller
{
    public function index(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        $f = ['status' => in_array($request->query['status'] ?? '', ['pending', 'approved', 'rejected'], true) ? $request->query['status'] : ''];
        $pg = Paginator::make(StockAdjustment::count($f, $scope), $request->query['page'] ?? 1);
        return $this->page($request, 'inventory/adjustments/index', 'Stock adjustments', [
            'rows' => StockAdjustment::paginate($f, $scope, $pg['offset'], $pg['per']), 'pg' => $pg, 'f' => $f, 'tab' => 'adjustments',
        ]);
    }

    public function create(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        return $this->page($request, 'inventory/adjustments/new', 'Request a stock adjustment', [
            'hubs' => Hub::options($scope), 'cats' => PlasticCategory::options(), 'kinds' => AdjustmentService::KINDS,
            'token' => bin2hex(random_bytes(16)), 'tab' => 'adjustments',
        ]);
    }

    public function store(Request $request): string
    {
        $r = AdjustmentService::request($request->body, AuthService::user());
        if (!empty($r['duplicate'])) {
            Session::flash('info', 'This request was already submitted, so no second one was made.');
            return redirect('/inventory/adjustments/' . $r['id']);
        }
        if ($r['errors']) {
            if (isset($r['errors']['_'])) {
                Session::flash('danger', $r['errors']['_']);
                return redirect('/inventory/adjustments/new');
            }
            return $this->invalid($r['errors'], $request->body, '/inventory/adjustments/new');
        }
        Session::flash('success', 'Request submitted. Stock changes only after someone else approves it.');
        return redirect('/inventory/adjustments/' . $r['id']);
    }

    public function show(Request $request, string $id): string
    {
        $user = AuthService::user();
        $a = AdjustmentService::findAccessible((int) $id, $user);
        if (!$a) {
            return $this->notFound($request);
        }
        return $this->page($request, 'inventory/adjustments/show', $a['reference'] ?? 'Adjustment', [
            'a' => $a, 'tab' => 'adjustments', 'own' => (int) $a['requested_by'] === (int) $user['id'],
            'canDecide' => $a['status'] === 'pending' && can('inventory.approve'),
        ]);
    }

    public function decide(Request $request, string $id): string
    {
        $r = AdjustmentService::decide((int) $id, (string) $request->input('decision', ''), $request->input('note'), AuthService::user());
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? (string) $r['message']);
        return redirect('/inventory/adjustments/' . (int) $id);
    }
}
