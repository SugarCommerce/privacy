<?php
/**
 * The data subject helpers: Redact, Mentions, Tally, Pseudonym and Consent.
 *
 * @package   SugarCommerce\Privacy
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Privacy\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Privacy\{Consent, Mentions, Pseudonym, Redact, Tally};

/**
 * Class DataSubjectTest
 *
 * The pieces that go wrong quietly: a credential in an export, an address a
 * search missed, an audit row that names the person it erased.
 */
final class DataSubjectTest extends TestCase {

	/* ─── Redact ───────────────────────────────────────────────────────── */

	public function test_columns_are_dropped_and_the_rest_kept(): void {
		$rows = array( array( 'id' => 1, 'access_token' => 'secret', 'email_hash' => 'abc', 'amount' => 900 ) );

		$this->assertSame( array( array( 'id' => 1, 'amount' => 900 ) ), Redact::rows( $rows, 'access_token', 'email_hash' ) );
		$this->assertSame( array( 'id' => 1 ), ( Redact::without( 'x' ) )( array( 'id' => 1 ) ), 'A column that is not there is no error.' );
	}

	/* ─── Mentions ─────────────────────────────────────────────────────── */

	public function test_an_address_is_found_whatever_its_case(): void {
		$this->assertTrue( Mentions::any( '{"to":"Ada@Example.com"}', array( 'ada@example.com' ) ) );
		$this->assertTrue( Mentions::any( 'to ada@example.com', array( ' ADA@example.com ' ) ) );
	}

	/**
	 * json_encode writes `/` as `\/`, so the address as typed is not in
	 * the payload that carries it.
	 */
	public function test_an_address_is_found_as_json_wrote_it(): void {
		$payload = (string) json_encode( array( 'email' => 'a/b@example.com' ) );

		$this->assertStringNotContainsString( 'a/b@example.com', $payload );
		$this->assertTrue( Mentions::any( $payload, array( 'a/b@example.com' ) ) );
	}

	public function test_nothing_mentions_an_empty_value(): void {
		$this->assertFalse( Mentions::any( 'anything at all', array( '', '  ' ) ) );
		$this->assertFalse( Mentions::any( 'other@example.com', array( 'ada@example.com' ) ) );
	}

	public function test_keys_are_distinct_folded_and_non_empty(): void {
		$this->assertSame( array( 'ada@example.com', 'bo@example.com' ), Mentions::keys( array( 'Ada@Example.com', '', ' ada@example.com', 'bo@example.com', null ) ) );
		$this->assertSame( 'ada@example.com', Mentions::key( "  ADA@example.com\n" ) );
	}

	public function test_strip_replaces_every_address_in_free_text(): void {
		$this->assertSame(
			'550 <[address]>: mailbox full; cc [address], "[address]"',
			Mentions::strip( '550 <Ada@Example.com>: mailbox full; cc bo@example.org, "c.d+tag@example.net"' )
		);
		$this->assertSame( 'Delivery failed', Mentions::strip( 'Delivery failed' ) );
	}

	public function test_strip_replaces_an_address_as_json_wrote_it(): void {
		$payload = (string) json_encode( array( 'to' => 'a/b@exämple.com' ) );

		$this->assertSame( '{"to":"[address]"}', Mentions::strip( $payload ) );
		$this->assertFalse( Mentions::any( Mentions::strip( $payload ), array( 'a/b@exämple.com' ) ) );
	}

	public function test_strip_takes_the_replacement_literally(): void {
		$this->assertSame( 'to $0 \1', Mentions::strip( 'to ada@example.com \1', '$0' ) );
		$this->assertSame( 'to ', Mentions::strip( 'to ada@example.com', '' ) );
	}

	public function test_strip_still_strips_text_that_is_not_utf8(): void {
		$this->assertSame( "\xff [address]", Mentions::strip( "\xff ada@example.com" ) );
	}

	/* ─── Tally ────────────────────────────────────────────────────────── */

	public function test_a_summary_leaves_out_the_noughts(): void {
		$this->assertSame( '2 orders, 1 licenses', Tally::summary( array( 'orders' => 2, 'gifts' => 0, 'licenses' => 1 ) ) );
		$this->assertSame( Tally::NOTHING, Tally::summary( array( 'orders' => 0 ) ) );
		$this->assertSame( 'none', Tally::summary( array(), 'none' ) );
	}

	public function test_counts_are_taken_by_kind(): void {
		$this->assertSame( array( 'orders' => 2, 'reports' => 0 ), Tally::count( array( 'orders' => array( 1, 2 ), 'reports' => array() ) ) );
	}

	public function test_a_note_is_the_summary_then_the_reason(): void {
		$this->assertSame( '1 orders — ticket 4411', Tally::note( array( 'orders' => 1 ), ' ticket 4411 ' ) );
		$this->assertSame( 'nothing held', Tally::note( array(), '' ) );
	}

	/* ─── Pseudonym ────────────────────────────────────────────────────── */

	public function test_the_subject_is_the_start_of_the_digest(): void {
		$digest = hash_hmac( 'sha256', 'ada@example.com', 'key' );

		$this->assertSame( substr( $digest, 0, 16 ), Pseudonym::subject( $digest ) );
		$this->assertSame( substr( $digest, 0, 8 ), Pseudonym::subject( strtoupper( $digest ), 8 ) );
	}

	public function test_an_address_is_never_a_subject(): void {
		$this->assertSame( '', Pseudonym::subject( 'ada@example.com' ) );
		$this->assertSame( '', Pseudonym::subject( '' ) );
	}

	/**
	 * SQLite column names are case-insensitive, so `Access_Token` is the
	 * same column and must go with `access_token`.
	 */
	public function test_redaction_ignores_case(): void {
		$rows = array( array( 'id' => 1, 'Access_Token' => 'secret' ) );

		$this->assertSame( array( array( 'id' => 1 ) ), Redact::rows( $rows, 'access_token' ) );
	}

	/**
	 * The allow-list form: a column added later stays out.
	 */
	public function test_only_keeps_the_named_columns(): void {
		$keep = Redact::only( 'ID', 'amount' );

		$this->assertSame( array( 'id' => 1, 'amount' => 900 ), $keep( array( 'id' => 1, 'amount' => 900, 'new_secret' => 'x' ) ) );
	}

	/**
	 * Written with JSON_UNESCAPED_UNICODE, the slash is escaped and the
	 * accent is not; written by default, both are. Either way it is the
	 * address.
	 */
	public function test_every_json_form_of_an_address_is_found(): void {
		$address = 'josé/ops@example.com';

		$this->assertTrue( Mentions::any( (string) json_encode( array( 'to' => $address ) ), array( $address ) ) );
		$this->assertTrue( Mentions::any( (string) json_encode( array( 'to' => $address ), JSON_UNESCAPED_UNICODE ), array( $address ) ) );
		$this->assertTrue( Mentions::any( (string) json_encode( array( 'to' => $address ), JSON_UNESCAPED_SLASHES ), array( $address ) ) );
	}

	/**
	 * Case is folded on the text, and an escape is not a letter: a capital
	 * JSON wrote as `É` stays `É`, which is not the `é` of
	 * the address as the person typed it today. So the queued payload for
	 * Élodie@example.com was missed by an erasure for élodie@example.com.
	 */
	public function test_an_address_json_escaped_in_capitals_is_found(): void {
		$this->assertTrue( Mentions::any( (string) json_encode( array( 'to' => 'Élodie@Example.com' ) ), array( 'élodie@example.com' ) ) );
		$this->assertTrue( Mentions::any( (string) json_encode( array( 'to' => 'élodie@example.com' ) ), array( 'ÉLODIE@example.com' ) ) );
		$this->assertTrue( Mentions::any( '{"to":"ÉLODIE\/OPS@EXAMPLE.COM"}', array( 'élodie/ops@example.com' ) ) );
		$this->assertTrue( Mentions::any( '{"to":"\u00C9\ud83d\ude00@example.com"}', array( 'é😀@example.com' ) ) );
		$this->assertFalse( Mentions::any( '{"to":"Émile@example.com"}', array( 'élodie@example.com' ) ) );
		$this->assertFalse( Mentions::any( '{"to":"\ud800 broken"}', array( 'ada@example.com' ) ) );
	}

	/**
	 * The pattern for an address used to try every position of a long run
	 * of characters for an @ at its end: without PCRE's JIT, 50 KB of a
	 * logged payload took fifteen seconds.
	 */
	public function test_strip_costs_what_reading_the_text_costs(): void {
		$jit = ini_get( 'pcre.jit' );

		ini_set( 'pcre.jit', '0' );

		try {
			$from = microtime( true );
			$long = str_repeat( 'a', 50000 ) . '@';

			$this->assertSame( $long, Mentions::strip( $long ) );
			$this->assertSame( "\xff" . $long, Mentions::strip( "\xff" . $long ) );
			$this->assertSame( 'x [address] y', Mentions::strip( 'x ' . str_repeat( 'a', 50000 ) . '@example.com y' ) );
			$this->assertLessThan( 1.0, microtime( true ) - $from );
		} finally {
			ini_set( 'pcre.jit', (string) $jit );
		}
	}

	/**
	 * Every match starts where a run of address characters starts, as it
	 * did before the pattern was told so.
	 */
	public function test_strip_replaces_whole_runs(): void {
		$this->assertSame( '[address] [address] @ x@ [address]', Mentions::strip( 'a@b @@b @ x@ a@b@c' ) );
		$this->assertSame( '<[address]>,([address])', Mentions::strip( '<a.b@c.d>,(e@f)' ) );
	}

	/* ─── Consent ──────────────────────────────────────────────────────── */

	/**
	 * @return array<string, array{mixed, Consent, string}>
	 */
	public static function answers(): array {
		return array(
			'yes'        => array( 1, Consent::Yes, 'yes' ),
			'yes text'   => array( '1', Consent::Yes, 'yes' ),
			'no'         => array( 0, Consent::No, 'no' ),
			'no text'    => array( '0', Consent::No, 'no' ),
			'not asked'  => array( null, Consent::NotAsked, '' ),
			'empty'      => array( '', Consent::NotAsked, '' ),
			'true'       => array( true, Consent::Yes, 'yes' ),
			// Only casts to 1: not unambiguous agreement.
			'1abc'       => array( '1abc', Consent::No, 'no' ),
			'01'         => array( '01', Consent::No, 'no' ),
			'1.5'        => array( 1.5, Consent::No, 'no' ),
			'an array'   => array( array( 'x' ), Consent::No, 'no' ),
		);
	}

	#[DataProvider( 'answers' )]
	public function test_not_asked_is_never_no( mixed $stored, Consent $answer, string $cell ): void {
		$this->assertSame( $answer, Consent::of( $stored ) );
		$this->assertSame( $cell, Consent::of( $stored )->cell() );
	}

	public function test_the_reading_says_not_asked(): void {
		$this->assertSame( 'not asked', Consent::of( null )->value );
	}
}
