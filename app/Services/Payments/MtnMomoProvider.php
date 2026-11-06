<?php
declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Http\HttpClient;

/**
 * MTN MoMo Disbursements (payouts to pickers). Built from MTN's public developer documentation and checked against a local mock;
 * confirm in the MTN sandbox before going live.
 *
 *  - Auth: POST /disbursement/token/ (Basic apiUser:apiKey + Ocp-Apim-Subscription-Key) -> bearer token.
 *  - Send: POST /disbursement/v1_0/transfer with X-Reference-Id (a UUID we derive from the payment's idempotency key + attempt number,
 *    so repeating the same attempt can never pay twice; MTN answers 409 for a reference it already has).
 *  - Check: GET /disbursement/v1_0/transfer/{uuid} -> SUCCESSFUL | PENDING | FAILED.
 * A timeout or 5xx after sending is NOT a failure (the money may have moved): the payment stays "processing" and is checked later.
 */
final class MtnMomoProvider implements PaymentProvider
{
    private static ?string $token = null;
    private static int $tokenUntil = 0;

    public function name(): string
    {
        return 'mtn_momo';
    }

    /** Forget the cached bearer token (after a credential change; used by tests). */
    public static function forgetToken(): void
    {
        self::$token = null;
        self::$tokenUntil = 0;
    }

    private function cfg(): array
    {
        return (array) config('app.integrations.momo.mtn', []);
    }

    private function ready(array $c): bool
    {
        return !empty($c['enabled']) && ($c['subscription_key'] ?? '') !== '' && ($c['api_user'] ?? '') !== '' && ($c['api_key'] ?? '') !== '';
    }

    /** Deterministic UUID (version 5 layout) for one attempt of one payment. */
    public static function referenceFor(array $payment, int $attempt): string
    {
        $h = sha1('plastilink-mtn|' . $payment['idempotency_key'] . '|' . $attempt);
        return sprintf('%s-%s-5%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), dechex(8 | (hexdec($h[16]) & 3)), substr($h, 17, 3), substr($h, 20, 12));
    }

    private function bearer(array $c): ?string
    {
        if (self::$token !== null && time() < self::$tokenUntil) {
            return self::$token;
        }
        $r = HttpClient::request('POST', rtrim((string) $c['base_url'], '/') . '/disbursement/token/', [
            'Authorization' => 'Basic ' . base64_encode($c['api_user'] . ':' . $c['api_key']),
            'Ocp-Apim-Subscription-Key' => (string) $c['subscription_key'], 'Content-Length' => '0',
        ], '');
        $t = HttpClient::json($r['body'])['access_token'] ?? null;
        if ($r['status'] !== 200 || !is_string($t) || $t === '') {
            return null;
        }
        self::$token = $t;
        self::$tokenUntil = time() + max(60, (int) (HttpClient::json($r['body'])['expires_in'] ?? 3600) - 60);
        return $t;
    }

    private function headers(array $c, string $token, ?string $reference = null): array
    {
        $h = ['Authorization' => 'Bearer ' . $token, 'X-Target-Environment' => (string) ($c['environment'] ?? 'sandbox'),
            'Ocp-Apim-Subscription-Key' => (string) $c['subscription_key'], 'Content-Type' => 'application/json'];
        if ($reference !== null) {
            $h['X-Reference-Id'] = $reference;
        }
        if (($c['callback_url'] ?? '') !== '') {
            $h['X-Callback-Url'] = (string) $c['callback_url'];
        }
        return $h;
    }

    public function initiate(array $payment, array $input): ProviderResult
    {
        $c = $this->cfg();
        if (!$this->ready($c)) {
            return new ProviderResult('failed', null, 'MTN MoMo is not configured.');
        }
        $msisdn = ltrim((string) ($payment['destination_phone'] ?? ''), '+');
        if (!preg_match('/^256\d{9}$/', $msisdn)) {
            return new ProviderResult('failed', null, 'The picker has no valid phone number for mobile money.');
        }
        $token = $this->bearer($c);
        if ($token === null) {
            return new ProviderResult('failed', null, 'Could not sign in to MTN MoMo (check the credentials).');
        }
        $attempt = ((int) (Payment::lastAttempt((int) $payment['id'])['attempt_no'] ?? 0)) + 1;
        $ref = self::referenceFor($payment, $attempt);
        $body = json_encode([
            'amount' => (string) (int) $payment['amount_ugx'], 'currency' => (string) ($c['currency'] ?? 'UGX'), 'externalId' => (string) $payment['reference'],
            'payee' => ['partyIdType' => 'MSISDN', 'partyId' => $msisdn], 'payerMessage' => 'PlastiLink payout', 'payeeNote' => 'PlastiLink ' . $payment['reference'],
        ]);
        $r = HttpClient::request('POST', rtrim((string) $c['base_url'], '/') . '/disbursement/v1_0/transfer', $this->headers($c, $token, $ref), $body);

        if ($r['status'] === 202 || $r['status'] === 409) {
            return new ProviderResult('processing', $ref, 'Sent to MTN MoMo.');     // 409: MTN already has this exact reference
        }
        if ($r['status'] === 0 || $r['status'] >= 500 || $r['status'] === 429) {
            return new ProviderResult('processing', $ref, 'MTN MoMo did not answer; the payment will be checked again.');
        }
        if ($r['status'] === 401) {
            self::$token = null;
        }
        return new ProviderResult('failed', null, match ($r['status']) {
            400 => 'MTN MoMo rejected the request.', 401, 403 => 'MTN MoMo refused our credentials or access.', default => 'MTN MoMo could not start the payment.',
        });
    }

    public function status(array $payment, array $lastAttempt): ProviderResult
    {
        $c = $this->cfg();
        $ref = (string) ($lastAttempt['provider_reference'] ?? $payment['provider_reference'] ?? '');
        if (!$this->ready($c) || !preg_match('/^[0-9a-f-]{36}$/', $ref)) {
            return new ProviderResult('processing', $ref ?: null, 'Cannot check the payment yet.');
        }
        $token = $this->bearer($c);
        if ($token === null) {
            return new ProviderResult('processing', $ref, 'Could not sign in to MTN MoMo; will retry.');
        }
        $r = HttpClient::request('GET', rtrim((string) $c['base_url'], '/') . '/disbursement/v1_0/transfer/' . $ref, $this->headers($c, $token));
        if ($r['status'] === 404) {
            return new ProviderResult('failed', $ref, 'MTN MoMo has no record of this payment.');
        }
        if ($r['status'] !== 200) {
            return new ProviderResult('processing', $ref, 'MTN MoMo did not answer; will retry.');
        }
        $d = HttpClient::json($r['body']);
        return match (strtoupper((string) ($d['status'] ?? ''))) {
            'SUCCESSFUL' => new ProviderResult('successful', $ref, 'Paid by MTN MoMo.'),
            'FAILED'     => new ProviderResult('failed', $ref, 'MTN MoMo reported a failure' . (isset($d['reason']) && is_string($d['reason']) ? ': ' . substr(preg_replace('/[^A-Za-z0-9 _.-]/', '', $d['reason']) ?? '', 0, 80) : '.')),
            default      => new ProviderResult('processing', $ref, 'Waiting for MTN MoMo.'),
        };
    }
}
