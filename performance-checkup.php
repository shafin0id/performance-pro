<?php
/**
 * Plugin Name: Performance Pro
 * Plugin URI: https://github.com/shafinoid/performance-checkup
 * Description: A simple diagnostic tool that helps site admins spot common performance red flags without overwhelming them. Tracks query counts, slow queries, and memory usage in wp-admin only.
 * Version: 1.0.0
 * Author: Shafinoid
 * Author URI: https://github.com/shafinoid
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: performance-checkup
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Plugin version
define( 'PERFORMANCE_CHECKUP_VERSION', '1.0.0' );
define( 'PERFORMANCE_CHECKUP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PERFORMANCE_CHECKUP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin files
 * We only load in admin because this plugin does nothing on the frontend by design
 */
if ( is_admin() ) {
	require_once PERFORMANCE_CHECKUP_PLUGIN_DIR . 'includes/class-performance-detector.php';
	require_once PERFORMANCE_CHECKUP_PLUGIN_DIR . 'includes/class-admin-page.php';
	
	// Initialize the plugin
	Performance_Checkup_Detector::get_instance();
	Performance_Checkup_Admin_Page::get_instance();
}

/**
 * Activation hook
 * We don't create any tables or options on activation.
 * This keeps things simple and avoids database bloat.
 */
register_activation_hook( __FILE__, 'performance_checkup_activate' );
function performance_checkup_activate() {
	// Nothing to do on activation
	// We intentionally avoid creating options or tables
	// The plugin should just work without setup
}

/**
 * Deactivation hook
 */
register_deactivation_hook( __FILE__, 'performance_checkup_deactivate' );
function performance_checkup_deactivate() {
	// Clean up transients if any were set
	delete_transient( 'performance_checkup_notice_dismissed' );
}
