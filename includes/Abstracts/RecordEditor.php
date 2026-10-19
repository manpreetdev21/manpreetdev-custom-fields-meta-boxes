<?php
/**
 * Shared editor plumbing for the record screens.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Abstracts;

defined( 'ABSPATH' ) || exit;

/**
 * Base for the screen that edits one record.
 *
 * Five record types, five editors, and the parts that differ are the meta
 * boxes and the settings in them. The nonce, the capability check, the
 * autosave guard, reading the request, storing the result and carrying a
 * refusal to the next screen are identical, and were identical three times
 * before this existed.
 *
 * Plain form controls, not a builder: each of these screens has a fixed set
 * of settings and no repeating structure, so there is nothing to build and
 * nothing that stops working when a script does not load.
 */
abstract class RecordEditor extends Module {

	/**
	 * The record type this screen edits.
	 */
	abstract protected function type(): RecordType;

	/**
	 * The request key its controls post under, e.g. `wpcmb_post_type`.
	 */
	abstract protected function request_key(): string;

	/**
	 * Register the meta boxes for this screen.
	 */
	abstract public function add_meta_boxes(): void;

	/**
	 * The placeholder for the title field.
	 */
	abstract protected function title_hint(): string;

	/**
	 * Why a cleaned configuration cannot do its job, or an empty string.
	 *
	 * @param array<string, mixed> $config  Cleaned configuration.
	 * @param int                  $post_id The record being saved.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Part of the contract subclasses implement.
	 */
	protected function rejection( array $config, int $post_id ): string {
		return '';
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * What to say under a refusal, which differs by what the record builds.
	 */
	protected function rejection_consequence(): string {
		return __( 'The record was saved, but it is not in use until this is fixed.', 'manpreetdev-custom-fields-meta-boxes' );
	}

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
		$post_type = $this->type()->post_type();

		add_action( 'add_meta_boxes_' . $post_type, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . $post_type, array( $this, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * One record's configuration, with that type's defaults filled in.
	 *
	 * @param \WP_Post $post Current post.
	 *
	 * @return array<string, mixed>
	 */
	protected function config( \WP_Post $post ): array {
		$type = $this->type();

		return array_merge( $type::defaults(), $type->config( $post ) );
	}

	/**
	 * Replace the title placeholder with something meaningful here.
	 *
	 * @param string   $text Current placeholder.
	 * @param \WP_Post $post Current post.
	 */
	public function title_placeholder( $text, $post ): string {
		if ( $post instanceof \WP_Post && $this->type()->post_type() === $post->post_type ) {
			return $this->title_hint();
		}

		return (string) $text;
	}

	/**
	 * The nonce field every one of these screens posts.
	 */
	protected function nonce_field(): void {
		wp_nonce_field( 'wpcmb_save_' . $this->request_key(), $this->request_key() . '_nonce' );
	}

	/**
	 * Save a submitted record.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ): void {
		$key   = $this->request_key();
		$nonce = $key . '_nonce';

		if ( ! isset( $_POST[ $nonce ] ) ) {
			return;
		}

		check_admin_referer( 'wpcmb_save_' . $key, $nonce );

		if ( ! current_user_can( RecordType::capability() ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$raw = array();

		if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize() below is this record type's trust boundary and cleans every value against its own schema; a blanket sanitizer here would flatten the arrays the checkbox and multiple-select controls post.
			$raw = wp_unslash( $_POST[ $key ] );
		}

		$type   = $this->type();
		$config = $type->sanitize( (array) $raw );

		/*
		 * A record that cannot do its job is still stored. Losing somebody's
		 * work because one field is wrong is worse than keeping a record that
		 * does nothing yet, and the reason is carried to the next screen.
		 */
		$rejection = $this->rejection( $config, (int) $post_id );

		if ( '' !== $rejection ) {
			set_transient( 'wpcmb_notice_' . $key . '_' . get_current_user_id(), $rejection, MINUTE_IN_SECONDS );
		}

		$type->save( (int) $post_id, $config );

		$this->after_save( $config, $rejection );
	}

	/**
	 * Anything this record type needs doing after a save.
	 *
	 * @param array<string, mixed> $config    Stored configuration.
	 * @param string               $rejection Refusal, empty when fine.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Part of the contract subclasses implement.
	 */
	protected function after_save( array $config, string $rejection ): void {
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Show a refusal carried over from the save.
	 */
	public function render_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || $this->type()->post_type() !== $screen->post_type ) {
			return;
		}

		$key    = 'wpcmb_notice_' . $this->request_key() . '_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_string( $notice ) || '' === $notice ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-warning"><p>%s</p><p>%s</p></div>',
			esc_html( $notice ),
			esc_html( $this->rejection_consequence() )
		);
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
	protected function text( string $name, string $label, string $value, string $help = '', array $attributes = array() ): void {
		$extra = '';

		foreach ( $attributes as $attribute => $setting ) {
			$extra .= sprintf( ' %s="%s"', esc_attr( $attribute ), esc_attr( $setting ) );
		}

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-%1$s-%2$s">%3$s</label>
			<input class="widefat" type="%4$s" id="wpcmb-%1$s-%2$s" name="%1$s[%2$s]" value="%5$s"%6$s />%7$s</p>',
			esc_attr( $this->request_key() ),
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
	protected function toggle( string $name, string $label, bool $on, string $help = '' ): void {
		printf(
			'<p><input type="hidden" name="%1$s[%2$s]" value="0" />
			<label class="wpcmb-checkbox"><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s /> %4$s</label>%5$s</p>',
			esc_attr( $this->request_key() ),
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label ),
			'' !== $help ? '<span class="description wpcmb-help">' . esc_html( $help ) . '</span>' : ''
		);
	}

	/**
	 * Render one dropdown.
	 *
	 * @param string                $name    Setting name.
	 * @param string                $label   Label.
	 * @param string                $value   Current value.
	 * @param array<string, string> $choices Options, keyed by value.
	 * @param string                $help    Description below the control.
	 */
	protected function select( string $name, string $label, string $value, array $choices, string $help = '' ): void {
		printf(
			'<p><label class="wpcmb-label" for="wpcmb-%1$s-%2$s">%3$s</label>
			<select class="widefat" id="wpcmb-%1$s-%2$s" name="%1$s[%2$s]">',
			esc_attr( $this->request_key() ),
			esc_attr( $name ),
			esc_html( $label )
		);

		foreach ( $choices as $option => $text ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $option ),
				selected( $value, (string) $option, false ),
				esc_html( $text )
			);
		}

		printf(
			'</select>%s</p>',
			'' !== $help ? '<span class="description wpcmb-help">' . esc_html( $help ) . '</span>' : ''
		);
	}

	/**
	 * Render a group of checkboxes under a legend.
	 *
	 * @param string                $name     Setting name.
	 * @param string                $legend   Legend.
	 * @param array<string, string> $choices  Options, keyed by value.
	 * @param array<int, string>    $selected Values currently on.
	 */
	protected function checkboxes( string $name, string $legend, array $choices, array $selected ): void {
		printf( '<fieldset class="wpcmb-fieldset"><legend>%s</legend>', esc_html( $legend ) );

		foreach ( $choices as $option => $text ) {
			printf(
				'<label class="wpcmb-checkbox"><input type="checkbox" name="%1$s[%2$s][]" value="%3$s"%4$s /> %5$s</label>',
				esc_attr( $this->request_key() ),
				esc_attr( $name ),
				esc_attr( (string) $option ),
				checked( in_array( (string) $option, $selected, true ), true, false ),
				esc_html( $text )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Render a field group chooser.
	 *
	 * Three of the five record types point at a field group, and all three
	 * want the same thing: the published groups, by title, with the key
	 * beside it — two groups can share a title and only the key says which.
	 *
	 * @param string $value Currently chosen group key.
	 * @param string $help  Description below the control.
	 */
	protected function field_group_select( string $value, string $help = '' ): void {
		$choices = array( '' => __( '— Choose a field group —', 'manpreetdev-custom-fields-meta-boxes' ) );

		foreach ( $this->container->get( \WPCMB\Fields\Repository::class )->all( true ) as $group ) {
			$choices[ $group->key ] = sprintf( '%s (%s)', '' !== $group->title ? $group->title : $group->key, $group->key );
		}

		$this->select( 'field_group', __( 'Field group', 'manpreetdev-custom-fields-meta-boxes' ), $value, $choices, $help );
	}
}
