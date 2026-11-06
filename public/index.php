<?php
declare(strict_types=1);

/** PlastiLink front controller: every web request enters here. */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=utf-8');

Session::start();
$request = Request::capture();

// CSRF protection for every state-changing request. The only exemption: provider callbacks and USSD, which are not browser
// requests; they authenticate with a secret token in the URL (see WebhookService) and never act on the body alone.
$machine = $request->method === 'POST' && preg_match('#^/(webhooks/(mtn|airtel|sms)|ussd)/[^/]+$#', $request->path) === 1;
if (!$machine && in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $token = $request->body['_csrf'] ?? ($request->server['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!Session::verifyCsrf(is_string($token) ? $token : null)) {
        echo ErrorHandler::renderError(400, $request);
        exit;
    }
}

$router = new Router();
require BASE_PATH . '/routes/web.php';

echo $router->dispatch($request);
