<?php
/**
 * A stored consent answer, as the person would read it.
 *
 * @package   SugarCommerce\Privacy
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Privacy\Models;

/**
 * Enum Consent
 *
 * Consent is stored as 1, 0 or null, and the null is the one that matters:
 * "not asked" is not "no". An export that prints it as "no" tells the
 * person they declined something they were never offered; one that prints
 * it as "yes" is worse. Three answers, kept three.
 *
 * @since 1.0.0
 */
enum Consent: string {

	/**
	 * They agreed.
	 */
	case Yes = 'yes';

	/**
	 * They were asked, and did not agree.
	 */
	case No = 'no';

	/**
	 * Nobody asked them.
	 */
	case NotAsked = 'not asked';

	/**
	 * The answer a stored value records.
	 *
	 * Yes is exactly 1, '1' or true. Consent has to be unambiguous, so a
	 * value that only casts to 1 — '1abc', '01', 1.5, a non-empty array —
	 * is not read as agreement; it is no.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $stored 1 or '1' (or true) for yes; null or '' for not
	 *                      asked; anything else is no.
	 *
	 * @return self
	 */
	public static function of( mixed $stored ): self {
		return match ( true ) {
			null === $stored || '' === $stored                   => self::NotAsked,
			1 === $stored || '1' === $stored || true === $stored => self::Yes,
			default                                              => self::No,
		};
	}

	/**
	 * For a spreadsheet: yes, no, or a blank cell when nobody asked.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function cell(): string {
		return self::NotAsked === $this ? '' : $this->value;
	}

	/**
	 * For a person to read: Yes, No or Not asked, in the site's language.
	 *
	 * What a WordPress personal data export shows. `value` stays English,
	 * for a log or a file a program reads.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Yes      => __( 'Yes', 'sugarcommerce' ),
			self::No       => __( 'No', 'sugarcommerce' ),
			self::NotAsked => __( 'Not asked', 'sugarcommerce' ),
		};
	}
}
