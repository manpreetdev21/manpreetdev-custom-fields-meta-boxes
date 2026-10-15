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


/* -------------------------------------------------------------------------
 * Taxonomies: the same shape, with its own reserved list and key length.
 * ---------------------------------------------------------------------- */

$taxonomies = new WPCMB\PostTypes\CustomTaxonomies( new WPCMB\Container() );

$clean = $taxonomies->sanitize( array( 'name' => 'Project Type' ) );

assert( 'project_type' === $clean['name'], 'a typed name becomes a taxonomy key' );

// A taxonomy key may be 32 characters, where a post type's is 20.
$long = $taxonomies->sanitize( array( 'name' => str_repeat( 'abcdefgh', 8 ) ) );

assert( 32 === strlen( $long['name'] ), 'a taxonomy key is cut to 32 characters' );

foreach ( array( 'category', 'post_tag', 'post_format', 'nav_menu', 'link_category', 'terms', 'taxonomy' ) as $reserved ) {
	assert( '' !== $taxonomies->rejection( $reserved ), $reserved . ' is reserved and refused' );
}

assert( '' !== $taxonomies->rejection( 'wp_anything' ), 'and anything beginning wp_' );
assert( '' === $taxonomies->rejection( 'project_type' ), 'a name nobody has taken is allowed' );

// A post type key is cleaned the same way the post type record cleans it, so
// a join written on either screen names the same thing.
$attached = $taxonomies->sanitize( array( 'name' => 'x', 'post_types' => array( 'project', '', 'project', 'Bad Name' ) ) );

assert( array( 'project', 'badname' ) === $attached['post_types'], 'post types are cleaned and de-duplicated' );

/* -------------------------------------------------------------------------
 * The labels differ by whether terms nest.
 * ---------------------------------------------------------------------- */

$record = array_merge(
	WPCMB\PostTypes\CustomTaxonomies::defaults(),
	array( 'name' => 'project_type', 'title' => 'Project Types', 'singular' => 'Project Type', 'plural' => 'Project Types' )
);

$nested = $taxonomies->arguments( array_merge( $record, array( 'hierarchical' => true ) ) );
$flat   = $taxonomies->arguments( array_merge( $record, array( 'hierarchical' => false ) ) );

assert( 'Parent Project Type' === $nested['labels']['parent_item'], 'a nested taxonomy gets parent wording' );
assert( ! isset( $flat['labels']['parent_item'] ), 'and a flat one does not' );
assert( isset( $flat['labels']['separate_items_with_commas'] ), 'a flat taxonomy gets tag wording' );
assert( ! isset( $nested['labels']['separate_items_with_commas'] ), 'and a nested one does not' );

// The rewrite carries the nesting too, or term URLs come out flat.
assert( true === $nested['rewrite']['hierarchical'], 'nested terms get nested URLs' );
assert( 'project_type' === $nested['rewrite']['slug'], 'the URL slug falls back to the key' );

$slugged = $taxonomies->arguments( array_merge( $record, array( 'rewrite_slug' => 'project-type' ) ) );

assert( 'project-type' === $slugged['rewrite']['slug'], 'and an explicit slug wins' );

$unrewritten = $taxonomies->arguments( array_merge( $record, array( 'rewrite' => false ) ) );

assert( false === $unrewritten['rewrite'], 'permalinks can be turned off' );

/* -------------------------------------------------------------------------
 * What the post type editor offers.
 * ---------------------------------------------------------------------- */

assert( method_exists( $taxonomies, 'defined' ), 'the editor can ask what records define' );


/* -------------------------------------------------------------------------
 * Blocks, forms and options pages: the three that point at a field group.
 * ---------------------------------------------------------------------- */

$blocks = new WPCMB\PostTypes\CustomBlocks( new WPCMB\Container() );

$clean = $blocks->sanitize(
	array(
		'field_group' => 'group_abc123',
		'name'        => 'Testimonial Block',
		'mode'        => 'nonsense',
		'supports'    => array( 'align', 'invented' ),
	)
);

assert( 'testimonial_block' === $clean['name'], 'a block name is normalised like every other key' );
assert( 'group_abc123' === $clean['field_group'], 'a group key is kept as it is' );
assert( 'auto' === $clean['mode'], 'an unknown editor mode falls back to auto' );
assert( array( 'align' ) === $clean['supports'], 'an invented support is dropped' );

// A group key is already key-shaped: normalising it the way a name is would
// invent a key matching no group.
$spaced = $blocks->sanitize( array( 'field_group' => 'group abc', 'name' => 'x' ) );

assert( ! str_contains( $spaced['field_group'], '_abc' ), 'a group key is not re-spelled' );

assert( '' !== $blocks->rejection( array( 'name' => '', 'field_group' => 'group_a' ) ), 'a block with no name is refused' );
assert( '' !== $blocks->rejection( array( 'name' => 'x', 'field_group' => '' ) ), 'a block with no field group is refused' );
assert( '' === $blocks->rejection( array( 'name' => 'x', 'field_group' => 'group_a' ) ), 'and one with both is not' );

/* Forms carry the shortcode's own vocabulary, so one path builds a form. */
$forms = new WPCMB\PostTypes\CustomForms( new WPCMB\Container() );

$defaults = WPCMB\PostTypes\CustomForms::defaults();

assert( array_key_exists( 'field_group', $defaults ), 'a form record names its field group' );
assert( ! array_key_exists( 'group', $defaults ), 'and does not also carry the shortcode attribute for it' );

$form = $forms->sanitize(
	array(
		'field_group' => 'group_abc123',
		'action'      => 'nonsense',
		'post_status' => 'nonsense',
		'guests'      => '1',
		'redirect'    => 'javascript:alert(1)',
		'email'       => 'not an email',
	)
);

assert( 'post' === $form['action'], 'an unknown action falls back to creating an entry' );
assert( 'publish' === $form['post_status'], 'and an unknown status to published' );
assert( '1' === $form['guests'], 'guests is kept as the string the shortcode uses' );
assert( '' === $form['email'], 'a malformed notification address is dropped' );

// A redirect is somewhere this site will send a visitor.
assert( ! str_starts_with( $form['redirect'], 'javascript:' ), 'a javascript redirect is refused' );

// The attributes a record produces are the shortcode's, so Form::render()
// needs no second vocabulary.
$attributes = $forms->attributes( $form );

assert( 'group_abc123' === $attributes['group'], 'the field group becomes the group attribute' );
assert( ! array_key_exists( 'field_group', $attributes ), 'and the record-only key is not passed on' );

/* Options pages gained the one thing rules could never express. */
$pages = new WPCMB\PostTypes\CustomOptionsPages( new WPCMB\Container() );

$page = $pages->sanitize(
	array(
		'slug'       => 'Site Settings',
		'parent'     => 'evil.php',
		'capability' => '',
	)
);

assert( 'site-settings' === $page['slug'], 'a slug is a slug, not a key' );
assert( '' === $page['parent'], 'a menu parent nobody can prove is there falls back to top level' );
assert( 'manage_options' === $page['capability'], 'an empty capability falls back' );

// Another options page is a valid parent, which is what sub-pages are.
$child = $pages->sanitize( array( 'slug' => 'child', 'parent' => 'wpcmb-options-site-settings' ) );

assert( 'wpcmb-options-site-settings' === $child['parent'], 'another options page can be the parent' );

// And a page cannot be its own parent, which a dropdown makes easy to try.
assert( '' !== $pages->rejection( array( 'slug' => 'site', 'parent' => 'wpcmb-options-site' ) ), 'a page cannot be placed inside itself' );
assert( '' !== $pages->rejection( array( 'slug' => '' ) ), 'a page with no slug is refused' );
assert( '' === $pages->rejection( array( 'slug' => 'site', 'parent' => '' ) ), 'and a sound one is not' );

assert( isset( WPCMB\PostTypes\CustomOptionsPages::parents()[''] ), 'top level is offered as a parent' );
assert( isset( WPCMB\PostTypes\CustomOptionsPages::parents()['tools.php'] ), 'and so are the WordPress menus' );

echo "records-check: OK\n";
