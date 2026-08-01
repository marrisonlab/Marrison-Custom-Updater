<?php
/**
 * Maintenance client bootstrap.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires WordPress hooks for the bundled client.
 */
final class Plugin {
	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-actions-controller.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-dashboard-access-controller.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-rest-controller.php';
		Actions_Controller::init();
		Dashboard_Access_Controller::init();

		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		if ( is_admin() ) {
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-admin.php';
			Admin::init();
		}
	}

	/**
	 * Load REST-only code and register routes.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-debug-logger.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-rate-limiter.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-authenticator.php';

		Rest_Controller::register_routes();
		Actions_Controller::register_routes();
		Dashboard_Access_Controller::register_routes();
	}
}
