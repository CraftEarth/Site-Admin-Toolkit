<?php

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Default thresholds.
 */
function sat_alert_defaults()
{
    return [
        'failed_logins' => 10,
        'probes' => 10,
        'critical_risk' => 70,
    ];
}


/**
 * Alert configuration.
 */
function sat_get_alert_settings()
{
    return wp_parse_args(
        get_option(
            'sat_alert_settings',
            []
        ),
        sat_alert_defaults()
    );
}


/**
 * Save alert settings.
 */
function sat_save_alert_settings()
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
        'sat_save_alert_settings'
    );

    $failed =
        isset(
            $_POST[
                'failed_logins'
            ]
        )
            ? absint(
                $_POST[
                    'failed_logins'
                ]
            )
            : 10;

    $probes =
        isset(
            $_POST['probes']
        )
            ? absint(
                $_POST['probes']
            )
            : 10;

    $critical =
        isset(
            $_POST[
                'critical_risk'
            ]
        )
            ? absint(
                $_POST[
                    'critical_risk'
                ]
            )
            : 70;

    update_option(
        'sat_alert_settings',
        [
            'failed_logins' =>
                max(1, $failed),

            'probes' =>
                max(1, $probes),

            'critical_risk' =>
                min(
                    100,
                    max(
                        1,
                        $critical
                    )
                ),
        ],
        false
    );

    wp_safe_redirect(
        admin_url(
            'admin.php?page=site-admin-toolkit&tab=alerts&sat_alerts=saved'
        )
    );

    exit;
}

add_action(
    'admin_post_sat_save_alert_settings',
    'sat_save_alert_settings'
);


/**
 * Build current alerts.
 */
function sat_build_alerts()
{
    $settings =
        sat_get_alert_settings();

    $alerts = [];

    if (
        function_exists(
            'sat_build_threat_correlation'
        )
    ) {

        $threats =
            sat_build_threat_correlation(
                24
            );

        foreach (
            $threats['ips']
            as $ip
        ) {

            if (
                $ip['failed_logins']
                >=
                $settings[
                    'failed_logins'
                ]
            ) {

                $alerts[] = [
                    'severity' =>
                        'critical',

                    'title' =>
                        'Failed login threshold exceeded',

                    'source' =>
                        $ip['ip'],

                    'message' =>
                        $ip[
                            'failed_logins'
                        ] .
                        ' failed login attempts recorded.',
                ];
            }


            if (
                $ip['probes']
                >=
                $settings['probes']
            ) {

                $alerts[] = [
                    'severity' =>
                        'warning',

                    'title' =>
                        'Reconnaissance threshold exceeded',

                    'source' =>
                        $ip['ip'],

                    'message' =>
                        $ip['probes'] .
                        ' 404 probe events recorded.',
                ];
            }


            if (
                $ip['score']
                >=
                $settings[
                    'critical_risk'
                ]
            ) {

                $alerts[] = [
                    'severity' =>
                        'critical',

                    'title' =>
                        'Critical correlated risk',

                    'source' =>
                        $ip['ip'],

                    'message' =>
                        'Correlated risk score reached ' .
                        $ip['score'] .
                        '/100.',
                ];
            }
        }
    }


    if (
        function_exists(
            'sat_get_new_outbound_domains'
        )
    ) {

        $outbound =
            sat_get_new_outbound_domains();

        if (
            $outbound[
                'has_baseline'
            ]
        ) {

            foreach (
                $outbound['new']
                as $domain
            ) {

                $alerts[] = [
                    'severity' =>
                        'warning',

                    'title' =>
                        'New outbound destination',

                    'source' =>
                        $domain['host'],

                    'message' =>
                        'WordPress contacted a domain not present in the outbound baseline.',
                ];
            }
        }
    }


    if (
        function_exists(
            'sat_integrity_compare'
        )
    ) {

        $files =
            sat_integrity_compare();

        if (
            $files[
                'has_baseline'
            ]
        ) {

            foreach (
                $files[
                    'suspicious'
                ]
                as $path => $finding
            ) {

                $alerts[] = [
                    'severity' =>
                        'critical',

                    'title' =>
                        'Suspicious executable file',

                    'source' =>
                        $path,

                    'message' =>
                        $finding[
                            'reason'
                        ],
                ];
            }


            if (
                count(
                    $files[
                        'modified'
                    ]
                ) > 20
            ) {

                $alerts[] = [
                    'severity' =>
                        'warning',

                    'title' =>
                        'Large file-change event',

                    'source' =>
                        'File Integrity',

                    'message' =>
                        count(
                            $files[
                                'modified'
                            ]
                        ) .
                        ' files differ from the baseline.',
                ];
            }
        }
    }


    return $alerts;
}


/**
 * Render Alerts tab.
 */
function sat_render_alert_console()
{
    if (
        !current_user_can(
            'manage_options'
        )
    ) {
        return;
    }

    $settings =
        sat_get_alert_settings();

    $alerts =
        sat_build_alerts();

    ?>
    <div class="sat-panel">

        <div class="sat-health-heading">

            <div>

                <h2>
                    Security Alert Engine
                </h2>

                <p>
                    Correlates threat, network and file-integrity
                    findings against configurable thresholds.
                </p>

            </div>

            <span class="sat-status <?php
                echo empty($alerts)
                    ? 'sat-status-good'
                    : 'sat-status-warning';
            ?>">

                <?php
                echo esc_html(
                    empty($alerts)
                        ? 'No Active Alerts'
                        : count($alerts) .
                          ' Active Alerts'
                );
                ?>

            </span>

        </div>


        <?php
        if (
            isset(
                $_GET[
                    'sat_alerts'
                ]
            )
        ) :
        ?>

            <div class="notice notice-success inline">
                <p>
                    Alert thresholds saved.
                </p>
            </div>

        <?php endif; ?>


        <h3>
            Active Alerts
        </h3>

        <?php
        if (
            empty($alerts)
        ) :
        ?>

            <div class="sat-log-empty">
                No current findings exceed configured thresholds.
            </div>

        <?php else : ?>

            <div class="sat-alert-list">

                <?php
                foreach (
                    $alerts
                    as $alert
                ) :
                ?>

                    <div class="sat-alert-item sat-alert-<?php
                        echo esc_attr(
                            $alert[
                                'severity'
                            ]
                        );
                    ?>">

                        <div>

                            <strong>
                                <?php
                                echo esc_html(
                                    $alert[
                                        'title'
                                    ]
                                );
                                ?>
                            </strong>

                            <p>
                                <?php
                                echo esc_html(
                                    $alert[
                                        'message'
                                    ]
                                );
                                ?>
                            </p>

                            <code>
                                <?php
                                echo esc_html(
                                    $alert[
                                        'source'
                                    ]
                                );
                                ?>
                            </code>

                        </div>

                        <span class="sat-status sat-status-<?php
                            echo esc_attr(
                                $alert[
                                    'severity'
                                ]
                            );
                        ?>">
                            <?php
                            echo esc_html(
                                ucfirst(
                                    $alert[
                                        'severity'
                                    ]
                                )
                            );
                            ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <h3>
            Detection Thresholds
        </h3>

        <form
            method="post"
            action="<?php
                echo esc_url(
                    admin_url(
                        'admin-post.php'
                    )
                );
            ?>"
            class="sat-alert-settings"
        >

            <input
                type="hidden"
                name="action"
                value="sat_save_alert_settings"
            >

            <?php
            wp_nonce_field(
                'sat_save_alert_settings'
            );
            ?>


            <label>

                Failed Login Threshold

                <input
                    type="number"
                    min="1"
                    name="failed_logins"
                    value="<?php
                        echo esc_attr(
                            $settings[
                                'failed_logins'
                            ]
                        );
                    ?>"
                >

            </label>


            <label>

                404 Probe Threshold

                <input
                    type="number"
                    min="1"
                    name="probes"
                    value="<?php
                        echo esc_attr(
                            $settings[
                                'probes'
                            ]
                        );
                    ?>"
                >

            </label>


            <label>

                Critical Risk Score

                <input
                    type="number"
                    min="1"
                    max="100"
                    name="critical_risk"
                    value="<?php
                        echo esc_attr(
                            $settings[
                                'critical_risk'
                            ]
                        );
                    ?>"
                >

            </label>


            <?php
            submit_button(
                'Save Alert Thresholds',
                'primary',
                'submit',
                false
            );
            ?>

        </form>


        <div class="sat-diagnostic-note">

            <strong>
                Detection model:
            </strong>

            Alerts identify technical indicators and correlated
            behavior. They should support investigation rather
            than be treated as proof of malicious intent.

        </div>

    </div>
    <?php
}
