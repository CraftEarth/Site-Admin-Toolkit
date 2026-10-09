<?php

if (!defined('ABSPATH')) {
    exit;
}

function sat_premium_status_class($summary)
{
    if (sat_dev_premium_enabled()) {
        return 'good';
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

    ?>
    <div class="sat-panel sat-premium-panel">

        <div class="sat-health-heading">

            <div>
                <h2>Site Admin Toolkit Premium</h2>

                <p>
                    Upgrade from core WordPress security visibility to
                    deeper history, automation, integrations and
                    infrastructure-level monitoring.
                </p>
            </div>

            <span class="sat-status sat-status-<?php echo esc_attr(sat_premium_status_class($summary)); ?>">
                <?php echo esc_html($summary['plan']); ?>
            </span>

        </div>

        <?php if ($notice) : ?>

            <div class="notice <?php echo $notice['type'] === 'success' ? 'notice-success' : 'notice-error'; ?> inline">
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
                <span>Licensing API</span>
                <strong><?php echo $api_configured ? 'Connected' : 'Not Configured'; ?></strong>
            </div>

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
                    Coupons can create trials, discounts or promotional
                    Premium entitlements when validated by your licensing server.
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
                    Coupon creation is intentionally not available inside
                    customer installations. Coupons will be created from
                    your private licensing dashboard.
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

        <div class="sat-premium-note">
            <strong>Architecture:</strong>
            Future modules should use
            <code>sat_feature_enabled('feature_name')</code>
            instead of implementing their own license checks.
        </div>

        <?php if (sat_current_plan() !== 'free' && !sat_dev_premium_enabled()) : ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sat_clear_entitlement">
                <?php wp_nonce_field('sat_clear_entitlement'); ?>
                <?php submit_button('Clear Cached Entitlement', 'secondary', 'submit', false); ?>
            </form>

        <?php endif; ?>

    </div>
    <?php
}
