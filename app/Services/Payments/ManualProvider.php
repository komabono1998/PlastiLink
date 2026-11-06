<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Staff pay outside the system (mobile money app, cash at the hub) and record what they did here.
 * Mobile money needs the transaction ID; cash needs a receipt note. Reconciliation later checks it against the real statement.
 */
final class ManualProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'manual';
    }

    public function initiate(array $payment, array $input): ProviderResult
    {
        $ref = trim((string) ($input['provider_reference'] ?? ''));
        $note = trim((string) ($input['receipt_note'] ?? ''));
        if ($payment['method'] === 'mobile_money') {
            if (!preg_match('/^[A-Za-z0-9\-_.]{4,80}$/', $ref)) {
                return new ProviderResult('failed', null, 'Enter the mobile money transaction ID (4 to 80 letters or numbers).');
            }
            return new ProviderResult('successful', $ref, 'Mobile money payment recorded by staff.');
        }
        if (mb_strlen($note) < 3) {
            return new ProviderResult('failed', null, 'Enter a receipt note for the cash payment (who was paid, who witnessed).');
        }
        return new ProviderResult('successful', null, 'Cash payment recorded: ' . mb_substr($note, 0, 200));
    }

    public function status(array $payment, array $lastAttempt): ProviderResult
    {
        return new ProviderResult('failed', null, 'Manual payments are confirmed by staff and cannot be polled.');
    }
}
