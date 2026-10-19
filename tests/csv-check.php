<?php
/**
 * Standalone self-check for CSV output.
 *
 * Both exports — a repeater's rows and a form's submissions — write through
 * this, and a submission cell is text a stranger typed. A spreadsheet treats
 * a cell beginning `=` as a formula, so the escaping here is the difference
 * between a download and a payload that runs when somebody opens it.
 *
 * Run with `php tests/csv-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Admin\Csv;

/* -------------------------------------------------------------------------
 * Quoting.
 * ---------------------------------------------------------------------- */

assert( '"a","b"' === Csv::line( array( 'a', 'b' ) ), 'every cell is quoted' );
assert( '""' === Csv::line( array( '' ) ), 'including an empty one' );
assert( '"a,b"' === Csv::line( array( 'a,b' ) ), 'a comma inside a cell does not split it' );
assert( '"say ""hi"""' === Csv::line( array( 'say "hi"' ) ), 'a quote is doubled, not dropped' );
assert( "\"two\nlines\"" === Csv::line( array( "two\nlines" ) ), 'a newline stays inside the quotes' );

/* -------------------------------------------------------------------------
 * Formula injection. The cell is data, and it has to stay data.
 * ---------------------------------------------------------------------- */

$dangerous = array(
	'=1+1',
	'=HYPERLINK("http://example.com","click")',
	'+1',
	'-1',
	'@SUM(A1)',
	"\tlead",
	"\rlead",
);

foreach ( $dangerous as $cell ) {
	$written = Csv::line( array( $cell ) );

	assert(
		str_starts_with( $written, '"\'' ),
		'a formula character is defused by a leading quote: ' . $cell . ' became ' . $written
	);
}

// And an ordinary value is left exactly as it was: a guard that quoted
// everything would corrupt every number in the file.
foreach ( array( 'plain', '42', '3.14', 'a=b', 'e-mail', 'user@example.com' ) as $cell ) {
	assert( '"' . $cell . '"' === Csv::line( array( $cell ) ), 'an ordinary cell is untouched: ' . $cell );
}

/* -------------------------------------------------------------------------
 * Values that are not strings.
 * ---------------------------------------------------------------------- */

assert( '' === Csv::cell( null ), 'nothing stored is an empty cell' );
assert( '' === Csv::cell( array() ), 'and so is an empty list' );
assert( '1' === Csv::cell( true ), 'a yes is 1' );
assert( '0' === Csv::cell( false ), 'and a no is 0, not an empty cell' );
assert( '0' === Csv::cell( 0 ), 'zero is an answer' );
assert( '' === Csv::cell( '' ), 'an empty string is an empty cell' );
assert( 'text' === Csv::cell( 'text' ), 'a string is itself' );

// Objects the field types hand back, rather than "Array" or an id.
assert( 'A Post' === Csv::cell( new WP_Post( 5, 'A Post' ) ), 'a post reads as its title' );

/* -------------------------------------------------------------------------
 * Nested values. A repeater holds answers somebody typed, so dropping them
 * would make the export useless for the one thing it is for.
 * ---------------------------------------------------------------------- */

assert( 'red | blue' === Csv::cell( array( 'red', 'blue' ) ), 'a list of choices reads as a list' );

$rows = array(
	array( 'name' => 'Ada', 'role' => 'Engineer' ),
	array( 'name' => 'Linus', 'role' => 'Maintainer' ),
);

assert(
	'name: Ada | role: Engineer ; name: Linus | role: Maintainer' === Csv::cell( $rows ),
	'repeater rows are named and separated, got: ' . Csv::cell( $rows )
);

// Empty sub values drop out rather than leaving a trail of separators.
assert( 'name: Ada' === Csv::cell( array( array( 'name' => 'Ada', 'role' => '' ) ) ), 'an empty sub value is left out' );

// Nesting deeper still reads, rather than printing "Array".
$nested = array( array( 'name' => 'Ada', 'tags' => array( 'x', 'y' ) ) );

assert( 'name: Ada | tags: x | y' === Csv::cell( $nested ), 'a nested list reads through, got: ' . Csv::cell( $nested ) );

/*
 * A formula arriving inside a nested value is still defused, because the
 * guard is applied to the finished cell rather than to the raw value. This is
 * the realistic shape of the attack: nobody types `=cmd()` into a field the
 * export writes straight out, they type it into a repeater row.
 */
$in_a_list = Csv::cell( array( '=cmd()' ) );

assert( '=cmd()' === $in_a_list, 'a list keeps the value as typed' );
assert( '"\'=cmd()"' === Csv::line( array( $in_a_list ) ), 'and the line defuses it on the way out' );

// Inside a repeater row the key leads the cell, so the formula is no longer
// first — but the written line is checked rather than assumed either way.
$in_a_row = Csv::cell( array( array( 'note' => '=cmd()' ) ) );

assert( 'note: =cmd()' === $in_a_row, 'a row names its sub field' );
assert( '"note: =cmd()"' === Csv::line( array( $in_a_row ) ), 'and nothing a spreadsheet would run leads the cell' );

/* -------------------------------------------------------------------------
 * The round trip. Quoting rules are easy to get subtly wrong in a way that
 * only shows up as a spreadsheet with its columns one cell out, so what is
 * written is read back with a real parser rather than eyeballed.
 * ---------------------------------------------------------------------- */

$cells = array(
	'plain',
	'a,b',
	'say "hi"',
	"two\nlines",
	'=HYPERLINK("http://example.com","click")',
	'',
	'0',
);

$handle = fopen( 'php://temp', 'r+' );

fwrite( $handle, Csv::line( $cells ) . "\r\n" );
rewind( $handle );

$parsed = fgetcsv( $handle, 0, ',', '"', '' );

fclose( $handle );

assert( is_array( $parsed ), 'a written line parses' );
assert( count( $cells ) === count( $parsed ), 'with the cell count it was given, got: ' . count( (array) $parsed ) );

/*
 * Every cell comes back as it went in, except the one that was defused — and
 * that one differs by exactly the leading quote, which is the whole point: the
 * value is still legible, it just is not a formula any more.
 */
foreach ( $cells as $index => $cell ) {
	$back = (string) ( $parsed[ $index ] ?? '' );

	assert(
		$back === $cell || $back === "'" . $cell,
		sprintf( 'cell %d round-trips: %s came back as %s', $index, $cell, $back )
	);
}

assert( "'" . $cells[4] === $parsed[4], 'and the formula is the one that came back changed' );
assert( 'a,b' === $parsed[1], 'a comma did not split a cell' );
assert( "two\nlines" === $parsed[3], 'and a newline did not split a row' );

echo "csv check passed\n";
