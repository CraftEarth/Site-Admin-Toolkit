<?php

if (!defined('ABSPATH')) {
    exit;
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
        return $response;
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
            'sat_license_server_error',
            !empty($body['message'])
                ? sanitize_text_field($body['message'])
                : 'The licensing server rejected the request.'
        );
    }

    return $body;
}

function sat_license_activate_remote($license_key)
{
    return sat_license_api_request(
        'v1/licenses/activate',
        [
            'license_key' => $license_key,
            'site_url' => home_url(),
            'installation_id' => sat_get_installation_id(),
            'plugin_version' => defined('SAT_VERSION')
                ? SAT_VERSION
                : '',
        ]
    );
}

function sat_coupon_redeem_remote($coupon)
{
    return sat_license_api_request(
        'v1/coupons/redeem',
        [
            'coupon' => $coupon,
            'site_url' => home_url(),
            'installation_id' => sat_get_installation_id(),
            'plugin_version' => defined('SAT_VERSION')
                ? SAT_VERSION
                : '',
        ]
    );
}
