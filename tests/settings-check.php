<?php
/**
 * Standalone self-check for the group and field settings.
 *
 * Every setting here is one somebody ticks in the editor and expects to
 * change what the plugin does. A setting that is stored but not read is the
 * worst kind of bug — it looks like it worked — so each one is checked at the
 * point where it is supposed to take effect.
 *
 * Run with `php tests/settings-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\FieldTypes\Choice;
use WPCMB\FieldTypes\Enhanced;
use WPCMB\FieldTypes\Input;
use WPCMB\FieldTypes\Media;
use WPCMB\FieldTypes\Structure;
use WPCMB\Fields\FieldGroup;

/**
 * A field definition.
 *
 * @param string               $type     Field type.
 * @param array<string, mixed> $settings Type settings.
 * @param array<string, mixed> $extra    Other field properties.
 *
 * @return array<string, mixed>
 */
function wpcmb_setting_field( string $type, array $settings = array(), array $extra = array() ): array {
	return array_merge( array( 'type' => $type, 'name' => 'f', 'settings' => $settings ), $extra );
}

/**
 * What a field type renders, as markup.
 *
 * @param object               $handler Field type.
 * @param array<string, mixed> $field   Field definition.
 * @param mixed                $value   Current value.
 */
function wpcmb_render( object $handler, array $field, mixed $value = '' ): string {
	ob_start();
	$handler->render( $field, $value, 'wpcmb_values[f]', 'wpcmb-f' );

	return (string) ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Group settings.
 * ---------------------------------------------------------------------- */

$defaults = FieldGroup::default_settings();

// The instructions have always rendered below the control. Defaulting the new
// setting to anything else would silently move them on every existing screen.
assert( 'field' === $defaults['instruction_placement'], 'instructions stay where they were by default' );
assert( true === $defaults['show_in_rest'], 'a group is exposed to REST by default' );
assert( 'manage_options' === $defaults['options_capability'], 'an options page defaults to manage_options' );

$group = FieldGroup::sanitize(
	array(
		'title'    => 'G',
		'settings' => array(
			'instruction_placement' => 'label',
			'display_title'         => ' Pricing ',
			'options_capability'    => 'edit_pages',
			'options_parent'        => 'tools.php',
			'options_icon'          => 'dashicons-cart',
			'hide_on_screen'        => array( 'custom_fields', 'page_attributes' ),
		),
	)
);

assert( 'label' === $group['settings']['instruction_placement'], 'instruction placement is stored' );
assert( 'Pricing' === $group['settings']['display_title'], 'a display title is trimmed and stored' );
assert( 'edit_pages' === $group['settings']['options_capability'], 'a capability is stored' );
assert( 'tools.php' === $group['settings']['options_parent'], 'a menu parent is stored' );
assert( in_array( 'custom_fields', $group['settings']['hide_on_screen'], true ), 'the custom fields box can be hidden' );
assert( in_array( 'page_attributes', $group['settings']['hide_on_screen'], true ), 'page attributes can be hidden' );

// A parent outside the list would be a menu file we do not know is there.
$bad_parent = FieldGroup::sanitize( array( 'settings' => array( 'options_parent' => 'evil.php' ) ) );
assert( '' === $bad_parent['settings']['options_parent'], 'an unknown menu parent falls back to top level' );

// A page nobody can reach is worse than one with the default capability.
$no_cap = FieldGroup::sanitize( array( 'settings' => array( 'options_capability' => '' ) ) );
assert( 'manage_options' === $no_cap['settings']['options_capability'], 'an empty capability falls back' );

/*
 * show_in_rest is the one setting where absence and "off" have to mean
 * different things: every group saved before it existed has no value for it
 * and must stay exposed, while the editor says "off" with an explicit 0.
 */
$legacy = FieldGroup::sanitize( array( 'settings' => array( 'style' => 'seamless' ) ) );
assert( true === $legacy['settings']['show_in_rest'], 'a group saved before the setting existed stays exposed' );

$hidden = FieldGroup::sanitize( array( 'settings' => array( 'show_in_rest' => '0' ) ) );
assert( false === $hidden['settings']['show_in_rest'], 'an unchecked box turns REST exposure off' );

/* -------------------------------------------------------------------------
 * Input: prepend and append.
 * ---------------------------------------------------------------------- */

$input = new Input();

$plain = wpcmb_render( $input, wpcmb_setting_field( 'text' ) );
assert( ! str_contains( $plain, 'wpcmb-affix' ), 'a field with no affix gets no wrapper' );

$affixed = wpcmb_render( $input, wpcmb_setting_field( 'number', array( 'prepend' => '$', 'append' => 'per month' ) ) );
assert( str_contains( $affixed, 'wpcmb-affix__text' ), 'an affix is rendered' );
assert( str_contains( $affixed, 'per month' ), 'the append text is rendered' );

// An affix is decoration. If it were announced it would be read twice: once
// as part of the label, once beside the box.
assert( 2 === substr_count( $affixed, 'aria-hidden="true"' ), 'both affixes are hidden from assistive tech' );

/* -------------------------------------------------------------------------
 * Choice: the empty option, the layout, and other values.
 * ---------------------------------------------------------------------- */

$choice  = new Choice();
$choices = array( 'choices' => "a : Apple\nb : Banana" );

$with_null = wpcmb_render( $choice, wpcmb_setting_field( 'select', $choices ) );
assert( str_contains( $with_null, '<option value="">' ), 'a dropdown offers an empty choice by default' );

$without_null = wpcmb_render( $choice, wpcmb_setting_field( 'select', $choices + array( 'allow_null' => '0' ) ) );
assert( ! str_contains( $without_null, '<option value="">' ), 'the empty choice can be turned off' );

$row = wpcmb_render( $choice, wpcmb_setting_field( 'radio', $choices + array( 'layout' => 'horizontal' ) ) );
assert( str_contains( $row, 'wpcmb-choices--row' ), 'choices can be laid out in a row' );

$column = wpcmb_render( $choice, wpcmb_setting_field( 'radio', $choices ) );
assert( ! str_contains( $column, 'wpcmb-choices--row' ), 'and stay in a column by default' );

$toggle_all = wpcmb_render( $choice, wpcmb_setting_field( 'checkbox', $choices + array( 'toggle_all' => '1' ) ) );
assert( str_contains( $toggle_all, 'data-wpcmb-toggle-all' ), 'select-all is offered when asked for' );

// Select-all on a single-value list would be a control that cannot do
// anything, so it is not drawn.
$single = wpcmb_setting_field( 'checkbox', $choices + array( 'toggle_all' => '1', 'multiple' => '0' ) );
assert( ! str_contains( wpcmb_render( $choice, $single ), 'data-wpcmb-toggle-all' ), 'select-all needs a multi-value list' );

$strict = wpcmb_setting_field( 'checkbox', $choices );
assert( array( 'a' ) === $choice->sanitize( array( 'a', 'zzz' ), $strict ), 'an undeclared value is dropped' );

$loose = wpcmb_setting_field( 'checkbox', $choices + array( 'allow_custom' => '1' ) );
assert( array( 'a', 'Pear' ) === $choice->sanitize( array( 'a', 'Pear' ), $loose ), 'with other values on, a typed value is kept' );
assert( array( 'Pear', 'Plum' ) === $choice->sanitize( array( 'Pear, Plum' ), $loose ), 'the box splits on commas' );
assert( array( 'a' ) === $choice->sanitize( array( 'a', '' ), $loose ), 'an empty box adds nothing' );

/*
 * Free text reaches storage only here, so it is the one place a choice field
 * has to clean rather than compare. A script element is removed whole — tag
 * and contents — which leaves nothing, and nothing is not stored.
 */
assert( array() === $choice->sanitize( array( '<script>alert(1)</script>' ), $loose ), 'a typed script element is dropped' );
assert( array( 'Pear' ) === $choice->sanitize( array( '<b>Pear</b>' ), $loose ), 'and markup around a typed value is stripped' );

// A value typed once has to come back as a normal ticked choice, or the next
// save would silently drop what the field is still holding.
$stored = wpcmb_render( $choice, $loose, array( 'Pear' ) );
assert( str_contains( $stored, 'value="Pear"' ) && str_contains( $stored, 'checked' ), 'a stored custom value is offered back, ticked' );

/* -------------------------------------------------------------------------
 * Separator.
 * ---------------------------------------------------------------------- */

$structure = new Structure();

assert( isset( $structure->types()['separator'] ), 'separator is a type' );
assert( ! Structure::stores_value( wpcmb_setting_field( 'separator' ) ), 'a separator stores nothing' );

$rule = wpcmb_render( $structure, wpcmb_setting_field( 'separator' ) );
assert( str_contains( $rule, 'role="separator"' ), 'an unlabelled separator is announced as one' );

$labelled = wpcmb_render( $structure, wpcmb_setting_field( 'separator', array(), array( 'label' => 'Shipping' ) ) );
assert( str_contains( $labelled, 'Shipping' ), 'a labelled separator carries its legend' );
assert( ! str_contains( $labelled, 'role="separator"' ), 'a legend is the heading, so the role is not repeated' );

/* -------------------------------------------------------------------------
 * Media: file types, file sizes and image dimensions.
 *
 * The picker filters the library as a convenience. These are the rules, and
 * they are checked here because a value can also arrive from a form, the
 * REST API or code, none of which open a picker.
 * ---------------------------------------------------------------------- */

$media = new Media();

$small = tempnam( sys_get_temp_dir(), 'wpcmb' );
file_put_contents( (string) $small, str_repeat( 'x', 2048 ) );

$large = tempnam( sys_get_temp_dir(), 'wpcmb' );
file_put_contents( (string) $large, str_repeat( 'x', 600 * 1024 ) );

wpcmb_test_attachment( 11, 'image/png', (string) $small . '.png', array( 'width' => 400, 'height' => 300 ) );
wpcmb_test_attachment( 12, 'application/pdf', (string) $small . '.pdf' );
wpcmb_test_attachment( 13, 'image/png', (string) $large . '.png', array( 'width' => 4000, 'height' => 3000 ) );

// The files on disk are what the size checks measure, so the paths have to
// be the real ones.
rename( (string) $small, (string) $small . '.png' );
copy( (string) $small . '.png', (string) $small . '.pdf' );
rename( (string) $large, (string) $large . '.png' );

$any = wpcmb_setting_field( 'image' );
assert( '' === $media->validate( 11, $any ), 'with no limits, anything is allowed' );

$pdf_only = wpcmb_setting_field( 'file', array( 'mime_types' => 'pdf' ) );
assert( '' === $media->validate( 12, $pdf_only ), 'an allowed extension passes' );
assert( '' !== $media->validate( 11, $pdf_only ), 'a disallowed extension is refused' );

// The list takes a family or a full mime type as well as an extension,
// because that is what the picker's own filter takes.
$images = wpcmb_setting_field( 'file', array( 'mime_types' => 'image' ) );
assert( '' === $media->validate( 11, $images ), 'a mime family matches' );

$exact = wpcmb_setting_field( 'file', array( 'mime_types' => 'image/png' ) );
assert( '' === $media->validate( 11, $exact ), 'a full mime type matches' );

$capped = wpcmb_setting_field( 'image', array( 'max_size' => '100' ) );
assert( '' === $media->validate( 11, $capped ), 'a small file passes a size cap' );
assert( '' !== $media->validate( 13, $capped ), 'a large file does not' );

// A bare number is kilobytes, which is how the media library writes its own
// limits. A number with a unit means that unit.
$mb = wpcmb_setting_field( 'image', array( 'max_size' => '1MB' ) );
assert( '' === $media->validate( 13, $mb ), '600KB fits in a 1MB cap' );

$floor = wpcmb_setting_field( 'image', array( 'min_size' => '10' ) );
assert( '' !== $media->validate( 11, $floor ), 'a file below the floor is refused' );

$narrow = wpcmb_setting_field( 'image', array( 'min_width' => 800 ) );
assert( '' !== $media->validate( 11, $narrow ), 'an image narrower than the minimum is refused' );
assert( '' === $media->validate( 13, $narrow ), 'and a wide enough one is not' );

$short = wpcmb_setting_field( 'image', array( 'max_height' => 500 ) );
assert( '' === $media->validate( 11, $short ), 'an image within the height cap passes' );
assert( '' !== $media->validate( 13, $short ), 'and one above it is refused' );

// Dimensions are only knowable for an image. Refusing a PDF for being the
// wrong shape would be refusing it for a measurement nobody has.
assert( '' === $media->validate( 12, $narrow ), 'a non-image is not judged on dimensions' );

// Several ids, one verdict: the first file that breaks a rule is the one
// reported, so a gallery cannot sneak a bad file in behind a good one.
$gallery = wpcmb_setting_field( 'gallery', array( 'max_height' => 500 ) );
assert( '' !== $media->validate( array( 11, 13 ), $gallery ), 'every item in a gallery is checked' );

foreach ( array( $small . '.png', $small . '.pdf', $large . '.png' ) as $path ) {
	if ( is_file( (string) $path ) ) {
		unlink( (string) $path );
	}
}

/* -------------------------------------------------------------------------
 * Embed sizes.
 * ---------------------------------------------------------------------- */

$enhanced = new Enhanced();

// The settings exist on the embed type and nowhere else, because oEmbed is
// the only thing here that takes a size.
assert( isset( $enhanced->settings_schema( 'embed' )['width'] ), 'an embed can be given a width' );
assert( ! isset( $enhanced->settings_schema( 'signature' )['width'] ), 'a signature cannot' );

echo "settings-check: OK\n";
