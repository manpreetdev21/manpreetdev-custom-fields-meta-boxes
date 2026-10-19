<?php
/**
 * The Taxonomies editor screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Abstracts\RecordType;
use WPCMB\PostTypes\CustomPostTypes;
use WPCMB\PostTypes\CustomTaxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves one taxonomy record.
 *
 * The post types it can be attached to include ones a customer defined on the
 * Post Types screen but has not published yet: a site is usually built by
 * making both and then joining them up, and a list that only shows what is
 * registered right now cannot express that.
 */
final class TaxonomyEditor extends Module {

	/**
	 * Nonce action for the editor form.
	 */
	private const NONCE = 'wpcmb_save_taxonomy';

	/**
	 * Transient prefix carrying a refusal across the redirect after a save.
	 */
	private const NOTICE_PREFIX = 'wpcmb_tax_notice_';

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
		add_action( 'add_meta_boxes_' . CustomTaxonomies::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . CustomTaxonomies::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * The record type this screen edits.
	 */
	private function type(): CustomTaxonomies {
		return $this->container->get( CustomTaxonomies::class );
	}

	/**
	 * One record's configuration, with the defaults filled in.
	 *
	 * @param \WP_Post $post Current post.
	 *
	 * @return array<string, mixed>
	 */
	private function config( \WP_Post $post ): array {
		return array_merge( CustomTaxonomies::defaults(), $this->type()->config( $post ) );
	}

	/**
	 * Replace the title placeholder.
	 *
	 * @param string   $text Current placeholder.
	 * @param \WP_Post $post Current post.
	 */
	public function title_placeholder( $text, $post ): string {
		if ( $post instanceof \WP_Post && CustomTaxonomies::POST_TYPE === $post->post_type ) {
			return __( 'Taxonomy name, e.g. Project Types', 'manpreetdev-custom-fields-meta-boxes' );
		}

		return (string) $text;
	}

	/**
	 * Register the editor meta boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'wpcmb-tax-basic',
			__( 'Basic Settings', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_basic' ),
			CustomTaxonomies::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wpcmb-tax-labels',
			__( 'Labels', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_labels' ),
			CustomTaxonomies::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'wpcmb-tax-post-types',
			__( 'Post Types', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_post_types' ),
			CustomTaxonomies::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'wpcmb-tax-advanced',
			__( 'Advanced', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_advanced' ),
			CustomTaxonomies::POST_TYPE,
			'normal',
			'low'
		);

		add_meta_box(
			'wpcmb-tax-visibility',
			__( 'Visibility', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_visibility' ),
			CustomTaxonomies::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the key, the term structure and the description.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_basic( \WP_Post $post ): void {
		$config = $this->config( $post );

		wp_nonce_field( self::NONCE, 'wpcmb_taxonomy_nonce' );

		echo '<p class="description">' . esc_html__( 'Publish this record to register the taxonomy. Saving it as a draft keeps it defined but inactive.', 'manpreetdev-custom-fields-meta-boxes' ) . '</p>';

		$this->text(
			'name',
			__( 'Taxonomy key', 'manpreetdev-custom-fields-meta-boxes' ),
			(string) $config['name'],
			__( 'Lowercase, up to 32 characters. This is what every term is stored against, so changing it later orphans them all.', 'manpreetdev-custom-fields-meta-boxes' ),
			array(
				'placeholder' => 'project_type',
				'maxlength'   => '32',
				'required'    => 'required',
			)
		);

		$this->toggle(
			'hierarchical',
			__( 'Terms can have parents, like categories', 'manpreetdev-custom-fields-meta-boxes' ),
			(bool) $config['hierarchical'],
			__( 'Off behaves like tags: a flat list, entered by typing.', 'manpreetdev-custom-fields-meta-boxes' )
		);

		$this->text( 'description', __( 'Description', 'manpreetdev-custom-fields-meta-boxes' ), (string) $config['description'] );
	}

	/**
	 * Render the singular and plural labels.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_labels( \WP_Post $post ): void {
		$config = $this->config( $post );

		echo '<p class="description">' . esc_html__( 'Everything else — Add New, Search, Parent, and the tag-style wording for a flat taxonomy — is built from these two.', 'manpreetdev-custom-fields-meta-boxes' ) . '</p>';

		echo '<div class="wpcmb-grid-2">';

		$this->text( 'singular', __( 'Singular name', 'manpreetdev-custom-fields-meta-boxes' ), (string) $config['singular'], '', array( 'placeholder' => __( 'Project Type', 'manpreetdev-custom-fields-meta-boxes' ) ) );
		$this->text( 'plural', __( 'Plural name', 'manpreetdev-custom-fields-meta-boxes' ), (string) $config['plural'], '', array( 'placeholder' => __( 'Project Types', 'manpreetdev-custom-fields-meta-boxes' ) ) );

		echo '</div>';
	}

	/**
	 * Render the post types this taxonomy attaches to.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_post_types( \WP_Post $post ): void {
		$config    = $this->config( $post );
		$attached  = (array) $config['post_types'];
		$available = $this->available_post_types();

		echo '<p class="description">' . esc_html__( 'Where this taxonomy appears. A post type can also claim it from its own screen — either side of the join works.', 'manpreetdev-custom-fields-meta-boxes' ) . '</p>';

		echo '<fieldset class="wpcmb-fieldset">';

		foreach ( $available as $name => $label ) {
			printf(
				'<label class="wpcmb-checkbox"><input type="checkbox" name="wpcmb_taxonomy[post_types][]" value="%s"%s /> %s <code>%s</code>%s</label>',
				esc_attr( $name ),
				checked( in_array( $name, $attached, true ), true, false ),
				esc_html( $label ),
				esc_html( $name ),
				post_type_exists( $name ) ? '' : ' <em>' . esc_html__( '(not registered yet)', 'manpreetdev-custom-fields-meta-boxes' ) . '</em>'
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Every post type this taxonomy could be attached to.
	 *
	 * Registered ones, plus any a record defines — including drafts, because
	 * a site is built by making both halves and then joining them, and a
	 * taxonomy saved before its post type would otherwise have nothing to
	 * tick.
	 *
	 * @return array<string, string>
	 */
	private function available_post_types(): array {
		$available = array();

		/*
		 * Public post types only.
		 *
		 * Every plugin that keeps its configuration in posts — this one, and
		 * the others on a typical site — registers non-public types with a UI,
		 * and so does core for reusable blocks and navigation menus. None of
		 * those are content anybody classifies, and listing them buried the
		 * three or four that are.
		 */
		foreach ( get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		) as $post_type ) {
			$available[ $post_type->name ] = $post_type->labels->name;
		}

		foreach ( $this->container->get( CustomPostTypes::class )->records( true ) as $record ) {
			$name = (string) ( $record['name'] ?? '' );

			if ( '' === $name || isset( $available[ $name ] ) ) {
				continue;
			}

			$label = (string) ( $record['plural'] ?? '' );

			$available[ $name ] = '' !== $label ? $label : (string) ( $record['title'] ?? $name );
		}

		ksort( $available );

		return $available;
	}

	/**
	 * Render the permalink settings.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_advanced( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->toggle( 'rewrite', __( 'Give terms pretty permalinks', 'manpreetdev-custom-fields-meta-boxes' ), (bool) $config['rewrite'] );

		echo '<div class="wpcmb-grid-2">';

		$this->text(
			'rewrite_slug',
			__( 'URL slug', 'manpreetdev-custom-fields-meta-boxes' ),
			(string) $config['rewrite_slug'],
			__( 'Defaults to the taxonomy key.', 'manpreetdev-custom-fields-meta-boxes' )
		);

		echo '</div>';

		$this->toggle( 'with_front', __( 'Prefix URLs with the permalink base', 'manpreetdev-custom-fields-meta-boxes' ), (bool) $config['with_front'] );
		$this->toggle( 'show_admin_column', __( 'Show a column on the post list', 'manpreetdev-custom-fields-meta-boxes' ), (bool) $config['show_admin_column'] );
		$this->toggle( 'show_tagcloud', __( 'Allow it in the tag cloud widget', 'manpreetdev-custom-fields-meta-boxes' ), (bool) $config['show_tagcloud'] );
		$this->toggle( 'sort', __( 'Remember the order terms were added in', 'manpreetdev-custom-fields-meta-boxes' ), (bool) $config['sort'] );
	}

	/**
	 * Render the visibility settings.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_visibility( \WP_Post $post ): void {
		$config = $this->config( $post );

		$this->toggle( 'public', __( 'Public', 'manpreetdev-custom-fields-meta-boxes' ), (bool) $config['public'], __( 'Terms have archive pages on the front of the site.', 'manpreetdev-custom-fields-meta-boxes' ) );
		$this->toggle( 'show_ui', __( 'Show in the admin', 'manpreetdev-custom-fields-meta-boxes' ), (bool) $config['show_ui'] );
		$this->toggle( 'show_in_menu', __( 'Show in the admin menu', 'manpreetdev-custom-fields-meta-boxes' ), (bool) $config['show_in_menu'] );
		$this->toggle( 'show_in_rest', __( 'Show in the REST API', 'manpreetdev-custom-fields-meta-boxes' ), (bool) $config['show_in_rest'], __( 'Required for the block editor.', 'manpreetdev-custom-fields-meta-boxes' ) );
	}

	/**
	 * Render one text control.
	 *
	 * @param string                $name       Setting name.
	 * @param string                $label      Label.
	 * @param string                $value      Current value.
	 * @param string                $help       Description below the control.
	 * @param array<string, string> $attributes Extra input attributes.
	 */
	private function text( string $name, string $label, string $value, string $help = '', array $attributes = array() ): void {
		$extra = '';

		foreach ( $attributes as $attribute => $setting ) {
			$extra .= sprintf( ' %s="%s"', esc_attr( $attribute ), esc_attr( $setting ) );
		}

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-tax-%1$s">%2$s</label>
			<input class="widefat" type="%3$s" id="wpcmb-tax-%1$s" name="wpcmb_taxonomy[%1$s]" value="%4$s"%5$s />%6$s</p>',
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
			'<p><input type="hidden" name="wpcmb_taxonomy[%1$s]" value="0" />
			<label class="wpcmb-checkbox"><input type="checkbox" name="wpcmb_taxonomy[%1$s]" value="1"%2$s /> %3$s</label>%4$s</p>',
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
		if ( ! isset( $_POST['wpcmb_taxonomy_nonce'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE, 'wpcmb_taxonomy_nonce' );

		if ( ! current_user_can( RecordType::capability() ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$raw = array();

		if ( isset( $_POST['wpcmb_taxonomy'] ) && is_array( $_POST['wpcmb_taxonomy'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize() below is this record type's trust boundary and cleans every value against its own schema; a blanket sanitizer here would flatten the arrays the checkbox groups post.
			$raw = wp_unslash( $_POST['wpcmb_taxonomy'] );
		}

		$type   = $this->type();
		$config = $type->sanitize( (array) $raw );

		$rejection = $type->rejection( (string) $config['name'], (int) $post_id );

		if ( '' !== $rejection ) {
			set_transient( self::NOTICE_PREFIX . get_current_user_id(), $rejection, MINUTE_IN_SECONDS );
		}

		$type->save( (int) $post_id, $config );

		// Term permalinks do not work until the rules are rebuilt, and
		// nothing else on this request will do it.
		if ( '' === $rejection && ! empty( $config['rewrite'] ) ) {
			delete_option( 'rewrite_rules' );
		}
	}

	/**
	 * Show a refusal carried over from the save.
	 */
	public function render_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || CustomTaxonomies::POST_TYPE !== $screen->post_type ) {
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
			esc_html__( 'The record was saved, but no taxonomy is registered from it until the key is changed.', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}
}
