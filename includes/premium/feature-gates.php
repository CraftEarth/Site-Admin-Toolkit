<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single gate used throughout Site Admin Toolkit.
 *
 * Future modules should ask this function instead of duplicating
 * license logic.
 */
function sat_feature_enabled($feature_key)
{
    $feature = sat_get_feature($feature_key);

    if (!$feature) {
        return false;
    }

    if ($feature['plan'] === 'free') {
        return true;
    }

    if (sat_dev_premium_enabled()) {
        return true;
    }

    $entitlement = sat_get_entitlement();

    if (!sat_entitlement_is_active($entitlement)) {
        return false;
    }

    if (
        empty($entitlement['features'])
        || !is_array($entitlement['features'])
    ) {
        return false;
    }

    return in_array(
        $feature_key,
        $entitlement['features'],
        true
    );
}

function sat_feature_locked($feature_key)
{
    return !sat_feature_enabled($feature_key);
}

function sat_feature_plan($feature_key)
{
    $feature = sat_get_feature($feature_key);

    return $feature && isset($feature['plan'])
        ? $feature['plan']
        : null;
}
