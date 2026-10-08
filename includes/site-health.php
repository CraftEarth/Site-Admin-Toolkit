<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get Site Admin Toolkit health information.
 */
function sat_get_site_health_data()
{
    global $wpdb;

    // Refresh WordPress update information.
    if (function_exists('wp_version_check')) {
        wp_version_check();
    }

    if (function_exists('wp_update_plugins')) {
        wp_update_plugins();
    }

    if (function_exists('wp_update_themes')) {
        wp_update_themes();
    }

    $plugin_updates = get_site_transient('update_plugins');
    $theme_updates  = get_site_transient('update_themes');

    $plugin_update_count = 0;
    $theme_update_count  = 0;

    if (
        is_object($plugin_updates) &&
        !empty($plugin_updates->response)
    ) {
        $plugin_update_count = count($plugin_updates->response);
    }

    if (
        is_object($theme_updates) &&
        !empty($theme_updates->response)
    ) {
        $theme_update_count = count($theme_updates->response);
    }

    $core_update_available = false;

    if (function_exists('get_core_updates')) {
        $core_updates = get_core_updates([
            'dismissed' => false,
        ]);

        if (is_array($core_updates)) {
            foreach ($core_updates as $update) {
                if (
                    isset($update->response) &&
                    $update->response === 'upgrade'
                ) {
                    $core_update_available = true;
                    break;
                }
            }
        }
    }

    $https_enabled = is_ssl();

    $debug_enabled =
        defined('WP_DEBUG') &&
        WP_DEBUG;

    $cron_disabled =
        defined('DISABLE_WP_CRON') &&
        DISABLE_WP_CRON;

    $rest_status = sat_check_rest_api();

    $theme = wp_get_theme();

    $php_status =
        version_compare(PHP_VERSION, '8.1', '>=')
        ? 'good'
        : 'warning';

    $checks = [
        [
            'label' => 'WordPress Core',
            'value' => $core_update_available
                ? 'Update Available'
                : 'Up to Date',
            'status' => $core_update_available
                ? 'warning'
                : 'good',
            'message' => $core_update_available
                ? 'A WordPress core update is available.'
                : 'WordPress core appears current.',
        ],

        [
            'label' => 'Plugin Updates',
            'value' => (string) $plugin_update_count,
            'status' => $plugin_update_count > 0
                ? 'warning'
                : 'good',
            'message' => $plugin_update_count > 0
                ? $plugin_update_count . ' plugin update(s) available.'
                : 'No plugin updates detected.',
        ],

        [
            'label' => 'Theme Updates',
            'value' => (string) $theme_update_count,
            'status' => $theme_update_count > 0
                ? 'warning'
                : 'good',
            'message' => $theme_update_count > 0
                ? $theme_update_count . ' theme update(s) available.'
                : 'No theme updates detected.',
        ],

        [
            'label' => 'PHP Version',
            'value' => PHP_VERSION,
            'status' => $php_status,
            'message' => $php_status === 'good'
                ? 'PHP version meets the toolkit baseline.'
                : 'PHP is below the toolkit baseline of 8.1 and should be reviewed.',
        ],

        [
            'label' => 'HTTPS',
            'value' => $https_enabled
                ? 'Enabled'
                : 'Not Detected',
            'status' => $https_enabled
                ? 'good'
                : 'warning',
            'message' => $https_enabled
                ? 'The current request is using HTTPS.'
                : 'HTTPS was not detected for this request.',
        ],

        [
            'label' => 'Debug Mode',
            'value' => $debug_enabled
                ? 'Enabled'
                : 'Disabled',
            'status' => $debug_enabled
                ? 'warning'
                : 'good',
            'message' => $debug_enabled
                ? 'WP_DEBUG is enabled. Review this before using the site in production.'
                : 'WP_DEBUG is disabled.',
        ],

        [
            'label' => 'WP-Cron',
            'value' => $cron_disabled
                ? 'Disabled'
                : 'Enabled',
            'status' => $cron_disabled
                ? 'warning'
                : 'good',
            'message' => $cron_disabled
                ? 'Built-in WP-Cron is disabled. Confirm an external cron job exists.'
                : 'Built-in WP-Cron is enabled.',
        ],

        [
            'label' => 'REST API',
            'value' => $rest_status['value'],
            'status' => $rest_status['status'],
            'message' => $rest_status['message'],
        ],
    ];

    $warning_count = 0;
    $critical_count = 0;

    foreach ($checks as $check) {
        if ($check['status'] === 'warning') {
            $warning_count++;
        }

        if ($check['status'] === 'critical') {
            $critical_count++;
        }
    }

    if ($critical_count > 0) {
        $overall_status = 'critical';
        $overall_label = 'Critical';
    } elseif ($warning_count > 0) {
        $overall_status = 'warning';
        $overall_label = 'Needs Attention';
    } else {
        $overall_status = 'good';
        $overall_label = 'Good';
    }

    return [
        'overall_status' => $overall_status,
        'overall_label' => $overall_label,
        'checks' => $checks,

        'details' => [
            'WordPress Version' => get_bloginfo('version'),
            'PHP Version' => PHP_VERSION,
            'Database Version' => $wpdb->db_version(),
            'Active Theme' => $theme->get('Name'),
            'Theme Version' => $theme->get('Version'),
            'Memory Limit' => ini_get('memory_limit'),
            'Upload Limit' => size_format(wp_max_upload_size()),
            'Site URL' => site_url(),
            'Home URL' => home_url(),
        ],
    ];
}


/**
 * Perform a lightweight REST API check.
 */
function sat_check_rest_api()
{
    $url = rest_url();

    $response = wp_remote_get(
        $url,
        [
            'timeout' => 5,
            'redirection' => 3,
        ]
    );

    if (is_wp_error($response)) {
        return [
            'value' => 'Unavailable',
            'status' => 'critical',
            'message' =>
                'The REST API request failed: ' .
                $response->get_error_message(),
        ];
    }

    $code = wp_remote_retrieve_response_code($response);

    if ($code >= 200 && $code < 400) {
        return [
            'value' => 'Available',
            'status' => 'good',
            'message' => 'The WordPress REST API responded successfully.',
        ];
    }

    return [
        'value' => 'Problem Detected',
        'status' => 'critical',
        'message' =>
            'The REST API returned HTTP status ' .
            absint($code) .
            '.',
    ];
}


/**
 * Render the Site Health panel.
 */
function sat_render_site_health()
{
    $health = sat_get_site_health_data();

    ?>
    <div class="sat-panel sat-health-panel">

        <div class="sat-health-heading">

            <div>
                <h2>Site Health & Updates</h2>

                <p>
                    Quick maintenance checks for common WordPress
                    support and administration tasks.
                </p>
            </div>

            <span class="sat-status sat-status-<?php
                echo esc_attr(
                    $health['overall_status']
                );
            ?>">
                <?php
                echo esc_html(
                    $health['overall_label']
                );
                ?>
            </span>

        </div>

        <div class="sat-health-grid">

            <?php foreach ($health['checks'] as $check) : ?>

                <div class="sat-health-card">

                    <div class="sat-health-card-top">

                        <strong>
                            <?php
                            echo esc_html(
                                $check['label']
                            );
                            ?>
                        </strong>

                        <span class="sat-status sat-status-<?php
                            echo esc_attr(
                                $check['status']
                            );
                        ?>">
                            <?php
                            echo esc_html(
                                ucfirst(
                                    $check['status']
                                )
                            );
                            ?>
                        </span>

                    </div>

                    <div class="sat-health-value">
                        <?php
                        echo esc_html(
                            $check['value']
                        );
                        ?>
                    </div>

                    <p>
                        <?php
                        echo esc_html(
                            $check['message']
                        );
                        ?>
                    </p>

                </div>

            <?php endforeach; ?>

        </div>

        <h3>System Details</h3>

        <table class="widefat striped">

            <tbody>

                <?php
                foreach (
                    $health['details']
                    as $label => $value
                ) :
                ?>

                    <tr>

                        <td>
                            <strong>
                                <?php
                                echo esc_html($label);
                                ?>
                            </strong>
                        </td>

                        <td>
                            <?php
                            echo esc_html($value);
                            ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>
    <?php
}
