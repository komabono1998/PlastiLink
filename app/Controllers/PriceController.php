<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Models\Hub;
use App\Models\PlasticCategory;
use App\Models\Price;
use App\Services\AuthService;
use App\Services\PriceService;

final class PriceController extends Controller
{
    public function index(Request $request): string
    {
        $pg = Paginator::make(Price::historyCount(), $request->query['page'] ?? 1, 15);
        return $this->page($request, 'prices/index', 'Prices', [
            'current' => Price::currentTable(), 'history' => Price::history($pg['offset'], $pg['per']), 'pg' => $pg,
            'cats' => PlasticCategory::options(), 'hubs' => Hub::options(),
        ]);
    }

    public function store(Request $request): string
    {
        $r = PriceService::set($request->body, AuthService::user());
        if ($r['errors']) {
            return $this->invalid($r['errors'], $request->body, '/prices');
        }
        Session::flash('success', 'New price saved. It applies to collections weighed from now on; earlier collections keep the price they were weighed at.');
        return redirect('/prices');
    }
}
