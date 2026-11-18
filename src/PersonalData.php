<?php
/**
 * A plugin's personal data, registered with WordPress's privacy tools.
 *
 * @package   SugarCommerce\Privacy
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     2.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Privacy;

use BackedEnum;
use InvalidArgumentException;
use Stringable;

/**
 * Class PersonalData
 *
 * WordPress answers an export or erasure request under Tools by calling an
 * exporter and an eraser for every group of data each plugin holds, a page
 * at a time, and each has its own array shape to return. Written by hand
 * that is two callbacks per table, each paging, labelling and stringifying
 * the same way, and each one place to forget a column that must not leave.
 *
 * Here a group is an array: what to call it, how to find a page of rows
 * for an address, which columns to show under which label, and how to erase
 * them. One call registers the lot.
 *
 *     ( new PersonalData( 'myplugin', 'My Plugin', array(
 *         'orders' => array(
 *             'label'  => __( 'Orders', 'myplugin' ),
 *             'find'   => fn ( string $email, int $page, int $per_page ): array => $rows,
 *             'fields' => array( 'id' => __( 'Order', 'myplugin' ), 'total' => __( 'Total', 'myplugin' ) ),
 *             'erase'  => fn ( string $email, int $page ): array => array( 'removed' => 2 ),
 *         ),
 *     ) ) )->register();
 *
 * Other code can add groups through the `{id}_personal_data_groups` filter,
 * so an add-on's table is exported and erased with the plugin's own.
 *
 * @since 2.0.0
 */
final class PersonalData {

	/**
	 * Rows asked for per page when a group does not say.
	 *
	 * @since 2.0.0
	 *
	 * @var int
	 */
	public const int PER_PAGE = 100;

	/**
	 * The groups, as given.
	 *
	 * @since 2.0.0
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $groups = array();

	/**
	 * Whether register() has hooked in.
	 *
	 * @since 2.0.0
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Set up a plugin's personal data.
	 *
	 * @since 2.0.0
	 *
	 * @param string                              $id     The plugin's prefix: lower-case
	 *                                                    letters, digits, `_` and `-`.
	 *                                                    Names the exporters, the
	 *                                                    erasers and the filter.
	 * @param string                              $name   The plugin's name, put before
	 *                                                    each group's label where
	 *                                                    WordPress names an exporter.
	 * @param array<string, array<string, mixed>> $groups Key => group. See add().
	 *
	 * @throws InvalidArgumentException When the id is not a plain slug.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $name = '',
		array $groups = array()
	) {
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/D', $id ) ) {
			throw new InvalidArgumentException( 'A personal data id is lower-case letters, digits, underscores and hyphens.' );
		}

		foreach ( $groups as $key => $group ) {
			$this->add( (string) $key, $group );
		}
	}

	/**
	 * Add a group.
	 *
	 * - `label`            (string, required) What the export calls the group.
	 * - `description`      (string) A line under the label in the export.
	 * - `find`             (callable) `( string $email, int $page, int $per_page )`
	 *                      to the rows on that page (arrays or objects). No
	 *                      `find`, no exporter.
	 * - `fields`           (array) Column => label, or column => array with
	 *                      `label`, and `value` (a callable given the row) or
	 *                      `consent` (true to show Yes, No or Not asked). Only
	 *                      these are exported. Without it every column is,
	 *                      under its own name, minus `redact`.
	 * - `redact`           (list<string>) Columns never exported, when
	 *                      `fields` is not given.
	 * - `item_id`          (string|callable) The column, or a callable given
	 *                      the row, that tells two rows apart. Default `id`.
	 * - `per_page`         (int) Rows asked for per page. Default 100.
	 * - `erase`            (callable) `( string $email, int $page )` to an
	 *                      array of `removed` and `retained` (counts or
	 *                      bools), `messages` and `done` (default true), or a
	 *                      count of rows removed. No `erase`, no eraser.
	 * - `retained_message` (string) Said when anything was retained, such as
	 *                      why orders are kept.
	 * - `group_id`         (string) The export's group id. Default `{id}-{key}`.
	 *
	 * @since 2.0.0
	 *
	 * @param string               $key   The group's key, unique to this plugin.
	 * @param array<string, mixed> $group The group.
	 *
	 * @return self
	 */
	public function add( string $key, array $group ): self {
		$this->groups[ $key ] = $group;

		return $this;
	}

	/**
	 * Hook the exporters and erasers into WordPress.
	 *
	 * Safe to call more than once. The groups are read when WordPress asks,
	 * so a group added later — or by the filter — is still registered.
	 *
	 * @since 2.0.0
	 *
	 * @return self
	 */
	public function register(): self {
		if ( ! $this->registered ) {
			add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );

			$this->registered = true;
		}

		return $this;
	}

	/**
	 * The groups, after the filter, each checked and filled in.
	 *
	 * A group without a label, or with neither `find` nor `erase`, is
	 * reported through _doing_it_wrong() and left out.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function groups(): array {
		/**
		 * Filter a plugin's personal data groups.
		 *
		 * @param array<string, array<string, mixed>> $groups Key => group.
		 */
		$groups = apply_filters( $this->id . '_personal_data_groups', $this->groups );
		$valid  = array();

		foreach ( is_array( $groups ) ? $groups : array() as $key => $group ) {
			$key   = (string) $key;
			$group = is_array( $group ) ? $group : array();
			$label = isset( $group['label'] ) && is_string( $group['label'] ) ? trim( $group['label'] ) : '';
			$find  = isset( $group['find'] ) && is_callable( $group['find'] ) ? $group['find'] : null;
			$erase = isset( $group['erase'] ) && is_callable( $group['erase'] ) ? $group['erase'] : null;

			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/D', $key ) || '' === $label || ( null === $find && null === $erase ) ) {
				self::wrong( sprintf( 'The personal data group "%s" needs a slug for a key, a label, and a find or erase callback.', $key ) );
				continue;
			}

			$valid[ $key ] = array(
				'label'            => $label,
				'description'      => (string) ( $group['description'] ?? '' ),
				'find'             => $find,
				'fields'           => self::fields( (array) ( $group['fields'] ?? array() ) ),
				'redact'           => array_map( 'strval', (array) ( $group['redact'] ?? array() ) ),
				'item_id'          => $group['item_id'] ?? 'id',
				'per_page'         => max( 1, (int) ( $group['per_page'] ?? self::PER_PAGE ) ),
				'erase'            => $erase,
				'retained_message' => (string) ( $group['retained_message'] ?? '' ),
				'group_id'         => (string) ( $group['group_id'] ?? $this->id . '-' . $key ),
			);
		}

		return $valid;
	}

	/**
	 * Add this plugin's exporters to WordPress's list.
	 *
	 * The `wp_privacy_personal_data_exporters` filter.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $exporters Exporters so far.
	 *
	 * @return array<string, array{exporter_friendly_name: string, callback: callable}>
	 */
	public function exporters( mixed $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();

		foreach ( $this->groups() as $key => $group ) {
			if ( null !== $group['find'] ) {
				$exporters[ $this->id . '-' . $key ] = array(
					'exporter_friendly_name' => $this->friendly( $group['label'] ),
					'callback'               => fn ( $email, $page = 1 ): array => $this->export( $key, (string) $email, (int) $page ),
				);
			}
		}

		return $exporters;
	}

	/**
	 * Add this plugin's erasers to WordPress's list.
	 *
	 * The `wp_privacy_personal_data_erasers` filter.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $erasers Erasers so far.
	 *
	 * @return array<string, array{eraser_friendly_name: string, callback: callable}>
	 */
	public function erasers( mixed $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();

		foreach ( $this->groups() as $key => $group ) {
			if ( null !== $group['erase'] ) {
				$erasers[ $this->id . '-' . $key ] = array(
					'eraser_friendly_name' => $this->friendly( $group['label'] ),
					'callback'             => fn ( $email, $page = 1 ): array => $this->erase( $key, (string) $email, (int) $page ),
				);
			}
		}

		return $erasers;
	}

	/**
	 * One page of a group's export, in the shape WordPress wants.
	 *
	 * Done when the page came back short. A row with nothing to show — every
	 * field empty — is left out rather than exported as a blank item.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key   The group.
	 * @param string $email The address the request is for.
	 * @param int    $page  The page, from 1.
	 *
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $key, string $email, int $page = 1 ): array {
		$group = $this->groups()[ $key ] ?? null;
		$email = trim( $email );

		if ( null === $group || null === $group['find'] || '' === $email ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page  = max( 1, $page );
		$found = ( $group['find'] )( $email, $page, $group['per_page'] );
		$rows  = is_iterable( $found ) ? array_values( is_array( $found ) ? $found : iterator_to_array( $found, false ) ) : array();
		$data  = array();

		foreach ( $rows as $index => $row ) {
			$row   = self::row( $row );
			$pairs = self::pairs( $group, $row );

			if ( array() === $pairs ) {
				continue;
			}

			$data[] = array(
				'group_id'          => $group['group_id'],
				'group_label'       => $group['label'],
				'group_description' => $group['description'],
				'item_id'           => $key . '-' . self::item_id( $group['item_id'], $row, $page . '-' . $index ),
				'data'              => $pairs,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < $group['per_page'],
		);
	}

	/**
	 * One page of a group's erasure, in the shape WordPress wants.
	 *
	 * @since 2.0.0
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

	/**
	 * Fields as column => array{label, value, consent}.
	 *
	 * @since 2.0.0
	 *
	 * @param array<array-key, mixed> $fields As given.
	 *
	 * @return array<string, array{label: string, value: callable|null, consent: bool}>
	 */
	private static function fields( array $fields ): array {
		$clean = array();

		foreach ( $fields as $column => $field ) {
			$field = is_array( $field ) ? $field : array( 'label' => $field );

			$clean[ (string) $column ] = array(
				'label'   => is_scalar( $field['label'] ?? null ) && '' !== (string) $field['label'] ? (string) $field['label'] : (string) $column,
				'value'   => isset( $field['value'] ) && is_callable( $field['value'] ) ? $field['value'] : null,
				'consent' => ! empty( $field['consent'] ),
			);
		}

		return $clean;
	}

	/**
	 * The name/value pairs a row exports.
	 *
	 * With fields, those and only those — an allow-list, so a column added
	 * to the table later stays out. Without, every column but the redacted.
	 * Columns are matched whatever their case, as Redact matches them.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed>    $group The group.
	 * @param array<array-key, mixed> $row   The row.
	 *
	 * @return list<array{name: string, value: string}>
	 */
	private static function pairs( array $group, array $row ): array {
		$pairs = array();

		if ( array() === $group['fields'] ) {
			foreach ( Redact::rows( array( $row ), ...$group['redact'] )[0] as $column => $value ) {
				$text = self::text( $value );

				if ( '' !== $text ) {
					$pairs[] = array(
						'name'  => (string) $column,
						'value' => $text,
					);
				}
			}

			return $pairs;
		}

		$columns = array_change_key_case( $row );

		foreach ( $group['fields'] as $column => $field ) {
			$value = null === $field['value'] ? ( $columns[ strtolower( $column ) ] ?? null ) : ( $field['value'] )( $row );
			$text  = $field['consent'] ? Consent::of( $value )->label() : self::text( $value );

			if ( '' !== $text ) {
				$pairs[] = array(
					'name'  => $field['label'],
					'value' => $text,
				);
			}
		}

		return $pairs;
	}

	/**
	 * A value as the text an export shows.
	 *
	 * Booleans are Yes and No in the site's language; a list or an object is
	 * JSON, since an export is no place to lose a nested value; nothing is ''.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	public static function text( mixed $value ): string {
		return match ( true ) {
			null === $value                                        => '',
			is_bool( $value )                                      => $value ? __( 'Yes', 'sugarcommerce' ) : __( 'No', 'sugarcommerce' ),
			is_scalar( $value ) || $value instanceof Stringable    => trim( (string) $value ),
			$value instanceof BackedEnum                           => (string) $value->value,
			default                                                => self::json( $value ),
		};
	}

	/**
	 * A row as an array: an object's public properties, or nothing.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $row A row as `find` returned it.
	 *
	 * @return array<array-key, mixed>
	 */
	private static function row( mixed $row ): array {
		return match ( true ) {
			is_array( $row )  => $row,
			is_object( $row ) => get_object_vars( $row ),
			default           => array(),
		};
	}

	/**
	 * What tells a row apart from the others in its group.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed                   $item_id  A column or a callable.
	 * @param array<array-key, mixed> $row      The row.
	 * @param string                  $fallback Its place, when it has no id.
	 *
	 * @return string
	 */
	private static function item_id( mixed $item_id, array $row, string $fallback ): string {
		$value = is_callable( $item_id )
			? $item_id( $row )
			: ( array_change_key_case( $row )[ strtolower( (string) $item_id ) ] ?? null );

		return is_scalar( $value ) && '' !== (string) $value ? (string) $value : $fallback;
	}

	/**
	 * A nested value as JSON.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $value An array or object.
	 *
	 * @return string
	 */
	private static function json( mixed $value ): string {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
		$json  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, $flags ) : json_encode( $value, $flags );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * What WordPress calls an exporter or eraser: the plugin, then the group.
	 *
	 * @since 2.0.0
	 *
	 * @param string $label The group's label.
	 *
	 * @return string
	 */
	private function friendly( string $label ): string {
		return '' === $this->name ? $label : $this->name . ' ' . $label;
	}

	/**
	 * Report a group that cannot be registered.
	 *
	 * @since 2.0.0
	 *
	 * @param string $message What is wrong.
	 *
	 * @return void
	 */
	private static function wrong( string $message ): void {
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( __CLASS__, esc_html( $message ), '2.0.0' );
		}
	}
}
