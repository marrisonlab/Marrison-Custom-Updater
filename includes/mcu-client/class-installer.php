<?php
/**
 * Maintenance client lifecycle handlers.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles lifecycle events for the bundled client.
 */
final class Installer {
	/**
	 * Activate the client module.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( plugin_basename( MCU_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'WP Master Updater maintenance client requires PHP 7.4 or newer.', 'marrison-custom-updater' ),
				esc_html__( 'Plugin activation failed', 'marrison-custom-updater' ),
				array( 'back_link' => true )
			);
		}

		Settings::ensure_defaults();
	}

	/**
	 * Deactivate the client module.
	 *
	 * Data is intentionally kept until plugin uninstall/removal.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// No recurring task is registered by the client module.
	}
}
