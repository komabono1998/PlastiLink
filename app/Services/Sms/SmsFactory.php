<?php
declare(strict_types=1);

namespace App\Services\Sms;

final class SmsFactory
{
    public static function make(): SmsGateway
    {
        return match ((string) config('app.integrations.sms.driver', 'log')) {
            'africastalking' => new AfricasTalkingSms(),
            'mock'           => new MockSms(),
            default          => new LogSms(),
        };
    }
}
