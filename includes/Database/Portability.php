<?php
/**
 * Moving a site's configuration between installs.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Database;

use WPCMB\Abstracts\RecordType;
use WPCMB\Container;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\CustomBlocks;
use WPCMB\PostTypes\CustomForms;
use WPCMB\PostTypes\CustomOptionsPages;
use WPCMB\PostTypes\CustomPostTypes;
use WPCMB\PostTypes\CustomTaxonomies;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes everything this plugin keeps as configuration.
 *
 * Field groups could already be exported. The post types, taxonomies, block
 * types, forms and options pages added since could not, so a site built on
 * staging had to be rebuilt by hand on production — which is the one thing an
 * export is for.
 *
 * Not a module: nothing is hooked here. The Tools screen and WP-CLI both ask
 * this class, so there is one definition of what an export contains and one
 * of what an import accepts.
 */
final class Portability {

	/**
	 * Marks a file as one of ours, so an import can recognise it.
	 */
	public const FORMAT = 'manpreetdev-custom-fields-meta-boxes';

	/**
	 * Markers an import still recognises, from before the plugin was renamed.
	 *
	 * An export is a file somebody keeps. The marker is the slug the plugin
	 * had when the file was written, so a rename would turn every export made
	 * before it into a file this plugin refuses to read — which is the one
	 * thing the format exists to prevent.
	 *
	 * @var array<int, string>
	 */
	private const FORMER_FORMATS = array( 'metafields-custom-fields', 'wp-custom-meta-box' );

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * The record types an export covers, keyed by the section they live in.
	 *
	 * The section names are part of the file format, so they are spelled out
	 * here rather than derived from a class name that may be refactored.
	 *
	 * @return array<string, class-string<RecordType>>
	 */
	public static function sections(): array {
		return array(
			'post_types'    => CustomPostTypes::class,
			'taxonomies'    => CustomTaxonomies::class,
			'blocks'        => CustomBlocks::class,
			'forms'         => CustomForms::class,
			'options_pages' => CustomOptionsPages::class,
		);
	}

	/**
	 * Everything on the site, or just the sections asked for.
	 *
	 * @param array<int, string>|null $only     Section names, or null for all.
	 * @param array<int, string>|null $group_keys Field group keys, or null for all.
	 *
	 * @return array<string, mixed>
	 */
	public function export( ?array $only = null, ?array $group_keys = null ): array {
		$payload = array(
			'format'   => self::FORMAT,
			'version'  => WPCMB_VERSION,
			'exported' => gmdate( 'Y-m-d H:i:s' ),
			'site'     => home_url(),
		);

		if ( null === $only || in_array( 'field_groups', $only, true ) ) {
			$payload['field_groups'] = $this->export_groups( $group_keys );
		}

		foreach ( self::sections() as $section => $class ) {
			if ( null !== $only && ! in_array( $section, $only, true ) ) {
				continue;
			}

			$payload[ $section ] = array_values( $this->container->get( $class )->records( true ) );
		}

		/**
		 * Filters an export before it is written.
		 *
		 * @since 1.15.0
		 *
		 * @param array<string, mixed>    $payload Export payload.
		 * @param array<int, string>|null $only    Sections asked for, or null for all.
		 */
		return (array) apply_filters( 'wpcmb/export', $payload, $only );
	}

	/**
	 * Field groups, as an export holds them.
	 *
	 * @param array<int, string>|null $keys Group keys, or null for all.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function export_groups( ?array $keys ): array {
		$groups = $this->container->get( Repository::class )->all( true );

		if ( null !== $keys ) {
			$groups = array_intersect_key( $groups, array_flip( $keys ) );
		}

		return array_values( array_map( static fn( FieldGroup $group ): array => $group->to_array(), $groups ) );
	}

	/**
	 * How many of each thing an export holds.
	 *
	 * @param array<string, mixed> $payload Export payload.
	 *
	 * @return array<string, int>
	 */
	public static function summarise( array $payload ): array {
		$counts = array();

		foreach ( array_merge( array( 'field_groups' => '' ), self::sections() ) as $section => $ignored ) {
			if ( isset( $payload[ $section ] ) && is_array( $payload[ $section ] ) ) {
				$counts[ $section ] = count( $payload[ $section ] );
			}
		}

		return $counts;
	}

	/**
	 * Read a decoded file into the shape an import walks.
	 *
	 * Three things have been exported over this plugin's life and all three
	 * still import: the envelope this version writes, a bare list of field
	 * groups, and a single field group on its own. A file somebody exported a
	 * year ago is the one thing an import has to be forgiving about.
	 *
	 * @param mixed $decoded Decoded JSON.
	 *
	 * @return array<string, mixed>|null Null when it is not ours.
	 */
	public static function normalise( $decoded ): ?array {
		if ( ! is_array( $decoded ) || array() === $decoded ) {
			return null;
		}

		// The envelope, under this version's marker or any the plugin used
		// before it was renamed.
		if ( isset( $decoded['format'] )
			&& in_array( $decoded['format'], array_merge( array( self::FORMAT ), self::FORMER_FORMATS ), true )
		) {
			return $decoded;
		}

		// One field group on its own.
		if ( isset( $decoded['key'] ) || isset( $decoded['fields'] ) ) {
			return array( 'field_groups' => array( $decoded ) );
		}

		// A bare list of field groups, which is what earlier versions wrote.
		if ( array_is_list( $decoded ) ) {
			return array( 'field_groups' => $decoded );
		}

		return null;
	}

	/**
	 * Write an export into this site.
	 *
	 * @param array<string, mixed> $payload Normalised payload.
	 * @param string               $status  Status for anything the import creates.
	 *
	 * @return array<string, int> How many of each section were written.
	 */
	public function import( array $payload, string $status = 'draft' ): array {
		$written = array();

		if ( isset( $payload['field_groups'] ) && is_array( $payload['field_groups'] ) ) {
			$written['field_groups'] = $this->import_groups( $payload['field_groups'], $status );
		}

		foreach ( self::sections() as $section => $class ) {
			if ( ! isset( $payload[ $section ] ) || ! is_array( $payload[ $section ] ) ) {
				continue;
			}

			$written[ $section ] = $this->import_records( $this->container->get( $class ), $payload[ $section ], $status );
		}

		/**
		 * Fires after an import has finished.
		 *
		 * @since 1.15.0
		 *
		 * @param array<string, int>   $written How many of each section were written.
		 * @param array<string, mixed> $payload The payload that was imported.
		 */
		do_action( 'wpcmb/imported', $written, $payload );

		return $written;
	}

	/**
	 * Import field groups, updating any already here by key.
	 *
	 * @param array<int, mixed> $groups Exported groups.
	 * @param string            $status Status for anything it creates.
	 */
	private function import_groups( array $groups, string $status ): int {
		$repository = $this->container->get( Repository::class );
		$imported   = 0;

		foreach ( $groups as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$config   = FieldGroup::sanitize( $raw );
			$existing = $repository->get( $config['key'] );
			$post_id  = $existing instanceof FieldGroup ? $existing->id : 0;

			if ( 0 === $post_id ) {
				/*
				 * Imported inactive unless the caller says otherwise. An
				 * import is somebody else's configuration arriving on a live
				 * site, and a field group that starts publishing fields onto
				 * post screens the moment a file is uploaded is not a
				 * pleasant surprise.
				 */
				$post_id = wp_insert_post(
					array(
						'post_type'   => FieldGroupPostType::POST_TYPE,
						'post_status' => $status,
						'post_title'  => '' !== $config['title'] ? $config['title'] : __( 'Imported field group', 'manpreetdev-custom-fields-meta-boxes' ),
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					continue;
				}
			} else {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $config['title'],
					)
				);
			}

			$repository->save( (int) $post_id, $config );
			++$imported;
		}

		return $imported;
	}

	/**
	 * Import one section of records.
	 *
	 * @param RecordType        $type    Record type.
	 * @param array<int, mixed> $records Exported records.
	 * @param string            $status  Status for anything it creates.
	 */
	private function import_records( RecordType $type, array $records, string $status ): int {
		$imported = 0;

		foreach ( $records as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$title  = (string) ( $raw['title'] ?? '' );
			$config = $type->sanitize( $raw );

			/*
			 * Matched on identity, so importing the same file twice updates
			 * what is here rather than building a second copy of everything.
			 * The identity is read from the cleaned configuration plus the
			 * title, which is what a form is distinguished by.
			 */
			$existing = $type->find( $type->identity( $config + array( 'title' => $title ) ) );

			$post_id = null !== $existing ? $existing['id'] : 0;

			if ( 0 === $post_id ) {
				$post_id = wp_insert_post(
					array(
						'post_type'   => $type->post_type(),
						'post_status' => $status,
						'post_title'  => '' !== $title ? $title : __( 'Imported', 'manpreetdev-custom-fields-meta-boxes' ),
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					continue;
				}
			} elseif ( '' !== $title ) {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $title,
					)
				);
			}

			$type->save( (int) $post_id, $config );
			++$imported;
		}

		return $imported;
	}

	/**
	 * An export as PHP a theme or a small plugin can carry.
	 *
	 * Field groups register through their own function; records go through
	 * the filter each record type already applies to its own list, so this
	 * needs nothing in the plugin that was not already there.
	 *
	 * @param array<string, mixed> $payload Export payload.
	 */
	public function php( array $payload ): string {
		$lines = array(
			'<?php',
			'/**',
			' * Field configuration exported from ' . home_url() . '.',
			' *',
			' * Drop this in a theme or a small plugin. Everything here is then',
			' * defined in code: the screens still show it, and the records it',
			' * replaces can be deleted.',
			' */',
			'',
			'defined( \'ABSPATH\' ) || exit;',
			'',
		);

		foreach ( (array) ( $payload['field_groups'] ?? array() ) as $group ) {
			$lines[] = "add_action( 'wpcmb/register_field_groups', function () {";
			$lines[] = "\twpcmb_register_field_group( " . self::code( (array) $group, 1 ) . ' );';
			$lines[] = '} );';
			$lines[] = '';
		}

		foreach ( self::sections() as $section => $class ) {
			$records = (array) ( $payload[ $section ] ?? array() );

			if ( array() === $records ) {
				continue;
			}

			$post_type = $this->container->get( $class )->post_type();

			$lines[] = "add_filter( 'wpcmb/records/{$post_type}', function ( \$records ) {";

			foreach ( $records as $index => $record ) {
				$lines[] = "\t\$records['imported_{$index}'] = " . self::code( (array) $record, 1 ) . ';';
			}

			$lines[] = "\treturn \$records;";
			$lines[] = '} );';
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * One array as readable PHP.
	 *
	 * @param array<string, mixed> $value  Array to write.
	 * @param int                  $indent Tabs to indent continuation lines by.
	 */
	private static function code( array $value, int $indent = 0 ): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Deliberate code generation.
		$code = var_export( $value, true );

		return str_replace(
			array( 'array (', "\n" ),
			array( 'array(', "\n" . str_repeat( "\t", $indent ) ),
			$code
		);
	}
}
