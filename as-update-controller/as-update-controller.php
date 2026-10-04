<?php
/**
 * Plugin Name: AS Update Controller
 * Description: One catalogue, manual update checks and guided updates for AlphaSys plugins.
 * Version: 0.7.1
 * Author: AlphaSys
 * Author URI: https://alphasys.com.au
 * Update URI: https://github.com/cchatterton/as-update-controller
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Network: true
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: as-update-controller
 */
if (!defined('ABSPATH')) { exit; }
define('ASUC_VERSION', '0.7.1');
define('ASUC_API_VERSION', 1);
define('ASUC_FILE', __FILE__);
define('ASUC_DIR', __DIR__ . '/');
define('ASUC_CATALOGUE_URL', 'https://api.github.com/repos/cchatterton/as-update-controller/contents/catalogue.json?ref=main');
foreach (['state', 'catalogue', 'discovery', 'legacy', 'updates', 'operations', 'actions', 'admin'] as $asuc_module) {
    require_once ASUC_DIR . 'functions/' . $asuc_module . '.php';
}
unset($asuc_module);
register_activation_hook(__FILE__, 'asuc_activate');
register_deactivation_hook(__FILE__, 'asuc_deactivate');
add_action('plugins_loaded', 'asuc_boot', PHP_INT_MAX);
function asuc_boot(): void {
    if (!asuc_available()) { return; }
    asuc_suppress_legacy();
    add_action('init', 'asuc_suppress_legacy', -999);
    add_action('admin_init', 'asuc_suppress_legacy', -999);
    add_filter('site_transient_update_plugins', 'asuc_project_updates', PHP_INT_MAX);
    add_filter('pre_set_site_transient_update_plugins', 'asuc_project_updates', PHP_INT_MAX);
    add_filter('plugins_api', 'asuc_plugin_information', PHP_INT_MAX, 3);
    add_filter('plugin_row_meta', 'asuc_row_meta', PHP_INT_MAX, 4);
    add_filter('upgrader_pre_download', 'asuc_verify_download', 10, 4);
    add_filter('upgrader_source_selection', 'asuc_verify_source', 20, 4);
    asuc_migrate_manual_checks();
    add_action('admin_menu', 'asuc_menu');
    add_action('network_admin_menu', 'asuc_menu');
    add_action('admin_enqueue_scripts', 'asuc_assets');
    add_action('admin_post_asuc_action', 'asuc_handle_form');
    add_action('wp_ajax_asuc_action', 'asuc_handle_ajax');
    add_filter('plugin_action_links_' . plugin_basename(ASUC_FILE), 'asuc_settings_link');
    add_filter('network_admin_plugin_action_links_' . plugin_basename(ASUC_FILE), 'asuc_settings_link');
}
