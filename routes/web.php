<?php
declare(strict_types=1);

/** @var App\Core\Router $router */

use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\StyleguideController;
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
