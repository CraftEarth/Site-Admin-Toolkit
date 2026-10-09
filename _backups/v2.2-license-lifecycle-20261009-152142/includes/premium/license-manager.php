<?php

if (!defined('ABSPATH')) {
    exit;
}

function sat_premium_notice_key()
{
    return 'sat_premium_notice_' . get_current_user_id();
}

function sat_set_premium_notice($type, $message)
{
    set_transient(
        sat_premium_notice_key(),
        [
            'type' => sanitize_key($type),
            'message' => sanitize_text_field($message),
        ],
        60
    );
}

function sat_get_premium_notice()
{
    $notice = get_transient(sat_premium_notice_key());

    delete_transient(sat_premium_notice_key());

    return is_array($notice)
        ? $notice
        : null;
}

function sat_apply_server_entitlement_response($response)
{
    if (empty($response['entitlement']) || !is_array($response['entitlement'])) {
        return new WP_Error(
            'sat_entitlement_missing',
            'The licensing server did not return an entitlement.'
        );
    }

    $entitlement = $response['entitlement'];
    $entitlement['source'] = !empty($entitlement['source'])
        ? $entitlement['source']
        : 'server';

    sat_save_entitlement($entitlement);

    /*
     * Store only an opaque installation token when supplied.
     * The raw customer license key/coupon is never persisted.
     */
    if (!empty($response['installation_token'])) {
        update_option(
            'sat_license_installation_token',
            sanitize_text_field($response['installation_token']),
            false
        );
    }

    return true;
}

function sat_handle_license_activation()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer('sat_activate_license');

    $license_key = isset($_POST['license_key'])
        ? sanitize_text_field(wp_unslash($_POST['license_key']))
        : '';

    if ($license_key === '') {
        sat_set_premium_notice(
            'error',
            'Enter a license key before activating.'
        );

        wp_safe_redirect(
            admin_url('admin.php?page=site-admin-toolkit&tab=premium')
        );
        exit;
    }

    $response = sat_license_activate_remote($license_key);

    if (is_wp_error($response)) {
        sat_set_premium_notice(
            'error',
            $response->get_error_message()
        );
    } else {
        $applied = sat_apply_server_entitlement_response($response);

        if (is_wp_error($applied)) {
            sat_set_premium_notice(
                'error',
                $applied->get_error_message()
            );
        } else {
            sat_set_premium_notice(
                'success',
                !empty($response['message'])
                    ? $response['message']
                    : 'Premium license activated.'
            );
        }
    }

    wp_safe_redirect(
        admin_url('admin.php?page=site-admin-toolkit&tab=premium')
    );
    exit;
}

add_action(
    'admin_post_sat_activate_license',
    'sat_handle_license_activation'
);

function sat_handle_coupon_redemption()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer('sat_redeem_coupon');

    $coupon = isset($_POST['coupon_code'])
        ? strtoupper(
            sanitize_text_field(
                wp_unslash($_POST['coupon_code'])
            )
        )
        : '';

    if ($coupon === '') {
        sat_set_premium_notice(
            'error',
            'Enter a coupon code before redeeming.'
        );

        wp_safe_redirect(
            admin_url('admin.php?page=site-admin-toolkit&tab=premium')
        );
        exit;
    }

    $response = sat_coupon_redeem_remote($coupon);

    if (is_wp_error($response)) {
        sat_set_premium_notice(
            'error',
            $response->get_error_message()
        );
    } else {
        $applied = sat_apply_server_entitlement_response($response);

        if (is_wp_error($applied)) {
            sat_set_premium_notice(
                'error',
                $applied->get_error_message()
            );
        } else {
            sat_set_premium_notice(
                'success',
                !empty($response['message'])
                    ? $response['message']
                    : 'Coupon redeemed successfully.'
            );
        }
    }

    wp_safe_redirect(
        admin_url('admin.php?page=site-admin-toolkit&tab=premium')
    );
    exit;
}

add_action(
    'admin_post_sat_redeem_coupon',
    'sat_handle_coupon_redemption'
);

function sat_handle_clear_dev_entitlement()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer('sat_clear_entitlement');

    sat_clear_entitlement();
    delete_option('sat_license_installation_token');

    sat_set_premium_notice(
        'success',
        'Cached Premium entitlement cleared.'
    );

    wp_safe_redirect(
        admin_url('admin.php?page=site-admin-toolkit&tab=premium')
    );
    exit;
}

add_action(
    'admin_post_sat_clear_entitlement',
    'sat_handle_clear_dev_entitlement'
);
