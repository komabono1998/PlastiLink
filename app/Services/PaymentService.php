<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Services\Payments\ProviderFactory;
use App\Services\Payments\ProviderResult;

/**
 * Executing and reversing payments.
 * Status machine:  pending -> processing -> successful | failed;   failed -> (retry) processing/successful;   successful -> reversed.
 * A payment can only post to the ledger once (unique source key + row lock), and a provider reference can only be used once.
 */
final class PaymentService
{
    public const TRANSITIONS = [
        'pending'    => ['processing', 'successful', 'failed'],
        'processing' => ['successful', 'failed'],
        'failed'     => ['processing', 'successful'],
        'successful' => ['reversed'],
        'reversed'   => [],
    ];

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Payments this user may see: pickers their own, recyclers their own, staff by hub scope (administrators everything). */
    public static function findAccessible(int $id, array $actor): ?array
    {
        $p = Payment::find($id);
        if (!$p) {
            return null;
        }
        if ($actor['role_slug'] === 'waste_picker') {
            return $p['direction'] === 'out' && (int) $p['picker_user_id'] === (int) $actor['id'] ? $p : null;
        }
        if ($actor['role_slug'] === 'recycler') {
            $rec = \App\Models\Recycler::findByUserId((int) $actor['id']);
            return $rec && $p['direction'] === 'in' && (int) $p['recycler_id'] === (int) $rec['id'] ? $p : null;
        }
        if (!StockInput::can($actor, 'payments.view') && !StockInput::can($actor, 'invoices.view')) {
            return null;
        }
        $hub = $p['direction'] === 'out' ? $p['preferred_hub_id'] : $p['invoice_hub_id'];
        return HubScope::allows(HubScope::forUser($actor), $hub !== null ? (int) $hub : null) ? $p : null;
    }

    /** Execute a payout: the provider records or starts the payment. @return array{error:?string, message?:string} */
    public static function process(int $id, array $in, array $actor): array
    {
        $p = self::findAccessible($id, $actor);
        if (!$p || $p['direction'] !== 'out') {
            return ['error' => 'Payment not found.'];
        }
        if (!StockInput::can($actor, 'payments.process')) {
            return ['error' => 'You do not have permission to process payments.'];
        }
        try {
            return Database::transaction(function () use ($id, $in, $actor): array {
                $row = Payment::lock($id);
                if (!$row || !in_array($row['status'], ['pending', 'failed'], true)) {
                    return ['error' => 'Only pending or failed payments can be processed. This one is ' . ($row['status'] ?? 'missing') . '.'];
                }
                $req = PaymentRequest::lock((int) $row['request_id']);
                if (!$req || $req['status'] !== 'approved') {
                    return ['error' => 'The payout request behind this payment is no longer approved.'];
                }
                LedgerEntry::lockAccount('picker', (int) $row['picker_id']);
                if (LedgerService::balance('picker', (int) $row['picker_id']) < (int) $row['amount_ugx']) {
                    return ['error' => "The picker's balance no longer covers this payment (a collection may have been reversed)."];
                }
                $provider = ProviderFactory::make((string) $row['provider']);
                $result = $provider->initiate($row, $in);
                return self::applyResult($row, $result, (int) $actor['id']);
            });
        } catch (FinanceException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Staff mark a payment as failed (money did not go out). The request stays approved so it can be retried. */
    public static function fail(int $id, ?string $reason, array $actor): array
    {
        $p = self::findAccessible($id, $actor);
        if (!$p || $p['direction'] !== 'out') {
            return ['error' => 'Payment not found.'];
        }
        if (!StockInput::can($actor, 'payments.process')) {
            return ['error' => 'You do not have permission to process payments.'];
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 3) {
            return ['error' => 'Say why the payment failed.'];
        }
        return Database::transaction(function () use ($id, $reason, $actor): array {
            $row = Payment::lock($id);
            if (!$row || !in_array($row['status'], ['pending', 'processing'], true)) {
                return ['error' => 'Only pending or processing payments can be marked failed.'];
            }
            Payment::addAttempt($id, (string) $row['provider'], 'failed', $row['provider_reference'], $reason, (int) $actor['id']);
            Payment::update($id, ['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 255), 'processed_by' => (int) $actor['id'], 'processed_at' => gmdate('Y-m-d H:i:s')]);
            AuditLog::write('payment.failed', (int) $actor['id'], 'payment', $id, ['reason' => mb_substr($reason, 0, 200)]);
            return ['error' => null, 'message' => 'Payment marked as failed. It can be tried again.'];
        });
    }

    /** Apply a provider's answer to a locked payment row. Must run inside a transaction. */
    private static function applyResult(array $row, ProviderResult $r, ?int $userId): array
    {
        $id = (int) $row['id'];
        $provider = (string) $row['provider'];

        if ($r->status === 'failed') {
            Payment::addAttempt($id, $provider, 'failed', $r->reference, $r->message, $userId);
            Payment::update($id, ['status' => 'failed', 'failure_reason' => mb_substr((string) $r->message, 0, 255), 'processed_by' => $userId, 'processed_at' => gmdate('Y-m-d H:i:s')]);
            AuditLog::write('payment.failed', $userId, 'payment', $id, ['reason' => mb_substr((string) $r->message, 0, 200)]);
            return ['error' => $r->message ?? 'The payment failed.'];
        }

        if ($r->status === 'processing') {
            Payment::addAttempt($id, $provider, 'processing', $r->reference, $r->message, $userId);
            Payment::update($id, ['status' => 'processing', 'provider_reference' => $r->reference, 'failure_reason' => null]);
            AuditLog::write('payment.processing', $userId, 'payment', $id);
            return ['error' => null, 'message' => 'Payment sent to the provider. It is now processing.'];
        }

        // successful: one provider reference can only ever back one payment
        try {
            Payment::update($id, ['provider_reference' => $r->reference]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new FinanceException('That transaction reference has already been used for another payment.');
            }
            throw $e;
        }
        LedgerService::post('picker', (int) $row['picker_id'], 'payout', -(int) $row['amount_ugx'], 'payment', $id, $row['reference'], 'Payout ' . $row['method'], $userId);
        Payment::addAttempt($id, $provider, 'successful', $r->reference, $r->message, $userId);
        Payment::update($id, ['status' => 'successful', 'failure_reason' => null, 'processed_by' => $userId, 'processed_at' => gmdate('Y-m-d H:i:s')]);
        PaymentRequest::setStatus((int) $row['request_id'], 'paid');
        AuditLog::write('payment.successful', $userId, 'payment', $id, ['amount' => (int) $row['amount_ugx'], 'method' => $row['method']]);
        return ['error' => null, 'message' => 'Payment recorded as successful. The picker\'s balance has been reduced.'];
    }

    /** Ask the provider about payments left "processing" (run by `php jobs.php poll-payments`). @return int payments updated */
    public static function pollProcessing(): int
    {
        $n = 0;
        foreach (Payment::processingIds() as $id) {
            try {
                $done = Database::transaction(function () use ($id): bool {
                    $row = Payment::lock($id);
                    if (!$row || $row['status'] !== 'processing') {
                        return false;
                    }
                    $last = Payment::lastAttempt($id) ?? [];
                    $r = ProviderFactory::make((string) $row['provider'])->status($row, $last);
                    if ($r->status === 'processing') {
                        return false;
                    }
                    LedgerEntry::lockAccount('picker', (int) $row['picker_id']);
                    if ($r->status === 'successful' && LedgerService::balance('picker', (int) $row['picker_id']) < (int) $row['amount_ugx']) {
                        $r = new ProviderResult('failed', $r->reference, "The picker's balance no longer covers this payment.");
                    }
                    self::applyResult($row, $r, null);
                    return true;
                });
                $n += $done ? 1 : 0;
            } catch (FinanceException) {
                // leave it processing; staff can resolve it manually
            }
        }
        return $n;
    }

    /** Reverse a successful payment (payout or recycler receipt). Needs authority and a reason; posts an opposite ledger entry. */
    public static function reverse(int $id, ?string $reason, array $actor): array
    {
        if (!self::findAccessible($id, $actor)) {
            return ['error' => 'Payment not found.'];
        }
        if (!StockInput::can($actor, 'payments.reverse')) {
            return ['error' => 'You do not have permission to reverse payments.'];
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 5) {
            return ['error' => 'Give a clear reason (at least 5 characters) for reversing this payment.'];
        }
        return Database::transaction(function () use ($id, $reason, $actor): array {
            $row = Payment::lock($id);
            if (!$row || !self::canMove($row['status'], 'reversed')) {
                return ['error' => 'Only successful payments can be reversed.'];
            }
            if ($row['direction'] === 'out') {
                LedgerEntry::lockAccount('picker', (int) $row['picker_id']);
                LedgerService::post('picker', (int) $row['picker_id'], 'payout_reversal', (int) $row['amount_ugx'], 'payment', $id, $row['reference'], mb_substr($reason, 0, 255), (int) $actor['id']);
            } else {
                \App\Models\Invoice::lock((int) $row['invoice_id']);
                LedgerService::post('recycler', (int) $row['recycler_id'], 'invoice_payment_reversal', (int) $row['amount_ugx'], 'payment', $id, $row['reference'], mb_substr($reason, 0, 255), (int) $actor['id']);
            }
            Payment::update($id, ['status' => 'reversed', 'reversed_by' => (int) $actor['id'], 'reversed_at' => gmdate('Y-m-d H:i:s'), 'reversal_reason' => mb_substr($reason, 0, 500)]);
            if ($row['direction'] === 'in') {
                InvoiceService::refreshStatus((int) $row['invoice_id']);
            }
            AuditLog::write('payment.reversed', (int) $actor['id'], 'payment', $id, ['amount' => (int) $row['amount_ugx'], 'reason' => mb_substr($reason, 0, 200)]);
            return ['error' => null, 'message' => 'Payment reversed. An opposite entry was posted to the ledger.'];
        });
    }
}
