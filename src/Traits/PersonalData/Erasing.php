<?php
/**
 * One page of a group's erasure, in the shape WordPress wants.
 *
 * @package   SugarCommerce\Privacy
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Privacy\Traits\PersonalData;

/**
 * Trait Erasing
 *
 * What a group's `erase` returns, as removed, retained, messages and
 * done.
 *
 * @since 1.0.0
 */
trait Erasing {

	/**
	 * One page of a group's erasure, in the shape WordPress wants.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   The group.
	 * @param string $email The address the request is for.
	 * @param int    $page  The page, from 1.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $key, string $email, int $page = 1 ): array {
		$group  = $this->groups()[ $key ] ?? null;
		$email  = trim( $email );
		$answer = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		if ( null === $group || null === $group['erase'] || '' === $email ) {
			return $answer;
		}

		$result = ( $group['erase'] )( $email, max( 1, $page ) );

		if ( is_int( $result ) || is_bool( $result ) ) {
			$result = array( 'removed' => $result );
		}

		$result = is_array( $result ) ? $result : array();

		$answer['items_removed']  = (bool) ( $result['removed'] ?? false );
		$answer['items_retained'] = (bool) ( $result['retained'] ?? false );
		$answer['done']           = (bool) ( $result['done'] ?? true );
		$answer['messages']       = array_values(
			array_filter(
				array_map( 'strval', (array) ( $result['messages'] ?? array() ) ),
				static fn ( string $message ): bool => '' !== $message
			)
		);

		if ( $answer['items_retained'] && '' !== $group['retained_message'] ) {
			$answer['messages'][] = $group['retained_message'];
		}

		return $answer;
	}
}
