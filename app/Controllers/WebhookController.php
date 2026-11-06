<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\UssdService;
use App\Services\WebhookService;

/** Machine-to-machine endpoints (no session, no CSRF: protected by the secret token in the URL, rate limiting and re-checking with the provider). */
final class WebhookController extends Controller
{
    /** Common gate. Returns an error response, or null when the caller may proceed. */
    private function gate(string $source, string $token, Request $request): ?string
    {
        $ip = Request::ip();
        if (WebhookService::throttled($ip)) {
            WebhookService::log($source, 'throttled', $ip);
            http_response_code(429);
            return '';
        }
        if (!WebhookService::authorized($token)) {
            WebhookService::log($source, 'denied', $ip);
            http_response_code(404);
            return \App\Core\ErrorHandler::renderError(404, $request);   // looks like any unknown page
        }
        return null;
    }

    private function payload(): array
    {
        $d = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
        return is_array($d) ? $d : [];
    }

    private function ok(string $source, string $outcome): string
    {
        WebhookService::log($source, $outcome, Request::ip());
        header('Content-Type: application/json');
        return '{"ok":true}';
    }

    public function mtn(Request $request, string $token): string
    {
        return $this->gate('mtn', $token, $request) ?? $this->ok('mtn', WebhookService::mtn($this->payload()));
    }

    public function airtel(Request $request, string $token): string
    {
        return $this->gate('airtel', $token, $request) ?? $this->ok('airtel', WebhookService::airtel($this->payload()));
    }

    public function sms(Request $request, string $token): string
    {
        return $this->gate('sms', $token, $request) ?? $this->ok('sms', WebhookService::smsDelivery($request->body));
    }

    public function ussd(Request $request, string $token): string
    {
        if (($denied = $this->gate('ussd', $token, $request)) !== null) {
            return $denied;
        }
        $b = $request->body;
        $reply = UssdService::handle((string) ($b['phoneNumber'] ?? ''), (string) ($b['text'] ?? ''), (string) ($b['sessionId'] ?? ''));
        WebhookService::log('ussd', str_starts_with($reply, 'CON') ? 'continue' : 'end', Request::ip());
        header('Content-Type: text/plain; charset=utf-8');
        return $reply;
    }
}
