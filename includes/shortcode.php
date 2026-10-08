<?php

if (!defined('ABSPATH')) {
    exit;
}

function sat_announcement_shortcode()
{
    $options =
        get_option(
            'sat_settings',
            []
        );

    if (
        empty(
            $options['announcement_enabled']
        )
    ) {
        return '';
    }

    $text =
        trim(
            $options['announcement_text']
            ?? ''
        );

    if ($text === '') {
        return '';
    }

    return sprintf(
        '<div class="sat-announcement">%s</div>',
        esc_html($text)
    );
}

add_shortcode(
    'sat_announcement',
    'sat_announcement_shortcode'
);
