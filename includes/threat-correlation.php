<?php

if (!defined('ABSPATH')) {
    exit;
}


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
