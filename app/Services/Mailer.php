<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Outbound message channel (email now, SMS in Phase 9). Concrete drivers are swappable
 * so real delivery needs only a new class and a config change.
 */
interface Mailer
{
    /** @param string $channel email|sms */
    public function send(string $channel, string $to, string $subject, string $body): bool;
}
