<?php
/**
 * Plugin Name: WPFlat Forms
 * Description: Online forms your clients fill in, with sections that repeat for each company or branch, saved drafts they can come back to, and answers you read in the app. Needs WPFlat Core.
 * Version: 0.4.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Digitalzooks
 * License: GPL-2.0-or-later
 * Text Domain: wpflatforms
 */

defined( 'ABSPATH' ) || exit;

define( 'WPFLATFORMS_VERSION', '0.4.3' );
define( 'WPFLATFORMS_FILE', __FILE__ );
define( 'WPFLATFORMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPFLATFORMS_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! defined( 'WPFLATCORE_VERSION' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p><strong>WPFlat Forms</strong> needs the <strong>WPFlat Core</strong> plugin. Install and activate WPFlat Core, then WPFlat Forms will start.</p></div>';
					}
				}
			);
			return;
		}
		spl_autoload_register(
			function ( $class ) {
				if ( 0 === strpos( $class, 'WPFlatForms\\' ) ) {
					$path = WPFLATFORMS_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, 12 ) ) . '.php';
					if ( is_readable( $path ) ) {
						require_once $path;
					}
				}
			}
		);
		WPFlatForms\Module::init();
	},
	1
);
