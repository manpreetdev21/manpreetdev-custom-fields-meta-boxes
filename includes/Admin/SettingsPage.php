<?php
/**
 * Settings screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\FieldTypes\Choice;
use WPCMB\FieldTypes\Media;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings, built on the WordPress Settings API.
 *
 * The Settings API supplies the nonce, the capability check, the option
 * writes and the "Settings saved" notice, so this class only declares the
 * fields and their sanitizers.
 */
final class SettingsPage extends Module {

	/**
	 * Page slug.
	 */
	public const SLUG = 'wpcmb-settings';

	/**
	 * Option group name.
	 */
	private const OPTION_GROUP = 'wpcmb_settings';

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
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the settings submenu.
	 */
	public function register_page(): void {
		add_submenu_page(
			Menu::SLUG,
			__( 'Settings', 'manpreetdev-custom-fields-meta-boxes' ),
			__( 'Settings', 'manpreetdev-custom-fields-meta-boxes' ),
			FieldGroupPostType::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Declare settings, sections and fields.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			'wpcmb_admin_theme',
			array(
				'type'              => 'string',
				'default'           => 'auto',
				'sanitize_callback' => static fn( $value ): string =>
					in_array( $value, array( 'auto', 'light', 'dark' ), true ) ? (string) $value : 'auto',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'wpcmb_delete_data_on_uninstall',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( self::class, 'sanitize_opt_in' ),
			)
		);

		add_settings_section(
			'wpcmb_general',
			__( 'General', 'manpreetdev-custom-fields-meta-boxes' ),
			'__return_false',
			self::SLUG
		);

		add_settings_field(
			'wpcmb_admin_theme',
			__( 'Admin theme', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_theme_field' ),
			self::SLUG,
			'wpcmb_general',
			array( 'label_for' => 'wpcmb_admin_theme' )
		);

		add_settings_field(
			'wpcmb_delete_data_on_uninstall',
			__( 'On uninstall', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_uninstall_field' ),
			self::SLUG,
			'wpcmb_general'
		);

		$this->register_list_settings();
	}

	/**
	 * Declare the editable country and region lists.
	 *
	 * Kept as free text in the same `value : Label` form the choices setting
	 * uses rather than a row-per-entry builder: these lists are pasted and
	 * bulk-edited far more often than they are picked at one by one, and a
	 * textarea is the control that makes that easy.
	 */
	private function register_list_settings(): void {
		foreach ( array( Choice::COUNTRIES_OPTION, Choice::STATES_OPTION ) as $option ) {
			register_setting(
				self::OPTION_GROUP,
				$option,
				array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => array( $this, 'sanitize_list' ),
				)
			);
		}

		add_settings_section(
			'wpcmb_lists',
			__( 'Country and region lists', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_lists_intro' ),
			self::SLUG
		);

		add_settings_field(
			Choice::COUNTRIES_OPTION,
			__( 'Countries', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_countries_field' ),
			self::SLUG,
			'wpcmb_lists',
			array( 'label_for' => Choice::COUNTRIES_OPTION )
		);

		add_settings_field(
			Choice::STATES_OPTION,
			__( 'States / regions', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_states_field' ),
			self::SLUG,
			'wpcmb_lists',
			array( 'label_for' => Choice::STATES_OPTION )
		);

		register_setting(
			self::OPTION_GROUP,
			Media::TYPES_OPTION,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_list' ),
			)
		);

		add_settings_section(
			'wpcmb_uploads',
			__( 'Media upload types', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_uploads_intro' ),
			self::SLUG
		);

		add_settings_field(
			Media::TYPES_OPTION,
			__( 'Upload types', 'manpreetdev-custom-fields-meta-boxes' ),
			array( $this, 'render_upload_types_field' ),
			self::SLUG,
			'wpcmb_uploads',
			array( 'label_for' => Media::TYPES_OPTION )
		);
	}

	/**
	 * Explain what the upload type list is for.
	 */
	public function render_uploads_intro(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'What a file, image, gallery, video or audio field can be restricted to. Each line becomes a choice in the field editor, so "what this site accepts" is answered once here rather than retyped into every field.',
				'manpreetdev-custom-fields-meta-boxes'
			)
		);
	}

	/**
	 * Render the upload types control.
	 */
	public function render_upload_types_field(): void {
		$this->render_list_field(
			Media::TYPES_OPTION,
			"image : Any image\npdf, docx : Documents",
			sprintf(
				/* translators: %s: comma-separated list of the built-in upload type labels. */
				__( 'One per line, as "extensions : Label". A family like image, video or audio works as a value too. Leave empty for the built-in list: %s.', 'manpreetdev-custom-fields-meta-boxes' ),
				implode( ', ', Media::default_upload_types() )
			)
		);
	}

	/**
	 * Read a value as a deliberate yes, and treat everything else as no.
	 *
	 * Stricter than casting and stricter than rest_sanitize_boolean(), both of
	 * which read some form of "no" as yes: `(bool) 'false'` is true, and
	 * rest_sanitize_boolean() still returns true for 'no', 'off' and any other
	 * word it does not recognise. This guards "delete everything on uninstall",
	 * where a wrong yes destroys every field group and value on the site, so
	 * nothing but an explicit affirmative counts.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_opt_in( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 1 === (int) $value;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Clean a submitted list.
	 *
	 * Line breaks have to survive, so this is not sanitize_textarea_field:
	 * each line is cleaned on its own and the structure is rebuilt.
	 *
	 * @param mixed $value Submitted value.
	 */
	public function sanitize_list( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$lines = array_filter(
			array_map(
				static fn( $line ): string => trim( sanitize_text_field( $line ) ),
				(array) preg_split( '/\r\n|\r|\n/', $value )
			),
			static fn( $line ): bool => '' !== $line
		);

		return implode( "\n", $lines );
	}

	/**
	 * Explain what the two lists do.
	 */
	public function render_lists_intro(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'One entry per line, as "value : Label" — for example "FR : France". The value is what gets stored; the label is what editors see. Leave a list empty to use the built-in one.',
				'manpreetdev-custom-fields-meta-boxes'
			)
		);
	}

	/**
	 * Render the countries list control.
	 */
	public function render_countries_field(): void {
		$this->render_list_field(
			Choice::COUNTRIES_OPTION,
			"FR : France\nDE : Germany",
			__( 'Adds to or replaces the built-in ISO country list.', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}

	/**
	 * Render the regions list control.
	 */
	public function render_states_field(): void {
		$this->render_list_field(
			Choice::STATES_OPTION,
			"NSW : New South Wales\nVIC : Victoria",
			__( 'The built-in list is US states. Replace it with the regions your site actually uses.', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}

	/**
	 * Render one list textarea.
	 *
	 * @param string $option      Option name.
	 * @param string $placeholder Example content.
	 * @param string $help        Description below the control.
	 */
	private function render_list_field( string $option, string $placeholder, string $help ): void {
		printf(
			'<textarea id="%1$s" name="%1$s" class="large-text code" rows="8" placeholder="%2$s">%3$s</textarea>
			<p class="description">%4$s</p>',
			esc_attr( $option ),
			esc_attr( $placeholder ),
			esc_textarea( (string) get_option( $option, '' ) ),
			esc_html( $help )
		);
	}

	/**
	 * Render the admin theme control.
	 */
	public function render_theme_field(): void {
		$current = (string) get_option( 'wpcmb_admin_theme', 'auto' );

		$choices = array(
			'auto'  => __( 'Follow the operating system', 'manpreetdev-custom-fields-meta-boxes' ),
			'light' => __( 'Always light', 'manpreetdev-custom-fields-meta-boxes' ),
			'dark'  => __( 'Always dark', 'manpreetdev-custom-fields-meta-boxes' ),
		);

		echo '<select id="wpcmb_admin_theme" name="wpcmb_admin_theme">';

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Render the uninstall behaviour control.
	 */
	public function render_uninstall_field(): void {
		printf(
			'<label><input type="checkbox" name="wpcmb_delete_data_on_uninstall" value="1"%s /> %s</label>
			<p class="description">%s</p>',
			checked( (bool) get_option( 'wpcmb_delete_data_on_uninstall' ), true, false ),
			esc_html__( 'Delete all plugin data when the plugin is deleted', 'manpreetdev-custom-fields-meta-boxes' ),
			esc_html__( 'Off by default. Deleting a plugin to reinstall it should not cost you your field groups.', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}

	/**
	 * Render the settings screen.
	 */
	public function render(): void {
		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'manpreetdev-custom-fields-meta-boxes' ) );
		}

		?>
		<div class="wrap wpcmb-wrap">
			<h1><?php esc_html_e( 'Manpreetdev Custom Fields Settings', 'manpreetdev-custom-fields-meta-boxes' ); ?></h1>
			<p class="wpcmb-lede"><?php esc_html_e( 'How the plugin looks while you work, and what it leaves behind when it goes.', 'manpreetdev-custom-fields-meta-boxes' ); ?></p>
			<form action="options.php" method="post" class="wpcmb-settings-form">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
