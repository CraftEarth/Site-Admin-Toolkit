<?php

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Network analytics for the activity database.
 */
function sat_network_analytics($hours = 24)
{
    global $wpdb;

    $hours = max(
        1,
        min(
            168,
            absint($hours)
        )
    );

    $table =
        sat_activity_table();


    $top_ips =
        $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    ip_address,
                    COUNT(*) AS requests,
                    SUM(risk_score) AS risk
                FROM {$table}
                WHERE
                    created_at >= DATE_SUB(
                        NOW(),
                        INTERVAL %d HOUR
                    )
                    AND ip_address <> ''
                GROUP BY ip_address
                ORDER BY requests DESC
                LIMIT 20
                ",
                $hours
            ),
            ARRAY_A
        );


    $methods =
        $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    method,
                    COUNT(*) AS total
                FROM {$table}
                WHERE
                    created_at >= DATE_SUB(
                        NOW(),
                        INTERVAL %d HOUR
                    )
                    AND method <> ''
                GROUP BY method
                ORDER BY total DESC
                ",
                $hours
            ),
            ARRAY_A
        );


    $endpoints =
        $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    endpoint,
                    COUNT(*) AS total
                FROM {$table}
                WHERE
                    created_at >= DATE_SUB(
                        NOW(),
                        INTERVAL %d HOUR
                    )
                    AND endpoint <> ''
                    AND direction = 'inbound'
                GROUP BY endpoint
                ORDER BY total DESC
                LIMIT 25
                ",
                $hours
            ),
            ARRAY_A
        );


    $probes =
        $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    endpoint,
                    COUNT(*) AS total
                FROM {$table}
                WHERE
                    created_at >= DATE_SUB(
                        NOW(),
                        INTERVAL %d HOUR
                    )
                    AND event_type = '404_probe'
                GROUP BY endpoint
                ORDER BY total DESC
                LIMIT 25
                ",
                $hours
            ),
            ARRAY_A
        );


    $response_codes =
        $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    status_code,
                    COUNT(*) AS total
                FROM {$table}
                WHERE
                    created_at >= DATE_SUB(
                        NOW(),
                        INTERVAL %d HOUR
                    )
                    AND status_code IS NOT NULL
                GROUP BY status_code
                ORDER BY total DESC
                ",
                $hours
            ),
            ARRAY_A
        );


    $slow_requests =
        $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    created_at,
                    method,
                    endpoint,
                    ip_address,
                    duration_ms
                FROM {$table}
                WHERE
                    created_at >= DATE_SUB(
                        NOW(),
                        INTERVAL %d HOUR
                    )
                    AND direction = 'inbound'
                    AND duration_ms IS NOT NULL
                    AND duration_ms >= 500
                ORDER BY duration_ms DESC
                LIMIT 25
                ",
                $hours
            ),
            ARRAY_A
        );


    $rest_count =
        (int)
        $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COUNT(*)
                FROM {$table}
                WHERE
                    created_at >= DATE_SUB(
                        NOW(),
                        INTERVAL %d HOUR
                    )
                    AND event_type = 'rest_request'
                ",
                $hours
            )
        );


    $inbound_count =
        (int)
        $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COUNT(*)
                FROM {$table}
                WHERE
                    created_at >= DATE_SUB(
                        NOW(),
                        INTERVAL %d HOUR
                    )
                    AND direction = 'inbound'
                ",
                $hours
            )
        );


    $outbound_count =
        (int)
        $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COUNT(*)
                FROM {$table}
                WHERE
                    created_at >= DATE_SUB(
                        NOW(),
                        INTERVAL %d HOUR
                    )
                    AND direction = 'outbound'
                ",
                $hours
            )
        );


    return [
        'top_ips' =>
            $top_ips,

        'methods' =>
            $methods,

        'endpoints' =>
            $endpoints,

        'probes' =>
            $probes,

        'response_codes' =>
            $response_codes,

        'slow_requests' =>
            $slow_requests,

        'rest_count' =>
            $rest_count,

        'inbound_count' =>
            $inbound_count,

        'outbound_count' =>
            $outbound_count,
    ];
}


/**
 * Return observed outbound hosts.
 */
function sat_get_observed_outbound_domains()
{
    global $wpdb;

    $rows =
        $wpdb->get_results(
            "
            SELECT
                endpoint,
                COUNT(*) AS total,
                MAX(created_at) AS last_seen
            FROM " .
            sat_activity_table() .
            "
            WHERE
                direction = 'outbound'
                AND endpoint <> ''
            GROUP BY endpoint
            ORDER BY total DESC
            ",
            ARRAY_A
        );

    $domains = [];

    foreach ($rows as $row) {

        $host =
            wp_parse_url(
                $row['endpoint'],
                PHP_URL_HOST
            );

        if (!$host) {
            continue;
        }

        $host =
            strtolower($host);

        if (!isset($domains[$host])) {

            $domains[$host] = [
                'host' => $host,
                'requests' => 0,
                'last_seen' =>
                    $row['last_seen'],
            ];
        }

        $domains[$host]['requests'] +=
            (int)
            $row['total'];

        if (
            strtotime($row['last_seen'])
            >
            strtotime(
                $domains[$host]['last_seen']
            )
        ) {
            $domains[$host]['last_seen'] =
                $row['last_seen'];
        }
    }

    uasort(
        $domains,
        function ($a, $b) {
            return
                $b['requests']
                <=>
                $a['requests'];
        }
    );

    return array_values(
        $domains
    );
}


/**
 * Save current outbound domains as trusted baseline.
 */
function sat_create_outbound_baseline()
{
    if (
        !current_user_can(
            'manage_options'
        )
    ) {
        wp_die(
            'Insufficient permissions.'
        );
    }

    check_admin_referer(
        'sat_create_outbound_baseline'
    );

    $observed =
        sat_get_observed_outbound_domains();

    $hosts =
        array_map(
            function ($item) {
                return $item['host'];
            },
            $observed
        );

    update_option(
        'sat_outbound_domain_baseline',
        [
            'created_at' =>
                current_time('mysql'),

            'hosts' =>
                array_values(
                    array_unique($hosts)
                ),
        ],
        false
    );

    wp_safe_redirect(
        admin_url(
            'admin.php?page=site-admin-toolkit&tab=network&sat_network=baseline'
        )
    );

    exit;
}

add_action(
    'admin_post_sat_create_outbound_baseline',
    'sat_create_outbound_baseline'
);


/**
 * Compare outbound traffic to baseline.
 */
function sat_get_new_outbound_domains()
{
    $baseline =
        get_option(
            'sat_outbound_domain_baseline',
            []
        );

    $observed =
        sat_get_observed_outbound_domains();

    if (
        empty($baseline['hosts'])
    ) {
        return [
            'has_baseline' => false,
            'baseline' => $baseline,
            'new' => [],
            'observed' => $observed,
        ];
    }

    $known =
        array_flip(
            array_map(
                'strtolower',
                $baseline['hosts']
            )
        );

    $new = [];

    foreach ($observed as $domain) {

        if (
            !isset(
                $known[
                    strtolower(
                        $domain['host']
                    )
                ]
            )
        ) {
            $new[] = $domain;
        }
    }

    return [
        'has_baseline' => true,
        'baseline' => $baseline,
        'new' => $new,
        'observed' => $observed,
    ];
}


/**
 * Export network analytics.
 */
function sat_export_network_report()
{
    if (
        !current_user_can(
            'manage_options'
        )
    ) {
        wp_die(
            'Insufficient permissions.'
        );
    }

    check_admin_referer(
        'sat_export_network_report'
    );

    $data =
        sat_network_analytics(24);

    nocache_headers();

    header(
        'Content-Type: text/csv; charset=utf-8'
    );

    header(
        'Content-Disposition: attachment; filename=site-admin-toolkit-network-report.csv'
    );

    $out =
        fopen(
            'php://output',
            'w'
        );

    fputcsv(
        $out,
        [
            'IP Address',
            'Requests',
            'Accumulated Event Risk',
        ]
    );

    foreach (
        $data['top_ips']
        as $row
    ) {

        fputcsv(
            $out,
            [
                $row['ip_address'],
                $row['requests'],
                $row['risk'],
            ]
        );
    }

    fclose($out);
    exit;
}

add_action(
    'admin_post_sat_export_network_report',
    'sat_export_network_report'
);


/**
 * Render Network tab.
 */
function sat_render_network_analytics()
{
    if (
        !current_user_can(
            'manage_options'
        )
    ) {
        return;
    }

    $data =
        sat_network_analytics(24);

    $domains =
        sat_get_new_outbound_domains();

    ?>
    <div class="sat-panel">

        <div class="sat-health-heading">

            <div>

                <h2>
                    Network & Request Analytics
                </h2>

                <p>
                    Analyze WordPress-level inbound and outbound
                    activity recorded during the previous 24 hours.
                </p>

            </div>

            <span class="sat-status sat-status-good">
                24 Hour Window
            </span>

        </div>


        <div class="sat-grid">

            <div class="sat-card">
                <span>
                    Inbound Events
                </span>

                <strong>
                    <?php
                    echo esc_html(
                        number_format_i18n(
                            $data[
                                'inbound_count'
                            ]
                        )
                    );
                    ?>
                </strong>
            </div>


            <div class="sat-card">
                <span>
                    Outbound Events
                </span>

                <strong>
                    <?php
                    echo esc_html(
                        number_format_i18n(
                            $data[
                                'outbound_count'
                            ]
                        )
                    );
                    ?>
                </strong>
            </div>


            <div class="sat-card">
                <span>
                    REST Requests
                </span>

                <strong>
                    <?php
                    echo esc_html(
                        number_format_i18n(
                            $data[
                                'rest_count'
                            ]
                        )
                    );
                    ?>
                </strong>
            </div>


            <div class="sat-card">
                <span>
                    New Outbound Domains
                </span>

                <strong>
                    <?php
                    echo esc_html(
                        $domains['has_baseline']
                            ? count(
                                $domains['new']
                            )
                            : 'N/A'
                    );
                    ?>
                </strong>
            </div>

        </div>


        <h3>
            Top Source IPs
        </h3>

        <div class="sat-table-scroll">

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>IP Address</th>
                        <th>Events</th>
                        <th>Risk</th>
                    </tr>
                </thead>

                <tbody>

                <?php
                if (
                    empty(
                        $data['top_ips']
                    )
                ) :
                ?>

                    <tr>
                        <td colspan="3">
                            No source IP activity recorded.
                        </td>
                    </tr>

                <?php
                else :

                    foreach (
                        $data['top_ips']
                        as $row
                    ) :
                ?>

                    <tr>

                        <td>
                            <code>
                                <?php
                                echo esc_html(
                                    $row[
                                        'ip_address'
                                    ]
                                );
                                ?>
                            </code>
                        </td>

                        <td>
                            <?php
                            echo esc_html(
                                $row[
                                    'requests'
                                ]
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo esc_html(
                                $row['risk']
                            );
                            ?>
                        </td>

                    </tr>

                <?php
                    endforeach;
                endif;
                ?>

                </tbody>

            </table>

        </div>


        <h3>
            Request Methods
        </h3>

        <div class="sat-inline-stats">

            <?php
            foreach (
                $data['methods']
                as $method
            ) :
            ?>

                <div>

                    <strong>
                        <?php
                        echo esc_html(
                            $method['method']
                        );
                        ?>
                    </strong>

                    <span>
                        <?php
                        echo esc_html(
                            $method['total']
                        );
                        ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>


        <h3>
            Most Requested Endpoints
        </h3>

        <div class="sat-table-scroll">

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Requests</th>
                    </tr>
                </thead>

                <tbody>

                <?php
                foreach (
                    $data['endpoints']
                    as $row
                ) :
                ?>

                    <tr>

                        <td>
                            <code>
                                <?php
                                echo esc_html(
                                    $row['endpoint']
                                );
                                ?>
                            </code>
                        </td>

                        <td>
                            <?php
                            echo esc_html(
                                $row['total']
                            );
                            ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>


        <h3>
            Reconnaissance Targets
        </h3>

        <div class="sat-table-scroll">

            <table class="widefat striped">

                <thead>
                    <tr>
                        <th>404 Target</th>
                        <th>Hits</th>
                    </tr>
                </thead>

                <tbody>

                <?php
                if (
                    empty(
                        $data['probes']
                    )
                ) :
                ?>

                    <tr>
                        <td colspan="2">
                            No 404 reconnaissance recorded.
                        </td>
                    </tr>

                <?php
                else :

                    foreach (
                        $data['probes']
                        as $row
                    ) :
                ?>

                    <tr>

                        <td>
                            <code>
                                <?php
                                echo esc_html(
                                    $row['endpoint']
                                );
                                ?>
                            </code>
                        </td>

                        <td>
                            <?php
                            echo esc_html(
                                $row['total']
                            );
                            ?>
                        </td>

                    </tr>

                <?php
                    endforeach;
                endif;
                ?>

                </tbody>

            </table>

        </div>


        <h3>
            HTTP Response Codes
        </h3>

        <div class="sat-inline-stats">

            <?php
            foreach (
                $data['response_codes']
                as $row
            ) :
            ?>

                <div>

                    <strong>
                        <?php
                        echo esc_html(
                            $row[
                                'status_code'
                            ]
                        );
                        ?>
                    </strong>

                    <span>
                        <?php
                        echo esc_html(
                            $row['total']
                        );
                        ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>


        <h3>
            Slow Requests
        </h3>

        <?php
        if (
            empty(
                $data['slow_requests']
            )
        ) :
        ?>

            <div class="sat-log-empty">
                No recorded requests exceeded 500 ms.
            </div>

        <?php else : ?>

            <div class="sat-table-scroll">

                <table class="widefat striped">

                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Method</th>
                            <th>Endpoint</th>
                            <th>IP</th>
                            <th>Duration</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php
                    foreach (
                        $data[
                            'slow_requests'
                        ]
                        as $row
                    ) :
                    ?>

                        <tr>

                            <td>
                                <?php
                                echo esc_html(
                                    $row[
                                        'created_at'
                                    ]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $row['method']
                                );
                                ?>
                            </td>

                            <td>
                                <code>
                                    <?php
                                    echo esc_html(
                                        $row[
                                            'endpoint'
                                        ]
                                    );
                                    ?>
                                </code>
                            </td>

                            <td>
                                <code>
                                    <?php
                                    echo esc_html(
                                        $row[
                                            'ip_address'
                                        ]
                                    );
                                    ?>
                                </code>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $row[
                                        'duration_ms'
                                    ]
                                );
                                ?>
                                ms
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>


        <h3>
            Outbound Domain Baseline
        </h3>

        <?php
        if (
            !$domains[
                'has_baseline'
            ]
        ) :
        ?>

            <div class="sat-diagnostic-note">

                <strong>
                    No outbound baseline exists.
                </strong>

                Create a baseline after reviewing the currently
                observed external destinations.

            </div>

        <?php else : ?>

            <p>
                Baseline created:
                <strong>
                    <?php
                    echo esc_html(
                        $domains[
                            'baseline'
                        ]['created_at']
                    );
                    ?>
                </strong>
            </p>

            <?php
            if (
                empty(
                    $domains['new']
                )
            ) :
            ?>

                <div class="sat-log-empty">
                    No new outbound destinations detected.
                </div>

            <?php else : ?>

                <table class="widefat striped">

                    <thead>
                        <tr>
                            <th>New Destination</th>
                            <th>Requests</th>
                            <th>Last Seen</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php
                    foreach (
                        $domains['new']
                        as $domain
                    ) :
                    ?>

                        <tr>

                            <td>
                                <code>
                                    <?php
                                    echo esc_html(
                                        $domain['host']
                                    );
                                    ?>
                                </code>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $domain[
                                        'requests'
                                    ]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $domain[
                                        'last_seen'
                                    ]
                                );
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        <?php endif; ?>


        <div class="sat-network-actions">

            <form
                method="post"
                action="<?php
                    echo esc_url(
                        admin_url(
                            'admin-post.php'
                        )
                    );
                ?>"
            >

                <input
                    type="hidden"
                    name="action"
                    value="sat_create_outbound_baseline"
                >

                <?php
                wp_nonce_field(
                    'sat_create_outbound_baseline'
                );
                ?>

                <?php
                submit_button(
                    'Create / Rebuild Outbound Baseline',
                    'secondary',
                    'submit',
                    false
                );
                ?>

            </form>


            <form
                method="post"
                action="<?php
                    echo esc_url(
                        admin_url(
                            'admin-post.php'
                        )
                    );
                ?>"
            >

                <input
                    type="hidden"
                    name="action"
                    value="sat_export_network_report"
                >

                <?php
                wp_nonce_field(
                    'sat_export_network_report'
                );
                ?>

                <?php
                submit_button(
                    'Export Network Report',
                    'secondary',
                    'submit',
                    false
                );
                ?>

            </form>

        </div>

    </div>
    <?php
}
