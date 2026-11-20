<?php
/**
 * One page of a group's export, in the shape WordPress wants.
 *
 * @package   SugarCommerce\Privacy
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Privacy\Traits\PersonalData;

use BackedEnum;
use SugarCommerce\Privacy\Consent;
use SugarCommerce\Privacy\Redact;
use Stringable;

/**
 * Trait Exporting
 *
 * The rows a group's `find` returns, as the name/value pairs an export
 * shows: only the fields named, or every column but the redacted.
 *
 * @since 1.0.0
 */
trait Exporting {

	/**
	 * One page of a group's export, in the shape WordPress wants.
	 *
	 * Done when the page came back short. A row with nothing to show — every
	 * field empty — is left out rather than exported as a blank item.
	 *
	 * @since 1.0.0
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
	 * The name/value pairs a row exports.
	 *
	 * With fields, those and only those — an allow-list, so a column added
	 * to the table later stays out. Without, every column but the redacted.
	 * Columns are matched whatever their case, as Redact matches them.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * A string is always a column. Asked whether it was callable first,
	 * `key`, `date`, `count` and every other column named like a PHP
	 * function were called as that function: `key` gave every row the
	 * same id, which WordPress merges into one item.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed                   $item_id  A column or a callable.
	 * @param array<array-key, mixed> $row      The row.
	 * @param string                  $fallback Its place, when it has no id.
	 *
	 * @return string
	 */
	private static function item_id( mixed $item_id, array $row, string $fallback ): string {
		$value = ! is_string( $item_id ) && is_callable( $item_id )
			? $item_id( $row )
			: ( array_change_key_case( $row )[ strtolower( (string) $item_id ) ] ?? null );

		return is_scalar( $value ) && '' !== (string) $value ? (string) $value : $fallback;
	}

	/**
	 * A nested value as JSON.
	 *
	 * @since 1.0.0
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
}
