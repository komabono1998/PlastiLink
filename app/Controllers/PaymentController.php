<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Dates;
use App\Helpers\Paginator;
use App\Models\Payment;
use App\Models\Recycler;
use App\Services\AuthService;
use App\Services\HubScope;
use App\Services\PaymentService;

final class PaymentController extends Controller
{
    public function index(Request $request): string
    {
        $user = AuthService::user();
        $role = $user['role_slug'];
        $pickerId = null;
        $recyclerId = null;
        $scope = null;
        if ($role === 'waste_picker') {
            $pickerId = (int) (\App\Services\PayoutService::pickerFor($user)['id'] ?? 0);
        } elseif ($role === 'recycler') {
            $recyclerId = (int) (Recycler::findByUserId((int) $user['id'])['id'] ?? 0);
        } else {
            $scope = HubScope::forUser($user);
        }
        $from = (string) ($request->query['from'] ?? '');
        $to = (string) ($request->query['to'] ?? '');
        $fromR = $from !== '' ? Dates::dayRangeUtc($from) : null;
        $toR = $to !== '' ? Dates::dayRangeUtc($to) : null;
        $f = [
            'q'         => trim((string) ($request->query['q'] ?? '')),
            'status'    => in_array($request->query['status'] ?? '', ['pending', 'processing', 'successful', 'failed', 'reversed'], true) ? $request->query['status'] : '',
            'direction' => in_array($request->query['direction'] ?? '', ['in', 'out'], true) ? $request->query['direction'] : '',
            'from'      => $fromR[0] ?? null, 'to' => $toR[1] ?? null,
        ];
        $pg = Paginator::make(Payment::count($f, $scope, $pickerId, $recyclerId), $request->query['page'] ?? 1);
        return $this->page($request, 'payments/index', 'Payments', [
            'rows' => Payment::paginate($f, $scope, $pickerId, $recyclerId, $pg['offset'], $pg['per']), 'pg' => $pg,
            'f' => $f + ['from_raw' => $fromR ? $from : '', 'to_raw' => $toR ? $to : ''], 'role' => $role,
        ]);
    }

    public function show(Request $request, string $id): string
    {
        $user = AuthService::user();
        $p = PaymentService::findAccessible((int) $id, $user);
        if (!$p) {
            return $this->notFound($request);
        }
        return $this->page($request, 'payments/show', $p['reference'] ?? 'Payment', [
            'p' => $p, 'attempts' => Payment::attempts((int) $id), 'provider' => (string) $p['provider'],
            'canProcess' => $p['direction'] === 'out' && in_array($p['status'], ['pending', 'failed'], true) && can('payments.process') && \App\Services\StockInput::isFinanceStaff($user),
            'canFail' => $p['direction'] === 'out' && in_array($p['status'], ['pending', 'processing'], true) && can('payments.process') && \App\Services\StockInput::isFinanceStaff($user),
            'canReverse' => $p['status'] === 'successful' && can('payments.reverse'),
            'canConfirm' => $p['direction'] === 'in' && $p['status'] === 'pending' && can('payments.approve') && (int) $p['created_by'] !== (int) $user['id'],
            'ownRecord' => (int) $p['created_by'] === (int) $user['id'],
        ]);
    }

    public function process(Request $request, string $id): string
    {
        return $this->done(PaymentService::process((int) $id, $request->body, AuthService::user()), (int) $id);
    }

    public function fail(Request $request, string $id): string
    {
        return $this->done(PaymentService::fail((int) $id, (string) $request->input('reason', ''), AuthService::user()), (int) $id);
    }

    public function reverse(Request $request, string $id): string
    {
        return $this->done(PaymentService::reverse((int) $id, (string) $request->input('reason', ''), AuthService::user()), (int) $id);
    }

    private function done(array $r, int $id): string
    {
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? (string) $r['message']);
        return redirect('/payments/' . $id);
    }
}
