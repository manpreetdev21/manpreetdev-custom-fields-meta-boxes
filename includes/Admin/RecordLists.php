<?php
/**
 * List columns for the block, form and options page records.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\CustomBlocks;
use WPCMB\PostTypes\CustomForms;
use WPCMB\PostTypes\CustomOptionsPages;

defined( 'ABSPATH' ) || exit;

/**
 * Columns for the three records that reference a field group.
 *
 * One module rather than three, because all three answer the same questions —
 * what does it identify as, which field group does it draw, and is it
 * actually doing anything — and the only difference is the wording.
 */
final class RecordLists extends Module {

	/**
	 * Only load in the admin.
	 */
	public function is_enabled(): bool {
		return is_admin();
	}

	/**
	 * Register hooks for each record type.
	 */
	public function boot(): void {
		foreach ( array( CustomBlocks::POST_TYPE, CustomForms::POST_TYPE, CustomOptionsPages::POST_TYPE ) as $post_type ) {
			add_filter( 'manage_' . $post_type . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . $post_type . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		}

		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
	}

	/**
	 * Replace the date column with what matters.
	 *
	 * @param array<string, string> $columns Existing columns.
	 *
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();

		unset( $columns['date'] );

		return array_merge(
			$columns,
			array(
				'wpcmb_identity' => __( 'Identifier', 'manpreetdev-custom-fields-meta-boxes' ),
				'wpcmb_group'    => __( 'Field group', 'manpreetdev-custom-fields-meta-boxes' ),
				'wpcmb_detail'   => __( 'Details', 'manpreetdev-custom-fields-meta-boxes' ),
			)
		);
	}

	/**
	 * Render one column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post id.
	 */
	public function render_column( $column, $post_id ): void {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$record = $this->record( $post );

		if ( 'wpcmb_identity' === $column ) {
			$this->render_identity( $post->post_type, $record, (int) $post_id );

			return;
		}

		if ( 'wpcmb_group' === $column ) {
			if ( CustomOptionsPages::POST_TYPE === $post->post_type ) {
				$this->render_page_groups( $record );

				return;
			}

			$this->render_group( $record );

			return;
		}

		if ( 'wpcmb_detail' === $column ) {
			$this->render_detail( $post->post_type, $record );
		}
	}

	/**
	 * What the record identifies as.
	 *
	 * @param string               $post_type Record post type.
	 * @param array<string, mixed> $record    Stored configuration.
	 * @param int                  $post_id   Record post id.
	 */
	private function render_identity( string $post_type, array $record, int $post_id ): void {
		if ( CustomForms::POST_TYPE === $post_type ) {
			printf( '<code>%s</code>', esc_html( sprintf( '[wpcmb_form id="%d"]', $post_id ) ) );

			return;
		}

		$key = CustomBlocks::POST_TYPE === $post_type
			? (string) ( $record['name'] ?? '' )
			: (string) ( $record['slug'] ?? '' );

		echo '' !== $key ? '<code>' . esc_html( $key ) . '</code>' : '—';
	}

	/**
	 * The field group a record draws, as a link to it.
	 *
	 * @param array<string, mixed> $record Stored configuration.
	 */
	private function render_group( array $record ): void {
		$key = (string) ( $record['field_group'] ?? '' );

		if ( '' === $key ) {
			echo '—';

			return;
		}

		$group = $this->container->get( Repository::class )->get( $key );

		if ( null === $group ) {
			printf( '<em>%s</em>', esc_html__( 'missing', 'manpreetdev-custom-fields-meta-boxes' ) );

			return;
		}

		printf(
			'<a href="%s">%s</a>',
			esc_url( get_edit_post_link( $group->id ) ?? '' ),
			esc_html( '' !== $group->title ? $group->title : $group->key )
		);
	}

	/**
	 * The field groups on an options page.
	 *
	 * An options page does not name a field group: groups point at the page
	 * with a location rule. So the column lists the groups whose rules do,
	 * where it used to show a dash for every page.
	 *
	 * @param array<string, mixed> $record Stored configuration.
	 */
	private function render_page_groups( array $record ): void {
		$slug = (string) ( $record['slug'] ?? '' );

		$groups = '' === $slug ? array() : $this->container->get( \WPCMB\Fields\Resolver::class )->groups(
			new \WPCMB\Fields\Context( new \WPCMB\Fields\ObjectRef( \WPCMB\Fields\ObjectRef::OPTION, $slug ) )
		);

		if ( array() === $groups ) {
			echo '—';

			return;
		}

		$links = array();

		foreach ( $groups as $group ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( get_edit_post_link( $group->id ) ?? '' ),
				esc_html( '' !== $group->title ? $group->title : $group->key )
			);
		}

		echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link escaped above.
	}

	/**
	 * The one detail worth a column for each kind of record.
	 *
	 * @param string               $post_type Record post type.
	 * @param array<string, mixed> $record    Stored configuration.
	 */
	private function render_detail( string $post_type, array $record ): void {
		if ( CustomBlocks::POST_TYPE === $post_type ) {
			// By the names the Block Types screen gives them, not their keys.
			$labels   = CustomBlocks::features();
			$supports = array_map( static fn( $key ): string => (string) ( $labels[ $key ] ?? $key ), (array) ( $record['supports'] ?? array() ) );

			echo esc_html( array() !== $supports ? implode( ', ', $supports ) : '—' );

			return;
		}

		if ( CustomForms::POST_TYPE === $post_type ) {
			echo esc_html(
				'1' === (string) ( $record['guests'] ?? '0' )
					? __( 'Open to anyone', 'manpreetdev-custom-fields-meta-boxes' )
					: __( 'Signed-in visitors', 'manpreetdev-custom-fields-meta-boxes' )
			);

			return;
		}

		// "Under Settings", the words the editor's own dropdown uses, rather
		// than options-general.php.
		$parent  = (string) ( $record['parent'] ?? '' );
		$others  = array();
		$records = $this->container->get( CustomOptionsPages::class )->records( true );

		foreach ( $records as $other ) {
			$slug = (string) ( $other['slug'] ?? '' );

			// The record that builds the page names it; see CustomOptionsPages::pages().
			if ( '' !== $slug && ! isset( $others[ $slug ] ) ) {
				$others[ $slug ] = (string) ( '' !== (string) ( $other['menu_title'] ?? '' ) ? $other['menu_title'] : ( $other['title'] ?? $slug ) );
			}
		}

		echo esc_html( CustomOptionsPages::parents( $others )[ $parent ] ?? $parent );
	}

	/**
	 * Flag a published record that is not doing anything.
	 *
	 * @param array<string, string> $states Existing states.
	 * @param \WP_Post              $post   Post object.
	 *
	 * @return array<string, string>
	 */
	public function post_states( $states, $post ): array {
		$states = is_array( $states ) ? $states : array();

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return $states;
		}

		if ( ! in_array( $post->post_type, array( CustomBlocks::POST_TYPE, CustomForms::POST_TYPE, CustomOptionsPages::POST_TYPE ), true ) ) {
			return $states;
		}

		$record = $this->record( $post );
		$key    = (string) ( $record['field_group'] ?? '' );

		// Every one of these three draws a field group. Pointing at one that
		// has been deleted is the failure somebody will be hunting for.
		if ( '' !== $key && null === $this->container->get( Repository::class )->get( $key ) ) {
			$states['wpcmb_missing_group'] = __( 'Field group missing', 'manpreetdev-custom-fields-meta-boxes' );

			return $states;
		}

		if ( CustomBlocks::POST_TYPE === $post->post_type && '' === (string) ( $record['name'] ?? '' ) ) {
			$states['wpcmb_no_name'] = __( 'No name', 'manpreetdev-custom-fields-meta-boxes' );
		} elseif ( CustomBlocks::POST_TYPE === $post->post_type && ! isset( $this->container->get( CustomBlocks::class )->live()[ $post->ID ] ) ) {
			// Another record has the name, or the group it needs is missing.
			$states['wpcmb_dormant'] = __( 'Not registered', 'manpreetdev-custom-fields-meta-boxes' );
		}

		if ( CustomOptionsPages::POST_TYPE === $post->post_type && '' === (string) ( $record['slug'] ?? '' ) ) {
			$states['wpcmb_no_slug'] = __( 'No slug', 'manpreetdev-custom-fields-meta-boxes' );
		} elseif ( CustomOptionsPages::POST_TYPE === $post->post_type
			&& ( $this->container->get( CustomOptionsPages::class )->pages()[ (string) $record['slug'] ]['record'] ?? 0 ) !== $post->ID
		) {
			// Another record builds this slug's page.
			$states['wpcmb_dormant'] = __( 'Not in use', 'manpreetdev-custom-fields-meta-boxes' );
		}

		if ( CustomForms::POST_TYPE === $post->post_type && '' === $key ) {
			$states['wpcmb_no_group'] = __( 'No field group', 'manpreetdev-custom-fields-meta-boxes' );
		}

		return $states;
	}

	/**
	 * One record's configuration, whichever type it is.
	 *
	 * @param \WP_Post $post Record post.
	 *
	 * @return array<string, mixed>
	 */
	private function record( \WP_Post $post ): array {
		$types = array(
			CustomBlocks::POST_TYPE       => CustomBlocks::class,
			CustomForms::POST_TYPE        => CustomForms::class,
			CustomOptionsPages::POST_TYPE => CustomOptionsPages::class,
		);

		if ( ! isset( $types[ $post->post_type ] ) ) {
			return array();
		}

		return $this->container->get( $types[ $post->post_type ] )->config( $post );
	}
}
