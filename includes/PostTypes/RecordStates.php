<?php
/**
 * Active and inactive, for everything the plugin stores as a post.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Gives one of the plugin's own post types exactly two states.
 *
 * Field groups, post types, taxonomies, block types, forms and options pages
 * are each stored as a post so WordPress supplies the list screen, the trash
 * and the rest. But a post has half a dozen statuses and these have two:
 * published is active, a draft is inactive. Everything here follows from
 * that, once, for all six of them.
 */
final class RecordStates {

	/**
	 * Statuses that are neither active nor trashed, and so are inactive.
	 */
	public const INACTIVE = array( 'draft', 'pending', 'private', 'future' );

	/**
	 * Constructor.
	 *
	 * @param string $post_type Post type these states apply to.
	 * @param string $singular  Singular name, e.g. "Post type".
	 * @param string $plural    Plural name, e.g. "Post types".
	 */
	public function __construct(
		private readonly string $post_type,
		private readonly string $singular,
		private readonly string $plural
	) {
	}

	/**
	 * Register the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'two_states' ), 10, 2 );
		add_filter( 'wp_untrash_post_status', array( $this, 'restore_status' ), 10, 3 );
		add_filter( 'post_updated_messages', array( $this, 'updated_messages' ) );
		add_filter( 'bulk_post_updated_messages', array( $this, 'bulk_messages' ), 10, 2 );
		add_filter( 'views_edit-' . $this->post_type, array( $this, 'views' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
	}

	/**
	 * Keep a record active or inactive, and nothing else.
	 *
	 * Bulk Edit and Quick Edit offer every status a post can have. Private,
	 * pending and scheduled mean nothing here, and each switched the record
	 * off without saying so — out of the Inactive view, out of the lists the
	 * plugin reads, out of exports, and out of the checks that stop two
	 * records claiming one name. They are saved as inactive, which is what
	 * they did anyway, and a password is meaningless too.
	 *
	 * @param array<string, mixed> $data    Post data about to be written.
	 * @param array<string, mixed> $postarr Raw post array.
	 *
	 * @return array<string, mixed>
	 */
	public function two_states( $data, $postarr = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The filter's signature.
		$data = is_array( $data ) ? $data : array();

		if ( ( $data['post_type'] ?? '' ) !== $this->post_type ) {
			return $data;
		}

		if ( in_array( $data['post_status'] ?? '', array( 'private', 'pending', 'future' ), true ) ) {
			$data['post_status'] = 'draft';
		}

		$data['post_password'] = '';

		return $data;
	}

	/**
	 * Restore a record from the trash as it was.
	 *
	 * WordPress restores every post as a draft, so that nothing restored goes
	 * public by accident. Here a draft is inactive: a post type taken out of
	 * the trash came back unregistered, and its whole menu and every post in
	 * it disappeared from the admin until somebody noticed and published it.
	 *
	 * @param string $status          Status WordPress would restore to.
	 * @param int    $post_id         Post id.
	 * @param string $previous_status Status before it was trashed.
	 */
	public function restore_status( $status, $post_id = 0, $previous_status = '' ): string {
		if ( get_post_type( (int) $post_id ) !== $this->post_type ) {
			return (string) $status;
		}

		return 'publish' === $previous_status ? 'publish' : 'draft';
	}

	/**
	 * Say what was saved, and whether it is now in use.
	 *
	 * @param array<string, array<int, string>> $messages Messages by post type.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function updated_messages( $messages ): array {
		$messages = is_array( $messages ) ? $messages : array();

		/* translators: %s: what was saved, e.g. "Post type". */
		$saved = sprintf( __( '%s updated.', 'manpreetdev-custom-fields-meta-boxes' ), $this->singular );
		/* translators: %s: what was published, e.g. "Post type". */
		$published = sprintf( __( '%s published. It is now active.', 'manpreetdev-custom-fields-meta-boxes' ), $this->singular );
		/* translators: %s: what was saved, e.g. "Post type". */
		$draft = sprintf( __( '%s saved as a draft. It is inactive until published.', 'manpreetdev-custom-fields-meta-boxes' ), $this->singular );

		$messages[ $this->post_type ] = array(
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
	 * Name the record in the notices after trashing, restoring and the rest.
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
		$singular = $this->singular;
		$plural   = $this->plural;
		$noun     = static fn( int $count ): string => strtolower( 1 === $count ? $singular : $plural );

		$messages[ $this->post_type ] = array(
			/* translators: 1: number, 2: lowercase name, singular or plural to match. */
			'updated'   => sprintf( _n( '%1$s %2$s updated.', '%1$s %2$s updated.', $updated, 'manpreetdev-custom-fields-meta-boxes' ), '%s', $noun( $updated ) ),
			/* translators: 1: number, 2: lowercase name, singular or plural to match. */
			'locked'    => sprintf( _n( '%1$s %2$s not updated, somebody is editing it.', '%1$s %2$s not updated, somebody is editing them.', $locked, 'manpreetdev-custom-fields-meta-boxes' ), '%s', $noun( $locked ) ),
			/* translators: 1: number, 2: lowercase name, singular or plural to match. */
			'deleted'   => sprintf( _n( '%1$s %2$s permanently deleted.', '%1$s %2$s permanently deleted.', $deleted, 'manpreetdev-custom-fields-meta-boxes' ), '%s', $noun( $deleted ) ),
			/* translators: 1: number, 2: lowercase name, singular or plural to match. */
			'trashed'   => sprintf( _n( '%1$s %2$s moved to the Trash.', '%1$s %2$s moved to the Trash.', $trashed, 'manpreetdev-custom-fields-meta-boxes' ), '%s', $noun( $trashed ) ),
			/* translators: 1: number, 2: lowercase name, singular or plural to match. */
			'untrashed' => sprintf( _n( '%1$s %2$s restored from the Trash.', '%1$s %2$s restored from the Trash.', $restored, 'manpreetdev-custom-fields-meta-boxes' ), '%s', $noun( $restored ) ),
		);

		return $messages;
	}

	/**
	 * Name the two views for what they mean here.
	 *
	 * @param array<string, string> $views View links.
	 *
	 * @return array<string, string>
	 */
	public function views( $views ): array {
		$views  = is_array( $views ) ? $views : array();
		$labels = array(
			'publish' => __( 'Active', 'manpreetdev-custom-fields-meta-boxes' ),
			'draft'   => __( 'Inactive', 'manpreetdev-custom-fields-meta-boxes' ),
		);

		foreach ( $labels as $status => $label ) {
			if ( isset( $views[ $status ] ) ) {
				$views[ $status ] = (string) preg_replace( '/>[^<>]+(<span class="count">)/', '>' . esc_html( $label ) . ' $1', $views[ $status ], 1 );
			}
		}

		return $views;
	}

	/**
	 * No Quick Edit.
	 *
	 * It offers a date, a password and a private status, none of which these
	 * have. Bulk Edit stays, for switching several on or off at once.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Current post.
	 *
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $post ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( $post instanceof \WP_Post && $this->post_type === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'], $actions['inline hide-if-js'] );
		}

		return $actions;
	}

	/**
	 * Call an inactive record inactive, whatever status left it so.
	 *
	 * @param array<string, string> $states Post states.
	 * @param \WP_Post              $post   Current post.
	 *
	 * @return array<string, string>
	 */
	public function post_states( $states, $post ): array {
		$states = is_array( $states ) ? $states : array();

		if ( $post instanceof \WP_Post && $this->post_type === $post->post_type && in_array( $post->post_status, self::INACTIVE, true ) ) {
			unset( $states['draft'], $states['pending'], $states['private'], $states['scheduled'], $states['protected'] );

			$states['wpcmb_inactive'] = __( 'Inactive', 'manpreetdev-custom-fields-meta-boxes' );
		}

		return $states;
	}
}
