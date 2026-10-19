<?php
/**
 * Writing CSV a spreadsheet will not execute.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One definition of how this plugin writes a CSV row.
 *
 * Shared because there are two exports — a repeater's rows and a form's
 * submissions — and the escaping is the part that matters. A second copy of it
 * is a second place for the formula guard to be forgotten.
 */
final class Csv {

	/**
	 * Encode one row.
	 *
	 * Cells starting with a formula character are prefixed with a quote so a
	 * spreadsheet treats them as text. Without it an exported value such as
	 * `=1+1` becomes a live formula, and `=HYPERLINK(...)` becomes something
	 * worse the moment somebody opens the file — which matters most here,
	 * because a submission is a cell a stranger filled in.
	 *
	 * @param array<int, string> $cells Cell values.
	 */
	public static function line( array $cells ): string {
		$escaped = array();

		foreach ( $cells as $cell ) {
			if ( 1 === preg_match( '/^[=+\-@\t\r]/', $cell ) ) {
				$cell = "'" . $cell;
			}

			$escaped[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}

		return implode( ',', $escaped );
	}

	/**
	 * One stored value as a single cell.
	 *
	 * Nested values are flattened rather than skipped. A repeater holds
	 * answers somebody typed, and a spreadsheet missing them is the wrong
	 * answer to "a repeater does not fit in a cell" — so rows are joined with
	 * a semicolon and the values inside a row with a pipe, which reads as a
	 * sentence and still splits again if anyone needs it to.
	 *
	 * @param mixed $value Stored or formatted value.
	 */
	public static function cell( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( null === $value ) {
			return '';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( $value instanceof \WP_Post ) {
			return $value->post_title;
		}

		if ( $value instanceof \WP_Term ) {
			return $value->name;
		}

		if ( $value instanceof \WP_User ) {
			return $value->display_name;
		}

		if ( ! is_array( $value ) || array() === $value ) {
			return '';
		}

		$parts = array();

		foreach ( $value as $key => $item ) {
			$flat = self::cell( $item );

			if ( '' === $flat ) {
				continue;
			}

			/*
			 * A repeater row is keyed by field name; a list of chosen options
			 * is not. Naming the cell only where there is a name to give keeps
			 * a plain list reading as a plain list.
			 */
			$parts[] = is_string( $key ) ? $key . ': ' . $flat : $flat;
		}

		return implode( is_array( reset( $value ) ) ? ' ; ' : ' | ', $parts );
	}
}
