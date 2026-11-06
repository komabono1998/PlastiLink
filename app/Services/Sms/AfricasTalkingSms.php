<?php
declare(strict_types=1);

namespace App\Services\Sms;

use App\Services\Http\HttpClient;

/**
 * Africa's Talking SMS (POST /version1/messaging, "apiKey" header, form body). Built from the public documentation and
 * checked against a local mock server; confirm with the Africa's Talking sandbox before going live.
 * Result codes: 100 Processed, 101 Sent, 102 Queued = accepted; other codes (invalid sender, blacklisted, insufficient balance...) are final failures.
 */
final class AfricasTalkingSms implements SmsGateway
{
    public function name(): string
    {
        return 'africastalking';
    }

    public function send(string $to, string $body): SmsResult
    {
        $c = (array) config('app.integrations.sms.africastalking', []);
        $user = (string) ($c['username'] ?? '');
        $key = (string) ($c['api_key'] ?? '');
        if ($user === '' || $key === '') {
            return new SmsResult('failed', null, "Africa's Talking is not configured.");
        }
        $base = (string) ($c['base_url'] ?? '') !== '' ? rtrim((string) $c['base_url'], '/')
            : (($c['sandbox'] ?? true) ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com');
        $form = ['username' => $user, 'to' => $to, 'message' => $body];
        if (($c['sender_id'] ?? '') !== '') {
            $form['from'] = (string) $c['sender_id'];
        }
        $r = HttpClient::request('POST', $base . '/version1/messaging', [
            'apiKey' => $key, 'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query($form));

        if ($r['status'] === 0 || $r['status'] >= 500 || $r['status'] === 429) {
            return new SmsResult('retry', null, 'The SMS gateway did not answer.');
        }
        if ($r['status'] === 401 || $r['status'] === 403) {
            return new SmsResult('failed', null, "The SMS gateway rejected our credentials.");
        }
        $rec = HttpClient::json($r['body'])['SMSMessageData']['Recipients'][0] ?? null;
        if (!is_array($rec)) {
            return new SmsResult('failed', null, 'Unexpected answer from the SMS gateway.');
        }
        if (in_array((int) ($rec['statusCode'] ?? 0), [100, 101, 102], true)) {
            return new SmsResult('sent', isset($rec['messageId']) ? substr((string) $rec['messageId'], 0, 80) : null);
        }
        return new SmsResult('failed', null, 'Rejected by the gateway: ' . substr(preg_replace('/[^A-Za-z0-9 _.-]/', '', (string) ($rec['status'] ?? 'unknown')) ?? '', 0, 60));
    }
}
