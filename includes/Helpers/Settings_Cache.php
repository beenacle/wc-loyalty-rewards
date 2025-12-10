<?php

namespace WCLR\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Settings cache helper to reduce get_option() calls.
 */
class Settings_Cache {

	private static ?array $settings = null;
	private static bool $initialized = false;

	/**
	 * Get settings with caching.
	 *
	 * @return array
	 */
	public static function get(): array {
		if ( ! self::$initialized ) {
			self::$settings = get_option( 'wclr_settings', [] );
			self::$initialized = true;
		}
		return self::$settings;
	}

	/**
	 * Clear cache (call after settings update).
	 */
	public static function clear(): void {
		self::$settings = null;
		self::$initialized = false;
	}

	/**
	 * Get a specific setting path.
	 *
	 * @param string $path Dot-separated path (e.g., 'order_earning.enabled').
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_path( string $path, $default = null ) {
		$settings = self::get();
		$keys = explode( '.', $path );
		$value = $settings;
		foreach ( $keys as $key ) {
			if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
				return $default;
			}
			$value = $value[ $key ];
		}
		return $value;
	}
}

