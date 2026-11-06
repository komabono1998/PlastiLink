<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Payment;

/**
 * Callbacks from providers (payment updates, SMS delivery reports) and USSD. Three rules:
 *  1. The URL carries a secret token (app.integrations.webhook_token). No token configured = every callback URL answers 404.
 *  2. A payment callback never moves money by itself: it only tells us WHICH payment to look at; the answer comes from asking the
 *     provider (PaymentService::pollOne). A forged or replayed callback can therefore change nothing.
 *  3. Requests are rate-limited per IP and recorded (without payloads, which can hold phone numbers).
 */
final class WebhookService
{
    public const LIMIT_PER_MINUTE = 120;

    public static function authorized(string $given): bool
    {
        $t = (string) config('app.integrations.webhook_token', '');
        return strlen($t) >= 24 && hash_equals($t, $given);
    }

    public static function throttled(string $ip): bool
    {
        $s = Database::connection()->prepare('SELECT COUNT(*) FROM webhook_log WHERE ip_address = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        $s->execute([$ip]);
        return (int) $s->fetchColumn() >= self::LIMIT_PER_MINUTE;
    }

    public static function log(string $source, string $outcome, string $ip): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO webhook_log (source, ip_address, outcome, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')->execute([substr($source, 0, 20), $ip, substr($outcome, 0, 30)]);
        if (random_int(1, 200) === 1) {
            $pdo->exec('DELETE FROM webhook_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        }
    }

    /** MTN MoMo callback: we only use our own externalId (the payment reference) or the reference id to find the payment. */
    public static function mtn(array $json): string
    {
        $ref = (string) ($json['externalId'] ?? $json['referenceId'] ?? '');
        return self::check($ref);
    }

    /** Airtel Money callback: { transaction: { id, status_code, ... } }. */
    public static function airtel(array $json): string
    {
        return self::check((string) ($json['transaction']['id'] ?? ''));
    }

    private static function check(string $ref): string
    {
        $id = Payment::idByAnyReference($ref);
        if ($id === null) {
            return 'unknown';
        }
        return PaymentService::pollOne($id) ? 'settled' : 'checked';
    }

    /** SMS delivery report (Africa's Talking: id, status, phoneNumber, failureReason). */
    public static function smsDelivery(array $post): string
    {
        return SmsService::recordDelivery((string) ($post['id'] ?? ''), (string) ($post['status'] ?? '')) ? 'recorded' : 'ignored';
    }
}
