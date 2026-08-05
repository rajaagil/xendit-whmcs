<?php

/**
 * SamudraDev WHMCS Xendit Gateway.
 *
 * Commercial module by samudradev. Contact raja@sarana.digital for licensing.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/xendit/license.php';

function xendit_MetaData()
{
    return [
        'DisplayName' => 'SamudraDev Xendit',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function xendit_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'SamudraDev Xendit',
        ],
        'licenseKey' => [
            'FriendlyName' => 'License Key',
            'Type' => 'textarea',
            'Rows' => '3',
            'Cols' => '80',
            'Description' => 'Required. Contact raja@sarana.digital to request a license.',
        ],
        'secretKey' => [
            'FriendlyName' => 'Secret API Key',
            'Type' => 'password',
            'Size' => '80',
            'Description' => 'Use your Xendit secret key. Keep test and live keys separate.',
        ],
        'webhookToken' => [
            'FriendlyName' => 'Webhook Verification Token',
            'Type' => 'password',
            'Size' => '80',
            'Description' => 'The token from Xendit Dashboard Webhook settings, sent as x-callback-token.',
        ],
        'invoiceDuration' => [
            'FriendlyName' => 'Invoice Duration',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '86400',
            'Description' => 'Payment link validity in seconds. Default: 86400 (24 hours).',
        ],
        'sendEmail' => [
            'FriendlyName' => 'Let Xendit Send Email',
            'Type' => 'yesno',
            'Description' => 'Tick to let Xendit email the payer.',
        ],
        'forceIntegerAmount' => [
            'FriendlyName' => 'Round Amount',
            'Type' => 'yesno',
            'Description' => 'Recommended for IDR/PHP/VND/THB. Rounds WHMCS invoice balance to a whole number.',
            'Default' => 'on',
        ],
        'buttonText' => [
            'FriendlyName' => 'Button Text',
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'Pay with Xendit',
        ],
    ];
}

function xendit_link($params)
{
    $licenseStatus = samudradev_xendit_license_status($params['licenseKey'] ?? '');
    if (!$licenseStatus['valid']) {
        return '<div class="alert alert-danger">SamudraDev Xendit license inactive. '
            . htmlspecialchars($licenseStatus['message'], ENT_QUOTES, 'UTF-8')
            . '</div>';
    }

    $invoiceId = (int) $params['invoiceid'];
    $buttonText = $params['buttonText'] ?: $params['langpaynow'];
    $action = rtrim($params['systemurl'], '/') . '/modules/gateways/xendit/pay.php';

    return '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="invoiceid" value="' . $invoiceId . '">'
        . '<button type="submit" class="btn btn-primary">'
        . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8')
        . '</button>'
        . '</form>';
}
