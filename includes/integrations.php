<?php

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Integration defaults.
 */
function sat_integration_defaults()
{
    return [
        'email_enabled' => 0,
        'email_address' => get_option('admin_email'),

        'webhook_enabled' => 0,
        'webhook_url' => '',

        'zoho_enabled' => 0,
        'zoho_dc' => 'com',
        'zoho_flow_enabled' => 0,
        'zoho_flow_url' => '',
    ];
}


/**
 * Retrieve integration settings.
 */
function sat_get_integration_settings()
{
    $settings = wp_parse_args(
        get_option(
            'sat_integration_settings',
            []
        ),
        sat_integration_defaults()
    );

    /*
     * Enforce Premium entitlements at the data layer.
     *
     * Stored settings alone never grant access to a
     * Premium integration.
     */
    if (
        function_exists('sat_feature_enabled')
        && !sat_feature_enabled('email_automation')
    ) {
        $settings['email_enabled'] = 0;
    }

    if (
        function_exists('sat_feature_enabled')
        && !sat_feature_enabled('webhook_integrations')
    ) {
        $settings['webhook_enabled'] = 0;
    }

    if (
        function_exists('sat_feature_enabled')
        && !sat_feature_enabled('zoho_integration')
    ) {
        $settings['zoho_enabled'] = 0;
        $settings['zoho_flow_enabled'] = 0;
    }

    return $settings;
}


/**
 * Register integration settings.
 */
function sat_register_integration_settings()
{
    register_setting(
        'sat_integration_settings_group',
        'sat_integration_settings',
        [
            'type' => 'array',
            'sanitize_callback' =>
                'sat_sanitize_integration_settings',
            'default' =>
                sat_integration_defaults(),
        ]
    );
}

add_action(
    'admin_init',
    'sat_register_integration_settings'
);


/**
 * Sanitize integration settings.
 */
function sat_sanitize_integration_settings($input)
{
    $allowed_dc = [
        'com',
        'eu',
        'in',
        'com.au',
        'jp',
        'ca',
        'sa',
    ];

    $dc =
        isset($input['zoho_dc'])
            ? sanitize_text_field(
                $input['zoho_dc']
            )
            : 'com';

    if (!in_array($dc, $allowed_dc, true)) {
        $dc = 'com';
    }

    return [
        'email_enabled' =>
            !empty(
                $input['email_enabled']
            )
                ? 1
                : 0,

        'email_address' =>
            sanitize_email(
                $input['email_address']
                ?? ''
            ),

        'webhook_enabled' =>
            !empty(
                $input['webhook_enabled']
            )
                ? 1
                : 0,

        'webhook_url' =>
            esc_url_raw(
                $input['webhook_url']
                ?? ''
            ),

        'zoho_enabled' =>
            !empty(
                $input['zoho_enabled']
            )
                ? 1
                : 0,

        'zoho_dc' =>
            $dc,

        'zoho_flow_enabled' =>
            !empty(
                $input['zoho_flow_enabled']
            )
                ? 1
                : 0,

        'zoho_flow_url' =>
            esc_url_raw(
                $input['zoho_flow_url']
                ?? ''
            ),
    ];
}


/**
 * Check whether Zoho credentials exist.
 *
 * Credentials are intentionally read from wp-config.php
 * rather than stored in WordPress options.
 */
function sat_zoho_credentials_status()
{
    return [
        'client_id' =>
            defined(
                'SAT_ZOHO_CLIENT_ID'
            )
            &&
            SAT_ZOHO_CLIENT_ID !== '',

        'client_secret' =>
            defined(
                'SAT_ZOHO_CLIENT_SECRET'
            )
            &&
            SAT_ZOHO_CLIENT_SECRET !== '',

        'refresh_token' =>
            defined(
                'SAT_ZOHO_REFRESH_TOKEN'
            )
            &&
            SAT_ZOHO_REFRESH_TOKEN !== '',
    ];
}


/**
 * Build Zoho Accounts domain.
 */
function sat_zoho_accounts_url($dc)
{
    $domains = [
        'com' =>
            'https://accounts.zoho.com',

        'eu' =>
            'https://accounts.zoho.eu',

        'in' =>
            'https://accounts.zoho.in',

        'com.au' =>
            'https://accounts.zoho.com.au',

        'jp' =>
            'https://accounts.zoho.jp',

        'ca' =>
            'https://accounts.zohocloud.ca',

        'sa' =>
            'https://accounts.zoho.sa',
    ];

    return $domains[$dc]
        ?? $domains['com'];
}


/**
 * Exchange refresh token for temporary Zoho access token.
 *
 * Access tokens are never saved by the plugin.
 */
function sat_zoho_get_access_token()
{
    $settings =
        sat_get_integration_settings();

    $credentials =
        sat_zoho_credentials_status();

    if (
        !$credentials['client_id']
        ||
        !$credentials['client_secret']
        ||
        !$credentials['refresh_token']
    ) {
        return new WP_Error(
            'sat_zoho_credentials',
            'Zoho credentials are not fully configured in wp-config.php.'
        );
    }

    $url =
        sat_zoho_accounts_url(
            $settings['zoho_dc']
        )
        .
        '/oauth/v2/token';

    $response =
        wp_remote_post(
            $url,
            [
                'timeout' => 15,

                'body' => [
                    'refresh_token' =>
                        SAT_ZOHO_REFRESH_TOKEN,

                    'client_id' =>
                        SAT_ZOHO_CLIENT_ID,

                    'client_secret' =>
                        SAT_ZOHO_CLIENT_SECRET,

                    'grant_type' =>
                        'refresh_token',
                ],
            ]
        );

    if (is_wp_error($response)) {
        return $response;
    }

    $code =
        wp_remote_retrieve_response_code(
            $response
        );

    $body =
        json_decode(
            wp_remote_retrieve_body(
                $response
            ),
            true
        );

    if (
        $code < 200
        ||
        $code >= 300
        ||
        empty($body['access_token'])
    ) {
        $message =
            isset($body['error'])
                ? $body['error']
                : 'Zoho OAuth request failed.';

        return new WP_Error(
            'sat_zoho_oauth',
            sanitize_text_field(
                $message
            )
        );
    }

    return [
        'access_token' =>
            $body['access_token'],

        'expires_in' =>
            isset($body['expires_in'])
                ? absint(
                    $body['expires_in']
                )
                : null,

        'api_domain' =>
            isset($body['api_domain'])
                ? esc_url_raw(
                    $body['api_domain']
                )
                : '',
    ];
}


/**
 * Test Zoho OAuth.
 */
function sat_test_zoho_connection()
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
        'sat_test_zoho_connection'
    );

    if (!sat_feature_enabled('zoho_integration')) {
        wp_die('Premium Zoho Integration is required.');
    }

    $result =
        sat_zoho_get_access_token();

    if (is_wp_error($result)) {

        set_transient(
            'sat_zoho_test_' .
            get_current_user_id(),
            [
                'success' => false,
                'message' =>
                    $result->get_error_message(),
            ],
            60
        );

    } else {

        set_transient(
            'sat_zoho_test_' .
            get_current_user_id(),
            [
                'success' => true,
                'message' =>
                    'Zoho OAuth connection succeeded.',
            ],
            60
        );
    }

    wp_safe_redirect(
        admin_url(
            'admin.php?page=site-admin-toolkit&tab=integrations'
        )
    );

    exit;
}

add_action(
    'admin_post_sat_test_zoho_connection',
    'sat_test_zoho_connection'
);


/**
 * Send one alert through enabled integrations.
 */
function sat_send_integrated_alert($alert)
{
    $settings =
        sat_get_integration_settings();

    $payload = [
        'source' =>
            'Site Admin Toolkit',

        'site' =>
            home_url(),

        'timestamp' =>
            current_time('mysql'),

        'severity' =>
            $alert['severity'],

        'title' =>
            $alert['title'],

        'event_source' =>
            $alert['source'],

        'message' =>
            $alert['message'],
    ];


    /*
     * Email alerts.
     */
    if (
        !empty(
            $settings['email_enabled']
        )
        &&
        is_email(
            $settings['email_address']
        )
    ) {

        $subject =
            '[Site Admin Toolkit] ' .
            strtoupper(
                $alert['severity']
            )
            .
            ': ' .
            $alert['title'];

        $body =
            "Site: " .
            home_url()
            .
            "\n\nSeverity: " .
            $alert['severity']
            .
            "\nSource: " .
            $alert['source']
            .
            "\n\n" .
            $alert['message'];

        wp_mail(
            $settings['email_address'],
            $subject,
            $body
        );
    }


    /*
     * Generic JSON webhook.
     */
    if (
        !empty(
            $settings['webhook_enabled']
        )
        &&
        !empty(
            $settings['webhook_url']
        )
    ) {

        wp_remote_post(
            $settings['webhook_url'],
            [
                'timeout' => 10,

                'headers' => [
                    'Content-Type' =>
                        'application/json',
                ],

                'body' =>
                    wp_json_encode(
                        $payload
                    ),
            ]
        );
    }


    /*
     * Zoho Flow incoming webhook.
     *
     * Completely optional.
     */
    if (
        !empty(
            $settings['zoho_enabled']
        )
        &&
        !empty(
            $settings['zoho_flow_enabled']
        )
        &&
        !empty(
            $settings['zoho_flow_url']
        )
    ) {

        wp_remote_post(
            $settings['zoho_flow_url'],
            [
                'timeout' => 10,

                'headers' => [
                    'Content-Type' =>
                        'application/json',
                ],

                'body' =>
                    wp_json_encode(
                        $payload
                    ),
            ]
        );
    }
}


/**
 * Alert fingerprint.
 *
 * Prevents repeated hourly notifications for
 * the same unchanged alert.
 */
function sat_alert_fingerprint($alert)
{
    return hash(
        'sha256',
        wp_json_encode(
            [
                $alert['severity'],
                $alert['title'],
                $alert['source'],
                $alert['message'],
            ]
        )
    );
}


/**
 * Dispatch current alerts.
 */
function sat_dispatch_current_alerts()
{
    if (
        !function_exists(
            'sat_build_alerts'
        )
    ) {
        return;
    }

    $alerts =
        sat_build_alerts();

    if (empty($alerts)) {
        return;
    }

    $sent =
        get_option(
            'sat_sent_alert_fingerprints',
            []
        );

    if (!is_array($sent)) {
        $sent = [];
    }

    $now = time();

    /*
     * Drop fingerprints older than 24 hours.
     */
    foreach (
        $sent
        as $fingerprint => $timestamp
    ) {

        if (
            $timestamp <
            (
                $now -
                DAY_IN_SECONDS
            )
        ) {
            unset(
                $sent[$fingerprint]
            );
        }
    }


    foreach ($alerts as $alert) {

        $fingerprint =
            sat_alert_fingerprint(
                $alert
            );

        if (
            isset(
                $sent[$fingerprint]
            )
        ) {
            continue;
        }

        sat_send_integrated_alert(
            $alert
        );

        $sent[$fingerprint] =
            $now;
    }

    update_option(
        'sat_sent_alert_fingerprints',
        $sent,
        false
    );
}


/**
 * Schedule hourly alert dispatch.
 */
function sat_schedule_alert_dispatch()
{
    if (
        !wp_next_scheduled(
            'sat_alert_dispatch_event'
        )
    ) {

        wp_schedule_event(
            time() + 300,
            'hourly',
            'sat_alert_dispatch_event'
        );
    }
}

add_action(
    'init',
    'sat_schedule_alert_dispatch'
);

add_action(
    'sat_alert_dispatch_event',
    'sat_dispatch_current_alerts'
);


/**
 * Remove scheduled event when plugin is deactivated.
 */
function sat_clear_alert_dispatch()
{
    $timestamp =
        wp_next_scheduled(
            'sat_alert_dispatch_event'
        );

    if ($timestamp) {

        wp_unschedule_event(
            $timestamp,
            'sat_alert_dispatch_event'
        );
    }
}

register_deactivation_hook(
    SAT_PLUGIN_DIR .
    'site-admin-toolkit.php',
    'sat_clear_alert_dispatch'
);


/**
 * Send a manual test alert.
 */
function sat_send_test_integration_alert()
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
        'sat_send_test_integration_alert'
    );

    sat_send_integrated_alert(
        [
            'severity' => 'warning',

            'title' =>
                'Integration Test',

            'source' =>
                'Local Test',

            'message' =>
                'This is a test alert generated by Site Admin Toolkit.',
        ]
    );

    set_transient(
        'sat_integration_test_' .
        get_current_user_id(),
        true,
        60
    );

    wp_safe_redirect(
        admin_url(
            'admin.php?page=site-admin-toolkit&tab=integrations'
        )
    );

    exit;
}

add_action(
    'admin_post_sat_send_test_integration_alert',
    'sat_send_test_integration_alert'
);


/**
 * Render Integration Center.
 */
function sat_render_integrations()
{
    if (
        !current_user_can(
            'manage_options'
        )
    ) {
        return;
    }

    $settings =
        sat_get_integration_settings();

    $credentials =
        sat_zoho_credentials_status();

    $zoho_test =
        get_transient(
            'sat_zoho_test_' .
            get_current_user_id()
        );

    delete_transient(
        'sat_zoho_test_' .
        get_current_user_id()
    );

    $test_sent =
        get_transient(
            'sat_integration_test_' .
            get_current_user_id()
        );

    delete_transient(
        'sat_integration_test_' .
        get_current_user_id()
    );

    ?>
    <div class="sat-panel">

        <div class="sat-health-heading">

            <div>

                <h2>
                    Integrations Center
                </h2>

                <p>
                    Connect Site Admin Toolkit alerts to
                    email, generic webhooks or Zoho.
                </p>

            </div>

            <span class="sat-status sat-status-good">
                Optional
            </span>

        </div>


        <?php if ($test_sent) : ?>

            <div class="notice notice-success inline">
                <p>
                    Test alert dispatched through enabled integrations.
                </p>
            </div>

        <?php endif; ?>


        <?php if (is_array($zoho_test)) : ?>

            <div class="notice <?php
                echo $zoho_test['success']
                    ? 'notice-success'
                    : 'notice-error';
            ?> inline">

                <p>
                    <?php
                    echo esc_html(
                        $zoho_test['message']
                    );
                    ?>
                </p>

            </div>

        <?php endif; ?>


        <form
            method="post"
            action="options.php"
        >

            <?php
            settings_fields(
                'sat_integration_settings_group'
            );
            ?>


            <div class="sat-integration-section">

                <h3>
                    Email Alerts
                </h3>

                <label>

                    <input
                        type="checkbox"
                        name="sat_integration_settings[email_enabled]"
                        value="1"
                        <?php
                        checked(
                            !empty(
                                $settings[
                                    'email_enabled'
                                ]
                            )
                        );
                        ?>
                    >

                    Enable email alerts

                </label>

                <p>

                    <input
                        type="email"
                        class="regular-text"
                        name="sat_integration_settings[email_address]"
                        value="<?php
                            echo esc_attr(
                                $settings[
                                    'email_address'
                                ]
                            );
                        ?>"
                        placeholder="security@example.com"
                    >

                </p>

            </div>


            <div class="sat-integration-section">

                <h3>
                    Generic Webhook
                </h3>

                <label>

                    <input
                        type="checkbox"
                        name="sat_integration_settings[webhook_enabled]"
                        value="1"
                        <?php
                        checked(
                            !empty(
                                $settings[
                                    'webhook_enabled'
                                ]
                            )
                        );
                        ?>
                    >

                    Enable JSON webhook alerts

                </label>

                <p>

                    <input
                        type="url"
                        class="large-text"
                        name="sat_integration_settings[webhook_url]"
                        value="<?php
                            echo esc_attr(
                                $settings[
                                    'webhook_url'
                                ]
                            );
                        ?>"
                        placeholder="https://example.com/security-webhook"
                    >

                </p>

            </div>


            <div class="sat-integration-section">

                <h3>
                    Zoho
                </h3>

                <label>

                    <input
                        type="checkbox"
                        name="sat_integration_settings[zoho_enabled]"
                        value="1"
                        <?php
                        checked(
                            !empty(
                                $settings[
                                    'zoho_enabled'
                                ]
                            )
                        );
                        ?>
                    >

                    Enable Zoho integration

                </label>


                <p>
                    <label>
                        Zoho Data Center

                        <select
                            name="sat_integration_settings[zoho_dc]"
                        >

                            <?php
                            foreach (
                                [
                                    'com' =>
                                        'United States (.com)',

                                    'eu' =>
                                        'Europe (.eu)',

                                    'in' =>
                                        'India (.in)',

                                    'com.au' =>
                                        'Australia (.com.au)',

                                    'jp' =>
                                        'Japan (.jp)',

                                    'ca' =>
                                        'Canada (.ca)',

                                    'sa' =>
                                        'Saudi Arabia (.sa)',
                                ]
                                as $dc => $label
                            ) :
                            ?>

                                <option
                                    value="<?php echo esc_attr($dc); ?>"
                                    <?php
                                    selected(
                                        $settings['zoho_dc'],
                                        $dc
                                    );
                                    ?>
                                >
                                    <?php
                                    echo esc_html($label);
                                    ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>
                </p>


                <h4>
                    OAuth Credential Status
                </h4>

                <table class="widefat striped">

                    <tbody>

                        <tr>
                            <td>
                                <strong>
                                    Client ID
                                </strong>
                            </td>

                            <td>
                                <?php
                                echo $credentials['client_id']
                                    ? 'Configured'
                                    : 'Missing';
                                ?>
                            </td>
                        </tr>


                        <tr>
                            <td>
                                <strong>
                                    Client Secret
                                </strong>
                            </td>

                            <td>
                                <?php
                                echo $credentials['client_secret']
                                    ? 'Configured'
                                    : 'Missing';
                                ?>
                            </td>
                        </tr>


                        <tr>
                            <td>
                                <strong>
                                    Refresh Token
                                </strong>
                            </td>

                            <td>
                                <?php
                                echo $credentials['refresh_token']
                                    ? 'Configured'
                                    : 'Missing';
                                ?>
                            </td>
                        </tr>

                    </tbody>

                </table>


                <p class="description">

                    Zoho OAuth secrets are intentionally read from
                    wp-config.php and are not stored by Site Admin Toolkit.

                </p>


                <h4>
                    Zoho Flow Alerts
                </h4>

                <label>

                    <input
                        type="checkbox"
                        name="sat_integration_settings[zoho_flow_enabled]"
                        value="1"
                        <?php
                        checked(
                            !empty(
                                $settings[
                                    'zoho_flow_enabled'
                                ]
                            )
                        );
                        ?>
                    >

                    Send security alerts to a Zoho Flow webhook

                </label>

                <p>

                    <input
                        type="url"
                        class="large-text"
                        name="sat_integration_settings[zoho_flow_url]"
                        value="<?php
                            echo esc_attr(
                                $settings[
                                    'zoho_flow_url'
                                ]
                            );
                        ?>"
                        placeholder="https://flow.zoho.com/..."
                    >

                </p>

            </div>


            <?php
            submit_button(
                'Save Integration Settings'
            );
            ?>

        </form>


        <?php if (sat_feature_enabled('scheduled_reports')) : ?>

            <?php
            $sat_last_report =
                get_option(
                    'sat_last_scheduled_report',
                    []
                );

            $sat_next_report =
                wp_next_scheduled(
                    'sat_scheduled_security_report_event'
                );

            $sat_report_status =
                isset($_GET['sat_report_status'])
                    ? sanitize_key(
                        wp_unslash(
                            $_GET['sat_report_status']
                        )
                    )
                    : '';
            ?>

            <hr>

            <h3>
                Scheduled Security Reports
            </h3>

            <p>
                Premium security summaries are automatically
                generated and emailed once per week.
            </p>

            <?php if ($sat_report_status === 'sent') : ?>

                <div class="notice notice-success inline">
                    <p>
                        Security report sent successfully.
                    </p>
                </div>

            <?php elseif ($sat_report_status === 'failed') : ?>

                <div class="notice notice-error inline">
                    <p>
                        The security report could not be sent.
                        Check the WordPress mail configuration.
                    </p>
                </div>

            <?php endif; ?>

            <table class="widefat striped"
                   style="max-width:700px;margin-bottom:16px;">

                <tbody>

                    <tr>
                        <th style="width:220px;">
                            Last report sent
                        </th>

                        <td>
                            <?php
                            echo !empty(
                                $sat_last_report['sent_at']
                            )
                                ? esc_html(
                                    $sat_last_report['sent_at']
                                )
                                : 'Not sent yet';
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <th>
                            Last recipient
                        </th>

                        <td>
                            <?php
                            echo !empty(
                                $sat_last_report['email']
                            )
                                ? esc_html(
                                    $sat_last_report['email']
                                )
                                : '—';
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <th>
                            Next scheduled report
                        </th>

                        <td>
                            <?php
                            echo $sat_next_report
                                ? esc_html(
                                    wp_date(
                                        'Y-m-d H:i:s',
                                        $sat_next_report
                                    )
                                )
                                : 'Not currently scheduled';
                            ?>
                        </td>
                    </tr>

                </tbody>

            </table>

            <form
                method="post"
                action="<?php
                    echo esc_url(
                        admin_url(
                            'admin-post.php'
                        )
                    );
                ?>"
                style="margin-bottom:20px;"
            >

                <input
                    type="hidden"
                    name="action"
                    value="sat_send_security_report_now"
                >

                <?php
                wp_nonce_field(
                    'sat_send_security_report_now'
                );

                submit_button(
                    'Send Security Report Now',
                    'secondary',
                    'submit',
                    false
                );
                ?>

            </form>

        <?php endif; ?>

        <div class="sat-integration-actions">

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
                    value="sat_send_test_integration_alert"
                >

                <?php
                wp_nonce_field(
                    'sat_send_test_integration_alert'
                );
                ?>

                <?php
                submit_button(
                    'Send Test Alert',
                    'secondary',
                    'submit',
                    false
                );
                ?>

            </form>


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
                    value="sat_test_zoho_connection"
                >

                <?php
                wp_nonce_field(
                    'sat_test_zoho_connection'
                );
                ?>

                <?php
                submit_button(
                    'Test Zoho OAuth',
                    'secondary',
                    'submit',
                    false
                );
                ?>

            </form>

        </div>


        <?php
        if (function_exists('sat_render_server_log_ingestion_panel')) {
            sat_render_server_log_ingestion_panel();
        }
        ?>

        <div class="sat-diagnostic-note">

            <strong>
                Security design:
            </strong>

            Site Admin Toolkit does not display or store
            the Zoho Client Secret or Refresh Token.

            Alerts are dispatched only when the related
            integration is enabled.

        </div>

    </div>
    <?php
}

/**
 * Build the Premium weekly security report.
 */
function sat_build_scheduled_security_report()
{
    global $wpdb;

    $activity_table = sat_activity_table();

    $activity_count = (int) $wpdb->get_var(
        "
        SELECT COUNT(*)
        FROM {$activity_table}
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        "
    );

    $failed_logins = (int) $wpdb->get_var(
        "
        SELECT COUNT(*)
        FROM {$activity_table}
        WHERE event_type = 'login_failed'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        "
    );

    $open_incidents = 0;
    $high_risk_incidents = 0;

    if (function_exists('sat_incident_table')) {
        $incident_table = sat_incident_table();

        $open_incidents = (int) $wpdb->get_var(
            "
            SELECT COUNT(*)
            FROM {$incident_table}
            WHERE status <> 'resolved'
            "
        );

        $high_risk_incidents = (int) $wpdb->get_var(
            "
            SELECT COUNT(*)
            FROM {$incident_table}
            WHERE status <> 'resolved'
              AND risk_score >= 50
            "
        );
    }

    $file_events = 0;

    if (function_exists('sat_file_forensics_table')) {
        $forensics_table = sat_file_forensics_table();

        $file_events = (int) $wpdb->get_var(
            "
            SELECT COUNT(*)
            FROM {$forensics_table}
            WHERE detected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            "
        );
    }

    $site = home_url();

    $subject =
        '[Site Admin Toolkit] Weekly Security Report - ' .
        wp_parse_url($site, PHP_URL_HOST);

    $body =
        "Site Admin Toolkit - Weekly Security Report\n" .
        "===========================================\n\n" .
        "Site: {$site}\n" .
        "Period: Previous 7 days\n" .
        "Generated: " . current_time('mysql') . "\n\n" .
        "SECURITY SUMMARY\n" .
        "----------------\n" .
        "Recorded activity events: {$activity_count}\n" .
        "Failed login attempts: {$failed_logins}\n" .
        "Open incidents: {$open_incidents}\n" .
        "High-risk incidents: {$high_risk_incidents}\n" .
        "File-forensics events: {$file_events}\n\n" .
        "Review Site Admin Toolkit in WordPress for detailed evidence,\n" .
        "incident status, file changes, and network activity.\n";

    return [
        'subject' => $subject,
        'body' => $body,
    ];
}


/**
 * Send the Premium scheduled security report.
 */
function sat_send_scheduled_security_report()
{
    if (!sat_feature_enabled('scheduled_reports')) {
        return false;
    }

    $settings = sat_get_integration_settings();

    $email =
        !empty($settings['email_address'])
        && is_email($settings['email_address'])
            ? $settings['email_address']
            : get_option('admin_email');

    if (!is_email($email)) {
        return false;
    }

    $report = sat_build_scheduled_security_report();

    $sent = wp_mail(
        $email,
        $report['subject'],
        $report['body']
    );

    if ($sent) {
        update_option(
            'sat_last_scheduled_report',
            [
                'sent_at' => current_time('mysql'),
                'email' => $email,
            ],
            false
        );
    }


    return $sent;
}


/**
 * Schedule weekly Premium security reports.
 */
function sat_schedule_security_reports()
{
    $hook = 'sat_scheduled_security_report_event';

    if (!sat_feature_enabled('scheduled_reports')) {
        $timestamp = wp_next_scheduled($hook);

        if ($timestamp) {
            wp_unschedule_event(
                $timestamp,
                $hook
            );
        }

        return;
    }

    if (!wp_next_scheduled($hook)) {
        wp_schedule_event(
            time() + HOUR_IN_SECONDS,
            'weekly',
            $hook
        );
    }
}

add_action(
    'init',
    'sat_schedule_security_reports'
);

add_action(
    'sat_scheduled_security_report_event',
    'sat_send_scheduled_security_report'
);


/**
 * Remove scheduled security report on plugin deactivation.
 */
function sat_clear_scheduled_security_report()
{
    $timestamp =
        wp_next_scheduled(
            'sat_scheduled_security_report_event'
        );

    if ($timestamp) {
        wp_unschedule_event(
            $timestamp,
            'sat_scheduled_security_report_event'
        );
    }
}

register_deactivation_hook(
    SAT_PLUGIN_DIR . 'site-admin-toolkit.php',
    'sat_clear_scheduled_security_report'
);

/**
 * Manually send the Premium scheduled security report.
 */
function sat_send_security_report_now()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer(
        'sat_send_security_report_now'
    );

    if (!sat_feature_enabled('scheduled_reports')) {
        wp_safe_redirect(
            add_query_arg(
                'sat_report_status',
                'premium_required',
                admin_url(
                    'admin.php?page=site-admin-toolkit&tab=integrations'
                )
            )
        );

        exit;
    }

    $sent =
        sat_send_scheduled_security_report();

    wp_safe_redirect(
        add_query_arg(
            'sat_report_status',
            $sent ? 'sent' : 'failed',
            admin_url(
                'admin.php?page=site-admin-toolkit&tab=integrations'
            )
        )
    );

    exit;
}

add_action(
    'admin_post_sat_send_security_report_now',
    'sat_send_security_report_now'
);







