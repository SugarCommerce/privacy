<?php
/**
 * PHPUnit bootstrap.
 *
 * Just enough of WordPress for the privacy tools to hook into: filters,
 * translation, the auth salt and _doing_it_wrong().
 *
 * @package SugarCommerce\Privacy
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

/**
 * Registered filters.
 *
 * @var array<string, array<int, callable>>
 */
$GLOBALS['pv_filters'] = array();

/**
 * What _doing_it_wrong() was told.
 *
 * @var array<int, string>
 */
$GLOBALS['pv_wrong'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Register a filter callback.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 *
	 * @return bool
	 */
	function add_filter( string $hook, callable $callback ): bool {
		$GLOBALS['pv_filters'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Run a value through the callbacks for a hook.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  $value   Value.
	 * @param mixed  ...$args Further arguments.
	 *
	 * @return mixed
	 */
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['pv_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 *
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Core's HTML escaper.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * A fixed salt, for a stable pseudonym.
	 *
	 * @param string $scheme Which salt.
	 *
	 * @return string
	 */
	function wp_salt( string $scheme = 'auth' ): string {
		return 'test-salt-' . $scheme;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	/**
	 * Record the complaint rather than raising it.
	 *
	 * @param string $function The function.
	 * @param string $message  The complaint.
	 * @param string $version  Since when.
	 *
	 * @return void
	 */
	function _doing_it_wrong( string $function, string $message, string $version ): void {
		$GLOBALS['pv_wrong'][] = $message;
	}
}

/**
 * Forget every filter and complaint a test left behind.
 *
 * @return void
 */
function pv_reset(): void {
	$GLOBALS['pv_filters'] = array();
	$GLOBALS['pv_wrong']   = array();
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
