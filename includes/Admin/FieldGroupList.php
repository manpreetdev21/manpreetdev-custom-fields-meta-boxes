<?php
/**
 * Field group list screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Database\LocalJson;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Locations;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Customises the field group list table.
 *
 * Built on the post type's own list table rather than a `WP_List_Table`
 * subclass: search, pagination, sorting, bulk actions, trash and screen
 * options already work there, so only the columns and the duplicate action
 * are new.
 */
final class FieldGroupList extends Module {

	/**
	 * Query arg carrying the id to duplicate.
	 */
	private const ACTION_DUPLICATE = 'wpcmb_duplicate';

	/**
	 * Groups whose file is ahead of the database, once resolved.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $syncable = null;

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
		$type = FieldGroupPostType::POST_TYPE;

		add_filter( "manage_{$type}_posts_columns", array( $this, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", array( $this, 'sortable_columns' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'post_class', array( $this, 'row_class' ), 10, 3 );
		add_filter( 'post_date_column_status', array( $this, 'hide_date_status' ), 10, 2 );
		add_filter( 'disable_months_dropdown', array( $this, 'no_months' ), 10, 2 );
		add_filter( 'posts_search', array( $this, 'search_config' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'default_order' ) );
		add_action( 'admin_action_' . self::ACTION_DUPLICATE, array( $this, 'handle_duplicate' ) );
		add_action( 'admin_notices', array( $this, 'duplicate_notice' ) );
		add_action( 'admin_action_' . LocalJson::ACTION_SYNC, array( $this, 'handle_sync' ) );
		add_action( 'admin_notices', array( $this, 'sync_notice' ) );

		// Any write to a field group invalidates the cached group list.
		add_action( 'save_post_' . $type, array( $this, 'flush' ) );
		add_action( 'deleted_post', array( $this, 'flush' ) );
		add_action( 'trashed_post', array( $this, 'flush' ) );
		add_action( 'untrashed_post', array( $this, 'flush' ) );
	}

	/**
	 * Replace the default columns.
	 *
	 * @param array<string, string> $columns Existing columns.
	 *
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		return array(
			'cb'             => $columns['cb'] ?? '',
			'title'          => __( 'Title', 'manpreetdev-custom-fields-meta-boxes' ),
			'wpcmb_key'      => __( 'Key', 'manpreetdev-custom-fields-meta-boxes' ),
			'wpcmb_fields'   => __( 'Fields', 'manpreetdev-custom-fields-meta-boxes' ),
			'wpcmb_location' => __( 'Location', 'manpreetdev-custom-fields-meta-boxes' ),
			'wpcmb_modified' => __( 'Modified', 'manpreetdev-custom-fields-meta-boxes' ),
		);
	}

	/**
	 * Sortable columns: the title and when it last changed.
	 *
	 * The Key header used to sort by the post's menu order, which a field
	 * group never sets — its Order lives in its settings — so it sorted by
	 * nothing and both directions gave the same list. A group key is random
	 * by design, so there is no order worth offering in its place.
	 *
	 * @param array<string, string> $columns Sortable columns.
	 *
	 * @return array<string, string>
	 */
	public function sortable_columns( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();

		unset( $columns['date'] );

		$columns['wpcmb_modified'] = array( 'modified', true );

		return $columns;
	}

	/**
	 * List the most recently changed groups first, unless asked otherwise.
	 *
	 * @param \WP_Query $query The list query.
	 */
	public function default_order( $query ): void {
		if ( ! $this->is_list_query( $query ) || '' !== (string) $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'orderby', 'modified' );
		$query->set( 'order', 'DESC' );
	}

	/**
	 * Find groups by key and by field name, not only by title.
	 *
	 * The list shows each group's key, and a field name is what a template
	 * uses — "which group is `hero_title` in?" is the question people bring
	 * to this box. Both are in the stored configuration, which is searched
	 * alongside the title.
	 *
	 * @param string    $search SQL WordPress built for the title search.
	 * @param \WP_Query $query  The list query.
	 */
	public function search_config( $search, $query ): string {
		$search = (string) $search;
		$term   = $query instanceof \WP_Query ? trim( (string) $query->get( 's' ) ) : '';

		if ( '' === $search || '' === $term || ! $this->is_list_query( $query ) ) {
			return $search;
		}

		global $wpdb;

		$config = $wpdb->prepare(
			"{$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s )",
			FieldGroupPostType::META_CONFIG,
			'%' . $wpdb->esc_like( $term ) . '%'
		);

		// WordPress's clause opens with " AND (" around the title terms; the
		// configuration match joins it as one more alternative.
		return (string) preg_replace( '/^\s*AND\s*\(/', ' AND (' . $config . ' OR ', $search, 1 );
	}


	/**
	 * No month filter on this list.
	 *
	 * It filters by the month a group was created, beside a column showing
	 * when each was last changed, which reads as a filter that gets it wrong.
	 *
	 * @param bool   $disabled  Whether it is disabled already.
	 * @param string $post_type Post type of the list.
	 */
	public function no_months( $disabled, $post_type = '' ): bool {
		return FieldGroupPostType::POST_TYPE === $post_type ? true : (bool) $disabled;
	}

	/**
	 * Whether a query is this screen's own list.
	 *
	 * @param mixed $query Query.
	 */
	private function is_list_query( $query ): bool {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen instanceof \WP_Screen && 'edit-' . FieldGroupPostType::POST_TYPE === $screen->id;
	}

	/**
	 * Render a custom column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post id.
	 */
	public function render_column( $column, $post_id ): void {
		$group = $this->container->get( Repository::class )->get( (int) $post_id );

		if ( ! $group instanceof FieldGroup ) {
			return;
		}

		switch ( $column ) {
			case 'wpcmb_key':
				printf( '<code class="wpcmb-cell-key">%s</code>', esc_html( $group->key ) );
				break;

			case 'wpcmb_fields':
				$count = count( $group->fields );

				// A zero is worth saying out loud rather than printing as "0":
				// a group with no fields does nothing, and that is the single
				// most useful thing this column can tell you at a glance.
				printf(
					'<span class="wpcmb-cell-count%s">%s</span>',
					0 === $count ? ' is-empty' : '',
					esc_html( 0 === $count ? __( 'None', 'manpreetdev-custom-fields-meta-boxes' ) : (string) $count )
				);
				break;

			case 'wpcmb_modified':
				/*
				 * From the local time, which every post has. WordPress leaves the
				 * GMT one empty on a draft, so reading that put an inactive group
				 * — any new one, any copy — at 1 January 1970.
				 */
				$modified = get_post_datetime( (int) $post_id, 'modified' );

				if ( ! $modified instanceof \DateTimeImmutable ) {
					break;
				}

				// The same wording WordPress uses for the date column it replaces.
				printf(
					'<time datetime="%s">%s</time>',
					esc_attr( $modified->format( 'c' ) ),
					esc_html(
						sprintf(
							/* translators: 1: date, 2: time. */
							__( '%1$s at %2$s', 'manpreetdev-custom-fields-meta-boxes' ),
							wp_date( __( 'Y/m/d', 'manpreetdev-custom-fields-meta-boxes' ), $modified->getTimestamp() ),
							wp_date( __( 'g:i a', 'manpreetdev-custom-fields-meta-boxes' ), $modified->getTimestamp() )
						)
					)
				);
				break;

			case 'wpcmb_location':
				$summary = Locations::describe( $group->location );

				printf(
					'<span class="wpcmb-cell-location%s">%s</span>',
					'' === $summary ? ' is-unset' : '',
					esc_html( '' !== $summary ? $summary : __( 'Not set', 'manpreetdev-custom-fields-meta-boxes' ) )
				);
				break;
		}
	}

	/**
	 * Tag our own rows so the stylesheet has something of ours to hold onto.
	 *
	 * The list table is core markup end to end — `.wp-list-table`, `.tablenav`,
	 * `.row-actions` — and painting any of that would change how a list table
	 * looks on this screen versus every other one in WordPress. A class of our
	 * own on the row is the supported way in, and it exists nowhere else.
	 *
	 * `post_class` runs on the front end too, so this checks it is our own
	 * admin list screen before adding anything.
	 *
	 * @param array<int, string> $classes Existing classes.
	 * @param array<int, string> $css     Extra classes passed to the filter.
	 * @param int                $post_id Post id.
	 *
	 * @return array<int, string>
	 */
	public function row_class( $classes, $css = array(), $post_id = 0 ): array {
		$classes = is_array( $classes ) ? $classes : array();

		if ( ! is_admin() || FieldGroupPostType::POST_TYPE !== get_post_type( (int) $post_id ) ) {
			return $classes;
		}

		// Not loaded during every admin request — an AJAX handler can reach
		// post_class without wp-admin's screen API being available.
		if ( ! function_exists( 'get_current_screen' ) ) {
			return $classes;
		}

		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || 'edit' !== $screen->base ) {
			return $classes;
		}

		$classes[] = 'wpcmb-row';

		return $classes;
	}

	/**
	 * Add a Duplicate row action.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Current post.
	 *
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $post ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( ! $post instanceof \WP_Post || FieldGroupPostType::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		// A trashed group is restored or deleted, not copied.
		if ( ! current_user_can( FieldGroupPostType::capability() ) || 'trash' === $post->post_status ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::ACTION_DUPLICATE . '&post=' . $post->ID ),
			self::ACTION_DUPLICATE . '_' . $post->ID
		);

		$actions['wpcmb_duplicate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Duplicate', 'manpreetdev-custom-fields-meta-boxes' )
		);

		$group = $this->container->get( Repository::class )->get( $post->ID );

		if ( $group instanceof FieldGroup && isset( $this->syncable()[ $group->key ] ) ) {
			$actions['wpcmb_sync'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->sync_url( array( $group->key ) ) ),
				esc_html__( 'Sync from file', 'manpreetdev-custom-fields-meta-boxes' )
			);
		}

		return $actions;
	}

	/**
	 * The groups whose file is ahead of the database, asked once per request.
	 *
	 * @return array<string, string>
	 */
	private function syncable(): array {
		if ( null === $this->syncable ) {
			$this->syncable = $this->container->get( LocalJson::class )->syncable();
		}

		return $this->syncable;
	}

	/**
	 * A link that syncs the given groups.
	 *
	 * @param array<int, string> $keys Group keys.
	 */
	private function sync_url( array $keys ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => LocalJson::ACTION_SYNC,
					'keys'   => implode( ',', $keys ),
				),
				admin_url( 'admin.php' )
			),
			LocalJson::ACTION_SYNC
		);
	}

	/**
	 * Offer to import the groups whose files are ahead.
	 *
	 * Shown rather than done: a file arriving from version control is somebody
	 * else's change to this site's configuration, and overwriting what is here
	 * without being asked is how an afternoon's work disappears.
	 */
	public function sync_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || 'edit-' . FieldGroupPostType::POST_TYPE !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.
		$synced = isset( $_GET['wpcmb_synced'] ) ? absint( wp_unslash( $_GET['wpcmb_synced'] ) ) : 0;

		if ( $synced > 0 ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of field groups. */
						_n( '%d field group imported from its file.', '%d field groups imported from their files.', $synced, 'manpreetdev-custom-fields-meta-boxes' ),
						$synced
					)
				)
			);
		}

		$available = $this->syncable();

		if ( array() === $available ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of field groups. */
					_n( '%d field group has a newer version in its JSON file.', '%d field groups have a newer version in their JSON files.', count( $available ), 'manpreetdev-custom-fields-meta-boxes' ),
					count( $available )
				)
			),
			esc_html( implode( ', ', $available ) ),
			esc_url( $this->sync_url( array_keys( $available ) ) ),
			esc_html__( 'Sync all', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}

	/**
	 * Handle a sync request.
	 */
	public function handle_sync(): void {
		check_admin_referer( LocalJson::ACTION_SYNC );

		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to import field groups.', 'manpreetdev-custom-fields-meta-boxes' ) );
		}

		$requested = isset( $_GET['keys'] ) ? sanitize_text_field( wp_unslash( $_GET['keys'] ) ) : '';
		$requested = array_filter( array_map( 'sanitize_key', explode( ',', $requested ) ) );

		/*
		 * Only what is actually behind its file is imported, whatever the link
		 * asked for. The keys travel in the URL, so without this a crafted
		 * link could overwrite a group whose file is older than it.
		 */
		$synced = $this->container->get( LocalJson::class )->sync(
			array_values( array_intersect( $requested, array_keys( $this->syncable() ) ) )
		);

		$this->flush();

		wp_safe_redirect(
			add_query_arg(
				'wpcmb_synced',
				$synced,
				admin_url( 'edit.php?post_type=' . FieldGroupPostType::POST_TYPE )
			)
		);
		exit;
	}


	/**
	 * Drop the "Last Modified" prefix from the date column.
	 *
	 * @param string   $status Column status text.
	 * @param \WP_Post $post   Current post.
	 */
	public function hide_date_status( $status, $post ): string {
		if ( $post instanceof \WP_Post && FieldGroupPostType::POST_TYPE === $post->post_type ) {
			return '';
		}

		return (string) $status;
	}

	/**
	 * Handle the Duplicate row action.
	 */
	public function handle_duplicate(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		check_admin_referer( self::ACTION_DUPLICATE . '_' . $post_id );

		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to duplicate field groups.', 'manpreetdev-custom-fields-meta-boxes' ) );
		}

		$new_id = $this->container->get( Repository::class )->duplicate( $post_id );

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		wp_safe_redirect( add_query_arg( 'wpcmb_duplicated', '1', get_edit_post_link( $new_id, 'raw' ) ) );
		exit;
	}

	/**
	 * Confirm a successful duplication.
	 */
	public function duplicate_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.
		if ( empty( $_GET['wpcmb_duplicated'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Field group duplicated. It is inactive until you publish it.', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}

	/**
	 * Invalidate cached field groups.
	 */
	public function flush(): void {
		$this->container->get( Repository::class )->flush();
	}
}
