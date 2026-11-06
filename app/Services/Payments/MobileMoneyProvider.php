<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Picks MTN MoMo or Airtel Money from the number's network prefix and hands the payment over.
 * Choose it with app.finance.provider = 'momo'. Cash payouts always stay with the manual provider.
 */
final class MobileMoneyProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'momo';
    }

    /** @return 'mtn'|'airtel'|null */
    public static function networkOf(?string $e164): ?string
    {
        if (!$e164 || !preg_match('/^\+256(\d{2})\d{7}$/', $e164, $m)) {
            return null;
        }
        return match (true) {
            in_array($m[1], ['77', '78', '76', '39'], true) => 'mtn',
            in_array($m[1], ['70', '75', '74', '20'], true) => 'airtel',
            default => null,
        };
    }

    private function pick(array $payment): ?PaymentProvider
    {
        return match (self::networkOf($payment['destination_phone'] ?? null)) {
            'mtn' => new MtnMomoProvider(), 'airtel' => new AirtelMoneyProvider(), default => null,
        };
    }

    public function initiate(array $payment, array $input): ProviderResult
    {
        $p = $this->pick($payment);
        return $p ? $p->initiate($payment, $input) : new ProviderResult('failed', null, 'This phone number is not on MTN or Airtel. Pay by cash or record the payment manually.');
    }

    public function status(array $payment, array $lastAttempt): ProviderResult
    {
        $p = $this->pick($payment);
        return $p ? $p->status($payment, $lastAttempt) : new ProviderResult('failed', $lastAttempt['provider_reference'] ?? null, 'Unsupported network.');
    }
}
