<?php

if (!defined('ABSPATH')) {
    exit;
}

function sat_entitlement_option()
{
    return 'sat_premium_entitlement';
}

function sat_installation_id_option()
{
    return 'sat_installation_id';
}

function sat_license_policy_option()
{
    return 'sat_license_policy';
}

function sat_license_activation_option()
{
    return 'sat_license_activation';
}

function sat_get_installation_id()
{
    $id = get_option(sat_installation_id_option(), '');

    if (!$id) {
        $id = wp_generate_uuid4();
        update_option(sat_installation_id_option(), $id, false);
    }

    return $id;
}

function sat_dev_premium_enabled()
{
    return defined('SAT_LICENSE_DEV_MODE')
        && SAT_LICENSE_DEV_MODE === true;
}

function sat_get_entitlement()
{
    if (sat_dev_premium_enabled()) {
        return [
            'product' => 'site-admin-toolkit',
            'plan' => 'premium',
            'status' => 'active',
            'source' => 'development',
            'sites_allowed' => 999,
            'expires_at' => null,
            'last_verified_at' => gmdate('c'),
            'features' => array_keys(sat_feature_registry()),
        ];
    }

    $entitlement = get_option(sat_entitlement_option(), []);

    return is_array($entitlement)
        ? $entitlement
        : [];
}

function sat_save_entitlement($entitlement)
{
    if (!is_array($entitlement)) {
        return false;
    }

    $allowed_statuses = [
        'active',
        'trial',
        'grace',
        'expired',
        'inactive',
        'revoked',
        'disabled',
        'past_due',
        'canceled',
        'unpaid',
    ];

    $status = isset($entitlement['status'])
        ? sanitize_key($entitlement['status'])
        : 'inactive';

    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'inactive';
    }

    $features = [];

    if (!empty($entitlement['features']) && is_array($entitlement['features'])) {
        foreach ($entitlement['features'] as $feature) {
            $features[] = sanitize_key($feature);
        }
    }

    $clean = [
        'product' => isset($entitlement['product'])
            ? sanitize_key($entitlement['product'])
            : 'site-admin-toolkit',

        'product_name' => isset($entitlement['product_name'])
            ? sanitize_text_field($entitlement['product_name'])
            : 'Site Admin Toolkit',

        'plan' => isset($entitlement['plan'])
            ? sanitize_key($entitlement['plan'])
            : 'free',

        'plan_name' => isset($entitlement['plan_name'])
            ? sanitize_text_field($entitlement['plan_name'])
            : '',

        'status' => $status,

        'source' => isset($entitlement['source'])
            ? sanitize_key($entitlement['source'])
            : 'server',

        'sites_allowed' => isset($entitlement['sites_allowed'])
            ? max(1, absint($entitlement['sites_allowed']))
            : 1,

        'expires_at' => !empty($entitlement['expires_at'])
            ? sanitize_text_field($entitlement['expires_at'])
            : null,

        'last_verified_at' => !empty($entitlement['last_verified_at'])
            ? sanitize_text_field($entitlement['last_verified_at'])
            : gmdate('c'),

        'features' => array_values(array_unique($features)),
    ];

    return update_option(
        sat_entitlement_option(),
        $clean,
        false
    );
}

function sat_clear_entitlement()
{
    delete_option(sat_entitlement_option());
}

function sat_get_license_policy()
{
    $defaults = [
        'validate_interval_hours' => 24,
        'grace_days' => 7,
    ];

    $policy = get_option(sat_license_policy_option(), []);

    if (!is_array($policy)) {
        $policy = [];
    }

    return [
        'validate_interval_hours' => !empty($policy['validate_interval_hours'])
            ? max(1, absint($policy['validate_interval_hours']))
            : $defaults['validate_interval_hours'],

        'grace_days' => !empty($policy['grace_days'])
            ? max(1, absint($policy['grace_days']))
            : $defaults['grace_days'],
    ];
}

function sat_save_license_policy($policy)
{
    if (!is_array($policy)) {
        return false;
    }

    $clean = [
        'validate_interval_hours' => !empty($policy['validate_interval_hours'])
            ? max(1, absint($policy['validate_interval_hours']))
            : 24,

        'grace_days' => !empty($policy['grace_days'])
            ? max(1, absint($policy['grace_days']))
            : 7,
    ];

    return update_option(
        sat_license_policy_option(),
        $clean,
        false
    );
}

function sat_get_license_activation()
{
    $activation = get_option(sat_license_activation_option(), []);

    return is_array($activation)
        ? $activation
        : [];
}

function sat_save_license_activation($activation)
{
    if (!is_array($activation)) {
        return false;
    }

    $clean = [
        'id' => isset($activation['id'])
            ? absint($activation['id'])
            : 0,

        'installation_id' => isset($activation['installation_id'])
            ? sanitize_text_field($activation['installation_id'])
            : sat_get_installation_id(),

        'site_url' => isset($activation['site_url'])
            ? esc_url_raw($activation['site_url'])
            : home_url(),

        'site_host' => isset($activation['site_host'])
            ? sanitize_text_field($activation['site_host'])
            : '',

        'status' => isset($activation['status'])
            ? sanitize_key($activation['status'])
            : 'active',

        'plugin_version' => isset($activation['plugin_version'])
            ? sanitize_text_field($activation['plugin_version'])
            : '',

        'last_seen_at' => !empty($activation['last_seen_at'])
            ? sanitize_text_field($activation['last_seen_at'])
            : null,

        'deactivated_at' => !empty($activation['deactivated_at'])
            ? sanitize_text_field($activation['deactivated_at'])
            : null,

        'sites_active' => isset($activation['sites_active'])
            ? absint($activation['sites_active'])
            : null,

        'sites_allowed' => isset($activation['sites_allowed'])
            ? max(1, absint($activation['sites_allowed']))
            : null,
    ];

    return update_option(
        sat_license_activation_option(),
        $clean,
        false
    );
}

function sat_clear_license_activation()
{
    delete_option(sat_license_activation_option());
}

function sat_entitlement_is_active($entitlement = null)
{
    if ($entitlement === null) {
        $entitlement = sat_get_entitlement();
    }

    if (empty($entitlement) || empty($entitlement['status'])) {
        return false;
    }

    return in_array(
        $entitlement['status'],
        ['active', 'trial', 'grace'],
        true
    );
}

function sat_current_plan()
{
    $entitlement = sat_get_entitlement();

    if (!sat_entitlement_is_active($entitlement)) {
        return 'free';
    }

    return !empty($entitlement['plan'])
        ? $entitlement['plan']
        : 'premium';
}

function sat_format_entitlement_expiration($value)
{
    if (empty($value)) {
        return 'Lifetime';
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        return sanitize_text_field($value);
    }

    return wp_date(
        get_option('date_format'),
        $timestamp
    );
}

function sat_format_license_datetime($value)
{
    if (empty($value)) {
        return 'Not yet';
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        return sanitize_text_field($value);
    }

    return wp_date(
        get_option('date_format') . ' ' . get_option('time_format'),
        $timestamp
    );
}

function sat_license_last_verified_timestamp()
{
    $entitlement = sat_get_entitlement();

    if (empty($entitlement['last_verified_at'])) {
        return 0;
    }

    $timestamp = strtotime($entitlement['last_verified_at']);

    return $timestamp
        ? $timestamp
        : 0;
}

function sat_license_in_grace_window()
{
    $last_verified = sat_license_last_verified_timestamp();

    if (!$last_verified) {
        return false;
    }

    $policy = sat_get_license_policy();
    $grace_seconds = DAY_IN_SECONDS * max(1, absint($policy['grace_days']));

    return (time() - $last_verified) <= $grace_seconds;
}

function sat_entitlement_summary()
{
    $entitlement = sat_get_entitlement();
    $activation = sat_get_license_activation();

    if (sat_dev_premium_enabled()) {
        return [
            'plan' => 'Premium',
            'status' => 'Development Mode',
            'expires' => 'Never',
            'source' => 'Local wp-config.php',
            'last_checked' => 'Development Mode',
            'sites' => 'Unlimited',
        ];
    }

    if (!sat_entitlement_is_active($entitlement)) {
        return [
            'plan' => 'Free',
            'status' => 'Active',
            'expires' => 'N/A',
            'source' => 'Community',
            'last_checked' => !empty($entitlement['last_verified_at'])
                ? sat_format_license_datetime($entitlement['last_verified_at'])
                : 'Not yet',
            'sites' => 'N/A',
        ];
    }

    $sites_allowed = !empty($entitlement['sites_allowed'])
        ? absint($entitlement['sites_allowed'])
        : 1;

    $sites_active = isset($activation['sites_active'])
        ? absint($activation['sites_active'])
        : null;

    return [
        'plan' => !empty($entitlement['plan_name'])
            ? $entitlement['plan_name']
            : ucfirst((string) ($entitlement['plan'] ?? 'premium')),

        'status' => ucfirst((string) ($entitlement['status'] ?? 'active')),

        'expires' => !empty($entitlement['expires_at'])
            ? sat_format_entitlement_expiration($entitlement['expires_at'])
            : 'Lifetime',

        'source' => ucfirst((string) ($entitlement['source'] ?? 'server')),

        'last_checked' => !empty($entitlement['last_verified_at'])
            ? sat_format_license_datetime($entitlement['last_verified_at'])
            : 'Not yet',

        'sites' => $sites_active !== null
            ? $sites_active . ' of ' . $sites_allowed
            : (string) $sites_allowed,
    ];
}
