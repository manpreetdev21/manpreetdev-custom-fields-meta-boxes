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
		return __( 'Block title, e.g. Testimonial', 'manpreetdev-custom-fields-meta-boxes' );
	}

	/**
	 * What to say under a refusal.
	 */
	protected function rejection_consequence(): string {
		return __( 'The record was saved, but no block is registered from it until this is fixed.', 'manpreetdev-custom-fields-meta-boxes' );
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
		add_meta_box( 'wpcmb-block-basic', __( 'Basic Settings', 'manpreetdev-custom-fields-meta-boxes' ), array( $this, 'render_basic' ), CustomBlocks::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'wpcmb-block-editor', __( 'In the Editor', 'manpreetdev-custom-fields-meta-boxes' ), array( $this, 'render_editor' ), CustomBlocks::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'wpcmb-block-supports', __( 'Features', 'manpreetdev-custom-fields-meta-boxes' ), array( $this, 'render_supports' ), CustomBlocks::POST_TYPE, 'normal', 'low' );
		add_meta_box( 'wpcmb-block-appearance', __( 'Appearance', 'manpreetdev-custom-fields-meta-boxes' ), array( $this, 'render_appearance' ), CustomBlocks::POST_TYPE, 'side', 'default' );
	}

	/**
	 * Render the name and the field group it draws.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_basic( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->nonce_field();

		echo '<p class="description">' . esc_html__( 'Publish this record to register the block. Saving it as a draft keeps it defined but inactive.', 'manpreetdev-custom-fields-meta-boxes' ) . '</p>';

		$this->text(
			'name',
			__( 'Block name', 'manpreetdev-custom-fields-meta-boxes' ),
			(string) $config['name'],
			__( 'Lowercase. This becomes part of the block\'s identity in saved content, so changing it leaves existing blocks unrecognised.', 'manpreetdev-custom-fields-meta-boxes' ),
			array(
				'placeholder' => 'testimonial',
				'required'    => 'required',
			)
		);

		$this->field_group_select(
			(string) $config['field_group'],
			__( 'The block shows this group\'s fields. Edit the group to change what the block asks for.', 'manpreetdev-custom-fields-meta-boxes' )
		);

		$this->text( 'description', __( 'Description', 'manpreetdev-custom-fields-meta-boxes' ), (string) $config['description'], __( 'Shown in the block inserter.', 'manpreetdev-custom-fields-meta-boxes' ) );
		$this->text( 'keywords', __( 'Keywords', 'manpreetdev-custom-fields-meta-boxes' ), (string) $config['keywords'], __( 'Comma separated. What somebody might type in the inserter to find it.', 'manpreetdev-custom-fields-meta-boxes' ) );
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
			__( 'How it behaves in the editor', 'manpreetdev-custom-fields-meta-boxes' ),
			(string) $config['mode'],
			array(
				'auto'    => __( 'Preview, with the fields on click', 'manpreetdev-custom-fields-meta-boxes' ),
				'preview' => __( 'Preview only', 'manpreetdev-custom-fields-meta-boxes' ),
				'edit'    => __( 'Fields only', 'manpreetdev-custom-fields-meta-boxes' ),
			),
			__( 'A block whose template renders nothing visible is easier to work with as fields.', 'manpreetdev-custom-fields-meta-boxes' )
		);

		$this->toggle(
			'inner_blocks',
			__( 'Allow other blocks inside it', 'manpreetdev-custom-fields-meta-boxes' ),
			(bool) $config['inner_blocks'],
			__( 'The template decides where they go, with wpcmb_inner_blocks().', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}

	/**
	 * Render the block supports.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_supports( \WP_Post $post ): void {
		$config = $this->config( $post );

		echo '<p class="description">' . esc_html__( 'The controls WordPress adds to the block sidebar for you.', 'manpreetdev-custom-fields-meta-boxes' ) . '</p>';

		$this->checkboxes( 'supports', __( 'Supports', 'manpreetdev-custom-fields-meta-boxes' ), CustomBlocks::features(), (array) $config['supports'] );
	}

	/**
	 * Render the icon and category.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_appearance( \WP_Post $post ): void {
		$config = $this->config( $post );

		printf( '<p><span class="wpcmb-label">%s</span>', esc_html__( 'Icon', 'manpreetdev-custom-fields-meta-boxes' ) );

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

		printf( '<span class="description wpcmb-help">%s</span></p>', esc_html__( 'Shown in the inserter. Dashicons only — the editor draws it, not this plugin.', 'manpreetdev-custom-fields-meta-boxes' ) );

		$this->text(
			'category',
			__( 'Category', 'manpreetdev-custom-fields-meta-boxes' ),
			(string) $config['category'],
			__( 'An inserter category. Left empty, the block goes in this plugin\'s own.', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}
}
