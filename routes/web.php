<?php
declare(strict_types=1);

/** @var App\Core\Router $router */

use App\Controllers\AccountController;
use App\Controllers\ApprovalController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\CollectionController;
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
