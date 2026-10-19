<?php
/**
 * The Taxonomies list screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\PostTypes\CustomTaxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Columns and row state for the taxonomy records.
 *
 * The same three questions the Post Types screen answers: what key does it
 * register, what is it attached to, and is it actually registered right now.
 */
final class TaxonomyList extends Module {

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
		add_filter( 'manage_' . CustomTaxonomies::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . CustomTaxonomies::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
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
				'wpcmb_key'        => __( 'Key', 'manpreetdev-custom-fields-meta-boxes' ),
				'wpcmb_type'       => __( 'Terms', 'manpreetdev-custom-fields-meta-boxes' ),
				'wpcmb_post_types' => __( 'Attached to', 'manpreetdev-custom-fields-meta-boxes' ),
				'wpcmb_count'      => __( 'Terms used', 'manpreetdev-custom-fields-meta-boxes' ),
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
		$record = $this->container->get( CustomTaxonomies::class )->config( (int) $post_id );
		$name   = (string) ( $record['name'] ?? '' );

		if ( 'wpcmb_key' === $column ) {
			echo '' !== $name ? '<code>' . esc_html( $name ) . '</code>' : '—';

			return;
		}

		if ( 'wpcmb_type' === $column ) {
			echo empty( $record['hierarchical'] )
				? esc_html__( 'Flat, like tags', 'manpreetdev-custom-fields-meta-boxes' )
				: esc_html__( 'Nested, like categories', 'manpreetdev-custom-fields-meta-boxes' );

			return;
		}

		if ( 'wpcmb_post_types' === $column ) {
			$attached = (array) ( $record['post_types'] ?? array() );

			echo array() !== $attached ? esc_html( implode( ', ', $attached ) ) : '—';

			return;
		}

		if ( 'wpcmb_count' !== $column ) {
			return;
		}

		if ( '' === $name || ! taxonomy_exists( $name ) ) {
			echo '—';

			return;
		}

		$terms = wp_count_terms(
			array(
				'taxonomy'   => $name,
				'hide_empty' => false,
			)
		);

		printf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'edit-tags.php?taxonomy=' . rawurlencode( $name ) ) ),
			esc_html( number_format_i18n( is_wp_error( $terms ) ? 0 : (int) $terms ) )
		);
	}

	/**
	 * Say in the list when a record is defined but registering nothing.
	 *
	 * @param array<string, string> $states Existing states.
	 * @param \WP_Post              $post   Post object.
	 *
	 * @return array<string, string>
	 */
	public function post_states( $states, $post ): array {
		$states = is_array( $states ) ? $states : array();

		if ( ! $post instanceof \WP_Post || CustomTaxonomies::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return $states;
		}

		$record = $this->container->get( CustomTaxonomies::class )->config( $post );
		$name   = (string) ( $record['name'] ?? '' );

		if ( '' === $name ) {
			$states['wpcmb_no_key'] = __( 'No key', 'manpreetdev-custom-fields-meta-boxes' );

			return $states;
		}

		if ( ! taxonomy_exists( $name ) ) {
			$states['wpcmb_dormant'] = __( 'Not registered', 'manpreetdev-custom-fields-meta-boxes' );

			return $states;
		}

		// Registered but attached to nothing is the state that looks like a
		// bug from the front end: the terms screen exists, and no post can
		// use it.
		if ( array() === (array) ( $record['post_types'] ?? array() ) ) {
			$states['wpcmb_unattached'] = __( 'No post types', 'manpreetdev-custom-fields-meta-boxes' );
		}

		return $states;
	}

	/**
	 * Link to the taxonomy's own terms screen from the row.
	 *
	 * @param array<string, string> $actions Existing actions.
	 * @param \WP_Post              $post    Post object.
	 *
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $post ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( ! $post instanceof \WP_Post || CustomTaxonomies::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$name = (string) ( $this->container->get( CustomTaxonomies::class )->config( $post )['name'] ?? '' );

		if ( '' === $name || ! taxonomy_exists( $name ) ) {
			return $actions;
		}

		$actions['wpcmb_terms'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'edit-tags.php?taxonomy=' . rawurlencode( $name ) ) ),
			esc_html__( 'Manage terms', 'manpreetdev-custom-fields-meta-boxes' )
		);

		return $actions;
	}
}
