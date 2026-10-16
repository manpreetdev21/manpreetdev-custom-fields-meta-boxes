<?php
/**
 * Field groups on a nav menu itself.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Context;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Resolver;
use WPCMB\Fields\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves field groups located on a menu, rather than its items.
 *
 * A menu is a term in the `nav_menu` taxonomy, so the values are term meta
 * and nothing new is stored. Getting the fields onto the screen is the whole
 * difficulty.
 *
 * WordPress gives no hook inside the menu editor. The Menus screen has one
 * action, `after_menu_locations_table`, which is on a different tab; the menu
 * settings — auto-add pages, menu locations — are printed inline with nothing
 * around them, and the meta boxes that look like they belong to the menu are
 * inside a second form whose action is "add menu item".
 *
 * So the fields are printed at the end of the page and moved into the menu's
 * own form by a few lines of script, which is what makes them save with the
 * Save Menu button rather than needing a second one. With the script blocked
 * they say so instead of silently failing, the same way the fields builder
 * does.
 */
final class NavMenuSettings extends Module {

	/**
	 * Request key the menu's own fields post under.
	 *
	 * Deliberately not the usual one: menu items post `wpcmb_values[12][x]`
	 * on the same form, and a menu whose id collided with an item id would
	 * otherwise read the wrong values.
	 */
	private const INPUT_PREFIX = 'wpcmb_menu_values';

	/**
	 * Nonce action for the menu's fields.
	 */
	private const NONCE = 'wpcmb_save_nav_menu_settings';

	/**
	 * Only load in the admin.
	 */
	public function is_enabled(): bool {
		return is_admin();
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_footer-nav-menus.php', array( $this, 'render' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'save' ), 10, 1 );
	}

	/**
	 * The menu being edited, or 0.
	 *
	 * Core works this out into a global on this screen, which is the only
	 * statement of which menu the form belongs to — the request can be empty
	 * on a first visit, where core selects one itself.
	 */
	private function current_menu(): int {
		global $nav_menu_selected_id;

		return (int) $nav_menu_selected_id;
	}

	/**
	 * Field groups that apply to one menu.
	 *
	 * @param int $menu_id Menu term id.
	 *
	 * @return array<int, \WPCMB\Fields\FieldGroup>
	 */
	private function groups( int $menu_id ): array {
		return $this->container->get( Resolver::class )->groups(
			new Context( new ObjectRef( ObjectRef::TERM, $menu_id ), array( 'taxonomy' => 'nav_menu' ) )
		);
	}

	/**
	 * Print the menu's fields, ready to be moved into its form.
	 */
	public function render(): void {
		$menu_id = $this->current_menu();

		if ( $menu_id <= 0 ) {
			return;
		}

		$groups = $this->groups( $menu_id );

		if ( array() === $groups ) {
			return;
		}

		$ref      = new ObjectRef( ObjectRef::TERM, $menu_id );
		$renderer = $this->container->get( Renderer::class );

		echo '<div class="wpcmb-menu-settings" data-wpcmb-menu-settings hidden>';

		wp_nonce_field( self::NONCE, 'wpcmb_menu_settings_nonce' );

		foreach ( $groups as $group ) {
			printf(
				'<fieldset class="menu-settings-group wpcmb-menu-settings__group"><legend class="menu-settings-group-name howto">%s</legend>',
				esc_html( '' !== $group->title ? $group->title : __( 'Fields', 'metafields-custom-fields' ) )
			);

			$renderer->group( $group, $ref, self::INPUT_PREFIX );

			echo '</fieldset>';
		}

		printf(
			'<p class="wpcmb-menu-settings__fallback">%s</p>',
			esc_html__( 'These fields need JavaScript on this screen. WordPress provides no place for them in the menu form, so they are moved into it by script; your saved values are unchanged while it is unavailable.', 'metafields-custom-fields' )
		);

		echo '</div>';

		$this->print_script();
	}

	/**
	 * Move the fields into the menu form, where Save Menu will post them.
	 *
	 * Inline rather than a file: it is six lines, it runs on exactly one
	 * admin screen, and it exists only because core has no hook there.
	 */
	private function print_script(): void {
		$script = <<<'JS'
( function () {
	var fields = document.querySelector( '[data-wpcmb-menu-settings]' );
	var form = document.getElementById( 'update-nav-menu' );
	var settings = form ? form.querySelector( '.menu-settings' ) : null;

	if ( ! fields || ! settings ) {
		return;
	}

	settings.appendChild( fields );
	fields.hidden = false;

	// The fallback line is for the case where this script did not run.
	var fallback = fields.querySelector( '.wpcmb-menu-settings__fallback' );

	if ( fallback ) {
		fallback.hidden = true;
	}

	if ( window.wpcmb && window.wpcmb.initFields ) {
		fields.querySelectorAll( '.wpcmb-group-fields' ).forEach( window.wpcmb.initFields );
	}
}() );
JS;

		wp_print_inline_script_tag( $script );
	}

	/**
	 * Store a menu's submitted values.
	 *
	 * @param int $menu_id Menu term id.
	 */
	public function save( $menu_id ): void {
		$menu_id = (int) $menu_id;

		if ( $menu_id <= 0 ) {
			return;
		}

		if ( ! isset( $_POST['wpcmb_menu_settings_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpcmb_menu_settings_nonce'] ) ), self::NONCE )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$submitted = array();

		if ( isset( $_POST[ self::INPUT_PREFIX ] ) && is_array( $_POST[ self::INPUT_PREFIX ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized by its own field type below; a blanket sanitizer here would flatten the arrays composite types post.
			$submitted = wp_unslash( $_POST[ self::INPUT_PREFIX ] );
		}

		$ref      = new ObjectRef( ObjectRef::TERM, $menu_id );
		$renderer = $this->container->get( Renderer::class );
		$values   = $this->container->get( Values::class );

		$fields = $this->container->get( Resolver::class )->fields(
			new Context( $ref, array( 'taxonomy' => 'nav_menu' ) )
		);

		foreach ( $fields as $name => $field ) {
			// A field absent from the submission was not on the form; writing
			// an empty value would wipe what the person never saw.
			if ( ! $renderer->stores_value( $field ) || ! array_key_exists( $name, $submitted ) ) {
				continue;
			}

			$values->update( $name, $submitted[ $name ], $ref );
		}
	}
}
