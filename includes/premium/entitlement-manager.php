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
            'plan' => 'premium',
            'status' => 'active',
            'source' => 'development',
            'sites_allowed' => 999,
            'expires_at' => null,
            'last_verified_at' => current_time('mysql', true),
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
        'plan' => isset($entitlement['plan'])
            ? sanitize_key($entitlement['plan'])
            : 'free',

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
            : current_time('mysql', true),

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

function sat_entitlement_summary()
{
    $entitlement = sat_get_entitlement();

    if (sat_dev_premium_enabled()) {
        return [
            'plan' => 'Premium',
            'status' => 'Development Mode',
            'expires' => 'Never',
            'source' => 'Local wp-config.php',
        ];
    }

    if (!sat_entitlement_is_active($entitlement)) {
        return [
            'plan' => 'Free',
            'status' => 'Active',
            'expires' => 'N/A',
            'source' => 'Community',
        ];
    }

    return [
        'plan' => ucfirst((string) ($entitlement['plan'] ?? 'premium')),
        'status' => ucfirst((string) ($entitlement['status'] ?? 'active')),
        'expires' => !empty($entitlement['expires_at'])
            ? $entitlement['expires_at']
            : 'No expiration supplied',
        'source' => ucfirst((string) ($entitlement['source'] ?? 'server')),
    ];
}
