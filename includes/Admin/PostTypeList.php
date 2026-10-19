<?php
/**
 * The Post Types list screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\PostTypes\CustomPostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Columns and row state for the post type records.
 *
 * WordPress already supplies the list table, so this adds only what it cannot
 * know: the key each record registers, what it supports, and whether the post
 * type is actually registered right now — which is not the same as the record
 * existing, and is the first thing anybody wants to know when it is not
 * working.
 */
final class PostTypeList extends Module {

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
		add_filter( 'manage_' . CustomPostTypes::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . CustomPostTypes::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
	}

	/**
	 * Replace the date column with what matters here.
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
				'wpcmb_key'      => __( 'Key', 'manpreetdev-custom-fields-meta-boxes' ),
				'wpcmb_supports' => __( 'Supports', 'manpreetdev-custom-fields-meta-boxes' ),
				'wpcmb_count'    => __( 'Items', 'manpreetdev-custom-fields-meta-boxes' ),
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
		$record = $this->container->get( CustomPostTypes::class )->config( (int) $post_id );
		$name   = (string) ( $record['name'] ?? '' );

		if ( 'wpcmb_key' === $column ) {
			echo '' !== $name ? '<code>' . esc_html( $name ) . '</code>' : '—';

			return;
		}

		if ( 'wpcmb_supports' === $column ) {
			$supports = (array) ( $record['supports'] ?? array() );

			echo array() !== $supports ? esc_html( implode( ', ', $supports ) ) : '—';

			return;
		}

		if ( 'wpcmb_count' !== $column ) {
			return;
		}

		// How many posts exist under this key, which answers "is anything
		// actually using it" without opening the post type.
		if ( '' === $name || ! post_type_exists( $name ) ) {
			echo '—';

			return;
		}

		$counts = wp_count_posts( $name );
		$total  = (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 ) + (int) ( $counts->pending ?? 0 ) + (int) ( $counts->private ?? 0 );

		printf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'edit.php?post_type=' . rawurlencode( $name ) ) ),
			esc_html( number_format_i18n( $total ) )
		);
	}

	/**
	 * Say in the list when a record is defined but not registering anything.
	 *
	 * @param array<string, string> $states Existing states.
	 * @param \WP_Post              $post   Post object.
	 *
	 * @return array<string, string>
	 */
	public function post_states( $states, $post ): array {
		$states = is_array( $states ) ? $states : array();

		if ( ! $post instanceof \WP_Post || CustomPostTypes::POST_TYPE !== $post->post_type ) {
			return $states;
		}

		// "Draft" already says inactive; only a published record that is not
		// registering is worth flagging.
		if ( 'publish' !== $post->post_status ) {
			return $states;
		}

		$type   = $this->container->get( CustomPostTypes::class );
		$record = $type->config( $post );
		$name   = (string) ( $record['name'] ?? '' );

		if ( '' === $name ) {
			$states['wpcmb_no_key'] = __( 'No key', 'manpreetdev-custom-fields-meta-boxes' );

			return $states;
		}

		if ( ! post_type_exists( $name ) ) {
			$states['wpcmb_dormant'] = __( 'Not registered', 'manpreetdev-custom-fields-meta-boxes' );
		}

		return $states;
	}

	/**
	 * Link to the post type's own screen from the row.
	 *
	 * @param array<string, string> $actions Existing actions.
	 * @param \WP_Post              $post    Post object.
	 *
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $post ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( ! $post instanceof \WP_Post || CustomPostTypes::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$name = (string) ( $this->container->get( CustomPostTypes::class )->config( $post )['name'] ?? '' );

		if ( '' === $name || ! post_type_exists( $name ) ) {
			return $actions;
		}

		$actions['wpcmb_view'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'edit.php?post_type=' . rawurlencode( $name ) ) ),
			esc_html__( 'Open', 'manpreetdev-custom-fields-meta-boxes' )
		);

		return $actions;
	}
}
