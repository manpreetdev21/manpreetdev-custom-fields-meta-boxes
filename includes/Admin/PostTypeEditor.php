<?php
/**
 * The Post Types editor screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Abstracts\RecordType;
use WPCMB\PostTypes\CustomPostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves one post type record.
 *
 * Plain form controls in meta boxes, like the field group's own settings box
 * and unlike its fields builder: there is a fixed set of settings here and no
 * repeating structure, so there is nothing for a builder to build and nothing
 * that stops working when a script does not load.
 */
final class PostTypeEditor extends Module {

	/**
	 * Nonce action for the editor form.
	 */
	private const NONCE = 'wpcmb_save_post_type';

	/**
	 * Transient prefix carrying a refusal across the redirect after a save.
	 */
	private const NOTICE_PREFIX = 'wpcmb_pt_notice_';

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
		add_action( 'add_meta_boxes_' . CustomPostTypes::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . CustomPostTypes::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * The record type this screen edits.
	 */
	private function type(): CustomPostTypes {
		return $this->container->get( CustomPostTypes::class );
	}

	/**
	 * One record's configuration, with the defaults filled in.
	 *
	 * @param \WP_Post $post Current post.
	 *
	 * @return array<string, mixed>
	 */
	private function config( \WP_Post $post ): array {
		return array_merge( CustomPostTypes::defaults(), $this->type()->config( $post ) );
	}

	/**
	 * Replace the title placeholder with something that means anything here.
	 *
	 * @param string   $text Current placeholder.
	 * @param \WP_Post $post Current post.
	 */
	public function title_placeholder( $text, $post ): string {
		if ( $post instanceof \WP_Post && CustomPostTypes::POST_TYPE === $post->post_type ) {
			return __( 'Post type name, e.g. Projects', 'metafields-custom-fields' );
		}

		return (string) $text;
	}

	/**
	 * Register the editor meta boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'wpcmb-pt-basic',
			__( 'Basic Settings', 'metafields-custom-fields' ),
			array( $this, 'render_basic' ),
			CustomPostTypes::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wpcmb-pt-labels',
			__( 'Labels', 'metafields-custom-fields' ),
			array( $this, 'render_labels' ),
			CustomPostTypes::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'wpcmb-pt-supports',
			__( 'Features', 'metafields-custom-fields' ),
			array( $this, 'render_supports' ),
			CustomPostTypes::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'wpcmb-pt-advanced',
			__( 'Advanced', 'metafields-custom-fields' ),
			array( $this, 'render_advanced' ),
			CustomPostTypes::POST_TYPE,
			'normal',
			'low'
		);

		add_meta_box(
			'wpcmb-pt-visibility',
			__( 'Visibility', 'metafields-custom-fields' ),
			array( $this, 'render_visibility' ),
			CustomPostTypes::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the name and slug.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_basic( \WP_Post $post ): void {
		$config = $this->config( $post );

		wp_nonce_field( self::NONCE, 'wpcmb_post_type_nonce' );

		echo '<p class="description">' . esc_html__( 'Publish this record to register the post type. Saving it as a draft keeps it defined but inactive.', 'metafields-custom-fields' ) . '</p>';

		$this->text(
			'name',
			__( 'Post type key', 'metafields-custom-fields' ),
			(string) $config['name'],
			__( 'Lowercase, up to 20 characters. This is what gets stored against every post, so changing it later orphans everything already saved.', 'metafields-custom-fields' ),
			array(
				'placeholder' => 'project',
				'maxlength'   => '20',
				'required'    => 'required',
			)
		);

		$this->text(
			'description',
			__( 'Description', 'metafields-custom-fields' ),
			(string) $config['description'],
			__( 'Shown to editors in a few places in the admin.', 'metafields-custom-fields' )
		);
	}

	/**
	 * Render the singular and plural labels.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_labels( \WP_Post $post ): void {
		$config = $this->config( $post );

		echo '<p class="description">' . esc_html__( 'The rest of the labels — Add New, Edit, Search, and the empty-list messages — are built from these two.', 'metafields-custom-fields' ) . '</p>';

		echo '<div class="wpcmb-grid-2">';

		$this->text( 'singular', __( 'Singular name', 'metafields-custom-fields' ), (string) $config['singular'], '', array( 'placeholder' => __( 'Project', 'metafields-custom-fields' ) ) );
		$this->text( 'plural', __( 'Plural name', 'metafields-custom-fields' ), (string) $config['plural'], '', array( 'placeholder' => __( 'Projects', 'metafields-custom-fields' ) ) );

		echo '</div>';
	}

	/**
	 * Render the supported features and taxonomies.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_supports( \WP_Post $post ): void {
		$config   = $this->config( $post );
		$supports = (array) $config['supports'];

		echo '<fieldset class="wpcmb-fieldset"><legend>' . esc_html__( 'Supports', 'metafields-custom-fields' ) . '</legend>';

		foreach ( CustomPostTypes::features() as $feature => $label ) {
			printf(
				'<label class="wpcmb-checkbox"><input type="checkbox" name="wpcmb_post_type[supports][]" value="%s"%s /> %s</label>',
				esc_attr( $feature ),
				checked( in_array( $feature, $supports, true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset>';

		$taxonomies = (array) $config['taxonomies'];

		echo '<fieldset class="wpcmb-fieldset"><legend>' . esc_html__( 'Taxonomies', 'metafields-custom-fields' ) . '</legend>';

		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $taxonomy ) {
			printf(
				'<label class="wpcmb-checkbox"><input type="checkbox" name="wpcmb_post_type[taxonomies][]" value="%s"%s /> %s <code>%s</code></label>',
				esc_attr( $taxonomy->name ),
				checked( in_array( $taxonomy->name, $taxonomies, true ), true, false ),
				esc_html( $taxonomy->labels->name ),
				esc_html( $taxonomy->name )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Render the permalink and capability settings.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_advanced( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->toggle( 'rewrite', __( 'Give it pretty permalinks', 'metafields-custom-fields' ), (bool) $config['rewrite'], __( 'Off stores the posts but gives them no readable URLs.', 'metafields-custom-fields' ) );

		echo '<div class="wpcmb-grid-2">';

		$this->text(
			'rewrite_slug',
			__( 'URL slug', 'metafields-custom-fields' ),
			(string) $config['rewrite_slug'],
			__( 'Defaults to the post type key.', 'metafields-custom-fields' )
		);

		$this->text(
			'capability_type',
			__( 'Capability type', 'metafields-custom-fields' ),
			(string) $config['capability_type'],
			__( 'Whose permissions apply. "post" means anyone who can edit posts can edit these.', 'metafields-custom-fields' ),
			array( 'placeholder' => 'post' )
		);

		echo '</div>';

		$this->toggle( 'with_front', __( 'Prefix URLs with the permalink base', 'metafields-custom-fields' ), (bool) $config['with_front'] );
		$this->toggle( 'has_archive', __( 'Give it an archive page', 'metafields-custom-fields' ), (bool) $config['has_archive'] );
		$this->toggle( 'hierarchical', __( 'Allow parents, like pages', 'metafields-custom-fields' ), (bool) $config['hierarchical'], __( 'Needs the Page attributes feature to be set from the editor.', 'metafields-custom-fields' ) );
		$this->toggle( 'exclude_from_search', __( 'Keep out of site search', 'metafields-custom-fields' ), (bool) $config['exclude_from_search'] );
	}

	/**
	 * Render the visibility settings.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_visibility( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->toggle( 'public', __( 'Public', 'metafields-custom-fields' ), (bool) $config['public'], __( 'Visible on the front of the site and in search.', 'metafields-custom-fields' ) );
		$this->toggle( 'show_ui', __( 'Show in the admin', 'metafields-custom-fields' ), (bool) $config['show_ui'] );
		$this->toggle( 'show_in_menu', __( 'Show in the admin menu', 'metafields-custom-fields' ), (bool) $config['show_in_menu'] );
		$this->toggle( 'show_in_rest', __( 'Show in the REST API', 'metafields-custom-fields' ), (bool) $config['show_in_rest'], __( 'Required for the block editor.', 'metafields-custom-fields' ) );

		$this->text( 'menu_icon', __( 'Menu icon', 'metafields-custom-fields' ), (string) $config['menu_icon'], __( 'A Dashicon name or an image URL.', 'metafields-custom-fields' ), array( 'placeholder' => 'dashicons-admin-post' ) );
		$this->text( 'menu_position', __( 'Menu position', 'metafields-custom-fields' ), (string) $config['menu_position'], __( '0 lets WordPress decide. 5 is below Posts, 20 is below Pages.', 'metafields-custom-fields' ), array( 'type' => 'number' ) );
	}

	/**
	 * Render one text control.
	 *
	 * @param string               $name       Setting name.
	 * @param string               $label      Label.
	 * @param string               $value      Current value.
	 * @param string               $help       Description below the control.
	 * @param array<string,string> $attributes Extra input attributes.
	 */
	private function text( string $name, string $label, string $value, string $help = '', array $attributes = array() ): void {
		$extra = '';

		foreach ( $attributes as $attribute => $setting ) {
			$extra .= sprintf( ' %s="%s"', esc_attr( $attribute ), esc_attr( $setting ) );
		}

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-pt-%1$s">%2$s</label>
			<input class="widefat" type="%3$s" id="wpcmb-pt-%1$s" name="wpcmb_post_type[%1$s]" value="%4$s"%5$s />%6$s</p>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $attributes['type'] ?? 'text' ),
			esc_attr( $value ),
			$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each name and value escaped above.
			'' !== $help ? '<span class="description wpcmb-help">' . esc_html( $help ) . '</span>' : ''
		);
	}

	/**
	 * Render one checkbox, with the hidden 0 that makes unchecking stick.
	 *
	 * @param string $name  Setting name.
	 * @param string $label Label.
	 * @param bool   $on    Whether it is currently on.
	 * @param string $help  Description below the control.
	 */
	private function toggle( string $name, string $label, bool $on, string $help = '' ): void {
		printf(
			'<p><input type="hidden" name="wpcmb_post_type[%1$s]" value="0" />
			<label class="wpcmb-checkbox"><input type="checkbox" name="wpcmb_post_type[%1$s]" value="1"%2$s /> %3$s</label>%4$s</p>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label ),
			'' !== $help ? '<span class="description wpcmb-help">' . esc_html( $help ) . '</span>' : ''
		);
	}

	/**
	 * Save a submitted record.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ): void {
		if ( ! isset( $_POST['wpcmb_post_type_nonce'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE, 'wpcmb_post_type_nonce' );

		if ( ! current_user_can( RecordType::capability() ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$raw = array();

		if ( isset( $_POST['wpcmb_post_type'] ) && is_array( $_POST['wpcmb_post_type'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize() below is this record type's trust boundary and cleans every value against its own schema; a blanket sanitizer here would flatten the arrays the checkbox groups post.
			$raw = wp_unslash( $_POST['wpcmb_post_type'] );
		}

		$type   = $this->type();
		$config = $type->sanitize( (array) $raw );

		/*
		 * A name that cannot be registered is still stored — losing somebody's
		 * work because one field is wrong is worse than keeping a record that
		 * does nothing yet — and the reason is carried to the next screen.
		 */
		$rejection = $type->rejection( (string) $config['name'], (int) $post_id );

		if ( '' !== $rejection ) {
			set_transient( self::NOTICE_PREFIX . get_current_user_id(), $rejection, MINUTE_IN_SECONDS );
		}

		$type->save( (int) $post_id, $config );

		/*
		 * Permalinks for a new post type do not work until the rules are
		 * rebuilt, and nothing else on this request will do it. Flushed on
		 * save only, never on every page load.
		 */
		if ( '' === $rejection && ! empty( $config['rewrite'] ) ) {
			delete_option( 'rewrite_rules' );
		}
	}

	/**
	 * Show a refusal carried over from the save.
	 */
	public function render_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || CustomPostTypes::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_string( $notice ) || '' === $notice ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-warning"><p>%s</p><p>%s</p></div>',
			esc_html( $notice ),
			esc_html__( 'The record was saved, but no post type is registered from it until the key is changed.', 'metafields-custom-fields' )
		);
	}
}
