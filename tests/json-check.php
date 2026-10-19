<?php
/**
 * Standalone self-check for local JSON.
 *
 * The risk here is a sync that offers itself forever, or one that imports a
 * file older than what is already here and silently reverts somebody's work.
 * Both are timestamp comparisons, so both are asserted against a real
 * directory of real files.
 *
 * Run with `php tests/json-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Container;
use WPCMB\Database\LocalJson;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;

/* A directory of our own, removed at the end whatever happens. */
$dir = sys_get_temp_dir() . '/wpcmb-json-check-' . getmypid();

register_shutdown_function(
	static function () use ( $dir ): void {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}

		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}
);

wpcmb_test_filter( 'wpcmb/json/save_path', static fn() => $dir );
wpcmb_test_filter( 'wpcmb/json/load_paths', static fn() => array( $dir ) );

// Syncing writes field groups, so only somebody who may edit them is offered
// the choice. Asserted on its own below.
$GLOBALS['wpcmb_test_can'] = true;

$container = new Container();
$json      = $container->get( LocalJson::class );

assert( $dir === $json->save_path(), 'the save path is filterable' );
assert( array( $dir ) === $json->load_paths(), 'and so are the load paths' );

/*
 * The directory is the switch, and it starts off. A plugin that wrote files
 * into every theme the moment it was activated would be putting files in
 * somebody's repository without being asked.
 */
assert( ! $json->is_writing(), 'no directory means nothing is written' );
assert( array() === $json->files(), 'and nothing is read either' );

$GLOBALS['wpcmb_test_posts'] = array( new WP_Post( 7, 'Early', 'publish', 'wpcmb_field_group' ) );

$GLOBALS['wpcmb_test_meta']['post'][7]['_wpcmb_config'] = wp_json_encode(
	FieldGroup::sanitize( array( 'key' => 'group_early', 'fields' => array() ) )
);

$json->write( 7, array() );

assert( ! is_file( $dir . '/group_early.json' ), 'a save with the directory absent writes no file' );

mkdir( $dir, 0777, true );

assert( $json->is_writing(), 'creating the directory switches writing on' );

$json->write( 7, array() );

assert( is_file( $dir . '/group_early.json' ), 'and then a save does write one' );

$early = json_decode( (string) file_get_contents( $dir . '/group_early.json' ), true );

// The stamp travels inside the file: a checkout gives every file the time it
// was checked out, so the filesystem cannot answer "when did this change".
assert( isset( $early['modified'] ), 'the file records its own modification stamp' );

unlink( $dir . '/group_early.json' );

$GLOBALS['wpcmb_test_posts'] = array();

/**
 * Write a group file by hand, as a checkout would deliver one.
 *
 * @param string $dir      Directory.
 * @param string $key      Group key.
 * @param string $title    Group title.
 * @param int    $modified Modification stamp to record.
 * @param array  $fields   Field definitions.
 */
function wpcmb_write_json( string $dir, string $key, string $title, int $modified, array $fields = array() ): void {
	file_put_contents(
		$dir . '/' . $key . '.json',
		(string) wp_json_encode(
			array(
				'key'      => $key,
				'title'    => $title,
				'fields'   => array() !== $fields ? $fields : array( array( 'name' => 'one', 'type' => 'text' ) ),
				'location' => array(),
				'settings' => array( 'active' => true ),
				'modified' => $modified,
			)
		)
	);
}

/* -------------------------------------------------------------------------
 * Reading the directory.
 * ---------------------------------------------------------------------- */

wpcmb_write_json( $dir, 'group_alpha', 'Alpha', 1000 );
wpcmb_write_json( $dir, 'group_beta', 'Beta', 2000 );

// Not ours: no key, so nothing to register or sync under.
file_put_contents( $dir . '/notes.json', (string) wp_json_encode( array( 'hello' => 'world' ) ) );

// Not JSON at all. A theme folder has a README in it sooner or later.
file_put_contents( $dir . '/README.md', 'Field groups live here.' );

$files = $json->files();

assert( 2 === count( $files ), 'every group file is read and nothing else is, got: ' . count( $files ) );
assert( isset( $files['group_alpha'], $files['group_beta'] ), 'keyed by the group key inside the file' );
assert( 1000 === $files['group_alpha']['modified'], 'carrying the stamp the file records' );

/* -------------------------------------------------------------------------
 * A file with no group in the database registers itself, so a fresh checkout
 * works with no import step at all.
 * ---------------------------------------------------------------------- */

$GLOBALS['wpcmb_test_posts'] = array();

$repository = new Repository();

$json->register( $repository );

assert( $repository->get( 'group_alpha' ) instanceof FieldGroup, 'a file-only group is registered' );
assert( null !== $repository->field_by_name( 'one' ), 'and its fields are findable by name' );
assert( 0 === $repository->get( 'group_alpha' )->id, 'with no post behind it' );

/* -------------------------------------------------------------------------
 * What is offered for sync.
 * ---------------------------------------------------------------------- */

$available = $json->syncable();

assert( 2 === count( $available ), 'a group that is only a file is offered' );
assert( 'Alpha' === ( $available['group_alpha'] ?? '' ), 'by the title its file gives it' );

/*
 * Now the group exists here, saved more recently than its file. Nothing to
 * import: this site is ahead, and offering the file would revert it.
 */
$GLOBALS['wpcmb_test_posts'] = array( new WP_Post( 10, 'Alpha', 'publish', 'wpcmb_field_group' ) );

$GLOBALS['wpcmb_test_meta']['post'][10]['_wpcmb_config'] = wp_json_encode(
	FieldGroup::sanitize( array( 'key' => 'group_alpha', 'fields' => array() ) )
);

$GLOBALS['wpcmb_test_post_modified'][10] = 5000;

$repository = new Repository();

// The group list is cached in the object cache, which outlives the instance
// that filled it — so the posts just seeded are invisible without this.
$repository->flush();

$json = new LocalJson( $wpcmb_container = new Container() );

$wpcmb_container->share( Repository::class, $repository );

$available = $json->syncable();

assert( ! isset( $available['group_alpha'] ), 'a group saved after its file is not offered' );
assert( isset( $available['group_beta'] ), 'but one that is still only a file is' );

/* The file moves ahead, as a pull would move it. */
wpcmb_write_json( $dir, 'group_alpha', 'Alpha from the file', 9000 );

assert( isset( $json->syncable()['group_alpha'] ), 'a file newer than the group is offered' );

/* -------------------------------------------------------------------------
 * Syncing, and settling afterwards.
 *
 * This is the one that bit: comparing the file against the post's own
 * modified time means a file saved on another machine, at a clock ahead of
 * this one, is offered again every time the screen loads.
 * ---------------------------------------------------------------------- */

assert( 1 === $json->sync( array( 'group_alpha' ) ), 'the group is imported' );

$repository->flush();

assert( 'Alpha from the file' === get_post( 10 )->post_title, 'taking the title from the file' );
assert( 'group_alpha' === ( $repository->get( 'group_alpha' )->key ?? '' ), 'and is stored under its own key' );

// The one that bit in testing: comparing the file against the post's modified
// time offers a file saved on a machine whose clock is ahead for ever.
assert( ! isset( $json->syncable()['group_alpha'] ), 'and is not offered again' );
assert( isset( $json->syncable()['group_beta'] ), 'while a group still only a file stays offered' );

/* A key nobody has a file for imports nothing rather than erroring. */
assert( 0 === $json->sync( array( 'group_nothing' ) ), 'a key with no file imports nothing' );
assert( 0 === $json->sync( array() ), 'and neither does an empty request' );

/*
 * And nothing is offered to somebody who could not act on it. Syncing writes
 * field groups, so a reader who may not edit them is not shown a button whose
 * handler would refuse them anyway.
 */
wpcmb_write_json( $dir, 'group_gamma', 'Gamma', 9999 );

assert( isset( $json->syncable()['group_gamma'] ), 'a new file is offered to an editor' );

$GLOBALS['wpcmb_test_can'] = false;

assert( array() === $json->syncable(), 'and to nobody who may not edit field groups' );

$GLOBALS['wpcmb_test_can'] = true;

echo "json check passed\n";
