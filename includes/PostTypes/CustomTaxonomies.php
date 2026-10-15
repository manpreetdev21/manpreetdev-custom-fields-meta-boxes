<?php
/**
 * Taxonomies a site registers through the plugin.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\PostTypes;

use WPCMB\Abstracts\RecordType;
use WPCMB\Fields\FieldGroup;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the taxonomies a site has defined on the Taxonomies screen.
 *
 * The same shape as the post type records, and the same promise: these are
 * arguments to `register_taxonomy()`, not a store. Terms live in
 * `wp_terms` either way, so deleting a record stops the taxonomy being
 * registered and leaves every term exactly where it was.
 */
final class CustomTaxonomies extends RecordType {

	/**
	 * Post type storing these records.
	 */
	public const POST_TYPE = 'wpcmb_taxonomy';

	/**
	 * Names WordPress will not let a plugin register.
	 *
	 * The reserved list from `register_taxonomy()`'s documentation. A
	 * taxonomy key is also capped at 32 characters rather than a post type's
	 * 20, which is the only other place the two differ.
	 */
	private const RESERVED = array(
		'category',
		'post_tag',
		'nav_menu',
		'link_category',
		'post_format',
		'wp_theme',
		'wp_template_part_area',
		'wp_pattern_category',
		'author',
		'fields',
		'terms',
		'type',
		'taxonomy',
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
			'singular' => __( 'Taxonomy', 'metafields-custom-fields' ),
			'plural'   => __( 'Taxonomies', 'metafields-custom-fields' ),
		);
	}

	/**
	 * Everything a record holds, before anybody has filled it in.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'name'              => '',
			'singular'          => '',
			'plural'            => '',
			'description'       => '',
			'post_types'        => array(),
			'hierarchical'      => true,
			'public'            => true,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'show_tagcloud'     => false,
			'rewrite'           => true,
			'rewrite_slug'      => '',
			'with_front'        => true,
			'sort'              => false,
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
		/*
		 * Normalised the way a post type key and a field name are, so "Project
		 * Type" becomes project_type rather than projecttype. 32 characters,
		 * which is what a taxonomy key may be — `sanitize_key` has no idea
		 * about the limit and WordPress fails quietly past it.
		 */
		$name = substr( FieldGroup::sanitize_field_name( (string) ( $raw['name'] ?? '' ) ), 0, 32 );

		$post_types = array();

		foreach ( (array) ( $raw['post_types'] ?? array() ) as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' !== $post_type ) {
				$post_types[] = $post_type;
			}
		}

		return array(
			'name'              => $name,
			'singular'          => sanitize_text_field( (string) ( $raw['singular'] ?? '' ) ),
			'plural'            => sanitize_text_field( (string) ( $raw['plural'] ?? '' ) ),
			'description'       => sanitize_text_field( (string) ( $raw['description'] ?? '' ) ),
			'post_types'        => array_values( array_unique( $post_types ) ),
			'hierarchical'      => ! empty( $raw['hierarchical'] ),
			'public'            => ! empty( $raw['public'] ),
			'show_ui'           => ! empty( $raw['show_ui'] ),
			'show_in_menu'      => ! empty( $raw['show_in_menu'] ),
			'show_in_rest'      => ! empty( $raw['show_in_rest'] ),
			'show_admin_column' => ! empty( $raw['show_admin_column'] ),
			'show_tagcloud'     => ! empty( $raw['show_tagcloud'] ),
			'rewrite'           => ! empty( $raw['rewrite'] ),
			'rewrite_slug'      => sanitize_title( (string) ( $raw['rewrite_slug'] ?? '' ) ),
			'with_front'        => ! empty( $raw['with_front'] ),
			'sort'              => ! empty( $raw['sort'] ),
		);
	}

	/**
	 * Why a name cannot be used, or an empty string.
	 *
	 * @param string $name    Taxonomy name.
	 * @param int    $post_id The record being saved, so it does not clash with itself.
	 */
	public function rejection( string $name, int $post_id = 0 ): string {
		if ( '' === $name ) {
			return __( 'A taxonomy needs a name.', 'metafields-custom-fields' );
		}

		if ( in_array( $name, self::RESERVED, true ) ) {
			return sprintf(
				/* translators: %s: the reserved taxonomy name. */
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
					/* translators: %s: the taxonomy name. */
					__( 'Another record already registers "%s".', 'metafields-custom-fields' ),
					$name
				);
			}
		}

		if ( taxonomy_exists( $name ) && ! $this->owns( $name ) ) {
			return sprintf(
				/* translators: %s: the taxonomy name. */
				__( '"%s" is already registered by something else on this site.', 'metafields-custom-fields' ),
				$name
			);
		}

		return '';
	}

	/**
	 * Whether a registered taxonomy came from one of these records.
	 *
	 * @param string $name Taxonomy name.
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
	 * Every taxonomy a record defines, keyed by name.
	 *
	 * Read by the post type editor, which has to offer a taxonomy a customer
	 * has defined even before it is registered — a record saved as a draft,
	 * or one saved a moment ago on another screen.
	 *
	 * @param bool $include_inactive Whether to include drafts.
	 *
	 * @return array<string, string> Names mapped to their label.
	 */
	public function defined( bool $include_inactive = true ): array {
		$defined = array();

		foreach ( $this->records( $include_inactive ) as $record ) {
			$name = (string) ( $record['name'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$label = (string) ( $record['plural'] ?? '' );

			if ( '' === $label ) {
				$label = (string) ( $record['title'] ?? $name );
			}

			$defined[ $name ] = $label;
		}

		return $defined;
	}

	/**
	 * Register every taxonomy a record describes.
	 *
	 * @param array<int, array<string, mixed>> $records Stored configurations.
	 */
	public function apply( array $records ): void {
		foreach ( $records as $post_id => $record ) {
			$name = (string) ( $record['name'] ?? '' );

			if ( '' === $name || taxonomy_exists( $name ) ) {
				continue;
			}

			$args = $this->arguments( $record );

			/**
			 * Filters the arguments one taxonomy is registered with.
			 *
			 * @since 1.11.0
			 *
			 * @param array<string, mixed> $args    Registration arguments.
			 * @param string               $name    Taxonomy name.
			 * @param int                  $post_id Record post id.
			 */
			$args = (array) apply_filters( 'wpcmb/taxonomy/args', $args, $name, $post_id );

			/*
			 * Registered against nothing when no post type is named. A
			 * taxonomy with no object type is still a real taxonomy with real
			 * terms — it simply has nothing attached yet, which is the state
			 * a half-finished record should be in rather than an error.
			 */
			register_taxonomy( $name, (array) ( $record['post_types'] ?? array() ), $args );
		}
	}

	/**
	 * Turn a record into register_taxonomy() arguments.
	 *
	 * @param array<string, mixed> $record Stored configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function arguments( array $record ): array {
		$record = array_merge( self::defaults(), $record );

		$singular = '' !== $record['singular'] ? (string) $record['singular'] : (string) ( $record['title'] ?? '' );
		$plural   = '' !== $record['plural'] ? (string) $record['plural'] : $singular;

		$rewrite = (bool) $record['rewrite']
			? array(
				'slug'         => '' !== $record['rewrite_slug'] ? (string) $record['rewrite_slug'] : (string) $record['name'],
				'with_front'   => (bool) $record['with_front'],
				'hierarchical' => (bool) $record['hierarchical'],
			)
			: false;

		return array(
			'labels'            => $this->labels( $singular, $plural, (bool) $record['hierarchical'] ),
			'description'       => (string) $record['description'],
			'hierarchical'      => (bool) $record['hierarchical'],
			'public'            => (bool) $record['public'],
			'show_ui'           => (bool) $record['show_ui'],
			'show_in_menu'      => (bool) $record['show_in_menu'],
			'show_in_rest'      => (bool) $record['show_in_rest'],
			'show_admin_column' => (bool) $record['show_admin_column'],
			'show_tagcloud'     => (bool) $record['show_tagcloud'],
			'rewrite'           => $rewrite,
			'sort'              => (bool) $record['sort'],
		);
	}

	/**
	 * The full label set, built from a singular and a plural name.
	 *
	 * Hierarchical and flat taxonomies need different words: categories have
	 * parents and "most used" means nothing for them, tags have neither.
	 *
	 * @param string $singular     Singular name.
	 * @param string $plural       Plural name.
	 * @param bool   $hierarchical Whether terms can have parents.
	 *
	 * @return array<string, string>
	 */
	private function labels( string $singular, string $plural, bool $hierarchical ): array {
		$labels = array(
			'name'          => $plural,
			'singular_name' => $singular,
			'menu_name'     => $plural,
			'all_items'     => $plural,
			/* translators: %s: singular taxonomy name. */
			'edit_item'     => sprintf( __( 'Edit %s', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: singular taxonomy name. */
			'view_item'     => sprintf( __( 'View %s', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: singular taxonomy name. */
			'update_item'   => sprintf( __( 'Update %s', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: singular taxonomy name. */
			'add_new_item'  => sprintf( __( 'Add %s', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: singular taxonomy name. */
			'new_item_name' => sprintf( __( 'New %s name', 'metafields-custom-fields' ), $singular ),
			/* translators: %s: plural taxonomy name. */
			'search_items'  => sprintf( __( 'Search %s', 'metafields-custom-fields' ), $plural ),
			/* translators: %s: lowercase plural taxonomy name. */
			'not_found'     => sprintf( __( 'No %s found.', 'metafields-custom-fields' ), strtolower( $plural ) ),
			/* translators: %s: lowercase plural taxonomy name. */
			'back_to_items' => sprintf( __( 'Back to %s', 'metafields-custom-fields' ), strtolower( $plural ) ),
		);

		if ( $hierarchical ) {
			/* translators: %s: singular taxonomy name. */
			$labels['parent_item'] = sprintf( __( 'Parent %s', 'metafields-custom-fields' ), $singular );
			/* translators: %s: singular taxonomy name. */
			$labels['parent_item_colon'] = sprintf( __( 'Parent %s:', 'metafields-custom-fields' ), $singular );

			return $labels;
		}

		/* translators: %s: lowercase plural taxonomy name. */
		$labels['popular_items'] = sprintf( __( 'Popular %s', 'metafields-custom-fields' ), strtolower( $plural ) );
		/* translators: %s: lowercase plural taxonomy name. */
		$labels['separate_items_with_commas'] = sprintf( __( 'Separate %s with commas', 'metafields-custom-fields' ), strtolower( $plural ) );
		/* translators: %s: lowercase plural taxonomy name. */
		$labels['add_or_remove_items'] = sprintf( __( 'Add or remove %s', 'metafields-custom-fields' ), strtolower( $plural ) );
		/* translators: %s: lowercase plural taxonomy name. */
		$labels['choose_from_most_used'] = sprintf( __( 'Choose from the most used %s', 'metafields-custom-fields' ), strtolower( $plural ) );

		return $labels;
	}
}
