<?php
/**
 * Field groups as files in the theme, for version control.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Database;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a JSON file per field group beside the code that uses it.
 *
 * A field group is configuration, but it lives in the database, so it travels
 * between environments the way content does: by hand, or by copying a
 * database. That is the wrong direction — a template reading `wpcmb_get_field(
 * 'byline' )` is in version control, and the field that makes it work is not.
 *
 * So every save also writes `wpcmb-json/{key}.json`. Those files are then read
 * back as groups registered in code, which means a fresh checkout works
 * immediately with no import step: the fields exist because the files do. A
 * group also in the database wins over its file, which is what keeps the
 * editor authoritative while you are working in it.
 *
 * Switched on by creating the directory, and off by removing it. That is one
 * less setting, and it puts the decision where the consequence is: a theme
 * with the folder gets files, a theme without one does not.
 */
final class LocalJson extends Module {

	/**
	 * The folder looked for inside a theme.
	 */
	private const DIRECTORY = 'wpcmb-json';

	/**
	 * Query arg carrying a sync request.
	 */
	public const ACTION_SYNC = 'wpcmb_sync';

	/**
	 * Meta key recording which version of a file this group already holds.
	 *
	 * Without it, syncing a file would never settle. A file's `modified` is
	 * the time it was saved on whichever machine wrote it, which can be ahead
	 * of the time it is imported here, so comparing it with this post's
	 * modified time would keep offering the same import forever. Recording
	 * what was taken also means a sync does not have to rewrite the file to
	 * make the two agree, which would put a change in the working tree every
	 * time somebody pressed the button.
	 */
	private const META_SYNCED = '_wpcmb_json_synced';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wpcmb/field_group/saved', array( $this, 'write' ), 10, 2 );
		add_action( 'wpcmb/register_field_groups', array( $this, 'register' ) );

		// A group deleted in the admin takes its file with it. Trashing does
		// not: trash is reversible and deleting the file would not be.
		add_action( 'before_delete_post', array( $this, 'forget' ) );
	}

	/**
	 * Where files are written.
	 *
	 * The active theme, so the groups travel with the templates that read
	 * them. A site keeping them in a plugin instead filters this.
	 */
	public function save_path(): string {
		/**
		 * Filters the directory field group files are written to.
		 *
		 * The directory is not created: it existing is what switches saving
		 * on, so creating it here would switch it on for everybody.
		 *
		 * @since 1.17.0
		 *
		 * @param string $path Absolute directory path, no trailing slash.
		 */
		return (string) apply_filters( 'wpcmb/json/save_path', get_stylesheet_directory() . '/' . self::DIRECTORY );
	}

	/**
	 * Where files are read from.
	 *
	 * More than one, so a child theme can load its parent's groups as well as
	 * its own. Earlier paths win, which matches how WordPress resolves
	 * templates.
	 *
	 * @return array<int, string>
	 */
	public function load_paths(): array {
		$paths = array( $this->save_path() );

		$parent = get_template_directory() . '/' . self::DIRECTORY;

		if ( ! in_array( $parent, $paths, true ) ) {
			$paths[] = $parent;
		}

		/**
		 * Filters the directories field group files are read from.
		 *
		 * @since 1.17.0
		 *
		 * @param array<int, string> $paths Absolute directory paths.
		 */
		return array_values( array_unique( array_filter( (array) apply_filters( 'wpcmb/json/load_paths', $paths ) ) ) );
	}

	/**
	 * Whether files are being written.
	 */
	public function is_writing(): bool {
		return is_dir( $this->save_path() );
	}

	/**
	 * Write one group to its file.
	 *
	 * @param int                  $post_id Field group post id.
	 * @param array<string, mixed> $config  The configuration that was saved.
	 */
	public function write( $post_id, $config ): void {
		if ( ! $this->is_writing() ) {
			return;
		}

		$group = $this->container->get( Repository::class )->get( (int) $post_id );

		if ( ! $group instanceof FieldGroup ) {
			return;
		}

		$payload = $group->to_array();

		/*
		 * The modification time travels inside the file rather than being read
		 * off the filesystem. A checkout gives every file the time it was
		 * checked out, so a file's own mtime says when this machine saw it,
		 * not when somebody changed it — and sync would then offer to import
		 * every group after every pull.
		 */
		$payload['modified'] = (int) get_post_modified_time( 'U', true, (int) $post_id );

		$written = file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A path inside the theme the site chose; WP_Filesystem asks for credentials this has no screen to collect.
			$this->path_for( $group->key ),
			(string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
		);

		if ( false === $written ) {
			return;
		}

		update_post_meta( (int) $post_id, self::META_SYNCED, $payload['modified'] );

		/**
		 * Fires after a field group has been written to its file.
		 *
		 * @since 1.17.0
		 *
		 * @param string               $key     Group key.
		 * @param array<string, mixed> $payload What was written.
		 */
		do_action( 'wpcmb/json/written', $group->key, $payload );
	}

	/**
	 * Delete the file of a group being deleted.
	 *
	 * @param int $post_id Post id.
	 */
	public function forget( $post_id ): void {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post || FieldGroupPostType::POST_TYPE !== $post->post_type || ! $this->is_writing() ) {
			return;
		}

		$group = $this->container->get( Repository::class )->get( (int) $post_id );
		$path  = $group instanceof FieldGroup ? $this->path_for( $group->key ) : '';

		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Register every group that has a file, so a checkout needs no import.
	 *
	 * A group also in the database wins, because Repository prefers stored
	 * groups over registered ones — so registering a file whose group is
	 * being edited in the admin changes nothing.
	 *
	 * @param Repository $repository Field group repository.
	 */
	public function register( $repository ): void {
		if ( ! $repository instanceof Repository ) {
			return;
		}

		foreach ( $this->files() as $file ) {
			$repository->register( $file['config'] );
		}
	}

	/**
	 * Every group file found, keyed by group key.
	 *
	 * @return array<string, array{config: array<string, mixed>, modified: int, path: string}>
	 */
	public function files(): array {
		$files = array();

		foreach ( $this->load_paths() as $directory ) {
			foreach ( (array) glob( $directory . '/*.json' ) as $path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, not a request.
				$decoded = json_decode( (string) file_get_contents( (string) $path ), true );

				if ( ! is_array( $decoded ) || '' === (string) ( $decoded['key'] ?? '' ) ) {
					continue;
				}

				$key = (string) $decoded['key'];

				// Earlier paths win, so a child theme overrides its parent.
				if ( isset( $files[ $key ] ) ) {
					continue;
				}

				$files[ $key ] = array(
					'config'   => $decoded,
					'modified' => (int) ( $decoded['modified'] ?? 0 ),
					'path'     => (string) $path,
				);
			}
		}

		return $files;
	}

	/**
	 * The groups whose file says something the database does not.
	 *
	 * Either the group is only a file — somebody else's checkout, newly pulled
	 * — or its file was changed more recently than the group was saved here.
	 *
	 * @return array<string, string> Group title, keyed by group key.
	 */
	public function syncable(): array {
		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			return array();
		}

		$repository = $this->container->get( Repository::class );
		$available  = array();

		foreach ( $this->files() as $key => $file ) {
			$stored = $repository->get( $key );
			$title  = (string) ( $file['config']['title'] ?? $key );

			if ( ! $stored instanceof FieldGroup || 0 === $stored->id ) {
				$available[ $key ] = $title;

				continue;
			}

			if ( $file['modified'] > $this->held( $stored->id ) ) {
				$available[ $key ] = $title;
			}
		}

		return $available;
	}

	/**
	 * The newest version of a group's file this site already holds.
	 *
	 * Whichever is later: the last file version imported, or the last time the
	 * group was saved here. A group saved in the admin after a sync is ahead
	 * of the file it came from, and must not be offered for import again.
	 *
	 * @param int $post_id Field group post id.
	 */
	private function held( int $post_id ): int {
		return max(
			(int) get_post_meta( $post_id, self::META_SYNCED, true ),
			(int) get_post_modified_time( 'U', true, $post_id )
		);
	}

	/**
	 * Import the given groups from their files.
	 *
	 * @param array<int, string> $keys Group keys to import.
	 *
	 * @return int How many were written.
	 */
	public function sync( array $keys ): int {
		$files      = $this->files();
		$repository = $this->container->get( Repository::class );
		$synced     = 0;

		foreach ( $keys as $key ) {
			if ( ! isset( $files[ $key ] ) ) {
				continue;
			}

			$config = FieldGroup::sanitize( $files[ $key ]['config'] );
			$title  = (string) ( $files[ $key ]['config']['title'] ?? $key );
			$stored = $repository->get( $key );
			$status = false !== ( $files[ $key ]['config']['settings']['active'] ?? true ) ? 'publish' : 'draft';

			if ( $stored instanceof FieldGroup && $stored->id > 0 ) {
				$post_id = $stored->id;

				/*
				 * An existing group keeps the status it has here. Whether a
				 * group is switched on is a decision about this site, not
				 * something a file pulled from another one should overrule.
				 */
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $title,
					)
				);
			} else {
				$post_id = wp_insert_post(
					array(
						'post_type'   => FieldGroupPostType::POST_TYPE,
						'post_status' => $status,
						'post_title'  => '' !== $title ? $title : __( 'Imported field group', 'manpreetdev-custom-fields-meta-boxes' ),
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					continue;
				}
			}

			$repository->save( (int) $post_id, $config );

			update_post_meta( (int) $post_id, self::META_SYNCED, $files[ $key ]['modified'] );

			++$synced;
		}

		if ( $synced > 0 ) {
			/**
			 * Fires after groups have been imported from their files.
			 *
			 * @since 1.17.0
			 *
			 * @param array<int, string> $keys Group keys that were asked for.
			 */
			do_action( 'wpcmb/json/synced', $keys );
		}

		return $synced;
	}

	/**
	 * The file one group is written to.
	 *
	 * @param string $key Group key.
	 */
	private function path_for( string $key ): string {
		return $this->save_path() . '/' . sanitize_file_name( $key ) . '.json';
	}
}
