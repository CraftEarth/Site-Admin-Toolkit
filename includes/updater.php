<?php

if (!defined('ABSPATH')) {
    exit;
}

define(
    'SAT_UPDATE_API',
    'https://licenses.51-81-87-159.sslip.io/v1/updates/check'
);

/**
 * Fetch release metadata from the Site Admin Toolkit update server.
 */
function sat_get_update_metadata()
{
    $cached = get_site_transient('sat_update_metadata');

    if (is_array($cached)) {
        return $cached;
    }

    $response = wp_remote_get(
        SAT_UPDATE_API,
        [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]
    );

    if (is_wp_error($response)) {
        return false;
    }

    $status = wp_remote_retrieve_response_code($response);

    if ($status !== 200) {
        return false;
    }

    $body = json_decode(
        wp_remote_retrieve_body($response),
        true
    );

    if (
        !is_array($body) ||
        empty($body['ok']) ||
        empty($body['plugin']) ||
        !is_array($body['plugin'])
    ) {
        return false;
    }

    $plugin = $body['plugin'];

    if (
        empty($plugin['slug']) ||
        empty($plugin['version']) ||
        empty($plugin['package'])
    ) {
        return false;
    }

    set_site_transient(
        'sat_update_metadata',
        $plugin,
        6 * HOUR_IN_SECONDS
    );

    return $plugin;
}


/**
 * Add Site Admin Toolkit to WordPress update checks.
 */
function sat_check_for_plugin_update($transient)
{
    if (
        !is_object($transient) ||
        empty($transient->checked)
    ) {
        return $transient;
    }

    $metadata = sat_get_update_metadata();

    if (!$metadata) {
        return $transient;
    }

    if (
        version_compare(
            SAT_VERSION,
            $metadata['version'],
            '>='
        )
    ) {
        return $transient;
    }

    $plugin_file =
        plugin_basename(
            SAT_PLUGIN_DIR . 'site-admin-toolkit.php'
        );

    $update = new stdClass();

    $update->slug =
        'site-admin-toolkit';

    $update->plugin =
        $plugin_file;

    $update->new_version =
        $metadata['version'];

    $update->package =
        $metadata['package'];

    $update->url =
        'https://github.com/CraftEarth/Site-Admin-Toolkit';

    if (!empty($metadata['tested'])) {
        $update->tested =
            $metadata['tested'];
    }

    if (!empty($metadata['requires'])) {
        $update->requires =
            $metadata['requires'];
    }

    if (!empty($metadata['requires_php'])) {
        $update->requires_php =
            $metadata['requires_php'];
    }

    $transient->response[
        $plugin_file
    ] = $update;

    return $transient;
}

add_filter(
    'site_transient_update_plugins',
    'sat_check_for_plugin_update'
);


/**
 * Provide details for the WordPress plugin information popup.
 */
function sat_plugin_information(
    $result,
    $action,
    $args
) {
    if (
        $action !== 'plugin_information' ||
        empty($args->slug) ||
        $args->slug !== 'site-admin-toolkit'
    ) {
        return $result;
    }

    $metadata = sat_get_update_metadata();

    if (!$metadata) {
        return $result;
    }

    $info = new stdClass();

    $info->name =
        'Site Admin Toolkit';

    $info->slug =
        'site-admin-toolkit';

    $info->version =
        $metadata['version'];

    $info->author =
        'William Murphy / CraftEarth';

    $info->homepage =
        'https://github.com/CraftEarth/Site-Admin-Toolkit';

    $info->download_link =
        $metadata['package'];

    $info->requires =
        $metadata['requires'] ?? '';

    $info->tested =
        $metadata['tested'] ?? '';

    $info->requires_php =
        $metadata['requires_php'] ?? '';

    $info->sections = [
        'description' =>
            'Site Admin Toolkit provides WordPress administration, security, diagnostics, monitoring and premium management tools.',

        'changelog' =>
            !empty($metadata['changelog'])
                ? nl2br(
                    esc_html(
                        $metadata['changelog']
                    )
                )
                : 'No changelog available.',
    ];

    return $info;
}

add_filter(
    'plugins_api',
    'sat_plugin_information',
    20,
    3
);


/**
 * Clear cached release data after plugin updates.
 */
function sat_clear_update_cache(
    $upgrader,
    $options
) {
    if (
        empty($options['action']) ||
        $options['action'] !== 'update'
    ) {
        return;
    }

    delete_site_transient(
        'sat_update_metadata'
    );
}

add_action(
    'upgrader_process_complete',
    'sat_clear_update_cache',
    10,
    2
);