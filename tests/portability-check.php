<?php
/**
 * Standalone self-check for export and import.
 *
 * An export is a file somebody will import on another site, possibly a year
 * from now. Two things have to hold: a file this version writes says what it
 * holds and nothing else, and a file any version wrote is still recognised.
 * The third — that importing the same file twice updates rather than
 * duplicates — needs a database and lives in tests/integration.php.
 *
 * Run with `php tests/portability-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Container;
use WPCMB\Database\Portability;

$GLOBALS['wpcmb_test_posts'] = array();

wpcmb_test_record( 11, 'wpcmb_post_type', 'Projects', array( 'name' => 'project', 'labels' => array( 'name' => 'Projects' ) ) );
wpcmb_test_record( 12, 'wpcmb_taxonomy', 'Project Types', array( 'name' => 'project_type' ) );
wpcmb_test_record( 13, 'wpcmb_options_page', 'Theme Options', array( 'slug' => 'theme-options' ) );

$portability = new Portability( new Container() );

/* -------------------------------------------------------------------------
 * The envelope: what a file says about itself.
 * ---------------------------------------------------------------------- */

$all = $portability->export();

assert( Portability::FORMAT === $all['format'], 'an export names the plugin that wrote it' );
assert( WPCMB_VERSION === $all['version'], 'and the version, so a reader knows what it is looking at' );
assert( isset( $all['site'] ) && '' !== $all['site'], 'and the site it came from' );

foreach ( array_keys( Portability::sections() ) as $section ) {
	assert( isset( $all[ $section ] ), $section . ' is present in a full export' );
}

assert( isset( $all['field_groups'] ), 'and so are the field groups' );

/* -------------------------------------------------------------------------
 * Asking for part of it gets part of it. A deploy script that exports post
 * types must not carry the whole site along with them.
 * ---------------------------------------------------------------------- */

$some = $portability->export( array( 'post_types' ) );

assert( isset( $some['post_types'] ), 'the section asked for is there' );
assert( ! isset( $some['field_groups'] ), 'field groups are not, when they were not asked for' );
assert( ! isset( $some['taxonomies'] ), 'and neither is anything else' );

assert( array( 'post_types' => 1 ) === Portability::summarise( $some ), 'a summary counts what is present and nothing else' );

$counts = Portability::summarise( $all );

assert( 1 === $counts['post_types'], 'one post type record was found' );
assert( 1 === $counts['taxonomies'], 'and one taxonomy' );
assert( 1 === $counts['options_pages'], 'and one options page' );
assert( 0 === $counts['blocks'], 'and no blocks, which is reported as none rather than left out' );

/* -------------------------------------------------------------------------
 * Reading a file back. Everything this plugin has ever exported still
 * imports, because the file somebody has is the one they exported last year.
 * ---------------------------------------------------------------------- */

$round_trip = Portability::normalise( json_decode( (string) wp_json_encode( $all ), true ) );

assert( null !== $round_trip, 'an export this version wrote is recognised' );
assert( isset( $round_trip['post_types'] ), 'and arrives whole' );

$bare = Portability::normalise( array( array( 'key' => 'group_a' ), array( 'key' => 'group_b' ) ) );

assert( null !== $bare && 2 === count( $bare['field_groups'] ), 'a bare list of groups is read as field groups' );

$single = Portability::normalise( array( 'key' => 'group_a', 'fields' => array() ) );

assert( null !== $single && 1 === count( $single['field_groups'] ), 'one group on its own is read as a list of one' );

/* -------------------------------------------------------------------------
 * And what is refused. An import writes to post meta, so a file that is not
 * ours has to be turned away before anything is written, not part way
 * through.
 * ---------------------------------------------------------------------- */

assert( null === Portability::normalise( null ), 'a file that did not decode is refused' );
assert( null === Portability::normalise( 'nope' ), 'and so is a string' );
assert( null === Portability::normalise( 42 ), 'and a number' );
assert( null === Portability::normalise( array() ), 'and an empty array, which decodes but says nothing' );
assert( null === Portability::normalise( array( 'format' => 'some-other-plugin', 'groups' => array() ) ), "and another plugin's export" );
assert( null === Portability::normalise( array( 'title' => 'Not a group' ) ), 'and a keyed array that is not a group' );

/* -------------------------------------------------------------------------
 * The PHP export. It is only useful if it is valid PHP that registers the
 * same things, so it is parsed rather than eyeballed.
 * ---------------------------------------------------------------------- */

$php = $portability->php(
	array(
		'field_groups' => array( array( 'key' => 'group_a', 'title' => 'Group A', 'fields' => array() ) ),
		'post_types'   => array( array( 'name' => 'project' ) ),
	)
);

assert( str_contains( $php, 'wpcmb_register_field_group(' ), 'generated PHP registers the field groups' );
assert( str_contains( $php, "add_filter( 'wpcmb/records/wpcmb_post_type'" ), 'and the records, through the filter each type already applies' );
assert( ! str_contains( $php, 'array (' ), 'and is written the way the rest of the codebase is' );

$tmp = tempnam( sys_get_temp_dir(), 'wpcmb' );

file_put_contents( $tmp, $php );

$output = array();
$status = 0;

exec( sprintf( '%s -l %s 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( (string) $tmp ) ), $output, $status );
unlink( (string) $tmp );

assert( 0 === $status, 'and it parses: ' . implode( ' ', $output ) );

echo "portability check passed\n";
