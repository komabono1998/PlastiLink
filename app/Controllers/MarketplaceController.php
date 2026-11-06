<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorHandler;
use App\Core\Request;
use App\Models\PurchaseOrder;
use App\Services\AuthService;
use App\Services\OrderService;

final class MarketplaceController extends Controller
{
    /** Plastic available to buy (on hand minus reserved). Recyclers see availability only, never other buyers' orders. */
    public function index(Request $request): string
    {
        if (OrderService::sideOf(AuthService::user())[0] !== 'recycler') {
            return ErrorHandler::renderError(403, $request);
        }
        return $this->page($request, 'marketplace/index', 'Marketplace', ['stock' => PurchaseOrder::available()]);
    }
}
