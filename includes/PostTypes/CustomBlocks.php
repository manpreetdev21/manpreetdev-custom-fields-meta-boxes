<?php
/**
 * Block types a site registers through the plugin.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\PostTypes;

use WPCMB\Abstracts\RecordType;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Block types defined on the Block Types screen.
 *
 * A block record is a field group plus the handful of things a block needs
 * that a field group does not: a name, an icon, a category, a render mode.
 * It does not copy the fields — it points at the group that owns them, so
 * editing the group changes the block and there is one place to look.
 *
 * The block settings used to live on the field group itself. Those are still
 * read for any group no record has replaced, so a site that upgrades keeps
 * every block it already had, in content already published.
 */
final class CustomBlocks extends RecordType {

	/**
	 * Post type storing these records.
	 */
	public const POST_TYPE = 'wpcmb_block';

	/**
	 * The post type that stores these records.
	 */
	public function post_type(): string {
		return self::POST_TYPE;
	}

	/**
	 * Names for the labels and headings.
	 *
	 * @return array{singular: string, plural: string}
	 */
	public function names(): array {
		return array(
			'singular' => __( 'Block Type', 'metafields-custom-fields' ),
			'plural'   => __( 'Block Types', 'metafields-custom-fields' ),
		);
	}

	/**
	 * Everything a record holds, before anybody has filled it in.
	 *
	 * The keys match the `block_*` group settings they replace, minus the
	 * prefix, so the migration is a rename and nothing has to be reinterpreted.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'field_group'  => '',
			'name'         => '',
			'icon'         => '',
			'category'     => '',
			'description'  => '',
			'keywords'     => '',
			'mode'         => 'auto',
			'supports'     => array(),
			'inner_blocks' => false,
		);
	}

	/**
	 * The block features a record can switch on.
	 *
	 * @return array<string, string>
	 */
	public static function features(): array {
		return array(
			'align'      => __( 'Alignment', 'metafields-custom-fields' ),
			'anchor'     => __( 'HTML anchor', 'metafields-custom-fields' ),
			'className'  => __( 'Additional CSS class', 'metafields-custom-fields' ),
			'color'      => __( 'Colour settings', 'metafields-custom-fields' ),
			'spacing'    => __( 'Spacing controls', 'metafields-custom-fields' ),
			'typography' => __( 'Typography controls', 'metafields-custom-fields' ),
		);
	}

	/**
	 * Clean a submitted configuration.
	 *
	 * @param array<string, mixed> $raw Untrusted configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize( array $raw ): array {
		$supports = array();

		foreach ( (array) ( $raw['supports'] ?? array() ) as $feature ) {
			if ( isset( self::features()[ (string) $feature ] ) ) {
				$supports[] = (string) $feature;
			}
		}

		return array(
			// The block name becomes part of a block's identity in saved
			// content, so it is normalised the same way everything else is
			// and never rewritten afterwards.
			// A group key is already key-shaped — `group_` and hex — so it is
			// cleaned rather than normalised: turning spaces into underscores
			// would invent a key that matches no group.
			'field_group'  => sanitize_key( (string) ( $raw['field_group'] ?? '' ) ),
			'name'         => FieldGroup::sanitize_field_name( (string) ( $raw['name'] ?? '' ) ),
			'icon'         => sanitize_text_field( (string) ( $raw['icon'] ?? '' ) ),
			'category'     => FieldGroup::sanitize_field_name( (string) ( $raw['category'] ?? '' ) ),
			'description'  => sanitize_text_field( (string) ( $raw['description'] ?? '' ) ),
			'keywords'     => sanitize_text_field( (string) ( $raw['keywords'] ?? '' ) ),
			'mode'         => in_array( (string) ( $raw['mode'] ?? '' ), array( 'auto', 'preview', 'edit' ), true ) ? (string) $raw['mode'] : 'auto',
			'supports'     => array_values( array_unique( $supports ) ),
			'inner_blocks' => ! empty( $raw['inner_blocks'] ),
		);
	}

	/**
	 * Why a record cannot register a block, or an empty string.
	 *
	 * @param array<string, mixed> $config  Cleaned configuration.
	 * @param int                  $post_id The record being saved.
	 */
	public function rejection( array $config, int $post_id = 0 ): string {
		$name = (string) ( $config['name'] ?? '' );

		if ( '' === $name ) {
			return __( 'A block needs a name.', 'metafields-custom-fields' );
		}

		if ( '' === (string) ( $config['field_group'] ?? '' ) ) {
			return __( 'A block needs a field group to take its fields from.', 'metafields-custom-fields' );
		}

		foreach ( $this->records( true ) as $id => $record ) {
			if ( $id !== $post_id && ( $record['name'] ?? '' ) === $name ) {
				return sprintf(
					/* translators: %s: the block name. */
					__( 'Another record already registers the block "%s".', 'metafields-custom-fields' ),
					$name
				);
			}
		}

		return '';
	}

	/**
	 * The field group a record points at, if it still exists.
	 *
	 * @param array<string, mixed> $record Stored configuration.
	 */
	public function group( array $record ): ?FieldGroup {
		$key = (string) ( $record['field_group'] ?? '' );

		if ( '' === $key ) {
			return null;
		}

		return $this->container->get( Repository::class )->get( $key );
	}

	/**
	 * A field group carrying this record's block settings.
	 *
	 * The block registry has always read `block_*` settings off a group, and
	 * everything downstream of it — attributes, templates, the editor form,
	 * value resolution — is built on that. A record therefore hands back the
	 * group it points at with its own settings written over the top, rather
	 * than a second registration path that would have to be kept in step.
	 *
	 * @param array<string, mixed> $record Stored configuration.
	 */
	public function as_group( array $record ): ?FieldGroup {
		$group = $this->group( $record );

		if ( null === $group ) {
			return null;
		}

		$record = array_merge( self::defaults(), $record );

		$settings = $group->settings;

		$settings['block_enabled']      = true;
		$settings['block_name']         = (string) $record['name'];
		$settings['block_icon']         = (string) $record['icon'];
		$settings['block_category']     = (string) $record['category'];
		$settings['block_description']  = (string) $record['description'];
		$settings['block_keywords']     = (string) $record['keywords'];
		$settings['block_mode']         = (string) $record['mode'];
		$settings['block_supports']     = (array) $record['supports'];
		$settings['block_inner_blocks'] = (bool) $record['inner_blocks'];

		$config = $group->to_array();

		$config['settings'] = $settings;

		// The record's own title is the block's title, when it has one.
		if ( '' !== (string) ( $record['title'] ?? '' ) ) {
			$config['title'] = (string) $record['title'];
		}

		return FieldGroup::from_array( $config );
	}

	/**
	 * Every block a record defines, as groups the registry can read.
	 *
	 * @return array<string, FieldGroup> Keyed by block name, without the namespace.
	 */
	public function as_groups(): array {
		$groups = array();

		foreach ( $this->records() as $record ) {
			$name  = (string) ( $record['name'] ?? '' );
			$group = '' === $name ? null : $this->as_group( $record );

			if ( null !== $group ) {
				$groups[ $name ] = $group;
			}
		}

		return $groups;
	}

	/**
	 * The field group keys a record points at.
	 *
	 * Read by the registry, so a group whose block settings have been
	 * migrated into a record is not registered twice from two sources.
	 *
	 * @return array<int, string>
	 */
	public function claimed_groups(): array {
		$keys = array();

		foreach ( $this->records( true ) as $record ) {
			$key = (string) ( $record['field_group'] ?? '' );

			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		return array_values( array_unique( $keys ) );
	}
}
