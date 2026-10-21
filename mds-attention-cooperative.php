<?php
/**
 * Plugin Name: Million Dollar Script - Attention Cooperative
 * Plugin URI: https://milliondollarscript.com/extensions/attention-cooperative
 * Description: Coordinate local sponsor-inventory partner pools with explicit approvals, scoped exposure, capacity tracking, audit logs, and revocation.
 * Version: 0.3.1
 * Author: Million Dollar Script
 * Author URI: https://milliondollarscript.com
 * Text Domain: mds-attention-cooperative
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.1.3
 * Requires PHP: 8.1
 * License: GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins: million-dollar-script
 *
 * Requires MDS: 3.0.0
 * Requires MDS API: 1
 * Requires License: No
 * MDS Provides: inventory.cooperative partner-inventory.manifest partner-approval.placements
 * MDS Requires: platform.core
 * MDS Recommends: mds-sponsorboard mds-launch-wall mds-missions mds-local-money-map
 * MDS Requires Service: no
 * MDS Setup Category: monetization
 * MDS Minimum Security Level: api_key_read
 * MDS LLM Safe Actions: read_inventory
 *
 * @package MillionDollarScript\Extensions\AttentionCooperative
 */

namespace MillionDollarScript\Extensions\AttentionCooperative;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MDS_ATTENTION_COOPERATIVE_VERSION')) {
    define('MDS_ATTENTION_COOPERATIVE_VERSION', '0.3.1');
}

if (!defined('MDS_ATTENTION_COOPERATIVE_DB_VERSION')) {
    define('MDS_ATTENTION_COOPERATIVE_DB_VERSION', '0.3.0');
}

if (!defined('MDS_ATTENTION_COOPERATIVE_FILE')) {
    define('MDS_ATTENTION_COOPERATIVE_FILE', __FILE__);
}

if (!defined('MDS_ATTENTION_COOPERATIVE_PATH')) {
    define('MDS_ATTENTION_COOPERATIVE_PATH', plugin_dir_path(__FILE__));
}

if (!defined('MDS_ATTENTION_COOPERATIVE_URL')) {
    define('MDS_ATTENTION_COOPERATIVE_URL', plugin_dir_url(__FILE__));
}

if (!defined('MDS_ATTENTION_COOPERATIVE_BASENAME')) {
    define('MDS_ATTENTION_COOPERATIVE_BASENAME', plugin_basename(__FILE__));
}

function mds_attention_cooperative_check_mds_active() {
    return class_exists('\\MillionDollarScript\\Core\\Runtime')
        && \MillionDollarScript\Core\Runtime::is_ready();
}

function mds_attention_cooperative_missing_mds_notice() {
    echo '<div class="notice notice-error"><p>';
    echo '<strong>' . esc_html__('Million Dollar Script - Attention Cooperative', 'mds-attention-cooperative') . '</strong>: ';
    echo esc_html__('This extension requires the Million Dollar Script plugin to be installed and activated.', 'mds-attention-cooperative');
    echo ' <a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Manage Plugins', 'mds-attention-cooperative') . '</a>';
    echo '</p></div>';
}

function mds_attention_cooperative_load_files() {
    require_once MDS_ATTENTION_COOPERATIVE_PATH . 'includes/Repository.php';
    require_once MDS_ATTENTION_COOPERATIVE_PATH . 'includes/CredentialCipher.php';
    require_once MDS_ATTENTION_COOPERATIVE_PATH . 'includes/ServiceCredentialRepository.php';
    require_once MDS_ATTENTION_COOPERATIVE_PATH . 'includes/ServiceSignature.php';
    require_once MDS_ATTENTION_COOPERATIVE_PATH . 'includes/ServiceCredentialAdmin.php';
    require_once MDS_ATTENTION_COOPERATIVE_PATH . 'includes/Privacy.php';
    require_once MDS_ATTENTION_COOPERATIVE_PATH . 'includes/Retention.php';
    require_once MDS_ATTENTION_COOPERATIVE_PATH . 'includes/Main.php';
}

function mds_attention_cooperative_init() {
    if (!mds_attention_cooperative_check_mds_active()) {
        add_action('admin_notices', __NAMESPACE__ . '\\mds_attention_cooperative_missing_mds_notice');
        return;
    }

    mds_attention_cooperative_load_files();
    Main::instance()->register();
    Retention::register();
}

function mds_attention_cooperative_register_extension() {
    if (!mds_attention_cooperative_check_mds_active()) {
        return;
    }

    if (class_exists('\\MillionDollarScript\\Extensions\\Registry')) {
        \MillionDollarScript\Extensions\Registry::register([
            'id' => 'mds-attention-cooperative',
            'name' => 'Attention Cooperative',
            'description' => 'Coordinate explicit sponsor-inventory partner pools without a central ad network.',
            'version' => MDS_ATTENTION_COOPERATIVE_VERSION,
            'author' => 'Million Dollar Script',
            'url' => 'https://milliondollarscript.com/extensions/attention-cooperative',
            'main_file' => __FILE__,
            'premium' => false,
            'license_required' => false,
            'cleanup' => [
                'description' => 'Cooperative pools, partners, inventory, placements, credentials, and audit records.',
            ],
            'capabilities' => [
                'admin_pages' => true,
                'cooperative_inventory' => true,
                'partner_registry' => true,
                'audit_logs' => true,
                'api' => true,
            ],
        ]);
    }
}

function mds_attention_cooperative_activate() {
    if (!mds_attention_cooperative_check_mds_active()) {
        deactivate_plugins(MDS_ATTENTION_COOPERATIVE_BASENAME);
        wp_die(
            esc_html__('Million Dollar Script - Attention Cooperative requires the Million Dollar Script plugin to be installed and activated.', 'mds-attention-cooperative'),
            esc_html__('Plugin Activation Error', 'mds-attention-cooperative'),
            ['back_link' => true]
        );
    }

    mds_attention_cooperative_load_files();
    Main::register_post_types();
    ServiceCredentialRepository::install();
    Repository::maybe_seed();
    Retention::activate();
    flush_rewrite_rules();
}

function mds_attention_cooperative_deactivate() {
    mds_attention_cooperative_load_files();
    Retention::deactivate();
    flush_rewrite_rules();
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\mds_attention_cooperative_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\mds_attention_cooperative_deactivate');

add_action('plugins_loaded', __NAMESPACE__ . '\\mds_attention_cooperative_init', 20);
add_action('million-dollar-script/register/extensions', __NAMESPACE__ . '\\mds_attention_cooperative_register_extension');
