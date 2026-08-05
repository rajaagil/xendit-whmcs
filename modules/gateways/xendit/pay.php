<?php

/**
 * SamudraDev WHMCS Xendit Gateway payment redirect.
 *
 * Commercial module by samudradev. Contact raja@sarana.digital for licensing.
 */

use WHMCS\Database\Capsule;

require_once dirname(__DIR__, 3) . '/init.php';
require_once ROOTDIR . '/includes/gatewayfunctions.php';
require_once __DIR__ . '/license.php';

$gatewayModuleName = 'xendit';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    http_response_code(503);
    exit('Xendit gateway is not active.');
}

if (empty($gatewayParams['secretKey'])) {
    http_response_code(503);
    exit('Xendit secret key is not configured.');
}

$licenseStatus = samudradev_xendit_license_status($gatewayParams['licenseKey'] ?? '');
if (!$licenseStatus['valid']) {
    http_response_code(403);
    exit('SamudraDev Xendit license inactive. ' . $licenseStatus['message']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$invoiceId = isset($_POST['invoiceid']) ? (int) $_POST['invoiceid'] : 0;
$clientId = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;

if ($invoiceId <= 0 || $clientId <= 0) {
    http_response_code(400);
    exit('Invalid invoice request.');
}

$invoice = Capsule::table('tblinvoices')
    ->where('id', $invoiceId)
    ->where('userid', $clientId)
    ->first();

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}

if (strtolower((string) $invoice->status) === 'paid') {
    header('Location: ' . xendit_return_url($invoiceId));
    exit;
}

$paidAmount = (float) Capsule::table('tblaccounts')
    ->where('invoiceid', $invoiceId)
    ->sum('amountin');
$balance = max(0, (float) $invoice->total - $paidAmount);

if ($balance <= 0) {
    header('Location: ' . xendit_return_url($invoiceId));
    exit;
}

$client = Capsule::table('tblclients')
    ->where('id', $clientId)
    ->first();

$currencyCode = 'IDR';
if ($client && !empty($client->currency)) {
    $currency = Capsule::table('tblcurrencies')
        ->where('id', (int) $client->currency)
        ->first();

    if ($currency && !empty($currency->code)) {
        $currencyCode = strtoupper((string) $currency->code);
    }
}

$amount = !empty($gatewayParams['forceIntegerAmount'])
    ? (int) round($balance)
    : round($balance, 2);

$systemUrl = rtrim(WHMCS\Config\Setting::getValue('SystemURL'), '/');
$returnUrl = xendit_return_url($invoiceId);
$payload = [
    'external_id' => 'WHMCS-' . $invoiceId . '-' . time(),
    'amount' => $amount,
    'description' => 'WHMCS Invoice #' . $invoiceId,
    'currency' => $currencyCode,
    'invoice_duration' => max(60, (int) ($gatewayParams['invoiceDuration'] ?: 86400)),
    'success_redirect_url' => $returnUrl,
    'failure_redirect_url' => $returnUrl,
    'callback_url' => $systemUrl . '/modules/gateways/callback/xendit.php',
    'metadata' => [
        'whmcs_invoice_id' => $invoiceId,
        'whmcs_client_id' => $clientId,
    ],
];

if ($client && !empty($client->email)) {
    $payload['payer_email'] = (string) $client->email;
    $payload['customer'] = [
        'given_names' => trim((string) $client->firstname) ?: 'WHMCS',
        'surname' => trim((string) $client->lastname) ?: 'Client',
        'email' => (string) $client->email,
        'mobile_number' => (string) $client->phonenumber,
    ];
}

if (!empty($gatewayParams['sendEmail'])) {
    $payload['should_send_email'] = true;
}

[$statusCode, $responseBody, $curlError] = xendit_api_request(
    'POST',
    'https://api.xendit.co/v2/invoices',
    $gatewayParams['secretKey'],
    $payload
);

$response = json_decode($responseBody, true);

logTransaction(
    $gatewayModuleName,
    [
        'request' => xendit_redact_payload($payload),
        'status_code' => $statusCode,
        'response' => $response ?: $responseBody,
        'curl_error' => $curlError,
    ],
    ($statusCode >= 200 && $statusCode < 300) ? 'Invoice Created' : 'Invoice Create Failed'
);

if ($curlError || $statusCode < 200 || $statusCode >= 300 || empty($response['invoice_url'])) {
    http_response_code(502);
    exit('Unable to create Xendit invoice. Please contact support.');
}

header('Location: ' . $response['invoice_url'], true, 302);
exit;

function xendit_api_request($method, $url, $secretKey, array $payload)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($secretKey . ':'),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$statusCode, $body === false ? '' : $body, $error];
}

function xendit_return_url($invoiceId)
{
    return rtrim(WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/viewinvoice.php?id=' . (int) $invoiceId;
}

function xendit_redact_payload(array $payload)
{
    if (isset($payload['customer']['email'])) {
        $payload['customer']['email'] = '[redacted]';
    }

    if (isset($payload['payer_email'])) {
        $payload['payer_email'] = '[redacted]';
    }

    return $payload;
}
