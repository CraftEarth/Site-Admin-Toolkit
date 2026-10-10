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

    if (!empty($response['installation_token'])) {
        update_option(
            'sat_license_installation_token',
            sanitize_text_field($response['installation_token']),
            false
        );
    }

    if (!empty($response['policy']) && is_array($response['policy'])) {
        sat_save_license_policy($response['policy']);
    }

    if (!empty($response['activation']) && is_array($response['activation'])) {
        sat_save_license_activation($response['activation']);
    }

    return true;
}

function sat_license_error_is_connectivity_failure($error)
{
    if (!is_wp_error($error)) {
        return false;
    }

    return in_array(
        $error->get_error_code(),
        [
            'sat_license_transport_error',
            'sat_license_invalid_response',
            'http_request_failed',
        ],
        true
    );
}

function sat_apply_license_grace_if_available($error)
{
    if (!sat_license_error_is_connectivity_failure($error)) {
        return false;
    }

    $entitlement = sat_get_entitlement();

    if (
        empty($entitlement)
        || !in_array(
            $entitlement['status'] ?? '',
            ['active', 'trial', 'grace'],
            true
        )
        || !sat_license_in_grace_window()
    ) {
        return false;
    }

    $entitlement['status'] = 'grace';
    $entitlement['source'] = 'cached';

    sat_save_entitlement($entitlement);

    return true;
}

function sat_mark_entitlement_from_server_error($error)
{
    if (!is_wp_error($error)) {
        return;
    }

    $code = $error->get_error_code();
    $entitlement = sat_get_entitlement();

    if (empty($entitlement) || !is_array($entitlement)) {
        return;
    }

    if (
        strpos($code, 'license_') === 0
        || strpos($code, 'activation_') === 0
        || $code === 'product_mismatch'
    ) {
        if ($code === 'license_expired') {
            $entitlement['status'] = 'expired';
        } elseif ($code === 'license_revoked') {
            $entitlement['status'] = 'revoked';
        } else {
            $entitlement['status'] = 'inactive';
        }

        $entitlement['source'] = 'server';
        sat_save_entitlement($entitlement);
    }
}

function sat_run_license_validation($manual = false)
{
    if (sat_dev_premium_enabled()) {
        return true;
    }

    if (!get_option('sat_license_installation_token', '')) {
        return new WP_Error(
            'sat_license_token_missing',
            'No active license is stored for this site.'
        );
    }

    $response = sat_license_validate_remote();

    if (is_wp_error($response)) {
        if (sat_apply_license_grace_if_available($response)) {
            return new WP_Error(
                'sat_license_grace',
                'The licensing server could not be reached. Premium remains available during the grace period.'
            );
        }

        sat_mark_entitlement_from_server_error($response);

        return $response;
    }

    $applied = sat_apply_server_entitlement_response($response);

    if (is_wp_error($applied)) {
        return $applied;
    }

    return true;
}

function sat_license_heartbeat_event()
{
    sat_run_license_validation(false);
}

add_action(
    'sat_license_heartbeat',
    'sat_license_heartbeat_event'
);

function sat_schedule_license_heartbeat()
{
    if (
        sat_dev_premium_enabled()
        || !get_option('sat_license_installation_token', '')
    ) {
        return;
    }

    if (!wp_next_scheduled('sat_license_heartbeat')) {
        wp_schedule_event(
            time() + 300,
            'daily',
            'sat_license_heartbeat'
        );
    }
}

add_action(
    'init',
    'sat_schedule_license_heartbeat'
);

function sat_unschedule_license_heartbeat()
{
    $timestamp = wp_next_scheduled('sat_license_heartbeat');

    if ($timestamp) {
        wp_unschedule_event(
            $timestamp,
            'sat_license_heartbeat'
        );
    }
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
            sat_schedule_license_heartbeat();

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
            sat_schedule_license_heartbeat();

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

function sat_handle_license_check()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer('sat_check_license');

    $result = sat_run_license_validation(true);

    if (is_wp_error($result)) {
        $type = $result->get_error_code() === 'sat_license_grace'
            ? 'warning'
            : 'error';

        sat_set_premium_notice(
            $type,
            $result->get_error_message()
        );
    } else {
        sat_set_premium_notice(
            'success',
            'License checked successfully. Your entitlement is up to date.'
        );
    }

    wp_safe_redirect(
        admin_url('admin.php?page=site-admin-toolkit&tab=premium')
    );
    exit;
}

add_action(
    'admin_post_sat_check_license',
    'sat_handle_license_check'
);

function sat_handle_license_deactivation()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer('sat_deactivate_license');

    $response = sat_license_deactivate_remote();

    if (is_wp_error($response)) {
        sat_set_premium_notice(
            'error',
            $response->get_error_message()
        );

        wp_safe_redirect(
            admin_url('admin.php?page=site-admin-toolkit&tab=premium')
        );
        exit;
    }

    sat_clear_entitlement();
    sat_clear_license_activation();
    delete_option('sat_license_installation_token');
    sat_unschedule_license_heartbeat();

    sat_set_premium_notice(
        'success',
        !empty($response['message'])
            ? $response['message']
            : 'This site has been deactivated.'
    );

    wp_safe_redirect(
        admin_url('admin.php?page=site-admin-toolkit&tab=premium')
    );
    exit;
}

add_action(
    'admin_post_sat_deactivate_license',
    'sat_handle_license_deactivation'
);

function sat_handle_clear_dev_entitlement()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer('sat_clear_entitlement');

    sat_clear_entitlement();
    sat_clear_license_activation();
    delete_option('sat_license_installation_token');
    sat_unschedule_license_heartbeat();

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

function sat_handle_buy_premium()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer('sat_buy_premium');

    $response = sat_stripe_checkout_remote('premium');

    if (is_wp_error($response)) {
        sat_set_premium_notice(
            'error',
            $response->get_error_message()
        );

        wp_safe_redirect(
            admin_url('admin.php?page=site-admin-toolkit&tab=premium')
        );
        exit;
    }

    $checkout_url = isset($response['checkout']['url'])
        ? esc_url_raw($response['checkout']['url'])
        : '';

    $checkout_host = $checkout_url
        ? strtolower((string) wp_parse_url($checkout_url, PHP_URL_HOST))
        : '';

    if (
        !$checkout_url ||
        $checkout_host !== 'checkout.stripe.com'
    ) {
        sat_set_premium_notice(
            'error',
            'The licensing server returned an invalid checkout URL.'
        );

        wp_safe_redirect(
            admin_url('admin.php?page=site-admin-toolkit&tab=premium')
        );
        exit;
    }

    wp_redirect($checkout_url, 303);
    exit;
}

add_action(
    'admin_post_sat_buy_premium',
    'sat_handle_buy_premium'
);
