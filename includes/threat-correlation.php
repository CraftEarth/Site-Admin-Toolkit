<?php

if (!defined('ABSPATH')) {
    exit;
}

define('SAT_INCIDENT_DB_VERSION', '1.0');

function sat_incident_table()
{
    global $wpdb;

    return $wpdb->prefix . 'sat_incidents';
}

function sat_install_incident_table()
{
    $installed = get_option('sat_incident_db_version');

    if ($installed === SAT_INCIDENT_DB_VERSION) {
        return;
    }

    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = sat_incident_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            incident_key CHAR(64) NOT NULL,
            source_type VARCHAR(60) NOT NULL,
            source_value VARCHAR(190) NOT NULL,
            title VARCHAR(255) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'warning',
            risk_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            acknowledged_by BIGINT UNSIGNED NULL,
            acknowledged_at DATETIME NULL,
            investigation_notes LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY incident_key (incident_key),
            KEY status (status),
            KEY source_type (source_type),
            KEY risk_score (risk_score),
            KEY updated_at (updated_at)
        ) {$charset};
    ";

    dbDelta($sql);

    update_option(
        'sat_incident_db_version',
        SAT_INCIDENT_DB_VERSION,
        false
    );
}

add_action(
    'admin_init',
    'sat_install_incident_table'
);


/**
 * Pull recent security events for correlation.
 */
function sat_get_correlation_events($hours = 24)
{
    global $wpdb;

    $hours = max(1, min(168, absint($hours)));

    return $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT *
            FROM " . sat_activity_table() . "
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)
            ORDER BY id DESC
            ",
            $hours
        ),
        ARRAY_A
    );
}


/**
 * Build correlated threat intelligence from raw events.
 */
function sat_build_threat_correlation($hours = 24)
{
    $events = sat_get_correlation_events($hours);

    $ips = [];
    $outbound = [];
    $admin_events = [];
    $plugin_events = [];

    foreach ($events as $event) {

        $type = $event['event_type'];
        $ip   = $event['ip_address'];

        /*
         * IP correlation.
         */
        if ($ip !== '') {

            if (!isset($ips[$ip])) {
                $ips[$ip] = [
                    'ip' => $ip,
                    'events' => 0,
                    'failed_logins' => 0,
                    'usernames' => [],
                    'probes' => 0,
                    'xmlrpc' => 0,
                    'posts' => 0,
                    'rest' => 0,
                    'raw_risk' => 0,
                    'reasons' => [],
                ];
            }

            $ips[$ip]['events']++;
            $ips[$ip]['raw_risk'] += absint($event['risk_score']);

            if ($type === 'login_failed') {

                $ips[$ip]['failed_logins']++;

                if (!empty($event['details'])) {
                    $ips[$ip]['usernames'][$event['details']] = true;
                }
            }

            if ($type === '404_probe') {
                $ips[$ip]['probes']++;
            }

            if ($type === 'xmlrpc_request') {
                $ips[$ip]['xmlrpc']++;
            }

            if ($type === 'post_request') {
                $ips[$ip]['posts']++;
            }

            if ($type === 'rest_request') {
                $ips[$ip]['rest']++;
            }
        }


        /*
         * Outbound destination inventory.
         */
        if (
            $type === 'http_outbound' &&
            !empty($event['endpoint'])
        ) {

            $host = wp_parse_url(
                $event['endpoint'],
                PHP_URL_HOST
            );

            if ($host) {

                $host = strtolower($host);

                if (!isset($outbound[$host])) {
                    $outbound[$host] = [
                        'host' => $host,
                        'requests' => 0,
                        'failures' => 0,
                        'last_seen' => $event['created_at'],
                    ];
                }

                $outbound[$host]['requests']++;

                if (
                    empty($event['status_code']) ||
                    (int) $event['status_code'] >= 400
                ) {
                    $outbound[$host]['failures']++;
                }

                if (
                    strtotime($event['created_at']) >
                    strtotime($outbound[$host]['last_seen'])
                ) {
                    $outbound[$host]['last_seen'] =
                        $event['created_at'];
                }
            }
        }


        /*
         * Sensitive administrative events.
         */
        if ($type === 'user_created') {
            $admin_events[] = $event;
        }

        if (
            $type === 'plugin_activated' ||
            $type === 'plugin_deactivated'
        ) {
            $plugin_events[] = $event;
        }
    }


    /*
     * Turn raw behavior into correlated scores.
     */
    foreach ($ips as &$item) {

        $score = 0;
        $reasons = [];

        $username_count =
            count($item['usernames']);

        /*
         * Failed login burst.
         */
        if ($item['failed_logins'] >= 20) {

            $score += 45;

            $reasons[] =
                'High-volume failed login activity';

        } elseif ($item['failed_logins'] >= 10) {

            $score += 30;

            $reasons[] =
                'Repeated failed login activity';

        } elseif ($item['failed_logins'] >= 5) {

            $score += 15;

            $reasons[] =
                'Multiple failed login attempts';
        }


        /*
         * Credential / username spraying.
         */
        if ($username_count >= 8) {

            $score += 40;

            $reasons[] =
                'Possible credential spraying across many usernames';

        } elseif ($username_count >= 4) {

            $score += 25;

            $reasons[] =
                'Multiple usernames targeted';
        }


        /*
         * Reconnaissance.
         */
        if ($item['probes'] >= 25) {

            $score += 35;

            $reasons[] =
                'Heavy 404 reconnaissance activity';

        } elseif ($item['probes'] >= 10) {

            $score += 20;

            $reasons[] =
                'Repeated path probing';

        } elseif ($item['probes'] >= 5) {

            $score += 10;

            $reasons[] =
                'Multiple missing-path probes';
        }


        /*
         * XML-RPC abuse.
         */
        if ($item['xmlrpc'] >= 20) {

            $score += 35;

            $reasons[] =
                'Heavy XML-RPC activity';

        } elseif ($item['xmlrpc'] >= 5) {

            $score += 15;

            $reasons[] =
                'Repeated XML-RPC requests';
        }


        /*
         * POST / REST bursts.
         */
        if ($item['posts'] >= 50) {

            $score += 15;

            $reasons[] =
                'High POST request volume';
        }

        if ($item['rest'] >= 100) {

            $score += 10;

            $reasons[] =
                'High REST API request volume';
        }


        /*
         * Preserve some weight from individual event risk.
         */
        $score += min(
            20,
            intval($item['raw_risk'] / 5)
        );

        $item['score'] =
            min(100, $score);

        $item['username_count'] =
            $username_count;

        $item['reasons'] =
            $reasons;

        $classification =
            sat_risk_label(
                $item['score']
            );

        $item['label'] =
            $classification['label'];

        $item['status'] =
            $classification['status'];
    }

    unset($item);


    usort(
        $ips,
        function ($a, $b) {
            return $b['score'] <=> $a['score'];
        }
    );

    uasort(
        $outbound,
        function ($a, $b) {
            return $b['requests'] <=> $a['requests'];
        }
    );


    return [
        'ips' => array_values($ips),
        'outbound' => array_values($outbound),
        'admin_events' => $admin_events,
        'plugin_events' => $plugin_events,
    ];
}


/**
 * Persist correlated threats as incidents.
 */
function sat_sync_correlated_incidents($hours = 24)
{
    if (!sat_feature_enabled('incident_workflow')) {
        return;
    }

    global $wpdb;

    $table = sat_incident_table();
    $data = sat_build_threat_correlation($hours);
    $now = current_time('mysql');

    foreach ($data['ips'] as $item) {

        $score = absint($item['score']);

        /*
         * Ignore low-risk / normal activity.
         */
        if ($score < 25) {
            continue;
        }

        $ip = sanitize_text_field($item['ip']);

        if ($ip === '') {
            continue;
        }

        $incident_key = hash(
            'sha256',
            'correlated_ip|' . strtolower($ip)
        );

        $severity = 'warning';

        if ($score >= 80) {
            $severity = 'critical';
        } elseif ($score >= 50) {
            $severity = 'high';
        }

        $reasons = !empty($item['reasons'])
            ? implode(', ', $item['reasons'])
            : 'Correlated suspicious activity';

        $title =
            'Suspicious activity from ' . $ip;

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT id, status
                FROM {$table}
                WHERE incident_key = %s
                LIMIT 1
                ",
                $incident_key
            ),
            ARRAY_A
        );

        if ($existing) {

            /*
             * A previously resolved incident is reopened when
             * new suspicious activity is observed.
             */
            $new_status =
                $existing['status'] === 'resolved'
                    ? 'open'
                    : $existing['status'];

            $wpdb->update(
                $table,
                [
                    'title' => $title,
                    'severity' => $severity,
                    'risk_score' => $score,
                    'status' => $new_status,
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                    'resolved_at' =>
                        $new_status === 'resolved'
                            ? $now
                            : null,
                ],
                [
                    'id' => absint($existing['id']),
                ]
            );

            continue;
        }

        $wpdb->insert(
            $table,
            [
                'incident_key' => $incident_key,
                'source_type' => 'ip',
                'source_value' => $ip,
                'title' => $title,
                'severity' => $severity,
                'risk_score' => $score,
                'status' => 'open',
                'investigation_notes' =>
                    sanitize_textarea_field($reasons),
                'created_at' => $now,
                'updated_at' => $now,
                'last_seen_at' => $now,
            ]
        );
    }
}
/**
 * CSV incident export.
 */
function sat_export_incident_report()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer(
        'sat_export_incident_report'
    );

    $data =
        sat_build_threat_correlation(24);

    nocache_headers();

    header(
        'Content-Type: text/csv; charset=utf-8'
    );

    header(
        'Content-Disposition: attachment; filename=site-admin-toolkit-incident-report.csv'
    );

    $out = fopen('php://output', 'w');

    fputcsv(
        $out,
        [
            'IP Address',
            'Risk Score',
            'Classification',
            'Events',
            'Failed Logins',
            'Usernames Targeted',
            '404 Probes',
            'XML-RPC Requests',
            'POST Requests',
            'REST Requests',
            'Reasons',
        ]
    );

    foreach ($data['ips'] as $item) {

        fputcsv(
            $out,
            [
                $item['ip'],
                $item['score'],
                $item['label'],
                $item['events'],
                $item['failed_logins'],
                $item['username_count'],
                $item['probes'],
                $item['xmlrpc'],
                $item['posts'],
                $item['rest'],
                implode(
                    '; ',
                    $item['reasons']
                ),
            ]
        );
    }

    fclose($out);
    exit;
}

add_action(
    'admin_post_sat_export_incident_report',
    'sat_export_incident_report'
);


/**
 * Render correlation console.
 */
function sat_render_threat_correlation()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $data =
        sat_build_threat_correlation(24);

    ?>
    <div class="sat-panel sat-threat-correlation">

        <div class="sat-health-heading">

            <div>
                <h2>
                    Advanced Threat Correlation
                </h2>

                <p>
                    Correlates authentication, reconnaissance,
                    XML-RPC, REST, POST, plugin and outbound HTTP
                    behavior from the previous 24 hours.
                </p>
            </div>

            <span class="sat-status sat-status-good">
                24 Hour Window
            </span>

        </div>


        <h3>Correlated Sources</h3>

        <?php if (empty($data['ips'])) : ?>

            <div class="sat-log-empty">
                No IP-based activity is available for correlation.
            </div>

        <?php else : ?>

            <div class="sat-table-scroll">

                <table class="widefat striped">

                    <thead>
                        <tr>
                            <th>IP</th>
                            <th>Risk</th>
                            <th>Events</th>
                            <th>Failed Logins</th>
                            <th>Usernames</th>
                            <th>404 Probes</th>
                            <th>XML-RPC</th>
                            <th>Reason</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($data['ips'] as $item) : ?>

                        <tr>

                            <td>
                                <code>
                                    <?php echo esc_html($item['ip']); ?>
                                </code>
                            </td>

                            <td>
                                <span class="sat-status sat-status-<?php
                                    echo esc_attr($item['status']);
                                ?>">
                                    <?php
                                    echo esc_html(
                                        $item['label'] .
                                        ' - ' .
                                        $item['score']
                                    );
                                    ?>
                                </span>
                            </td>

                            <td>
                                <?php echo esc_html($item['events']); ?>
                            </td>

                            <td>
                                <?php echo esc_html($item['failed_logins']); ?>
                            </td>

                            <td>
                                <?php echo esc_html($item['username_count']); ?>
                            </td>

                            <td>
                                <?php echo esc_html($item['probes']); ?>
                            </td>

                            <td>
                                <?php echo esc_html($item['xmlrpc']); ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    empty($item['reasons'])
                                        ? 'No correlated threat pattern'
                                        : implode(
                                            ', ',
                                            $item['reasons']
                                        )
                                );
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>


        <h3>Outbound Connection Inventory</h3>

        <?php if (empty($data['outbound'])) : ?>

            <div class="sat-log-empty">
                No outbound WordPress HTTP destinations recorded.
            </div>

        <?php else : ?>

            <div class="sat-table-scroll">

                <table class="widefat striped">

                    <thead>
                        <tr>
                            <th>Destination</th>
                            <th>Requests</th>
                            <th>Failures</th>
                            <th>Last Seen</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($data['outbound'] as $host) : ?>

                        <tr>
                            <td>
                                <code>
                                    <?php echo esc_html($host['host']); ?>
                                </code>
                            </td>

                            <td>
                                <?php echo esc_html($host['requests']); ?>
                            </td>

                            <td>
                                <?php echo esc_html($host['failures']); ?>
                            </td>

                            <td>
                                <?php echo esc_html($host['last_seen']); ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>


        <h3>Sensitive Administrative Events</h3>

        <div class="sat-threat-summary-grid">

            <div class="sat-diagnostic-card">
                <span>New User Events</span>

                <strong>
                    <?php
                    echo esc_html(
                        count($data['admin_events'])
                    );
                    ?>
                </strong>

                <p>
                    User creation events recorded during the
                    correlation window.
                </p>
            </div>


            <div class="sat-diagnostic-card">
                <span>Plugin Changes</span>

                <strong>
                    <?php
                    echo esc_html(
                        count($data['plugin_events'])
                    );
                    ?>
                </strong>

                <p>
                    Plugin activation/deactivation events
                    recorded during the correlation window.
                </p>
            </div>

        </div>


        <?php if (sat_feature_enabled('incident_workflow')) : ?>

            <?php
            $incidents = sat_get_incidents(100);
            ?>

            <h3>Incident Workflow</h3>

            <?php if (isset($_GET['sat_incident_updated'])) : ?>
                <div class="notice notice-success inline">
                    <p>Incident updated successfully.</p>
                </div>
            <?php endif; ?>

            <?php if (empty($incidents)) : ?>

                <div class="sat-log-empty">
                    No persistent incidents have been recorded yet.
                </div>

            <?php else : ?>

                <div class="sat-table-scroll">

                    <table class="widefat striped">

                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Source</th>
                                <th>Risk</th>
                                <th>Status</th>
                                <th>Last Seen</th>
                                <th>Investigation</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($incidents as $incident) : ?>

                            <tr>

                                <td>
                                    <?php echo esc_html((string) $incident['id']); ?>
                                </td>

                                <td>
                                    <code>
                                        <?php echo esc_html($incident['source_value']); ?>
                                    </code>
                                </td>

                                <td>
                                    <?php
                                    echo esc_html(
                                        strtoupper($incident['severity']) .
                                        ' - ' .
                                        (string) $incident['risk_score']
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php echo esc_html(ucfirst($incident['status'])); ?>
                                </td>

                                <td>
                                    <?php echo esc_html($incident['last_seen_at']); ?>
                                </td>

                                <td>

                                    <form
                                        method="post"
                                        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="sat_update_incident"
                                        >

                                        <input
                                            type="hidden"
                                            name="incident_id"
                                            value="<?php echo esc_attr((string) $incident['id']); ?>"
                                        >

                                        <?php wp_nonce_field('sat_update_incident'); ?>

                                        <p>
                                            <select name="incident_status">

                                                <option value="open"
                                                    <?php selected($incident['status'], 'open'); ?>>
                                                    Open
                                                </option>

                                                <option value="acknowledged"
                                                    <?php selected($incident['status'], 'acknowledged'); ?>>
                                                    Acknowledged
                                                </option>

                                                <option value="investigating"
                                                    <?php selected($incident['status'], 'investigating'); ?>>
                                                    Investigating
                                                </option>

                                                <option value="resolved"
                                                    <?php selected($incident['status'], 'resolved'); ?>>
                                                    Resolved
                                                </option>

                                            </select>
                                        </p>

                                        <p>
                                            <textarea
                                                name="investigation_notes"
                                                rows="4"
                                                class="large-text"
                                                placeholder="Investigation notes..."
                                            ><?php echo esc_textarea($incident['investigation_notes'] ?? ''); ?></textarea>
                                        </p>

                                        <?php
                                        submit_button(
                                            'Save Incident',
                                            'secondary',
                                            'submit',
                                            false
                                        );
                                        ?>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        <?php endif; ?>

        <h3>Incident Export</h3>

        <p>
            Export the current correlated IP threat view as CSV
            for incident review or external analysis.
        </p>

        <form
            method="post"
            action="<?php
                echo esc_url(
                    admin_url('admin-post.php')
                );
            ?>"
        >

            <input
                type="hidden"
                name="action"
                value="sat_export_incident_report"
            >

            <?php
            wp_nonce_field(
                'sat_export_incident_report'
            );
            ?>

            <?php
            submit_button(
                'Export Incident Report',
                'secondary',
                'submit',
                false
            );
            ?>

        </form>


        <div class="sat-diagnostic-note">

            <strong>Interpretation:</strong>

            These classifications indicate suspicious behavior
            patterns. They do not prove that an IP belongs to an
            attacker.

        </div>

    </div>
    <?php
}



function sat_incident_cron_schedules($schedules)
{
    $schedules['sat_every_15_minutes'] = [
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display'  => 'Every 15 Minutes',
    ];

    return $schedules;
}

add_filter(
    'cron_schedules',
    'sat_incident_cron_schedules'
);

function sat_schedule_incident_sync()
{
    $hook = 'sat_incident_sync_event';
    $next = wp_next_scheduled($hook);

    if (!sat_feature_enabled('incident_workflow')) {
        if ($next) {
            wp_unschedule_event($next, $hook);
        }

        return;
    }

    if (!$next) {
        wp_schedule_event(
            time() + MINUTE_IN_SECONDS,
            'sat_every_15_minutes',
            $hook
        );
    }
}

add_action(
    'init',
    'sat_schedule_incident_sync',
    20
);

function sat_run_incident_sync_cron()
{
    if (!sat_feature_enabled('incident_workflow')) {
        return;
    }

    $lock_name = 'sat_incident_sync_lock';
    $lock_time = (int) get_option($lock_name, 0);
    $now = time();

    if (
        $lock_time > 0 &&
        ($now - $lock_time) < (20 * MINUTE_IN_SECONDS)
    ) {
        return;
    }

    if ($lock_time > 0) {
        delete_option($lock_name);
    }

    if (!add_option($lock_name, $now, '', false)) {
        return;
    }

    try {
        sat_sync_correlated_incidents(24);

        update_option(
            'sat_incident_sync_last_run',
            time(),
            false
        );
    } finally {
        delete_option($lock_name);
    }
}

add_action(
    'sat_incident_sync_event',
    'sat_run_incident_sync_cron'
);

function sat_get_incidents($limit = 100)
{
    global $wpdb;

    $limit = max(1, min(500, absint($limit)));

    return $wpdb->get_results(
        "
        SELECT *
        FROM " . sat_incident_table() . "
        ORDER BY
            CASE status
                WHEN 'open' THEN 1
                WHEN 'acknowledged' THEN 2
                WHEN 'investigating' THEN 3
                WHEN 'resolved' THEN 4
                ELSE 5
            END,
            risk_score DESC,
            updated_at DESC
        LIMIT {$limit}
        ",
        ARRAY_A
    );
}

function sat_handle_incident_update()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer('sat_update_incident');

    if (!sat_feature_enabled('incident_workflow')) {
        wp_die('Premium incident workflow is not available.');
    }

    global $wpdb;

    $incident_id = isset($_POST['incident_id'])
        ? absint($_POST['incident_id'])
        : 0;

    $status = isset($_POST['incident_status'])
        ? sanitize_key(wp_unslash($_POST['incident_status']))
        : '';

    $notes = isset($_POST['investigation_notes'])
        ? sanitize_textarea_field(
            wp_unslash($_POST['investigation_notes'])
        )
        : '';

    $allowed_statuses = [
        'open',
        'acknowledged',
        'investigating',
        'resolved',
    ];

    if (
        !$incident_id ||
        !in_array($status, $allowed_statuses, true)
    ) {
        wp_die('Invalid incident update.');
    }

    $now = current_time('mysql');

    $data = [
        'status' => $status,
        'investigation_notes' => $notes,
        'updated_at' => $now,
    ];

    if ($status === 'acknowledged') {
        $data['acknowledged_by'] = get_current_user_id();
        $data['acknowledged_at'] = $now;
        $data['resolved_at'] = null;
    }

    if ($status === 'investigating') {
        $data['resolved_at'] = null;
    }

    if ($status === 'open') {
        $data['resolved_at'] = null;
    }

    if ($status === 'resolved') {
        $data['resolved_at'] = $now;
    }

    $wpdb->update(
        sat_incident_table(),
        $data,
        [
            'id' => $incident_id,
        ]
    );

    wp_safe_redirect(
        admin_url(
            'admin.php?page=site-admin-toolkit&tab=threats&sat_incident_updated=1'
        )
    );

    exit;
}

add_action(
    'admin_post_sat_update_incident',
    'sat_handle_incident_update'
);

