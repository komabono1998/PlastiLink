<?php
declare(strict_types=1);

namespace App\Services\Sms;

/** Development driver: writes messages to storage/logs/outbox.log. In production it refuses (no silent "sent"). */
final class LogSms implements SmsGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $to, string $body): SmsResult
    {
        if (config('app.env') === 'production') {
            return new SmsResult('failed', null, 'No SMS gateway is configured (app.integrations.sms.driver).');
        }
        $ok = @file_put_contents(BASE_PATH . '/storage/logs/outbox.log', sprintf("[%s] SMS to %s\n%s\n\n", date('Y-m-d H:i:s'), $to, $body), FILE_APPEND | LOCK_EX) !== false;
        return $ok ? new SmsResult('sent', 'LOG-' . bin2hex(random_bytes(4))) : new SmsResult('retry', null, 'Could not write the log file.');
    }
}
