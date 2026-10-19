<?php
/**
 * The row loop templates read repeaters with.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

use WPCMB\FieldTypes\Flexible;

defined( 'ABSPATH' ) || exit;

/**
 * A stack of open row loops.
 *
 * A repeater's value is already a list of rows, so a template can walk it with
 * `foreach ( wpcmb_get_field( 'slides' ) as $row )` and always could. What that
 * does not give is the sub field's *definition* — its label, its choices, the
 * default it falls back to — because a sub field is not in the top-level name
 * index and `wpcmb_get_field()` cannot find it. Nor does it give a short way to
 * walk a repeater nested inside a repeater.
 *
 * So this holds a pointer into the rows instead: `have()` says whether another
 * row is coming, `row()` moves onto it, and `sub()` reads from wherever the
 * pointer is. A loop opened while another is open is nested inside it, which is
 * what makes the nested case read the same as the flat one.
 *
 * The shape is deliberately the one ACF uses — `have_rows()`, `the_row()`,
 * `get_sub_field()` — because a site moving off ACF has that spelling in every
 * template it owns, and a different spelling for the same idea would mean
 * rewriting all of them.
 */
final class Rows {

	/**
	 * Value reader.
	 *
	 * @var Values
	 */
	private Values $values;

	/**
	 * Field group repository.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Open loops, innermost last.
	 *
	 * Each frame holds the rows twice: formatted, which is what a template
	 * asks for, and stored, for the reader that asked not to be handed a
	 * WP_Post where it stored an id. They are the same list in the same order,
	 * so one index addresses both.
	 *
	 * @var array<int, array{selector: string, ref: string, rows: array<int, array<string, mixed>>, raw: array<int, array<string, mixed>>, field: array<string, mixed>, index: int}>
	 */
	private array $stack = array();

	/**
	 * Constructor.
	 *
	 * @param Values     $values     Value reader.
	 * @param Repository $repository Field group repository.
	 */
	public function __construct( Values $values, Repository $repository ) {
		$this->values     = $values;
		$this->repository = $repository;
	}

	/**
	 * Whether another row is coming, opening the loop if it is not open yet.
	 *
	 * This looks without moving, so the `if` around a `while` does not eat the
	 * first row:
	 *
	 *     if ( wpcmb_have_rows( 'slides' ) ) {
	 *         while ( wpcmb_have_rows( 'slides' ) ) {
	 *             wpcmb_the_row();
	 *         }
	 *     }
	 *
	 * Moving is `row()`'s job, so a `while` whose body never calls it never
	 * ends. That is the contract ACF has, and keeping it is what lets a
	 * template written against ACF behave here the way it did there.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier, for a loop not nested in another.
	 */
	public function have( string $selector, mixed $identifier = null ): bool {
		$open = $this->current();

		if ( null !== $open && $this->is_same_loop( $open, $selector, $identifier ) ) {
			if ( $open['index'] + 1 < count( $open['rows'] ) ) {
				return true;
			}

			array_pop( $this->stack );

			return false;
		}

		return $this->open( $selector, $identifier );
	}

	/**
	 * Move onto the next row and return it.
	 *
	 * @param bool $format Whether to return the row formatted.
	 *
	 * @return array<string, mixed> The row, or an empty array outside a loop.
	 */
	public function row( bool $format = false ): array {
		$index = count( $this->stack ) - 1;

		if ( $index < 0 ) {
			return array();
		}

		++$this->stack[ $index ]['index'];

		return $this->get( $format );
	}

	/**
	 * The row the pointer is on, without moving it.
	 *
	 * @param bool $format Whether to return the row formatted.
	 *
	 * @return array<string, mixed> The row, or an empty array outside a loop.
	 */
	public function get( bool $format = false ): array {
		$open = $this->current();

		if ( null === $open || $open['index'] < 0 ) {
			return array();
		}

		$rows = $format ? $open['rows'] : $open['raw'];

		return $rows[ $open['index'] ] ?? array();
	}

	/**
	 * One value from the current row.
	 *
	 * @param string $selector Sub field name.
	 * @param bool   $format   Whether to format the value.
	 *
	 * @return mixed The value, the sub field's default, or null.
	 */
	public function sub( string $selector, bool $format = true ): mixed {
		$row = $this->get( $format );

		if ( array_key_exists( $selector, $row ) && null !== $row[ $selector ] ) {
			return $row[ $selector ];
		}

		$field = $this->sub_field( $selector );

		return $field['default'] ?? null;
	}

	/**
	 * The definition of one sub field of the current row.
	 *
	 * The reason this class exists rather than a `foreach`: a sub field is not
	 * in the top-level name index, so nothing else can hand a template the
	 * label, choices or default of a field inside a repeater.
	 *
	 * @param string $selector Sub field name.
	 *
	 * @return array<string, mixed>|null
	 */
	public function sub_field( string $selector ): ?array {
		$open = $this->current();

		if ( null === $open ) {
			return null;
		}

		return $this->fields_for( $open['field'], $this->get() )[ $selector ] ?? null;
	}

	/**
	 * Which layout the current row is, for flexible content.
	 *
	 * @return string Layout name, or an empty string for a plain repeater.
	 */
	public function layout(): string {
		return (string) ( $this->get()[ Flexible::LAYOUT_KEY ] ?? '' );
	}

	/**
	 * The current row's position, counting from one.
	 *
	 * @return int Zero outside a loop, and before the first row() call.
	 */
	public function index(): int {
		$open = $this->current();

		return null === $open ? 0 : $open['index'] + 1;
	}

	/**
	 * How many rows the current loop holds.
	 */
	public function count(): int {
		$open = $this->current();

		return null === $open ? 0 : count( $open['rows'] );
	}

	/**
	 * Close every open loop.
	 *
	 * Needed after a `break`, which leaves a loop open part way through: the
	 * next loop over the same field on the same object would otherwise pick up
	 * where this one stopped.
	 */
	public function reset(): void {
		$this->stack = array();
	}

	/**
	 * Append a row, returning the new row count.
	 *
	 * @param string               $selector   Field name.
	 * @param array<string, mixed> $row        The row to add.
	 * @param mixed                $identifier Object identifier.
	 *
	 * @return int The number of rows now stored, or 0 if nothing was written.
	 */
	public function add( string $selector, array $row, mixed $identifier = null ): int {
		$rows   = $this->stored( $selector, $identifier );
		$rows[] = $row;

		return $this->values->update( $selector, $rows, $identifier ) ? count( $rows ) : 0;
	}

	/**
	 * Replace one row.
	 *
	 * @param string               $selector   Field name.
	 * @param int                  $index      Row position, counting from one.
	 * @param array<string, mixed> $row        The replacement row.
	 * @param mixed                $identifier Object identifier.
	 */
	public function update( string $selector, int $index, array $row, mixed $identifier = null ): bool {
		$rows = $this->stored( $selector, $identifier );

		if ( ! array_key_exists( $index - 1, $rows ) ) {
			return false;
		}

		$rows[ $index - 1 ] = $row;

		return $this->values->update( $selector, $rows, $identifier );
	}

	/**
	 * Remove one row.
	 *
	 * @param string $selector   Field name.
	 * @param int    $index      Row position, counting from one.
	 * @param mixed  $identifier Object identifier.
	 */
	public function delete( string $selector, int $index, mixed $identifier = null ): bool {
		$rows = $this->stored( $selector, $identifier );

		if ( ! array_key_exists( $index - 1, $rows ) ) {
			return false;
		}

		unset( $rows[ $index - 1 ] );

		return $this->values->update( $selector, array_values( $rows ), $identifier );
	}

	/**
	 * The innermost open loop, or null.
	 *
	 * @return array{selector: string, ref: string, rows: array<int, array<string, mixed>>, raw: array<int, array<string, mixed>>, field: array<string, mixed>, index: int}|null
	 */
	private function current(): ?array {
		return array() === $this->stack ? null : $this->stack[ count( $this->stack ) - 1 ];
	}

	/**
	 * Whether an open loop is the one being asked about.
	 *
	 * The object is part of the answer, not just the field name. A loop left
	 * open by a `break` while rendering one post must not be mistaken for the
	 * same field on the next post of an archive, which would quietly render
	 * the previous post's rows.
	 *
	 * @param array<string, mixed> $open       The open loop.
	 * @param string               $selector   Field name asked for.
	 * @param mixed                $identifier Object identifier asked for.
	 */
	private function is_same_loop( array $open, string $selector, mixed $identifier ): bool {
		if ( $open['selector'] !== $selector ) {
			return false;
		}

		// A nested loop has no object of its own: it belongs to the row it was
		// opened inside, so an identifier is not part of its identity.
		return '' === $open['ref'] || (string) ObjectRef::from( $identifier ) === $open['ref'];
	}

	/**
	 * Open a loop, nesting it inside the current row where there is one.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier.
	 *
	 * @return bool Whether there is a row to read.
	 */
	private function open( string $selector, mixed $identifier ): bool {
		$open = $this->current();

		/*
		 * Nested, when a loop is open and its current row has a field by this
		 * name. Decided from the row rather than the field definition, so a
		 * flexible layout without that field falls through to a top-level read
		 * instead of looping over nothing.
		 */
		if ( null !== $open && $open['index'] >= 0 && array_key_exists( $selector, $this->get() ) ) {
			$field = $this->fields_for( $open['field'], $this->get() )[ $selector ] ?? array();
			$rows  = $this->rows( $this->get( true )[ $selector ] ?? null );
			$raw   = $this->rows( $this->get()[ $selector ] ?? null );
			$ref   = '';
		} else {
			$field = $this->repository->field_by_name( $selector );

			if ( null === $field ) {
				return false;
			}

			$rows = $this->rows( $this->values->get( $selector, $identifier, true ) );
			$raw  = $this->rows( $this->values->get( $selector, $identifier, false ) );
			$ref  = (string) ObjectRef::from( $identifier );
		}

		if ( array() === $rows ) {
			return false;
		}

		$this->stack[] = array(
			'selector' => $selector,
			'ref'      => $ref,
			'rows'     => $rows,
			'raw'      => $raw,
			'field'    => is_array( $field ) ? $field : array(),
			'index'    => -1,
		);

		return true;
	}

	/**
	 * A value as a list of rows, dropping anything that is not one.
	 *
	 * @param mixed $value Value to read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = array();

		foreach ( $value as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * The sub fields that apply to one row, keyed by name.
	 *
	 * A repeater has one set. Flexible content has a set per layout, chosen by
	 * the layout the row records, so the fields of a Quote row are not the
	 * fields of a Gallery row.
	 *
	 * @param array<string, mixed> $field Repeater or flexible content field.
	 * @param array<string, mixed> $row   The row they apply to.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fields_for( array $field, array $row ): array {
		$sub_fields = is_array( $field['sub_fields'] ?? null ) ? $field['sub_fields'] : array();
		$layout     = (string) ( $row[ Flexible::LAYOUT_KEY ] ?? '' );

		if ( '' !== $layout ) {
			$sub_fields = array();

			foreach ( (array) ( $field['layouts'] ?? array() ) as $candidate ) {
				if ( is_array( $candidate ) && (string) ( $candidate['name'] ?? '' ) === $layout ) {
					$sub_fields = is_array( $candidate['sub_fields'] ?? null ) ? $candidate['sub_fields'] : array();
					break;
				}
			}
		}

		$keyed = array();

		foreach ( $sub_fields as $sub ) {
			if ( is_array( $sub ) && '' !== (string) ( $sub['name'] ?? '' ) ) {
				$keyed[ (string) $sub['name'] ] = $sub;
			}
		}

		return $keyed;
	}

	/**
	 * A field's stored rows, for the writers.
	 *
	 * Stored rather than formatted: these are read, changed and written back,
	 * and writing back a formatted row would store the WP_Post a media field
	 * handed out in place of the id it was given.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stored( string $selector, mixed $identifier ): array {
		return $this->rows( $this->values->get( $selector, $identifier, false ) );
	}
}
