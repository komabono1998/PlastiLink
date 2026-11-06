<?php
declare(strict_types=1);

namespace App\Services\Sms;

/** Something that can deliver a text message. Implementations: LogSms (default), MockSms (tests), AfricasTalkingSms. */
interface SmsGateway
{
    public function name(): string;

    /** @param string $to E.164 number */
    public function send(string $to, string $body): SmsResult;
}
