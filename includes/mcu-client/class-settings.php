<?php
/**
 * Maintenance client settings storage.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the maintenance client option.
 */
final class Settings {
	const OPTION_NAME = 'mcu_maintenance_client_settings';

	/**
	 * Default option values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'secret'                          => '',
			'dashboard_access_key'            => '',
			'dashboard_access_enabled'        => true,
			'dashboard_access_user_id'        => 0,
			'debug_enabled'                   => false,
			'last_request_at'                 => 0,
			'last_request_ip'                 => '',
			'last_auth_result'                => 'never',
			'last_dashboard_access_at'        => 0,
			'last_dashboard_access_ip'        => '',
			'last_dashboard_access_result'    => 'never',
		);
	}

	/**
	 * Ensure the option exists and a secret is present.
	 *
	 * @return void
	 */
	public static function ensure_defaults() {
		$stored   = get_option( self::OPTION_NAME, false );
		$settings = is_array( $stored ) ? array_merge( self::defaults(), $stored ) : self::defaults();
		$changed  = false;

		if ( empty( $settings['secret'] ) ) {
			$settings['secret'] = self::generate_secret();
			$changed            = true;
		}

		if ( empty( $settings['dashboard_access_key'] ) ) {
			$settings['dashboard_access_key'] = self::generate_secret();
			$changed                          = true;
		}

		if ( false === $stored ) {
			add_option( self::OPTION_NAME, $settings, '', 'no' );
			return;
		}

		if ( $changed ) {
			self::save( $settings );
		}
	}

	/**
	 * Return merged settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Save settings with autoload disabled.
	 *
	 * @param array<string,mixed> $settings Settings to save.
	 * @return void
	 */
	public static function save( array $settings ) {
		$settings = array_merge( self::defaults(), $settings );
		update_option( self::OPTION_NAME, $settings, false );
	}

	/**
	 * Get the shared secret, creating one if necessary.
	 *
	 * @return string
	 */
	public static function get_secret() {
		$settings = self::get();

		if ( empty( $settings['secret'] ) ) {
			$settings['secret'] = self::generate_secret();
			self::save( $settings );
		}

		return (string) $settings['secret'];
	}

	/**
	 * Replace the shared secret.
	 *
	 * @return string
	 */
	public static function regenerate_secret() {
		$settings                     = self::get();
		$settings['secret']           = self::generate_secret();
		$settings['last_auth_result'] = 'secret_regenerated';
		self::save( $settings );

		return (string) $settings['secret'];
	}

	/**
	 * Ensure dashboard access has a secret and a target admin user.
	 *
	 * @param int $user_id Preferred user ID.
	 * @return array<string,mixed>
	 */
	public static function ensure_dashboard_access( $user_id = 0 ) {
		$settings = self::get();
		$changed  = false;

		if ( empty( $settings['dashboard_access_key'] ) ) {
			$settings['dashboard_access_key'] = self::generate_secret();
			$changed                          = true;
		}

		$user_id = absint( $user_id );
		if ( $user_id > 0 && empty( $settings['dashboard_access_user_id'] ) && user_can( $user_id, 'manage_options' ) ) {
			$settings['dashboard_access_user_id'] = $user_id;
			$changed                              = true;
		}

		if ( $changed ) {
			self::save( $settings );
		}

		return $settings;
	}

	/**
	 * Return the dashboard access secret, creating one if necessary.
	 *
	 * @return string
	 */
	public static function get_dashboard_access_key() {
		$settings = self::ensure_dashboard_access();
		return (string) $settings['dashboard_access_key'];
	}

	/**
	 * Replace the dashboard access secret and bind it to an admin user.
	 *
	 * @param int $user_id Target admin user ID.
	 * @return string
	 */
	public static function regenerate_dashboard_access_key( $user_id = 0 ) {
		$settings                            = self::get();
		$settings['dashboard_access_key']    = self::generate_secret();
		$settings['dashboard_access_enabled'] = true;

		$user_id = absint( $user_id );
		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			$settings['dashboard_access_user_id'] = $user_id;
		}

		$settings['last_dashboard_access_result'] = 'key_regenerated';
		self::save( $settings );

		return (string) $settings['dashboard_access_key'];
	}

	/**
	 * Store whether dashboard one-click access is enabled.
	 *
	 * @param bool $enabled Enabled flag.
	 * @param int  $user_id Optional target admin user ID.
	 * @return void
	 */
	public static function set_dashboard_access_enabled( $enabled, $user_id = 0 ) {
		$settings                               = self::ensure_dashboard_access( $user_id );
		$settings['dashboard_access_enabled']   = (bool) $enabled;
		$user_id                                = absint( $user_id );

		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			$settings['dashboard_access_user_id'] = $user_id;
		}

		self::save( $settings );
	}

	/**
	 * Is dashboard one-click access enabled?
	 *
	 * @return bool
	 */
	public static function dashboard_access_enabled() {
		$settings = self::get();
		return ! empty( $settings['dashboard_access_enabled'] );
	}

	/**
	 * Return the configured dashboard access user ID.
	 *
	 * @return int
	 */
	public static function dashboard_access_user_id() {
		$settings = self::get();
		return isset( $settings['dashboard_access_user_id'] ) ? absint( $settings['dashboard_access_user_id'] ) : 0;
	}

	/**
	 * Generate a 32-byte cryptographically secure secret, encoded for copy/paste.
	 *
	 * @return string
	 */
	public static function generate_secret() {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				$bytes = random_bytes( 32 );
				return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
			} catch ( \Exception $exception ) {
				// Fall back to WordPress below.
			}
		}

		return wp_generate_password( 64, true, true );
	}

	/**
	 * Store the last request metadata.
	 *
	 * @param string $ip     Request IP address.
	 * @param string $result Authentication result.
	 * @return void
	 */
	public static function record_request( $ip, $result ) {
		$settings = self::get();
		$ip       = sanitize_text_field( (string) $ip );
		$result   = sanitize_key( (string) $result );
		$now      = time();

		if (
			$ip === (string) $settings['last_request_ip']
			&& $result === (string) $settings['last_auth_result']
			&& (int) $settings['last_request_at'] > $now - MINUTE_IN_SECONDS
		) {
			return;
		}

		$settings['last_request_at']  = $now;
		$settings['last_request_ip']  = $ip;
		$settings['last_auth_result'] = $result;

		self::save( $settings );
	}

	/**
	 * Store the last dashboard access metadata.
	 *
	 * @param string $ip     Request IP address.
	 * @param string $result Access result.
	 * @return void
	 */
	public static function record_dashboard_access_request( $ip, $result ) {
		$settings = self::get();
		$ip       = sanitize_text_field( (string) $ip );
		$result   = sanitize_key( (string) $result );
		$now      = time();

		if (
			$ip === (string) $settings['last_dashboard_access_ip']
			&& $result === (string) $settings['last_dashboard_access_result']
			&& (int) $settings['last_dashboard_access_at'] > $now - MINUTE_IN_SECONDS
		) {
			return;
		}

		$settings['last_dashboard_access_at']     = $now;
		$settings['last_dashboard_access_ip']     = $ip;
		$settings['last_dashboard_access_result'] = $result;

		self::save( $settings );
	}

	/**
	 * Store the debug mode flag.
	 *
	 * @param bool $enabled Whether debug is enabled.
	 * @return void
	 */
	public static function set_debug_enabled( $enabled ) {
		$settings                  = self::get();
		$settings['debug_enabled'] = (bool) $enabled;
		self::save( $settings );
	}

	/**
	 * Is debug logging enabled?
	 *
	 * @return bool
	 */
	public static function debug_enabled() {
		$settings = self::get();
		return ! empty( $settings['debug_enabled'] );
	}
}
