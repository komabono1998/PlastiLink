<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Dates;
use App\Helpers\Paginator;
use App\Models\Payment;
use App\Services\AuthService;
use App\Services\FinanceService;
use App\Services\ReconciliationService;

final class FinanceController extends Controller
{
    public function index(Request $request): string
    {
        return $this->page($request, 'finance/index', 'Finance', ['s' => FinanceService::summary(), 'problems' => FinanceService::verify()]);
    }

    public function reconciliation(Request $request): string
    {
        $from = (string) ($request->query['from'] ?? '');
        $to = (string) ($request->query['to'] ?? '');
        $fromR = $from !== '' ? Dates::dayRangeUtc($from) : null;
        $toR = $to !== '' ? Dates::dayRangeUtc($to) : null;
        $rec = in_array($request->query['reconciled'] ?? '0', ['0', '1', ''], true) ? (string) ($request->query['reconciled'] ?? '0') : '0';
        $f = ['status' => 'successful', 'reconciled' => $rec, 'from' => $fromR[0] ?? null, 'to' => $toR[1] ?? null,
              'direction' => in_array($request->query['direction'] ?? '', ['in', 'out'], true) ? $request->query['direction'] : ''];
        $pg = Paginator::make(Payment::count($f, null, null, null), $request->query['page'] ?? 1, 30);
        return $this->page($request, 'finance/reconciliation', 'Reconciliation', [
            'rows' => Payment::paginate($f, null, null, null, $pg['offset'], $pg['per']), 'pg' => $pg,
            'f' => $f + ['from_raw' => $fromR ? $from : '', 'to_raw' => $toR ? $to : ''],
            'summary' => ReconciliationService::summary($fromR[0] ?? null, $toR[1] ?? null),
        ]);
    }

    public function reconcile(Request $request): string
    {
        $ids = is_array($request->body['payment_ids'] ?? null) ? $request->body['payment_ids'] : [];
        $r = ReconciliationService::mark($ids, (string) $request->input('statement_reference', ''), AuthService::user());
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? ($r['count'] . ' payment(s) reconciled.'));
        return redirect('/finance/reconciliation');
    }
}
