<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Dates;
use App\Helpers\Money;
use App\Helpers\Weight;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Recycler;

/**
 * Recycler invoices and the money they pay.
 * An invoice is issued automatically when an order is complete: confirmed received kg x agreed price, whole UGX.
 * Money received is recorded by one person and confirmed by another before it counts.
 */
final class InvoiceService
{
    /** Invoice this user may see (recycler: own; staff: by hub scope; administrators: all), else null. */
    public static function findAccessible(int $id, array $actor): ?array
    {
        $i = Invoice::find($id);
        if (!$i) {
            return null;
        }
        if ($actor['role_slug'] === 'recycler') {
            $rec = Recycler::findByUserId((int) $actor['id']);
            return $rec && (int) $rec['id'] === (int) $i['recycler_id'] ? $i : null;
        }
        return StockInput::can($actor, 'invoices.view') && HubScope::allows(HubScope::forUser($actor), (int) $i['hub_id']) ? $i : null;
    }

    public static function outstanding(array $i): int
    {
        return $i['status'] === 'void' ? 0 : (int) $i['amount_ugx'] - (int) $i['paid_ugx'];
    }

    public static function isOverdue(array $i): bool
    {
        return in_array($i['status'], ['issued', 'partially_paid'], true) && $i['due_date'] < Dates::todayYmd();
    }

    /**
     * Issue the invoice for a completed order, once everything the recycler is going to confirm HAS been confirmed.
     * Safe to call repeatedly (an order is invoiced at most once). Returns the invoice id, or null if nothing to do.
     */
    public static function tryIssue(int $orderId, ?int $userId): ?int
    {
        return Database::atomic(function () use ($orderId, $userId): ?int {
            $o = PurchaseOrder::lock($orderId);
            $item = PurchaseOrder::lockItem($orderId);
            if (!$o || !$item || $o['status'] !== 'completed') {
                return null;
            }
            $exists = Database::connection()->prepare('SELECT id FROM invoices WHERE order_id = ?');
            $exists->execute([$orderId]);
            if ($exists->fetchColumn()) {
                return null;
            }
            $unconfirmed = (int) Database::connection()->query("SELECT COUNT(*) FROM stock_dispatches WHERE purchase_order_id = $orderId AND status = 'dispatched'")->fetchColumn();
            if ($unconfirmed > 0) {
                return null;     // wait until the recycler has confirmed every load
            }
            $kg = Weight::fromDecimal((string) $item['received_kg']);
            if ($kg <= 0) {
                return null;     // nothing arrived: nothing to bill
            }
            $price = (int) $item['price_per_kg_ugx'];
            $amount = Weight::value($kg, $price);
            if ($amount <= 0) {
                return null;
            }
            $due = (new \DateTimeImmutable(Dates::todayYmd()))->modify('+' . (int) config('app.finance.invoice_terms_days', 14) . ' days')->format('Y-m-d');
            $id = Invoice::insert(['order' => $orderId, 'recycler' => (int) $o['recycler_id'], 'hub' => (int) $o['hub_id'], 'kg' => Weight::toDecimal($kg), 'price' => $price, 'amount' => $amount, 'due' => $due]);
            $number = Invoice::find($id)['invoice_number'];
            LedgerService::post('recycler', (int) $o['recycler_id'], 'invoice_issued', $amount, 'invoice', $id, $number, 'Order ' . $o['reference'], $userId);
            AuditLog::write('invoice.issued', $userId, 'invoice', $id, ['order' => $orderId, 'amount' => $amount]);
            return $id;
        });
    }

    /** Issue invoices for completed orders that were finished before invoicing existed. @return int invoices created */
    public static function generateMissing(): int
    {
        $n = 0;
        foreach (Invoice::ordersMissingInvoice() as $orderId) {
            $n += self::tryIssue($orderId, null) ? 1 : 0;
        }
        return $n;
    }

    /** Recalculate an invoice's status from its successful payments. Call inside the transaction that changed them. */
    public static function refreshStatus(int $invoiceId): void
    {
        $inv = Invoice::lock($invoiceId);
        if (!$inv || $inv['status'] === 'void') {
            return;
        }
        $paid = Payment::receivedForInvoice($invoiceId);
        $status = $paid >= (int) $inv['amount_ugx'] ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued');
        if ($status !== $inv['status']) {
            Invoice::setStatus($invoiceId, $status);
        }
    }

    /** Void an invoice that has no money against it. */
    public static function void(int $id, ?string $reason, array $actor): array
    {
        if (!self::findAccessible($id, $actor)) {
            return ['error' => 'Invoice not found.'];
        }
        if (!StockInput::can($actor, 'invoices.manage')) {
            return ['error' => 'You do not have permission to void invoices.'];
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 5) {
            return ['error' => 'Give a clear reason (at least 5 characters) for voiding this invoice.'];
        }
        return Database::transaction(function () use ($id, $reason, $actor): array {
            $inv = Invoice::lock($id);
            if (!$inv || $inv['status'] !== 'issued') {
                return ['error' => 'Only an unpaid, issued invoice can be voided. Reverse any payments first.'];
            }
            $busy = Database::connection()->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND status IN ('pending','successful')");
            $busy->execute([$id]);
            if ((int) $busy->fetchColumn() > 0) {
                return ['error' => 'This invoice has payments recorded against it. Reverse or reject them first.'];
            }
            LedgerService::post('recycler', (int) $inv['recycler_id'], 'invoice_void', -(int) $inv['amount_ugx'], 'invoice', $id, $inv['invoice_number'], mb_substr($reason, 0, 255), (int) $actor['id']);
            Invoice::markVoid($id, (int) $actor['id'], mb_substr($reason, 0, 500));
            AuditLog::write('invoice.voided', (int) $actor['id'], 'invoice', $id, ['reason' => mb_substr($reason, 0, 200)]);
            return ['error' => null, 'message' => 'Invoice voided.'];
        });
    }

    /**
     * Record money received from a recycler against an invoice. It stays "pending" until a different person confirms it.
     * @return array{errors:array<string,string>, error:?string, id?:int, duplicate?:bool}
     */
    public static function recordReceipt(int $invoiceId, array $in, array $actor): array
    {
        $inv = self::findAccessible($invoiceId, $actor);
        if (!$inv) {
            return ['errors' => [], 'error' => 'Invoice not found.'];
        }
        if (!StockInput::can($actor, 'invoices.manage')) {
            return ['errors' => [], 'error' => 'You do not have permission to record payments.'];
        }
        $token = StockInput::token($in);
        if (!$token) {
            return ['errors' => [], 'error' => 'This form expired. Please reload the page and try again.'];
        }
        if ($dup = Payment::idByKey('rcp-' . $token)) {
            return ['errors' => [], 'error' => null, 'id' => $dup, 'duplicate' => true];
        }

        $errors = [];
        $amount = Money::parseUgx((string) ($in['amount_ugx'] ?? ''));
        if ($amount === null || $amount < 1) {
            $errors['amount_ugx'] = 'Enter the amount as a whole number of shillings.';
        }
        $method = (string) ($in['method'] ?? '');
        if (!in_array($method, ['mobile_money', 'bank', 'cash'], true)) {
            $errors['method'] = 'Choose how the money was received.';
        }
        $ref = trim((string) ($in['provider_reference'] ?? ''));
        if ($method !== 'cash' && $method !== '' && !preg_match('/^[A-Za-z0-9\-_.\/]{4,80}$/', $ref)) {
            $errors['provider_reference'] = 'Enter the transaction or bank reference (4 to 80 letters or numbers).';
        }
        $date = trim((string) ($in['received_on'] ?? ''));
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            $errors['received_on'] = 'Enter the date the money was received.';
        } elseif ($date > Dates::todayYmd()) {
            $errors['received_on'] = 'The date cannot be in the future.';
        }
        if ($errors) {
            return ['errors' => $errors, 'error' => null];
        }

        try {
            return Database::transaction(function () use ($invoiceId, $token, $amount, $method, $ref, $date, $actor): array {
                $row = Invoice::lock($invoiceId);
                if (!$row || !in_array($row['status'], ['issued', 'partially_paid'], true)) {
                    return ['errors' => [], 'error' => 'Payments can only be recorded against an open invoice.'];
                }
                $open = (int) $row['amount_ugx'] - Payment::receivedForInvoice($invoiceId) - Payment::pendingForInvoice($invoiceId);
                if ($amount > $open) {
                    return ['errors' => ['amount_ugx' => 'Only ' . format_ugx(max(0, $open)) . ' is still open on this invoice (counting payments waiting for confirmation).'], 'error' => null];
                }
                $id = Payment::insert(['key' => 'rcp-' . $token, 'direction' => 'in', 'recycler' => (int) $row['recycler_id'], 'invoice' => $invoiceId, 'amount' => $amount,
                    'method' => $method, 'provider' => 'manual', 'reference' => $ref !== '' ? $ref : null, 'status' => 'pending', 'received_on' => $date, 'by' => (int) $actor['id']]);
                AuditLog::write('receipt.recorded', (int) $actor['id'], 'payment', $id, ['invoice' => $invoiceId, 'amount' => $amount, 'method' => $method]);
                return ['errors' => [], 'error' => null, 'id' => $id];
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                if ($d2 = Payment::idByKey('rcp-' . $token)) {
                    return ['errors' => [], 'error' => null, 'id' => $d2, 'duplicate' => true];
                }
                return ['errors' => ['provider_reference' => 'That transaction reference has already been used for another payment.'], 'error' => null];
            }
            throw $e;
        }
    }

    /** confirm | reject a recorded receipt. The confirmer must be a different person from whoever recorded it. */
    public static function decideReceipt(int $paymentId, string $decision, ?string $note, array $actor): array
    {
        $p = PaymentService::findAccessible($paymentId, $actor);
        if (!$p || $p['direction'] !== 'in') {
            return ['error' => 'Payment not found.'];
        }
        if (!StockInput::can($actor, 'payments.approve')) {
            return ['error' => 'You do not have permission to confirm payments.'];
        }
        if (!in_array($decision, ['confirm', 'reject'], true)) {
            return ['error' => 'Unknown action.'];
        }
        $note = trim((string) $note);
        if ($decision === 'reject' && mb_strlen($note) < 3) {
            return ['error' => 'Give a short reason for rejecting this payment.'];
        }
        return Database::transaction(function () use ($paymentId, $decision, $note, $actor): array {
            $row = Payment::lock($paymentId);
            if (!$row || $row['status'] !== 'pending') {
                return ['error' => 'Only payments waiting for confirmation can be decided.'];
            }
            if ((int) $row['created_by'] === (int) $actor['id']) {
                return ['error' => 'You recorded this payment, so someone else must confirm it.'];
            }
            $inv = Invoice::lock((int) $row['invoice_id']);
            if ($decision === 'reject') {
                Payment::update($paymentId, ['status' => 'failed', 'failure_reason' => mb_substr($note, 0, 255), 'confirmed_by' => (int) $actor['id'], 'confirmed_at' => gmdate('Y-m-d H:i:s')]);
                AuditLog::write('receipt.rejected', (int) $actor['id'], 'payment', $paymentId, ['reason' => mb_substr($note, 0, 200)]);
                return ['error' => null, 'message' => 'Payment rejected. It does not count towards the invoice.'];
            }
            if (!$inv || !in_array($inv['status'], ['issued', 'partially_paid'], true)) {
                return ['error' => 'The invoice is no longer open for payments.'];
            }
            if ((int) $row['amount_ugx'] > (int) $inv['amount_ugx'] - Payment::receivedForInvoice((int) $row['invoice_id'])) {
                return ['error' => 'This payment is more than what is still owed on the invoice.'];
            }
            LedgerService::post('recycler', (int) $row['recycler_id'], 'invoice_payment', -(int) $row['amount_ugx'], 'payment', $paymentId, $row['reference'], 'Receipt ' . $row['method'], (int) $actor['id']);
            Payment::update($paymentId, ['status' => 'successful', 'confirmed_by' => (int) $actor['id'], 'confirmed_at' => gmdate('Y-m-d H:i:s'), 'processed_by' => (int) $row['created_by'], 'processed_at' => $row['created_at']]);
            self::refreshStatus((int) $row['invoice_id']);
            AuditLog::write('receipt.confirmed', (int) $actor['id'], 'payment', $paymentId, ['amount' => (int) $row['amount_ugx']]);
            return ['error' => null, 'message' => 'Payment confirmed and applied to the invoice.'];
        });
    }
}
