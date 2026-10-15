<?php
/**
 * One-time upgrades between plugin versions.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Database;

use WPCMB\Abstracts\Module;
use WPCMB\Admin\OptionsPages;
use WPCMB\Fields\Repository;
use WPCMB\Installer;
use WPCMB\PostTypes\CustomBlocks;
use WPCMB\PostTypes\CustomForms;
use WPCMB\PostTypes\CustomOptionsPages;
use WPCMB\PostTypes\SubmissionPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Brings a site's stored configuration up to the current version.
 *
 * Activation is not enough: updating a plugin does not re-activate it, so an
 * upgrade has to notice for itself that the stored version is behind. This
 * runs on `admin_init`, once, and writes the new version only when every
 * migration has finished — a half-finished upgrade is retried rather than
 * recorded as done.
 *
 * Every migration here is additive. It reads the old configuration and writes
 * a record beside it; nothing is deleted, nothing is rewritten, and the code
 * that reads the old shape still reads it. A site can be rolled back to the
 * previous version and will behave exactly as it did.
 */
final class Migrations extends Module {

	/**
	 * Option recording which migrations have run.
	 */
	public const OPTION = 'wpcmb_migrations';

	/**
	 * Only in the admin: this writes posts, and nothing on the front of the
	 * site needs it to have happened yet.
	 */
	public function is_enabled(): bool {
		return is_admin();
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_init', array( $this, 'run' ) );
	}

	/**
	 * The migrations this version knows about, in order.
	 *
	 * @return array<string, callable>
	 */
	private function migrations(): array {
		return array(
			'1.12.0-blocks'        => array( $this, 'migrate_blocks' ),
			'1.12.0-forms'         => array( $this, 'migrate_forms' ),
			'1.12.0-options-pages' => array( $this, 'migrate_options_pages' ),
		);
	}

	/**
	 * Run anything that has not run yet.
	 */
	public function run(): void {
		// Writing records is an administrator's job, and a subscriber
		// loading an admin page must not trigger it.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$done = (array) get_option( self::OPTION, array() );

		foreach ( $this->migrations() as $name => $callback ) {
			if ( in_array( $name, $done, true ) ) {
				continue;
			}

			$created = (int) call_user_func( $callback );

			$done[] = $name;

			update_option( self::OPTION, array_values( array_unique( $done ) ), false );

			/**
			 * Fires after one migration completes.
			 *
			 * @since 1.12.0
			 *
			 * @param string $name    Migration name.
			 * @param int    $created How many records it wrote.
			 */
			do_action( 'wpcmb/migrated', $name, $created );
		}

		if ( WPCMB_VERSION !== (string) get_option( Installer::VERSION_OPTION ) ) {
			update_option( Installer::VERSION_OPTION, WPCMB_VERSION, false );
		}
	}

	/**
	 * Turn every block-enabled field group into a block record.
	 *
	 * @return int Records created.
	 */
	public function migrate_blocks(): int {
		$records = $this->container->get( CustomBlocks::class );
		$claimed = $records->claimed_groups();
		$created = 0;

		foreach ( $this->container->get( Repository::class )->all( true ) as $group ) {
			$name = (string) ( $group->settings['block_name'] ?? '' );

			if ( empty( $group->settings['block_enabled'] ) || '' === $name ) {
				continue;
			}

			if ( in_array( $group->key, $claimed, true ) ) {
				continue;
			}

			$active = 'publish' === get_post_status( $group->id );

			$config = $records->sanitize(
				array(
					'field_group'  => $group->key,
					'name'         => $name,
					'icon'         => (string) ( $group->settings['block_icon'] ?? '' ),
					'category'     => (string) ( $group->settings['block_category'] ?? '' ),
					'description'  => (string) ( $group->settings['block_description'] ?? '' ),
					'keywords'     => (string) ( $group->settings['block_keywords'] ?? '' ),
					'mode'         => (string) ( $group->settings['block_mode'] ?? 'auto' ),
					'supports'     => (array) ( $group->settings['block_supports'] ?? array() ),
					'inner_blocks' => ! empty( $group->settings['block_inner_blocks'] ),
				)
			);

			$created += $this->write( $records, $group->title, $config, $active );
		}

		return $created;
	}

	/**
	 * Give every field group that has taken a submission a form record.
	 *
	 * A form was only ever described by its shortcode, so there is no stored
	 * configuration to carry over — what can be recovered is which groups have
	 * actually been used as forms, which is exactly the set that should appear
	 * on the Forms screen rather than an empty list.
	 *
	 * @return int Records created.
	 */
	public function migrate_forms(): int {
		$records    = $this->container->get( CustomForms::class );
		$repository = $this->container->get( Repository::class );
		$created    = 0;

		$existing = array();

		foreach ( $records->records( true ) as $record ) {
			$existing[] = (string) ( $record['field_group'] ?? '' );
		}

		foreach ( (array) get_option( SubmissionPostType::OPTION, array() ) as $group_key ) {
			$group_key = (string) $group_key;

			if ( '' === $group_key || in_array( $group_key, $existing, true ) ) {
				continue;
			}

			$group = $repository->get( $group_key );

			if ( null === $group ) {
				continue;
			}

			/*
			 * The defaults, not a guess at what the shortcode said. The
			 * shortcode stays where it is and keeps its own attributes; this
			 * record is a starting point somebody can switch to when they
			 * want the form managed here.
			 */
			$config = $records->sanitize( array_merge( CustomForms::defaults(), array( 'field_group' => $group_key ) ) );

			$created += $this->write( $records, $group->title, $config, true );
		}

		return $created;
	}

	/**
	 * Turn every rule-created options page into a record.
	 *
	 * @return int Records created.
	 */
	public function migrate_options_pages(): int {
		$records = $this->container->get( CustomOptionsPages::class );
		$claimed = $records->claimed_slugs();
		$created = 0;

		foreach ( $this->container->get( OptionsPages::class )->pages() as $slug => $page ) {
			$slug = (string) $slug;

			// Skip the ones this migration is reading back out of itself.
			if ( '' === $slug || in_array( $slug, $claimed, true ) || isset( $page['record'] ) ) {
				continue;
			}

			$config = $records->sanitize(
				array(
					'slug'       => $slug,
					'menu_title' => (string) ( $page['title'] ?? '' ),
					'parent'     => (string) ( $page['parent'] ?? '' ),
					'capability' => (string) ( $page['capability'] ?? 'manage_options' ),
					'icon'       => (string) ( $page['icon'] ?? '' ),
					'position'   => (int) ( $page['order'] ?? 0 ),
				)
			);

			$created += $this->write( $records, (string) ( $page['title'] ?? $slug ), $config, true );
		}

		return $created;
	}

	/**
	 * Write one record.
	 *
	 * @param \WPCMB\Abstracts\RecordType $type   Record type.
	 * @param string                      $title  Record title.
	 * @param array<string, mixed>        $config Cleaned configuration.
	 * @param bool                        $active Whether it should be published.
	 *
	 * @return int 1 when written, 0 when not.
	 */
	private function write( $type, string $title, array $config, bool $active ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => $type->post_type(),

				// A record for something that was switched off stays switched
				// off, so a migration cannot turn anything on.
				'post_status' => $active ? 'publish' : 'draft',
				'post_title'  => '' !== $title ? $title : __( 'Untitled', 'metafields-custom-fields' ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		$type->save( (int) $post_id, $config );

		return 1;
	}
}
