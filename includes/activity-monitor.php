<?php

if (!defined('ABSPATH')) {
    exit;
}

define('SAT_ACTIVITY_DB_VERSION', '1.0');


/**
 * Create/update the activity table.
 */
function sat_activity_install()
{
    global $wpdb;

    $installed =
        get_option('sat_activity_db_version');

    if ($installed === SAT_ACTIVITY_DB_VERSION) {
        return;
    }

    require_once ABSPATH .
        'wp-admin/includes/upgrade.php';

    $table =
        $wpdb->prefix .
        'sat_activity';

    $charset =
        $wpdb->get_charset_collate();

    $sql = "
    CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        direction VARCHAR(10) NOT NULL DEFAULT 'inbound',
        event_type VARCHAR(60) NOT NULL,
        method VARCHAR(10) NULL,
        endpoint TEXT NULL,
        status_code SMALLINT UNSIGNED NULL,
        ip_address VARCHAR(45) NULL,
        user_id BIGINT UNSIGNED NULL,
        duration_ms DECIMAL(12,2) NULL,
        risk_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        details TEXT NULL,
        PRIMARY KEY (id),
        KEY event_type (event_type),
        KEY created_at (created_at),
        KEY ip_address (ip_address),
        KEY risk_score (risk_score)
    ) {$charset};
    ";

    dbDelta($sql);

    update_option(
        'sat_activity_db_version',
        SAT_ACTIVITY_DB_VERSION
    );
}

add_action(
    'admin_init',
    'sat_activity_install'
);


/**
 * Return activity table name.
 */
function sat_activity_table()
{
    global $wpdb;

    return $wpdb->prefix .
        'sat_activity';
}


/**
 * Get visitor IP.
 *
 * We intentionally do not trust proxy headers automatically.
 */
function sat_activity_ip()
{
    return isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(
            wp_unslash(
                $_SERVER['REMOTE_ADDR']
            )
        )
        : '';
}


/**
 * Strip query strings so tokens/passwords are not logged.
 */
function sat_safe_endpoint($value)
{
    if (!$value) {
        return '';
    }

    $value =
        wp_unslash($value);

    $parts =
        wp_parse_url($value);

    if (!$parts) {
        return '';
    }

    $endpoint = '';

    if (!empty($parts['host'])) {
        $endpoint .=
            (!empty($parts['scheme'])
                ? $parts['scheme'] . '://'
                : '') .
            $parts['host'];
    }

    if (!empty($parts['path'])) {
        $endpoint .= $parts['path'];
    }

    return sanitize_text_field(
        substr($endpoint, 0, 1500)
    );
}


/**
 * Write an activity event.
 */
function sat_log_activity(
    $event_type,
    $args = []
) {
    global $wpdb;

    $defaults = [
        'direction' => 'inbound',
        'method' => '',
        'endpoint' => '',
        'status_code' => null,
        'ip_address' => sat_activity_ip(),
        'user_id' => get_current_user_id(),
        'duration_ms' => null,
        'risk_score' => 0,
        'details' => '',
    ];

    $data =
        wp_parse_args(
            $args,
            $defaults
        );

    $wpdb->insert(
        sat_activity_table(),
        [
            'created_at' =>
                current_time('mysql'),

            'direction' =>
                sanitize_key(
                    $data['direction']
                ),

            'event_type' =>
                sanitize_key(
                    $event_type
                ),

            'method' =>
                sanitize_text_field(
                    strtoupper(
                        $data['method']
                    )
                ),

            'endpoint' =>
                sat_safe_endpoint(
                    $data['endpoint']
                ),

            'status_code' =>
                $data['status_code'] !== null
                    ? absint(
                        $data['status_code']
                    )
                    : null,

            'ip_address' =>
                sanitize_text_field(
                    $data['ip_address']
                ),

            'user_id' =>
                absint(
                    $data['user_id']
                ),

            'duration_ms' =>
                $data['duration_ms'],

            'risk_score' =>
                min(
                    100,
                    absint(
                        $data['risk_score']
                    )
                ),

            'details' =>
                sanitize_textarea_field(
                    substr(
                        $data['details'],
                        0,
                        3000
                    )
                ),
        ]
    );
}


/**
 * Successful login.
 */
function sat_monitor_login(
    $user_login,
    $user
) {
    sat_log_activity(
        'login_success',
        [
            'method' => 'POST',
            'endpoint' => '/wp-login.php',
            'user_id' => $user->ID,
            'risk_score' => 0,
            'details' =>
                'Successful login for user: ' .
                $user_login,
        ]
    );
}

add_action(
    'wp_login',
    'sat_monitor_login',
    10,
    2
);


/**
 * Failed login.
 */
function sat_monitor_failed_login(
    $username
) {
    sat_log_activity(
        'login_failed',
        [
            'method' => 'POST',
            'endpoint' => '/wp-login.php',
            'risk_score' => 20,
            'details' =>
                'Failed login attempt for username: ' .
                sanitize_user($username),
        ]
    );
}

add_action(
    'wp_login_failed',
    'sat_monitor_failed_login'
);


/**
 * Logout.
 */
function sat_monitor_logout()
{
    sat_log_activity(
        'logout',
        [
            'endpoint' => '/wp-login.php',
            'risk_score' => 0,
            'details' =>
                'Authenticated user logged out.',
        ]
    );
}

add_action(
    'wp_logout',
    'sat_monitor_logout'
);


/**
 * New user creation.
 */
function sat_monitor_user_register(
    $user_id
) {
    $user =
        get_userdata($user_id);

    sat_log_activity(
        'user_created',
        [
            'endpoint' => '/wp-admin/',
            'user_id' => $user_id,
            'risk_score' => 15,
            'details' =>
                $user
                    ? 'New WordPress user created: ' .
                      $user->user_login
                    : 'New WordPress user created.',
        ]
    );
}

add_action(
    'user_register',
    'sat_monitor_user_register'
);


/**
 * Plugin activation.
 */
function sat_monitor_plugin_activation(
    $plugin
) {
    sat_log_activity(
        'plugin_activated',
        [
            'endpoint' => '/wp-admin/plugins.php',
            'risk_score' => 10,
            'details' =>
                'Plugin activated: ' .
                $plugin,
        ]
    );
}

add_action(
    'activated_plugin',
    'sat_monitor_plugin_activation'
);


/**
 * Plugin deactivation.
 */
function sat_monitor_plugin_deactivation(
    $plugin
) {
    sat_log_activity(
        'plugin_deactivated',
        [
            'endpoint' => '/wp-admin/plugins.php',
            'risk_score' => 5,
            'details' =>
                'Plugin deactivated: ' .
                $plugin,
        ]
    );
}

add_action(
    'deactivated_plugin',
    'sat_monitor_plugin_deactivation'
);


/**
 * Outbound WordPress HTTP traffic.
 */
function sat_monitor_http_outbound(
    $response,
    $context,
    $class,
    $parsed_args,
    $url
) {
    if ($context !== 'response') {
        return;
    }

    $status = null;

    if (
        !is_wp_error($response)
        && isset(
            $response['response']['code']
        )
    ) {
        $status =
            absint(
                $response[
                    'response'
                ]['code']
            );
    }

    sat_log_activity(
        'http_outbound',
        [
            'direction' => 'outbound',

            'method' =>
                isset(
                    $parsed_args['method']
                )
                    ? $parsed_args['method']
                    : 'GET',

            'endpoint' => $url,

            'status_code' => $status,

            'ip_address' => '',

            'risk_score' =>
                is_wp_error($response)
                    ? 10
                    : 0,

            'details' =>
                is_wp_error($response)
                    ? 'Outbound HTTP request failed: ' .
                      $response->get_error_message()
                    : 'Outbound WordPress HTTP request.',
        ]
    );
}

add_action(
    'http_api_debug',
    'sat_monitor_http_outbound',
    10,
    5
);


/**
 * Capture selected inbound traffic at shutdown.
 */
function sat_monitor_request_shutdown()
{
    if (
        defined('DOING_CRON') &&
        DOING_CRON
    ) {
        return;
    }

    $uri =
        isset($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI']
            : '';

    $method =
        isset($_SERVER['REQUEST_METHOD'])
            ? $_SERVER['REQUEST_METHOD']
            : 'GET';

    $status =
        http_response_code();

    $event = null;
    $risk = 0;
    $details = '';

    if (
        defined('XMLRPC_REQUEST')
        && XMLRPC_REQUEST
    ) {
        $event = 'xmlrpc_request';
        $risk = 15;
        $details =
            'XML-RPC request detected.';
    }
    elseif (
        defined('REST_REQUEST')
        && REST_REQUEST
    ) {
        $event = 'rest_request';
        $risk =
            strtoupper($method) === 'POST'
                ? 3
                : 0;

        $details =
            'WordPress REST API request.';
    }
    elseif (
        function_exists('is_404')
        && is_404()
    ) {
        $event = '404_probe';
        $risk = 5;
        $details =
            'Request resulted in HTTP 404.';
    }
    elseif (
        strtoupper($method) === 'POST'
    ) {
        $event = 'post_request';
        $risk = 2;
        $details =
            'Inbound POST request.';
    }

    if (!$event) {
        return;
    }

    sat_log_activity(
        $event,
        [
            'direction' => 'inbound',
            'method' => $method,
            'endpoint' => $uri,
            'status_code' => $status,
            'risk_score' => $risk,
            'details' => $details,
        ]
    );
}

add_action(
    'shutdown',
    'sat_monitor_request_shutdown'
);


/**
 * Remove old activity automatically.
 *
 * Default retention: 14 days.
 */
function sat_activity_cleanup()
{
    global $wpdb;

    $retention_days = 14;

    $cutoff =
        gmdate(
            'Y-m-d H:i:s',
            time() -
            (
                DAY_IN_SECONDS *
                $retention_days
            )
        );

    $wpdb->query(
        $wpdb->prepare(
            "
            DELETE FROM " .
            sat_activity_table() .
            "
            WHERE created_at < %s
            ",
            $cutoff
        )
    );
}

add_action(
    'admin_init',
    'sat_activity_cleanup'
);


/**
 * Generate safe local test events.
 */
function sat_generate_test_events()
{
    if (
        !current_user_can(
            'manage_options'
        )
    ) {
        wp_die(
            'Insufficient permissions.'
        );
    }

    check_admin_referer(
        'sat_generate_test_events'
    );

    $environment =
        function_exists(
            'wp_get_environment_type'
        )
            ? wp_get_environment_type()
            : 'production';

    if (
        $environment === 'production'
        && !in_array(
            sat_activity_ip(),
            [
                '127.0.0.1',
                '::1',
            ],
            true
        )
    ) {
        wp_die(
            'Test events are restricted to local or non-production environments.'
        );
    }

    $ip =
        sat_activity_ip();

    sat_log_activity(
        'login_failed',
        [
            'ip_address' => $ip,
            'endpoint' => '/wp-login.php',
            'method' => 'POST',
            'risk_score' => 20,
            'details' =>
                '[SIMULATED] Failed login attempt.',
        ]
    );

    sat_log_activity(
        'login_failed',
        [
            'ip_address' => $ip,
            'endpoint' => '/wp-login.php',
            'method' => 'POST',
            'risk_score' => 20,
            'details' =>
                '[SIMULATED] Failed login attempt.',
        ]
    );

    sat_log_activity(
        '404_probe',
        [
            'ip_address' => $ip,
            'endpoint' =>
                '/wp-content/plugins/fake-plugin/shell.php',
            'method' => 'GET',
            'status_code' => 404,
            'risk_score' => 15,
            'details' =>
                '[SIMULATED] Plugin-path reconnaissance probe.',
        ]
    );

    sat_log_activity(
        'xmlrpc_request',
        [
            'ip_address' => $ip,
            'endpoint' => '/xmlrpc.php',
            'method' => 'POST',
            'risk_score' => 20,
            'details' =>
                '[SIMULATED] XML-RPC activity.',
        ]
    );

    sat_log_activity(
        'http_outbound',
        [
            'direction' => 'outbound',
            'endpoint' =>
                'https://api.example.com/security-test',
            'method' => 'POST',
            'status_code' => 200,
            'ip_address' => '',
            'risk_score' => 5,
            'details' =>
                '[SIMULATED] Outbound API request.',
        ]
    );

    wp_safe_redirect(
        admin_url(
            'admin.php?page=site-admin-toolkit&sat_test=1'
        )
    );

    exit;
}

add_action(
    'admin_post_sat_generate_test_events',
    'sat_generate_test_events'
);


/**
 * Get recent events.
 */
function sat_get_recent_activity(
    $limit = 100
) {
    global $wpdb;

    $limit =
        min(
            500,
            max(
                1,
                absint($limit)
            )
        );

    return $wpdb->get_results(
        "
        SELECT *
        FROM " .
        sat_activity_table() .
        "
        ORDER BY id DESC
        LIMIT {$limit}
        ",
        ARRAY_A
    );
}


/**
 * Calculate threat activity by IP.
 */
function sat_get_threat_summary()
{
    global $wpdb;

    return $wpdb->get_results(
        "
        SELECT
            ip_address,
            COUNT(*) AS event_count,
            SUM(risk_score) AS total_risk,
            SUM(
                CASE
                    WHEN event_type = 'login_failed'
                    THEN 1 ELSE 0
                END
            ) AS failed_logins,
            SUM(
                CASE
                    WHEN event_type = '404_probe'
                    THEN 1 ELSE 0
                END
            ) AS probes,
            SUM(
                CASE
                    WHEN event_type = 'xmlrpc_request'
                    THEN 1 ELSE 0
                END
            ) AS xmlrpc_requests
        FROM " .
        sat_activity_table() .
        "
        WHERE
            ip_address IS NOT NULL
            AND ip_address <> ''
            AND created_at >= DATE_SUB(
                NOW(),
                INTERVAL 24 HOUR
            )
        GROUP BY ip_address
        ORDER BY total_risk DESC
        LIMIT 25
        ",
        ARRAY_A
    );
}


/**
 * Translate accumulated activity risk.
 */
function sat_risk_label($score)
{
    $score =
        absint($score);

    if ($score >= 80) {
        return [
            'label' => 'Critical',
            'status' => 'critical',
        ];
    }

    if ($score >= 50) {
        return [
            'label' => 'High Risk',
            'status' => 'critical',
        ];
    }

    if ($score >= 25) {
        return [
            'label' => 'Suspicious',
            'status' => 'warning',
        ];
    }

    return [
        'label' => 'Normal',
        'status' => 'good',
    ];
}


/**
 * Render monitor.
 */
function sat_render_activity_monitor()
{
    if (
        !current_user_can(
            'manage_options'
        )
    ) {
        return;
    }

    $events =
        sat_get_recent_activity(100);

    $threats =
        sat_get_threat_summary();

    ?>
    <div class="sat-panel sat-activity-monitor">

        <div class="sat-health-heading">

            <div>

                <h2>
                    Activity & Threat Monitor
                </h2>

                <p>
                    Observe authentication,
                    HTTP/API traffic, REST activity,
                    reconnaissance patterns,
                    plugin changes and outbound
                    WordPress connections.
                </p>

            </div>

            <span class="sat-status sat-status-good">
                Monitoring
            </span>

        </div>


        <?php
        if (
            isset($_GET['sat_test'])
        ) :
        ?>

            <div class="notice notice-success inline">
                <p>
                    Test security events generated successfully.
                </p>
            </div>

        <?php endif; ?>


        <h3>
            Threat Summary - Last 24 Hours
        </h3>

        <?php if (empty($threats)) : ?>

            <div class="sat-log-empty">
                No IP-based threat activity has been recorded yet.
            </div>

        <?php else : ?>

            <div class="sat-table-scroll">

                <table class="widefat striped">

                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Events</th>
                            <th>Failed Logins</th>
                            <th>404 Probes</th>
                            <th>XML-RPC</th>
                            <th>Risk</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php
                    foreach (
                        $threats
                        as $threat
                    ) :

                        $risk =
                            sat_risk_label(
                                $threat[
                                    'total_risk'
                                ]
                            );

                    ?>

                        <tr>

                            <td>
                                <code>
                                    <?php
                                    echo esc_html(
                                        $threat[
                                            'ip_address'
                                        ]
                                    );
                                    ?>
                                </code>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $threat[
                                        'event_count'
                                    ]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $threat[
                                        'failed_logins'
                                    ]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $threat[
                                        'probes'
                                    ]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $threat[
                                        'xmlrpc_requests'
                                    ]
                                );
                                ?>
                            </td>

                            <td>

                                <span class="sat-status sat-status-<?php
                                    echo esc_attr(
                                        $risk['status']
                                    );
                                ?>">

                                    <?php
                                    echo esc_html(
                                        $risk['label']
                                        . ' - ' .
                                        $threat[
                                            'total_risk'
                                        ]
                                    );
                                    ?>

                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>


        <h3>
            Recent Activity
        </h3>

        <div class="sat-table-scroll">

            <table class="widefat striped sat-activity-table">

                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Direction</th>
                        <th>Event</th>
                        <th>Method</th>
                        <th>Endpoint</th>
                        <th>Status</th>
                        <th>IP</th>
                        <th>User</th>
                        <th>Risk</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($events)) : ?>

                    <tr>
                        <td colspan="9">
                            No activity has been recorded yet.
                        </td>
                    </tr>

                <?php else : ?>

                    <?php
                    foreach (
                        $events
                        as $event
                    ) :
                    ?>

                        <tr>

                            <td>
                                <?php
                                echo esc_html(
                                    $event[
                                        'created_at'
                                    ]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    ucfirst(
                                        $event[
                                            'direction'
                                        ]
                                    )
                                );
                                ?>
                            </td>

                            <td>
                                <strong>
                                    <?php
                                    echo esc_html(
                                        $event[
                                            'event_type'
                                        ]
                                    );
                                    ?>
                                </strong>

                                <?php
                                if (
                                    !empty(
                                        $event[
                                            'details'
                                        ]
                                    )
                                ) :
                                ?>

                                    <div class="sat-event-detail">
                                        <?php
                                        echo esc_html(
                                            $event[
                                                'details'
                                            ]
                                        );
                                        ?>
                                    </div>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $event[
                                        'method'
                                    ]
                                );
                                ?>
                            </td>

                            <td>
                                <code>
                                    <?php
                                    echo esc_html(
                                        $event[
                                            'endpoint'
                                        ]
                                    );
                                    ?>
                                </code>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $event[
                                        'status_code'
                                    ]
                                );
                                ?>
                            </td>

                            <td>
                                <code>
                                    <?php
                                    echo esc_html(
                                        $event[
                                            'ip_address'
                                        ]
                                    );
                                    ?>
                                </code>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $event[
                                        'user_id'
                                    ]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $event[
                                        'risk_score'
                                    ]
                                );
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <h3>
            Local Test Generator
        </h3>

        <p>
            Generate controlled demo security events
            so the monitoring and threat-scoring pipeline
            can be tested on localhost.
        </p>

        <form
            method="post"
            action="<?php
                echo esc_url(
                    admin_url(
                        'admin-post.php'
                    )
                );
            ?>"
        >

            <input
                type="hidden"
                name="action"
                value="sat_generate_test_events"
            >

            <?php
            wp_nonce_field(
                'sat_generate_test_events'
            );
            ?>

            <?php
            submit_button(
                'Generate Security Test Events',
                'secondary',
                'submit',
                false
            );
            ?>

        </form>


        <div class="sat-diagnostic-note">

            <strong>
                Privacy & security:
            </strong>

            Site Admin Toolkit does not log
            passwords, cookies, authorization headers,
            API tokens, request bodies or URL query strings.

            Activity records are automatically removed
            after 14 days in this version.

        </div>

    </div>
    <?php
}
