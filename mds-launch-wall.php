<?php
/**
 * Plugin Name: Million Dollar Script - Launch Wall
 * Plugin URI: https://milliondollarscript.com/extensions/launch-wall
 * Description: Sell and moderate paid launch slots and featured placements for products, apps, books, events, and campaigns.
 * Version: 0.2.0
 * Author: Million Dollar Script
 * Author URI: https://milliondollarscript.com
 * Text Domain: mds-launch-wall
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 8.1
 * License: GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins: million-dollar-script
 *
 * Requires MDS: 3.0.0
 * Requires MDS API: 1
 * Requires License: No
 * MDS Provides: inventory.launch-wall sponsorship.inventory
 * MDS Requires: platform.core
 * MDS Recommends: mds-woocommerce
 * MDS Requires Service: no
 * MDS Setup Category: monetization
 * MDS Minimum Security Level: api_key_read
 * MDS LLM Safe Actions: read_walls read_launches
 *
 * @package MillionDollarScript\Extensions\LaunchWall
 */

namespace MillionDollarScript\Extensions\LaunchWall;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MDS_LAUNCH_WALL_VERSION')) {
    define('MDS_LAUNCH_WALL_VERSION', '0.2.0');
}

if (!defined('MDS_LAUNCH_WALL_FILE')) {
    define('MDS_LAUNCH_WALL_FILE', __FILE__);
}

if (!defined('MDS_LAUNCH_WALL_PATH')) {
    define('MDS_LAUNCH_WALL_PATH', plugin_dir_path(__FILE__));
}

if (!defined('MDS_LAUNCH_WALL_URL')) {
    define('MDS_LAUNCH_WALL_URL', plugin_dir_url(__FILE__));
}

if (!defined('MDS_LAUNCH_WALL_BASENAME')) {
    define('MDS_LAUNCH_WALL_BASENAME', plugin_basename(__FILE__));
}

function mds_launch_wall_check_mds_active() {
    return class_exists('\\MillionDollarScript\\Core\\Runtime')
        && \MillionDollarScript\Core\Runtime::is_ready();
}

function mds_launch_wall_missing_mds_notice() {
    echo '<div class="notice notice-error"><p>';
    echo '<strong>' . esc_html__('Million Dollar Script - Launch Wall', 'mds-launch-wall') . '</strong>: ';
    echo esc_html__('This extension requires the Million Dollar Script plugin to be installed and activated.', 'mds-launch-wall');
    echo ' <a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Manage Plugins', 'mds-launch-wall') . '</a>';
    echo '</p></div>';
}

function mds_launch_wall_load_files() {
    require_once MDS_LAUNCH_WALL_PATH . 'includes/Repository.php';
    require_once MDS_LAUNCH_WALL_PATH . 'includes/Privacy.php';
    require_once MDS_LAUNCH_WALL_PATH . 'includes/Retention.php';
    require_once MDS_LAUNCH_WALL_PATH . 'includes/Main.php';
}

function mds_launch_wall_init() {
    if (!mds_launch_wall_check_mds_active()) {
        add_action('admin_notices', __NAMESPACE__ . '\\mds_launch_wall_missing_mds_notice');
        return;
    }

    mds_launch_wall_load_files();
    Main::instance()->register();
}

function mds_launch_wall_register_extension() {
    if (!mds_launch_wall_check_mds_active()) {
        return;
    }

    if (class_exists('\\MillionDollarScript\\Extensions\\Registry')) {
        \MillionDollarScript\Extensions\Registry::register([
            'id' => 'mds-launch-wall',
            'name' => 'Launch Wall',
            'description' => 'Sell and moderate paid launch slots and featured placements.',
            'version' => MDS_LAUNCH_WALL_VERSION,
            'author' => 'Million Dollar Script',
            'url' => 'https://milliondollarscript.com/extensions/launch-wall',
            'main_file' => __FILE__,
            'premium' => false,
            'license_required' => false,
            'cleanup' => [
                'description' => 'Launch walls, launch entries, and extension settings.',
            ],
            'capabilities' => [
                'admin_pages' => true,
                'launch_inventory' => true,
                'frontend_assets' => true,
                'api' => true,
            ],
        ]);
    }
}

function mds_launch_wall_activate() {
    if (!mds_launch_wall_check_mds_active()) {
        deactivate_plugins(MDS_LAUNCH_WALL_BASENAME);
        wp_die(
            esc_html__('Million Dollar Script - Launch Wall requires the Million Dollar Script plugin to be installed and activated.', 'mds-launch-wall'),
            esc_html__('Plugin Activation Error', 'mds-launch-wall'),
            ['back_link' => true]
        );
    }

    mds_launch_wall_load_files();
    Main::register_post_types();
    Repository::maybe_seed();
    Retention::activate();
    flush_rewrite_rules();
}

function mds_launch_wall_deactivate() {
    mds_launch_wall_load_files();
    Retention::deactivate();
    flush_rewrite_rules();
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\mds_launch_wall_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\mds_launch_wall_deactivate');

add_action('plugins_loaded', __NAMESPACE__ . '\\mds_launch_wall_init', 20);
add_action('million-dollar-script/register/extensions', __NAMESPACE__ . '\\mds_launch_wall_register_extension');
