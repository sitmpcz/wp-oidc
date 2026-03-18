<?php
/**
 * Plugin Name: WordPress OIDC Login
 * Plugin URI: https://github.com/dubovsky/wp-oidc
 * Description: Replace WordPress login form with Keycloak OIDC authentication
 * Version: 1.0.0
 * Author: Dubovsky
 * Author URI: https://github.com/dubovsky
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-oidc
 * Domain Path: /languages
 * Requires PHP: 8.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Load plugin classes
require_once __DIR__ . '/includes/class-oidc-client.php';
require_once __DIR__ . '/includes/class-auth-handler.php';
require_once __DIR__ . '/includes/class-admin-settings.php';
require_once __DIR__ . '/includes/class-backchannel-logout.php';

// Initialize the plugin
add_action(
	'plugins_loaded',
	function () {
		$admin_settings = new \WpOidc\AdminSettings();
		$admin_settings->init();

		$auth_handler = new \WpOidc\AuthHandler();
		$auth_handler->init();

		// Initialize backchannel logout handler
		$backchannel_logout = new \WpOidc\BackchannelLogout();
		$backchannel_logout->init();
	}
);

// Register activation hook
register_activation_hook(
	__FILE__,
	function () {
		// Create default options if not set
		if ( ! get_option( 'wp_oidc_enabled' ) ) {
			update_option( 'wp_oidc_enabled', 0 );
		}
	}
);
