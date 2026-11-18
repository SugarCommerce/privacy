<?php
/**
 * Who an audit row is about, without naming them.
 *
 * @package   SugarCommerce\Privacy
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Privacy;

/**
 * Class Pseudonym
 *
 * An audit trail of erasures that kept the addresses would undo every one
 * of them. One that kept nothing could not show that a second request was
 * for the same person. The start of a keyed hash of the address does both:
 * equal for the same address, and meaningless to anybody without the key.
 *
 * Keyed is the point — an unkeyed hash of an email address is reversed by
 * hashing a list of addresses. Hand this a digest from a keyed hash —
 * for_address() makes one with the site's salt — never `hash( 'sha256', $email )`.
 *
 * Short, so it identifies a request rather than serving as a lookup key
 * that could be joined against anything else. Sixteen hex characters is
 * 64 bits: collisions among one install's requests are not a concern.
 *
 * @since 1.0.0
 */
final readonly class Pseudonym {

	/**
	 * Characters kept.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	public const int LENGTH = 16;

	/**
	 * The audit subject for a keyed digest.
	 *
	 * @since 1.0.0
	 *
	 * @param string $digest Hex digest from a keyed hash.
	 * @param int    $length Characters kept.
	 *
	 * @return string Lower-case hex, or '' when the digest is not hex.
	 */
	public static function subject( string $digest, int $length = self::LENGTH ): string {
		$digest = strtolower( trim( $digest ) );

		return '' !== $digest && ctype_xdigit( $digest ) ? substr( $digest, 0, max( 1, $length ) ) : '';
	}

	/**
	 * The audit subject for an email address, keyed with a site secret.
	 *
	 * The address is case-folded first, so the same person asking twice in
	 * different case is the same subject. The key defaults to WordPress's
	 * own `wp_salt( 'auth' )`: secret, per site, and already there. Outside
	 * WordPress a key has to be passed.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $address The address the request was for.
	 * @param string|null $key     A secret. Null for the site's auth salt.
	 * @param int         $length  Characters kept.
	 *
	 * @return string Lower-case hex, or '' for a blank address.
	 *
	 * @throws \LogicException When there is no key: an unkeyed hash of an
	 *                         address is the address, to anyone with a list.
	 */
	public static function for_address( string $address, ?string $key = null, int $length = self::LENGTH ): string {
		$address = Mentions::key( $address );

		if ( '' === $address ) {
			return '';
		}

		$key ??= function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';

		if ( '' === $key ) {
			throw new \LogicException( 'Pseudonym::for_address() needs a secret key outside WordPress.' );
		}

		return self::subject( hash_hmac( 'sha256', $address, $key ), $length );
	}
}
