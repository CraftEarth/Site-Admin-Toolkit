<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run Site Admin Toolkit database migrations only when needed.
 */
function sat_run_database_migrations()
{
    $installed = get_option(
        'sat_db_version',
        ''
    );

    if ($installed === SAT_DB_VERSION) {
        return;
    }

    if (function_exists('sat_activity_install')) {
        sat_activity_install();
    }

    if (function_exists('sat_install_file_forensics_table')) {
        sat_install_file_forensics_table();
    }

    if (function_exists('sat_install_server_log_table')) {
        sat_install_server_log_table();
    }

    if (function_exists('sat_install_incident_table')) {
        sat_install_incident_table();
    }

    update_option(
        'sat_db_version',
        SAT_DB_VERSION,
        false
    );
}

/**
 * Run migrations after WordPress loads the plugin.
 */
add_action(
    'init',
    'sat_run_database_migrations',
    5
);