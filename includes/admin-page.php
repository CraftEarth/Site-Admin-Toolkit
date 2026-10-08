<?php

if (!defined('ABSPATH')) {
    exit;
}

function sat_admin_menu()
{
    add_menu_page(
        'Site Admin Toolkit',
        'Site Toolkit',
        'manage_options',
        'site-admin-toolkit',
        'sat_admin_page',
        'dashicons-shield-alt',
        80
    );
}

add_action(
    'admin_menu',
    'sat_admin_menu'
);


/**
 * Return allowed console tabs.
 */
function sat_console_tabs()
{
    return [
        'overview' => 'Overview',
        'health' => 'Health',
        'database' => 'Database',
        'traffic' => 'Traffic',
        'threats' => 'Threats',
        'files' => 'Files',
        'logs' => 'Logs',
        'settings' => 'Settings',
    ];
}


/**
 * Render navigation.
 */
function sat_render_console_nav($active)
{
    $tabs =
        sat_console_tabs();

    ?>
    <nav class="sat-console-tabs">

        <?php
        foreach (
            $tabs
            as $slug => $label
        ) :

            $url =
                add_query_arg(
                    [
                        'page' =>
                            'site-admin-toolkit',

                        'tab' =>
                            $slug,
                    ],
                    admin_url(
                        'admin.php'
                    )
                );

        ?>

            <a
                href="<?php
                    echo esc_url($url);
                ?>"
                class="sat-console-tab <?php
                    echo $active === $slug
                        ? 'sat-console-tab-active'
                        : '';
                ?>"
            >
                <?php
                echo esc_html($label);
                ?>
            </a>

        <?php endforeach; ?>

    </nav>
    <?php
}


/**
 * Overview.
 */
function sat_render_console_overview()
{
    global $wpdb;

    $activity_table =
        $wpdb->prefix .
        'sat_activity';

    $event_count = 0;
    $high_risk = 0;

    $table_exists =
        $wpdb->get_var(
            $wpdb->prepare(
                "
                SHOW TABLES LIKE %s
                ",
                $activity_table
            )
        );

    if ($table_exists) {

        $event_count =
            (int)
            $wpdb->get_var(
                "
                SELECT COUNT(*)
                FROM {$activity_table}
                WHERE created_at >= DATE_SUB(
                    NOW(),
                    INTERVAL 24 HOUR
                )
                "
            );

        if (
            function_exists(
                'sat_build_threat_correlation'
            )
        ) {

            $correlation =
                sat_build_threat_correlation(
                    24
                );

            foreach (
                $correlation['ips']
                as $ip
            ) {

                if (
                    $ip['score'] >= 50
                ) {
                    $high_risk++;
                }
            }
        }
    }

    ?>
    <div class="sat-console-hero">

        <h2>
            Security & Operations Overview
        </h2>

        <p>
            WordPress diagnostics, traffic visibility,
            threat correlation and forensic monitoring.
        </p>

    </div>


    <div class="sat-grid">

        <div class="sat-card">
            <span>WordPress</span>

            <strong>
                <?php
                echo esc_html(
                    get_bloginfo(
                        'version'
                    )
                );
                ?>
            </strong>
        </div>


        <div class="sat-card">
            <span>PHP</span>

            <strong>
                <?php
                echo esc_html(
                    PHP_VERSION
                );
                ?>
            </strong>
        </div>


        <div class="sat-card">
            <span>24h Events</span>

            <strong>
                <?php
                echo esc_html(
                    number_format_i18n(
                        $event_count
                    )
                );
                ?>
            </strong>
        </div>


        <div class="sat-card">
            <span>High-Risk Sources</span>

            <strong>
                <?php
                echo esc_html(
                    number_format_i18n(
                        $high_risk
                    )
                );
                ?>
            </strong>
        </div>


        <div class="sat-card">
            <span>Environment</span>

            <strong>
                <?php
                echo esc_html(
                    function_exists(
                        'wp_get_environment_type'
                    )
                        ? wp_get_environment_type()
                        : 'production'
                );
                ?>
            </strong>
        </div>


        <div class="sat-card">
            <span>HTTPS</span>

            <strong>
                <?php
                echo is_ssl()
                    ? 'Enabled'
                    : 'Not Detected';
                ?>
            </strong>
        </div>

    </div>


    <div class="sat-panel">

        <h2>
            Console Areas
        </h2>

        <div class="sat-console-area-grid">

            <a href="<?php
                echo esc_url(
                    admin_url(
                        'admin.php?page=site-admin-toolkit&tab=health'
                    )
                );
            ?>">
                Site Health
                <span>
                    Runtime, updates and service checks
                </span>
            </a>


            <a href="<?php
                echo esc_url(
                    admin_url(
                        'admin.php?page=site-admin-toolkit&tab=traffic'
                    )
                );
            ?>">
                Traffic Monitor
                <span>
                    Incoming/outgoing activity
                </span>
            </a>


            <a href="<?php
                echo esc_url(
                    admin_url(
                        'admin.php?page=site-admin-toolkit&tab=threats'
                    )
                );
            ?>">
                Threat Correlation
                <span>
                    Risk scoring and incident analysis
                </span>
            </a>


            <a href="<?php
                echo esc_url(
                    admin_url(
                        'admin.php?page=site-admin-toolkit&tab=files'
                    )
                );
            ?>">
                File Forensics
                <span>
                    Integrity baselines and file changes
                </span>
            </a>

        </div>

    </div>
    <?php
}


/**
 * Main admin page.
 */
function sat_admin_page()
{
    if (
        !current_user_can(
            'manage_options'
        )
    ) {
        wp_die(
            esc_html__(
                'You do not have permission to access this page.',
                'site-admin-toolkit'
            )
        );
    }

    $tabs =
        sat_console_tabs();

    $active =
        isset($_GET['tab'])
            ? sanitize_key(
                wp_unslash(
                    $_GET['tab']
                )
            )
            : 'overview';

    if (!isset($tabs[$active])) {
        $active = 'overview';
    }

    ?>
    <div class="wrap sat-wrap">

        <div class="sat-console-header">

            <div>
                <h1>
                    Site Admin Toolkit
                </h1>

                <p>
                    WordPress operations,
                    diagnostics and security console.
                </p>
            </div>

            <span class="sat-console-version">
                v<?php
                echo esc_html(
                    defined('SAT_VERSION')
                        ? SAT_VERSION
                        : ''
                );
                ?>
            </span>

        </div>

        <?php
        sat_render_console_nav(
            $active
        );
        ?>


        <div class="sat-console-content">

            <?php

            switch ($active) {

                case 'health':

                    if (
                        function_exists(
                            'sat_render_site_health'
                        )
                    ) {
                        sat_render_site_health();
                    }

                    if (
                        function_exists(
                            'sat_render_backup_readiness'
                        )
                    ) {
                        sat_render_backup_readiness();
                    }

                    break;


                case 'database':

                    if (
                        function_exists(
                            'sat_render_database_health'
                        )
                    ) {
                        sat_render_database_health();
                    }

                    break;


                case 'traffic':

                    if (
                        function_exists(
                            'sat_render_activity_monitor'
                        )
                    ) {
                        sat_render_activity_monitor();
                    }

                    break;


                case 'threats':

                    if (
                        function_exists(
                            'sat_render_threat_correlation'
                        )
                    ) {
                        sat_render_threat_correlation();
                    }

                    break;


                case 'files':

                    if (
                        function_exists(
                            'sat_render_file_integrity'
                        )
                    ) {
                        sat_render_file_integrity();
                    }

                    break;


                case 'logs':

                    if (
                        function_exists(
                            'sat_render_diagnostics'
                        )
                    ) {
                        sat_render_diagnostics();
                    }

                    break;


                case 'settings':

                    ?>
                    <div class="sat-panel">

                        <h2>
                            Toolkit Settings
                        </h2>

                        <form
                            method="post"
                            action="options.php"
                        >

                            <?php

                            settings_fields(
                                'sat_settings_group'
                            );

                            do_settings_sections(
                                'site-admin-toolkit'
                            );

                            submit_button(
                                'Save Settings'
                            );

                            ?>

                        </form>

                        <p>
                            Announcement shortcode:
                            <code>
                                [sat_announcement]
                            </code>
                        </p>

                    </div>
                    <?php

                    break;


                case 'overview':
                default:

                    sat_render_console_overview();

                    break;
            }

            ?>

        </div>

    </div>
    <?php
}
