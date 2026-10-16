<?php
/**
 * The `wp wpcmb` command.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\CLI;

use WPCMB\Container;
use WPCMB\Database\Portability;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Validator;
use WPCMB\Fields\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Manage field groups and values from the command line.
 *
 * Deliberately narrow: listing groups, reading and writing values, and moving
 * field groups between environments. Those are what a deploy script or a
 * migration needs, and each is a thin shell over the same services the admin
 * screens use rather than a second way of doing the work.
 *
 * Every public method here is a subcommand, so nothing else belongs on this
 * class.
 */
final class FieldCommand {

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
	 * List field groups.
	 *
	 * ## OPTIONS
	 *
	 * [--inactive]
	 * : Include groups that are not active.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb groups
	 *     wp wpcmb groups --inactive --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function groups( $args, $assoc_args ): void {
		$groups = $this->container->get( Repository::class )->all( isset( $assoc_args['inactive'] ) );

		$rows = array();

		foreach ( $groups as $group ) {
			$rows[] = array(
				'key'    => $group->key,
				'title'  => $group->title,
				'fields' => count( $group->fields ),
				'active' => $group->is_active() ? 'yes' : 'no',
				'block'  => ! empty( $group->settings['block_enabled'] ) ? (string) $group->settings['block_name'] : '',
			);
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'key', 'title', 'fields', 'active', 'block' )
		);
	}

	/**
	 * Read a field value.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Field name.
	 *
	 * <object>
	 * : Object identifier, e.g. 12, post_12, term_5, user_2 or options.
	 *
	 * [--raw]
	 * : Return the stored value without field-type formatting.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - yaml
	 *   - var_export
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb get byline 12
	 *     wp wpcmb get api_key options --raw
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function get( $args, $assoc_args ): void {
		$ref = $this->resolve( $args[1] );

		$value = $this->container->get( Values::class )->get( $args[0], $ref, ! isset( $assoc_args['raw'] ) );

		\WP_CLI::print_value( $value, array( 'format' => (string) ( $assoc_args['format'] ?? 'json' ) ) );
	}

	/**
	 * Write a field value.
	 *
	 * The value is validated and sanitized exactly as it would be from an
	 * edit screen, so a script cannot store something the admin would refuse.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Field name.
	 *
	 * <object>
	 * : Object identifier.
	 *
	 * <value>
	 * : The value. Parsed as JSON when it is valid JSON, so structured fields
	 * : can be written from a script.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb update byline 12 "Manpreet Singh"
	 *     wp wpcmb update rows 12 '[{"title":"One"},{"title":"Two"}]'
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	public function update( $args ): void {
		list( $name, $identifier, $raw ) = $args;

		$ref   = $this->resolve( $identifier );
		$field = $this->container->get( Repository::class )->field_by_name( $name );

		if ( null === $field ) {
			\WP_CLI::error( sprintf( 'No field named "%s".', $name ) );
		}

		$decoded = json_decode( $raw, true );
		$value   = ( null === $decoded && 'null' !== $raw ) ? $raw : $decoded;

		$errors = $this->container->get( Validator::class )->validate(
			array( $name => $field ),
			array( $name => $value )
		);

		if ( array() !== $errors ) {
			\WP_CLI::error( (string) reset( $errors ) );
		}

		$clean = $this->container->get( Renderer::class )->sanitize( $value, $field );

		$this->container->get( Values::class )->update( $name, $clean, $ref );

		\WP_CLI::success( sprintf( 'Updated "%s" on %s.', $name, (string) $ref ) );
	}

	/**
	 * Delete a field value.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Field name.
	 *
	 * <object>
	 * : Object identifier.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb delete byline 12
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	public function delete( $args ): void {
		$ref = $this->resolve( $args[1] );

		if ( ! $this->container->get( Values::class )->delete( $args[0], $ref ) ) {
			\WP_CLI::warning( sprintf( 'Nothing stored for "%s" on %s.', $args[0], (string) $ref ) );

			return;
		}

		\WP_CLI::success( sprintf( 'Deleted "%s" from %s.', $args[0], (string) $ref ) );
	}

	/**
	 * Export this site's field configuration as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [<key>...]
	 * : Field group keys to export. Omit for all of them.
	 *
	 * [--only=<sections>]
	 * : Comma separated sections to include. Any of field_groups, post_types,
	 * taxonomies, blocks, forms, options_pages. Everything by default.
	 *
	 * [--file=<path>]
	 * : Write to a file instead of standard output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb export > site.json
	 *     wp wpcmb export --only=post_types,taxonomies --file=types.json
	 *     wp wpcmb export group_abc123 --only=field_groups
	 *
	 * @param array<int, string>    $args       Field group keys.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function export( $args, $assoc_args ): void {
		$sections = isset( $assoc_args['only'] )
			? array_map( 'trim', explode( ',', (string) $assoc_args['only'] ) )
			: null;

		$known = array_merge( array( 'field_groups' ), array_keys( Portability::sections() ) );

		if ( null !== $sections ) {
			$unknown = array_diff( $sections, $known );

			if ( array() !== $unknown ) {
				\WP_CLI::error( sprintf( 'Unknown section: %s. Known: %s.', implode( ', ', $unknown ), implode( ', ', $known ) ) );
			}
		}

		$payload = $this->container->get( Portability::class )->export( $sections, array() !== $args ? $args : null );
		$counts  = array_filter( Portability::summarise( $payload ) );

		if ( array() === $counts ) {
			\WP_CLI::error( 'Nothing matched.' );
		}

		$json = (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$file = (string) ( $assoc_args['file'] ?? '' );

		if ( '' === $file ) {
			\WP_CLI::line( $json );

			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A path the operator gave us, on their own machine.
		if ( false === file_put_contents( $file, $json ) ) {
			\WP_CLI::error( sprintf( 'Could not write to %s.', $file ) );
		}

		\WP_CLI::success( sprintf( 'Exported %s to %s.', self::tally( $counts ), $file ) );
	}
	/**
	 * Import a JSON export.
	 *
	 * Field groups, post types, taxonomies, block types, forms and options
	 * pages, whichever the file holds. Anything already here is matched and
	 * updated in place; anything new is created inactive, so an import cannot
	 * quietly start changing a production site before somebody has looked at
	 * it.
	 *
	 * Exports from any version of this plugin are accepted, including the bare
	 * list of field groups older versions wrote.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a JSON export.
	 *
	 * [--activate]
	 * : Publish whatever is newly created instead of leaving it inactive.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb import site.json
	 *     wp wpcmb import site.json --activate
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function import( $args, $assoc_args ): void {
		$file = $args[0];

		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local path the operator gave us.
		$payload = Portability::normalise( json_decode( (string) file_get_contents( $file ), true ) );

		if ( null === $payload ) {
			\WP_CLI::error( sprintf( '%s is not an export from this plugin.', $file ) );
		}

		$written = array_filter(
			$this->container->get( Portability::class )->import(
				$payload,
				isset( $assoc_args['activate'] ) ? 'publish' : 'draft'
			)
		);

		if ( array() === $written ) {
			\WP_CLI::error( 'Nothing was imported.' );
		}

		\WP_CLI::success( sprintf( 'Imported %s.', self::tally( $written ) ) );
	}

	/**
	 * Section counts as something a human reads.
	 *
	 * @param array<string, int> $counts Counts, keyed by section.
	 */
	private static function tally( array $counts ): string {
		$parts = array();

		foreach ( $counts as $section => $count ) {
			$parts[] = sprintf( '%d %s', $count, str_replace( '_', ' ', (string) $section ) );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Resolve an object identifier, or stop.
	 *
	 * @param string $identifier Object identifier.
	 */
	private function resolve( string $identifier ): ObjectRef {
		$ref = ObjectRef::from( $identifier );

		if ( ! $ref->is_valid() ) {
			\WP_CLI::error( sprintf( 'Could not resolve "%s" to an object.', $identifier ) );
		}

		return $ref;
	}
}
