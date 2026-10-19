<?php
/**
 * Shared storage for the things a site builds with this plugin.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Abstracts;

defined( 'ABSPATH' ) || exit;

/**
 * Base for a kind of record the plugin manages on a screen of its own.
 *
 * Post types, taxonomies, block types, forms and options pages are five
 * different things with one shape: a title, a JSON configuration blob, a list
 * screen, an editor, and registration on `init`. Field groups already worked
 * this way — this is that pattern named and shared, rather than copied five
 * times and then fixed in four of them.
 *
 * Each record is a post, for the same reasons field groups are: WordPress
 * supplies the list table, search, pagination, trash, capabilities, revisions
 * and export, and a bespoke table would reimplement all of it for a handful
 * of rows read once per screen.
 */
abstract class RecordType extends Module {

	/**
	 * Meta key holding the JSON-encoded configuration.
	 *
	 * The same key as a field group's, on a different post type, so one
	 * reader can be pointed at any of them.
	 */
	public const META_CONFIG = '_wpcmb_config';

	/**
	 * The post type that stores these records.
	 */
	abstract public function post_type(): string;

	/**
	 * Singular and plural names, for the labels and the headings.
	 *
	 * @return array{singular: string, plural: string}
	 */
	abstract public function names(): array;

	/**
	 * Clean a submitted configuration.
	 *
	 * The trust boundary for this record type: everything stored passes
	 * through here, from the editor, from an import and from code.
	 *
	 * @param array<string, mixed> $raw Untrusted configuration.
	 *
	 * @return array<string, mixed>
	 */
	abstract public function sanitize( array $raw ): array;

	/**
	 * What makes one record distinguishable from another.
	 *
	 * Used by an import to decide whether it is updating a record or adding
	 * one. Field groups have a key for this; records do not, so each type
	 * names the thing that is already unique about it — a post type's key, an
	 * options page's slug — and an import of the same export twice updates
	 * rather than duplicating.
	 *
	 * @param array<string, mixed> $record Stored configuration.
	 */
	public function identity( array $record ): string {
		return (string) ( $record['name'] ?? '' );
	}

	/**
	 * The record whose identity matches, with its post id, or null.
	 *
	 * @param string $identity Identity to look for.
	 *
	 * @return array{id: int, config: array<string, mixed>}|null
	 */
	public function find( string $identity ): ?array {
		if ( '' === $identity ) {
			return null;
		}

		foreach ( $this->records( true ) as $post_id => $record ) {
			if ( $this->identity( $record ) === $identity ) {
				return array(
					'id'     => (int) $post_id,
					'config' => $record,
				);
			}
		}

		return null;
	}

	/**
	 * Act on the records, once, on `init`.
	 *
	 * A post type record registers a post type; a block record registers a
	 * block. A record type with nothing to register leaves this alone.
	 *
	 * @param array<int, array<string, mixed>> $records Stored configurations, keyed by post id.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Part of the contract subclasses implement.
	 */
	public function apply( array $records ): void {
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Register hooks.
	 *
	 * Priority 5 for the storage so the records exist before anything on the
	 * default priority looks for them, then 6 to act on them — a taxonomy
	 * naming a post type has to be registered after that post type is.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register' ), 5 );
		add_action( 'init', array( $this, 'apply_records' ), 6 );
	}

	/**
	 * The capability required to manage these records.
	 *
	 * Deliberately the same one that governs field groups: this is all the
	 * same job, and a second capability would be a second thing to grant.
	 */
	public static function capability(): string {
		return \WPCMB\PostTypes\FieldGroupPostType::capability();
	}

	/**
	 * Register the storage post type.
	 */
	public function register(): void {
		$names = $this->names();

		register_post_type(
			$this->post_type(),
			array(
				'labels'           => array(
					'name'               => $names['plural'],
					'singular_name'      => $names['singular'],
					/* translators: %s: singular record name, e.g. "Post Type". */
					'add_new_item'       => sprintf( __( 'Add %s', 'manpreetdev-custom-fields-meta-boxes' ), $names['singular'] ),
					/* translators: %s: singular record name. */
					'edit_item'          => sprintf( __( 'Edit %s', 'manpreetdev-custom-fields-meta-boxes' ), $names['singular'] ),
					/* translators: %s: singular record name. */
					'new_item'           => sprintf( __( 'New %s', 'manpreetdev-custom-fields-meta-boxes' ), $names['singular'] ),
					/* translators: %s: plural record name. */
					'search_items'       => sprintf( __( 'Search %s', 'manpreetdev-custom-fields-meta-boxes' ), $names['plural'] ),
					/* translators: %s: lowercase plural record name. */
					'not_found'          => sprintf( __( 'No %s yet.', 'manpreetdev-custom-fields-meta-boxes' ), strtolower( $names['plural'] ) ),
					/* translators: %s: lowercase plural record name. */
					'not_found_in_trash' => sprintf( __( 'No %s in Trash.', 'manpreetdev-custom-fields-meta-boxes' ), strtolower( $names['plural'] ) ),
					'menu_name'          => $names['plural'],
				),
				'public'           => false,
				'show_ui'          => true,
				'show_in_menu'     => \WPCMB\Admin\Menu::SLUG,
				'show_in_rest'     => false,
				'hierarchical'     => false,
				'supports'         => array( 'title' ),
				'capabilities'     => \WPCMB\PostTypes\FieldGroupPostType::capabilities(),
				'map_meta_cap'     => false,
				'rewrite'          => false,
				'query_var'        => false,
				'can_export'       => true,
				'delete_with_user' => false,
			)
		);

		register_post_meta(
			$this->post_type(),
			self::META_CONFIG,
			array(
				'type'          => 'string',
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => false,
				'auth_callback' => static fn(): bool => current_user_can( self::capability() ),
			)
		);
	}

	/**
	 * Hand the stored records to apply().
	 */
	public function apply_records(): void {
		$this->apply( $this->records() );
	}

	/**
	 * Every published record's configuration, keyed by post id.
	 *
	 * Drafts are excluded, which is how a record is switched off without
	 * being deleted — the same meaning "draft" has for a field group.
	 *
	 * @param bool $include_inactive Whether to include drafts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function records( bool $include_inactive = false ): array {
		$posts = get_posts(
			array(
				'post_type'              => $this->post_type(),
				'post_status'            => $include_inactive ? array( 'publish', 'draft' ) : 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'menu_order title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		$records = array();

		foreach ( $posts as $post ) {
			$config = $this->config( $post );

			if ( array() === $config ) {
				continue;
			}

			$config['title']  = $post->post_title;
			$config['active'] = 'publish' === $post->post_status;

			$records[ (int) $post->ID ] = $config;
		}

		/**
		 * Filters the records of one type before they are used.
		 *
		 * Adding an entry here registers something no record exists for,
		 * which is how a site keeps this configuration in version control.
		 *
		 * @since 1.10.0
		 *
		 * @param array<int, array<string, mixed>> $records Configurations, keyed by post id.
		 */
		return (array) apply_filters( 'wpcmb/records/' . $this->post_type(), $records );
	}

	/**
	 * One record's stored configuration.
	 *
	 * @param \WP_Post|int $post Record post or id.
	 *
	 * @return array<string, mixed>
	 */
	public function config( $post ): array {
		$id = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;

		$raw = get_post_meta( $id, self::META_CONFIG, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$config = json_decode( $raw, true );

		return is_array( $config ) ? $config : array();
	}

	/**
	 * Store one record's configuration.
	 *
	 * @param int                  $post_id Record post id.
	 * @param array<string, mixed> $config  Already-sanitized configuration.
	 */
	public function save( int $post_id, array $config ): void {
		update_post_meta( $post_id, self::META_CONFIG, (string) wp_json_encode( $config ) );
	}
}
