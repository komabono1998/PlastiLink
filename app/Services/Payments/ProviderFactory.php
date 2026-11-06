<?php
declare(strict_types=1);

namespace App\Services\Payments;

final class ProviderFactory
{
    public static function make(?string $name = null): PaymentProvider
    {
        return match ($name ?? (string) config('app.finance.provider', 'manual')) {
            'mock'   => new MockProvider(),
            default  => new ManualProvider(),
        };
    }
}
