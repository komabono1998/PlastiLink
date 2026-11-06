<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Services\AuthService;
use App\Services\HubScope;
use App\Services\PayoutService;
use App\Services\StockInput;

final class PayoutController extends Controller
{
    public function index(Request $request): string
    {
        $user = AuthService::user();
        $isPicker = $user['role_slug'] === 'waste_picker';
        if (!$isPicker && !StockInput::isFinanceStaff($user)) {
            return $this->notFound($request);
        }
        $picker = $isPicker ? PayoutService::pickerFor($user) : null;
        $f = [
            'q'      => trim((string) ($request->query['q'] ?? '')),
            'status' => in_array($request->query['status'] ?? '', ['pending_approval', 'approved', 'paid', 'rejected', 'cancelled'], true) ? $request->query['status'] : '',
        ];
        $scope = $isPicker ? null : HubScope::forUser($user);
        $pid = $isPicker ? (int) ($picker['id'] ?? 0) : null;
        $pg = Paginator::make(PaymentRequest::count($f, $scope, $pid), $request->query['page'] ?? 1);
        return $this->page($request, 'payouts/index', $isPicker ? 'My payout requests' : 'Payout requests', [
            'rows' => PaymentRequest::paginate($f, $scope, $pid, $pg['offset'], $pg['per']), 'pg' => $pg, 'f' => $f, 'isPicker' => $isPicker,
        ]);
    }

    public function store(Request $request): string
    {
        $r = PayoutService::request($request->body, AuthService::user());
        $back = AuthService::user()['role_slug'] === 'waste_picker' ? '/earnings' : '/earnings/' . (int) ($request->body['picker_id'] ?? 0);
        if (!empty($r['duplicate'])) {
            Session::flash('info', 'This request was already sent, so no second one was made.');
            return redirect('/payouts/' . $r['id']);
        }
        if ($r['error']) {
            Session::flash('danger', $r['error']);
            return redirect($back);
        }
        if ($r['errors']) {
            return $this->invalid($r['errors'], $request->body, $back);
        }
        Session::flash('success', 'Payout requested. It now waits for approval.');
        return redirect('/payouts/' . $r['id']);
    }

    public function show(Request $request, string $id): string
    {
        $user = AuthService::user();
        $q = PayoutService::requestAccessible((int) $id, $user);
        if (!$q) {
            return $this->notFound($request);
        }
        $payment = $q['payment_id'] ? Payment::find((int) $q['payment_id']) : null;
        return $this->page($request, 'payouts/show', $q['reference'] ?? 'Payout request', [
            'q' => $q, 'payment' => $payment, 'attempts' => $payment ? Payment::attempts((int) $payment['id']) : [],
            'canDecide' => $q['status'] === 'pending_approval' && can('payments.approve') && (int) $q['requested_by'] !== (int) $user['id'],
            'own' => (int) $q['requested_by'] === (int) $user['id'],
            'canCancel' => in_array($q['status'], ['pending_approval', 'approved'], true) && ((int) $q['requested_by'] === (int) $user['id'] || can('payments.approve')),
            'canProcess' => $payment && in_array($payment['status'], ['pending', 'failed'], true) && can('payments.process') && StockInput::isFinanceStaff($user),
            'provider' => (string) config('app.finance.provider', 'manual'),
        ]);
    }

    public function decision(Request $request, string $id): string
    {
        $r = PayoutService::decide((int) $id, (string) $request->input('decision', ''), $request->input('note'), AuthService::user());
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? (string) $r['message']);
        return redirect('/payouts/' . (int) $id);
    }

    public function cancel(Request $request, string $id): string
    {
        $r = PayoutService::cancel((int) $id, (string) $request->input('reason', ''), AuthService::user());
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? (string) $r['message']);
        return redirect('/payouts/' . (int) $id);
    }
}
