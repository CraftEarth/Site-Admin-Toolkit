<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get WordPress database health information.
 *
 * Read-only: this module performs no cleanup or modification.
 */
function sat_get_database_health()
{
    global $wpdb;

    $database = DB_NAME;
    $prefix   = $wpdb->prefix;

    /*
     * WordPress tables and sizes.
     */
    $tables = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT
                TABLE_NAME AS table_name,
                ENGINE AS engine,
                TABLE_COLLATION AS collation,
                DATA_LENGTH AS data_length,
                INDEX_LENGTH AS index_length,
                DATA_FREE AS data_free,
                TABLE_ROWS AS table_rows
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = %s
              AND TABLE_NAME LIKE %s
            ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
            ",
            $database,
            $wpdb->esc_like($prefix) . '%'
        ),
        ARRAY_A
    );

    $total_size = 0;
    $total_overhead = 0;

    foreach ($tables as $table) {
        $total_size +=
            (int) $table['data_length'] +
            (int) $table['index_length'];

        $total_overhead +=
            (int) $table['data_free'];
    }

    /*
     * Autoloaded options.
     *
     * Supports traditional and newer WordPress autoload values.
     */
    $autoload_size = (int) $wpdb->get_var(
        "
        SELECT COALESCE(SUM(LENGTH(option_value)), 0)
        FROM {$wpdb->options}
        WHERE autoload IN (
            'yes',
            'on',
            'auto',
            'auto-on'
        )
        "
    );

    $autoload_count = (int) $wpdb->get_var(
        "
        SELECT COUNT(*)
        FROM {$wpdb->options}
        WHERE autoload IN (
            'yes',
            'on',
            'auto',
            'auto-on'
        )
        "
    );

    /*
     * Content cleanup candidates.
     */
    $revision_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = %s
            ",
            'revision'
        )
    );

    $trash_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_status = %s
            ",
            'trash'
        )
    );

    $spam_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT COUNT(*)
            FROM {$wpdb->comments}
            WHERE comment_approved = %s
            ",
            'spam'
        )
    );

    /*
     * Expired transient estimate.
     */
    $timeout_like =
        $wpdb->esc_like(
            '_transient_timeout_'
        ) . '%';

    $expired_transients = (int) $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT COUNT(*)
            FROM {$wpdb->options}
            WHERE option_name LIKE %s
              AND CAST(option_value AS UNSIGNED) < %d
            ",
            $timeout_like,
            time()
        )
    );

    /*
     * Health classifications.
     */
    $checks = [];

    $autoload_status =
        $autoload_size > 1000000
            ? 'warning'
            : 'good';

    $checks[] = [
        'label' => 'Autoloaded Options',
        'value' => size_format($autoload_size),
        'status' => $autoload_status,
        'message' =>
            $autoload_status === 'warning'
                ? 'Autoloaded option data exceeds 1 MB and may deserve review.'
                : 'Autoloaded option data is within the toolkit baseline.',
    ];

    $overhead_status =
        $total_overhead > 10000000
            ? 'warning'
            : 'good';

    $checks[] = [
        'label' => 'Table Overhead',
        'value' => size_format($total_overhead),
        'status' => $overhead_status,
        'message' =>
            $overhead_status === 'warning'
                ? 'Database table overhead is relatively high and should be reviewed before optimization.'
                : 'No unusually large table overhead was detected.',
    ];

    $checks[] = [
        'label' => 'Post Revisions',
        'value' => number_format_i18n(
            $revision_count
        ),
        'status' =>
            $revision_count > 500
                ? 'warning'
                : 'good',
        'message' =>
            $revision_count > 500
                ? 'A large number of revisions exists. Review retention needs before cleanup.'
                : 'Revision count is within the toolkit baseline.',
    ];

    $checks[] = [
        'label' => 'Expired Transients',
        'value' => number_format_i18n(
            $expired_transients
        ),
        'status' =>
            $expired_transients > 100
                ? 'warning'
                : 'good',
        'message' =>
            $expired_transients > 100
                ? 'Many expired transient timeout records were detected.'
                : 'Expired transient count is low.',
    ];

    $warning_count = 0;

    foreach ($checks as $check) {
        if ($check['status'] === 'warning') {
            $warning_count++;
        }
    }

    return [
        'database' =>
            $database,

        'prefix' =>
            $prefix,

        'tables' =>
            $tables,

        'total_size' =>
            $total_size,

        'total_overhead' =>
            $total_overhead,

        'autoload_size' =>
            $autoload_size,

        'autoload_count' =>
            $autoload_count,

        'revision_count' =>
            $revision_count,

        'trash_count' =>
            $trash_count,

        'spam_count' =>
            $spam_count,

        'expired_transients' =>
            $expired_transients,

        'checks' =>
            $checks,

        'overall_status' =>
            $warning_count > 0
                ? 'warning'
                : 'good',

        'overall_label' =>
            $warning_count > 0
                ? 'Needs Attention'
                : 'Good',
    ];
}


/**
 * Render Database Health panel.
 */
function sat_render_database_health()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $data = sat_get_database_health();

    ?>
    <div class="sat-panel sat-database-health">

        <div class="sat-health-heading">

            <div>

                <h2>
                    Database Health & Safe Maintenance
                </h2>

                <p>
                    Inspect WordPress database size,
                    overhead, autoloaded data, and common
                    cleanup candidates without changing anything.
                </p>

            </div>

            <span class="sat-status sat-status-<?php
                echo esc_attr(
                    $data['overall_status']
                );
            ?>">
                <?php
                echo esc_html(
                    $data['overall_label']
                );
                ?>
            </span>

        </div>


        <div class="sat-health-grid">

            <?php foreach ($data['checks'] as $check) : ?>

                <div class="sat-health-card">

                    <div class="sat-health-card-top">

                        <strong>
                            <?php
                            echo esc_html(
                                $check['label']
                            );
                            ?>
                        </strong>

                        <span class="sat-status sat-status-<?php
                            echo esc_attr(
                                $check['status']
                            );
                        ?>">
                            <?php
                            echo esc_html(
                                ucfirst(
                                    $check['status']
                                )
                            );
                            ?>
                        </span>

                    </div>

                    <div class="sat-health-value">
                        <?php
                        echo esc_html(
                            $check['value']
                        );
                        ?>
                    </div>

                    <p>
                        <?php
                        echo esc_html(
                            $check['message']
                        );
                        ?>
                    </p>

                </div>

            <?php endforeach; ?>

        </div>


        <h3>
            Database Summary
        </h3>

        <table class="widefat striped">

            <tbody>

                <tr>
                    <td><strong>Database</strong></td>
                    <td>
                        <?php
                        echo esc_html(
                            $data['database']
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Table Prefix</strong></td>
                    <td>
                        <code>
                            <?php
                            echo esc_html(
                                $data['prefix']
                            );
                            ?>
                        </code>
                    </td>
                </tr>

                <tr>
                    <td><strong>Total WordPress Table Size</strong></td>
                    <td>
                        <?php
                        echo esc_html(
                            size_format(
                                $data['total_size']
                            )
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Total Table Overhead</strong></td>
                    <td>
                        <?php
                        echo esc_html(
                            size_format(
                                $data['total_overhead']
                            )
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Autoloaded Options</strong></td>
                    <td>
                        <?php
                        echo esc_html(
                            number_format_i18n(
                                $data[
                                    'autoload_count'
                                ]
                            )
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Autoloaded Data Size</strong></td>
                    <td>
                        <?php
                        echo esc_html(
                            size_format(
                                $data[
                                    'autoload_size'
                                ]
                            )
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Post Revisions</strong></td>
                    <td>
                        <?php
                        echo esc_html(
                            number_format_i18n(
                                $data[
                                    'revision_count'
                                ]
                            )
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Trashed Posts</strong></td>
                    <td>
                        <?php
                        echo esc_html(
                            number_format_i18n(
                                $data[
                                    'trash_count'
                                ]
                            )
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Spam Comments</strong></td>
                    <td>
                        <?php
                        echo esc_html(
                            number_format_i18n(
                                $data[
                                    'spam_count'
                                ]
                            )
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td><strong>Expired Transient Timeouts</strong></td>
                    <td>
                        <?php
                        echo esc_html(
                            number_format_i18n(
                                $data[
                                    'expired_transients'
                                ]
                            )
                        );
                        ?>
                    </td>
                </tr>

            </tbody>

        </table>


        <h3>
            Largest WordPress Tables
        </h3>

        <div class="sat-table-scroll">

            <table class="widefat striped">

                <thead>

                    <tr>
                        <th>Table</th>
                        <th>Engine</th>
                        <th>Rows</th>
                        <th>Size</th>
                        <th>Overhead</th>
                        <th>Collation</th>
                    </tr>

                </thead>

                <tbody>

                    <?php
                    foreach (
                        array_slice(
                            $data['tables'],
                            0,
                            10
                        )
                        as $table
                    ) :

                        $table_size =
                            (int) $table['data_length'] +
                            (int) $table['index_length'];

                    ?>

                        <tr>

                            <td>
                                <code>
                                    <?php
                                    echo esc_html(
                                        $table[
                                            'table_name'
                                        ]
                                    );
                                    ?>
                                </code>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $table['engine']
                                    ?: 'N/A'
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    number_format_i18n(
                                        (int)
                                        $table[
                                            'table_rows'
                                        ]
                                    )
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    size_format(
                                        $table_size
                                    )
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    size_format(
                                        (int)
                                        $table[
                                            'data_free'
                                        ]
                                    )
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $table[
                                        'collation'
                                    ]
                                    ?: 'N/A'
                                );
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>


        <div class="sat-diagnostic-note">

            <strong>
                Safe mode:
            </strong>

            This version only inspects the database.

            It does not delete revisions, remove transients,
            optimize tables, or modify WordPress data.

            Always create and verify a backup before performing
            database cleanup or optimization.

        </div>

    </div>
    <?php
}
