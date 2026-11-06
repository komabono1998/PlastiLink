<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Helpers\Dates;
use App\Models\Collection;
use App\Models\Hub;
use App\Models\LoginAttempt;
use App\Models\Picker;
use App\Models\PurchaseOrder;
use App\Models\Recycler;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\AuthService;
use App\Services\HubScope;

final class DashboardController extends Controller
{
    /** /dashboard: send each role to its own home. */
    public function index(Request $request): string
    {
        return redirect(AuthService::homeFor(AuthService::user()['role_slug']));
    }

    public function admin(Request $request): string
    {
        $byStatus = User::countByStatus();
        $pickers = Picker::countByStatus();
        $recyclers = Recycler::countByStatus();
        $hubs = Hub::countByStatus();
        return $this->page($request, 'admin/dashboard', 'Dashboard', [
            'usersTotal'     => array_sum($byStatus),
            'usersActive'    => $byStatus['active'] ?? 0,
            'usersPending'   => $byStatus['pending'] ?? 0,
            'usersSuspended' => $byStatus['suspended'] ?? 0,
            'pickersTotal'   => array_sum($pickers),
            'pickersPending' => $pickers['pending'] ?? 0,
            'recyclersTotal' => array_sum($recyclers),
            'recyclersPending' => $recyclers['pending'] ?? 0,
            'hubsActive'     => $hubs['active'] ?? 0,
            'today'          => Collection::summary(null, null, ...(Dates::dayRangeUtc(Dates::todayYmd()) ?? [null, null])),
            'allTime'        => Collection::summary(null, null),
            'stock'          => StockBalance::totals(null),
            'orderStats'     => PurchaseOrder::stats('seller', null, null),
            'fin'            => can('finance.view') ? \App\Services\FinanceService::summary() : null,
            'transfersTransit' => StockTransfer::pendingReceipts(null),
            'adjustmentsPending' => StockAdjustment::pendingCount(null),
            'byRole'         => User::countByRole(),
            'failed24h'      => LoginAttempt::failedSince('24 HOUR'),
            'recentLogins'   => LoginAttempt::recentSuccessful(5),
        ]);
    }

    public function hub(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        $pickers = Picker::countByStatus($scope);
        return $this->page($request, 'hubs/dashboard', 'Hub dashboard', [
            'myHubs'         => Hub::list($scope),
            'pickersTotal'   => array_sum($pickers),
            'pickersPending' => $pickers['pending'] ?? 0,
            'today'          => Collection::summary($scope, null, ...(Dates::dayRangeUtc(Dates::todayYmd()) ?? [null, null])),
            'recent'         => Collection::paginate([], $scope, null, 0, 5),
            'stock'          => StockBalance::totals($scope),
            'orderStats'     => PurchaseOrder::stats('seller', null, $scope),
            'payoutsToPay'   => \App\Models\Payment::awaitingProcessCount($scope),
            'payoutsToApprove' => \App\Models\PaymentRequest::pendingApprovalCount($scope),
            'transfersWaiting' => StockTransfer::pendingReceipts($scope),
            'adjustmentsPending' => StockAdjustment::pendingCount($scope),
        ]);
    }

    public function picker(Request $request): string
    {
        $user = AuthService::user();
        $me = \App\Services\PayoutService::pickerFor($user);
        return $this->page($request, 'picker/dashboard', 'My dashboard', [
            'pos'     => $me ? \App\Services\PayoutService::position((int) $me['id']) : ['balance' => 0, 'held' => 0, 'available' => 0],
            'summary' => Collection::summary(null, (int) $user['id']),
            'recent'  => Collection::paginate([], null, (int) $user['id'], 0, 5),
        ]);
    }

    public function recycler(Request $request): string
    {
        $user = AuthService::user();
        PurchaseOrder::expireStale();
        $rec = Recycler::findByUserId((int) $user['id']);
        $rid = $rec ? (int) $rec['id'] : 0;
        return $this->page($request, 'recyclers/dashboard', 'Recycler dashboard', [
            'owing'  => $rid ? \App\Models\LedgerEntry::balance('recycler', $rid) : 0,
            'overdueInvoices' => $rid ? \App\Models\Invoice::count(['status' => 'overdue'], $rid, null) : 0,
            'stats'  => PurchaseOrder::stats('recycler', $rid, null),
            'recent' => PurchaseOrder::paginate([], $rid, null, 0, 6),
            'available' => count(PurchaseOrder::available()),
        ]);
    }

    /** Sidebar for the current user: only links that exist and that they may open. */
    public static function nav(Request $request): array
    {
        $user = AuthService::user();
        $home = AuthService::homeFor($user['role_slug']);
        $can = static fn(string ...$p): bool => (bool) array_intersect($p, AuthService::permissions());

        $defs = [
            [$home, 'speedometer2', 'Dashboard', true, true],
            ['/approvals', 'patch-check', 'Approvals', $can('pickers.manage', 'recyclers.approve'), false],
            ['/users', 'people', 'Users', $can('users.view'), false],
            ['/pickers', 'person-badge', 'Waste pickers', $can('pickers.view'), false],
            ['/collections', 'box-seam', $user['role_slug'] === 'waste_picker' ? 'My collections' : 'Collections', $can('collections.view'), false],
            ['/marketplace', 'shop-window', 'Marketplace', $user['role_slug'] === 'recycler' && $can('marketplace.browse'), false],
            ['/orders', 'receipt-cutoff', $user['role_slug'] === 'recycler' ? 'My orders' : 'Purchase orders', $can('orders.manage', 'orders.review'), false],
            ['/earnings', 'wallet2', 'My earnings', $user['role_slug'] === 'waste_picker' && $can('payments.view'), false],
            ['/payouts', 'cash-stack', $user['role_slug'] === 'waste_picker' ? 'My payout requests' : 'Payout requests', $user['role_slug'] !== 'recycler' && $can('payments.view'), false],
            ['/payments', 'credit-card', 'Payments', $can('payments.view', 'invoices.view') && $user['role_slug'] !== 'waste_picker', false],
            ['/invoices', 'file-earmark-text', 'Invoices', $can('invoices.view'), false],
            ['/statement', 'journal-richtext', 'Account statement', $user['role_slug'] === 'recycler', false],
            ['/finance', 'graph-up-arrow', 'Finance', $can('finance.view'), true],
            ['/finance/reconciliation', 'check2-square', 'Reconciliation', $can('payments.reconcile'), false],
            ['/inventory', 'boxes', 'Inventory', $can('inventory.view'), false],
            ['/prices', 'tag', 'Prices', $can('prices.view'), false],
            ['/hubs', 'shop', 'Collection hubs', $can('hubs.view'), false],
            ['/recyclers', 'building', 'Recyclers', $can('recyclers.approve', 'recyclers.manage'), false],
            ['/neighbourhoods', 'geo-alt', 'Neighbourhoods', $can('neighbourhoods.manage'), false],
            ['/admin/audit', 'journal-text', 'Audit trail', $can('audit.view'), false],
            ['/account/password', 'key', 'Change password', true, false],
        ];
        $items = [];
        foreach ($defs as [$path, $icon, $label, $show, $exact]) {
            if (!$show) {
                continue;
            }
            $active = $exact ? $request->path === $path : ($request->path === $path || str_starts_with($request->path, $path . '/'));
            $items[] = ['href' => url($path), 'icon' => $icon, 'label' => $label, 'active' => $active];
        }
        return $items;
    }
}
