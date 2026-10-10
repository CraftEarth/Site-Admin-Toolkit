<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server log ingestion table.
 */
function sat_server_log_table()
{
    global $wpdb;

    return $wpdb->prefix . 'sat_server_log_events';
}


/**
 * Install server log ingestion table.
 */
function sat_install_server_log_table()
{
    global $wpdb;

    $version = '1.1';

    if (
        get_option('sat_server_log_db_version')
        === $version
    ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table =
        sat_server_log_table();

    $charset =
        $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            detected_at datetime NOT NULL,
            source varchar(20) NOT NULL,
            log_path text NOT NULL,
            remote_ip varchar(45) NULL,
            method varchar(12) NULL,
            request_uri text NULL,
            status_code smallint unsigned NULL,
            bytes_sent bigint unsigned NULL,
            user_agent text NULL,
            raw_line text NULL,
            fingerprint char(64) NOT NULL,
            PRIMARY KEY (id),
            KEY detected_at (detected_at),
            KEY source (source),
            KEY status_code (status_code),
            KEY remote_ip (remote_ip),
            UNIQUE KEY fingerprint (fingerprint)
        ) {$charset};
    ";

    dbDelta($sql);

    update_option(
        'sat_server_log_db_version',
        $version,
        false
    );
}

add_action(
    'admin_init',
    'sat_install_server_log_table'
);


/**
 * Validate a server log path before reading it.
 *
 * Only local readable .log files are accepted.
 */
function sat_validate_server_log_path($path)
{
    $path = trim((string) $path);

    if ($path === '') {
        return false;
    }

    // Never allow PHP stream wrappers or URLs.
    if (strpos($path, '://') !== false) {
        return false;
    }

    // Reject null-byte tricks.
    if (strpos($path, "\0") !== false) {
        return false;
    }

    $real = realpath($path);

    if ($real === false) {
        return false;
    }

    if (!is_file($real) || !is_readable($real)) {
        return false;
    }

    // This feature is for server log files only.
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'log') {
        return false;
    }

    return $real;
}


/**
 * Read only the end of a log file.
 *
 * Prevents loading a multi-gigabyte access log into PHP memory.
 */
function sat_tail_server_log($path, $max_lines = 500, $max_bytes = 524288)
{
    $path = sat_validate_server_log_path($path);

    if (!$path) {
        return [];
    }

    $max_lines = max(
        1,
        min(2000, absint($max_lines))
    );

    $max_bytes = max(
        4096,
        min(2097152, absint($max_bytes))
    );

    $handle = @fopen($path, 'rb');

    if (!$handle) {
        return [];
    }

    if (fseek($handle, 0, SEEK_END) !== 0) {
        fclose($handle);
        return [];
    }

    $position = ftell($handle);

    if ($position === false || $position <= 0) {
        fclose($handle);
        return [];
    }

    $buffer = '';
    $bytes_read = 0;
    $chunk_size = 8192;

    while (
        $position > 0
        && $bytes_read < $max_bytes
    ) {
        $read_size = min(
            $chunk_size,
            $position,
            $max_bytes - $bytes_read
        );

        $position -= $read_size;

        if (fseek($handle, $position, SEEK_SET) !== 0) {
            break;
        }

        $chunk = fread(
            $handle,
            $read_size
        );

        if ($chunk === false) {
            break;
        }

        $buffer = $chunk . $buffer;
        $bytes_read += strlen($chunk);

        if (
            substr_count($buffer, "\n")
            > $max_lines
        ) {
            break;
        }
    }

    fclose($handle);

    $lines = preg_split(
        '/\r\n|\r|\n/',
        $buffer
    );

    $lines = array_values(
        array_filter(
            $lines,
            static function ($line) {
                return trim($line) !== '';
            }
        )
    );

    if (count($lines) > $max_lines) {
        $lines = array_slice(
            $lines,
            -$max_lines
        );
    }

    return $lines;
}


/**
 * Parse Apache/Nginx Common or Combined access-log format.
 */
function sat_parse_server_access_log_line($line)
{
    $line = trim((string) $line);

    if ($line === '') {
        return false;
    }

    $pattern =
        '/^(\S+)\s+\S+\s+\S+\s+' .
        '\[([^\]]+)\]\s+' .
        '"([A-Z]+)\s+(.+?)(?:\s+HTTP\/[0-9.]+)?"\s+' .
        '(\d{3})\s+' .
        '(\S+)' .
        '(?:\s+"([^"]*)"\s+"([^"]*)")?/';

    if (!preg_match($pattern, $line, $matches)) {
        return false;
    }

    $remote_ip = $matches[1];

    if (!filter_var($remote_ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    $log_time = DateTime::createFromFormat(
        'd/M/Y:H:i:s O',
        $matches[2]
    );

    $detected_at = $log_time
        ? $log_time->format('Y-m-d H:i:s')
        : current_time('mysql');

    $bytes = null;

    if (
        isset($matches[6])
        && $matches[6] !== '-'
        && is_numeric($matches[6])
    ) {
        $bytes = absint($matches[6]);
    }

    return [
        'detected_at' => $detected_at,
        'remote_ip' => $remote_ip,
        'method' => sanitize_key(
            strtolower($matches[3])
        ),
        'request_uri' => sanitize_text_field(
            $matches[4]
        ),
        'status_code' => absint(
            $matches[5]
        ),
        'bytes_sent' => $bytes,
        'referer' => isset($matches[7])
            ? sanitize_text_field($matches[7])
            : '',
        'user_agent' => isset($matches[8])
            ? sanitize_text_field($matches[8])
            : '',
        'raw_line' => $line,
    ];
}


/**
 * Import recent Apache/Nginx access-log events.
 */
function sat_ingest_server_log($path, $source = 'apache', $max_lines = 500)
{
    if (!sat_feature_enabled('server_log_ingestion')) {
        return [
            'success' => false,
            'imported' => 0,
            'skipped' => 0,
            'message' => 'Premium server log ingestion is not enabled.',
        ];
    }

    $source = sanitize_key($source);

    if (!in_array($source, ['apache', 'nginx'], true)) {
        $source = 'apache';
    }

    $real_path =
        sat_validate_server_log_path($path);

    if (!$real_path) {
        return [
            'success' => false,
            'imported' => 0,
            'skipped' => 0,
            'message' => 'The selected log file is not readable or valid.',
        ];
    }

    $lines =
        sat_tail_server_log(
            $real_path,
            $max_lines
        );

    global $wpdb;

    $table =
        sat_server_log_table();

    $imported = 0;
    $skipped = 0;

    foreach ($lines as $line) {
        $event =
            sat_parse_server_access_log_line(
                $line
            );

        if (!$event) {
            $skipped++;
            continue;
        }

        $fingerprint =
            hash(
                'sha256',
                $source .
                '|' .
                $real_path .
                '|' .
                $line
            );

        $inserted =
            $wpdb->query(
                $wpdb->prepare(
                    "
                    INSERT IGNORE INTO {$table}
                    (
                        detected_at,
                        source,
                        log_path,
                        remote_ip,
                        method,
                        request_uri,
                        status_code,
                        bytes_sent,
                        user_agent,
                        raw_line,
                        fingerprint
                    )
                    VALUES
                    (
                        %s, %s, %s, %s, %s,
                        %s, %d, %s, %s, %s, %s
                    )
                    ",
                    $event['detected_at'],
                    $source,
                    $real_path,
                    $event['remote_ip'],
                    strtoupper(
                        $event['method']
                    ),
                    $event['request_uri'],
                    $event['status_code'],
                    $event['bytes_sent'],
                    $event['user_agent'],
                    $event['raw_line'],
                    $fingerprint
                )
            );

        if ($inserted === 1) {
            $imported++;
        } else {
            $skipped++;
        }
    }

    return [
        'success' => true,
        'imported' => $imported,
        'skipped' => $skipped,
        'message' =>
            sprintf(
                'Imported %d new events. Skipped %d existing or unrecognized lines.',
                $imported,
                $skipped
            ),
    ];
}

/**
 * Get server log ingestion settings.
 */
function sat_get_server_log_settings()
{
    $defaults = [
        'source' => 'apache',
        'log_path' => '',
        'max_lines' => 500,
    ];

    $saved = get_option(
        'sat_server_log_settings',
        []
    );

    return wp_parse_args(
        is_array($saved) ? $saved : [],
        $defaults
    );
}


/**
 * Save Premium server log settings.
 */
function sat_save_server_log_settings()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer(
        'sat_save_server_log_settings'
    );

    if (!sat_feature_enabled('server_log_ingestion')) {
        wp_die('Premium feature required.');
    }

    $source =
        isset($_POST['source'])
            ? sanitize_key(
                wp_unslash($_POST['source'])
            )
            : 'apache';

    if (!in_array($source, ['apache', 'nginx'], true)) {
        $source = 'apache';
    }

    $log_path =
        isset($_POST['log_path'])
            ? sanitize_text_field(
                wp_unslash($_POST['log_path'])
            )
            : '';

    $max_lines =
        isset($_POST['max_lines'])
            ? absint($_POST['max_lines'])
            : 500;

    $max_lines =
        max(
            50,
            min(2000, $max_lines)
        );

    update_option(
        'sat_server_log_settings',
        [
            'source' => $source,
            'log_path' => $log_path,
            'max_lines' => $max_lines,
        ],
        false
    );

    wp_safe_redirect(
        add_query_arg(
            'sat_log_status',
            'saved',
            admin_url(
                'admin.php?page=site-admin-toolkit&tab=integrations'
            )
        )
    );

    exit;
}

add_action(
    'admin_post_sat_save_server_log_settings',
    'sat_save_server_log_settings'
);


/**
 * Manually ingest configured server log.
 */
function sat_run_server_log_ingestion()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer(
        'sat_run_server_log_ingestion'
    );

    if (!sat_feature_enabled('server_log_ingestion')) {
        wp_die('Premium feature required.');
    }

    $settings =
        sat_get_server_log_settings();

    $result =
        sat_ingest_server_log(
            $settings['log_path'],
            $settings['source'],
            $settings['max_lines']
        );

    set_transient(
        'sat_server_log_result_' .
        get_current_user_id(),
        $result,
        60
    );

    wp_safe_redirect(
        admin_url(
            'admin.php?page=site-admin-toolkit&tab=integrations'
        )
    );

    exit;
}

add_action(
    'admin_post_sat_run_server_log_ingestion',
    'sat_run_server_log_ingestion'
);


/**
 * Render Premium server log ingestion controls.
 */
function sat_render_server_log_ingestion_panel()
{
    if (!sat_feature_enabled('server_log_ingestion')) {
        return;
    }

    global $wpdb;

    $settings =
        sat_get_server_log_settings();

    $result =
        get_transient(
            'sat_server_log_result_' .
            get_current_user_id()
        );

    if ($result !== false) {
        delete_transient(
            'sat_server_log_result_' .
            get_current_user_id()
        );
    }

    $saved =
        isset($_GET['sat_log_status'])
        && sanitize_key(
            wp_unslash($_GET['sat_log_status'])
        ) === 'saved';

    $recent =
        $wpdb->get_results(
            "
            SELECT
                detected_at,
                source,
                remote_ip,
                method,
                status_code,
                request_uri
            FROM " . sat_server_log_table() . "
            ORDER BY id DESC
            LIMIT 25
            ",
            ARRAY_A
        );

    $total =
        (int) $wpdb->get_var(
            "
            SELECT COUNT(*)
            FROM " . sat_server_log_table()
        );
    ?>

    <hr>

    <h3>
        Apache / Nginx Log Ingestion
    </h3>

    <p>
        Import recent web-server access-log events for
        security analysis without loading the entire log file.
    </p>

    <?php if ($saved) : ?>

        <div class="notice notice-success inline">
            <p>
                Server log settings saved.
            </p>
        </div>

    <?php endif; ?>

    <?php if (is_array($result)) : ?>

        <div class="notice <?php
            echo !empty($result['success'])
                ? 'notice-success'
                : 'notice-error';
        ?> inline">

            <p>
                <?php
                echo esc_html(
                    $result['message'] ?? 'Log ingestion completed.'
                );
                ?>
            </p>

        </div>

    <?php endif; ?>

    <form
        method="post"
        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
    >

        <input
            type="hidden"
            name="action"
            value="sat_save_server_log_settings"
        >

        <?php
        wp_nonce_field(
            'sat_save_server_log_settings'
        );
        ?>

        <table class="form-table">

            <tr>
                <th scope="row">
                    Web Server
                </th>

                <td>
                    <select name="source">

                        <option
                            value="apache"
                            <?php selected(
                                $settings['source'],
                                'apache'
                            ); ?>
                        >
                            Apache
                        </option>

                        <option
                            value="nginx"
                            <?php selected(
                                $settings['source'],
                                'nginx'
                            ); ?>
                        >
                            Nginx
                        </option>

                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    Access Log Path
                </th>

                <td>
                    <input
                        type="text"
                        name="log_path"
                        class="regular-text"
                        value="<?php
                            echo esc_attr(
                                $settings['log_path']
                            );
                        ?>"
                        placeholder="/var/log/apache2/access.log"
                    >

                    <p class="description">
                        The PHP/WordPress process must have
                        read permission for this .log file.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    Lines Per Scan
                </th>

                <td>
                    <input
                        type="number"
                        name="max_lines"
                        min="50"
                        max="2000"
                        value="<?php
                            echo esc_attr(
                                $settings['max_lines']
                            );
                        ?>"
                    >
                </td>
            </tr>

        </table>

        <?php
        submit_button(
            'Save Log Settings',
            'secondary'
        );
        ?>

    </form>


    <form
        method="post"
        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        style="margin-bottom:20px;"
    >

        <input
            type="hidden"
            name="action"
            value="sat_run_server_log_ingestion"
        >

        <?php
        wp_nonce_field(
            'sat_run_server_log_ingestion'
        );

        submit_button(
            'Ingest Server Log Now',
            'primary',
            'submit',
            false
        );
        ?>

    </form>

    <p>
        <strong>Stored log events:</strong>
        <?php echo esc_html(number_format_i18n($total)); ?>
    </p>

    <?php if (!empty($recent)) : ?>

        <div class="sat-table-scroll">

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Server</th>
                        <th>IP</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Request</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($recent as $event) : ?>

                    <tr>
                        <td>
                            <?php echo esc_html($event['detected_at']); ?>
                        </td>

                        <td>
                            <?php echo esc_html(
                                strtoupper($event['source'])
                            ); ?>
                        </td>

                        <td>
                            <code><?php
                                echo esc_html($event['remote_ip']);
                            ?></code>
                        </td>

                        <td>
                            <?php echo esc_html($event['method']); ?>
                        </td>

                        <td>
                            <?php echo esc_html($event['status_code']); ?>
                        </td>

                        <td>
                            <code><?php
                                echo esc_html($event['request_uri']);
                            ?></code>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

    <?php
}
