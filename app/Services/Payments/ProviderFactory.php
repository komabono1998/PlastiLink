<?php
declare(strict_types=1);

namespace App\Services\Payments;

final class ProviderFactory
{
    public static function make(?string $name = null): PaymentProvider
    {
        return match ($name ?? (string) config('app.finance.provider', 'manual')) {
            'mock'         => new MockProvider(),
            'momo'         => new MobileMoneyProvider(),
            'mtn_momo'     => new MtnMomoProvider(),
            'airtel_money' => new AirtelMoneyProvider(),
            default        => new ManualProvider(),
        };
    }

    /** Provider recorded on a new payout. With a live mobile-money provider as the default, cash is still paid by hand (never sent to MTN/Airtel). */
    public static function forMethod(string $method): PaymentProvider
    {
        $default = (string) config('app.finance.provider', 'manual');
        return $method === 'cash' && in_array($default, ['momo', 'mtn_momo', 'airtel_money'], true) ? new ManualProvider() : self::make();
    }
}
