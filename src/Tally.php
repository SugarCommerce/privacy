<?php
/**
 * What an access or erasure request found and changed, said plainly.
 *
 * @package   SugarCommerce\Privacy
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Privacy;

/**
 * Class Tally
 *
 * The audit row for an erasure has to say what was done without saying who
 * to. Counts by kind do that: "2 orders, 1 licenses — ticket 4411" is a
 * record an operator can stand behind, and names nobody.
 *
 * @since 1.0.0
 */
final readonly class Tally {

	/**
	 * What summary() says when there is nothing.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const string NOTHING = 'nothing held';

	/**
	 * How many of each kind of record was found.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $found Kind => rows. Anything that cannot
	 *                                    be counted counts as none.
	 *
	 * @return array<string, int>
	 */
	public static function count( array $found ): array {
		return array_map( static fn ( mixed $rows ): int => is_countable( $rows ) ? count( $rows ) : 0, $found );
	}

	/**
	 * Counts as "2 orders, 1 licenses", leaving out the noughts.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, int> $counts  Kind => count. Pass the kinds
	 *                                    already translated when a person
	 *                                    will read the summary.
	 * @param string|null        $nothing What to say when every count is 0.
	 *                                    Null for NOTHING, in the site's
	 *                                    language.
	 *
	 * @return string
	 */
	public static function summary( array $counts, ?string $nothing = null ): string {
		$nothing ??= __( 'nothing held', 'sugarcommerce' );
		$parts     = array();

		foreach ( $counts as $kind => $count ) {
			if ( (int) $count > 0 ) {
				$parts[] = (int) $count . ' ' . $kind;
			}
		}

		return array() === $parts ? $nothing : implode( ', ', $parts );
	}

	/**
	 * The audit note: what was done, then why.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, int> $counts What changed.
	 * @param string             $reason The operator's reason, usually a
	 *                                   ticket. Never the person's address.
	 *
	 * @return string
	 */
	public static function note( array $counts, string $reason = '' ): string {
		$reason = trim( $reason );

		return trim( self::summary( $counts ) . ( '' === $reason ? '' : ' — ' . $reason ) );
	}
}
