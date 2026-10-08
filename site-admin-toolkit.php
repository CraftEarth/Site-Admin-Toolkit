<?php
/**
 * Plugin Name: Site Admin Toolkit
 * Plugin URI: https://github.com/CraftEarth
 * Description: Lightweight WordPress administration toolkit with site information, dashboard tools, announcements, and reusable admin features.
 * Version: 1.0.0
 * Author: William Murphy / CraftEarth
 * Author URI: https://github.com/CraftEarth
 * License: GPL-2.0-or-later
 * Text Domain: site-admin-toolkit
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SAT_VERSION', '1.0.0');
define('SAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAT_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SAT_PLUGIN_DIR . 'includes/settings.php';
require_once SAT_PLUGIN_DIR . 'includes/admin-page.php';
require_once SAT_PLUGIN_DIR . 'includes/dashboard-widget.php';
require_once SAT_PLUGIN_DIR . 'includes/shortcode.php';

/**
 * Load admin CSS.
 */
function sat_admin_assets($hook)
{
    if (strpos($hook, 'site-admin-toolkit') === false) {
        return;
    }

    wp_enqueue_style(
        'sat-admin',
        SAT_PLUGIN_URL . 'assets/css/admin.css',
        [],
        SAT_VERSION
    );
}

add_action('admin_enqueue_scripts', 'sat_admin_assets');
