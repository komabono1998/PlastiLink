<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\Receipt;
use App\Services\AuthService;
use App\Services\HubScope;

final class ReceiptController extends Controller
{
    public function show(Request $request, string $id): string
    {
        $user = AuthService::user();
        $r = Receipt::find((int) $id);
        if (!$r) {
            return $this->notFound($request);
        }
        // Pickers: their own receipts only. Hub staff: their hubs only. Administration roles: all.
        if ($user['role_slug'] === 'waste_picker' && (int) $r['picker_user_id'] !== (int) $user['id']) {
            return $this->notFound($request);
        }
        if (!HubScope::allows(HubScope::forUser($user), (int) $r['hub_id'])) {
            return $this->notFound($request);
        }
        return $this->page($request, 'receipts/show', 'Receipt ' . $r['receipt_number'], ['r' => $r, 'd' => $r['data']]);
    }
}
