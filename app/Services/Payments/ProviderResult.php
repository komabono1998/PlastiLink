<?php
declare(strict_types=1);

namespace App\Services\Payments;

/** What a payment provider says happened. status: successful | processing | failed */
final class ProviderResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $reference = null,
        public readonly ?string $message = null,
    ) {
    }
}
