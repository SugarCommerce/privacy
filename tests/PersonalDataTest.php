<?php
/**
 * PersonalData tests.
 *
 * @package SugarCommerce\Privacy
 */

declare( strict_types=1 );

namespace SugarCommerce\Privacy\Tests;

use ArrayIterator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Privacy\Consent;
use SugarCommerce\Privacy\PersonalData;
use SugarCommerce\Privacy\Pseudonym;
use SugarCommerce\Privacy\Tally;

/**
 * A plugin's groups, from arrays, in the shapes WordPress's export and
 * erasure tools call for — and never a column nobody asked to hand over.
 */
final class PersonalDataTest extends TestCase {

	/**
	 * Orders held for ada@example.com.
	 *
	 * @var list<array<string, mixed>>
	 */
	private const array ORDERS = array(
		array(
			'id'                => 7,
			'total'             => '£12.00',
			'access_token'      => 'secret-token',
			'marketing_consent' => null,
			'notes'             => '',
		),
		array(
			'id'                => 9,
			'total'             => '£4.50',
			'access_token'      => 'other-token',
			'marketing_consent' => 1,
			'notes'             => 'Gift',
		),
	);

	protected function setUp(): void {
		pv_reset();
	}

	/**
	 * A group of orders, paged two at a time.
	 *
	 * @param array<string, mixed> $extra Keys to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function orders( array $extra = array() ): array {
		return $extra + array(
			'label'       => 'Orders',
			'description' => 'Orders placed with this address.',
			'find'        => static fn ( string $email, int $page, int $per_page ): array => 'ada@example.com' === $email
				? array_slice( self::ORDERS, ( $page - 1 ) * $per_page, $per_page )
				: array(),
			'fields'      => array(
				'id'                => 'Order',
				'total'             => 'Total',
				'notes'             => 'Notes',
				'marketing_consent' => array(
					'label'   => 'Marketing',
					'consent' => true,
				),
			),
			'per_page'    => 2,
		);
	}

	public function test_register_hooks_both_lists_once(): void {
		$data = ( new PersonalData( 'shop', 'Shop', array( 'orders' => self::orders() ) ) )->register()->register();

		$this->assertCount( 1, $GLOBALS['pv_filters']['wp_privacy_personal_data_exporters'] );
		$this->assertCount( 1, $GLOBALS['pv_filters']['wp_privacy_personal_data_erasers'] );

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array( 'core' => array() ) );

		$this->assertArrayHasKey( 'core', $exporters, 'The other plugins are kept.' );
		$this->assertSame( 'Shop Orders', $exporters['shop-orders']['exporter_friendly_name'] );
		$this->assertSame( array( 'core' => array() ), apply_filters( 'wp_privacy_personal_data_erasers', array( 'core' => array() ) ), 'No erase, no eraser.' );
		$this->assertInstanceOf( PersonalData::class, $data );
	}

	public function test_an_export_page_is_in_wordpress_shape(): void {
		$data   = new PersonalData( 'shop', '', array( 'orders' => self::orders() ) );
		$export = $data->exporters( array() )['shop-orders']['callback']( 'ada@example.com', 1 );

		$this->assertFalse( $export['done'], 'A full page may have more after it.' );
		$this->assertSame(
			array(
				'group_id'          => 'shop-orders',
				'group_label'       => 'Orders',
				'group_description' => 'Orders placed with this address.',
				'item_id'           => 'orders-7',
				'data'              => array(
					array(
						'name'  => 'Order',
						'value' => '7',
					),
					array(
						'name'  => 'Total',
						'value' => '£12.00',
					),
					array(
						'name'  => 'Marketing',
						'value' => 'Not asked',
					),
				),
			),
			$export['data'][0]
		);
		$this->assertSame( 'Yes', $export['data'][1]['data'][3]['value'] );
		$this->assertSame( 'Gift', $export['data'][1]['data'][2]['value'] );
	}

	public function test_fields_are_an_allow_list(): void {
		$export = ( new PersonalData( 'shop', '', array( 'orders' => self::orders() ) ) )->export( 'orders', 'ada@example.com' );

		$this->assertStringNotContainsString( 'token', (string) json_encode( $export ) );
	}

	public function test_without_fields_every_column_but_the_redacted_is_exported(): void {
		$group = self::orders(
			array(
				'fields' => array(),
				'redact' => array( 'ACCESS_TOKEN' ),
			)
		);

		$export = ( new PersonalData( 'shop', '', array( 'orders' => $group ) ) )->export( 'orders', 'ada@example.com' );
		$names  = array_column( $export['data'][1]['data'], 'name' );

		$this->assertSame( array( 'id', 'total', 'marketing_consent', 'notes' ), $names );
		$this->assertStringNotContainsString( 'token', (string) json_encode( $export ) );
	}

	public function test_paging_ends_on_a_short_page(): void {
		$data = new PersonalData( 'shop', '', array( 'orders' => self::orders( array( 'per_page' => 1 ) ) ) );

		$this->assertFalse( $data->export( 'orders', 'ada@example.com', 1 )['done'] );
		$this->assertSame( 'orders-9', $data->export( 'orders', 'ada@example.com', 2 )['data'][0]['item_id'] );
		$this->assertTrue( $data->export( 'orders', 'ada@example.com', 3 )['done'] );
		$this->assertTrue( $data->export( 'orders', 'ada@example.com', 3 )['done'] );
		$this->assertSame( array(), $data->export( 'orders', 'ada@example.com', 3 )['data'] );
	}

	public function test_find_is_given_the_address_page_and_size(): void {
		$asked = array();
		$group = self::orders(
			array(
				'find'     => static function ( string $email, int $page, int $per_page ) use ( &$asked ): array {
					$asked = array( $email, $page, $per_page );

					return array();
				},
				'per_page' => 25,
			)
		);

		( new PersonalData( 'shop', '', array( 'orders' => $group ) ) )->export( 'orders', ' ada@example.com ', 0 );

		$this->assertSame( array( 'ada@example.com', 1, 25 ), $asked );
	}

	public function test_a_blank_address_finds_nothing(): void {
		$called = false;
		$group  = self::orders(
			array(
				'find' => static function () use ( &$called ): array {
					$called = true;

					return self::ORDERS;
				},
			)
		);

		$export = ( new PersonalData( 'shop', '', array( 'orders' => $group ) ) )->export( 'orders', '  ' );

		$this->assertFalse( $called );
		$this->assertTrue( $export['done'] );
	}

	public function test_values_become_text(): void {
		$this->assertSame( '', PersonalData::text( null ) );
		$this->assertSame( 'Yes', PersonalData::text( true ) );
		$this->assertSame( 'No', PersonalData::text( false ) );
		$this->assertSame( '0', PersonalData::text( 0 ) );
		$this->assertSame( '{"city":"Dún Laoghaire","url":"https://x.test/a"}', PersonalData::text( array( 'city' => 'Dún Laoghaire', 'url' => 'https://x.test/a' ) ) );
		$this->assertSame( 'not asked', PersonalData::text( Consent::NotAsked ) );
	}

	public function test_objects_iterators_and_value_callbacks_work(): void {
		$group = array(
			'label'   => 'Licences',
			'find'    => static fn (): ArrayIterator => new ArrayIterator( array( (object) array( 'key' => 'K-1', 'sites' => 3 ) ) ),
			'fields'  => array(
				'sites' => array(
					'label' => 'Sites',
					'value' => static fn ( array $row ): string => $row['sites'] . ' of 5',
				),
			),
			'item_id' => static fn ( array $row ): string => $row['key'],
		);

		$item = ( new PersonalData( 'shop', '', array( 'licences' => $group ) ) )->export( 'licences', 'ada@example.com' )['data'][0];

		$this->assertSame( 'licences-K-1', $item['item_id'] );
		$this->assertSame( '3 of 5', $item['data'][0]['value'] );
	}

	/**
	 * A column named like a PHP function -- key, date, count, current -- was
	 * called as that function: `key` gave every row the same id, so
	 * WordPress merged them into one item, and `date` failed the export.
	 */
	#[DataProvider( 'function_names' )]
	public function test_an_item_id_column_is_a_column_whatever_it_is_called( string $column ): void {
		$group = array(
			'label'   => 'Licences',
			'find'    => static fn (): array => array( array( $column => 'K-1' ), array( $column => 'K-2' ) ),
			'item_id' => $column,
		);

		$data = ( new PersonalData( 'shop', '', array( 'licences' => $group ) ) )->export( 'licences', 'ada@example.com' )['data'];

		$this->assertSame( array( 'licences-K-1', 'licences-K-2' ), array_column( $data, 'item_id' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function function_names(): array {
		return array(
			'key'     => array( 'key' ),
			'date'    => array( 'date' ),
			'count'   => array( 'count' ),
			'current' => array( 'current' ),
		);
	}

	public function test_an_item_id_callable_is_still_called(): void {
		$group = array(
			'label'   => 'Licences',
			'find'    => static fn (): array => array( array( 'k' => 'a' ) ),
			'item_id' => array( new class() {
				/**
				 * @param array<string, mixed> $row The row.
				 */
				public function id( array $row ): string {
					return strtoupper( (string) $row['k'] );
				}
			}, 'id' ),
		);

		$data = ( new PersonalData( 'shop', '', array( 'licences' => $group ) ) )->export( 'licences', 'ada@example.com' )['data'];

		$this->assertSame( 'licences-A', $data[0]['item_id'] );
	}

	public function test_a_row_with_nothing_to_show_is_left_out_and_no_id_is_its_place(): void {
		$group = array(
			'label'  => 'Notes',
			'find'   => static fn (): array => array( array( 'Text' => '' ), array( 'Text' => 'Hi' ) ),
			'fields' => array( 'TEXT' => 'Note' ),
		);

		$export = ( new PersonalData( 'shop', '', array( 'notes' => $group ) ) )->export( 'notes', 'ada@example.com', 2 );

		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'notes-2-1', $export['data'][0]['item_id'], 'Columns match whatever their case.' );
	}

	public function test_an_erasure_page_is_in_wordpress_shape(): void {
		$group = self::orders(
			array(
				'erase'            => static fn ( string $email, int $page ): array => array(
					'removed'  => 2,
					'retained' => 1,
					'messages' => array( 'Order 7 anonymised.', '' ),
				),
				'retained_message' => 'Orders are kept as financial records.',
			)
		);

		$data   = ( new PersonalData( 'shop', 'Shop', array( 'orders' => $group ) ) )->register();
		$eraser = apply_filters( 'wp_privacy_personal_data_erasers', array() )['shop-orders'];

		$this->assertSame( 'Shop Orders', $eraser['eraser_friendly_name'] );
		$this->assertSame(
			array(
				'items_removed'  => true,
				'items_retained' => true,
				'messages'       => array( 'Order 7 anonymised.', 'Orders are kept as financial records.' ),
				'done'           => true,
			),
			$eraser['callback']( 'ada@example.com', 1 )
		);
		$this->assertInstanceOf( PersonalData::class, $data );
	}

	public function test_an_erasure_can_answer_with_a_count_or_ask_for_another_page(): void {
		$data = new PersonalData(
			'shop',
			'',
			array(
				'count' => array(
					'label' => 'Count',
					'erase' => static fn (): int => 0,
				),
				'more'  => array(
					'label'            => 'More',
					'erase'            => static fn ( string $email, int $page ): array => array( 'done' => $page >= 2 ),
					'retained_message' => 'Kept.',
				),
			)
		);

		$this->assertSame(
			array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$data->erase( 'count', 'ada@example.com' )
		);
		$this->assertFalse( $data->erase( 'more', 'ada@example.com', 1 )['done'] );
		$this->assertTrue( $data->erase( 'more', 'ada@example.com', 2 )['done'] );
		$this->assertSame( array(), $data->erase( 'more', 'ada@example.com' )['messages'], 'Nothing retained, nothing said.' );
		$this->assertTrue( $data->erase( 'nope', 'ada@example.com' )['done'] );
	}

	public function test_add_ons_add_groups_through_the_filter(): void {
		$data = ( new PersonalData( 'shop', 'Shop' ) )->add( 'orders', self::orders() )->register();

		add_filter(
			'shop_personal_data_groups',
			static fn ( array $groups ): array => $groups + array(
				'reviews' => array(
					'label' => 'Reviews',
					'find'  => static fn (): array => array( array( 'id' => 1 ) ),
				),
			)
		);

		$this->assertSame( array( 'shop-orders', 'shop-reviews' ), array_keys( apply_filters( 'wp_privacy_personal_data_exporters', array() ) ) );
		$this->assertSame( array( 'orders', 'reviews' ), array_keys( $data->groups() ) );
	}

	public function test_a_broken_group_is_reported_and_left_out(): void {
		$data = new PersonalData(
			'shop',
			'',
			array(
				'no-label'   => array( 'find' => static fn (): array => array() ),
				'no-work'    => array( 'label' => 'Nothing' ),
				'Bad Key'    => array(
					'label' => 'Bad',
					'find'  => static fn (): array => array(),
				),
				'good'       => array(
					'label' => 'Good',
					'erase' => static fn (): int => 1,
				),
			)
		);

		$this->assertSame( array( 'good' ), array_keys( $data->groups() ) );
		$this->assertCount( 3, $GLOBALS['pv_wrong'] );
		$this->assertStringContainsString( '&quot;Bad Key&quot;', $GLOBALS['pv_wrong'][2], 'The complaint is escaped.' );
	}

	public function test_the_id_is_a_plain_slug(): void {
		$this->expectException( InvalidArgumentException::class );

		new PersonalData( 'My Shop' );
	}

	public function test_group_ids_default_to_the_plugin_and_can_be_shared(): void {
		$data = new PersonalData(
			'shop',
			'',
			array(
				'orders' => self::orders( array( 'group_id' => 'orders' ) ),
			)
		);

		$this->assertSame( 'orders', $data->export( 'orders', 'ada@example.com' )['data'][0]['group_id'] );
	}

	public function test_a_pseudonym_for_an_address_is_keyed_and_folded(): void {
		$expected = substr( hash_hmac( 'sha256', 'ada@example.com', wp_salt( 'auth' ) ), 0, 16 );

		$this->assertSame( $expected, Pseudonym::for_address( ' Ada@Example.com ' ) );
		$this->assertSame( substr( hash_hmac( 'sha256', 'ada@example.com', 'k' ), 0, 8 ), Pseudonym::for_address( 'ada@example.com', 'k', 8 ) );
		$this->assertNotSame( substr( hash( 'sha256', 'ada@example.com' ), 0, 16 ), Pseudonym::for_address( 'ada@example.com' ) );
		$this->assertSame( '', Pseudonym::for_address( '' ) );
	}

	public function test_a_pseudonym_refuses_an_empty_key(): void {
		$this->expectException( LogicException::class );

		Pseudonym::for_address( 'ada@example.com', '' );
	}

	public function test_consent_and_nothing_are_said_in_the_sites_language(): void {
		$this->assertSame( 'Not asked', Consent::NotAsked->label() );
		$this->assertSame( 'Yes', Consent::Yes->label() );
		$this->assertSame( 'No', Consent::No->label() );
		$this->assertSame( Tally::NOTHING, Tally::summary( array() ) );
	}
}
