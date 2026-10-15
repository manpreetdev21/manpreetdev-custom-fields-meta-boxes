<?php
/**
 * The Block Types editor screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\RecordEditor;
use WPCMB\Abstracts\RecordType;
use WPCMB\Fields\Registry;
use WPCMB\PostTypes\CustomBlocks;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves one block record.
 */
final class BlockEditor extends RecordEditor {

	/**
	 * The record type this screen edits.
	 */
	protected function type(): RecordType {
		return $this->container->get( CustomBlocks::class );
	}

	/**
	 * The request key its controls post under.
	 */
	protected function request_key(): string {
		return 'wpcmb_block';
	}

	/**
	 * The placeholder for the title field.
	 */
	protected function title_hint(): string {
		return __( 'Block title, e.g. Testimonial', 'metafields-custom-fields' );
	}

	/**
	 * What to say under a refusal.
	 */
	protected function rejection_consequence(): string {
		return __( 'The record was saved, but no block is registered from it until this is fixed.', 'metafields-custom-fields' );
	}

	/**
	 * Why a configuration cannot register a block.
	 *
	 * @param array<string, mixed> $config  Cleaned configuration.
	 * @param int                  $post_id The record being saved.
	 */
	protected function rejection( array $config, int $post_id ): string {
		return $this->container->get( CustomBlocks::class )->rejection( $config, $post_id );
	}

	/**
	 * Register the editor meta boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box( 'wpcmb-block-basic', __( 'Basic Settings', 'metafields-custom-fields' ), array( $this, 'render_basic' ), CustomBlocks::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'wpcmb-block-editor', __( 'In the Editor', 'metafields-custom-fields' ), array( $this, 'render_editor' ), CustomBlocks::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'wpcmb-block-supports', __( 'Features', 'metafields-custom-fields' ), array( $this, 'render_supports' ), CustomBlocks::POST_TYPE, 'normal', 'low' );
		add_meta_box( 'wpcmb-block-appearance', __( 'Appearance', 'metafields-custom-fields' ), array( $this, 'render_appearance' ), CustomBlocks::POST_TYPE, 'side', 'default' );
	}

	/**
	 * Render the name and the field group it draws.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_basic( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->nonce_field();

		echo '<p class="description">' . esc_html__( 'Publish this record to register the block. Saving it as a draft keeps it defined but inactive.', 'metafields-custom-fields' ) . '</p>';

		$this->text(
			'name',
			__( 'Block name', 'metafields-custom-fields' ),
			(string) $config['name'],
			__( 'Lowercase. This becomes part of the block\'s identity in saved content, so changing it leaves existing blocks unrecognised.', 'metafields-custom-fields' ),
			array(
				'placeholder' => 'testimonial',
				'required'    => 'required',
			)
		);

		$this->field_group_select(
			(string) $config['field_group'],
			__( 'The block shows this group\'s fields. Edit the group to change what the block asks for.', 'metafields-custom-fields' )
		);

		$this->text( 'description', __( 'Description', 'metafields-custom-fields' ), (string) $config['description'], __( 'Shown in the block inserter.', 'metafields-custom-fields' ) );
		$this->text( 'keywords', __( 'Keywords', 'metafields-custom-fields' ), (string) $config['keywords'], __( 'Comma separated. What somebody might type in the inserter to find it.', 'metafields-custom-fields' ) );
	}

	/**
	 * Render the editing behaviour.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_editor( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->select(
			'mode',
			__( 'How it behaves in the editor', 'metafields-custom-fields' ),
			(string) $config['mode'],
			array(
				'auto'    => __( 'Preview, with the fields on click', 'metafields-custom-fields' ),
				'preview' => __( 'Preview only', 'metafields-custom-fields' ),
				'edit'    => __( 'Fields only', 'metafields-custom-fields' ),
			),
			__( 'A block whose template renders nothing visible is easier to work with as fields.', 'metafields-custom-fields' )
		);

		$this->toggle(
			'inner_blocks',
			__( 'Allow other blocks inside it', 'metafields-custom-fields' ),
			(bool) $config['inner_blocks'],
			__( 'The template decides where they go, with wpcmb_inner_blocks().', 'metafields-custom-fields' )
		);
	}

	/**
	 * Render the block supports.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_supports( \WP_Post $post ): void {
		$config = $this->config( $post );

		echo '<p class="description">' . esc_html__( 'The controls WordPress adds to the block sidebar for you.', 'metafields-custom-fields' ) . '</p>';

		$this->checkboxes( 'supports', __( 'Supports', 'metafields-custom-fields' ), CustomBlocks::features(), (array) $config['supports'] );
	}

	/**
	 * Render the icon and category.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_appearance( \WP_Post $post ): void {
		$config = $this->config( $post );

		printf( '<p><span class="wpcmb-label">%s</span>', esc_html__( 'Icon', 'metafields-custom-fields' ) );

		// The plugin's own icon field, the same one the post type screen uses.
		$this->container->get( Registry::class )->get( 'icon' )->render(
			array(
				'key'      => 'field_wpcmb_block_icon',
				'name'     => 'icon',
				'type'     => 'icon',
				'settings' => array(),
			),
			(string) $config['icon'],
			'wpcmb_block[icon]',
			'wpcmb-block-icon'
		);

		printf( '<span class="description wpcmb-help">%s</span></p>', esc_html__( 'Shown in the inserter. Dashicons only — the editor draws it, not this plugin.', 'metafields-custom-fields' ) );

		$this->text(
			'category',
			__( 'Category', 'metafields-custom-fields' ),
			(string) $config['category'],
			__( 'An inserter category. Left empty, the block goes in this plugin\'s own.', 'metafields-custom-fields' )
		);
	}
}
