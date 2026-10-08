<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Determine whether maintenance mode should be shown.
 */
function sat_should_show_maintenance()
{
    $options = get_option('sat_settings', []);

    if (empty($options['maintenance_enabled'])) {
        return false;
    }

    // Administrators can always access the site.
    if (is_user_logged_in() && current_user_can('manage_options')) {
        return false;
    }

    // Do not interfere with background WordPress operations.
    if (wp_doing_ajax() || wp_doing_cron()) {
        return false;
    }

    // Allow REST API requests to continue working.
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }

    return true;
}

/**
 * Display maintenance page to public visitors.
 */
function sat_render_maintenance_page()
{
    if (!sat_should_show_maintenance()) {
        return;
    }

    $options = get_option('sat_settings', []);

    $title = !empty($options['maintenance_title'])
        ? $options['maintenance_title']
        : 'Site Maintenance';

    $message = !empty($options['maintenance_message'])
        ? $options['maintenance_message']
        : 'We are currently performing scheduled maintenance. Please check back soon.';

    $return_message = !empty($options['maintenance_return'])
        ? $options['maintenance_return']
        : '';

    // Correct HTTP response for temporary downtime.
    status_header(503);
    nocache_headers();

    if (!headers_sent()) {
        header('Retry-After: 3600');
    }

    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>
            <?php echo esc_html($title); ?>
        </title>

        <style>
            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f0f2f5;
                font-family: -apple-system, BlinkMacSystemFont,
                    "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                color: #1d2327;
                padding: 24px;
            }

            .sat-maintenance {
                width: 100%;
                max-width: 650px;
                background: #ffffff;
                border-radius: 12px;
                padding: 48px;
                box-shadow: 0 12px 40px rgba(0,0,0,.08);
                text-align: center;
            }

            .sat-maintenance-icon {
                font-size: 54px;
                margin-bottom: 20px;
            }

            .sat-maintenance h1 {
                margin: 0 0 20px;
                font-size: 34px;
            }

            .sat-maintenance-message {
                font-size: 18px;
                line-height: 1.7;
                color: #50575e;
            }

            .sat-maintenance-return {
                margin-top: 24px;
                padding-top: 20px;
                border-top: 1px solid #dcdcde;
                font-weight: 600;
            }

            .sat-maintenance-site {
                margin-top: 28px;
                color: #8c8f94;
                font-size: 14px;
            }

            @media (max-width: 600px) {
                .sat-maintenance {
                    padding: 32px 22px;
                }

                .sat-maintenance h1 {
                    font-size: 28px;
                }
            }
        </style>
    </head>

    <body>

        <main class="sat-maintenance">

            <div class="sat-maintenance-icon">
                ???
            </div>

            <h1>
                <?php echo esc_html($title); ?>
            </h1>

            <div class="sat-maintenance-message">
                <?php
                echo nl2br(
                    esc_html($message)
                );
                ?>
            </div>

            <?php if ($return_message !== '') : ?>

                <div class="sat-maintenance-return">
                    <?php
                    echo esc_html(
                        $return_message
                    );
                    ?>
                </div>

            <?php endif; ?>

            <div class="sat-maintenance-site">
                <?php
                echo esc_html(
                    get_bloginfo('name')
                );
                ?>
            </div>

        </main>

    </body>
    </html>
    <?php

    exit;
}

add_action(
    'template_redirect',
    'sat_render_maintenance_page',
    1
);
