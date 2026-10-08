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
        'dashicons-admin-tools',
        80
    );
}

add_action('admin_menu', 'sat_admin_menu');

function sat_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(
            esc_html__(
                'You do not have permission to access this page.',
                'site-admin-toolkit'
            )
        );
    }

    global $wpdb;

    $active_plugins =
        count(
            (array) get_option(
                'active_plugins',
                []
            )
        );

    $post_count =
        wp_count_posts('post')->publish ?? 0;

    $page_count =
        wp_count_posts('page')->publish ?? 0;

    $user_count =
        count_users();

    ?>
    <div class="wrap sat-wrap">

        <h1>Site Admin Toolkit</h1>

        <p class="sat-intro">
            Quick access to WordPress site information,
            maintenance details, and reusable admin tools.
        </p>

        <div class="sat-grid">

            <div class="sat-card">
                <span>WordPress Version</span>
                <strong>
                    <?php echo esc_html(get_bloginfo('version')); ?>
                </strong>
            </div>

            <div class="sat-card">
                <span>PHP Version</span>
                <strong>
                    <?php echo esc_html(PHP_VERSION); ?>
                </strong>
            </div>

            <div class="sat-card">
                <span>Active Plugins</span>
                <strong>
                    <?php echo esc_html($active_plugins); ?>
                </strong>
            </div>

            <div class="sat-card">
                <span>Total Users</span>
                <strong>
                    <?php echo esc_html(
                        $user_count['total_users']
                    ); ?>
                </strong>
            </div>

            <div class="sat-card">
                <span>Published Posts</span>
                <strong>
                    <?php echo esc_html($post_count); ?>
                </strong>
            </div>

            <div class="sat-card">
                <span>Published Pages</span>
                <strong>
                    <?php echo esc_html($page_count); ?>
                </strong>
            </div>

        </div>

                <?php
        if (function_exists('sat_render_site_health')) {
            sat_render_site_health();
        }
        ?>
<div class="sat-panel">

            <h2>System Information</h2>

            <table class="widefat striped">

                <tbody>

                    <tr>
                        <td><strong>Site URL</strong></td>
                        <td>
                            <?php echo esc_html(site_url()); ?>
                        </td>
                    </tr>

                    <tr>
                        <td><strong>Home URL</strong></td>
                        <td>
                            <?php echo esc_html(home_url()); ?>
                        </td>
                    </tr>

                    <tr>
                        <td><strong>Database</strong></td>
                        <td>
                            <?php echo esc_html(DB_NAME); ?>
                        </td>
                    </tr>

                    <tr>
                        <td><strong>Database Version</strong></td>
                        <td>
                            <?php
                            echo esc_html(
                                $wpdb->db_version()
                            );
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <td><strong>Theme</strong></td>
                        <td>
                            <?php
                            $theme = wp_get_theme();

                            echo esc_html(
                                $theme->get('Name')
                            );
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <td><strong>Debug Mode</strong></td>
                        <td>
                            <?php
                            echo (
                                defined('WP_DEBUG')
                                && WP_DEBUG
                            )
                                ? 'Enabled'
                                : 'Disabled';
                            ?>
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

        <div class="sat-panel">

            <h2>Site Announcement</h2>

            <form method="post" action="options.php">

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
                Add this shortcode to any page or post:
            </p>

            <code>[sat_announcement]</code>

        </div>

    
        <?php
        if (
            function_exists(
                'sat_render_diagnostics'
            )
        ) {
            sat_render_diagnostics();
        }
        ?>

        <?php
        if (
            function_exists(
                'sat_render_database_health'
            )
        ) {
            sat_render_database_health();
        }
        ?>
</div>
    <?php
}



