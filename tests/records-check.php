<?php
/**
 * Standalone self-check for the record types.
 *
 * A record is arguments to a WordPress registration function, held in a post.
 * What matters is that untrusted input cannot become arguments WordPress will
 * choke on, and that a record turns into the registration somebody meant.
 *
 * Run with `php tests/records-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\PostTypes\CustomPostTypes;

/**
 * A record type with no container behind it.
 *
 * The modules take a container; nothing being checked here touches it.
 */
function wpcmb_post_types(): CustomPostTypes {
	return new CustomPostTypes( new WPCMB\Container() );
}

$types = wpcmb_post_types();

/* -------------------------------------------------------------------------
 * The key: the one value that cannot be changed later.
 * ---------------------------------------------------------------------- */

$clean = $types->sanitize( array( 'name' => 'Project Items' ) );

assert( 'project_items' === $clean['name'], 'a typed name becomes a post type key' );

// WordPress fails silently past 20 characters, so the boundary cuts first.
$long = $types->sanitize( array( 'name' => str_repeat( 'abcdefgh', 4 ) ) );

assert( 20 === strlen( $long['name'] ), 'a key is cut to the 20 characters WordPress allows' );

$nasty = $types->sanitize( array( 'name' => '<script>alert(1)</script>' ) );

assert( 1 === preg_match( '/^[a-z0-9_-]*$/', $nasty['name'] ), 'a key holds nothing but key characters' );

/* -------------------------------------------------------------------------
 * What is refused, and why — the editor has to be able to say.
 * ---------------------------------------------------------------------- */

assert( '' !== $types->rejection( '' ), 'a record with no key is refused' );
assert( '' !== $types->rejection( 'post' ), 'a core post type is refused' );
assert( '' !== $types->rejection( 'page' ), 'and so is page' );
assert( '' !== $types->rejection( 'revision' ), 'and revision' );
assert( '' !== $types->rejection( 'wp_navigation' ), 'and the wp_ ones core added later' );
assert( '' !== $types->rejection( 'wp_anything' ), 'and anything else beginning wp_' );
assert( '' === $types->rejection( 'project' ), 'a name nobody has taken is allowed' );

// Reserved names are refused by name, not by whether they happen to be
// registered on the site the check runs on.
foreach ( array( 'post', 'page', 'attachment', 'action', 'author', 'order', 'theme', 'fields' ) as $reserved ) {
	assert( '' !== $types->rejection( $reserved ), $reserved . ' is reserved and refused' );
}

/* -------------------------------------------------------------------------
 * Features and taxonomies: only ones that exist.
 * ---------------------------------------------------------------------- */

$supports = $types->sanitize(
	array(
		'name'     => 'project',
		'supports' => array( 'title', 'editor', 'made-up', '<b>x</b>' ),
	)
);

assert( array( 'title', 'editor' ) === $supports['supports'], 'an unknown feature is dropped' );

$deduped = $types->sanitize( array( 'name' => 'p', 'supports' => array( 'title', 'title' ) ) );

assert( array( 'title' ) === $deduped['supports'], 'and a repeated one is stored once' );

/* -------------------------------------------------------------------------
 * The menu icon lands in an attribute, so it is held to two shapes.
 * ---------------------------------------------------------------------- */

$icons = array(
	'dashicons-portfolio'              => 'dashicons-portfolio',
	'https://e.com/icon.png'           => 'https://e.com/icon.png',
	'javascript:alert(1)'              => '',
	'dashicons-x" onload="alert(1)'    => '',
	'data:image/svg+xml;base64,PHN2Zz4' => '',
	'not an icon'                      => '',
);

foreach ( $icons as $given => $expected ) {
	$stored = $types->sanitize( array( 'name' => 'p', 'menu_icon' => $given ) )['menu_icon'];

	assert( $expected === $stored, sprintf( 'icon %s becomes %s', var_export( $given, true ), var_export( $expected, true ) ) );
}

/* -------------------------------------------------------------------------
 * A record becomes registration arguments.
 * ---------------------------------------------------------------------- */

$record = array_merge(
	CustomPostTypes::defaults(),
	array(
		'name'         => 'project',
		'title'        => 'Projects',
		'singular'     => 'Project',
		'plural'       => 'Projects',
		'has_archive'  => true,
		'rewrite'      => true,
		'rewrite_slug' => 'our-work',
		'supports'     => array( 'title', 'thumbnail' ),
	)
);

$args = $types->arguments( $record );

assert( 'Projects' === $args['labels']['name'], 'the plural becomes the name' );
assert( 'Project' === $args['labels']['singular_name'], 'and the singular the singular' );
assert( 'Add Project' === $args['labels']['add_new_item'], 'the rest of the labels are built from them' );
assert( true === $args['has_archive'], 'the archive setting carries over' );
assert( 'our-work' === $args['rewrite']['slug'], 'a URL slug overrides the key' );
assert( array( 'title', 'thumbnail' ) === $args['supports'], 'the features carry over' );

// A slug that was never filled in falls back to the key, not to nothing.
$unslugged = $types->arguments( array_merge( $record, array( 'rewrite_slug' => '' ) ) );

assert( 'project' === $unslugged['rewrite']['slug'], 'an empty URL slug falls back to the key' );

// Permalinks off means no rewrite at all, not a rewrite of nothing.
$unrewritten = $types->arguments( array_merge( $record, array( 'rewrite' => false ) ) );

assert( false === $unrewritten['rewrite'], 'permalinks can be turned off' );

/*
 * A post type that supports nothing is one nobody can fill in. WordPress
 * accepts the empty array and gives you an edit screen with no fields on it,
 * which looks like the plugin is broken.
 */
$empty = $types->arguments( array_merge( $record, array( 'supports' => array() ) ) );

assert( array( 'title' ) === $empty['supports'], 'a post type always supports at least a title' );

// Labels fall back to the record's own title when nobody named it twice.
$untitled = $types->arguments( array_merge( $record, array( 'singular' => '', 'plural' => '' ) ) );

assert( 'Projects' === $untitled['labels']['singular_name'], 'the record title is the fallback label' );

/*
 * menu_position and menu_icon are passed to WordPress as null when unset.
 * Zero is a real position and an empty string is a real icon — both would
 * put a post type at the top of the menu with no icon at all.
 */
$bare = $types->arguments( array_merge( $record, array( 'menu_position' => 0, 'menu_icon' => '' ) ) );

assert( null === $bare['menu_position'], 'an unset menu position is left to WordPress' );
assert( null === $bare['menu_icon'], 'and so is an unset icon' );

echo "records-check: OK\n";
