<?php
declare(strict_types=1);

/** @var App\Core\Router $router */

use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\StyleguideController;

$router->get('/', [HomeController::class, 'index']);
$router->get('/health', [HealthController::class, 'index']);

// Design-system reference page: only available outside production.
if (config('app.env') !== 'production') {
    $router->get('/styleguide', [StyleguideController::class, 'index']);
}
