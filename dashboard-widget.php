<?php

if (!defined('ABSPATH')) {
    exit;
}

function sat_register_dashboard_widget()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    wp_add_dashboard_widget(
        'sat_dashboard_widget',
        'Site Admin Toolkit',
        'sat_dashboard_widget_content'
    );
}

add_action(
    'wp_dashboard_setup',
    'sat_register_dashboard_widget'
);

function sat_dashboard_widget_content()
{
    $plugin_count =
        count(
            (array) get_option(
                'active_plugins',
                []
            )
        );

    ?>
    <p>
        <strong>WordPress:</strong>
        <?php echo esc_html(get_bloginfo('version')); ?>
    </p>

    <p>
        <strong>PHP:</strong>
        <?php echo esc_html(PHP_VERSION); ?>
    </p>

    <p>
        <strong>Active Plugins:</strong>
        <?php echo esc_html($plugin_count); ?>
    </p>

    <p>
        <a
            class="button button-primary"
            href="<?php
                echo esc_url(
                    admin_url(
                        'admin.php?page=site-admin-toolkit'
                    )
                );
            ?>"
        >
            Open Site Toolkit
        </a>
    </p>
    <?php
}
