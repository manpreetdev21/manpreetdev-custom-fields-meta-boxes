<?php
/**
 * Public template functions.
 *
 * The only global functions the plugin defines. Everything here is a thin
 * wrapper over a container service: themes get a short, stable, procedural
 * API, and the implementation stays swappable behind it.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

use WPCMB\Fields\Context;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Resolver;
use WPCMB\Fields\Rows;
use WPCMB\Fields\Validator;
use WPCMB\Fields\Values;
use WPCMB\Frontend\Form;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpcmb_get_field' ) ) {
	/**
	 * Read a field value.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier. Defaults to the current post.
	 *                           Accepts an id, a WP_Post/WP_Term/WP_User/WP_Comment,
	 *                           or a string such as `term_5` or `options`.
	 * @param bool   $format     Whether to run the value through field-type formatting.
	 *
	 * @return mixed The value, the field's default, or null.
	 */
	function wpcmb_get_field( string $selector, mixed $identifier = null, bool $format = true ): mixed {
		return wpcmb()->container()->get( Values::class )->get( $selector, $identifier, $format );
	}
}

if ( ! function_exists( 'wpcmb_the_field' ) ) {
	/**
	 * Echo a field value, escaped for HTML output.
	 *
	 * Arrays are joined with a comma. Anything that is not a scalar or an
	 * array of scalars prints nothing rather than "Array": use
	 * wpcmb_get_field() for structured values such as repeater rows.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier.
	 */
	function wpcmb_the_field( string $selector, mixed $identifier = null ): void {
		$value = wpcmb_get_field( $selector, $identifier );

		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
		}

		if ( ! is_scalar( $value ) ) {
			return;
		}

		echo esc_html( (string) $value );
	}
}

if ( ! function_exists( 'wpcmb_get_fields' ) ) {
	/**
	 * Read every field value that applies to an object.
	 *
	 * @param mixed $identifier Object identifier.
	 * @param bool  $format     Whether to run values through field-type formatting.
	 *
	 * @return array<string, mixed> Values keyed by field name.
	 */
	function wpcmb_get_fields( mixed $identifier = null, bool $format = true ): array {
		$context  = Context::for( $identifier );
		$resolver = wpcmb()->container()->get( Resolver::class );
		$values   = wpcmb()->container()->get( Values::class );

		$result = array();

		foreach ( array_keys( $resolver->fields( $context ) ) as $name ) {
			$result[ $name ] = $values->get( $name, $context->ref, $format );
		}

		return $result;
	}
}

if ( ! function_exists( 'wpcmb_has_field' ) ) {
	/**
	 * Whether a value is stored for a field, ignoring its default.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier.
	 */
	function wpcmb_has_field( string $selector, mixed $identifier = null ): bool {
		return wpcmb()->container()->get( Values::class )->has( $selector, $identifier );
	}
}

if ( ! function_exists( 'wpcmb_update_field' ) ) {
	/**
	 * Write a field value.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $value      Value to store, unslashed.
	 * @param mixed  $identifier Object identifier.
	 */
	function wpcmb_update_field( string $selector, mixed $value, mixed $identifier = null ): bool {
		return wpcmb()->container()->get( Values::class )->update( $selector, $value, $identifier );
	}
}

if ( ! function_exists( 'wpcmb_delete_field' ) ) {
	/**
	 * Delete a field value.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier.
	 */
	function wpcmb_delete_field( string $selector, mixed $identifier = null ): bool {
		return wpcmb()->container()->get( Values::class )->delete( $selector, $identifier );
	}
}

if ( ! function_exists( 'wpcmb_get_field_object' ) ) {
	/**
	 * The definition of a field, by name.
	 *
	 * @param string $selector Field name.
	 *
	 * @return array<string, mixed>|null
	 */
	function wpcmb_get_field_object( string $selector ): ?array {
		return wpcmb()->container()->get( Repository::class )->field_by_name( $selector );
	}
}

if ( ! function_exists( 'wpcmb_get_field_groups' ) ) {
	/**
	 * Field groups that apply to an object.
	 *
	 * @param mixed                 $identifier Object identifier.
	 * @param array<string, string> $extra      Screen details, e.g. `array( 'user_form' => 'edit' )`.
	 *
	 * @return array<string, FieldGroup>
	 */
	function wpcmb_get_field_groups( mixed $identifier = null, array $extra = array() ): array {
		return wpcmb()->container()->get( Resolver::class )->groups( Context::for( $identifier, $extra ) );
	}
}

if ( ! function_exists( 'wpcmb_register_field_group' ) ) {
	/**
	 * Register a field group from code.
	 *
	 * Call on the `wpcmb/register_field_groups` action. The group is not
	 * written to the database, and a stored group with the same key wins.
	 *
	 * @param array<string, mixed> $config Group configuration.
	 */
	function wpcmb_register_field_group( array $config ): FieldGroup {
		return wpcmb()->container()->get( Repository::class )->register( $config );
	}
}

if ( ! function_exists( 'wpcmb_form' ) ) {
	/**
	 * Render a field group as a front-end form.
	 *
	 * Same arguments as the `[wpcmb_form]` shortcode.
	 *
	 * @param array<string, mixed> $args    Form configuration. See Form::defaults().
	 * @param bool                 $display Whether to print the form.
	 *
	 * @return string The form markup.
	 */
	function wpcmb_form( array $args = array(), bool $display = true ): string {
		$html = wpcmb()->container()->get( Form::class )->render( $args );

		if ( $display ) {
			// Built entirely from escaped output by the renderer and template.
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $html;
	}
}

if ( ! function_exists( 'wpcmb_form_shortcode' ) ) {
	/**
	 * The shortcode that renders a field group as a form.
	 *
	 * @param string               $group_key Field group key.
	 * @param array<string, mixed> $args      Extra configuration.
	 */
	function wpcmb_form_shortcode( string $group_key, array $args = array() ): string {
		$parts = array( Form::SHORTCODE, sprintf( 'group="%s"', $group_key ) );

		foreach ( $args as $name => $value ) {
			$parts[] = sprintf( '%s="%s"', sanitize_key( (string) $name ), esc_attr( (string) $value ) );
		}

		return '[' . implode( ' ', $parts ) . ']';
	}
}

if ( ! function_exists( 'wpcmb_validate_values' ) ) {
	/**
	 * Validate a set of values against the fields that apply to an object.
	 *
	 * @param array<string, mixed> $values     Values keyed by field name.
	 * @param mixed                $identifier Object identifier.
	 *
	 * @return array<string, string> Error messages keyed by field name. Empty when valid.
	 */
	function wpcmb_validate_values( array $values, mixed $identifier = null ): array {
		$fields = wpcmb()->container()->get( Resolver::class )->fields( Context::for( $identifier ) );

		return wpcmb()->container()->get( Validator::class )->validate( $fields, $values );
	}
}

/*
 * The row loop.
 *
 * A repeater value is already a list of rows, so `foreach ( wpcmb_get_field(
 * 'slides' ) as $row )` works and is the shorter thing to write for a flat
 * repeater. These exist for the two cases it cannot do: reaching a sub field's
 * definition — its label, its choices, its default, none of which are in the
 * top-level name index — and walking a repeater nested inside a repeater
 * without nesting the loops by hand.
 *
 * The spelling is ACF's on purpose. A site moving across has it in every
 * template, and a second spelling for the same idea would mean editing all of
 * them.
 */

if ( ! function_exists( 'wpcmb_have_rows' ) ) {
	/**
	 * Whether the repeater has another row, opening the loop if needed.
	 *
	 * Looks without moving, so an `if` around a `while` does not consume the
	 * first row. Moving is wpcmb_the_row()'s job, and a loop body that never
	 * calls it never ends.
	 *
	 * @since 1.16.0
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier. Ignored for a nested loop,
	 *                           which belongs to the row it is opened inside.
	 */
	function wpcmb_have_rows( string $selector, mixed $identifier = null ): bool {
		return wpcmb()->container()->get( Rows::class )->have( $selector, $identifier );
	}
}

if ( ! function_exists( 'wpcmb_the_row' ) ) {
	/**
	 * Move onto the next row and return it.
	 *
	 * @since 1.16.0
	 *
	 * @param bool $format Whether to format the row's values.
	 *
	 * @return array<string, mixed> The row, or an empty array outside a loop.
	 */
	function wpcmb_the_row( bool $format = false ): array {
		return wpcmb()->container()->get( Rows::class )->row( $format );
	}
}

if ( ! function_exists( 'wpcmb_get_row' ) ) {
	/**
	 * The current row, without moving onto the next one.
	 *
	 * @since 1.16.0
	 *
	 * @param bool $format Whether to format the row's values.
	 *
	 * @return array<string, mixed> The row, or an empty array outside a loop.
	 */
	function wpcmb_get_row( bool $format = false ): array {
		return wpcmb()->container()->get( Rows::class )->get( $format );
	}
}

if ( ! function_exists( 'wpcmb_get_sub_field' ) ) {
	/**
	 * Read a value from the current row.
	 *
	 * @since 1.16.0
	 *
	 * @param string $selector Sub field name.
	 * @param bool   $format   Whether to run the value through field-type formatting.
	 *
	 * @return mixed The value, the sub field's default, or null.
	 */
	function wpcmb_get_sub_field( string $selector, bool $format = true ): mixed {
		return wpcmb()->container()->get( Rows::class )->sub( $selector, $format );
	}
}

if ( ! function_exists( 'wpcmb_the_sub_field' ) ) {
	/**
	 * Echo a value from the current row, escaped for HTML output.
	 *
	 * Behaves as wpcmb_the_field() does: arrays are joined with a comma, and
	 * anything that is not a scalar or a list of scalars prints nothing rather
	 * than "Array".
	 *
	 * @since 1.16.0
	 *
	 * @param string $selector Sub field name.
	 */
	function wpcmb_the_sub_field( string $selector ): void {
		$value = wpcmb_get_sub_field( $selector );

		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
		}

		if ( ! is_scalar( $value ) ) {
			return;
		}

		echo esc_html( (string) $value );
	}
}

if ( ! function_exists( 'wpcmb_get_sub_field_object' ) ) {
	/**
	 * The definition of a sub field of the current row.
	 *
	 * The one thing a plain foreach over the rows cannot reach: a sub field is
	 * not in the top-level name index, so this is the only way a template gets
	 * at its label, choices or default.
	 *
	 * @since 1.16.0
	 *
	 * @param string $selector Sub field name.
	 *
	 * @return array<string, mixed>|null
	 */
	function wpcmb_get_sub_field_object( string $selector ): ?array {
		return wpcmb()->container()->get( Rows::class )->sub_field( $selector );
	}
}

if ( ! function_exists( 'wpcmb_get_row_layout' ) ) {
	/**
	 * Which layout the current flexible content row is.
	 *
	 * @since 1.16.0
	 *
	 * @return string Layout name, or an empty string for a plain repeater.
	 */
	function wpcmb_get_row_layout(): string {
		return wpcmb()->container()->get( Rows::class )->layout();
	}
}

if ( ! function_exists( 'wpcmb_get_row_index' ) ) {
	/**
	 * The current row's position, counting from one.
	 *
	 * @since 1.16.0
	 *
	 * @return int Zero outside a loop.
	 */
	function wpcmb_get_row_index(): int {
		return wpcmb()->container()->get( Rows::class )->index();
	}
}

if ( ! function_exists( 'wpcmb_count_rows' ) ) {
	/**
	 * How many rows the current loop holds.
	 *
	 * @since 1.16.0
	 *
	 * @return int Zero outside a loop.
	 */
	function wpcmb_count_rows(): int {
		return wpcmb()->container()->get( Rows::class )->count();
	}
}

if ( ! function_exists( 'wpcmb_reset_rows' ) ) {
	/**
	 * Close every open row loop.
	 *
	 * Needed after breaking out of a loop part way through, which otherwise
	 * leaves it open and makes the next loop over the same field resume where
	 * that one stopped.
	 *
	 * @since 1.16.0
	 */
	function wpcmb_reset_rows(): void {
		wpcmb()->container()->get( Rows::class )->reset();
	}
}

if ( ! function_exists( 'wpcmb_add_row' ) ) {
	/**
	 * Append a row to a repeater.
	 *
	 * The row goes through the same sanitizing an edit screen does, so this
	 * cannot store what the admin would refuse.
	 *
	 * @since 1.16.0
	 *
	 * @param string               $selector   Field name.
	 * @param array<string, mixed> $row        Values keyed by sub field name.
	 * @param mixed                $identifier Object identifier.
	 *
	 * @return int The number of rows now stored, or 0 if nothing was written.
	 */
	function wpcmb_add_row( string $selector, array $row, mixed $identifier = null ): int {
		return wpcmb()->container()->get( Rows::class )->add( $selector, $row, $identifier );
	}
}

if ( ! function_exists( 'wpcmb_update_row' ) ) {
	/**
	 * Replace one row of a repeater.
	 *
	 * @since 1.16.0
	 *
	 * @param string               $selector   Field name.
	 * @param int                  $index      Row position, counting from one.
	 * @param array<string, mixed> $row        Values keyed by sub field name.
	 * @param mixed                $identifier Object identifier.
	 */
	function wpcmb_update_row( string $selector, int $index, array $row, mixed $identifier = null ): bool {
		return wpcmb()->container()->get( Rows::class )->update( $selector, $index, $row, $identifier );
	}
}

if ( ! function_exists( 'wpcmb_delete_row' ) ) {
	/**
	 * Remove one row of a repeater.
	 *
	 * @since 1.16.0
	 *
	 * @param string $selector   Field name.
	 * @param int    $index      Row position, counting from one.
	 * @param mixed  $identifier Object identifier.
	 */
	function wpcmb_delete_row( string $selector, int $index, mixed $identifier = null ): bool {
		return wpcmb()->container()->get( Rows::class )->delete( $selector, $index, $identifier );
	}
}