<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorHandler;
use App\Core\Request;
use App\Helpers\Dates;
use App\Helpers\Paginator;
use App\Models\LedgerEntry;
use App\Models\PaymentRequest;
use App\Services\AuthService;
use App\Services\LedgerService;
use App\Services\PayoutService;

/** A picker's money: earned, paid, available, ledger and requests. Pickers see their own; staff see pickers in their scope. */
final class EarningsController extends Controller
{
    /** /earnings (picker's own account). */
    public function mine(Request $request): string
    {
        $user = AuthService::user();
        if ($user['role_slug'] !== 'waste_picker') {
            return \App\Services\StockInput::isFinanceStaff($user) ? redirect('/pickers') : $this->notFound($request);
        }
        $picker = PayoutService::pickerFor($user);
        return $picker ? $this->account($request, $picker, true) : $this->notFound($request);
    }

    /** /earnings/{pickerId} (staff). */
    public function show(Request $request, string $id): string
    {
        if (AuthService::user()['role_slug'] === 'waste_picker') {
            return $this->notFound($request);   // pickers only ever see their own account, at /earnings
        }
        $picker = PayoutService::pickerFor(AuthService::user(), (int) $id);
        return $picker ? $this->account($request, $picker, false) : $this->notFound($request);
    }

    public function statement(Request $request): string
    {
        $picker = PayoutService::pickerFor(AuthService::user());
        return $picker && AuthService::user()['role_slug'] === 'waste_picker' ? $this->printable($request, $picker) : $this->notFound($request);
    }

    public function staffStatement(Request $request, string $id): string
    {
        if (AuthService::user()['role_slug'] === 'waste_picker') {
            return $this->notFound($request);
        }
        $picker = PayoutService::pickerFor(AuthService::user(), (int) $id);
        return $picker ? $this->printable($request, $picker) : $this->notFound($request);
    }

    private function account(Request $request, array $picker, bool $own): string
    {
        $pid = (int) $picker['id'];
        $pg = Paginator::make(LedgerEntry::countEntries('picker', $pid), $request->query['page'] ?? 1, 15);
        return $this->page($request, 'earnings/show', $own ? 'My earnings' : 'Earnings: ' . $picker['full_name'], [
            'picker' => $picker, 'own' => $own, 'pos' => PayoutService::position($pid),
            'entries' => LedgerEntry::entries('picker', $pid, $pg['offset'], $pg['per']), 'pg' => $pg,
            'requests' => PaymentRequest::paginate([], null, $pid, 0, 8),
            'token' => bin2hex(random_bytes(16)), 'min' => (int) config('app.finance.payout_min_ugx'), 'max' => (int) config('app.finance.payout_max_ugx'),
            'canRequest' => $own ? can('payouts.request_own') : can('payouts.request'),
            'totals' => LedgerEntry::totalsByType('picker', $pid),
        ]);
    }

    private function printable(Request $request, array $picker): string
    {
        $pid = (int) $picker['id'];
        $from = (string) ($request->query['from'] ?? '');
        $to = (string) ($request->query['to'] ?? '');
        $fromR = $from !== '' ? Dates::dayRangeUtc($from) : null;
        $toR = $to !== '' ? Dates::dayRangeUtc($to) : null;
        $fromUtc = $fromR[0] ?? null;
        $toUtc = $toR[1] ?? null;
        $inPeriod = LedgerEntry::totalsByType('picker', $pid, $fromUtc, $toUtc);
        $opening = $fromUtc ? array_sum(LedgerEntry::totalsByType('picker', $pid, null, $fromUtc)) : 0;
        return $this->page($request, 'earnings/statement', 'Income statement', [
            'picker' => $picker, 'entries' => array_reverse(LedgerEntry::entries('picker', $pid, 0, 500, $fromUtc, $toUtc)),
            'earned' => ($inPeriod['collection_earning'] ?? 0) + ($inPeriod['collection_reversal'] ?? 0),
            'paid' => -(($inPeriod['payout'] ?? 0) + ($inPeriod['payout_reversal'] ?? 0)),
            'opening' => $opening, 'closing' => $opening + array_sum($inPeriod),
            'from_raw' => $fromR ? $from : '', 'to_raw' => $toR ? $to : '', 'statement_no' => 'STM-' . $picker['picker_code'] . '-' . str_replace('-', '', Dates::todayYmd()),
        ]);
    }
}
