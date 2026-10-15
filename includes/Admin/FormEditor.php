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
		return __( 'Form name, e.g. Contact form', 'metafields-custom-fields' );
	}

	/**
	 * What to say under a refusal.
	 */
	protected function rejection_consequence(): string {
		return __( 'The record was saved, but the form will not render until this is fixed.', 'metafields-custom-fields' );
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
		add_meta_box( 'wpcmb-form-basic', __( 'Basic Settings', 'metafields-custom-fields' ), array( $this, 'render_basic' ), CustomForms::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'wpcmb-form-submission', __( 'What Happens on Submit', 'metafields-custom-fields' ), array( $this, 'render_submission' ), CustomForms::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'wpcmb-form-shortcode', __( 'Shortcode', 'metafields-custom-fields' ), array( $this, 'render_shortcode' ), CustomForms::POST_TYPE, 'normal', 'low' );
		add_meta_box( 'wpcmb-form-access', __( 'Who Can Submit', 'metafields-custom-fields' ), array( $this, 'render_access' ), CustomForms::POST_TYPE, 'side', 'default' );
	}

	/**
	 * Render the field group and the submit label.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_basic( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->nonce_field();

		echo '<p class="description">' . esc_html__( 'Publish this record to make the form usable. Saving it as a draft keeps it defined but inactive.', 'metafields-custom-fields' ) . '</p>';

		$this->field_group_select(
			(string) $config['field_group'],
			__( 'The form shows this group\'s fields, with its own validation and conditional logic.', 'metafields-custom-fields' )
		);

		$this->text( 'submit_label', __( 'Submit button', 'metafields-custom-fields' ), (string) $config['submit_label'], __( 'Defaults to "Submit".', 'metafields-custom-fields' ) );
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
			__( 'A submission', 'metafields-custom-fields' ),
			(string) $config['action'],
			array(
				'post'   => __( 'creates an entry', 'metafields-custom-fields' ),
				'values' => __( 'saves values against an existing object', 'metafields-custom-fields' ),
				'user'   => __( 'updates the signed-in user', 'metafields-custom-fields' ),
			),
			__( 'An entry is stored in this form\'s own post type, listed under Meta Boxes.', 'metafields-custom-fields' )
		);

		echo '<div class="wpcmb-grid-2">';

		$this->text(
			'post_type',
			__( 'Entry post type', 'metafields-custom-fields' ),
			(string) $config['post_type'],
			__( 'Left empty, the form uses its own. Naming a real post type means the form creates content.', 'metafields-custom-fields' )
		);

		$this->text(
			'object',
			__( 'Object to edit', 'metafields-custom-fields' ),
			(string) $config['object'],
			__( '"new" creates one. A reference like post_42 or options_site-settings edits that.', 'metafields-custom-fields' )
		);

		echo '</div>';

		$this->toggle( 'post_title', __( 'Ask for a title', 'metafields-custom-fields' ), '1' === (string) $config['post_title'] );

		$this->text( 'message', __( 'Message after submitting', 'metafields-custom-fields' ), (string) $config['message'], __( 'Defaults to "Thanks, that has been saved."', 'metafields-custom-fields' ) );
		$this->text(
			'redirect',
			__( 'Redirect to', 'metafields-custom-fields' ),
			(string) $config['redirect'],
			__( 'A URL on this site. Left empty, the message is shown in place.', 'metafields-custom-fields' ),
			array(
				'type'        => 'url',
				'placeholder' => '/thanks/',
			)
		);
		$this->text( 'email', __( 'Notify', 'metafields-custom-fields' ), (string) $config['email'], __( 'An address to email on each submission.', 'metafields-custom-fields' ), array( 'type' => 'email' ) );
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
			__( 'Anyone can submit', 'metafields-custom-fields' ),
			'1' === (string) $config['guests'],
			__( 'Off means signed-in visitors only. Uploads always need an account.', 'metafields-custom-fields' )
		);

		$this->toggle(
			'ajax',
			__( 'Submit without reloading', 'metafields-custom-fields' ),
			'1' === (string) $config['ajax'],
			__( 'The form still works and still validates with the script blocked.', 'metafields-custom-fields' )
		);

		$this->select(
			'post_status',
			__( 'Entry status', 'metafields-custom-fields' ),
			(string) $config['post_status'],
			array(
				'publish' => __( 'Published', 'metafields-custom-fields' ),
				'draft'   => __( 'Draft', 'metafields-custom-fields' ),
				'pending' => __( 'Pending', 'metafields-custom-fields' ),
			),
			__( 'Published is honoured only for a post type nobody can view. A form pointed at real content cannot publish it.', 'metafields-custom-fields' )
		);
	}

	/**
	 * Render the shortcode to paste.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_shortcode( \WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			printf( '<p class="description">%s</p>', esc_html__( 'Save this form once and its shortcode appears here.', 'metafields-custom-fields' ) );

			return;
		}

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-form-shortcode">%1$s</label>
			<input class="widefat code" type="text" id="wpcmb-form-shortcode" value="%2$s" readonly onfocus="this.select()" />
			<span class="description wpcmb-help">%3$s</span></p>',
			esc_html__( 'Paste this into any post or page', 'metafields-custom-fields' ),
			esc_attr( sprintf( '[wpcmb_form id="%d"]', $post->ID ) ),
			esc_html__( 'Every setting on this screen travels with it. Adding an attribute to the shortcode overrides that one setting for that one place.', 'metafields-custom-fields' )
		);
	}
}
