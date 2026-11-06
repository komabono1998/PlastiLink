<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Recycler;
use App\Services\AuthService;
use App\Services\HubScope;
use App\Services\InvoiceService;

final class InvoiceController extends Controller
{
    public function index(Request $request): string
    {
        $user = AuthService::user();
        $recyclerId = $user['role_slug'] === 'recycler' ? (int) (Recycler::findByUserId((int) $user['id'])['id'] ?? 0) : null;
        $scope = $recyclerId === null ? HubScope::forUser($user) : null;
        $f = [
            'q'      => trim((string) ($request->query['q'] ?? '')),
            'status' => in_array($request->query['status'] ?? '', ['issued', 'partially_paid', 'paid', 'void', 'overdue'], true) ? $request->query['status'] : '',
        ];
        $pg = Paginator::make(Invoice::count($f, $recyclerId, $scope), $request->query['page'] ?? 1);
        return $this->page($request, 'invoices/index', 'Invoices', [
            'rows' => Invoice::paginate($f, $recyclerId, $scope, $pg['offset'], $pg['per']), 'pg' => $pg, 'f' => $f, 'mine' => $recyclerId !== null,
        ]);
    }

    public function show(Request $request, string $id): string
    {
        $user = AuthService::user();
        $i = InvoiceService::findAccessible((int) $id, $user);
        if (!$i) {
            return $this->notFound($request);
        }
        $payments = Payment::paginate([], null, null, (int) $i['recycler_id'], 0, 100);
        $payments = array_values(array_filter($payments, static fn(array $p) => (int) $p['invoice_id'] === (int) $i['id']));
        return $this->page($request, 'invoices/show', $i['invoice_number'] ?? 'Invoice', [
            'i' => $i, 'payments' => $payments, 'outstanding' => InvoiceService::outstanding($i), 'overdue' => InvoiceService::isOverdue($i),
            'canRecord' => can('invoices.manage') && in_array($i['status'], ['issued', 'partially_paid'], true),
            'canVoid' => can('invoices.manage') && $i['status'] === 'issued',
            'token' => bin2hex(random_bytes(16)), 'role' => $user['role_slug'],
        ]);
    }

    public function record(Request $request, string $id): string
    {
        $r = InvoiceService::recordReceipt((int) $id, $request->body, AuthService::user());
        if (!empty($r['duplicate'])) {
            Session::flash('info', 'This payment was already recorded, so no second one was made.');
            return redirect('/payments/' . $r['id']);
        }
        if ($r['error']) {
            Session::flash('danger', $r['error']);
            return redirect('/invoices/' . (int) $id);
        }
        if ($r['errors']) {
            return $this->invalid($r['errors'], $request->body, '/invoices/' . (int) $id);
        }
        Session::flash('success', 'Payment recorded. It counts once a different person confirms it.');
        return redirect('/payments/' . $r['id']);
    }

    public function void(Request $request, string $id): string
    {
        $r = InvoiceService::void((int) $id, (string) $request->input('reason', ''), AuthService::user());
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? (string) $r['message']);
        return redirect('/invoices/' . (int) $id);
    }

    public function decideReceipt(Request $request, string $id): string
    {
        $r = InvoiceService::decideReceipt((int) $id, (string) $request->input('decision', ''), $request->input('note'), AuthService::user());
        Session::flash($r['error'] ? 'danger' : 'success', $r['error'] ?? (string) $r['message']);
        return redirect('/payments/' . (int) $id);
    }

    /** A recycler's account statement: invoices, payments and the balance, from the ledger. */
    public function statement(Request $request): string
    {
        $user = AuthService::user();
        $rec = $user['role_slug'] === 'recycler' ? Recycler::findByUserId((int) $user['id']) : null;
        if (!$rec) {
            return $this->notFound($request);
        }
        return $this->page($request, 'invoices/statement', 'Account statement', [
            'rec' => $rec, 'balance' => LedgerEntry::balance('recycler', (int) $rec['id']),
            'entries' => array_reverse(LedgerEntry::entries('recycler', (int) $rec['id'], 0, 300)),
        ]);
    }
}
