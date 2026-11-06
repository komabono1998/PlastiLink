<?php
declare(strict_types=1);

/**
 * Router script for `php -S`: a fake MTN MoMo, Airtel Money and Africa's Talking, controlled through a JSON state file (env MOCK_STATE).
 * Test use only. State keys: mtn_mode, mtn_final, airtel_mode, airtel_final, airtel_priv, at_mode. It logs every request into state["log"].
 */

$file = (string) getenv('MOCK_STATE');
$st = json_decode((string) @file_get_contents($file), true) ?: [];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$h = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
$raw = (string) file_get_contents('php://input');
$entry = ['m' => $method, 'p' => $path, 'h' => $h, 'b' => $raw];

$send = function (int $code, array|string $body) use (&$st, $file, &$entry): never {
    $st['log'][] = $entry + ['code' => $code];
    file_put_contents($file, json_encode($st));
    http_response_code($code);
    header('Content-Type: application/json');
    echo is_array($body) ? json_encode($body) : $body;
    exit;
};
$bearer = fn(string $t) => ($h['authorization'] ?? '') === 'Bearer ' . $t;
$json = json_decode($raw, true) ?: [];

// ---------------------------------------------------------------- MTN MoMo
if ($path === '/disbursement/token/' && $method === 'POST') {
    $ok = ($h['authorization'] ?? '') === 'Basic ' . base64_encode('mtn-user:mtn-key') && ($h['ocp-apim-subscription-key'] ?? '') === 'mtn-sub-key';
    $ok ? $send(200, ['access_token' => 'mtn-tok', 'token_type' => 'access_token', 'expires_in' => 3600]) : $send(401, ['error' => 'bad credentials']);
}
if ($path === '/disbursement/v1_0/transfer' && $method === 'POST') {
    if (!$bearer('mtn-tok')) {
        $send(401, []);
    }
    $ref = $h['x-reference-id'] ?? '';
    $mode = $st['mtn_mode'] ?? 'accept';
    if ($mode === '500') { $send(500, 'boom'); }
    if ($mode === '400') { $send(400, ['message' => 'secret detail that must never reach users']); }
    if ($mode === '403') { $send(403, []); }
    if (isset($st['transfers'][$ref])) { $send(409, ['code' => 'RESOURCE_ALREADY_EXIST']); }
    $st['transfers'][$ref] = $json;
    $send(202, '');
}
if (preg_match('#^/disbursement/v1_0/transfer/([0-9a-f-]{36})$#', $path, $m) && $method === 'GET') {
    if (!$bearer('mtn-tok')) { $send(401, []); }
    if (($st['mtn_status_mode'] ?? '') === '500') { $send(500, 'boom'); }
    if (!isset($st['transfers'][$m[1]])) { $send(404, []); }
    $send(200, ['status' => $st['mtn_final'] ?? 'PENDING', 'reason' => 'INTERNAL_PROCESSING_ERROR', 'amount' => $st['transfers'][$m[1]]['amount'] ?? null]);
}

// ---------------------------------------------------------------- Airtel Money
if ($path === '/auth/oauth2/token' && $method === 'POST') {
    ($json['client_id'] ?? '') === 'air-id' && ($json['client_secret'] ?? '') === 'air-secret' && ($json['grant_type'] ?? '') === 'client_credentials'
        ? $send(200, ['access_token' => 'air-tok', 'expires_in' => 180]) : $send(401, ['error' => 'bad credentials']);
}
if ($path === '/standard/v1/disbursements/' && $method === 'POST') {
    if (!$bearer('air-tok')) { $send(401, []); }
    $mode = $st['airtel_mode'] ?? 'ok';
    if ($mode === '500') { $send(500, 'boom'); }
    $plain = null;
    if (!empty($st['airtel_priv'])) {
        openssl_private_decrypt((string) base64_decode((string) ($json['pin'] ?? '')), $plain, $st['airtel_priv'], OPENSSL_PKCS1_PADDING);
    }
    $entry['pin_plain'] = $plain;
    if ($mode === 'fail') { $send(200, ['status' => ['success' => false, 'response_code' => 'DP00900001007', 'message' => 'secret detail']]); }
    $st['air'][$json['transaction']['id'] ?? ''] = $json;
    $send(200, ['data' => ['transaction' => ['id' => $json['transaction']['id'] ?? '', 'status' => 'TIP']], 'status' => ['success' => true, 'response_code' => 'DP00900001001']]);
}
if (preg_match('#^/standard/v1/disbursements/([A-Za-z0-9]+)$#', $path, $m) && $method === 'GET') {
    if (!$bearer('air-tok')) { $send(401, []); }
    $send(200, ['data' => ['transaction' => ['id' => $m[1], 'status' => $st['airtel_final'] ?? 'TIP']], 'status' => ['success' => true]]);
}

// ---------------------------------------------------------------- Africa's Talking
if ($path === '/version1/messaging' && $method === 'POST') {
    parse_str($raw, $f);
    $mode = $st['at_mode'] ?? 'ok';
    if (($h['apikey'] ?? '') !== 'at-key') { $send(401, 'The supplied authentication is invalid'); }
    if ($mode === '500') { $send(500, 'boom'); }
    if (($f['username'] ?? '') !== 'sandbox') { $send(401, 'bad user'); }
    $rec = $mode === 'rejected'
        ? ['statusCode' => 403, 'number' => $f['to'] ?? '', 'status' => 'RiskHold', 'cost' => '0']
        : ['statusCode' => 101, 'number' => $f['to'] ?? '', 'status' => 'Success', 'cost' => 'UGX 30', 'messageId' => 'ATXid_' . bin2hex(random_bytes(4))];
    $send(201, ['SMSMessageData' => ['Message' => 'Sent to 1/1 Total Cost: UGX 30', 'Recipients' => [$rec]]]);
}

$send(404, ['error' => 'no such mock route ' . $path]);
