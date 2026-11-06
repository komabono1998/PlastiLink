<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Development driver: writes messages to storage/logs/outbox.log instead of sending them.
 * Refuses to run in production, where a real driver must be configured.
 */
final class LogMailer implements Mailer
{
    public function send(string $channel, string $to, string $subject, string $body): bool
    {
        if (Config::get('app.env') === 'production') {
            throw new \RuntimeException('No production mail driver is configured (app.mail.driver).');
        }
        $entry = sprintf("[%s] %s to %s\nSubject: %s\n%s\n\n", date('Y-m-d H:i:s'), strtoupper($channel), $to, $subject, $body);
        return @file_put_contents(BASE_PATH . '/storage/logs/outbox.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    public static function make(): Mailer
    {
        return new self();
    }
}
