<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central feature registry.
 *
 * New Free/Premium capabilities should be registered here so plan
 * decisions remain centralized instead of being scattered throughout
 * individual modules.
 */
function sat_feature_registry()
{
    return [
        // Free / Community
        'site_health' => [
            'name' => 'Site Health Monitoring',
            'plan' => 'free',
            'description' => 'Core WordPress health and runtime checks.',
        ],
        'database_health' => [
            'name' => 'Database Health',
            'plan' => 'free',
            'description' => 'Read-only database health and size analysis.',
        ],
        'activity_monitor' => [
            'name' => 'Basic Activity Monitor',
            'plan' => 'free',
            'description' => 'Selected WordPress-level security activity.',
        ],
        'threat_correlation' => [
            'name' => 'Basic Threat Correlation',
            'plan' => 'free',
            'description' => 'Behavioral correlation and risk scoring.',
        ],
        'file_integrity' => [
            'name' => 'File Integrity Baseline',
            'plan' => 'free',
            'description' => 'SHA-256 baseline and file-change detection.',
        ],
        'diagnostics' => [
            'name' => 'Logs & Diagnostics',
            'plan' => 'free',
            'description' => 'Debug and PHP diagnostic visibility.',
        ],
        'maintenance_mode' => [
            'name' => 'Maintenance Mode',
            'plan' => 'free',
            'description' => 'Administrator-controlled maintenance mode.',
        ],
        'manual_exports' => [
            'name' => 'Manual Security Exports',
            'plan' => 'free',
            'description' => 'Manual CSV incident and network exports.',
        ],

        // Premium
        'server_log_ingestion' => [
            'name' => 'Apache / Nginx Log Ingestion',
            'plan' => 'premium',
            'description' => 'Analyze traffic before it reaches WordPress.',
        ],
        'extended_history' => [
            'name' => 'Extended Security History',
            'plan' => 'premium',
            'description' => 'Longer event retention and forensic history.',
        ],
        'scheduled_reports' => [
            'name' => 'Scheduled Security Reports',
            'plan' => 'premium',
            'description' => 'Automatically generated recurring security reports.',
        ],
        'email_automation' => [
            'name' => 'Automated Email Alerting',
            'plan' => 'premium',
            'description' => 'Automated security alert delivery.',
        ],
        'webhook_integrations' => [
            'name' => 'Webhook / SIEM Integrations',
            'plan' => 'premium',
            'description' => 'External JSON webhook and SIEM delivery.',
        ],
        'zoho_integration' => [
            'name' => 'Zoho Integration',
            'plan' => 'premium',
            'description' => 'Zoho OAuth and Flow alert integration.',
        ],
        'advanced_outbound_monitoring' => [
            'name' => 'Advanced Outbound Monitoring',
            'plan' => 'premium',
            'description' => 'Enhanced outbound destination baselines and history.',
        ],
        'trusted_proxy_support' => [
            'name' => 'Trusted Reverse Proxy Support',
            'plan' => 'premium',
            'description' => 'Configurable trusted proxy and client-IP handling.',
        ],
        'custom_alert_rules' => [
            'name' => 'Custom Alert Thresholds',
            'plan' => 'premium',
            'description' => 'Configure detection thresholds for failed logins, reconnaissance activity, and critical risk scoring.',
        ],
        'incident_workflow' => [
            'name' => 'Incident Workflow',
            'plan' => 'premium',
            'description' => 'Alert history, acknowledgement and investigation state.',
        ],
        'advanced_file_forensics' => [
            'name' => 'Advanced File Forensics',
            'plan' => 'premium',
            'description' => 'Historical file-change timelines and deeper evidence.',
        ],
        'multisite_management' => [
            'name' => 'Multi-Site / Agency Management',
            'plan' => 'premium',
            'description' => 'Future centralized management for multiple sites.',
        ],
    ];
}

function sat_get_feature($feature_key)
{
    $registry = sat_feature_registry();

    return isset($registry[$feature_key])
        ? $registry[$feature_key]
        : null;
}

function sat_features_by_plan($plan)
{
    return array_filter(
        sat_feature_registry(),
        static function ($feature) use ($plan) {
            return isset($feature['plan']) && $feature['plan'] === $plan;
        }
    );
}

