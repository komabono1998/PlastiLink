<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Development simulator: no money moves. initiate() leaves the payment "processing"; status() then reports success,
 * or failure when the amount is a multiple of 13 (so failure paths can be demonstrated). Refuses to run in production.
 */
final class MockProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function initiate(array $payment, array $input): ProviderResult
    {
        if (config('app.env') === 'production') {
            return new ProviderResult('failed', null, 'The mock payment provider is disabled in production.');
        }
        return new ProviderResult('processing', 'MOCK-' . strtoupper(bin2hex(random_bytes(4))), 'Simulated payment started.');
    }

    public function status(array $payment, array $lastAttempt): ProviderResult
    {
        if ((int) $payment['amount_ugx'] % 13 === 0) {
            return new ProviderResult('failed', $lastAttempt['provider_reference'] ?? null, 'Simulated provider failure.');
        }
        return new ProviderResult('successful', $lastAttempt['provider_reference'] ?? null, 'Simulated payment completed.');
    }
}
