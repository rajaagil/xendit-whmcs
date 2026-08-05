<?php

/**
 * SamudraDev WHMCS Xendit Gateway callback.
 *
 * Commercial module by samudradev. Contact raja@sarana.digital for licensing.
 */

require_once dirname(__DIR__, 3) . '/init.php';
require_once ROOTDIR . '/includes/gatewayfunctions.php';
require_once ROOTDIR . '/includes/invoicefunctions.php';
require_once dirname(__DIR__) . '/xendit/license.php';

$gatewayModuleName = 'xendit';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    http_response_code(503);
    exit('Gateway not active');
}

$licenseStatus = samudradev_xendit_license_status($gatewayParams['licenseKey'] ?? '');
if (!$licenseStatus['valid']) {
    logTransaction($gatewayModuleName, ['license' => $licenseStatus['message']], 'Invalid License');
    http_response_code(403);
    exit('License inactive');
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    logTransaction($gatewayModuleName, $rawBody, 'Invalid JSON');
    http_response_code(400);
    exit('Invalid JSON');
}

$event = xendit_normalize_event($payload);
$callbackToken = xendit_header('x-callback-token');
if (
    empty($gatewayParams['webhookToken'])
    || $callbackToken === ''
    || !hash_equals((string) $gatewayParams['webhookToken'], (string) $callbackToken)
) {
    logTransaction(
        $gatewayModuleName,
        [
            'payload' => xendit_redact_callback($payload),
            'callback_token_received' => $callbackToken === '' ? 'missing' : 'present',
            'configured_token' => empty($gatewayParams['webhookToken']) ? 'missing' : 'present',
        ],
        'Invalid Callback Token'
    );
    http_response_code(401);
    exit('Invalid token');
}

$status = strtoupper((string) ($event['status'] ?? ''));
$paidStatuses = ['PAID', 'SETTLED', 'SUCCEEDED'];

if (!in_array($status, $paidStatuses, true)) {
    logTransaction($gatewayModuleName, xendit_redact_callback($payload), 'Ignored: ' . ($status ?: 'Unknown Status'));
    http_response_code(200);
    exit('Ignored');
}

$invoiceId = xendit_invoice_id_from_payload($event);
if ($invoiceId <= 0) {
    logTransaction($gatewayModuleName, xendit_redact_callback($payload), 'Missing WHMCS Invoice ID');
    http_response_code(400);
    exit('Missing invoice id');
}

$invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);

$transactionId = (string) (
    $event['id']
    ?? $event['invoice_id']
    ?? $event['payment_id']
    ?? $event['capture_id']
    ?? $event['external_id']
    ?? $event['reference_id']
    ?? ''
);

if ($transactionId === '') {
    logTransaction($gatewayModuleName, xendit_redact_callback($payload), 'Missing Transaction ID');
    http_response_code(400);
    exit('Missing transaction id');
}

checkCbTransID($transactionId);

$paymentAmount = (float) (
    $event['paid_amount']
    ?? $event['amount']
    ?? $event['adjusted_received_amount']
    ?? $event['capture_amount']
    ?? $event['request_amount']
    ?? 0
);
$paymentFee = (float) ($event['fees_paid_amount'] ?? 0);

addInvoicePayment(
    $invoiceId,
    $transactionId,
    $paymentAmount,
    $paymentFee,
    $gatewayModuleName
);

logTransaction($gatewayModuleName, xendit_redact_callback($payload), 'Successful');

http_response_code(200);
echo 'OK';

function xendit_normalize_event(array $payload)
{
    $candidates = [
        $payload,
        $payload['data'] ?? null,
        $payload['paymentCapture']['value']['data'] ?? null,
        $payload['paymentAuthorization']['value']['data'] ?? null,
        $payload['paymentFailure']['value']['data'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_array($candidate) && (isset($candidate['status']) || isset($candidate['external_id']) || isset($candidate['reference_id']))) {
            return $candidate;
        }
    }

    return $payload;
}

function xendit_invoice_id_from_payload(array $payload)
{
    if (!empty($payload['metadata']['whmcs_invoice_id'])) {
        return (int) $payload['metadata']['whmcs_invoice_id'];
    }

    if (!empty($payload['external_id']) && preg_match('/^WHMCS-(\d+)(?:-|$)/', (string) $payload['external_id'], $matches)) {
        return (int) $matches[1];
    }

    if (!empty($payload['reference_id']) && preg_match('/^WHMCS-(\d+)(?:-|$)/', (string) $payload['reference_id'], $matches)) {
        return (int) $matches[1];
    }

    return 0;
}

function xendit_header($name)
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return $_SERVER[$serverKey];
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }
    }

    return '';
}

function xendit_redact_callback(array $payload)
{
    foreach (['payer_email', 'user_email', 'email'] as $key) {
        if (isset($payload[$key])) {
            $payload[$key] = '[redacted]';
        }
    }

    if (isset($payload['customer']['email'])) {
        $payload['customer']['email'] = '[redacted]';
    }

    return $payload;
}
