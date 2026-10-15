<?php
/**
 * Post types a site registers through the plugin.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\PostTypes;

use WPCMB\Abstracts\RecordType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post types a site has defined on the Post Types screen.
 *
 * What this does not do is own them. `register_post_type()` is a WordPress
 * function and these records are arguments to it — a site that outgrows the
 * screen exports its post types as PHP from Tools, drops that in a theme or a
 * small plugin, and deletes the records. Nothing about the content moves,
 * because the content was never stored here.
 */
final class CustomPostTypes extends RecordType {

	/**
	 * Post type storing these records.
	 */
	public const POST_TYPE = 'wpcmb_post_type';

	/**
	 * Names WordPress will not let a plugin register.
	 *
	 * The reserved list from `register_post_type()`'s own documentation, plus
	 * this plugin's own. Registering over one of these breaks the admin in
	 * ways that are hard to trace back, so it is refused at the boundary.
	 */
	private const RESERVED = array(
		'post',
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
		'action',
		'author',
		'order',
		'theme',
		'fields',
	);

	/**
	 * The post type that stores these records.
	 */
	public function post_type(): string {
		return self::POST_TYPE;
	}

	/**
	 * Names for the labels and headings.
	 *
	 * @return array{singular: string, plural: string}
	 */
	public function names(): array {
		return array(
			'singular' => __( 'Post Type', 'metafields-custom-fields' ),
			'plural'   => __( 'Post Types', 'metafields-custom-fields' ),
		);
	}

	/**
	 * Everything a record holds, before anybody has filled it in.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'name'                => '',
			'singular'            => '',
			'plural'              => '',
			'description'         => '',
			'public'              => true,
			'hierarchical'        => false,
			'has_archive'         => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'menu_position'       => 0,
			'menu_icon'           => 'dashicons-admin-post',
			'supports'            => array( 'title', 'editor', 'thumbnail' ),
			'taxonomies'          => array(),
			'rewrite_slug'        => '',
			'rewrite'             => true,
			'with_front'          => true,
			'exclude_from_search' => false,
			'capability_type'     => 'post',
		);
	}

	/**
	 * The `supports` features a post type can be given.
	 *
	 * @return array<string, string>
	 */
	public static function features(): array {
		return array(
			'title'           => __( 'Title', 'metafields-custom-fields' ),
			'editor'          => __( 'Editor', 'metafields-custom-fields' ),
			'excerpt'         => __( 'Excerpt', 'metafields-custom-fields' ),
			'thumbnail'       => __( 'Featured image', 'metafields-custom-fields' ),
			'author'          => __( 'Author', 'metafields-custom-fields' ),
			'comments'        => __( 'Comments', 'metafields-custom-fields' ),
			'trackbacks'      => __( 'Trackbacks', 'metafields-custom-fields' ),
			'revisions'       => __( 'Revisions', 'metafields-custom-fields' ),
			'custom-fields'   => __( 'Custom fields', 'metafields-custom-fields' ),
			'page-attributes' => __( 'Page attributes', 'metafields-custom-fields' ),
			'post-formats'    => __( 'Post formats', 'metafields-custom-fields' ),
		);
	}

	/**
	 * Clean a submitted configuration.
	 *
	 * @param array<string, mixed> $raw Untrusted configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize( array $raw ): array {
		$defaults = self::defaults();

		/*
		 * The key is the one value that cannot be changed later without
		 * orphaning every post stored under the old one, so it is cleaned
		 * hard: lowercase, 1-20 characters, letters, numbers, underscores and
		 * dashes only. WordPress enforces the length itself and fails
		 * silently past it.
		 *
		 * Normalised the same way a field name is, which turns "Project
		 * Items" into project_items rather than projectitems — `sanitize_key`
		 * alone drops the space and runs the words together.
		 */
		$name = substr( \WPCMB\Fields\FieldGroup::sanitize_field_name( (string) ( $raw['name'] ?? '' ) ), 0, 20 );

		$supports = array();

		foreach ( (array) ( $raw['supports'] ?? array() ) as $feature ) {
			$feature = sanitize_key( (string) $feature );

			if ( isset( self::features()[ $feature ] ) ) {
				$supports[] = $feature;
			}
		}

		$taxonomies = array();

		foreach ( (array) ( $raw['taxonomies'] ?? array() ) as $taxonomy ) {
			$taxonomy = sanitize_key( (string) $taxonomy );

			if ( '' !== $taxonomy ) {
				$taxonomies[] = $taxonomy;
			}
		}

		$capability = sanitize_key( (string) ( $raw['capability_type'] ?? '' ) );

		return array(
			'name'                => $name,
			'singular'            => sanitize_text_field( (string) ( $raw['singular'] ?? '' ) ),
			'plural'              => sanitize_text_field( (string) ( $raw['plural'] ?? '' ) ),
			'description'         => sanitize_text_field( (string) ( $raw['description'] ?? '' ) ),
			'public'              => ! empty( $raw['public'] ),
			'hierarchical'        => ! empty( $raw['hierarchical'] ),
			'has_archive'         => ! empty( $raw['has_archive'] ),
			'show_ui'             => ! empty( $raw['show_ui'] ),
			'show_in_menu'        => ! empty( $raw['show_in_menu'] ),
			'show_in_rest'        => ! empty( $raw['show_in_rest'] ),
			'menu_position'       => (int) ( $raw['menu_position'] ?? 0 ),

			// A Dashicon name or a URL; anything else would land in a style
			// attribute or a class, so it is held to those two shapes.
			'menu_icon'           => self::sanitize_icon( (string) ( $raw['menu_icon'] ?? '' ) ),
			'supports'            => array_values( array_unique( $supports ) ),
			'taxonomies'          => array_values( array_unique( $taxonomies ) ),
			'rewrite'             => ! empty( $raw['rewrite'] ),
			'rewrite_slug'        => sanitize_title( (string) ( $raw['rewrite_slug'] ?? '' ) ),
			'with_front'          => ! empty( $raw['with_front'] ),
			'exclude_from_search' => ! empty( $raw['exclude_from_search'] ),
			'capability_type'     => '' !== $capability ? $capability : $defaults['capability_type'],
		);
	}

	/**
	 * A Dashicon name, an image URL, or nothing.
	 *
	 * @param string $icon Submitted icon.
	 */
	private static function sanitize_icon( string $icon ): string {
		$icon = trim( $icon );

		if ( 1 === preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) ) {
			return $icon;
		}

		/*
		 * The picker's Media Library tab hands back an attachment id, and
		 * `menu_icon` takes a URL. Resolved here rather than at registration,
		 * so the stored value is the thing WordPress will be given and a
		 * deleted attachment does not leave a menu with a broken image.
		 */
		if ( ctype_digit( $icon ) ) {
			$url = wp_get_attachment_url( (int) $icon );

			return is_string( $url ) ? $url : '';
		}

		if ( str_starts_with( $icon, 'data:image/svg+xml;base64,' ) ) {
			// Core supports this, but an SVG from a form field is markup
			// nobody checked going into a style attribute. Refused.
			return '';
		}

		$url = sanitize_url( $icon );

		return '' !== $url && 1 === preg_match( '/\.(png|jpg|jpeg|gif|svg|webp)$/i', $url ) ? $url : '';
	}

	/**
	 * Why a name cannot be used, or an empty string.
	 *
	 * Separate from sanitize() because the editor has to explain the refusal,
	 * and "it vanished" is not an explanation.
	 *
	 * @param string $name    Post type name.
	 * @param int    $post_id The record being saved, so it does not clash with itself.
	 */
	public function rejection( string $name, int $post_id = 0 ): string {
		if ( '' === $name ) {
			return __( 'A post type needs a name.', 'metafields-custom-fields' );
		}

		if ( in_array( $name, self::RESERVED, true ) ) {
			return sprintf(
				/* translators: %s: the reserved post type name. */
				__( '"%s" is reserved by WordPress and cannot be used.', 'metafields-custom-fields' ),
				$name
			);
		}

		if ( str_starts_with( $name, 'wp_' ) ) {
			return __( 'Names beginning with wp_ are reserved by WordPress.', 'metafields-custom-fields' );
		}

		foreach ( $this->records( true ) as $id => $record ) {
			if ( $id !== $post_id && ( $record['name'] ?? '' ) === $name ) {
				return sprintf(
					/* translators: %s: the post type name. */
					__( 'Another record already registers "%s".', 'metafields-custom-fields' ),
					$name
				);
			}
		}

		/*
		 * A post type somebody else registered. Taking it over would change
		 * its labels and settings out from under the code that owns it, so
		 * the record is stored and left dormant rather than applied.
		 */
		if ( post_type_exists( $name ) && ! $this->owns( $name ) ) {
			return sprintf(
				/* translators: %s: the post type name. */
				__( '"%s" is already registered by something else on this site.', 'metafields-custom-fields' ),
				$name
			);
		}

		return '';
	}

	/**
	 * Whether a registered post type came from one of these records.
	 *
	 * @param string $name Post type name.
	 */
	public function owns( string $name ): bool {
		foreach ( $this->records( true ) as $record ) {
			if ( ( $record['name'] ?? '' ) === $name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register every post type a record describes.
	 *
	 * @param array<int, array<string, mixed>> $records Stored configurations.
	 */
	public function apply( array $records ): void {
		foreach ( $records as $post_id => $record ) {
			$name = (string) ( $record['name'] ?? '' );

			// A name that cannot be registered is left alone rather than
			// forced: the editor already said why, and overwriting somebody
			// else's post type on every page load would be worse.
			if ( '' === $name || post_type_exists( $name ) ) {
				continue;
			}

			$args = $this->arguments( $record );

			/**
			 * Filters the arguments one post type is registered with.
			 *
			 * @since 1.10.0
			 *
			 * @param array<string, mixed> $args    Registration arguments.
			 * @param string               $name    Post type name.
			 * @param int                  $post_id Record post id.
			 */
			$args = (array) apply_filters( 'wpcmb/post_type/args', $args, $name, $post_id );

			$registered = register_post_type( $name, $args );

			if ( is_wp_error( $registered ) ) {
				continue;
			}

			$this->attach_taxonomies( $name, (array) ( $record['taxonomies'] ?? array() ) );
		}
	}

	/**
	 * Attach the taxonomies a record names, once they exist.
	 *
	 * Run a second time, later, because the two halves of the join register at
	 * the same moment and one of them has to be second. A post type naming a
	 * taxonomy that a record defines would otherwise attach nothing — the
	 * taxonomy did not exist yet — and the customer would be left ticking a
	 * box that silently did nothing unless they also ticked the matching box
	 * on the other screen.
	 */
	public function attach_late(): void {
		foreach ( $this->records() as $record ) {
			$name = (string) ( $record['name'] ?? '' );

			if ( '' === $name || ! post_type_exists( $name ) ) {
				continue;
			}

			$this->attach_taxonomies( $name, (array) ( $record['taxonomies'] ?? array() ) );
		}
	}

	/**
	 * Attach a list of taxonomies to one post type.
	 *
	 * @param string            $post_type  Post type name.
	 * @param array<int, mixed> $taxonomies Taxonomy names.
	 */
	private function attach_taxonomies( string $post_type, array $taxonomies ): void {
		foreach ( $taxonomies as $taxonomy ) {
			$taxonomy = (string) $taxonomy;

			// Registering a taxonomy that is not there yet would register an
			// empty one under that name and shadow the real record.
			if ( '' !== $taxonomy && taxonomy_exists( $taxonomy ) ) {
				register_taxonomy_for_object_type( $taxonomy, $post_type );
			}
		}
	}

	/**
	 * Register hooks, plus the late pass that completes the join.
	 */
	public function boot(): void {
		parent::boot();

		// After both record types have registered on priority 6.
		add_action( 'init', array( $this, 'attach_late' ), 8 );
	}

	/**
	 * Turn a record into register_post_type() arguments.
	 *
	 * @param array<string, mixed> $record Stored configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function arguments( array $record ): array {
		$record = array_merge( self::defaults(), $record );

		$singular = '' !== $record['singular'] ? (string) $record['singular'] : (string) $record['title'];
		$plural   = '' !== $record['plural'] ? (string) $record['plural'] : $singular;

		$rewrite = (bool) $record['rewrite']
			? array(
				'slug'       => '' !== $record['rewrite_slug'] ? (string) $record['rewrite_slug'] : (string) $record['name'],
				'with_front' => (bool) $record['with_front'],
			)
			: false;

		return array(
			'labels'              => $this->labels( $singular, $plural ),
			'description'         => (string) $record['description'],
			'public'              => (bool) $record['public'],
			'hierarchical'        => (bool) $record['hierarchical'],
			'has_archive'         => (bool) $record['has_archive'],
			'show_ui'             => (bool) $record['show_ui'],
			'show_in_menu'        => (bool) $record['show_in_menu'],
			'show_in_rest'        => (bool) $record['show_in_rest'],
			'menu_position'       => 0 !== (int) $record['menu_position'] ? (int) $record['menu_position'] : null,
			'menu_icon'           => '' !== $record['menu_icon'] ? (string) $record['menu_icon'] : null,

			// A post type with nothing it supports is one nobody can fill in,
			// so an empty list falls back to a title.
			'supports'            => array() !== $record['supports'] ? (array) $record['supports'] : array( 'title' ),
			'taxonomies'          => (array) $record['taxonomies'],
			'rewrite'             => $rewrite,
			'exclude_from_search' => (bool) $record['exclude_from_search'],
			'capability_type'     => (string) $record['capability_type'],
			'map_meta_cap'        => true,
			'can_export'          => true,
		);
	}

	/**
	 * The full label set, built from a singular and a plural name.
	 *
	 * All of them, because the half WordPress generates from `name` alone
	 * leaves "Add New Post" on a screen for something that is not a post.
	 *
	 * @param string $singular Singular name.
	 * @param string $plural   Plural name.
	 *
	 * @return array<string, string>
	 */
	private function labels( string $singular, string $plural ): array {
		return array(
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			'all_items'          => $plural,
			/* translators: %s: singular post type name. */
			'add_new_item'       => sprintf( __( 'Add %s', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: singular post type name. */
			'edit_item'          => sprintf( __( 'Edit %s', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: singular post type name. */
			'new_item'           => sprintf( __( 'New %s', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: singular post type name. */
			'view_item'          => sprintf( __( 'View %s', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: plural post type name. */
			'view_items'         => sprintf( __( 'View %s', 'metafields-custom-fields' ), $plural ),
			/* translators: %s: plural post type name. */
			'search_items'       => sprintf( __( 'Search %s', 'metafields-custom-fields' ), $plural ),
			/* translators: %s: lowercase plural post type name. */
			'not_found'          => sprintf( __( 'No %s found.', 'metafields-custom-fields' ), strtolower( $plural ) ),
			/* translators: %s: lowercase plural post type name. */
			'not_found_in_trash' => sprintf( __( 'No %s found in Trash.', 'metafields-custom-fields' ), strtolower( $plural ) ),
			/* translators: %s: singular post type name. */
			'parent_item_colon'  => sprintf( __( 'Parent %s:', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: lowercase singular post type name. */
			'item_published'     => sprintf( __( '%s published.', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: singular post type name. */
			'item_updated'       => sprintf( __( '%s updated.', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: plural post type name. */
			'items_list'         => sprintf( __( '%s list', 'metafields-custom-fields' ), $plural ),
			/* translators: %s: lowercase plural post type name. */
			'item_link'          => sprintf( __( '%s link', 'metafields-custom-fields' ), $singular ),
		);
	}
}
