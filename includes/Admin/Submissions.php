<?php
/**
 * Reading stored form submissions.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Values;
use WPCMB\PostTypes\SubmissionPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Shows what a submission contains.
 *
 * Storing submissions is only half of it: the values land in post meta, and
 * post meta is invisible. The fields cannot be rendered the usual way either,
 * because the group's location rules point at wherever its form is, not at
 * this screen — and even if they matched, editable controls would offer a
 * save that no location rule would honour.
 *
 * So this reads instead of edits: the recorded group says which fields the
 * submission has, and each is shown formatted, in the order the form asked
 * for them.
 */
final class Submissions extends Module {

	/**
	 * Query arg carrying an export request.
	 */
	private const ACTION_EXPORT = 'wpcmb_export_submissions';

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
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'admin_action_' . self::ACTION_EXPORT, array( $this, 'handle_export' ) );
		add_action( 'manage_posts_extra_tablenav', array( $this, 'export_button' ) );

		foreach ( array_keys( SubmissionPostType::recorded() ) as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'column' ), 10, 2 );
		}
	}

	/**
	 * Offer the export above the list.
	 *
	 * @param string $which Which end of the table is being drawn.
	 */
	public function export_button( $which ): void {
		$screen = get_current_screen();

		if ( 'top' !== $which || ! $screen instanceof \WP_Screen || ! SubmissionPostType::is_submission_type( $screen->post_type ) ) {
			return;
		}

		if ( ! current_user_can( $this->export_capability( $screen->post_type ) ) ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => self::ACTION_EXPORT,
					'post_type' => $screen->post_type,
				),
				admin_url( 'admin.php' )
			),
			self::ACTION_EXPORT
		);

		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Export all to CSV', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}

	/**
	 * Stream every submission of one type as CSV.
	 *
	 * Everything, not the current filter. A half-honoured filter is worse than
	 * none: a file that silently holds a subset is one somebody reports
	 * numbers from, and the button says which it is.
	 */
	public function handle_export(): void {
		check_admin_referer( self::ACTION_EXPORT );

		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';

		if ( ! SubmissionPostType::is_submission_type( $post_type ) ) {
			wp_die( esc_html__( 'That is not a submission type.', 'manpreetdev-custom-fields-meta-boxes' ) );
		}

		if ( ! current_user_can( $this->export_capability( $post_type ) ) ) {
			wp_die( esc_html__( 'You do not have permission to export these submissions.', 'manpreetdev-custom-fields-meta-boxes' ) );
		}

		$group = $this->container->get( Repository::class )->get( (string) ( SubmissionPostType::recorded()[ $post_type ] ?? '' ) );
		$csv   = $this->csv( $post_type, $group instanceof FieldGroup ? $group : null );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $post_type . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		/*
		 * A byte order mark, because the main thing anyone does with this file
		 * is open it in Excel, which otherwise reads UTF-8 as the local
		 * codepage and mangles every name with an accent in it.
		 */
		echo "\xEF\xBB\xBF";
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV download, escaped by Csv::line().
		exit;
	}

	/**
	 * The submissions of one type, as CSV.
	 *
	 * @param string          $post_type Submission post type.
	 * @param FieldGroup|null $group     The group they came from, if it still exists.
	 */
	private function csv( string $post_type, ?FieldGroup $group ): string {
		$renderer = $this->container->get( Renderer::class );
		$values   = $this->container->get( Values::class );

		/*
		 * Columns come from the group, not from the submissions, so every row
		 * has the same cells in the same order even when a field was added
		 * after some of them arrived — and a column with nothing in it is the
		 * honest answer for a question nobody was asked.
		 */
		$fields = array();

		foreach ( null !== $group ? $group->fields : array() as $field ) {
			$name = (string) ( $field['name'] ?? '' );

			if ( '' !== $name && $renderer->stores_value( $field ) ) {
				$fields[ $name ] = '' !== (string) ( $field['label'] ?? '' ) ? (string) $field['label'] : $name;
			}
		}

		$header = array_merge(
			array(
				__( 'Submitted', 'manpreetdev-custom-fields-meta-boxes' ),
				__( 'From', 'manpreetdev-custom-fields-meta-boxes' ),
				__( 'Source', 'manpreetdev-custom-fields-meta-boxes' ),
			),
			array_values( $fields )
		);

		$lines = array( Csv::line( $header ) );

		foreach ( $this->submissions( $post_type ) as $post ) {
			$ref   = new ObjectRef( ObjectRef::POST, (int) $post->ID );
			$cells = array(
				(string) get_post_time( 'Y-m-d H:i:s', true, $post ),
				$this->submitter( $post ),
				(string) get_post_meta( $post->ID, SubmissionPostType::META_SOURCE, true ),
			);

			foreach ( array_keys( $fields ) as $name ) {
				$cells[] = Csv::cell( $values->get( $name, $ref ) );
			}

			$lines[] = Csv::line( $cells );
		}

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Every submission of one type, oldest first.
	 *
	 * Read in batches rather than all at once: a form that has been collecting
	 * for a year is the one somebody exports, and loading every row and all of
	 * its meta into memory is how that request dies instead of downloading.
	 *
	 * @param string $post_type Submission post type.
	 *
	 * @return iterable<\WP_Post>
	 */
	private function submissions( string $post_type ): iterable {
		$per_page = 200;
		$paged    = 1;

		do {
			$batch = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page'         => $per_page, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- One batch of an export that walks every row on purpose.
					'paged'                  => $paged,
					'orderby'                => 'date',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'suppress_filters'       => false,
				)
			);

			$found = count( $batch );

			foreach ( $batch as $post ) {
				yield $post;
			}

			++$paged;
		} while ( $found === $per_page );
	}

	/**
	 * Who sent a submission, as a cell.
	 *
	 * @param \WP_Post $post Submission.
	 */
	private function submitter( \WP_Post $post ): string {
		$user = (int) $post->post_author;

		if ( 0 === $user ) {
			return __( 'A visitor', 'manpreetdev-custom-fields-meta-boxes' );
		}

		$author = get_userdata( $user );

		return $author instanceof \WP_User ? $author->display_name : (string) $user;
	}

	/**
	 * What somebody needs to export a type's submissions.
	 *
	 * Reading every submission at once is more than reading one, so it takes
	 * the capability that edits them rather than the one that lists them.
	 *
	 * @param string $post_type Submission post type.
	 */
	private function export_capability( string $post_type ): string {
		$object = get_post_type_object( $post_type );

		return $object instanceof \WP_Post_Type ? (string) $object->cap->edit_posts : 'edit_posts';
	}

	/**
	 * Add the read-only panel to a submission screen.
	 *
	 * @param string   $post_type Current post type.
	 * @param \WP_Post $post      Current post.
	 */
	public function add_meta_box( $post_type, $post = null ): void {
		if ( ! is_string( $post_type ) || ! SubmissionPostType::is_submission_type( $post_type ) ) {
			return;
		}

		add_meta_box(
			'wpcmb-submission',
			__( 'Submitted values', 'manpreetdev-custom-fields-meta-boxes' ),
			function ( $post ): void {
				$this->render( $post instanceof \WP_Post ? $post : null );
			},
			$post_type,
			'normal',
			'high'
		);
	}

	/**
	 * Render one submission's values.
	 *
	 * @param \WP_Post|null $post Submission.
	 */
	private function render( ?\WP_Post $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$group = $this->group_of( $post );

		if ( ! $group instanceof FieldGroup ) {
			printf(
				'<p class="wpcmb-submission__empty">%s</p>',
				esc_html__( 'The field group this came from no longer exists, so its values cannot be labelled.', 'manpreetdev-custom-fields-meta-boxes' )
			);

			return;
		}

		$renderer = $this->container->get( Renderer::class );
		$values   = $this->container->get( Values::class );
		$ref      = new ObjectRef( ObjectRef::POST, (int) $post->ID );
		$rows     = '';

		foreach ( $group->fields as $field ) {
			$name = (string) ( $field['name'] ?? '' );

			if ( '' === $name || ! $renderer->stores_value( $field ) ) {
				continue;
			}

			$rows .= sprintf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( '' !== (string) ( $field['label'] ?? '' ) ? (string) $field['label'] : $name ),
				$this->display( $values->get( $name, $ref ) )
			);
		}

		if ( '' === $rows ) {
			printf(
				'<p class="wpcmb-submission__empty">%s</p>',
				esc_html__( 'This submission is empty.', 'manpreetdev-custom-fields-meta-boxes' )
			);

			return;
		}

		/*
		 * Core's own table class. No stylesheet of this plugin's loads on a
		 * submission screen — the field assets load where a group matches the
		 * screen, and no group matches this one — so borrowing the layout
		 * wp-admin already has is better than shipping a stylesheet to style
		 * one table.
		 */
		printf(
			'<table class="form-table wpcmb-submission" role="presentation">%s</table>',
			$rows // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		);

		$source = (string) get_post_meta( $post->ID, SubmissionPostType::META_SOURCE, true );

		if ( '' !== $source ) {
			printf(
				'<p class="wpcmb-submission__source">%s <a href="%s">%s</a></p>',
				esc_html__( 'Submitted from', 'manpreetdev-custom-fields-meta-boxes' ),
				esc_url( $source ),
				esc_html( $source )
			);
		}
	}

	/**
	 * One value, as something readable.
	 *
	 * Repeater rows and groups are nested lists rather than JSON: a person
	 * reading a submission wants the answers, not the shape they were stored
	 * in.
	 *
	 * @param mixed $value Stored value.
	 */
	private function display( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return esc_html( $value ? __( 'Yes', 'manpreetdev-custom-fields-meta-boxes' ) : __( 'No', 'manpreetdev-custom-fields-meta-boxes' ) );
		}

		if ( is_scalar( $value ) ) {
			$text = (string) $value;

			return '' === $text
				? '<span class="wpcmb-submission__blank">&mdash;</span>'
				: nl2br( esc_html( $text ) );
		}

		if ( ! is_array( $value ) || array() === $value ) {
			return '<span class="wpcmb-submission__blank">&mdash;</span>';
		}

		$items = '';

		foreach ( $value as $key => $item ) {
			$label = is_string( $key ) ? esc_html( $key ) . ': ' : '';

			$items .= sprintf( '<li>%s%s</li>', $label, $this->display( $item ) );
		}

		return sprintf( '<ul class="wpcmb-submission__list">%s</ul>', $items );
	}

	/**
	 * The group a submission came from.
	 *
	 * @param \WP_Post $post Submission.
	 */
	private function group_of( \WP_Post $post ): ?FieldGroup {
		$key = (string) get_post_meta( $post->ID, SubmissionPostType::META_GROUP, true );

		if ( '' === $key ) {
			return null;
		}

		return $this->container->get( Repository::class )->get( $key );
	}

	/**
	 * Replace the columns a submission list shows.
	 *
	 * The author column goes: a submission's author is whoever was signed in,
	 * which for a public form is nobody. The date stays, because when a
	 * submission arrived is the thing most often being looked for.
	 *
	 * @param array<string, string> $columns Existing columns.
	 *
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();

		unset( $columns['author'] );

		$date = $columns['date'] ?? __( 'Date', 'manpreetdev-custom-fields-meta-boxes' );

		unset( $columns['date'] );

		$columns['wpcmb_from'] = __( 'From', 'manpreetdev-custom-fields-meta-boxes' );
		$columns['date']       = $date;

		return $columns;
	}

	/**
	 * Fill one of our columns.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Submission id.
	 */
	public function column( $column, $post_id ): void {
		if ( 'wpcmb_from' !== $column ) {
			return;
		}

		$post = get_post( (int) $post_id );
		$user = $post instanceof \WP_Post ? (int) $post->post_author : 0;

		if ( 0 !== $user ) {
			$author = get_userdata( $user );

			echo esc_html( $author instanceof \WP_User ? $author->display_name : (string) $user );

			return;
		}

		echo esc_html__( 'A visitor', 'manpreetdev-custom-fields-meta-boxes' );
	}
}
