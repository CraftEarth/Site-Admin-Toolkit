<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculate directory size with a safety file limit.
 */
function sat_directory_size($directory, $max_files = 20000)
{
    if (!is_dir($directory) || !is_readable($directory)) {
        return [
            'size' => 0,
            'files' => 0,
            'limited' => false,
        ];
    }

    $size = 0;
    $files = 0;
    $limited = false;

    try {

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {

            if ($files >= $max_files) {
                $limited = true;
                break;
            }

            if ($file->isFile()) {
                $size += $file->getSize();
                $files++;
            }
        }

    } catch (Exception $e) {

        $limited = true;
    }

    return [
        'size' => $size,
        'files' => $files,
        'limited' => $limited,
    ];
}


/**
 * Get total database size.
 */
function sat_get_database_size()
{
    global $wpdb;

    $size = $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT SUM(data_length + index_length)
            FROM information_schema.TABLES
            WHERE table_schema = %s
            ",
            DB_NAME
        )
    );

    return $size ? (int) $size : 0;
}


/**
 * Detect common backup plugins.
 */
function sat_detect_backup_plugins()
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugins = get_plugins();

    $known = [
        'updraftplus' => 'UpdraftPlus',
        'duplicator' => 'Duplicator',
        'backwpup' => 'BackWPup',
        'all-in-one-wp-migration' => 'All-in-One WP Migration',
        'wpvivid' => 'WPvivid',
        'jetpack' => 'Jetpack',
        'backupbuddy' => 'BackupBuddy',
    ];

    $detected = [];

    foreach ($plugins as $plugin_file => $plugin_data) {

        foreach ($known as $slug => $name) {

            if (stripos($plugin_file, $slug) !== false) {

                $detected[] = [
                    'name' => $name,
                    'active' => is_plugin_active($plugin_file),
                ];

                break;
            }
        }
    }

    return $detected;
}


/**
 * Gather backup readiness data.
 */
function sat_get_backup_readiness()
{
    global $wpdb;

    $uploads = wp_upload_dir();

    $content_info =
        sat_directory_size(WP_CONTENT_DIR);

    $upload_info =
        sat_directory_size($uploads['basedir']);

    $database_size =
        sat_get_database_size();

    $backup_plugins =
        sat_detect_backup_plugins();

    $active_backup_plugin = false;

    foreach ($backup_plugins as $plugin) {
        if (!empty($plugin['active'])) {
            $active_backup_plugin = true;
            break;
        }
    }

    $content_writable =
        is_writable(WP_CONTENT_DIR);

    $uploads_writable =
        is_writable($uploads['basedir']);

    $config_exists =
        file_exists(ABSPATH . 'wp-config.php');

    $checks = [
        [
            'label' => 'Backup Plugin',
            'value' => $active_backup_plugin
                ? 'Detected'
                : 'Not Detected',
            'status' => $active_backup_plugin
                ? 'good'
                : 'warning',
            'message' => $active_backup_plugin
                ? 'An active recognized backup plugin was detected.'
                : 'No active recognized backup plugin was detected.',
        ],

        [
            'label' => 'wp-content',
            'value' => $content_writable
                ? 'Writable'
                : 'Not Writable',
            'status' => $content_writable
                ? 'good'
                : 'warning',
            'message' => $content_writable
                ? 'WordPress can write to wp-content.'
                : 'wp-content is not writable by PHP.',
        ],

        [
            'label' => 'Uploads',
            'value' => $uploads_writable
                ? 'Writable'
                : 'Not Writable',
            'status' => $uploads_writable
                ? 'good'
                : 'warning',
            'message' => $uploads_writable
                ? 'The uploads directory is writable.'
                : 'The uploads directory is not writable.',
        ],

        [
            'label' => 'wp-config.php',
            'value' => $config_exists
                ? 'Detected'
                : 'Not Found',
            'status' => $config_exists
                ? 'good'
                : 'critical',
            'message' => $config_exists
                ? 'WordPress configuration file detected.'
                : 'wp-config.php could not be located.',
        ],
    ];

    $warnings = 0;
    $critical = 0;

    foreach ($checks as $check) {

        if ($check['status'] === 'warning') {
            $warnings++;
        }

        if ($check['status'] === 'critical') {
            $critical++;
        }
    }

    if ($critical > 0) {

        $overall = 'critical';
        $overall_label = 'Critical';

    } elseif ($warnings > 0) {

        $overall = 'warning';
        $overall_label = 'Needs Attention';

    } else {

        $overall = 'good';
        $overall_label = 'Ready';
    }

    return [
        'overall' => $overall,
        'overall_label' => $overall_label,
        'checks' => $checks,
        'database_size' => $database_size,
        'content_info' => $content_info,
        'upload_info' => $upload_info,
        'backup_plugins' => $backup_plugins,
        'database_prefix' => $wpdb->prefix,
    ];
}


/**
 * Render backup readiness panel.
 */
function sat_render_backup_readiness()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $data = sat_get_backup_readiness();

    ?>
    <div class="sat-panel sat-backup-panel">

        <div class="sat-health-heading">

            <div>
                <h2>Backup & Protection Readiness</h2>

                <p>
                    Review storage, permissions,
                    configuration and backup-tool readiness.
                </p>
            </div>

            <span class="sat-status sat-status-<?php echo esc_attr($data['overall']); ?>">
                <?php echo esc_html($data['overall_label']); ?>
            </span>

        </div>


        <div class="sat-health-grid">

            <?php foreach ($data['checks'] as $check) : ?>

                <div class="sat-health-card">

                    <div class="sat-health-card-top">

                        <strong>
                            <?php echo esc_html($check['label']); ?>
                        </strong>

                        <span class="sat-status sat-status-<?php echo esc_attr($check['status']); ?>">
                            <?php echo esc_html(ucfirst($check['status'])); ?>
                        </span>

                    </div>

                    <div class="sat-health-value">
                        <?php echo esc_html($check['value']); ?>
                    </div>

                    <p>
                        <?php echo esc_html($check['message']); ?>
                    </p>

                </div>

            <?php endforeach; ?>

        </div>


        <h3>Backup Size Estimate</h3>

        <table class="widefat striped">

            <tbody>

                <tr>
                    <td><strong>Database Size</strong></td>
                    <td>
                        <?php echo esc_html(size_format($data['database_size'])); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>wp-content Size</strong></td>
                    <td>
                        <?php echo esc_html(size_format($data['content_info']['size'])); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>wp-content Files</strong></td>
                    <td>
                        <?php echo esc_html(number_format_i18n($data['content_info']['files'])); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Uploads Size</strong></td>
                    <td>
                        <?php echo esc_html(size_format($data['upload_info']['size'])); ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Database Prefix</strong></td>
                    <td>
                        <code><?php echo esc_html($data['database_prefix']); ?></code>
                    </td>
                </tr>

            </tbody>

        </table>


        <h3>Backup Software Detection</h3>

        <?php if (empty($data['backup_plugins'])) : ?>

            <div class="sat-log-empty">
                No recognized WordPress backup plugin is currently installed.
                External server-level backups may still exist.
            </div>

        <?php else : ?>

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>Backup Tool</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($data['backup_plugins'] as $plugin) : ?>

                        <tr>
                            <td>
                                <?php echo esc_html($plugin['name']); ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $plugin['active']
                                        ? 'Active'
                                        : 'Installed / Inactive'
                                );
                                ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>


        <div class="sat-diagnostic-note">

            <strong>Important:</strong>

            This panel evaluates backup readiness.
            It does not guarantee that a usable backup exists.

        </div>

    </div>
    <?php
}
