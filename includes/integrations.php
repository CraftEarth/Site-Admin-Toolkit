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
    return wp_parse_args(
        get_option(
            'sat_integration_settings',
            []
        ),
        sat_integration_defaults()
    );
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
