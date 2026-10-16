<?php
/**
 * Standalone self-check for the row loop.
 *
 * The loop is a pointer into a list, so what can go wrong is all positional:
 * a row consumed by the `if` that guards the `while`, a nested loop reading
 * the outer rows, a loop left open by a `break` bleeding into the next object.
 * Each of those is a template that renders the wrong content and does not
 * error, so each one is asserted here.
 *
 * Run with `php tests/rows-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Rows;
use WPCMB\Fields\Values;
use WPCMB\FieldTypes\Choice;
use WPCMB\FieldTypes\Flexible;
use WPCMB\FieldTypes\Input;
use WPCMB\FieldTypes\Repeater;

/*
 * The value pipeline, as the renderer and the registry wire it in WordPress:
 * each type formats on the way out and sanitizes on the way in, and a
 * composite type recurses through the same pair for its sub values. Wiring it
 * here is what makes a nested repeater format its rows, which is what the
 * nested loop reads.
 */
$repeater = new Repeater();
$flexible = new Flexible();
$choice   = new Choice();
$input    = new Input();

/**
 * Hand a value to its field type.
 *
 * @param string               $method format or sanitize.
 * @param mixed                $value  The value.
 * @param array<string, mixed> $field  Field definition.
 *
 * @return mixed
 */
$through = static function ( string $method, $value, array $field ) use ( $repeater, $flexible, $choice, $input ) {
	$type = match ( (string) ( $field['type'] ?? 'text' ) ) {
		'repeater'         => $repeater,
		'flexible_content' => $flexible,
		'select'           => $choice,
		default            => $input,
	};

	return $type->$method( $value, $field );
};

$format   = static fn( $value, array $field ) => $through( 'format', $value, $field );
$sanitize = static fn( $value, array $field ) => $through( 'sanitize', $value, $field );

wpcmb_test_filter( 'wpcmb/field/sub_formatter', static fn() => $format );
wpcmb_test_filter( 'wpcmb/field/sub_sanitizer', static fn() => $sanitize );

wpcmb_test_filter(
	'wpcmb/value/load',
	static fn( $value, string $name, $ref, ?array $field ) => null === $field ? $value : $format( $value, $field )
);

/**
 * The last value handed to the save pipeline, before any sanitizing.
 *
 * Sanitizing a repeater renumbers its rows on the way past, so it hides
 * whether the writer handed over a renumbered list or one with a hole in it.
 * This is where that can still be seen.
 *
 * @var mixed
 */
$submitted = null;

wpcmb_test_filter(
	'wpcmb/value/save',
	static function ( $value, string $name, $ref, ?array $field ) use ( $sanitize, &$submitted ) {
		$submitted = $value;

		return null === $field ? $value : $sanitize( $value, $field );
	}
);

/* -------------------------------------------------------------------------
 * One group: a repeater holding a nested repeater, and some flexible content.
 * ---------------------------------------------------------------------- */

$config = FieldGroup::sanitize(
	array(
		'key'    => 'group_rows_check',
		'title'  => 'Rows',
		'fields' => array(
			array(
				'name'       => 'slides',
				'type'       => 'repeater',
				'sub_fields' => array(
					array( 'name' => 'heading', 'type' => 'text', 'label' => 'Heading' ),
					array( 'name' => 'caption', 'type' => 'text', 'default' => 'No caption' ),
					// A sub field whose formatted value is not its stored one,
					// so a writer that reads the formatted rows is visible.
					array(
						'name'     => 'colour',
						'type'     => 'select',
						'settings' => array(
							'choices'       => "red : Red\nblue : Blue",
							'return_format' => 'label',
						),
					),
					array(
						'name'       => 'bullets',
						'type'       => 'repeater',
						'sub_fields' => array(
							array( 'name' => 'text', 'type' => 'text' ),
						),
					),
				),
			),
			array(
				'name'    => 'sections',
				'type'    => 'flexible_content',
				'layouts' => array(
					array(
						'name'       => 'quote',
						'label'      => 'Quote',
						'sub_fields' => array( array( 'name' => 'body', 'type' => 'text', 'label' => 'Quotation' ) ),
					),
					array(
						'name'       => 'heading',
						'label'      => 'Heading',
						'sub_fields' => array( array( 'name' => 'body', 'type' => 'text', 'label' => 'Heading text' ) ),
					),
				),
			),
			array( 'name' => 'plain', 'type' => 'text' ),
		),
	)
);

$GLOBALS['wpcmb_test_posts'] = array( new WP_Post( 500, 'Rows', 'publish', 'wpcmb_field_group' ) );
$GLOBALS['wpcmb_test_meta']['post'][500]['_wpcmb_config'] = wp_json_encode( $config );

$repository = new Repository();
$values     = new Values( $repository );
$rows       = new Rows( $values, $repository );

$values->update(
	'slides',
	array(
		array(
			'heading' => 'First',
			'caption' => 'One',
			'bullets' => array( array( 'text' => 'a' ), array( 'text' => 'b' ) ),
		),
		array(
			'heading' => 'Second',
			'caption' => null,
			'bullets' => array(),
		),
		array(
			'heading' => 'Third',
			'caption' => 'Three',
			'bullets' => array( array( 'text' => 'c' ) ),
		),
	),
	700
);

/* -------------------------------------------------------------------------
 * The plain loop, written the way a template writes it.
 * ---------------------------------------------------------------------- */

$seen = array();

if ( $rows->have( 'slides', 700 ) ) {
	while ( $rows->have( 'slides', 700 ) ) {
		$rows->row();

		$seen[] = $rows->sub( 'heading' );
	}
}

// The `if` guarding the `while` is the commonest shape there is. If have()
// moved the pointer, the guard would eat row one and this would read
// First/Second/Third as Second/Third.
assert( array( 'First', 'Second', 'Third' ) === $seen, 'every row is read once, in order, got: ' . implode( ',', $seen ) );

// The loop closed itself on the way out, so the next one starts over.
$again = array();

while ( $rows->have( 'slides', 700 ) ) {
	$rows->row();
	$again[] = $rows->sub( 'heading' );
}

assert( $seen === $again, 'a loop that ran to the end starts again from the first row' );

/* -------------------------------------------------------------------------
 * Positions and counts, which a template uses for markup.
 * ---------------------------------------------------------------------- */

$positions = array();

while ( $rows->have( 'slides', 700 ) ) {
	$rows->row();
	$positions[] = $rows->index() . '/' . $rows->count();
}

assert( array( '1/3', '2/3', '3/3' ) === $positions, 'the index counts from one and the count is the whole loop' );
assert( 0 === $rows->index(), 'outside a loop there is no position' );
assert( 0 === $rows->count(), 'and nothing to count' );

/* -------------------------------------------------------------------------
 * Nesting. A repeater inside a repeater, read with the same two calls.
 * ---------------------------------------------------------------------- */

$nested = array();

while ( $rows->have( 'slides', 700 ) ) {
	$rows->row();

	$heading = (string) $rows->sub( 'heading' );

	while ( $rows->have( 'bullets' ) ) {
		$rows->row();

		$nested[] = $heading . ':' . $rows->sub( 'text' );
	}

	// Back on the outer row after the inner loop closed, not on the inner one.
	$nested[] = $heading . '=' . $rows->index();
}

assert(
	array( 'First:a', 'First:b', 'First=1', 'Second=2', 'Third:c', 'Third=3' ) === $nested,
	'a nested loop reads its own rows and hands the pointer back, got: ' . implode( ',', $nested )
);

/* -------------------------------------------------------------------------
 * Sub field definitions. This is the thing a plain foreach cannot do: a sub
 * field is not in the top-level name index, so nothing else can reach it.
 * ---------------------------------------------------------------------- */

assert( null === $repository->field_by_name( 'heading' ), 'a sub field is not a top-level field' );

$rows->reset();
$rows->have( 'slides', 700 );
$rows->row();

assert( 'Heading' === ( $rows->sub_field( 'heading' )['label'] ?? '' ), 'a sub field definition is reachable inside the loop' );
assert( null === $rows->sub_field( 'nope' ), 'and a name that is not a sub field resolves to null' );

$rows->reset();
$rows->have( 'slides', 700 );
$rows->row();
$rows->row();

assert( 'Second' === $rows->sub( 'heading' ), 'the second row is the second row' );

// An empty sub field is an empty sub field, the same as a top-level one: a
// stored empty value wins over the default, or clearing a field would put the
// default back on the next page load.
assert( '' === $rows->sub( 'caption' ), 'a sub field cleared to empty stays empty' );

/*
 * A row saved before the repeater had this sub field does not have the key at
 * all, and that is the case the default is for. It is reachable only through
 * the sub field definition, which is the whole reason this class holds one.
 */
$GLOBALS['wpcmb_test_meta']['post'][701]['slides'] = array( array( 'heading' => 'Older' ) );

$rows->reset();
$rows->have( 'slides', 701 );
$rows->row();

assert( 'Older' === $rows->sub( 'heading' ), 'an older row still reads' );
assert( 'No caption' === $rows->sub( 'caption' ), "a sub field the row predates falls back to the sub field's default" );

$rows->reset();

assert( null === $rows->sub_field( 'heading' ), 'outside a loop there is no sub field' );
assert( null === $rows->sub( 'heading' ), 'and nothing to read' );
assert( array() === $rows->get(), 'and no row' );
assert( array() === $rows->row(), 'and nothing to move onto' );

/* -------------------------------------------------------------------------
 * Flexible content: the layout decides which sub fields a row has.
 * ---------------------------------------------------------------------- */

$values->update(
	'sections',
	array(
		array( Flexible::LAYOUT_KEY => 'quote', 'body' => 'Said it' ),
		array( Flexible::LAYOUT_KEY => 'heading', 'body' => 'Titled' ),
	),
	700
);

$layouts = array();

while ( $rows->have( 'sections', 700 ) ) {
	$rows->row();

	$layouts[] = $rows->layout() . ':' . $rows->sub( 'body' ) . ':' . ( $rows->sub_field( 'body' )['label'] ?? '' );
}

assert(
	array( 'quote:Said it:Quotation', 'heading:Titled:Heading text' ) === $layouts,
	'each row reports its layout and the sub fields of that layout, got: ' . implode( ' | ', $layouts )
);

assert( '' === $rows->layout(), 'outside a loop there is no layout' );

$rows->reset();
$rows->have( 'slides', 700 );
$rows->row();

assert( '' === $rows->layout(), 'and a plain repeater row has no layout' );

$rows->reset();

/* -------------------------------------------------------------------------
 * What is not a loop. A field that is not a repeater, a name nobody has, and
 * an object with nothing stored all have to say "no rows" rather than open a
 * loop over nothing — the `while` would not end.
 * ---------------------------------------------------------------------- */

$values->update( 'plain', 'a string', 700 );

assert( ! $rows->have( 'plain', 700 ), 'a field whose value is not rows is not a loop' );
assert( ! $rows->have( 'slides', 999 ), 'an object with nothing stored is not a loop' );

/*
 * A name with no field behind it is refused before storage is read at all.
 * Meta is a shared namespace — another plugin's rows sitting under a key this
 * plugin has no field for must not become a loop a template can walk, because
 * none of those values have been through a sanitizer of ours.
 */
$GLOBALS['wpcmb_test_meta']['post'][700]['other_plugin_rows'] = array( array( 'heading' => 'Not ours' ) );

assert( ! $rows->have( 'other_plugin_rows', 700 ), "another plugin's meta is not a loop" );
assert( ! $rows->have( 'nope', 700 ), 'and neither is a name nothing has stored' );
assert( array() === $rows->get(), 'and none of them left a loop open' );

/* -------------------------------------------------------------------------
 * A loop left open by a break.
 *
 * The pointer is still mid-list, which is exactly what reset_rows() is for.
 * But an archive template that breaks out of one post's loop must not then
 * render the previous post's rows on the next post, so the object is part of
 * a loop's identity rather than the field name alone.
 * ---------------------------------------------------------------------- */

$values->update( 'slides', array( array( 'heading' => 'Other' ) ), 800 );

while ( $rows->have( 'slides', 700 ) ) {
	$rows->row();
	break;
}

$other = array();

while ( $rows->have( 'slides', 800 ) ) {
	$rows->row();
	$other[] = $rows->sub( 'heading' );
}

assert( array( 'Other' ) === $other, 'a loop left open on one object does not answer for another, got: ' . implode( ',', $other ) );

$rows->reset();

/* -------------------------------------------------------------------------
 * Writers. They go through the same value pipeline an edit screen does, so a
 * row written here is a row the admin would have accepted.
 * ---------------------------------------------------------------------- */

$values->update( 'slides', array( array( 'heading' => 'Keep', 'colour' => 'red' ) ), 900 );

assert( 'Red' === $values->get( 'slides', 900 )[0]['colour'], 'the colour formats to its label' );

assert(
	2 === $rows->add( 'slides', array( 'heading' => 'Added', 'smuggled' => 'x' ), 900 ),
	'adding a row returns the new count'
);

$stored = $values->get( 'slides', 900, false );

assert( 2 === count( $stored ), 'and the row is stored' );
assert( 'Added' === $stored[1]['heading'], 'at the end' );

// Sanitizing happens on the way in: a key the repeater has no sub field for is
// dropped, so a write cannot put anything past the field definition.
assert( ! array_key_exists( 'smuggled', $stored[1] ), 'a key that is not a sub field is dropped' );

/*
 * The rows already there are rewritten as they were stored, not as they were
 * formatted. A writer that read the formatted rows would hand "Red" back to
 * the select, which has no such choice — so adding a row to the end would
 * quietly empty a field in the rows above it.
 */
assert( 'Keep' === $stored[0]['heading'], 'the rows already stored keep their values' );
assert( 'red' === $stored[0]['colour'], 'as stored, not as formatted' );

assert( $rows->update( 'slides', 1, array( 'heading' => 'Changed' ), 900 ), 'a row can be replaced by position' );
assert( 'Changed' === $values->get( 'slides', 900, false )[0]['heading'], 'and the replacement is stored' );

// Counting from one, as the loop reports it. Taking index 1 to mean the second
// row would quietly change the wrong one.
assert( ! $rows->update( 'slides', 0, array( 'heading' => 'No' ), 900 ), 'position zero is not a row' );
assert( ! $rows->update( 'slides', 99, array( 'heading' => 'No' ), 900 ), 'and neither is one past the end' );
assert( 'Changed' === $values->get( 'slides', 900, false )[0]['heading'], 'and a refused write changes nothing' );

assert( $rows->delete( 'slides', 1, 900 ), 'a row can be deleted by position' );

$left = $values->get( 'slides', 900, false );

assert( 1 === count( $left ), 'and is gone' );
assert( 'Added' === $left[0]['heading'], 'leaving the rest renumbered from zero' );

/*
 * Renumbered by the writer, not only by the sanitizer it happens to pass
 * through. Removing a row leaves a hole in the list, and a hole reaching
 * storage is a repeater whose rows are keyed 1, 2, 4 — which the sanitizer
 * tidies today, but a site that claims the write through wpcmb/storage/write
 * never sees it.
 */
assert( array( 0 ) === array_keys( (array) $submitted ), 'a deleted row leaves no gap in what is written' );
assert( ! $rows->delete( 'slides', 5, 900 ), 'deleting a row that is not there fails rather than clearing the field' );
assert( 1 === count( $values->get( 'slides', 900, false ) ), 'and leaves the rows alone' );

echo "rows check passed\n";
