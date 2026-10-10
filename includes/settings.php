<?php

if (!defined('ABSPATH')) {
    exit;
}

function sat_register_settings()
{
    register_setting(
        'sat_settings_group',
        'sat_settings',
        [
            'type' => 'array',
            'sanitize_callback' => 'sat_sanitize_settings',
            'default' => [
                'announcement_enabled' => 0,
                'announcement_text' => '',
                'maintenance_enabled' => 0,
                'maintenance_title' => 'Site Maintenance',
                'maintenance_message' =>
                    'We are currently performing scheduled maintenance. Please check back soon.',
                'maintenance_return' => '',
                'trusted_proxy_enabled' => 0,
                'trusted_proxy_header' => 'x_forwarded_for',
                'trusted_proxy_ips' => ''
            ]
        ]
    );

    /*
     * Announcement section
     */
    add_settings_section(
        'sat_announcement_section',
        'Announcement Settings',
        'sat_announcement_section_callback',
        'site-admin-toolkit'
    );

    add_settings_field(
        'sat_announcement_enabled',
        'Enable Announcement',
        'sat_announcement_enabled_field',
        'site-admin-toolkit',
        'sat_announcement_section'
    );

    add_settings_field(
        'sat_announcement_text',
        'Announcement Text',
        'sat_announcement_text_field',
        'site-admin-toolkit',
        'sat_announcement_section'
    );

    /*
     * Maintenance Mode section
     */
    add_settings_section(
        'sat_maintenance_section',
        'Maintenance Mode',
        'sat_maintenance_section_callback',
        'site-admin-toolkit'
    );

    add_settings_field(
        'sat_maintenance_enabled',
        'Enable Maintenance Mode',
        'sat_maintenance_enabled_field',
        'site-admin-toolkit',
        'sat_maintenance_section'
    );

    add_settings_field(
        'sat_maintenance_title',
        'Maintenance Title',
        'sat_maintenance_title_field',
        'site-admin-toolkit',
        'sat_maintenance_section'
    );

    add_settings_field(
        'sat_maintenance_message',
        'Maintenance Message',
        'sat_maintenance_message_field',
        'site-admin-toolkit',
        'sat_maintenance_section'
    );

    add_settings_field(
        'sat_maintenance_return',
        'Return Message',
        'sat_maintenance_return_field',
        'site-admin-toolkit',
        'sat_maintenance_section'
    );

    add_settings_section(
        'sat_trusted_proxy_section',
        'Trusted Reverse Proxy',
        'sat_trusted_proxy_section_callback',
        'site-admin-toolkit'
    );

    add_settings_field(
        'sat_trusted_proxy_enabled',
        'Enable Trusted Proxy',
        'sat_trusted_proxy_enabled_field',
        'site-admin-toolkit',
        'sat_trusted_proxy_section'
    );

    add_settings_field(
        'sat_trusted_proxy_header',
        'Client IP Header',
        'sat_trusted_proxy_header_field',
        'site-admin-toolkit',
        'sat_trusted_proxy_section'
    );

    add_settings_field(
        'sat_trusted_proxy_ips',
        'Trusted Proxy IPs / CIDRs',
        'sat_trusted_proxy_ips_field',
        'site-admin-toolkit',
        'sat_trusted_proxy_section'
    );
}

add_action(
    'admin_init',
    'sat_register_settings'
);

function sat_sanitize_settings($input)
{
    return [
        'announcement_enabled' =>
            !empty($input['announcement_enabled']) ? 1 : 0,

        'announcement_text' =>
            sanitize_textarea_field(
                $input['announcement_text'] ?? ''
            ),

        'maintenance_enabled' =>
            !empty($input['maintenance_enabled']) ? 1 : 0,

        'maintenance_title' =>
            sanitize_text_field(
                $input['maintenance_title'] ?? ''
            ),

        'maintenance_message' =>
            sanitize_textarea_field(
                $input['maintenance_message'] ?? ''
            ),

        'maintenance_return' =>
            sanitize_text_field(
                $input['maintenance_return'] ?? ''
            ),

        'trusted_proxy_enabled' =>
            !empty($input['trusted_proxy_enabled']) ? 1 : 0,

        'trusted_proxy_header' =>
            in_array(
                $input['trusted_proxy_header'] ?? '',
                [
                    'x_forwarded_for',
                    'cf_connecting_ip',
                    'true_client_ip',
                    'x_real_ip',
                ],
                true
            )
                ? $input['trusted_proxy_header']
                : 'x_forwarded_for',

        'trusted_proxy_ips' =>
            sanitize_textarea_field(
                $input['trusted_proxy_ips'] ?? ''
            )
    ];
}


/*
 * Announcement fields
 */

function sat_announcement_section_callback()
{
    echo '<p>Configure a reusable site announcement displayed with <code>[sat_announcement]</code>.</p>';
}

function sat_announcement_enabled_field()
{
    $options = get_option('sat_settings', []);

    ?>
    <label>
        <input
            type="checkbox"
            name="sat_settings[announcement_enabled]"
            value="1"
            <?php checked(!empty($options['announcement_enabled'])); ?>
        >
        Display the announcement
    </label>
    <?php
}

function sat_announcement_text_field()
{
    $options = get_option('sat_settings', []);

    ?>
    <textarea
        name="sat_settings[announcement_text]"
        rows="5"
        class="large-text"
        placeholder="Enter your site announcement..."
    ><?php
        echo esc_textarea(
            $options['announcement_text'] ?? ''
        );
    ?></textarea>
    <?php
}


/*
 * Maintenance Mode fields
 */

function sat_maintenance_section_callback()
{
    echo '<p>Temporarily hide the public website while administrators continue working normally.</p>';
}

function sat_maintenance_enabled_field()
{
    $options = get_option('sat_settings', []);

    ?>
    <label>
        <input
            type="checkbox"
            name="sat_settings[maintenance_enabled]"
            value="1"
            <?php checked(!empty($options['maintenance_enabled'])); ?>
        >
        Enable maintenance mode for public visitors
    </label>

    <p class="description">
        Logged-in administrators automatically bypass maintenance mode.
    </p>
    <?php
}

function sat_maintenance_title_field()
{
    $options = get_option('sat_settings', []);

    $value = $options['maintenance_title']
        ?? 'Site Maintenance';

    ?>
    <input
        type="text"
        name="sat_settings[maintenance_title]"
        value="<?php echo esc_attr($value); ?>"
        class="regular-text"
    >
    <?php
}

function sat_maintenance_message_field()
{
    $options = get_option('sat_settings', []);

    $value = $options['maintenance_message']
        ?? 'We are currently performing scheduled maintenance. Please check back soon.';

    ?>
    <textarea
        name="sat_settings[maintenance_message]"
        rows="5"
        class="large-text"
    ><?php echo esc_textarea($value); ?></textarea>
    <?php
}

function sat_maintenance_return_field()
{
    $options = get_option('sat_settings', []);

    ?>
    <input
        type="text"
        name="sat_settings[maintenance_return]"
        value="<?php
            echo esc_attr(
                $options['maintenance_return'] ?? ''
            );
        ?>"
        class="regular-text"
        placeholder="Example: Expected back at 3:00 PM"
    >

    <p class="description">
        Optional. Leave blank if there is no estimated return time.
    </p>
    <?php
}



function sat_trusted_proxy_section_callback()
{
    if (!sat_feature_enabled('trusted_proxy_support')) {
        echo '<p><strong>Premium feature.</strong> Activate Premium to configure trusted proxy handling.</p>';
        return;
    }

    echo '<p>Only forwarding headers from explicitly trusted proxy IPs or CIDR ranges will be accepted.</p>';
}

function sat_trusted_proxy_enabled_field()
{
    $options = get_option('sat_settings', []);
    $locked = !sat_feature_enabled('trusted_proxy_support');
    ?>
    <label>
        <input
            type="checkbox"
            name="sat_settings[trusted_proxy_enabled]"
            value="1"
            <?php checked(!empty($options['trusted_proxy_enabled'])); ?>
            <?php disabled($locked); ?>
        >
        Trust configured reverse proxies
    </label>
    <?php
}

function sat_trusted_proxy_header_field()
{
    $options = get_option('sat_settings', []);
    $value = $options['trusted_proxy_header'] ?? 'x_forwarded_for';
    $locked = !sat_feature_enabled('trusted_proxy_support');
    ?>
    <select
        name="sat_settings[trusted_proxy_header]"
        <?php disabled($locked); ?>
    >
        <option value="x_forwarded_for" <?php selected($value, 'x_forwarded_for'); ?>>
            X-Forwarded-For
        </option>
        <option value="cf_connecting_ip" <?php selected($value, 'cf_connecting_ip'); ?>>
            CF-Connecting-IP
        </option>
        <option value="true_client_ip" <?php selected($value, 'true_client_ip'); ?>>
            True-Client-IP
        </option>
        <option value="x_real_ip" <?php selected($value, 'x_real_ip'); ?>>
            X-Real-IP
        </option>
    </select>
    <?php
}

function sat_trusted_proxy_ips_field()
{
    $options = get_option('sat_settings', []);
    $value = $options['trusted_proxy_ips'] ?? '';
    $locked = !sat_feature_enabled('trusted_proxy_support');
    ?>
    <textarea
        name="sat_settings[trusted_proxy_ips]"
        rows="6"
        class="large-text code"
        placeholder="127.0.0.1&#10;10.0.0.0/8&#10;192.168.1.10"
        <?php disabled($locked); ?>
    ><?php echo esc_textarea($value); ?></textarea>

    <p class="description">
        One IP or CIDR range per line. Forwarded headers are ignored unless
        REMOTE_ADDR matches one of these trusted proxies.
    </p>
    <?php
}
