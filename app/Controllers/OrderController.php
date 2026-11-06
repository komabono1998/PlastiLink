<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Models\PlasticCategory;
use App\Models\PurchaseOrder;
use App\Models\StockDispatch;
use App\Services\AuthService;
use App\Services\HubScope;
use App\Services\OrderService;

final class OrderController extends Controller
{
    public function index(Request $request): string
    {
        $user = AuthService::user();
        PurchaseOrder::expireStale();
        [$side, $rec] = OrderService::sideOf($user);
        if ($side === null) {
            return ErrorHandler::renderError(403, $request);
        }
        $recyclerId = $side === 'recycler' ? (int) $rec['id'] : null;
        $hubScope = $side === 'seller' ? HubScope::forUser($user) : null;
        $f = [
            'q'        => trim((string) ($request->query['q'] ?? '')),
            'status'   => in_array($request->query['status'] ?? '', ['negotiating', 'confirmed', 'in_delivery', 'completed', 'rejected', 'withdrawn', 'cancelled', 'expired'], true) ? $request->query['status'] : '',
            'awaiting' => !empty($request->query['awaiting']) ? $side : '',
        ];
        $pg = Paginator::make(PurchaseOrder::count($f, $recyclerId, $hubScope), $request->query['page'] ?? 1);
        return $this->page($request, 'orders/index', $side === 'recycler' ? 'My orders' : 'Purchase orders', [
            'rows' => PurchaseOrder::paginate($f, $recyclerId, $hubScope, $pg['offset'], $pg['per']), 'pg' => $pg, 'f' => $f, 'side' => $side,
            'stats' => PurchaseOrder::stats($side, $recyclerId, $hubScope),
        ]);
    }

    public function create(Request $request): string
    {
        if (OrderService::sideOf(AuthService::user())[0] !== 'recycler') {
            return ErrorHandler::renderError(403, $request);
        }
        $stock = PurchaseOrder::available();
        $hubs = [];
        foreach ($stock as $s) {
            $hubs[$s['hub_id']] = $s['hub_name'];
        }
        return $this->page($request, 'orders/new', 'Make an offer', [
            'stock' => $stock, 'hubs' => $hubs, 'cats' => PlasticCategory::options(), 'token' => bin2hex(random_bytes(16)),
            'pre' => ['hub' => (int) ($request->query['hub'] ?? 0), 'cat' => (int) ($request->query['cat'] ?? 0)],
        ]);
    }

    public function store(Request $request): string
    {
        $r = OrderService::create($request->body, AuthService::user());
        if (!empty($r['duplicate'])) {
            Session::flash('info', 'This offer was already sent, so no second one was made.');
            return redirect('/orders/' . $r['id']);
        }
        if ($r['error']) {
            Session::flash('danger', $r['error']);
            return redirect('/orders/new');
        }
        if ($r['errors']) {
            return $this->invalid($r['errors'], $request->body, '/orders/new');
        }
        Session::flash('success', 'Offer sent to the hub. You will see their answer here.');
        return redirect('/orders/' . $r['id']);
    }

    public function show(Request $request, string $id): string
    {
        $user = AuthService::user();
        $o = OrderService::findAccessible((int) $id, $user);
        if (!$o) {
            return $this->notFound($request);
        }
        $dispatches = StockDispatch::forOrder((int) $id);
        $offers = PurchaseOrder::offers((int) $id);
        $latest = $offers ? end($offers) : null;
        return $this->page($request, 'orders/show', $o['reference'] ?? 'Order', [
            'o' => $o, 'offers' => $offers, 'latest' => $latest, 'dispatches' => $dispatches,
            'act' => OrderService::actions($o, $user), 'token' => bin2hex(random_bytes(16)),
            'maxRounds' => OrderService::MAX_ROUNDS,
        ]);
    }

    public function counter(Request $request, string $id): string
    {
        return $this->withForm(OrderService::counter((int) $id, $request->body, AuthService::user()), $request, (int) $id);
    }

    public function schedule(Request $request, string $id): string
    {
        return $this->withForm(OrderService::schedule((int) $id, $request->body, AuthService::user()), $request, (int) $id);
    }

    public function dispatch(Request $request, string $id): string
    {
        $r = OrderService::dispatch((int) $id, $request->body, AuthService::user());
        if (!empty($r['duplicate'])) {
            Session::flash('info', 'This load was already recorded, so no second one was made.');
            return redirect('/orders/' . (int) $id);
        }
        if (!$r['error'] && !$r['errors']) {
            $r['message'] = 'Load dispatched against this order. The recycler can now confirm what arrived.';
        }
        return $this->withForm($r, $request, (int) $id);
    }

    public function accept(Request $request, string $id): string
    {
        return $this->simple(OrderService::accept((int) $id, AuthService::user()), (int) $id);
    }

    public function end(Request $request, string $id): string
    {
        return $this->simple(OrderService::endNegotiation((int) $id, (string) $request->input('reason', ''), AuthService::user()), (int) $id);
    }

    public function cancel(Request $request, string $id): string
    {
        return $this->simple(OrderService::cancel((int) $id, (string) $request->input('reason', ''), AuthService::user()), (int) $id);
    }

    public function close(Request $request, string $id): string
    {
        return $this->simple(OrderService::closeShort((int) $id, (string) $request->input('note', ''), AuthService::user()), (int) $id);
    }

    public function receive(Request $request, string $id, string $did): string
    {
        return $this->simple(OrderService::receive((int) $id, (int) $did, $request->body, AuthService::user()), (int) $id);
    }

    /** Result with field errors (forms on the order page). */
    private function withForm(array $r, Request $request, int $id): string
    {
        if ($r['error']) {
            Session::flash('danger', $r['error']);
            return redirect('/orders/' . $id);
        }
        if ($r['errors']) {
            return $this->invalid($r['errors'], $request->body, '/orders/' . $id);
        }
        Session::flash('success', (string) ($r['message'] ?? 'Saved.'));
        return redirect('/orders/' . $id);
    }

    private function simple(array $r, int $id): string
    {
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? (string) $r['message']);
        return redirect('/orders/' . $id);
    }
}
