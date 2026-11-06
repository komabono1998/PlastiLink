<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Phone;
use App\Services\Sms\SmsFactory;

/**
 * Outbound text messages. Events call queue() (inside their own transaction, so a rolled-back event sends nothing);
 * `php jobs.php send-sms` calls dispatch(). Failures never break the event that caused the message.
 */
final class SmsService
{
    public const MAX_LEN = 320;
    public const MAX_ATTEMPTS = 5;

    /** @return int|null outbox id, or null when nothing was queued (no/invalid number, opted out, duplicate) */
    public static function queue(?string $phone, string $body, string $event, ?int $userId = null, ?string $dedupeKey = null): ?int
    {
        try {
            $to = $phone !== null ? Phone::normalize($phone) : null;
            $body = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body));
            if ($to === null || $body === '') {
                return null;
            }
            $pdo = Database::connection();
            $q = $pdo->prepare('SELECT sms_opt_out FROM users WHERE ' . ($userId !== null ? 'id = ?' : 'phone_e164 = ?') . ' LIMIT 1');
            $q->execute([$userId ?? $to]);
            if ((int) $q->fetchColumn() === 1) {
                return null;
            }
            $s = $pdo->prepare(
                'INSERT IGNORE INTO sms_outbox (user_id, recipient, body, event, dedupe_key, status, next_attempt_at, created_at)
                 VALUES (?, ?, ?, ?, ?, \'queued\', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $s->execute([$userId, $to, mb_substr($body, 0, self::MAX_LEN), substr($event, 0, 40), $dedupeKey !== null ? substr($dedupeKey, 0, 80) : null]);
            return $s->rowCount() === 1 ? (int) $pdo->lastInsertId() : null;
        } catch (\Throwable $e) {
            error_log('SMS queue failed: ' . get_class($e));
            return null;
        }
    }

    /** Send what is due. @return array{sent:int, failed:int, retry:int} */
    public static function dispatch(int $limit = 50): array
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE sms_outbox SET status = 'queued' WHERE status = 'sending' AND locked_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE");
        $ids = $pdo->query("SELECT id FROM sms_outbox WHERE status = 'queued' AND next_attempt_at <= UTC_TIMESTAMP() ORDER BY id LIMIT " . max(1, $limit))->fetchAll(\PDO::FETCH_COLUMN);
        $gateway = SmsFactory::make();
        $count = ['sent' => 0, 'failed' => 0, 'retry' => 0];
        $claim = $pdo->prepare("UPDATE sms_outbox SET status = 'sending', locked_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE id = ? AND status = 'queued'");
        $get = $pdo->prepare('SELECT * FROM sms_outbox WHERE id = ?');
        foreach ($ids as $id) {
            $claim->execute([$id]);
            if ($claim->rowCount() !== 1) {
                continue;   // another worker has it
            }
            $get->execute([$id]);
            $row = $get->fetch();
            try {
                $r = $gateway->send($row['recipient'], $row['body']);
            } catch (\Throwable) {
                $r = new \App\Services\Sms\SmsResult('retry', null, 'Gateway error.');
            }
            if ($r->status === 'sent') {
                $pdo->prepare("UPDATE sms_outbox SET status = 'sent', provider = ?, provider_message_id = ?, error = NULL, sent_at = UTC_TIMESTAMP(), locked_at = NULL WHERE id = ?")
                    ->execute([$gateway->name(), $r->messageId, $id]);
                $count['sent']++;
            } elseif ($r->status === 'retry' && (int) $row['attempts'] < self::MAX_ATTEMPTS) {
                $wait = 2 ** (int) $row['attempts'];   // 2, 4, 8, 16 minutes
                $pdo->prepare("UPDATE sms_outbox SET status = 'queued', error = ?, next_attempt_at = UTC_TIMESTAMP() + INTERVAL $wait MINUTE, locked_at = NULL WHERE id = ?")
                    ->execute([substr((string) $r->error, 0, 255), $id]);
                $count['retry']++;
            } else {
                $pdo->prepare("UPDATE sms_outbox SET status = 'failed', provider = ?, error = ?, locked_at = NULL WHERE id = ?")
                    ->execute([$gateway->name(), substr((string) ($r->error ?? 'Gave up after repeated failures.'), 0, 255), $id]);
                $count['failed']++;
            }
        }
        return $count;
    }

    /** Delivery report from the gateway. Never downgrades a delivered message. */
    public static function recordDelivery(string $messageId, string $status): bool
    {
        $map = ['success' => 'delivered', 'delivered' => 'delivered', 'sent' => 'sent', 'buffered' => 'sent', 'submitted' => 'sent', 'failed' => 'failed', 'rejected' => 'failed'];
        $d = $map[strtolower(trim($status))] ?? null;
        if ($d === null || $messageId === '' || strlen($messageId) > 80) {
            return false;
        }
        $s = Database::connection()->prepare(
            "UPDATE sms_outbox SET delivery_status = ?, delivered_at = IF(? = 'delivered', UTC_TIMESTAMP(), delivered_at)
             WHERE provider_message_id = ? AND (delivery_status IS NULL OR delivery_status <> 'delivered')"
        );
        $s->execute([$d, $d, $messageId]);
        return $s->rowCount() > 0;
    }

    // ---- message texts (short, no money details beyond what the picker already knows, never a national ID)

    public static function notifyCollectionApproved(array $collection, ?string $phone, int $userId): void
    {
        self::queue($phone, sprintf('PlastiLink: collection %s approved. %s kg, UGX %s.', $collection['reference'] ?? '', rtrim(rtrim(number_format((float) $collection['net_weight_kg'], 2, '.', ''), '0'), '.'), number_format((int) $collection['total_value_ugx'])),
            'collection_approved', $userId, 'collection-approved-' . $collection['id']);
    }

    public static function notifyPayoutPaid(array $payment, ?string $phone, int $userId): void
    {
        self::queue($phone, sprintf('PlastiLink: UGX %s paid out (%s).', number_format((int) $payment['amount_ugx']), $payment['reference'] ?? ''), 'payout_paid', $userId, 'payout-paid-' . $payment['id']);
    }

    public static function notifyRegistration(string $decision, ?string $phone, int $userId): void
    {
        $text = $decision === 'approved'
            ? 'PlastiLink: your registration is approved. You can now sign in.'
            : 'PlastiLink: your registration was not approved. Visit your nearest collection hub for help.';
        self::queue($phone, $text, 'registration_' . $decision, $userId, 'registration-' . $decision . '-' . $userId . '-' . date('YmdHi'));
    }
}
