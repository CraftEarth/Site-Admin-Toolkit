<?php

if (!defined('ABSPATH')) {
    exit;
}

function sat_premium_status_class($summary)
{
    if (sat_dev_premium_enabled()) {
        return 'good';
    }

    $entitlement = sat_get_entitlement();
    $status = $entitlement['status'] ?? 'inactive';

    if ($status === 'grace') {
        return 'warning';
    }

    return sat_current_plan() === 'free'
        ? 'warning'
        : 'good';
}

function sat_render_premium()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $summary = sat_entitlement_summary();
    $notice = sat_get_premium_notice();
    $free_features = sat_features_by_plan('free');
    $premium_features = sat_features_by_plan('premium');
    $api_configured = sat_license_api_configured();
    $entitlement = sat_get_entitlement();
    $policy = sat_get_license_policy();
    $has_token = (bool) get_option('sat_license_installation_token', '');
    $is_active = sat_entitlement_is_active($entitlement);

    ?>
    <div class="sat-panel sat-premium-panel">

        <div class="sat-health-heading">

            <div>
                <h2>Site Admin Toolkit Premium</h2>

                <p>
                    Premium licensing is validated automatically while
                    preserving a short offline grace period if the licensing
                    service is temporarily unavailable.
                </p>
            </div>

            <span class="sat-status sat-status-<?php echo esc_attr(sat_premium_status_class($summary)); ?>">
                <?php echo esc_html($summary['plan']); ?>
            </span>

        </div>

        <?php if ($notice) : ?>

            <?php
            $notice_class = 'notice-error';

            if ($notice['type'] === 'success') {
                $notice_class = 'notice-success';
            } elseif ($notice['type'] === 'warning') {
                $notice_class = 'notice-warning';
            }
            ?>

            <div class="notice <?php echo esc_attr($notice_class); ?> inline">
                <p><?php echo esc_html($notice['message']); ?></p>
            </div>

        <?php endif; ?>

        <?php if (sat_dev_premium_enabled()) : ?>

            <div class="notice notice-info inline">
                <p>
                    <strong>Developer Premium Mode is enabled.</strong>
                    All registered features are unlocked locally for testing.
                </p>
            </div>

        <?php elseif (($entitlement['status'] ?? '') === 'grace') : ?>

            <div class="notice notice-warning inline">
                <p>
                    <strong>Offline grace period active.</strong>
                    The license server could not be reached. Premium remains
                    available temporarily for up to
                    <?php echo esc_html((string) $policy['grace_days']); ?>
                    days from the last successful validation.
                </p>
            </div>

        <?php endif; ?>

        <div class="sat-premium-status-grid">

            <div class="sat-card">
                <span>Current Plan</span>
                <strong><?php echo esc_html($summary['plan']); ?></strong>
            </div>

            <div class="sat-card">
                <span>Status</span>
                <strong><?php echo esc_html($summary['status']); ?></strong>
            </div>

            <div class="sat-card">
                <span>Expires</span>
                <strong><?php echo esc_html($summary['expires']); ?></strong>
            </div>

            <div class="sat-card">
                <span>Sites</span>
                <strong><?php echo esc_html($summary['sites']); ?></strong>
            </div>

            <div class="sat-card">
                <span>Last Checked</span>
                <strong><?php echo esc_html($summary['last_checked']); ?></strong>
            </div>

            <div class="sat-card">
                <span>Validation</span>
                <strong>
                    Every <?php echo esc_html((string) $policy['validate_interval_hours']); ?> hours
                </strong>
            </div>

            <div class="sat-card">
                <span>Grace Period</span>
                <strong><?php echo esc_html((string) $policy['grace_days']); ?> days</strong>
            </div>

            <div class="sat-card">
                <span>Licensing API</span>
                <strong><?php echo $api_configured ? 'Connected' : 'Not Configured'; ?></strong>
            </div>

        </div>

        <?php if ($has_token && !sat_dev_premium_enabled()) : ?>

            <div class="sat-premium-box">

                <h3>License Controls</h3>

                <p>
                    Manually refresh the entitlement or deactivate this
                    installation and free its license seat.
                </p>

                <div class="sat-license-actions">

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="sat_check_license">
                        <?php wp_nonce_field('sat_check_license'); ?>
                        <?php submit_button('Check License Now', 'secondary', 'submit', false); ?>
                    </form>

                    <form
                        method="post"
                        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                        onsubmit="return confirm('Deactivate Premium on this site and free its license seat?');"
                    >
                        <input type="hidden" name="action" value="sat_deactivate_license">
                        <?php wp_nonce_field('sat_deactivate_license'); ?>
                        <?php submit_button('Deactivate This Site', 'delete', 'submit', false); ?>
                    </form>

                </div>

            </div>

        <?php endif; ?>

        <div class="sat-premium-box">

            <h3>Premium Access</h3>

            <?php if (!$is_active && !sat_dev_premium_enabled()) : ?>

                <p>
                    Upgrade Site Admin Toolkit to Premium and unlock all
                    Premium features for this installation.
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                    <input type="hidden" name="action" value="sat_buy_premium">

                    <?php wp_nonce_field('sat_buy_premium'); ?>

                    <?php
                    submit_button(
                        'Buy Premium',
                        'primary',
                        'submit',
                        false
                    );
                    ?>

                </form>

            <?php elseif ($is_active && !sat_dev_premium_enabled()) : ?>

                <p>
                    Premium is currently active on this site.
                </p>

                <p>
                    <strong>Current plan:</strong>
                    <?php echo esc_html($summary['plan']); ?>
                </p>

                <button type="button" class="button button-secondary" disabled>
                    Manage / Upgrade Plan
                </button>

                <p class="description">
                    Plan upgrades and customer account management will be
                    available through the licensing portal.
                </p>

            <?php else : ?>

                <p>
                    Developer Premium Mode is active. Checkout is disabled
                    while local Premium testing is enabled.
                </p>

            <?php endif; ?>

        </div>
        <div class="sat-premium-columns">

            <div class="sat-premium-box">

                <h3>Activate Premium</h3>

                <p>
                    Enter a Site Admin Toolkit license issued by the
                    licensing service.
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                    <input type="hidden" name="action" value="sat_activate_license">

                    <?php wp_nonce_field('sat_activate_license'); ?>

                    <input
                        type="text"
                        name="license_key"
                        class="regular-text"
                        autocomplete="off"
                        placeholder="SAT-XXXX-XXXX-XXXX-XXXX"
                    >

                    <?php
                    submit_button(
                        'Activate License',
                        'primary',
                        'submit',
                        false
                    );
                    ?>

                </form>

                <?php if (!$api_configured) : ?>

                    <p class="description">
                        License activation will become live after your
                        private licensing server is connected.
                    </p>

                <?php endif; ?>

            </div>

            <div class="sat-premium-box">

                <h3>Redeem a Coupon</h3>

                <p>
                    Coupons can create trials or promotional Premium
                    entitlements when validated by your licensing server.
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                    <input type="hidden" name="action" value="sat_redeem_coupon">

                    <?php wp_nonce_field('sat_redeem_coupon'); ?>

                    <input
                        type="text"
                        name="coupon_code"
                        class="regular-text"
                        autocomplete="off"
                        placeholder="LAUNCH50"
                    >

                    <?php
                    submit_button(
                        'Redeem Coupon',
                        'secondary',
                        'submit',
                        false
                    );
                    ?>

                </form>

                <p class="description">
                    Coupon creation remains private to the licensing dashboard.
                </p>

            </div>

        </div>

        <h3>Free / Community Features</h3>

        <div class="sat-premium-feature-grid">

            <?php foreach ($free_features as $key => $feature) : ?>

                <div class="sat-premium-feature sat-premium-feature-free">

                    <div>
                        <strong><?php echo esc_html($feature['name']); ?></strong>
                        <p><?php echo esc_html($feature['description']); ?></p>
                    </div>

                    <span class="sat-status sat-status-good">Included</span>

                </div>

            <?php endforeach; ?>

        </div>

        <h3>Premium Features</h3>

        <div class="sat-premium-feature-grid">

            <?php foreach ($premium_features as $key => $feature) : ?>

                <?php $enabled = sat_feature_enabled($key); ?>

                <div class="sat-premium-feature <?php echo $enabled ? 'sat-premium-feature-enabled' : 'sat-premium-feature-locked'; ?>">

                    <div>
                        <strong><?php echo esc_html($feature['name']); ?></strong>
                        <p><?php echo esc_html($feature['description']); ?></p>
                    </div>

                    <span class="sat-status <?php echo $enabled ? 'sat-status-good' : 'sat-status-warning'; ?>">
                        <?php echo $enabled ? 'Unlocked' : 'Premium'; ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>

        <?php if ($is_active && !sat_dev_premium_enabled()) : ?>

            <div class="sat-premium-note">
                <strong>License lifecycle active:</strong>
                this installation validates automatically and can be
                deactivated from the controls above to free its site seat.
            </div>

        <?php endif; ?>

    </div>
    <?php
}

