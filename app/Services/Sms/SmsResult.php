<?php
declare(strict_types=1);

namespace App\Services\Sms;

/** status: sent | failed (give up) | retry (try again later) */
final class SmsResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $messageId = null,
        public readonly ?string $error = null,
    ) {
    }
}
