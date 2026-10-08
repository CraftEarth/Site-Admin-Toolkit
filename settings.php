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
                'announcement_text' => ''
            ]
        ]
    );

    add_settings_section(
        'sat_main_section',
        'Announcement Settings',
        'sat_main_section_callback',
        'site-admin-toolkit'
    );

    add_settings_field(
        'sat_announcement_enabled',
        'Enable Announcement',
        'sat_announcement_enabled_field',
        'site-admin-toolkit',
        'sat_main_section'
    );

    add_settings_field(
        'sat_announcement_text',
        'Announcement Text',
        'sat_announcement_text_field',
        'site-admin-toolkit',
        'sat_main_section'
    );
}

add_action('admin_init', 'sat_register_settings');

function sat_sanitize_settings($input)
{
    return [
        'announcement_enabled' =>
            !empty($input['announcement_enabled']) ? 1 : 0,

        'announcement_text' =>
            sanitize_textarea_field(
                $input['announcement_text'] ?? ''
            )
    ];
}

function sat_main_section_callback()
{
    echo '<p>Configure a reusable site announcement that can be displayed using the shortcode <code>[sat_announcement]</code>.</p>';
}

function sat_announcement_enabled_field()
{
    $options = get_option('sat_settings', []);

    $enabled =
        !empty($options['announcement_enabled']);

    ?>
    <label>
        <input
            type="checkbox"
            name="sat_settings[announcement_enabled]"
            value="1"
            <?php checked($enabled); ?>
        >
        Display the announcement
    </label>
    <?php
}

function sat_announcement_text_field()
{
    $options = get_option('sat_settings', []);

    $text =
        $options['announcement_text'] ?? '';

    ?>
    <textarea
        name="sat_settings[announcement_text]"
        rows="5"
        class="large-text"
        placeholder="Enter your site announcement..."
    ><?php echo esc_textarea($text); ?></textarea>
    <?php
}
