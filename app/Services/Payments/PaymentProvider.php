<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * A way of moving money out to a picker. Implementations: ManualProvider (staff record what they paid),
 * MockProvider (development simulator). Phase 9 adds MTN MoMo and Airtel Money here without touching the rest of the system.
 */
interface PaymentProvider
{
    public function name(): string;

    /**
     * Start (or, for manual payments, record) the payment.
     * @param array $payment the payments row
     * @param array $input   provider-specific input from the staff member (e.g. transaction ID, receipt note)
     */
    public function initiate(array $payment, array $input): ProviderResult;

    /** Ask the provider what happened to a payment that was left "processing". */
    public function status(array $payment, array $lastAttempt): ProviderResult;
}
