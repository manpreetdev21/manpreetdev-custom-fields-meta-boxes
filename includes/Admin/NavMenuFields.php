<?php
/**
 * Field groups on nav menu items.
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
 * Renders and saves field groups located on a nav menu item.
 *
 * The `nav_menu_item` location rule matched from the first release and had
 * nothing hooked to it, the same as widgets did.
 *
 * A menu item is a post — of the `nav_menu_item` type — so there is no new
 * storage here at all: values are post meta on the item, they are deleted
 * with it, and `wpcmb_get_field( 'icon', $item->ID )` works in a walker
 * without anything being told about menus.
 */
final class NavMenuFields extends Module {

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
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Field groups that apply to one menu item.
	 *
	 * @param int $item_id Menu item post id.
	 *
	 * @return array<int, \WPCMB\Fields\FieldGroup>
	 */
	private function groups( int $item_id ): array {
		return $this->container->get( Resolver::class )->groups(
			new Context( new ObjectRef( ObjectRef::POST, $item_id ) )
		);
	}

	/**
	 * Render matching groups inside a menu item's panel.
	 *
	 * @param int           $item_id Menu item id.
	 * @param \WP_Post|null $item    Menu item.
	 */
	public function render( $item_id, $item = null ): void {
		$item_id = (int) $item_id;

		if ( $item_id <= 0 ) {
			return;
		}

		$groups = $this->groups( $item_id );

		if ( array() === $groups ) {
			return;
		}

		$ref      = new ObjectRef( ObjectRef::POST, $item_id );
		$renderer = $this->container->get( Renderer::class );

		/*
		 * Every item's fields post together, under the item's own id.
		 *
		 * The menu screen submits one form for the whole menu, so a field
		 * name that did not carry the item id would be the same name on every
		 * item and the last one would win.
		 */
		$prefix = sprintf( '%s[%d]', Renderer::INPUT_PREFIX, $item_id );

		// One nonce for the menu form, not one per item: the screen posts
		// every item at once and save() runs per item against that one nonce.
		wp_nonce_field( 'wpcmb_save_nav_menu', 'wpcmb_nav_menu_nonce', false );

		foreach ( $groups as $group ) {
			echo '<div class="wpcmb-menu-fields">';

			if ( '' !== $group->title ) {
				printf( '<p class="wpcmb-menu-fields__title"><strong>%s</strong></p>', esc_html( $group->title ) );
			}

			$renderer->group( $group, $ref, $prefix );

			echo '</div>';
		}
	}

	/**
	 * Store one menu item's submitted values.
	 *
	 * Fires once per item as WordPress walks the menu, so this reads only the
	 * slice of the submission belonging to the item it was handed.
	 *
	 * @param int $menu_id Menu term id.
	 * @param int $item_id Menu item post id.
	 */
	public function save( $menu_id, $item_id ): void {
		$item_id = (int) $item_id;

		if ( $item_id <= 0 ) {
			return;
		}

		if ( ! isset( $_POST['wpcmb_nav_menu_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpcmb_nav_menu_nonce'] ) ), 'wpcmb_save_nav_menu' )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized by its own field type below; a blanket sanitizer here would flatten the arrays composite types post.
		$all = isset( $_POST[ Renderer::INPUT_PREFIX ] ) && is_array( $_POST[ Renderer::INPUT_PREFIX ] )
			? wp_unslash( $_POST[ Renderer::INPUT_PREFIX ] )
			: array();

		if ( ! isset( $all[ $item_id ] ) || ! is_array( $all[ $item_id ] ) ) {
			return;
		}

		$submitted = $all[ $item_id ];

		$ref      = new ObjectRef( ObjectRef::POST, $item_id );
		$renderer = $this->container->get( Renderer::class );
		$values   = $this->container->get( Values::class );
		$fields   = $this->container->get( Resolver::class )->fields( new Context( $ref ) );

		foreach ( $fields as $name => $field ) {
			if ( ! $renderer->stores_value( $field ) || ! array_key_exists( $name, $submitted ) ) {
				continue;
			}

			$values->update( $name, $submitted[ $name ], $ref );
		}
	}
}
