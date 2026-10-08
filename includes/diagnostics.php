<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read the last lines of a file without loading the entire file into memory.
 */
function sat_tail_file($file, $lines = 50)
{
    if (!is_readable($file)) {
        return [];
    }

    $handle = fopen($file, 'rb');

    if (!$handle) {
        return [];
    }

    $buffer = '';
    $chunk_size = 4096;

    fseek($handle, 0, SEEK_END);
    $position = ftell($handle);

    while ($position > 0) {
        $read_size = min($chunk_size, $position);
        $position -= $read_size;

        fseek($handle, $position);

        $buffer =
            fread($handle, $read_size) .
            $buffer;

        if (substr_count($buffer, "\n") > $lines) {
            break;
        }
    }

    fclose($handle);

    $rows = preg_split(
        '/\r\n|\r|\n/',
        trim($buffer)
    );

    if (!$rows) {
        return [];
    }

    return array_slice(
        $rows,
        -$lines
    );
}


/**
 * Get diagnostic information.
 */
function sat_get_diagnostics()
{
    $debug_log_file =
        WP_CONTENT_DIR .
        DIRECTORY_SEPARATOR .
        'debug.log';

    $debug_log_exists =
        file_exists($debug_log_file);

    $debug_log_readable =
        $debug_log_exists &&
        is_readable($debug_log_file);

    $debug_log_size =
        $debug_log_exists
            ? filesize($debug_log_file)
            : 0;

    $debug_log_modified =
        $debug_log_exists
            ? filemtime($debug_log_file)
            : false;

    $wp_debug =
        defined('WP_DEBUG') &&
        WP_DEBUG;

    $wp_debug_log =
        defined('WP_DEBUG_LOG')
            ? WP_DEBUG_LOG
            : false;

    $wp_debug_display =
        defined('WP_DEBUG_DISPLAY')
            ? WP_DEBUG_DISPLAY
            : null;

    $display_errors =
        ini_get('display_errors');

    $php_error_log =
        ini_get('error_log');

    return [
        'wp_debug' =>
            $wp_debug,

        'wp_debug_log' =>
            $wp_debug_log,

        'wp_debug_display' =>
            $wp_debug_display,

        'display_errors' =>
            $display_errors,

        'php_error_log' =>
            $php_error_log,

        'debug_log_file' =>
            $debug_log_file,

        'debug_log_exists' =>
            $debug_log_exists,

        'debug_log_readable' =>
            $debug_log_readable,

        'debug_log_size' =>
            $debug_log_size,

        'debug_log_modified' =>
            $debug_log_modified,

        'recent_lines' =>
            $debug_log_readable
                ? sat_tail_file(
                    $debug_log_file,
                    50
                )
                : [],
    ];
}


/**
 * Convert boolean-like values into readable text.
 */
function sat_diag_status_text($value)
{
    return $value
        ? 'Enabled'
        : 'Disabled';
}


/**
 * Render diagnostic panel.
 */
function sat_render_diagnostics()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $diag =
        sat_get_diagnostics();

    ?>
    <div class="sat-panel sat-diagnostics">

        <div class="sat-diagnostics-heading">

            <div>

                <h2>
                    Error Log & Diagnostics
                </h2>

                <p>
                    Inspect WordPress and PHP debugging
                    configuration and recent WordPress log entries.
                </p>

            </div>

            <?php if ($diag['debug_log_exists']) : ?>

                <span class="sat-status sat-status-good">
                    Log Detected
                </span>

            <?php else : ?>

                <span class="sat-status sat-status-warning">
                    No debug.log
                </span>

            <?php endif; ?>

        </div>


        <div class="sat-diagnostic-grid">

            <div class="sat-diagnostic-card">

                <span>
                    WP_DEBUG
                </span>

                <strong>
                    <?php
                    echo esc_html(
                        sat_diag_status_text(
                            $diag['wp_debug']
                        )
                    );
                    ?>
                </strong>

            </div>


            <div class="sat-diagnostic-card">

                <span>
                    WP_DEBUG_LOG
                </span>

                <strong>
                    <?php
                    echo esc_html(
                        sat_diag_status_text(
                            !empty(
                                $diag['wp_debug_log']
                            )
                        )
                    );
                    ?>
                </strong>

            </div>


            <div class="sat-diagnostic-card">

                <span>
                    WP_DEBUG_DISPLAY
                </span>

                <strong>
                    <?php

                    if (
                        $diag['wp_debug_display']
                        === null
                    ) {
                        echo 'Default';
                    } else {
                        echo esc_html(
                            sat_diag_status_text(
                                $diag[
                                    'wp_debug_display'
                                ]
                            )
                        );
                    }

                    ?>
                </strong>

            </div>


            <div class="sat-diagnostic-card">

                <span>
                    PHP display_errors
                </span>

                <strong>
                    <?php
                    echo esc_html(
                        $diag['display_errors']
                            ? $diag['display_errors']
                            : 'Off'
                    );
                    ?>
                </strong>

            </div>

        </div>


        <h3>
            Debug Log Information
        </h3>

        <table class="widefat striped">

            <tbody>

                <tr>

                    <td>
                        <strong>
                            WordPress Debug Log
                        </strong>
                    </td>

                    <td>
                        <?php
                        echo esc_html(
                            $diag[
                                'debug_log_exists'
                            ]
                                ? 'Detected'
                                : 'Not Found'
                        );
                        ?>
                    </td>

                </tr>


                <tr>

                    <td>
                        <strong>
                            Log Readable
                        </strong>
                    </td>

                    <td>
                        <?php
                        echo esc_html(
                            $diag[
                                'debug_log_readable'
                            ]
                                ? 'Yes'
                                : 'No'
                        );
                        ?>
                    </td>

                </tr>


                <tr>

                    <td>
                        <strong>
                            Log Size
                        </strong>
                    </td>

                    <td>
                        <?php
                        echo esc_html(
                            size_format(
                                $diag[
                                    'debug_log_size'
                                ]
                            )
                        );
                        ?>
                    </td>

                </tr>


                <tr>

                    <td>
                        <strong>
                            Last Modified
                        </strong>
                    </td>

                    <td>
                        <?php

                        if (
                            $diag[
                                'debug_log_modified'
                            ]
                        ) {

                            echo esc_html(
                                wp_date(
                                    'Y-m-d H:i:s',
                                    $diag[
                                        'debug_log_modified'
                                    ]
                                )
                            );

                        } else {

                            echo 'N/A';

                        }

                        ?>
                    </td>

                </tr>


                <tr>

                    <td>
                        <strong>
                            PHP Error Log
                        </strong>
                    </td>

                    <td>
                        <?php
                        echo esc_html(
                            $diag[
                                'php_error_log'
                            ]
                                ? $diag[
                                    'php_error_log'
                                ]
                                : 'Not configured'
                        );
                        ?>
                    </td>

                </tr>

            </tbody>

        </table>


        <div class="sat-log-section">

            <h3>
                Recent WordPress Debug Log
            </h3>

            <p class="description">
                Displays the most recent 50 lines from
                wp-content/debug.log when available.
            </p>

            <?php
            if (
                !$diag['debug_log_exists']
            ) :
            ?>

                <div class="sat-log-empty">

                    No WordPress debug.log file
                    currently exists.

                </div>

            <?php
            elseif (
                !$diag['debug_log_readable']
            ) :
            ?>

                <div class="sat-log-empty">

                    The WordPress debug.log file
                    exists but cannot be read.

                </div>

            <?php
            elseif (
                empty(
                    $diag['recent_lines']
                )
            ) :
            ?>

                <div class="sat-log-empty">

                    The debug log is empty.

                </div>

            <?php
            else :
            ?>

                <pre class="sat-log-viewer"><?php

                    foreach (
                        $diag['recent_lines']
                        as $line
                    ) {

                        echo esc_html($line) .
                            "\n";

                    }

                ?></pre>

            <?php endif; ?>

        </div>


        <div class="sat-diagnostic-note">

            <strong>
                Security note:
            </strong>

            Debug logs can contain file paths,
            database errors, email addresses,
            request details, and other sensitive
            information.

            Do not expose this screen to
            non-administrators.

        </div>

    </div>
    <?php
}
