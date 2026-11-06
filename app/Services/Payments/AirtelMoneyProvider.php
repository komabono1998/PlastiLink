<?php
declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Http\HttpClient;

/**
 * Airtel Money disbursements (payouts to pickers). Built from public SDK and developer documentation and checked against a local mock;
 * the exact paths and codes MUST be confirmed in the Airtel sandbox before going live (paths are configurable: status_path).
 *
 *  - Auth: POST /auth/oauth2/token {client_id, client_secret, grant_type} -> bearer token.
 *  - Send: POST /standard/v1/disbursements/ with the payee's number, our transaction id (unique per attempt) and the disbursement PIN
 *    encrypted with Airtel's RSA public key (configured as app.integrations.momo.airtel.public_key; the PIN itself lives only in the local config).
 *  - Check: GET status_path -> TS (success) | TF (failed) | anything else = still in progress.
 * Timeouts and 5xx after sending leave the payment "processing" (money may have moved); it is checked later.
 */
final class AirtelMoneyProvider implements PaymentProvider
{
    private static ?string $token = null;
    private static int $tokenUntil = 0;

    public function name(): string
    {
        return 'airtel_money';
    }

    /** Forget the cached bearer token (after a credential change; used by tests). */
    public static function forgetToken(): void
    {
        self::$token = null;
        self::$tokenUntil = 0;
    }

    private function cfg(): array
    {
        return (array) config('app.integrations.momo.airtel', []);
    }

    private function ready(array $c): bool
    {
        return !empty($c['enabled']) && ($c['client_id'] ?? '') !== '' && ($c['client_secret'] ?? '') !== '' && ($c['pin'] ?? '') !== '' && ($c['public_key'] ?? '') !== '';
    }

    /** Unique, alphanumeric transaction id for one attempt of one payment. */
    public static function transactionId(array $payment, int $attempt): string
    {
        return 'PL' . (int) $payment['id'] . 'A' . $attempt . substr(sha1((string) $payment['idempotency_key']), 0, 8);
    }

    private function bearer(array $c): ?string
    {
        if (self::$token !== null && time() < self::$tokenUntil) {
            return self::$token;
        }
        $r = HttpClient::request('POST', rtrim((string) $c['base_url'], '/') . '/auth/oauth2/token', ['Content-Type' => 'application/json', 'Accept' => '*/*'],
            json_encode(['client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'grant_type' => 'client_credentials']));
        $d = HttpClient::json($r['body']);
        if ($r['status'] !== 200 || !is_string($d['access_token'] ?? null)) {
            return null;
        }
        self::$token = $d['access_token'];
        self::$tokenUntil = time() + max(60, (int) ($d['expires_in'] ?? 180) - 30);
        return self::$token;
    }

    private function encryptPin(array $c): ?string
    {
        $key = openssl_pkey_get_public((string) $c['public_key']);
        if (!$key || !openssl_public_encrypt((string) $c['pin'], $enc, $key, OPENSSL_PKCS1_PADDING)) {
            return null;
        }
        return base64_encode($enc);
    }

    private function headers(array $c, string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => '*/*', 'X-Country' => (string) ($c['country'] ?? 'UG'), 'X-Currency' => (string) ($c['currency'] ?? 'UGX')];
    }

    public function initiate(array $payment, array $input): ProviderResult
    {
        $c = $this->cfg();
        if (!$this->ready($c)) {
            return new ProviderResult('failed', null, 'Airtel Money is not configured.');
        }
        if (!preg_match('/^\+256(\d{9})$/', (string) ($payment['destination_phone'] ?? ''), $m)) {
            return new ProviderResult('failed', null, 'The picker has no valid phone number for mobile money.');
        }
        $token = $this->bearer($c);
        if ($token === null) {
            return new ProviderResult('failed', null, 'Could not sign in to Airtel Money (check the credentials).');
        }
        $pin = $this->encryptPin($c);
        if ($pin === null) {
            return new ProviderResult('failed', null, 'Airtel Money PIN encryption is not set up correctly.');
        }
        $attempt = ((int) (Payment::lastAttempt((int) $payment['id'])['attempt_no'] ?? 0)) + 1;
        $txn = self::transactionId($payment, $attempt);
        $body = json_encode(['payee' => ['msisdn' => $m[1]], 'reference' => 'PlastiLink ' . $payment['reference'], 'pin' => $pin,
            'transaction' => ['amount' => (int) $payment['amount_ugx'], 'id' => $txn]]);
        $r = HttpClient::request('POST', rtrim((string) $c['base_url'], '/') . '/standard/v1/disbursements/', $this->headers($c, $token), $body);

        if ($r['status'] === 0 || $r['status'] >= 500 || $r['status'] === 429) {
            return new ProviderResult('processing', $txn, 'Airtel Money did not answer; the payment will be checked again.');
        }
        $d = HttpClient::json($r['body']);
        if ($r['status'] === 401) {
            self::$token = null;
        }
        if ($r['status'] === 200 && ($d['status']['success'] ?? false) === true) {
            return new ProviderResult('processing', $txn, 'Sent to Airtel Money.');
        }
        return new ProviderResult('failed', null, $r['status'] === 401 || $r['status'] === 403 ? 'Airtel Money refused our credentials or access.' : 'Airtel Money could not start the payment.');
    }

    public function status(array $payment, array $lastAttempt): ProviderResult
    {
        $c = $this->cfg();
        $txn = (string) ($lastAttempt['provider_reference'] ?? $payment['provider_reference'] ?? '');
        if (!$this->ready($c) || !preg_match('/^[A-Za-z0-9]{6,60}$/', $txn)) {
            return new ProviderResult('processing', $txn ?: null, 'Cannot check the payment yet.');
        }
        $token = $this->bearer($c);
        if ($token === null) {
            return new ProviderResult('processing', $txn, 'Could not sign in to Airtel Money; will retry.');
        }
        $path = str_replace('{id}', rawurlencode($txn), (string) ($c['status_path'] ?? '/standard/v1/disbursements/{id}'));
        $r = HttpClient::request('GET', rtrim((string) $c['base_url'], '/') . $path, $this->headers($c, $token));
        if ($r['status'] !== 200) {
            return new ProviderResult('processing', $txn, 'Airtel Money did not answer; will retry.');
        }
        return match (strtoupper((string) (HttpClient::json($r['body'])['data']['transaction']['status'] ?? ''))) {
            'TS'    => new ProviderResult('successful', $txn, 'Paid by Airtel Money.'),
            'TF'    => new ProviderResult('failed', $txn, 'Airtel Money reported a failure.'),
            default => new ProviderResult('processing', $txn, 'Waiting for Airtel Money.'),
        };
    }
}
