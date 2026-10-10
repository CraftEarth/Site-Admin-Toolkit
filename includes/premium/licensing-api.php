<?php

if (!defined('ABSPATH')) {
    exit;
}

function sat_license_product_slug()
{
    return defined('SAT_LICENSE_PRODUCT')
        ? sanitize_key(SAT_LICENSE_PRODUCT)
        : 'site-admin-toolkit';
}

function sat_license_api_configured()
{
    return defined('SAT_LICENSE_API_URL')
        && filter_var(SAT_LICENSE_API_URL, FILTER_VALIDATE_URL);
}

function sat_license_api_url($path = '')
{
    if (!sat_license_api_configured()) {
        return '';
    }

    return trailingslashit(SAT_LICENSE_API_URL)
        . ltrim($path, '/');
}

function sat_license_api_request($path, array $payload)
{
    if (!sat_license_api_configured()) {
        return new WP_Error(
            'sat_license_api_not_configured',
            'The Site Admin Toolkit licensing server has not been configured yet.'
        );
    }

    $response = wp_remote_post(
        sat_license_api_url($path),
        [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]
    );

    if (is_wp_error($response)) {
        return new WP_Error(
            'sat_license_transport_error',
            $response->get_error_message()
        );
    }

    $status = wp_remote_retrieve_response_code($response);
    $body = json_decode(
        wp_remote_retrieve_body($response),
        true
    );

    if (!is_array($body)) {
        return new WP_Error(
            'sat_license_invalid_response',
            'The licensing server returned an invalid response.'
        );
    }

    if ($status < 200 || $status >= 300) {
        return new WP_Error(
            !empty($body['code'])
                ? sanitize_key($body['code'])
                : 'sat_license_server_error',
            !empty($body['message'])
                ? sanitize_text_field($body['message'])
                : 'The licensing server rejected the request.',
            [
                'http_status' => absint($status),
                'body' => $body,
            ]
        );
    }

    return $body;
}

function sat_license_common_payload()
{
    return [
        'product' => sat_license_product_slug(),
        'site_url' => home_url(),
        'installation_id' => sat_get_installation_id(),
        'plugin_version' => defined('SAT_VERSION')
            ? SAT_VERSION
            : '',
    ];
}

function sat_license_activate_remote($license_key)
{
    $payload = sat_license_common_payload();
    $payload['license_key'] = $license_key;

    return sat_license_api_request(
        'v1/licenses/activate',
        $payload
    );
}

function sat_coupon_redeem_remote($coupon)
{
    $payload = sat_license_common_payload();
    $payload['coupon'] = $coupon;

    return sat_license_api_request(
        'v1/coupons/redeem',
        $payload
    );
}

function sat_license_validate_remote()
{
    $token = get_option('sat_license_installation_token', '');

    if (!$token) {
        return new WP_Error(
            'sat_license_token_missing',
            'No active installation token is stored for this site.'
        );
    }

    return sat_license_api_request(
        'v1/licenses/validate',
        [
            'installation_token' => $token,
            'installation_id' => sat_get_installation_id(),
            'product' => sat_license_product_slug(),
            'plugin_version' => defined('SAT_VERSION')
                ? SAT_VERSION
                : '',
        ]
    );
}

function sat_license_deactivate_remote()
{
    $token = get_option('sat_license_installation_token', '');

    if (!$token) {
        return new WP_Error(
            'sat_license_token_missing',
            'No active installation token is stored for this site.'
        );
    }

    return sat_license_api_request(
        'v1/licenses/deactivate',
        [
            'installation_token' => $token,
            'installation_id' => sat_get_installation_id(),
            'product' => sat_license_product_slug(),
        ]
    );
}

function sat_stripe_checkout_remote($plan = 'premium')
{
    $user = wp_get_current_user();

    return sat_license_api_request(
        'v1/stripe/checkout',
        [
            'product' => sat_license_product_slug(),
            'plan' => sanitize_key($plan),
            'email' => sanitize_email($user->user_email),
        ]
    );
}
