<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Money;
use App\Helpers\Phone;
use App\Models\AuditLog;
use App\Models\Picker;
use App\Models\Price;
use App\Models\User;

/**
 * USSD menu for waste pickers (Africa's Talking style: the whole input so far arrives as "1*500*1").
 * The caller is identified by the phone number the network reports. There is no PIN, so USSD can only READ the picker's own
 * figures and ASK for a payout: capped (ussd.payout_max_ugx), limited per day, paid only to the registered phone, and it still
 * needs the normal approval by someone else. Replies start with CON (keep the session open) or END (close it), max 160 characters.
 */
final class UssdService
{
    public static function handle(string $phoneRaw, string $text, string $sessionId): string
    {
        if (!config('app.integrations.ussd.enabled', false)) {
            return 'END This service is not available yet.';
        }
        $e164 = Phone::normalize($phoneRaw);
        $user = $e164 ? User::findByIdentifier(['type' => 'phone', 'value' => $e164]) : null;
        $picker = $user && $user['role_slug'] === 'waste_picker' ? Picker::findByUserId((int) $user['id']) : null;
        if (!$user || !$picker) {
            return 'END This number is not registered as a PlastiLink waste picker. Visit a collection hub to join.';
        }
        if ($user['status'] !== 'active') {
            return 'END Your PlastiLink account is not active. Please contact your collection hub.';
        }

        $parts = $text === '' ? [] : explode('*', substr($text, 0, 60));
        foreach ($parts as $p) {
            if (!preg_match('/^[0-9]{1,9}$/', $p)) {
                return 'END Invalid input. Please dial again.';
            }
        }
        $reply = match ($parts[0] ?? '') {
            ''  => "CON PlastiLink\n1. My balance\n2. Last collections\n3. Today's prices\n4. Request payout",
            '1' => self::balance($picker),
            '2' => self::collections($picker),
            '3' => self::prices($picker),
            '4' => self::payout($user, $picker, array_slice($parts, 1), $sessionId),
            default => 'END Invalid choice. Please dial again.',
        };
        return mb_substr($reply, 0, 160);
    }

    private static function balance(array $picker): string
    {
        $pos = PayoutService::position((int) $picker['id']);
        return 'END Balance: ' . format_ugx($pos['balance']) . "\nAvailable to pay out: " . format_ugx($pos['available']);
    }

    private static function collections(array $picker): string
    {
        $s = Database::connection()->prepare(
            "SELECT net_weight_kg, total_value_ugx, status, recorded_at FROM collection_transactions WHERE picker_id = ? ORDER BY id DESC LIMIT 3"
        );
        $s->execute([(int) $picker['id']]);
        $rows = $s->fetchAll();
        if (!$rows) {
            return 'END You have no collections yet.';
        }
        $lines = array_map(static fn(array $r) => format_datetime($r['recorded_at'], 'd M') . ' ' . rtrim(rtrim(number_format((float) $r['net_weight_kg'], 2, '.', ''), '0'), '.') . 'kg '
            . number_format((int) $r['total_value_ugx']) . ' ' . $r['status'], $rows);
        return "END Last collections:\n" . implode("\n", $lines);
    }

    private static function prices(array $picker): string
    {
        $hub = $picker['preferred_hub_id'] !== null ? (int) $picker['preferred_hub_id'] : null;
        $lines = [];
        foreach (Database::connection()->query('SELECT id, name FROM plastic_categories WHERE is_active = 1 ORDER BY name')->fetchAll() as $c) {
            $p = Price::current((int) $c['id'], $hub);
            if ($p) {
                $lines[] = $c['name'] . ': ' . number_format((int) $p['price_per_kg_ugx']) . '/kg';
            }
        }
        return $lines ? "END Prices (UGX):\n" . implode("\n", $lines) : 'END No prices are set yet.';
    }

    /** 4 -> amount -> 1 (confirm) | 2 (cancel) */
    private static function payout(array $user, array $picker, array $steps, string $sessionId): string
    {
        $max = (int) config('app.integrations.ussd.payout_max_ugx', 100000);
        if (count($steps) === 0) {
            return 'CON Enter the amount to withdraw (UGX, max ' . number_format($max) . ')';
        }
        $amount = Money::parseUgx($steps[0]);
        $min = (int) config('app.finance.payout_min_ugx', 1000);
        if ($amount === null || $amount < $min || $amount > $max) {
            return 'END The amount must be between ' . number_format($min) . ' and ' . number_format($max) . ' UGX.';
        }
        if (count($steps) === 1) {
            return 'CON Withdraw ' . format_ugx($amount) . " to this phone after approval?\n1. Yes\n2. No";
        }
        if ($steps[1] !== '1') {
            return 'END Cancelled. Nothing was requested.';
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $sessionId)) {
            return 'END Could not verify this session. Please dial again.';
        }
        $today = Database::connection()->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'ussd.payout_requested' AND user_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY");
        $today->execute([(int) $user['id']]);
        if ((int) $today->fetchColumn() >= (int) config('app.integrations.ussd.payout_max_per_day', 3)) {
            return 'END You have reached the daily limit for USSD requests. Try again tomorrow or visit your hub.';
        }
        // The session id + amount make this request idempotent: if the network repeats the last step, no second request is created.
        $r = PayoutService::request(['token' => md5($sessionId . '|payout|' . $amount), 'amount_ugx' => (string) $amount, 'method' => 'mobile_money'], $user);
        if ($r['error'] !== null) {
            return 'END ' . $r['error'];
        }
        if ($r['errors']) {
            return 'END ' . array_values($r['errors'])[0];
        }
        if (empty($r['duplicate'])) {
            AuditLog::write('ussd.payout_requested', (int) $user['id'], 'payout_request', (int) $r['id'], ['amount' => $amount]);
        }
        return 'END Request received for ' . format_ugx($amount) . '. It will be paid to this phone once approved.';
    }
}
