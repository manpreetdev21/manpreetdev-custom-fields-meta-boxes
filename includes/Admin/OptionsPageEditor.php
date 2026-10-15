<?php
/**
 * The Options Pages editor screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\RecordEditor;
use WPCMB\Abstracts\RecordType;
use WPCMB\Fields\Registry;
use WPCMB\PostTypes\CustomOptionsPages;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves one options page record.
 */
final class OptionsPageEditor extends RecordEditor {

	/**
	 * The record type this screen edits.
	 */
	protected function type(): RecordType {
		return $this->container->get( CustomOptionsPages::class );
	}

	/**
	 * The request key its controls post under.
	 */
	protected function request_key(): string {
		return 'wpcmb_options_page';
	}

	/**
	 * The placeholder for the title field.
	 */
	protected function title_hint(): string {
		return __( 'Page name, e.g. Site Options', 'metafields-custom-fields' );
	}

	/**
	 * What to say under a refusal.
	 */
	protected function rejection_consequence(): string {
		return __( 'The record was saved, but no page is built from it until this is fixed.', 'metafields-custom-fields' );
	}

	/**
	 * Why a configuration cannot build a page.
	 *
	 * @param array<string, mixed> $config  Cleaned configuration.
	 * @param int                  $post_id The record being saved.
	 */
	protected function rejection( array $config, int $post_id ): string {
		return $this->container->get( CustomOptionsPages::class )->rejection( $config, $post_id );
	}

	/**
	 * Rebuild the rewrite rules only when something needs it.
	 *
	 * @param array<string, mixed> $config    Stored configuration.
	 * @param string               $rejection Refusal, empty when fine.
	 */
	protected function after_save( array $config, string $rejection ): void {
		// An options page is an admin screen; it has no permalinks. Nothing
		// to flush, unlike the post type and taxonomy records.
	}

	/**
	 * Register the editor meta boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box( 'wpcmb-op-basic', __( 'Basic Settings', 'metafields-custom-fields' ), array( $this, 'render_basic' ), CustomOptionsPages::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'wpcmb-op-fields', __( 'Fields on This Page', 'metafields-custom-fields' ), array( $this, 'render_fields' ), CustomOptionsPages::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'wpcmb-op-menu', __( 'Menu', 'metafields-custom-fields' ), array( $this, 'render_menu' ), CustomOptionsPages::POST_TYPE, 'side', 'default' );
	}

	/**
	 * Render the slug and titles.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_basic( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->nonce_field();

		echo '<p class="description">' . esc_html__( 'Publish this record to build the page. Saving it as a draft keeps it defined but inactive.', 'metafields-custom-fields' ) . '</p>';

		$this->text(
			'slug',
			__( 'Slug', 'metafields-custom-fields' ),
			(string) $config['slug'],
			__( 'Every value on the page is stored as wpcmb_{slug}_{field}, so changing it orphans what is already saved.', 'metafields-custom-fields' ),
			array(
				'placeholder' => 'site-settings',
				'required'    => 'required',
			)
		);

		echo '<div class="wpcmb-grid-2">';

		$this->text( 'menu_title', __( 'Menu title', 'metafields-custom-fields' ), (string) $config['menu_title'], __( 'Defaults to this record\'s name.', 'metafields-custom-fields' ) );
		$this->text( 'page_title', __( 'Page title', 'metafields-custom-fields' ), (string) $config['page_title'], __( 'The heading on the page itself.', 'metafields-custom-fields' ) );

		echo '</div>';
	}

	/**
	 * Explain how fields get onto the page.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_fields( \WP_Post $post ): void {
		$config = $this->config( $post );
		$slug   = (string) $config['slug'];

		if ( '' === $slug ) {
			printf( '<p class="description">%s</p>', esc_html__( 'Give the page a slug and this will tell you which field groups appear on it.', 'metafields-custom-fields' ) );

			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: the options page slug. */
					__( 'A field group appears on this page when one of its location rules says: Options Page is equal to %s. That is still how the fields get here — this record decides the page, the rules decide its contents.', 'metafields-custom-fields' ),
					$slug
				)
			)
		);

		$groups = $this->container->get( \WPCMB\Fields\Resolver::class )->groups(
			new \WPCMB\Fields\Context( new \WPCMB\Fields\ObjectRef( \WPCMB\Fields\ObjectRef::OPTION, $slug ) )
		);

		if ( array() === $groups ) {
			printf(
				'<p><strong>%s</strong></p>',
				esc_html__( 'No field group points at this page yet, so it will render empty.', 'metafields-custom-fields' )
			);

			return;
		}

		echo '<ul class="wpcmb-record-list">';

		foreach ( $groups as $group ) {
			printf(
				'<li><a href="%s">%s</a> <code>%s</code></li>',
				esc_url( get_edit_post_link( $group->id ) ?? '' ),
				esc_html( '' !== $group->title ? $group->title : $group->key ),
				esc_html( $group->key )
			);
		}

		echo '</ul>';
	}

	/**
	 * Render where the page sits in the menu.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_menu( \WP_Post $post ): void {
		$config = $this->config( $post );

		$others = array();

		foreach ( $this->container->get( CustomOptionsPages::class )->records() as $id => $record ) {
			if ( $id === $post->ID || '' === (string) ( $record['slug'] ?? '' ) ) {
				continue;
			}

			$others[ (string) $record['slug'] ] = (string) ( $record['menu_title'] ?? $record['title'] ?? $record['slug'] );
		}

		$this->select(
			'parent',
			__( 'Menu location', 'metafields-custom-fields' ),
			(string) $config['parent'],
			CustomOptionsPages::parents( $others ),
			__( 'Another options page can be the parent, which is how a settings area gets sub-pages.', 'metafields-custom-fields' )
		);

		$this->text(
			'capability',
			__( 'Capability', 'metafields-custom-fields' ),
			(string) $config['capability'],
			__( 'Who may open and save the page.', 'metafields-custom-fields' ),
			array( 'placeholder' => 'manage_options' )
		);

		printf( '<p><span class="wpcmb-label">%s</span>', esc_html__( 'Menu icon', 'metafields-custom-fields' ) );

		$this->container->get( Registry::class )->get( 'icon' )->render(
			array(
				'key'      => 'field_wpcmb_op_icon',
				'name'     => 'icon',
				'type'     => 'icon',
				'settings' => array(),
			),
			(string) $config['icon'],
			'wpcmb_options_page[icon]',
			'wpcmb-op-icon'
		);

		printf( '<span class="description wpcmb-help">%s</span></p>', esc_html__( 'Only used for a top-level menu.', 'metafields-custom-fields' ) );

		$this->text( 'position', __( 'Menu position', 'metafields-custom-fields' ), (string) $config['position'], __( '0 keeps the default place, below Settings.', 'metafields-custom-fields' ), array( 'type' => 'number' ) );
	}
}
