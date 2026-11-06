<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Money;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Picker;
use App\Models\User;
use App\Services\Payments\ProviderFactory;

/** Picker payouts: request -> approval (a different person) -> payment. Balance comes from the ledger, never from a stored number. */
final class PayoutService
{
    /** @return array{balance:int, held:int, available:int} */
    public static function position(int $pickerId): array
    {
        $balance = LedgerService::balance('picker', $pickerId);
        $held = PaymentRequest::holds($pickerId);
        return ['balance' => $balance, 'held' => $held, 'available' => max(0, $balance - $held)];
    }

    /**
     * The picker whose money this user may see: pickers see their own account, staff see pickers at their hubs
     * (administrators see everyone). Null if none.
     */
    public static function pickerFor(array $actor, ?int $pickerId = null): ?array
    {
        if ($actor['role_slug'] === 'waste_picker') {
            return Picker::findByUserId((int) $actor['id']);
        }
        if (!StockInput::isFinanceStaff($actor) || !StockInput::can($actor, 'payments.view') || $pickerId === null) {
            return null;
        }
        return PickerService::findAccessible($pickerId, $actor);
    }

    public static function requestAccessible(int $id, array $actor): ?array
    {
        $q = PaymentRequest::find($id);
        if (!$q) {
            return null;
        }
        if ($actor['role_slug'] === 'waste_picker') {
            return (int) $q['picker_user_id'] === (int) $actor['id'] ? $q : null;
        }
        return StockInput::isFinanceStaff($actor) && StockInput::can($actor, 'payments.view') && HubScope::allows(HubScope::forUser($actor), $q['preferred_hub_id'] !== null ? (int) $q['preferred_hub_id'] : null) ? $q : null;
    }

    /** @return array{errors:array<string,string>, error:?string, id?:int, duplicate?:bool} */
    public static function request(array $in, array $actor): array
    {
        $token = StockInput::token($in);
        if (!$token) {
            return ['errors' => [], 'error' => 'This form expired. Please reload the page and try again.'];
        }
        if ($dup = PaymentRequest::idByToken($token)) {
            return ['errors' => [], 'error' => null, 'id' => $dup, 'duplicate' => true];
        }
        $own = $actor['role_slug'] === 'waste_picker';
        if (!StockInput::can($actor, $own ? 'payouts.request_own' : 'payouts.request')) {
            return ['errors' => [], 'error' => 'You do not have permission to request payouts.'];
        }
        $picker = self::pickerFor($actor, $own ? null : (int) ($in['picker_id'] ?? 0));
        if (!$picker) {
            return ['errors' => [], 'error' => 'Picker not found.'];
        }
        if ($picker['account_status'] !== 'active') {
            return ['errors' => [], 'error' => "This picker's account is not active."];
        }

        $errors = [];
        $min = (int) config('app.finance.payout_min_ugx', 1000);
        $max = (int) config('app.finance.payout_max_ugx', 5000000);
        $amount = Money::parseUgx((string) ($in['amount_ugx'] ?? ''));
        if ($amount === null) {
            $errors['amount_ugx'] = 'Enter the amount as a whole number of shillings.';
        } elseif ($amount < $min || $amount > $max) {
            $errors['amount_ugx'] = 'The amount must be between ' . format_ugx($min) . ' and ' . format_ugx($max) . '.';
        }
        $method = (string) ($in['method'] ?? '');
        if (!in_array($method, ['cash', 'mobile_money'], true)) {
            $errors['method'] = 'Choose how the money will be paid.';
        }
        $phone = null;
        if ($method === 'mobile_money') {
            $phone = User::findById((int) $picker['user_id'])['phone_e164'] ?? null;
            if (!$phone) {
                $errors['method'] = 'This picker has no phone number on record for mobile money. Choose cash, or add a phone number first.';
            }
        }
        if ($errors) {
            return ['errors' => $errors, 'error' => null];
        }

        try {
            return Database::transaction(function () use ($token, $picker, $amount, $method, $phone, $actor): array {
                LedgerEntry::lockAccount('picker', (int) $picker['id']);     // one request at a time per picker
                $pos = self::position((int) $picker['id']);
                if ($amount > $pos['available']) {
                    return ['errors' => ['amount_ugx' => 'Only ' . format_ugx($pos['available']) . ' is available to pay out'
                        . ($pos['held'] > 0 ? ' (' . format_ugx($pos['held']) . ' is already in other requests).' : '.')], 'error' => null];
                }
                $id = PaymentRequest::insert(['token' => $token, 'picker' => (int) $picker['id'], 'amount' => $amount, 'method' => $method, 'phone' => $phone, 'by' => (int) $actor['id']]);
                AuditLog::write('payout.requested', (int) $actor['id'], 'payout_request', $id, ['picker' => (int) $picker['id'], 'amount' => $amount, 'method' => $method]);
                return ['errors' => [], 'error' => null, 'id' => $id];
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && ($d = PaymentRequest::idByToken($token))) {
                return ['errors' => [], 'error' => null, 'id' => $d, 'duplicate' => true];
            }
            throw $e;
        }
    }

    /** approve | reject (approver must differ from requester). Approval creates the payment in "pending". */
    public static function decide(int $id, string $decision, ?string $note, array $actor): array
    {
        if (!self::requestAccessible($id, $actor)) {
            return ['error' => 'Payout request not found.'];
        }
        if (!StockInput::can($actor, 'payments.approve')) {
            return ['error' => 'You do not have permission to approve payouts.'];
        }
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return ['error' => 'Unknown action.'];
        }
        $note = trim((string) $note);
        if ($decision === 'reject' && mb_strlen($note) < 3) {
            return ['error' => 'Give a short reason for rejecting this request.'];
        }

        return Database::transaction(function () use ($id, $decision, $note, $actor): array {
            $q = PaymentRequest::lock($id);
            if (!$q || $q['status'] !== 'pending_approval') {
                return ['error' => 'Only requests waiting for approval can be decided.'];
            }
            if ((int) $q['requested_by'] === (int) $actor['id']) {
                return ['error' => 'You made this request, so someone else must approve it.'];
            }
            if ($decision === 'reject') {
                PaymentRequest::decide($id, 'rejected', (int) $actor['id'], mb_substr($note, 0, 255));
                AuditLog::write('payout.rejected', (int) $actor['id'], 'payout_request', $id, ['reason' => mb_substr($note, 0, 200)]);
                return ['error' => null, 'message' => 'Request rejected.'];
            }

            LedgerEntry::lockAccount('picker', (int) $q['picker_id']);
            if (LedgerService::balance('picker', (int) $q['picker_id']) < PaymentRequest::holds((int) $q['picker_id'])) {
                return ['error' => "The picker's balance no longer covers their pending requests (a collection may have been reversed). Reject this request or ask for a smaller one."];
            }
            PaymentRequest::decide($id, 'approved', (int) $actor['id'], $note !== '' ? mb_substr($note, 0, 255) : null);
            $payId = Payment::insert(['key' => 'payout-' . $id, 'direction' => 'out', 'picker' => (int) $q['picker_id'], 'request' => $id, 'amount' => (int) $q['amount_ugx'],
                'method' => $q['method'], 'provider' => ProviderFactory::forMethod((string) $q['method'])->name(), 'phone' => $q['destination_phone'], 'status' => 'pending', 'by' => (int) $actor['id']]);
            AuditLog::write('payout.approved', (int) $actor['id'], 'payout_request', $id, ['payment' => $payId, 'amount' => (int) $q['amount_ugx']]);
            return ['error' => null, 'message' => 'Payout approved. It is now waiting to be paid.'];
        });
    }

    /** Cancel a request before it has been paid (by the requester or an approver). */
    public static function cancel(int $id, ?string $reason, array $actor): array
    {
        $q = self::requestAccessible($id, $actor);
        if (!$q) {
            return ['error' => 'Payout request not found.'];
        }
        $isRequester = (int) $q['requested_by'] === (int) $actor['id'];
        if (!$isRequester && !StockInput::can($actor, 'payments.approve')) {
            return ['error' => 'Only the person who made the request, or an approver, can cancel it.'];
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 3) {
            return ['error' => 'Give a short reason for cancelling.'];
        }
        return Database::transaction(function () use ($id, $reason, $actor): array {
            $q = PaymentRequest::lock($id);
            if (!$q || !in_array($q['status'], ['pending_approval', 'approved'], true)) {
                return ['error' => 'Only requests that have not been paid can be cancelled.'];
            }
            $pay = Database::connection()->prepare('SELECT id, status FROM payments WHERE request_id = ? FOR UPDATE');
            $pay->execute([$id]);
            if ($p = $pay->fetch()) {
                if (!in_array($p['status'], ['pending', 'failed'], true)) {
                    return ['error' => 'This payment is already being processed or paid, so the request cannot be cancelled.'];
                }
                Payment::update((int) $p['id'], ['status' => 'failed', 'failure_reason' => 'Request cancelled: ' . mb_substr($reason, 0, 200)]);
            }
            PaymentRequest::setStatus($id, 'cancelled');
            AuditLog::write('payout.cancelled', (int) $actor['id'], 'payout_request', $id, ['reason' => mb_substr($reason, 0, 200)]);
            return ['error' => null, 'message' => 'Request cancelled.'];
        });
    }
}
