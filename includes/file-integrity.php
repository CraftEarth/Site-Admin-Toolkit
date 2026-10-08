<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * File integrity settings.
 */
function sat_integrity_baseline_option()
{
    return 'sat_file_integrity_baseline';
}


/**
 * Determine whether a file should be scanned.
 */
function sat_integrity_should_scan_file($path)
{
    $extension =
        strtolower(
            pathinfo(
                $path,
                PATHINFO_EXTENSION
            )
        );

    $allowed = [
        'php',
        'js',
        'css',
        'json',
        'xml',
        'htaccess',
        'txt',
    ];

    if (
        basename($path) === '.htaccess'
    ) {
        return true;
    }

    return in_array(
        $extension,
        $allowed,
        true
    );
}


/**
 * Determine whether a path should be ignored.
 */
function sat_integrity_ignore_path($path)
{
    $normalized =
        wp_normalize_path($path);

    $ignore_parts = [
        '/cache/',
        '/upgrade/',
        '/backups/',
        '/backup/',
        '/node_modules/',
        '/vendor/',
    ];

    foreach ($ignore_parts as $part) {
        if (
            strpos(
                strtolower($normalized),
                $part
            ) !== false
        ) {
            return true;
        }
    }

    return false;
}


/**
 * Scan WordPress files and calculate hashes.
 */
function sat_integrity_scan($max_files = 25000)
{
    $roots = [
        ABSPATH,
    ];

    $files = [];
    $count = 0;
    $limited = false;

    foreach ($roots as $root) {

        if (!is_dir($root)) {
            continue;
        }

        try {

            $iterator =
                new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $root,
                        FilesystemIterator::SKIP_DOTS
                    )
                );

            foreach ($iterator as $file) {

                if ($count >= $max_files) {
                    $limited = true;
                    break 2;
                }

                if (!$file->isFile()) {
                    continue;
                }

                $path =
                    $file->getPathname();

                if (
                    sat_integrity_ignore_path(
                        $path
                    )
                ) {
                    continue;
                }

                if (
                    !sat_integrity_should_scan_file(
                        $path
                    )
                ) {
                    continue;
                }

                $normalized =
                    wp_normalize_path(
                        $path
                    );

                $relative =
                    ltrim(
                        str_replace(
                            wp_normalize_path(
                                ABSPATH
                            ),
                            '',
                            $normalized
                        ),
                        '/'
                    );

                if (!is_readable($path)) {
                    continue;
                }

                $hash =
                    hash_file(
                        'sha256',
                        $path
                    );

                if (!$hash) {
                    continue;
                }

                $files[$relative] = [
                    'hash' => $hash,
                    'size' => $file->getSize(),
                    'mtime' => $file->getMTime(),
                ];

                $count++;
            }

        } catch (Exception $e) {
            $limited = true;
        }
    }

    return [
        'files' => $files,
        'count' => $count,
        'limited' => $limited,
    ];
}


/**
 * Create file-integrity baseline.
 */
function sat_integrity_create_baseline()
{
    if (
        !current_user_can(
            'manage_options'
        )
    ) {
        wp_die('Insufficient permissions.');
    }

    check_admin_referer(
        'sat_integrity_create_baseline'
    );

    $scan =
        sat_integrity_scan();

    $baseline = [
        'created_at' =>
            current_time('mysql'),

        'created_by' =>
            get_current_user_id(),

        'file_count' =>
            $scan['count'],

        'limited' =>
            $scan['limited'],

        'files' =>
            $scan['files'],
    ];

    update_option(
        sat_integrity_baseline_option(),
        $baseline,
        false
    );

    wp_safe_redirect(
        admin_url(
            'admin.php?page=site-admin-toolkit&tab=files&sat_integrity=baseline_created'
        )
    );

    exit;
}

add_action(
    'admin_post_sat_integrity_create_baseline',
    'sat_integrity_create_baseline'
);


/**
 * Compare current files against baseline.
 */
function sat_integrity_compare()
{
    $baseline =
        get_option(
            sat_integrity_baseline_option(),
            []
        );

    if (
        empty($baseline) ||
        empty($baseline['files'])
    ) {
        return [
            'has_baseline' => false,
            'baseline' => [],
            'new' => [],
            'modified' => [],
            'deleted' => [],
            'suspicious' => [],
            'current_count' => 0,
        ];
    }

    $scan =
        sat_integrity_scan();

    $current =
        $scan['files'];

    $old =
        $baseline['files'];

    $new = [];
    $modified = [];
    $deleted = [];
    $suspicious = [];

    foreach (
        $current
        as $path => $info
    ) {

        if (!isset($old[$path])) {

            $new[$path] = $info;

        } elseif (
            $old[$path]['hash']
            !==
            $info['hash']
        ) {

            $modified[$path] = [
                'old' => $old[$path],
                'new' => $info,
            ];
        }


        /*
         * High-risk executable files in uploads.
         */
        $lower =
            strtolower($path);

        if (
            strpos(
                $lower,
                'wp-content/uploads/'
            ) === 0
            &&
            preg_match(
                '/\.(php|phtml|phar|php\d)$/i',
                $path
            )
        ) {

            $suspicious[$path] = [
                'reason' =>
                    'Executable PHP-like file located inside uploads.',
                'risk' => 'critical',
            ];
        }
    }


    foreach (
        $old
        as $path => $info
    ) {

        if (!isset($current[$path])) {
            $deleted[$path] = $info;
        }
    }


    return [
        'has_baseline' => true,
        'baseline' => $baseline,
        'new' => $new,
        'modified' => $modified,
        'deleted' => $deleted,
        'suspicious' => $suspicious,
        'current_count' => $scan['count'],
        'limited' => $scan['limited'],
    ];
}


/**
 * Permissions helper.
 */
function sat_file_permissions($path)
{
    if (!file_exists($path)) {
        return 'N/A';
    }

    $perms =
        fileperms($path);

    return substr(
        sprintf(
            '%o',
            $perms
        ),
        -4
    );
}


/**
 * Important filesystem checks.
 */
function sat_integrity_filesystem_checks()
{
    $uploads =
        wp_upload_dir();

    $paths = [
        'WordPress Root' =>
            ABSPATH,

        'wp-content' =>
            WP_CONTENT_DIR,

        'Plugins' =>
            WP_PLUGIN_DIR,

        'Uploads' =>
            $uploads['basedir'],

        'wp-config.php' =>
            ABSPATH . 'wp-config.php',
    ];

    $results = [];

    foreach (
        $paths
        as $label => $path
    ) {

        $exists =
            file_exists($path);

        $writable =
            $exists
            &&
            is_writable($path);

        $results[] = [
            'label' => $label,
            'path' => wp_normalize_path($path),
            'exists' => $exists,
            'writable' => $writable,
            'permissions' =>
                $exists
                    ? sat_file_permissions($path)
                    : 'N/A',
        ];
    }

    return $results;
}


/**
 * Render forensic file table.
 */
function sat_render_integrity_rows(
    $items,
    $type
) {
    if (empty($items)) {
        ?>
        <tr>
            <td colspan="4">
                None detected.
            </td>
        </tr>
        <?php
        return;
    }

    foreach ($items as $path => $info) {

        $size = '';

        if ($type === 'modified') {
            $size =
                size_format(
                    $info['new']['size']
                );
        } elseif (
            isset($info['size'])
        ) {
            $size =
                size_format(
                    $info['size']
                );
        }

        ?>
        <tr>

            <td>
                <code>
                    <?php
                    echo esc_html($path);
                    ?>
                </code>
            </td>

            <td>
                <?php
                echo esc_html(
                    ucfirst($type)
                );
                ?>
            </td>

            <td>
                <?php
                echo esc_html($size);
                ?>
            </td>

            <td>
                <?php

                if ($type === 'modified') {

                    echo esc_html(
                        wp_date(
                            'Y-m-d H:i:s',
                            $info['new']['mtime']
                        )
                    );

                } elseif (
                    isset($info['mtime'])
                ) {

                    echo esc_html(
                        wp_date(
                            'Y-m-d H:i:s',
                            $info['mtime']
                        )
                    );
                }

                ?>
            </td>

        </tr>
        <?php
    }
}


/**
 * Render Files / Forensics tab.
 */
function sat_render_file_integrity()
{
    if (
        !current_user_can(
            'manage_options'
        )
    ) {
        return;
    }

    $comparison =
        sat_integrity_compare();

    $filesystem =
        sat_integrity_filesystem_checks();

    ?>
    <div class="sat-panel">

        <div class="sat-health-heading">

            <div>
                <h2>
                    File Integrity & Forensics
                </h2>

                <p>
                    Establish a SHA-256 baseline and detect
                    new, modified, deleted, or suspicious files.
                </p>
            </div>

            <?php if (
                $comparison['has_baseline']
            ) : ?>

                <span class="sat-status sat-status-good">
                    Baseline Active
                </span>

            <?php else : ?>

                <span class="sat-status sat-status-warning">
                    No Baseline
                </span>

            <?php endif; ?>

        </div>


        <?php
        if (
            isset($_GET['sat_integrity'])
            &&
            $_GET['sat_integrity']
            === 'baseline_created'
        ) :
        ?>

            <div class="notice notice-success inline">
                <p>
                    File-integrity baseline created successfully.
                </p>
            </div>

        <?php endif; ?>


        <?php if (
            !$comparison['has_baseline']
        ) : ?>

            <div class="sat-diagnostic-note">

                <strong>
                    No file baseline exists yet.
                </strong>

                Create a baseline while the site is in a known-good
                state. Future scans will compare against it.

            </div>

        <?php else : ?>

            <div class="sat-health-grid">

                <div class="sat-health-card">

                    <strong>
                        New Files
                    </strong>

                    <div class="sat-health-value">
                        <?php
                        echo esc_html(
                            count(
                                $comparison['new']
                            )
                        );
                        ?>
                    </div>

                </div>


                <div class="sat-health-card">

                    <strong>
                        Modified Files
                    </strong>

                    <div class="sat-health-value">
                        <?php
                        echo esc_html(
                            count(
                                $comparison['modified']
                            )
                        );
                        ?>
                    </div>

                </div>


                <div class="sat-health-card">

                    <strong>
                        Deleted Files
                    </strong>

                    <div class="sat-health-value">
                        <?php
                        echo esc_html(
                            count(
                                $comparison['deleted']
                            )
                        );
                        ?>
                    </div>

                </div>


                <div class="sat-health-card">

                    <strong>
                        Suspicious Files
                    </strong>

                    <div class="sat-health-value">
                        <?php
                        echo esc_html(
                            count(
                                $comparison['suspicious']
                            )
                        );
                        ?>
                    </div>

                </div>

            </div>

        <?php endif; ?>


        <h3>
            Baseline Information
        </h3>

        <table class="widefat striped">

            <tbody>

                <tr>
                    <td>
                        <strong>
                            Created
                        </strong>
                    </td>

                    <td>
                        <?php
                        echo esc_html(
                            $comparison['has_baseline']
                                ? $comparison[
                                    'baseline'
                                ]['created_at']
                                : 'Not created'
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td>
                        <strong>
                            Baseline Files
                        </strong>
                    </td>

                    <td>
                        <?php
                        echo esc_html(
                            $comparison['has_baseline']
                                ? number_format_i18n(
                                    $comparison[
                                        'baseline'
                                    ]['file_count']
                                )
                                : '0'
                        );
                        ?>
                    </td>
                </tr>

                <tr>
                    <td>
                        <strong>
                            Current Scan Files
                        </strong>
                    </td>

                    <td>
                        <?php
                        echo esc_html(
                            number_format_i18n(
                                $comparison[
                                    'current_count'
                                ]
                            )
                        );
                        ?>
                    </td>
                </tr>

            </tbody>

        </table>


        <form
            method="post"
            action="<?php
                echo esc_url(
                    admin_url(
                        'admin-post.php'
                    )
                );
            ?>"
            class="sat-integrity-action"
        >

            <input
                type="hidden"
                name="action"
                value="sat_integrity_create_baseline"
            >

            <?php
            wp_nonce_field(
                'sat_integrity_create_baseline'
            );
            ?>

            <?php
            submit_button(
                $comparison['has_baseline']
                    ? 'Rebuild File Baseline'
                    : 'Create File Baseline',
                'secondary',
                'submit',
                false
            );
            ?>

        </form>


        <?php if (
            $comparison['has_baseline']
        ) : ?>

            <h3>
                File Changes
            </h3>

            <div class="sat-table-scroll">

                <table class="widefat striped">

                    <thead>

                        <tr>
                            <th>File</th>
                            <th>Change</th>
                            <th>Size</th>
                            <th>Modified</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php
                        sat_render_integrity_rows(
                            $comparison['new'],
                            'new'
                        );

                        sat_render_integrity_rows(
                            $comparison['modified'],
                            'modified'
                        );

                        sat_render_integrity_rows(
                            $comparison['deleted'],
                            'deleted'
                        );
                        ?>

                    </tbody>

                </table>

            </div>


            <h3>
                High-Risk File Findings
            </h3>

            <?php if (
                empty(
                    $comparison['suspicious']
                )
            ) : ?>

                <div class="sat-log-empty">
                    No executable PHP-like files were detected
                    inside the uploads directory.
                </div>

            <?php else : ?>

                <table class="widefat striped">

                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Risk</th>
                            <th>Reason</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php
                    foreach (
                        $comparison['suspicious']
                        as $path => $finding
                    ) :
                    ?>

                        <tr>

                            <td>
                                <code>
                                    <?php
                                    echo esc_html(
                                        $path
                                    );
                                    ?>
                                </code>
                            </td>

                            <td>
                                <span class="sat-status sat-status-critical">
                                    Critical
                                </span>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $finding['reason']
                                );
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        <?php endif; ?>


        <h3>
            Filesystem Permissions
        </h3>

        <div class="sat-table-scroll">

            <table class="widefat striped">

                <thead>

                    <tr>
                        <th>Path</th>
                        <th>Permissions</th>
                        <th>Writable</th>
                    </tr>

                </thead>

                <tbody>

                    <?php
                    foreach (
                        $filesystem
                        as $item
                    ) :
                    ?>

                        <tr>

                            <td>
                                <strong>
                                    <?php
                                    echo esc_html(
                                        $item['label']
                                    );
                                    ?>
                                </strong>

                                <div>
                                    <code>
                                        <?php
                                        echo esc_html(
                                            $item['path']
                                        );
                                        ?>
                                    </code>
                                </div>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $item['permissions']
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo esc_html(
                                    $item['writable']
                                        ? 'Yes'
                                        : 'No'
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
                Forensics note:
            </strong>

            File changes are indicators, not proof of compromise.
            Plugin, theme, WordPress core and administrator updates
            can legitimately modify files.

        </div>

    </div>
    <?php
}
