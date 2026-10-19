<?php
/**
 * Admin asset loading.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\FieldTypes\Enhanced;
use WPCMB\Fields\Context;
use WPCMB\Fields\Locations;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Registry;
use WPCMB\Fields\Resolver;
use WPCMB\PostTypes\CustomPostTypes;
use WPCMB\PostTypes\CustomTaxonomies;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues admin assets, and only on screens that use them.
 *
 * The stylesheet loads on every plugin screen; the builder script loads on
 * the field group editor alone, which is the only screen with anything to
 * script. No shared script bundle exists because nothing else needs one.
 */
final class Assets extends Module {

	/**
	 * Handle for the shared admin bundle.
	 */
	public const HANDLE = 'wpcmb-admin';

	/**
	 * Handle for the field group editor bundle.
	 */
	public const BUILDER_HANDLE = 'wpcmb-builder';

	/**
	 * Handle for the field assets used on edit screens.
	 */
	public const FIELDS_HANDLE = 'wpcmb-fields';

	/**
	 * Handle for the repeater script.
	 */
	public const REPEATER_HANDLE = 'wpcmb-repeater';

	/**
	 * Handle for the QR and barcode encoders.
	 */
	public const CODES_HANDLE = 'wpcmb-codes';

	/**
	 * Handle for the advanced field controls.
	 */
	public const ENHANCED_HANDLE = 'wpcmb-enhanced';

	/**
	 * Handle for the save gate.
	 */
	public const VALIDATE_HANDLE = 'wpcmb-validate';

	/**
	 * The cache-busting version for an asset.
	 *
	 * The plugin version alone is not enough: it only changes on release, so
	 * every edit to a script between releases is invisible to a browser that
	 * already cached the old one. Using the file's modification time means a
	 * changed file is always fetched, and an unchanged one is still cached.
	 *
	 * Falls back to the plugin version when the file cannot be read, which is
	 * the right answer for a packaged install where mtimes are meaningless.
	 *
	 * @param string $relative Path below the plugin directory.
	 */
	public static function version( string $relative ): string {
		$path = WPCMB_DIR . ltrim( $relative, '/' );

		if ( ! is_readable( $path ) ) {
			return WPCMB_VERSION;
		}

		$modified = filemtime( $path );

		return false === $modified ? WPCMB_VERSION : WPCMB_VERSION . '.' . $modified;
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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Enqueue assets for the current screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		$this->enqueue_fields();

		if ( ! $this->is_plugin_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			WPCMB_URL . 'assets/css/admin.css',
			array(),
			self::version( 'assets/css/admin.css' )
		);

		if ( ! $this->is_editor_screen() ) {
			return;
		}

		wp_enqueue_script(
			self::BUILDER_HANDLE,
			WPCMB_URL . 'assets/js/builder.js',
			array( 'jquery-ui-sortable' ),
			self::version( 'assets/js/builder.js' ),
			true
		);

		wp_localize_script(
			self::BUILDER_HANDLE,
			'wpcmbBuilder',
			array(
				'locationParams'   => Locations::params(),
				'locationChoices'  => Locations::choices(),

				// Parameters whose value is a specific object, searched on
				// demand rather than shipped with the page.
				'locationObjects'  => Locations::object_params(),
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( Ajax::NONCE ),
				/**
				 * Filters the field types offered in the field group editor.
				 *
				 * The registry answers this with every registered type,
				 * grouped by the family it belongs to.
				 *
				 * @since 1.0.0
				 *
				 * @param array<string, array<string, string>> $types Labels keyed by type, grouped.
				 */
				'fieldTypes'       => apply_filters( 'wpcmb/admin/field_types', array() ),
				'fieldSettings'    => $this->container->get( Registry::class )->editor_settings(),
				'fieldIcons'       => $this->container->get( Registry::class )->editor_icons(),

				/**
				 * Filters which field types accept sub fields in the editor.
				 *
				 * A type listed here gets a nested field list in the builder.
				 *
				 * @since 1.0.0
				 *
				 * @param array<int, string> $types Type names.
				 */
				'subFieldTypes'    => (array) apply_filters( 'wpcmb/admin/sub_field_types', array( 'repeater', 'group' ) ),

				/**
				 * Filters which field types are edited as a set of layouts.
				 *
				 * A type listed here gets a layout editor in the builder,
				 * each layout carrying its own nested field list.
				 *
				 * @since 1.0.0
				 *
				 * @param array<int, string> $types Type names.
				 */
				'layoutFieldTypes' => (array) apply_filters( 'wpcmb/admin/layout_field_types', array( 'flexible_content' ) ),
				'i18n'             => array(
					'confirmRemove'       => __( 'Remove this field?', 'manpreetdev-custom-fields-meta-boxes' ),
					'newField'            => __( 'New Field', 'manpreetdev-custom-fields-meta-boxes' ),
					'orLabel'             => __( 'or', 'manpreetdev-custom-fields-meta-boxes' ),
					'andLabel'            => __( 'and', 'manpreetdev-custom-fields-meta-boxes' ),
					'nameRequired'        => __( 'Every field needs a name.', 'manpreetdev-custom-fields-meta-boxes' ),
					'duplicateName'       => __( 'Field names must be unique within a group.', 'manpreetdev-custom-fields-meta-boxes' ),
					'label'               => __( 'Label', 'manpreetdev-custom-fields-meta-boxes' ),
					'name'                => __( 'Name', 'manpreetdev-custom-fields-meta-boxes' ),
					'type'                => __( 'Type', 'manpreetdev-custom-fields-meta-boxes' ),
					'defaultValue'        => __( 'Default value', 'manpreetdev-custom-fields-meta-boxes' ),
					'placeholder'         => __( 'Placeholder', 'manpreetdev-custom-fields-meta-boxes' ),
					'width'               => __( 'Width (%)', 'manpreetdev-custom-fields-meta-boxes' ),
					'instructions'        => __( 'Instructions', 'manpreetdev-custom-fields-meta-boxes' ),
					'required'            => __( 'Required', 'manpreetdev-custom-fields-meta-boxes' ),
					'reorder'             => __( 'Drag to reorder', 'manpreetdev-custom-fields-meta-boxes' ),
					'isEqual'             => __( 'is equal to', 'manpreetdev-custom-fields-meta-boxes' ),
					'isNotEqual'          => __( 'is not equal to', 'manpreetdev-custom-fields-meta-boxes' ),
					'idOrSlug'            => __( 'ID or slug', 'manpreetdev-custom-fields-meta-boxes' ),
					'searching'           => __( 'Searching…', 'manpreetdev-custom-fields-meta-boxes' ),
					'noMatches'           => __( 'No matches. Try a different search.', 'manpreetdev-custom-fields-meta-boxes' ),
					'searchFailed'        => __( 'The search failed. Type an ID instead.', 'manpreetdev-custom-fields-meta-boxes' ),
					'chooseOne'           => __( '— Choose —', 'manpreetdev-custom-fields-meta-boxes' ),
					'typeToSearch'        => __( 'Type to search…', 'manpreetdev-custom-fields-meta-boxes' ),
					'noFieldsYet'         => __( 'No fields yet', 'manpreetdev-custom-fields-meta-boxes' ),
					'noFieldsHint'        => __( 'Add a field to decide what this group collects.', 'manpreetdev-custom-fields-meta-boxes' ),
					'noRulesYet'          => __( 'No location rules', 'manpreetdev-custom-fields-meta-boxes' ),
					'noRulesHint'         => __( 'Without a rule this group stays hidden. Add one to choose where it appears.', 'manpreetdev-custom-fields-meta-boxes' ),
					'tabGeneral'          => __( 'General', 'manpreetdev-custom-fields-meta-boxes' ),
					'tabValidation'       => __( 'Validation', 'manpreetdev-custom-fields-meta-boxes' ),
					'tabAppearance'       => __( 'Appearance', 'manpreetdev-custom-fields-meta-boxes' ),
					'tabLogic'            => __( 'Logic', 'manpreetdev-custom-fields-meta-boxes' ),
					'tabAdvanced'         => __( 'Advanced', 'manpreetdev-custom-fields-meta-boxes' ),
					'wrapperClass'        => __( 'CSS class', 'manpreetdev-custom-fields-meta-boxes' ),
					'wrapperId'           => __( 'CSS id', 'manpreetdev-custom-fields-meta-boxes' ),
					'copyKey'             => __( 'Copy field name', 'manpreetdev-custom-fields-meta-boxes' ),
					'copiedKey'           => __( 'Field name copied', 'manpreetdev-custom-fields-meta-boxes' ),
					'fieldKey'            => __( 'Field key', 'manpreetdev-custom-fields-meta-boxes' ),
					'duplicateField'      => __( 'Duplicate field', 'manpreetdev-custom-fields-meta-boxes' ),
					'deleteField'         => __( 'Delete field', 'manpreetdev-custom-fields-meta-boxes' ),
					'collapseField'       => __( 'Collapse field', 'manpreetdev-custom-fields-meta-boxes' ),
					'expandField'         => __( 'Expand field', 'manpreetdev-custom-fields-meta-boxes' ),
					'copySuffix'          => __( '(copy)', 'manpreetdev-custom-fields-meta-boxes' ),
					'searchFields'        => __( 'Search fields', 'manpreetdev-custom-fields-meta-boxes' ),
					'expandAll'           => __( 'Expand all', 'manpreetdev-custom-fields-meta-boxes' ),
					'collapseAll'         => __( 'Collapse all', 'manpreetdev-custom-fields-meta-boxes' ),
					/* translators: %d: number of fields. */
					'fieldCount'          => __( '%d fields', 'manpreetdev-custom-fields-meta-boxes' ),
					/* translators: 1: fields shown, 2: fields in total. */
					'fieldCountFiltered'  => __( '%1$d of %2$d fields', 'manpreetdev-custom-fields-meta-boxes' ),
					/* translators: %d: rule group number. */
					'ruleGroup'           => __( 'Rule group %d', 'manpreetdev-custom-fields-meta-boxes' ),
					'duplicateGroup'      => __( 'Duplicate rule group', 'manpreetdev-custom-fields-meta-boxes' ),
					'removeGroup'         => __( 'Remove rule group', 'manpreetdev-custom-fields-meta-boxes' ),
					'removeRule'          => __( 'Remove rule', 'manpreetdev-custom-fields-meta-boxes' ),
					'appearsWhen'         => __( 'Appears when:', 'manpreetdev-custom-fields-meta-boxes' ),
					'appearsNowhere'      => __( 'This group has no rules, so it will not appear anywhere.', 'manpreetdev-custom-fields-meta-boxes' ),
					'anythingLabel'       => __( '(anything)', 'manpreetdev-custom-fields-meta-boxes' ),
					// Matches Locations::describe(), so the sentence in the editor
					// and the one in the field group list are word for word the same.
					'summaryIs'           => __( 'is', 'manpreetdev-custom-fields-meta-boxes' ),
					'summaryIsNot'        => __( 'is not', 'manpreetdev-custom-fields-meta-boxes' ),
					'addRule'             => __( '+ Add rule', 'manpreetdev-custom-fields-meta-boxes' ),
					'settings'            => __( 'Settings', 'manpreetdev-custom-fields-meta-boxes' ),
					'logic'               => __( 'Conditional logic', 'manpreetdev-custom-fields-meta-boxes' ),
					'showThisField'       => __( 'Show this field when', 'manpreetdev-custom-fields-meta-boxes' ),
					'hideThisField'       => __( 'Hide this field when', 'manpreetdev-custom-fields-meta-boxes' ),
					'allRules'            => __( 'all rules match', 'manpreetdev-custom-fields-meta-boxes' ),
					'anyRule'             => __( 'any rule matches', 'manpreetdev-custom-fields-meta-boxes' ),
					'addCondition'        => __( '+ Add condition', 'manpreetdev-custom-fields-meta-boxes' ),
					'noOtherFields'       => __( 'Add another field first to use conditional logic.', 'manpreetdev-custom-fields-meta-boxes' ),
					'noSettings'          => __( 'This field type has no extra settings.', 'manpreetdev-custom-fields-meta-boxes' ),
					'subFields'           => __( 'Sub fields', 'manpreetdev-custom-fields-meta-boxes' ),
					'addSubField'         => __( 'Add sub field', 'manpreetdev-custom-fields-meta-boxes' ),
					'layouts'             => __( 'Layouts', 'manpreetdev-custom-fields-meta-boxes' ),
					'addLayout'           => __( 'Add layout', 'manpreetdev-custom-fields-meta-boxes' ),
					'confirmRemoveLayout' => __( 'Remove this layout? Rows already using it will stop appearing.', 'manpreetdev-custom-fields-meta-boxes' ),
					'icon'                => __( 'Icon', 'manpreetdev-custom-fields-meta-boxes' ),
					'category'            => __( 'Category', 'manpreetdev-custom-fields-meta-boxes' ),
					'maxPerField'         => __( 'Max uses', 'manpreetdev-custom-fields-meta-boxes' ),
					'yes'                 => __( 'Yes', 'manpreetdev-custom-fields-meta-boxes' ),
					'no'                  => __( 'No', 'manpreetdev-custom-fields-meta-boxes' ),
				),
			)
		);

		wp_set_script_translations( self::BUILDER_HANDLE, 'manpreetdev-custom-fields-meta-boxes', WPCMB_DIR . 'languages' );
	}

	/**
	 * Load the field assets, but only on a screen that actually has fields.
	 *
	 * The check costs one cached group lookup and no queries; loading the
	 * media library and the field script on every admin screen would cost
	 * far more, on screens that render nothing.
	 */
	private function enqueue_fields(): void {
		/*
		 * Widgets and nav menus come first and unconditionally.
		 *
		 * Neither screen shows one object: the widgets screen lists every
		 * widget in every sidebar and the menus screen every item in a menu,
		 * so there is no reference to resolve groups against and no way to
		 * know whether any of them will draw fields without checking all of
		 * them. Loading the field assets on three admin screens is cheaper
		 * than that, and the alternative is controls with no styles and a
		 * media button that does nothing.
		 */
		if ( $this->is_widget_or_menu_screen() ) {
			$this->enqueue_field_assets( null );

			return;
		}

		$ref = $this->current_object();

		/*
		 * A record editor has no field groups on it and still needs these: the
		 * post type screen draws its menu icon with the plugin's own icon
		 * field, which is a grid, a media frame and a URL box behind one
		 * button — and without the script, a button that does nothing.
		 */
		$context = $this->current_context();

		if ( ! $this->is_record_editor() && ( null === $context || ! $this->container->get( Resolver::class )->has_groups( $context ) ) ) {
			return;
		}

		// A record editor is a post screen, so it always has a reference; this
		// says so rather than leaving the rest of the method to assume it.
		if ( null === $ref ) {
			return;
		}

		$this->enqueue_field_assets( $ref );
	}

	/**
	 * Whether this screen lists widgets or nav menu items.
	 *
	 * The Customizer counts: a widget form inside it is the same form, drawn
	 * by the same hook.
	 */
	private function is_widget_or_menu_screen(): bool {
		if ( is_customize_preview() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen instanceof \WP_Screen
			&& in_array( $screen->base, array( 'widgets', 'nav-menus', 'customize' ), true );
	}

	/**
	 * Enqueue the field stylesheet, scripts and their configuration.
	 *
	 * @param ObjectRef|null $ref The object the screen shows, when it shows one.
	 */
	private function enqueue_field_assets( ?ObjectRef $ref ): void {
		wp_enqueue_media();

		wp_enqueue_style(
			self::FIELDS_HANDLE,
			WPCMB_URL . 'assets/css/fields.css',
			array(),
			self::version( 'assets/css/fields.css' )
		);

		wp_enqueue_script(
			self::FIELDS_HANDLE,
			WPCMB_URL . 'assets/js/fields.js',
			array(),
			self::version( 'assets/js/fields.js' ),
			true
		);

		wp_localize_script(
			self::FIELDS_HANDLE,
			'wpcmbFields',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( Ajax::NONCE ),
				'dashicons' => Enhanced::dashicons(),
				// What "uploaded to this item" means, when the screen is a
				// post. Zero everywhere else, where the filter has no subject.
				'postId'    => null !== $ref && ObjectRef::POST === $ref->type ? (int) $ref->id : 0,
				'i18n'      => array(
					'remove'             => __( 'Remove', 'manpreetdev-custom-fields-meta-boxes' ),
					'searchOptions'      => __( 'Search options', 'manpreetdev-custom-fields-meta-boxes' ),
					'selectOptions'      => __( 'Select options', 'manpreetdev-custom-fields-meta-boxes' ),
					/* translators: %d: number of options chosen. */
					'selectedCount'      => __( '%d selected', 'manpreetdev-custom-fields-meta-boxes' ),
					'noMatches'          => __( 'No matches. Try a different search.', 'manpreetdev-custom-fields-meta-boxes' ),
					'selectMedia'        => __( 'Select media', 'manpreetdev-custom-fields-meta-boxes' ),
					'iconDashicons'      => __( 'Dashicons', 'manpreetdev-custom-fields-meta-boxes' ),
					'iconMedia'          => __( 'Media Library', 'manpreetdev-custom-fields-meta-boxes' ),
					'iconUrl'            => __( 'URL', 'manpreetdev-custom-fields-meta-boxes' ),
					'iconUseUrl'         => __( 'Use this URL', 'manpreetdev-custom-fields-meta-boxes' ),
					'embedLoading'       => __( 'Loading the preview…', 'manpreetdev-custom-fields-meta-boxes' ),
					'embedNone'          => __( 'Nothing could be embedded from that URL.', 'manpreetdev-custom-fields-meta-boxes' ),
					'qrTooLong'          => __( 'That is too long to fit in a QR code.', 'manpreetdev-custom-fields-meta-boxes' ),
					'barcodeUnsupported' => __( 'A barcode can only hold plain ASCII characters.', 'manpreetdev-custom-fields-meta-boxes' ),
				),
			)
		);

		// The encoders are separate because they are pure functions with no
		// DOM in them, and because nothing else needs to load them.
		wp_enqueue_script(
			self::CODES_HANDLE,
			WPCMB_URL . 'assets/js/codes.js',
			array(),
			self::version( 'assets/js/codes.js' ),
			true
		);

		wp_enqueue_script(
			self::ENHANCED_HANDLE,
			WPCMB_URL . 'assets/js/enhanced.js',
			array( self::FIELDS_HANDLE, self::CODES_HANDLE ),
			self::version( 'assets/js/enhanced.js' ),
			true
		);

		wp_enqueue_script(
			self::REPEATER_HANDLE,
			WPCMB_URL . 'assets/js/repeater.js',
			array( self::FIELDS_HANDLE, 'jquery-ui-sortable' ),
			self::version( 'assets/js/repeater.js' ),
			true
		);

		wp_localize_script(
			self::REPEATER_HANDLE,
			'wpcmbRepeater',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'i18n'    => array(
					/* translators: %d: row number. Kept as a literal token so the script can substitute it. */
					'row'          => __( 'Row %d', 'manpreetdev-custom-fields-meta-boxes' ),
					'rowFailed'    => __( 'That row could not be added. Try saving and reloading.', 'manpreetdev-custom-fields-meta-boxes' ),
					'importCsv'    => __( 'Import CSV', 'manpreetdev-custom-fields-meta-boxes' ),
					'exportCsv'    => __( 'Export CSV', 'manpreetdev-custom-fields-meta-boxes' ),
					'importFailed' => __( 'That file could not be imported.', 'manpreetdev-custom-fields-meta-boxes' ),
					'importEmpty'  => __( 'That file had no rows.', 'manpreetdev-custom-fields-meta-boxes' ),
					/* translators: %d: number of rows imported. */
					'importDone'   => __( '%d rows added. Save to keep them.', 'manpreetdev-custom-fields-meta-boxes' ),
					'exportFailed' => __( 'That export could not be built.', 'manpreetdev-custom-fields-meta-boxes' ),
				),
			)
		);

		/*
		 * The save gate. No dependency on `wp-data`: declaring one would pull
		 * the editor's data layer onto every classic screen to serve the block
		 * editor, and the script reads it defensively either way.
		 */
		wp_enqueue_script(
			self::VALIDATE_HANDLE,
			WPCMB_URL . 'assets/js/validate.js',
			array(),
			self::version( 'assets/js/validate.js' ),
			true
		);

		wp_localize_script(
			self::VALIDATE_HANDLE,
			'wpcmbValidate',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'object'  => null !== $ref ? (string) $ref : '',
				'i18n'    => array(
					'blocked'   => __( 'This cannot be saved yet:', 'manpreetdev-custom-fields-meta-boxes' ),
					'showField' => __( 'Show me', 'manpreetdev-custom-fields-meta-boxes' ),
				),
			)
		);

		wp_set_script_translations( self::FIELDS_HANDLE, 'manpreetdev-custom-fields-meta-boxes', WPCMB_DIR . 'languages' );
		wp_set_script_translations( self::REPEATER_HANDLE, 'manpreetdev-custom-fields-meta-boxes', WPCMB_DIR . 'languages' );
		wp_set_script_translations( self::VALIDATE_HANDLE, 'manpreetdev-custom-fields-meta-boxes', WPCMB_DIR . 'languages' );
	}

	/**
	 * The object the current admin screen is editing, if any.
	 */
	private function current_object(): ?ObjectRef {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || FieldGroupPostType::POST_TYPE === $screen->post_type ) {
			return null;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading which object the screen shows, not acting on it.
		$id = static fn( string $key ): int => isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// An options page is one of ours and holds fields, which no other
		// plugin screen does. Without this the field styles and the save gate
		// never load there, and it renders bare controls nothing validates.
		$options = OptionsPages::current_slug();

		if ( '' !== $options ) {
			return new ObjectRef( ObjectRef::OPTION, $options );
		}

		return match ( $screen->base ) {
			'post'      => new ObjectRef( ObjectRef::POST, $id( 'post' ) ),
			'term'      => new ObjectRef( ObjectRef::TERM, $id( 'tag_ID' ) ),

			// The taxonomy screen carries the Add New Term form, which has
			// fields on it and no term yet. Term 0 with the taxonomy supplied
			// as a screen detail is what that form is.
			'edit-tags' => new ObjectRef( ObjectRef::TERM, 0 ),
			'user-edit' => new ObjectRef( ObjectRef::USER, $id( 'user_id' ) ),
			'profile'   => new ObjectRef( ObjectRef::USER, get_current_user_id() ),
			'comment'   => new ObjectRef( ObjectRef::COMMENT, $id( 'c' ) ),
			default     => null,
		};
	}

	/**
	 * The current screen as a context, including what cannot be derived.
	 *
	 * A term's taxonomy comes from the term everywhere except the Add New
	 * Term form, where there is no term — so the screen says which taxonomy
	 * it is, and the field assets load there as they do everywhere else.
	 */
	private function current_context(): ?Context {
		$ref = $this->current_object();

		if ( null === $ref ) {
			return null;
		}

		$screen = get_current_screen();
		$extra  = array();

		if ( $screen instanceof \WP_Screen && '' !== (string) $screen->taxonomy ) {
			$extra['taxonomy'] = (string) $screen->taxonomy;
		}

		return new Context( $ref, $extra );
	}

	/**
	 * Add a body class so the admin stylesheet can scope itself.
	 *
	 * @param string $classes Existing body classes.
	 */
	public function body_class( $classes ): string {
		$classes = (string) $classes;

		if ( ! $this->is_plugin_screen( '' ) ) {
			return $classes;
		}

		$classes .= ' wpcmb-admin';

		// `auto` adds neither class and lets the stylesheet follow the OS.
		$theme = (string) get_option( 'wpcmb_admin_theme', 'auto' );

		if ( 'dark' === $theme || 'light' === $theme ) {
			$classes .= ' wpcmb-' . $theme;
		}

		return $classes;
	}

	/**
	 * Whether the current screen belongs to this plugin.
	 *
	 * @param string $hook Current admin page hook, empty when unavailable.
	 */
	private function is_plugin_screen( string $hook ): bool {
		if ( '' !== OptionsPages::current_slug() ) {
			return true;
		}

		$screen = get_current_screen();

		// `admin_body_class` passes no hook, and a submenu screen id is the
		// same string the hook would have been. Without this the body class is
		// missing on Settings and Tools, which leaves every design token on
		// those screens unresolved.
		if ( '' === $hook && $screen instanceof \WP_Screen ) {
			$hook = $screen->id;
		}

		if ( str_contains( $hook, '_page_' . Menu::SLUG ) || str_contains( $hook, 'page_wpcmb-' ) ) {
			return true;
		}

		/*
		 * Any post type of ours, not just field groups.
		 *
		 * Field groups, post types, taxonomies and a form's submissions are
		 * all stored as `wpcmb_`-prefixed post types, and every one of them
		 * is a screen this plugin drew. Naming them one at a time meant each
		 * new record type shipped with no stylesheet, no body class, and
		 * every design token unresolved — which is how the Post Types screen
		 * first appeared.
		 */
		return $screen instanceof \WP_Screen
			&& ( str_starts_with( (string) $screen->post_type, 'wpcmb_' ) || str_starts_with( $screen->id, 'toplevel_page_' . Menu::SLUG ) );
	}

	/**
	 * Whether the current screen edits one of the plugin's record types.
	 *
	 * The post type and taxonomy editors, not the field group one — that has
	 * its own builder and its own assets.
	 */
	private function is_record_editor(): bool {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->base ) {
			return false;
		}

		return in_array(
			(string) $screen->post_type,
			array( CustomPostTypes::POST_TYPE, CustomTaxonomies::POST_TYPE ),
			true
		);
	}

	/**
	 * Whether the current screen is the field group editor.
	 */
	private function is_editor_screen(): bool {
		$screen = get_current_screen();

		return $screen instanceof \WP_Screen
			&& FieldGroupPostType::POST_TYPE === $screen->post_type
			&& 'post' === $screen->base;
	}
}
