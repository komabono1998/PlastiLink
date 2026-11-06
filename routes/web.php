<?php
declare(strict_types=1);

/** @var App\Core\Router $router */

use App\Controllers\AccountController;
use App\Controllers\ApprovalController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\AdjustmentController;
use App\Controllers\CollectionController;
use App\Controllers\DispatchController;
use App\Controllers\EarningsController;
use App\Controllers\FinanceController;
use App\Controllers\InvoiceController;
use App\Controllers\PaymentController;
use App\Controllers\PayoutController;
use App\Controllers\InventoryController;
use App\Controllers\MarketplaceController;
use App\Controllers\OrderController;
use App\Controllers\TransferController;
use App\Controllers\PriceController;
use App\Controllers\ReceiptController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\HubController;
use App\Controllers\NeighbourhoodController;
use App\Controllers\PickerController;
use App\Controllers\RecyclerController;
use App\Controllers\RegistrationController;
use App\Controllers\ReportController;
use App\Controllers\WebhookController;
use App\Controllers\StyleguideController;
use App\Controllers\UserController;
use App\Middleware\AnyPermissionMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\RoleMiddleware;

// Public
$router->get('/', [HomeController::class, 'index']);
$router->get('/health', [HealthController::class, 'index']);

// Guests only
$guest = [new GuestMiddleware()];
$router->get('/login', [AuthController::class, 'showLogin'], $guest);
$router->post('/login', [AuthController::class, 'login'], $guest);
$router->get('/forgot-password', [AuthController::class, 'showForgot'], $guest);
$router->post('/forgot-password', [AuthController::class, 'forgot'], $guest);
$router->get('/reset-password', [AuthController::class, 'showReset'], $guest);
$router->post('/reset-password', [AuthController::class, 'reset'], $guest);

// Authenticated
$auth = new AuthMiddleware();
$router->post('/logout', [AuthController::class, 'logout'], [$auth]);
$router->get('/dashboard', [DashboardController::class, 'index'], [$auth]);

$router->get('/account/password', [AccountController::class, 'showPassword'], [$auth]);
$router->post('/account/password', [AccountController::class, 'changePassword'], [$auth]);

// Public self-registration (always lands as "pending")
$router->get('/register', [RegistrationController::class, 'showPicker'], $guest);
$router->post('/register', [RegistrationController::class, 'picker'], $guest);
$router->get('/register/recycler', [RegistrationController::class, 'showRecycler'], $guest);
$router->post('/register/recycler', [RegistrationController::class, 'recycler'], $guest);

// Administration: users
$viewUsers = [$auth, new PermissionMiddleware('users.view')];
$manageUsers = [$auth, new PermissionMiddleware('users.manage')];
$router->get('/users', [UserController::class, 'index'], $viewUsers);
$router->get('/users/create', [UserController::class, 'create'], $manageUsers);
$router->post('/users', [UserController::class, 'store'], $manageUsers);
$router->get('/users/{id:\d+}', [UserController::class, 'show'], $viewUsers);
$router->get('/users/{id:\d+}/edit', [UserController::class, 'edit'], $manageUsers);
$router->post('/users/{id:\d+}', [UserController::class, 'update'], $manageUsers);
$router->post('/users/{id:\d+}/status', [UserController::class, 'status'], $manageUsers);
$router->post('/users/{id:\d+}/reset-password', [UserController::class, 'resetPassword'], $manageUsers);

// Neighbourhoods
$nb = [$auth, new PermissionMiddleware('neighbourhoods.manage')];
$router->get('/neighbourhoods', [NeighbourhoodController::class, 'index'], $nb);
$router->post('/neighbourhoods', [NeighbourhoodController::class, 'store'], $nb);
$router->post('/neighbourhoods/{id:\d+}', [NeighbourhoodController::class, 'update'], $nb);

// Hubs (hub staff only ever see their own hubs: enforced in the controller via HubScope)
$viewHubs = [$auth, new PermissionMiddleware('hubs.view')];
$manageHubs = [$auth, new PermissionMiddleware('hubs.manage')];
$router->get('/hubs', [HubController::class, 'index'], $viewHubs);
$router->get('/hubs/create', [HubController::class, 'create'], $manageHubs);
$router->post('/hubs', [HubController::class, 'store'], $manageHubs);
$router->get('/hubs/{id:\d+}', [HubController::class, 'show'], $viewHubs);
$router->get('/hubs/{id:\d+}/edit', [HubController::class, 'edit'], $manageHubs);
$router->post('/hubs/{id:\d+}', [HubController::class, 'update'], $manageHubs);
$router->post('/hubs/{id:\d+}/assign', [HubController::class, 'assign'], $manageHubs);
$router->post('/hubs/{id:\d+}/unassign', [HubController::class, 'unassign'], $manageHubs);

// Waste pickers (staff screens)
$router->get('/pickers', [PickerController::class, 'index'], [$auth, new PermissionMiddleware('pickers.view')]);
$router->get('/pickers/register', [PickerController::class, 'create'], [$auth, new PermissionMiddleware('pickers.register')]);
$router->post('/pickers', [PickerController::class, 'store'], [$auth, new PermissionMiddleware('pickers.register')]);
$router->get('/pickers/{id:\d+}', [PickerController::class, 'show'], [$auth, new PermissionMiddleware('pickers.view')]);
$router->post('/pickers/{id:\d+}/decision', [PickerController::class, 'decide'], [$auth, new PermissionMiddleware('pickers.manage')]);

// Prices (history is append-only)
$router->get('/prices', [PriceController::class, 'index'], [$auth, new PermissionMiddleware('prices.view')]);
$router->post('/prices', [PriceController::class, 'store'], [$auth, new PermissionMiddleware('prices.manage')]);

// Collections: hub staff record and weigh; a different person approves; pickers see only their own
$router->get('/pickers/search', [PickerController::class, 'search'], [$auth, new PermissionMiddleware('collections.record')]);
$viewCol = [$auth, new PermissionMiddleware('collections.view')];
$recordCol = [$auth, new PermissionMiddleware('collections.record')];
$router->get('/collections', [CollectionController::class, 'index'], $viewCol);
$router->get('/collections/new', [CollectionController::class, 'create'], $recordCol);
$router->post('/collections', [CollectionController::class, 'store'], $recordCol);
$router->get('/collections/{id:\d+}', [CollectionController::class, 'show'], $viewCol);
$router->post('/collections/{id:\d+}/reweigh', [CollectionController::class, 'reweigh'], $recordCol);
$router->post('/collections/{id:\d+}/approve', [CollectionController::class, 'approve'], [$auth, new PermissionMiddleware('collections.approve')]);
$router->post('/collections/{id:\d+}/reject', [CollectionController::class, 'reject'], [$auth, new PermissionMiddleware('collections.approve')]);
$router->post('/collections/{id:\d+}/reverse', [CollectionController::class, 'reverse'], [$auth, new PermissionMiddleware('collections.reverse')]);
$router->get('/receipts/{id:\d+}', [ReceiptController::class, 'show'], $viewCol);

// Inventory: a ledger of movements (stock is never edited directly)
$viewInv = [$auth, new PermissionMiddleware('inventory.view')];
$manageInv = [$auth, new PermissionMiddleware('inventory.manage')];
$router->get('/inventory', [InventoryController::class, 'index'], $viewInv);
$router->get('/inventory/movements', [InventoryController::class, 'movements'], $viewInv);

$router->get('/inventory/transfers', [TransferController::class, 'index'], $viewInv);
$router->get('/inventory/transfers/new', [TransferController::class, 'create'], $manageInv);
$router->post('/inventory/transfers', [TransferController::class, 'store'], $manageInv);
$router->get('/inventory/transfers/{id:\d+}', [TransferController::class, 'show'], $viewInv);
$router->post('/inventory/transfers/{id:\d+}/receive', [TransferController::class, 'receive'], $manageInv);
$router->post('/inventory/transfers/{id:\d+}/cancel', [TransferController::class, 'cancel'], $manageInv);

$router->get('/inventory/dispatches', [DispatchController::class, 'index'], $viewInv);
$router->get('/inventory/dispatches/new', [DispatchController::class, 'create'], $manageInv);
$router->post('/inventory/dispatches', [DispatchController::class, 'store'], $manageInv);
$router->get('/inventory/dispatches/{id:\d+}', [DispatchController::class, 'show'], $viewInv);
$router->post('/inventory/dispatches/{id:\d+}/delivered', [DispatchController::class, 'delivered'], $manageInv);
$router->post('/inventory/dispatches/{id:\d+}/cancel', [DispatchController::class, 'cancel'], $manageInv);

$router->get('/inventory/adjustments', [AdjustmentController::class, 'index'], $viewInv);
$router->get('/inventory/adjustments/new', [AdjustmentController::class, 'create'], $manageInv);
$router->post('/inventory/adjustments', [AdjustmentController::class, 'store'], $manageInv);
$router->get('/inventory/adjustments/{id:\d+}', [AdjustmentController::class, 'show'], $viewInv);
$router->post('/inventory/adjustments/{id:\d+}/decision', [AdjustmentController::class, 'decide'], [$auth, new PermissionMiddleware('inventory.approve')]);

// Marketplace and purchase orders (OrderService decides which side the user is on; the routes only require "some marketplace permission")
$router->get('/marketplace', [MarketplaceController::class, 'index'], [$auth, new PermissionMiddleware('marketplace.browse')]);
$anyOrder = [$auth, new AnyPermissionMiddleware('orders.manage', 'orders.review')];
$router->get('/orders', [OrderController::class, 'index'], $anyOrder);
$router->get('/orders/new', [OrderController::class, 'create'], [$auth, new PermissionMiddleware('orders.manage')]);
$router->post('/orders', [OrderController::class, 'store'], [$auth, new PermissionMiddleware('orders.manage')]);
$router->get('/orders/{id:\d+}', [OrderController::class, 'show'], $anyOrder);
foreach (['counter' => 'counter', 'accept' => 'accept', 'end' => 'end', 'schedule' => 'schedule', 'cancel' => 'cancel', 'close' => 'close', 'dispatch' => 'dispatch'] as $path => $method) {
    $router->post('/orders/{id:\d+}/' . $path, [OrderController::class, $method], $anyOrder);
}
$router->post('/orders/{id:\d+}/receive/{did:\d+}', [OrderController::class, 'receive'], $anyOrder);

// Money: earnings, payouts, payments, invoices, reconciliation (services decide who may see which record)
$viewPay = [$auth, new PermissionMiddleware('payments.view')];
$router->get('/earnings', [EarningsController::class, 'mine'], $viewPay);
$router->get('/earnings/statement', [EarningsController::class, 'statement'], $viewPay);
$router->get('/earnings/{id:\d+}', [EarningsController::class, 'show'], $viewPay);
$router->get('/earnings/{id:\d+}/statement', [EarningsController::class, 'staffStatement'], $viewPay);
$router->get('/payouts', [PayoutController::class, 'index'], $viewPay);
$router->post('/payouts', [PayoutController::class, 'store'], [$auth, new AnyPermissionMiddleware('payouts.request', 'payouts.request_own')]);
$router->get('/payouts/{id:\d+}', [PayoutController::class, 'show'], $viewPay);
$router->post('/payouts/{id:\d+}/decision', [PayoutController::class, 'decision'], [$auth, new PermissionMiddleware('payments.approve')]);
$router->post('/payouts/{id:\d+}/cancel', [PayoutController::class, 'cancel'], $viewPay);

$anyMoney = [$auth, new AnyPermissionMiddleware('payments.view', 'invoices.view')];
$router->get('/payments', [PaymentController::class, 'index'], $anyMoney);
$router->get('/payments/{id:\d+}', [PaymentController::class, 'show'], $anyMoney);
$router->post('/payments/{id:\d+}/process', [PaymentController::class, 'process'], [$auth, new PermissionMiddleware('payments.process')]);
$router->post('/payments/{id:\d+}/fail', [PaymentController::class, 'fail'], [$auth, new PermissionMiddleware('payments.process')]);
$router->post('/payments/{id:\d+}/reverse', [PaymentController::class, 'reverse'], [$auth, new PermissionMiddleware('payments.reverse')]);
$router->post('/payments/{id:\d+}/receipt-decision', [InvoiceController::class, 'decideReceipt'], [$auth, new PermissionMiddleware('payments.approve')]);

$viewInv = [$auth, new PermissionMiddleware('invoices.view')];
$router->get('/invoices', [InvoiceController::class, 'index'], $viewInv);
$router->get('/invoices/{id:\d+}', [InvoiceController::class, 'show'], $viewInv);
$router->post('/invoices/{id:\d+}/payment', [InvoiceController::class, 'record'], [$auth, new PermissionMiddleware('invoices.manage')]);
$router->post('/invoices/{id:\d+}/void', [InvoiceController::class, 'void'], [$auth, new PermissionMiddleware('invoices.manage')]);
$router->get('/statement', [InvoiceController::class, 'statement'], $viewInv);

$router->get('/finance', [FinanceController::class, 'index'], [$auth, new PermissionMiddleware('finance.view')]);
$router->get('/finance/reconciliation', [FinanceController::class, 'reconciliation'], [$auth, new PermissionMiddleware('payments.reconcile')]);
$router->post('/finance/reconciliation', [FinanceController::class, 'reconcile'], [$auth, new PermissionMiddleware('payments.reconcile')]);

// Provider callbacks and USSD: no session or CSRF; the secret token in the URL is the credential (see WebhookService)
$router->post('/webhooks/mtn/{token}', [WebhookController::class, 'mtn']);
$router->post('/webhooks/airtel/{token}', [WebhookController::class, 'airtel']);
$router->post('/webhooks/sms/{token}', [WebhookController::class, 'sms']);
$router->post('/ussd/{token}', [WebhookController::class, 'ussd']);

// Reports and exports (ReportService decides which reports each role may open; Hub Managers only get their own hubs)
$router->get('/reports', [ReportController::class, 'index'], [$auth, new PermissionMiddleware('reports.view')]);
$router->get('/reports/{key:[a-z]+}', [ReportController::class, 'show'], [$auth, new PermissionMiddleware('reports.view')]);
$router->get('/reports/{key:[a-z]+}/export/{format:csv|xlsx|pdf}', [ReportController::class, 'export'], [$auth, new PermissionMiddleware('reports.view', 'reports.export')]);

// Recyclers
$anyRecycler = [$auth, new AnyPermissionMiddleware('recyclers.approve', 'recyclers.manage')];
$router->get('/recyclers', [RecyclerController::class, 'index'], $anyRecycler);
$router->get('/recyclers/create', [RecyclerController::class, 'create'], [$auth, new PermissionMiddleware('recyclers.manage')]);
$router->post('/recyclers', [RecyclerController::class, 'store'], [$auth, new PermissionMiddleware('recyclers.manage')]);
$router->get('/recyclers/{id:\d+}', [RecyclerController::class, 'show'], $anyRecycler);
$router->post('/recyclers/{id:\d+}/decision', [RecyclerController::class, 'decide'], [$auth, new PermissionMiddleware('recyclers.approve')]);

// Approval queue (pickers for hub managers / admins, recyclers for admins and project managers)
$router->get('/approvals', [ApprovalController::class, 'index'], [$auth, new AnyPermissionMiddleware('pickers.manage', 'recyclers.approve')]);

// Role dashboards: every route is protected on the server, not just hidden in the menu.
$router->get('/admin', [DashboardController::class, 'admin'], [$auth, new RoleMiddleware('super_admin', 'project_manager')]);
$router->get('/admin/audit', [AuditController::class, 'index'], [$auth, new PermissionMiddleware('audit.view')]);
$router->get('/hub', [DashboardController::class, 'hub'], [$auth, new RoleMiddleware('hub_manager', 'hub_operator')]);
$router->get('/picker', [DashboardController::class, 'picker'], [$auth, new RoleMiddleware('waste_picker')]);
$router->get('/recycler', [DashboardController::class, 'recycler'], [$auth, new RoleMiddleware('recycler')]);

// Design-system reference page: only available outside production.
if (config('app.env') !== 'production') {
    $router->get('/styleguide', [StyleguideController::class, 'index']);
}
