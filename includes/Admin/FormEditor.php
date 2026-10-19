<?php
/**
 * The Forms editor screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\RecordEditor;
use WPCMB\Abstracts\RecordType;
use WPCMB\PostTypes\CustomForms;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves one form record.
 */
final class FormEditor extends RecordEditor {

	/**
	 * The record type this screen edits.
	 */
	protected function type(): RecordType {
		return $this->container->get( CustomForms::class );
	}

	/**
	 * The request key its controls post under.
	 */
	protected function request_key(): string {
		return 'wpcmb_form';
	}

	/**
	 * The placeholder for the title field.
	 */
	protected function title_hint(): string {
		return __( 'Form name, e.g. Contact form', 'manpreetdev-custom-fields-meta-boxes' );
	}

	/**
	 * What to say under a refusal.
	 */
	protected function rejection_consequence(): string {
		return __( 'The record was saved, but the form will not render until this is fixed.', 'manpreetdev-custom-fields-meta-boxes' );
	}

	/**
	 * Why a configuration cannot render a form.
	 *
	 * @param array<string, mixed> $config  Cleaned configuration.
	 * @param int                  $post_id The record being saved.
	 */
	protected function rejection( array $config, int $post_id ): string {
		return $this->container->get( CustomForms::class )->rejection( $config );
	}

	/**
	 * Register the editor meta boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box( 'wpcmb-form-basic', __( 'Basic Settings', 'manpreetdev-custom-fields-meta-boxes' ), array( $this, 'render_basic' ), CustomForms::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'wpcmb-form-submission', __( 'What Happens on Submit', 'manpreetdev-custom-fields-meta-boxes' ), array( $this, 'render_submission' ), CustomForms::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'wpcmb-form-shortcode', __( 'Shortcode', 'manpreetdev-custom-fields-meta-boxes' ), array( $this, 'render_shortcode' ), CustomForms::POST_TYPE, 'normal', 'low' );
		add_meta_box( 'wpcmb-form-access', __( 'Who Can Submit', 'manpreetdev-custom-fields-meta-boxes' ), array( $this, 'render_access' ), CustomForms::POST_TYPE, 'side', 'default' );
	}

	/**
	 * Render the field group and the submit label.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_basic( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->nonce_field();

		echo '<p class="description">' . esc_html__( 'Publish this record to make the form usable. Saving it as a draft keeps it defined but inactive.', 'manpreetdev-custom-fields-meta-boxes' ) . '</p>';

		$this->field_group_select(
			(string) $config['field_group'],
			__( 'The form shows this group\'s fields, with its own validation and conditional logic.', 'manpreetdev-custom-fields-meta-boxes' )
		);

		$this->text( 'submit_label', __( 'Submit button', 'manpreetdev-custom-fields-meta-boxes' ), (string) $config['submit_label'], __( 'Defaults to "Submit".', 'manpreetdev-custom-fields-meta-boxes' ) );
	}

	/**
	 * Render what a submission does.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_submission( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->select(
			'action',
			__( 'A submission', 'manpreetdev-custom-fields-meta-boxes' ),
			(string) $config['action'],
			array(
				'post'   => __( 'creates an entry', 'manpreetdev-custom-fields-meta-boxes' ),
				'values' => __( 'saves values against an existing object', 'manpreetdev-custom-fields-meta-boxes' ),
				'user'   => __( 'updates the signed-in user', 'manpreetdev-custom-fields-meta-boxes' ),
			),
			__( 'An entry is stored in this form\'s own post type, listed under Meta Boxes.', 'manpreetdev-custom-fields-meta-boxes' )
		);

		echo '<div class="wpcmb-grid-2">';

		$this->text(
			'post_type',
			__( 'Entry post type', 'manpreetdev-custom-fields-meta-boxes' ),
			(string) $config['post_type'],
			__( 'Left empty, the form uses its own. Naming a real post type means the form creates content.', 'manpreetdev-custom-fields-meta-boxes' )
		);

		$this->text(
			'object',
			__( 'Object to edit', 'manpreetdev-custom-fields-meta-boxes' ),
			(string) $config['object'],
			__( '"new" creates one. A reference like post_42 or options_site-settings edits that.', 'manpreetdev-custom-fields-meta-boxes' )
		);

		echo '</div>';

		$this->toggle( 'post_title', __( 'Ask for a title', 'manpreetdev-custom-fields-meta-boxes' ), '1' === (string) $config['post_title'] );

		$this->text( 'message', __( 'Message after submitting', 'manpreetdev-custom-fields-meta-boxes' ), (string) $config['message'], __( 'Defaults to "Thanks, that has been saved."', 'manpreetdev-custom-fields-meta-boxes' ) );
		$this->text(
			'redirect',
			__( 'Redirect to', 'manpreetdev-custom-fields-meta-boxes' ),
			(string) $config['redirect'],
			__( 'A URL on this site. Left empty, the message is shown in place.', 'manpreetdev-custom-fields-meta-boxes' ),
			array(
				'type'        => 'url',
				'placeholder' => '/thanks/',
			)
		);
		$this->text( 'email', __( 'Notify', 'manpreetdev-custom-fields-meta-boxes' ), (string) $config['email'], __( 'An address to email on each submission.', 'manpreetdev-custom-fields-meta-boxes' ), array( 'type' => 'email' ) );
	}

	/**
	 * Render who may submit.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_access( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->toggle(
			'guests',
			__( 'Anyone can submit', 'manpreetdev-custom-fields-meta-boxes' ),
			'1' === (string) $config['guests'],
			__( 'Off means signed-in visitors only. Uploads always need an account.', 'manpreetdev-custom-fields-meta-boxes' )
		);

		$this->toggle(
			'ajax',
			__( 'Submit without reloading', 'manpreetdev-custom-fields-meta-boxes' ),
			'1' === (string) $config['ajax'],
			__( 'The form still works and still validates with the script blocked.', 'manpreetdev-custom-fields-meta-boxes' )
		);

		$this->select(
			'post_status',
			__( 'Entry status', 'manpreetdev-custom-fields-meta-boxes' ),
			(string) $config['post_status'],
			array(
				'publish' => __( 'Published', 'manpreetdev-custom-fields-meta-boxes' ),
				'draft'   => __( 'Draft', 'manpreetdev-custom-fields-meta-boxes' ),
				'pending' => __( 'Pending', 'manpreetdev-custom-fields-meta-boxes' ),
			),
			__( 'Published is honoured only for a post type nobody can view. A form pointed at real content cannot publish it.', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}

	/**
	 * Render the shortcode to paste.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_shortcode( \WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			printf( '<p class="description">%s</p>', esc_html__( 'Save this form once and its shortcode appears here.', 'manpreetdev-custom-fields-meta-boxes' ) );

			return;
		}

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-form-shortcode">%1$s</label>
			<input class="widefat code" type="text" id="wpcmb-form-shortcode" value="%2$s" readonly onfocus="this.select()" />
			<span class="description wpcmb-help">%3$s</span></p>',
			esc_html__( 'Paste this into any post or page', 'manpreetdev-custom-fields-meta-boxes' ),
			esc_attr( sprintf( '[wpcmb_form id="%d"]', $post->ID ) ),
			esc_html__( 'Every setting on this screen travels with it. Adding an attribute to the shortcode overrides that one setting for that one place.', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}
}
