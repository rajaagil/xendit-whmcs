<?php

const SAMUDRADEV_XENDIT_PRODUCT_ID = 'samudradev-whmcs-xendit';
const SAMUDRADEV_XENDIT_LICENSE_PUBLIC_KEY = 'I9gCITUMbfC7XTvu59Z7EJGQO5z7i7Q5IU7LFagPt3w=';

function samudradev_xendit_license_status($licenseKey, $domain = null)
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return [
            'valid' => false,
            'message' => 'PHP sodium extension is required for license verification.',
            'payload' => [],
        ];
    }

    $licenseKey = trim((string) $licenseKey);
    if ($licenseKey === '') {
        return [
            'valid' => false,
            'message' => 'License key is required. Contact raja@sarana.digital.',
            'payload' => [],
        ];
    }

    $parts = explode('.', $licenseKey);
    if (count($parts) !== 3 || $parts[0] !== 'sdv1') {
        return [
            'valid' => false,
            'message' => 'License key format is invalid.',
            'payload' => [],
        ];
    }

    $payloadJson = samudradev_xendit_base64url_decode($parts[1]);
    $signature = samudradev_xendit_base64url_decode($parts[2]);
    $payload = json_decode($payloadJson, true);

    if (!is_array($payload) || $payloadJson === false || $signature === false) {
        return [
            'valid' => false,
            'message' => 'License key payload is invalid.',
            'payload' => [],
        ];
    }

    $publicKey = base64_decode(SAMUDRADEV_XENDIT_LICENSE_PUBLIC_KEY, true);
    if (!$publicKey || !sodium_crypto_sign_verify_detached($signature, $parts[1], $publicKey)) {
        return [
            'valid' => false,
            'message' => 'License signature is invalid.',
            'payload' => $payload,
        ];
    }

    if (($payload['product'] ?? '') !== SAMUDRADEV_XENDIT_PRODUCT_ID) {
        return [
            'valid' => false,
            'message' => 'License product is invalid.',
            'payload' => $payload,
        ];
    }

    $expires = (string) ($payload['expires'] ?? '');
    if ($expires !== '' && $expires !== 'never' && strtotime($expires . ' 23:59:59 UTC') < time()) {
        return [
            'valid' => false,
            'message' => 'License has expired.',
            'payload' => $payload,
        ];
    }

    $licensedDomain = strtolower((string) ($payload['domain'] ?? ''));
    $currentDomain = strtolower((string) ($domain ?: samudradev_xendit_current_domain()));
    if (!samudradev_xendit_domain_matches($licensedDomain, $currentDomain)) {
        return [
            'valid' => false,
            'message' => 'License is not valid for this domain.',
            'payload' => $payload,
        ];
    }

    return [
        'valid' => true,
        'message' => 'License is active.',
        'payload' => $payload,
    ];
}

function samudradev_xendit_license_is_valid($licenseKey, $domain = null)
{
    $status = samudradev_xendit_license_status($licenseKey, $domain);

    return (bool) $status['valid'];
}

function samudradev_xendit_current_domain()
{
    if (!empty($_SERVER['HTTP_HOST'])) {
        return preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']);
    }

    if (class_exists('WHMCS\Config\Setting')) {
        $systemUrl = WHMCS\Config\Setting::getValue('SystemURL');
        $host = parse_url($systemUrl, PHP_URL_HOST);

        if ($host) {
            return $host;
        }
    }

    return '';
}

function samudradev_xendit_domain_matches($licensedDomain, $currentDomain)
{
    $licensedDomain = ltrim(strtolower(trim((string) $licensedDomain)), '.');
    $currentDomain = ltrim(strtolower(trim((string) $currentDomain)), '.');

    if ($licensedDomain === '' || $currentDomain === '') {
        return false;
    }

    if ($licensedDomain === '*') {
        return true;
    }

    if ($licensedDomain === $currentDomain) {
        return true;
    }

    if (strpos($licensedDomain, '*.') === 0) {
        $root = substr($licensedDomain, 2);

        return $currentDomain === $root || substr($currentDomain, -strlen('.' . $root)) === '.' . $root;
    }

    return false;
}

function samudradev_xendit_base64url_decode($value)
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode(strtr($value, '-_', '+/'), true);
}
