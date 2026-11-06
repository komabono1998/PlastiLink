<?php
declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Test double. Keeps what was "sent" in memory. Numbers ending in 0000 fail for good, numbers ending in 1111 ask for a retry
 * the first N times (see $retryBudget).
 */
final class MockSms implements SmsGateway
{
    /** @var list<array{to:string, body:string}> */
    public static array $sent = [];
    public static int $retryBudget = 0;

    public function name(): string
    {
        return 'mock';
    }

    public function send(string $to, string $body): SmsResult
    {
        if (config('app.env') === 'production') {
            return new SmsResult('failed', null, 'The mock SMS gateway is disabled in production.');
        }
        if (str_ends_with($to, '0000')) {
            return new SmsResult('failed', null, 'Simulated permanent failure.');
        }
        if (str_ends_with($to, '1111') && self::$retryBudget > 0) {
            self::$retryBudget--;
            return new SmsResult('retry', null, 'Simulated temporary failure.');
        }
        self::$sent[] = ['to' => $to, 'body' => $body];
        return new SmsResult('sent', 'MOCK-' . count(self::$sent));
    }
}
