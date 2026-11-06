<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Helpers\Dates;
use App\Helpers\Labels;
use App\Helpers\Paginator;
use App\Models\Hub;
use App\Models\StockBalance;
use App\Services\AuthService;
use App\Services\HubScope;

final class InventoryController extends Controller
{
    public function index(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        return $this->page($request, 'inventory/index', 'Inventory', [
            'balances'   => StockBalance::balances($scope),
            'mismatches' => StockBalance::mismatches(),
            'tab'        => 'balances',
        ]);
    }

    public function movements(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        $from = (string) ($request->query['from'] ?? '');
        $to = (string) ($request->query['to'] ?? '');
        $fromR = $from !== '' ? Dates::dayRangeUtc($from) : null;
        $toR = $to !== '' ? Dates::dayRangeUtc($to) : null;
        $f = [
            'hub_id' => (int) ($request->query['hub_id'] ?? 0) ?: '',
            'type'   => array_key_exists($request->query['type'] ?? '', Labels::movementTypes()) ? $request->query['type'] : '',
            'from'   => $fromR[0] ?? null,
            'to'     => $toR[1] ?? null,
        ];
        $pg = Paginator::make(StockBalance::countMovements($f, $scope), $request->query['page'] ?? 1, 25);
        return $this->page($request, 'inventory/movements', 'Stock movements', [
            'rows' => StockBalance::movements($f, $scope, $pg['offset'], $pg['per']), 'pg' => $pg,
            'f' => $f + ['from_raw' => $fromR ? $from : '', 'to_raw' => $toR ? $to : ''],
            'hubs' => Hub::options($scope), 'tab' => 'movements',
        ]);
    }
}
