<?php
/**
 * Field group editor screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the field group editor.
 *
 * The fields list and the location rules are edited by JavaScript and posted
 * as JSON in a single hidden control each; display settings are plain form
 * controls so they work without JavaScript. All three merge into one array
 * that passes through FieldGroup::sanitize() before it is stored, so there is
 * exactly one place where untrusted editor input becomes stored data.
 */
final class FieldGroupEditor extends Module {

	/**
	 * Nonce action.
	 */
	private const NONCE = 'wpcmb_save_field_group';

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
		add_action( 'add_meta_boxes_' . FieldGroupPostType::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . FieldGroupPostType::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
	}

	/**
	 * Register the editor meta boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'wpcmb-fields',
			__( 'Fields', 'metafields-custom-fields' ),
			array( $this, 'render_fields' ),
			FieldGroupPostType::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wpcmb-location',
			__( 'Location Rules', 'metafields-custom-fields' ),
			array( $this, 'render_location' ),
			FieldGroupPostType::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'wpcmb-settings',
			__( 'Display Settings', 'metafields-custom-fields' ),
			array( $this, 'render_settings' ),
			FieldGroupPostType::POST_TYPE,
			'side',
			'default'
		);

		/*
		 * Block and Form sit in the main column, below the fields and the
		 * rules, because each is a thing the group *becomes* rather than a
		 * detail of how it looks. In the side column, under Display Settings,
		 * the form's shortcodes were below the fold on most screens and the
		 * block settings read as an afterthought.
		 */
		add_meta_box(
			'wpcmb-block',
			__( 'Block', 'metafields-custom-fields' ),
			array( $this, 'render_block_settings' ),
			FieldGroupPostType::POST_TYPE,
			'normal',
			'low'
		);

		add_meta_box(
			'wpcmb-form',
			__( 'Front-end Form', 'metafields-custom-fields' ),
			array( $this, 'render_form_settings' ),
			FieldGroupPostType::POST_TYPE,
			'normal',
			'low'
		);
	}

	/**
	 * Render the block settings.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_block_settings( \WP_Post $post ): void {
		$settings = $this->group( $post )->settings;

		printf(
			'<p><label class="wpcmb-checkbox"><input type="hidden" name="wpcmb[settings][block_enabled]" value="" />
			<input type="checkbox" name="wpcmb[settings][block_enabled]" value="1"%s /> %s</label></p>',
			checked( ! empty( $settings['block_enabled'] ), true, false ),
			esc_html__( 'Register this group as a block', 'metafields-custom-fields' )
		);

		$text = array(
			'block_name'        => array(
				__( 'Block name', 'metafields-custom-fields' ),
				__( 'Part of your saved content. Renaming it orphans blocks already placed.', 'metafields-custom-fields' ),
			),
			'block_icon'        => array( __( 'Icon', 'metafields-custom-fields' ), __( 'A Dashicon name, e.g. cover-image.', 'metafields-custom-fields' ) ),
			'block_description' => array( __( 'Description', 'metafields-custom-fields' ), '' ),
			'block_keywords'    => array( __( 'Keywords', 'metafields-custom-fields' ), __( 'Comma separated.', 'metafields-custom-fields' ) ),
		);

		foreach ( $text as $name => $labels ) {
			printf(
				'<p><label class="wpcmb-label" for="wpcmb-%1$s">%2$s</label>
				<input class="widefat" type="text" id="wpcmb-%1$s" name="wpcmb[settings][%1$s]" value="%3$s" />%4$s</p>',
				esc_attr( $name ),
				esc_html( $labels[0] ),
				esc_attr( (string) ( $settings[ $name ] ?? '' ) ),
				'' !== $labels[1] ? '<span class="description">' . esc_html( $labels[1] ) . '</span>' : ''
			);
		}

		printf( '<p><label class="wpcmb-label" for="wpcmb-block-mode">%s</label>', esc_html__( 'Default mode', 'metafields-custom-fields' ) );
		echo '<select class="widefat" id="wpcmb-block-mode" name="wpcmb[settings][block_mode]">';

		foreach ( array(
			'auto'    => __( 'Edit, then preview', 'metafields-custom-fields' ),
			'preview' => __( 'Preview', 'metafields-custom-fields' ),
			'edit'    => __( 'Edit', 'metafields-custom-fields' ),
		) as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $settings['block_mode'] ?? 'auto', $value, false ),
				esc_html( $label )
			);
		}

		echo '</select></p>';

		$chosen = (array) ( $settings['block_supports'] ?? array() );

		echo '<fieldset class="wpcmb-fieldset"><legend>' . esc_html__( 'Supports', 'metafields-custom-fields' ) . '</legend>';

		foreach ( array(
			'align'             => __( 'Alignment', 'metafields-custom-fields' ),
			'anchor'            => __( 'Anchor', 'metafields-custom-fields' ),
			'custom_class_name' => __( 'Additional CSS class', 'metafields-custom-fields' ),
			'color'             => __( 'Colour', 'metafields-custom-fields' ),
			'spacing'           => __( 'Spacing', 'metafields-custom-fields' ),
			'typography'        => __( 'Typography', 'metafields-custom-fields' ),
		) as $value => $label ) {
			printf(
				'<label class="wpcmb-checkbox"><input type="checkbox" name="wpcmb[settings][block_supports][]" value="%s"%s /> %s</label>',
				esc_attr( $value ),
				checked( in_array( $value, $chosen, true ), true, false ),
				esc_html( $label )
			);
		}

		printf(
			'<label class="wpcmb-checkbox"><input type="hidden" name="wpcmb[settings][block_inner_blocks]" value="" />
			<input type="checkbox" name="wpcmb[settings][block_inner_blocks]" value="1"%s /> %s</label>',
			checked( ! empty( $settings['block_inner_blocks'] ), true, false ),
			esc_html__( 'Allow inner blocks', 'metafields-custom-fields' )
		);

		echo '</fieldset>';
	}

	/**
	 * Replace the title placeholder.
	 *
	 * @param string   $text Placeholder text.
	 * @param \WP_Post $post Current post.
	 */
	public function title_placeholder( $text, $post ): string {
		if ( $post instanceof \WP_Post && FieldGroupPostType::POST_TYPE === $post->post_type ) {
			return __( 'Field group title', 'metafields-custom-fields' );
		}

		return (string) $text;
	}

	/**
	 * Render the fields builder.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_fields( \WP_Post $post ): void {
		$group = $this->group( $post );

		wp_nonce_field( self::NONCE, 'wpcmb_nonce' );

		printf( '<input type="hidden" name="wpcmb[key]" value="%s" />', esc_attr( $group->key ) );
		?>
		<div class="wpcmb-builder" data-wpcmb-builder="fields">
			<textarea
				class="wpcmb-builder__state"
				name="wpcmb_fields_json"
				hidden
				aria-hidden="true"
			><?php echo esc_textarea( (string) wp_json_encode( $group->fields ) ); ?></textarea>

			<div class="wpcmb-builder__list" data-wpcmb-list></div>

			<p class="wpcmb-builder__actions">
				<button type="button" class="wpcmb-btn wpcmb-btn--add" data-wpcmb-add-field>
					<?php esc_html_e( 'Add Field', 'metafields-custom-fields' ); ?>
				</button>
			</p>

			<p class="wpcmb-builder__fallback">
				<?php esc_html_e( 'The fields builder needs JavaScript. Your saved fields are unchanged while it is unavailable.', 'metafields-custom-fields' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the location rule builder.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_location( \WP_Post $post ): void {
		$group = $this->group( $post );
		?>
		<div class="wpcmb-builder" data-wpcmb-builder="location">
			<p class="description">
				<?php esc_html_e( 'Show this field group when all rules in any one group match.', 'metafields-custom-fields' ); ?>
			</p>

			<textarea
				class="wpcmb-builder__state"
				name="wpcmb_location_json"
				hidden
				aria-hidden="true"
			><?php echo esc_textarea( (string) wp_json_encode( $group->location ) ); ?></textarea>

			<div class="wpcmb-builder__list" data-wpcmb-list></div>

			<p class="wpcmb-builder__actions">
				<button type="button" class="wpcmb-btn wpcmb-btn--add" data-wpcmb-add-group>
					<?php esc_html_e( 'Add Rule Group', 'metafields-custom-fields' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the display settings.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_settings( \WP_Post $post ): void {
		$settings = $this->group( $post )->settings;

		$selects = array(
			'position'              => array(
				'label'   => __( 'Position', 'metafields-custom-fields' ),
				'choices' => array(
					'normal'   => __( 'After content', 'metafields-custom-fields' ),
					'side'     => __( 'Side', 'metafields-custom-fields' ),
					'advanced' => __( 'Advanced', 'metafields-custom-fields' ),
				),
			),
			'style'                 => array(
				'label'   => __( 'Style', 'metafields-custom-fields' ),
				'choices' => array(
					'default'  => __( 'Standard meta box', 'metafields-custom-fields' ),
					'seamless' => __( 'Seamless', 'metafields-custom-fields' ),
				),
			),
			'label_placement'       => array(
				'label'   => __( 'Label placement', 'metafields-custom-fields' ),
				'choices' => array(
					'top'  => __( 'Above fields', 'metafields-custom-fields' ),
					'left' => __( 'Beside fields', 'metafields-custom-fields' ),
				),
			),
			'instruction_placement' => array(
				'label'   => __( 'Instruction placement', 'metafields-custom-fields' ),
				'choices' => array(
					'field' => __( 'Below the field', 'metafields-custom-fields' ),
					'label' => __( 'Above the field', 'metafields-custom-fields' ),
				),
			),
		);

		echo '<p class="description">' . esc_html__( 'Publish this group to activate it. Saving it as a draft keeps it inactive.', 'metafields-custom-fields' ) . '</p>';

		foreach ( $selects as $name => $select ) {
			printf( '<p><label class="wpcmb-label" for="wpcmb-%1$s">%2$s</label>', esc_attr( $name ), esc_html( $select['label'] ) );
			printf( '<select class="widefat" id="wpcmb-%1$s" name="wpcmb[settings][%1$s]">', esc_attr( $name ) );

			foreach ( $select['choices'] as $value => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $value ),
					selected( $settings[ $name ] ?? '', $value, false ),
					esc_html( $label )
				);
			}

			echo '</select></p>';
		}

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-menu-order">%s</label>
			<input class="widefat" type="number" id="wpcmb-menu-order" name="wpcmb[settings][menu_order]" value="%s" /></p>',
			esc_html__( 'Order', 'metafields-custom-fields' ),
			esc_attr( (string) ( $settings['menu_order'] ?? 0 ) )
		);

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-description">%s</label>
			<input class="widefat" type="text" id="wpcmb-description" name="wpcmb[settings][description]" value="%s" /></p>',
			esc_html__( 'Description', 'metafields-custom-fields' ),
			esc_attr( (string) ( $settings['description'] ?? '' ) )
		);

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-display-title">%s</label>
			<input class="widefat" type="text" id="wpcmb-display-title" name="wpcmb[settings][display_title]" value="%s" />
			<span class="description">%s</span></p>',
			esc_html__( 'Display title', 'metafields-custom-fields' ),
			esc_attr( (string) ( $settings['display_title'] ?? '' ) ),
			esc_html__( 'Heading shown above the fields on the edit screen. Leave empty to use the group title.', 'metafields-custom-fields' )
		);

		/*
		 * The hidden 0 is what makes unchecking this stick: a browser posts
		 * nothing at all for an unchecked box, and absence has to keep meaning
		 * "exposed" for every group saved before this setting existed.
		 */
		printf(
			'<p><input type="hidden" name="wpcmb[settings][show_in_rest]" value="0" />
			<label class="wpcmb-checkbox"><input type="checkbox" name="wpcmb[settings][show_in_rest]" value="1"%s /> %s</label>
			<span class="description">%s</span></p>',
			checked( ! empty( $settings['show_in_rest'] ), true, false ),
			esc_html__( 'Show in REST API', 'metafields-custom-fields' ),
			esc_html__( 'Off hides this group\'s fields from the REST API and the block editor.', 'metafields-custom-fields' )
		);

		$hidden = (array) ( $settings['hide_on_screen'] ?? array() );

		echo '<fieldset class="wpcmb-fieldset"><legend>' . esc_html__( 'Hide on screen', 'metafields-custom-fields' ) . '</legend>';

		foreach ( $this->hideable_elements() as $value => $label ) {
			printf(
				'<label class="wpcmb-checkbox"><input type="checkbox" name="wpcmb[settings][hide_on_screen][]" value="%s"%s /> %s</label>',
				esc_attr( $value ),
				checked( in_array( $value, $hidden, true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset>';

		$this->render_options_page_settings( $settings );
	}

	/**
	 * Persist the editor's input.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ): void {
		$post_id = (int) $post_id;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['wpcmb_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpcmb_nonce'] ) ), self::NONCE )
		) {
			return;
		}

		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			return;
		}

		$raw = isset( $_POST['wpcmb'] ) && is_array( $_POST['wpcmb'] )
			? wp_unslash( $_POST['wpcmb'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by FieldGroup::sanitize().
			: array();

		$raw['title']    = $post instanceof \WP_Post ? $post->post_title : '';
		$raw['fields']   = $this->decode_json_field( 'wpcmb_fields_json' );
		$raw['location'] = $this->decode_json_field( 'wpcmb_location_json' );

		$config = FieldGroup::sanitize( $raw );

		/**
		 * Filters a field group configuration immediately before it is stored.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $config  Sanitized configuration.
		 * @param int                  $post_id Field group post id.
		 */
		$config = (array) apply_filters( 'wpcmb/field_group/pre_save', $config, $post_id );

		$this->container->get( Repository::class )->save( $post_id, $config );

		/**
		 * Fires after a field group has been saved.
		 *
		 * @since 1.0.0
		 *
		 * @param int                  $post_id Field group post id.
		 * @param array<string, mixed> $config  Stored configuration.
		 */
		do_action( 'wpcmb/field_group/saved', $post_id, $config );
	}

	/**
	 * Decode one of the JSON-carrying editor controls.
	 *
	 * Returns an empty array for anything that is not a JSON array, which
	 * FieldGroup::sanitize() then treats as "no fields" or "no rules".
	 *
	 * @param string $key Request key.
	 *
	 * @return array<int, mixed>
	 */
	private function decode_json_field( string $key ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- save() verified the nonce before calling this.
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded, then sanitized by FieldGroup::sanitize().
		$decoded = json_decode( wp_unslash( $_POST[ $key ] ), true );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * The group being edited, or an empty group for a new post.
	 *
	 * @param \WP_Post $post Current post.
	 */
	private function group( \WP_Post $post ): FieldGroup {
		return $this->container->get( Repository::class )->get( $post->ID ) ?? new FieldGroup();
	}

	/**
	 * Editor elements a field group may hide.
	 *
	 * @return array<string, string>
	 */
	/**
	 * Render the front-end form section.
	 *
	 * Its own section rather than a line in Display Settings: a group
	 * that renders as a public form is doing something different from a
	 * group that renders on an edit screen, and the shortcode is the
	 * only thing anybody needs from this screen to use it.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_form_settings( \WP_Post $post ): void {
		/*
		 * Two snippets, because there is no one honest snippet. A form takes
		 * submissions from signed-in visitors only unless it is told
		 * otherwise, and the earlier wording here promised "a public form"
		 * while handing over the shortcode that refuses one — so the first
		 * thing anybody saw after pasting it was "You need to sign in".
		 */
		$key = $this->group( $post )->key;

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-shortcode">%1$s</label>
			<input class="widefat code" type="text" id="wpcmb-shortcode" value="%2$s" readonly onfocus="this.select()" />
			<span class="description">%3$s</span></p>

			<p><label class="wpcmb-label" for="wpcmb-shortcode-guests">%4$s</label>
			<input class="widefat code" type="text" id="wpcmb-shortcode-guests" value="%5$s" readonly onfocus="this.select()" />
			<span class="description">%6$s</span></p>',
			esc_html__( 'Front-end form — signed-in visitors', 'metafields-custom-fields' ),
			esc_attr( wpcmb_form_shortcode( $key ) ),
			esc_html__( 'Paste into any post or page. Visitors who are not signed in are asked to sign in.', 'metafields-custom-fields' ),
			esc_html__( 'Front-end form — anyone', 'metafields-custom-fields' ),
			esc_attr( wpcmb_form_shortcode( $key, array( 'guests' => '1' ) ) ),
			esc_html__( 'Accepts submissions from anyone, signed in or not. Uploads still need an account.', 'metafields-custom-fields' )
		);
	}

	/**
	 * Render the settings for the page an `options_page` rule creates.
	 *
	 * Shown on every group rather than only the ones with such a rule: the
	 * rules live in another meta box and can change after this one is drawn,
	 * and a setting that appears and disappears as rules are edited is worse
	 * than one that is explained.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 */
	private function render_options_page_settings( array $settings ): void {
		echo '<fieldset class="wpcmb-fieldset"><legend>' . esc_html__( 'Options page', 'metafields-custom-fields' ) . '</legend>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Used when a location rule points this group at an options page.', 'metafields-custom-fields' )
		);

		$parents = array(
			''                    => __( 'Its own top-level menu', 'metafields-custom-fields' ),
			Menu::SLUG            => __( 'Under Meta Boxes', 'metafields-custom-fields' ),
			'options-general.php' => __( 'Under Settings', 'metafields-custom-fields' ),
			'tools.php'           => __( 'Under Tools', 'metafields-custom-fields' ),
			'themes.php'          => __( 'Under Appearance', 'metafields-custom-fields' ),
			'edit.php'            => __( 'Under Posts', 'metafields-custom-fields' ),
			'upload.php'          => __( 'Under Media', 'metafields-custom-fields' ),
			'users.php'           => __( 'Under Users', 'metafields-custom-fields' ),
		);

		printf( '<p><label class="wpcmb-label" for="wpcmb-options-parent">%s</label>', esc_html__( 'Menu location', 'metafields-custom-fields' ) );
		echo '<select class="widefat" id="wpcmb-options-parent" name="wpcmb[settings][options_parent]">';

		foreach ( $parents as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( (string) ( $settings['options_parent'] ?? '' ), $value, false ),
				esc_html( $label )
			);
		}

		echo '</select></p>';

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-options-capability">%s</label>
			<input class="widefat" type="text" id="wpcmb-options-capability" name="wpcmb[settings][options_capability]" value="%s" placeholder="manage_options" />
			<span class="description">%s</span></p>',
			esc_html__( 'Capability', 'metafields-custom-fields' ),
			esc_attr( (string) ( $settings['options_capability'] ?? '' ) ),
			esc_html__( 'Who may open and save the page. Defaults to manage_options.', 'metafields-custom-fields' )
		);

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-options-icon">%s</label>
			<input class="widefat" type="text" id="wpcmb-options-icon" name="wpcmb[settings][options_icon]" value="%s" placeholder="dashicons-admin-generic" />
			<span class="description">%s</span></p>',
			esc_html__( 'Menu icon', 'metafields-custom-fields' ),
			esc_attr( (string) ( $settings['options_icon'] ?? '' ) ),
			esc_html__( 'A Dashicon name or an image URL. Only used for a top-level menu.', 'metafields-custom-fields' )
		);

		echo '</fieldset>';
	}

	/**
	 * The edit-screen elements a group can hide, keyed by their setting value.
	 *
	 * @return array<string, string>
	 */
	private function hideable_elements(): array {
		return array(
			'permalink'       => __( 'Permalink', 'metafields-custom-fields' ),
			'the_content'     => __( 'Content editor', 'metafields-custom-fields' ),
			'excerpt'         => __( 'Excerpt', 'metafields-custom-fields' ),
			'custom_fields'   => __( 'Custom fields', 'metafields-custom-fields' ),
			'page_attributes' => __( 'Page attributes', 'metafields-custom-fields' ),
			'discussion'      => __( 'Discussion', 'metafields-custom-fields' ),
			'comments'        => __( 'Comments', 'metafields-custom-fields' ),
			'revisions'       => __( 'Revisions', 'metafields-custom-fields' ),
			'slug'            => __( 'Slug', 'metafields-custom-fields' ),
			'author'          => __( 'Author', 'metafields-custom-fields' ),
			'format'          => __( 'Format', 'metafields-custom-fields' ),
			'featured_image'  => __( 'Featured image', 'metafields-custom-fields' ),
			'categories'      => __( 'Categories', 'metafields-custom-fields' ),
			'tags'            => __( 'Tags', 'metafields-custom-fields' ),
			'send-trackbacks' => __( 'Send trackbacks', 'metafields-custom-fields' ),
		);
	}
}
