<?php
/**
 * Field group storage.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\PostTypes;

use WPCMB\Abstracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type that stores field groups.
 *
 * Field groups live in `wp_posts`/`wp_postmeta` rather than custom tables.
 * WordPress already supplies the list table, search, pagination, sorting,
 * revisions, autosave, trash, capabilities, WXR import/export and the REST
 * plumbing for a post type. A bespoke schema would reimplement all of it and
 * add a migration surface for no measurable gain at field-group scale
 * (tens to low hundreds of rows, read once per screen and cached).
 */
final class FieldGroupPostType extends Module {

	/**
	 * Post type name.
	 */
	public const POST_TYPE = 'wpcmb_field_group';

	/**
	 * Meta key holding the JSON-encoded group configuration.
	 */
	public const META_CONFIG = '_wpcmb_config';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register' ), 5 );
		add_filter( 'wp_post_revision_meta_keys', array( $this, 'revision_meta_keys' ), 10, 2 );
		add_filter( 'post_updated_messages', array( $this, 'updated_messages' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'two_states' ), 10, 2 );
		add_filter( 'bulk_post_updated_messages', array( $this, 'bulk_messages' ), 10, 2 );
		add_filter( 'wp_untrash_post_status', array( $this, 'restore_status' ), 10, 3 );
	}

	/**
	 * Say "field group" in the notices after trashing, restoring and the rest.
	 *
	 * WordPress says "1 post moved to the Trash" for any type that does not
	 * name itself here.
	 *
	 * @param array<string, array<string, string>> $messages Messages by post type.
	 * @param array<string, int>                   $counts   How many of each.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function bulk_messages( $messages, $counts = array() ): array {
		$messages = is_array( $messages ) ? $messages : array();
		$counts   = is_array( $counts ) ? $counts : array();
		$updated  = (int) ( $counts['updated'] ?? 0 );
		$locked   = (int) ( $counts['locked'] ?? 0 );
		$deleted  = (int) ( $counts['deleted'] ?? 0 );
		$trashed  = (int) ( $counts['trashed'] ?? 0 );
		$restored = (int) ( $counts['untrashed'] ?? 0 );

		$messages[ self::POST_TYPE ] = array(
			/* translators: %s: number of field groups. */
			'updated'   => _n( '%s field group updated.', '%s field groups updated.', $updated, 'manpreetdev-custom-fields-meta-boxes' ),
			/* translators: %s: number of field groups. */
			'locked'    => _n( '%s field group not updated, somebody is editing it.', '%s field groups not updated, somebody is editing them.', $locked, 'manpreetdev-custom-fields-meta-boxes' ),
			/* translators: %s: number of field groups. */
			'deleted'   => _n( '%s field group permanently deleted.', '%s field groups permanently deleted.', $deleted, 'manpreetdev-custom-fields-meta-boxes' ),
			/* translators: %s: number of field groups. */
			'trashed'   => _n( '%s field group moved to the Trash.', '%s field groups moved to the Trash.', $trashed, 'manpreetdev-custom-fields-meta-boxes' ),
			/* translators: %s: number of field groups. */
			'untrashed' => _n( '%s field group restored from the Trash.', '%s field groups restored from the Trash.', $restored, 'manpreetdev-custom-fields-meta-boxes' ),
		);

		return $messages;
	}

	/**
	 * Keep a field group active or inactive, and nothing else.
	 *
	 * Bulk Edit offers every status a post can have. Private, pending and
	 * scheduled mean nothing for a field group, and each of them switched it
	 * off without saying so, out of the Inactive view and out of the lists
	 * that offer groups to export. They are saved as inactive, which is what
	 * they did anyway, and a password is meaningless here too.
	 *
	 * @param array<string, mixed> $data    Post data about to be written.
	 * @param array<string, mixed> $postarr Raw post array.
	 *
	 * @return array<string, mixed>
	 */
	public function two_states( $data, $postarr = array() ): array {
		$data = is_array( $data ) ? $data : array();

		if ( self::POST_TYPE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		if ( in_array( $data['post_status'] ?? '', array( 'private', 'pending', 'future' ), true ) ) {
			$data['post_status'] = 'draft';
		}

		$data['post_password'] = '';

		return $data;
	}

	/**
	 * Restore a group from the trash as it was.
	 *
	 * WordPress restores every post as a draft, so that nothing restored
	 * goes public by accident. For a field group, draft means inactive: an
	 * active group taken out of the trash by mistake came back switched off,
	 * and its fields quietly vanished from every screen it had been on.
	 *
	 * @param string $status          Status WordPress would restore to.
	 * @param int    $post_id         Post id.
	 * @param string $previous_status Status before it was trashed.
	 */
	public function restore_status( $status, $post_id = 0, $previous_status = '' ): string {
		if ( self::POST_TYPE !== get_post_type( (int) $post_id ) ) {
			return (string) $status;
		}

		return 'publish' === $previous_status ? 'publish' : 'draft';
	}

	/**
	 * Say what was saved.
	 *
	 * Without this the notice after saving a field group reads "Post
	 * updated.", which is core's wording for a thing this is not.
	 *
	 * @param array<string, array<int, string>> $messages Messages by post type.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function updated_messages( $messages ): array {
		$messages = is_array( $messages ) ? $messages : array();

		$saved     = __( 'Field group updated.', 'manpreetdev-custom-fields-meta-boxes' );
		$published = __( 'Field group published. It is now active.', 'manpreetdev-custom-fields-meta-boxes' );
		$draft     = __( 'Field group saved as a draft. It is inactive until published.', 'manpreetdev-custom-fields-meta-boxes' );

		$messages[ self::POST_TYPE ] = array(
			1  => $saved,
			4  => $saved,
			6  => $published,
			7  => $saved,
			8  => $published,
			10 => $draft,
		) + (array) ( $messages['post'] ?? array() );

		return $messages;
	}

	/**
	 * The capability required to administer field groups.
	 *
	 * Every field-group capability maps to this one, so no role is ever
	 * modified: nothing to grant on activation, nothing to clean up on
	 * uninstall, and no way for a stale capability to outlive the plugin.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability required to manage field groups.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'wpcmb/capability', 'manage_options' );
	}

	/**
	 * Post type capability map, every entry pointing at capability().
	 *
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		$cap  = self::capability();
		$keys = array(
			'edit_post',
			'read_post',
			'delete_post',
			'edit_posts',
			'edit_others_posts',
			'delete_posts',
			'delete_others_posts',
			'publish_posts',
			'read_private_posts',
			'create_posts',
		);

		return array_fill_keys( $keys, $cap );
	}

	/**
	 * Register the post type.
	 */
	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'           => array(
					'name'               => __( 'Field Groups', 'manpreetdev-custom-fields-meta-boxes' ),
					'singular_name'      => __( 'Field Group', 'manpreetdev-custom-fields-meta-boxes' ),
					'add_new_item'       => __( 'Add Field Group', 'manpreetdev-custom-fields-meta-boxes' ),
					'edit_item'          => __( 'Edit Field Group', 'manpreetdev-custom-fields-meta-boxes' ),
					'new_item'           => __( 'New Field Group', 'manpreetdev-custom-fields-meta-boxes' ),
					'search_items'       => __( 'Search Field Groups', 'manpreetdev-custom-fields-meta-boxes' ),
					'not_found'          => __( 'No field groups found.', 'manpreetdev-custom-fields-meta-boxes' ),
					'not_found_in_trash' => __( 'No field groups in Trash.', 'manpreetdev-custom-fields-meta-boxes' ),
				),
				'public'           => false,
				'show_ui'          => true,
				'show_in_menu'     => 'wpcmb',
				'show_in_rest'     => false,
				'hierarchical'     => false,
				'supports'         => array( 'title', 'revisions' ),
				'capabilities'     => self::capabilities(),
				'map_meta_cap'     => false,
				'rewrite'          => false,
				'query_var'        => false,
				'can_export'       => true,
				'delete_with_user' => false,
			)
		);

		register_post_meta(
			self::POST_TYPE,
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
	 * Include the group configuration in post revisions.
	 *
	 * WordPress 6.4+ revisions the meta keys returned here, which is why the
	 * whole group config is a single meta value.
	 *
	 * @param array<int, string> $keys      Revisioned meta keys.
	 * @param string             $post_type Post type being revisioned.
	 *
	 * @return array<int, string>
	 */
	public function revision_meta_keys( $keys, $post_type = '' ): array {
		$keys = is_array( $keys ) ? $keys : array();

		if ( self::POST_TYPE !== $post_type ) {
			return $keys;
		}

		$keys[] = self::META_CONFIG;

		return $keys;
	}
}
