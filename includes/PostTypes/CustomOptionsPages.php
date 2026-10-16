<?php
/**
 * Options pages a site defines through the plugin.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\PostTypes;

use WPCMB\Abstracts\RecordType;
use WPCMB\Admin\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Options pages defined on the Options Pages screen.
 *
 * An options page used to exist because a field group's location rule named
 * one. That is a neat trick — the page appears the moment somebody needs it —
 * but it means the page has no settings of its own beyond what one group
 * carries, it cannot be a sub-page of another options page, and nothing lists
 * the pages a site has.
 *
 * A record fixes all three. Rule-created pages keep working: a page named by
 * a rule and not described by a record is still built exactly as before.
 */
final class CustomOptionsPages extends RecordType {

	/**
	 * Post type storing these records.
	 */
	public const POST_TYPE = 'wpcmb_options_page';

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
			'singular' => __( 'Options Page', 'metafields-custom-fields' ),
			'plural'   => __( 'Options Pages', 'metafields-custom-fields' ),
		);
	}

	/**
	 * Everything a record holds, before anybody has filled it in.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'slug'       => '',
			'menu_title' => '',
			'page_title' => '',
			'parent'     => '',
			'capability' => 'manage_options',
			'icon'       => '',
			'position'   => 0,
			'redirect'   => false,
		);
	}

	/**
	 * Where an options page can be put.
	 *
	 * The plugin's own menu, the WordPress menus a settings screen plausibly
	 * belongs under, and — for a record — another options page, which is the
	 * thing rules could never express.
	 *
	 * @param array<string, string> $pages Other options pages, keyed by slug.
	 *
	 * @return array<string, string>
	 */
	public static function parents( array $pages = array() ): array {
		$parents = array(
			''                    => __( 'Its own top-level menu', 'metafields-custom-fields' ),
			Menu::SLUG            => __( 'Under Meta Boxes', 'metafields-custom-fields' ),
			'options-general.php' => __( 'Under Settings', 'metafields-custom-fields' ),
			'tools.php'           => __( 'Under Tools', 'metafields-custom-fields' ),
			'themes.php'          => __( 'Under Appearance', 'metafields-custom-fields' ),
			'edit.php'            => __( 'Under Posts', 'metafields-custom-fields' ),
			'upload.php'          => __( 'Under Media', 'metafields-custom-fields' ),
			'users.php'           => __( 'Under Users', 'metafields-custom-fields' ),
		);

		foreach ( $pages as $slug => $label ) {
			/* translators: %s: another options page's title. */
			$parents[ 'wpcmb-options-' . $slug ] = sprintf( __( 'Under "%s"', 'metafields-custom-fields' ), $label );
		}

		return $parents;
	}

	/**
	 * Clean a submitted configuration.
	 *
	 * @param array<string, mixed> $raw Untrusted configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize( array $raw ): array {
		$capability = sanitize_key( (string) ( $raw['capability'] ?? '' ) );

		/*
		 * A parent is a menu file, a plugin menu slug, or another options
		 * page. Anything else would be a menu nobody can prove is there, and
		 * add_submenu_page() would silently drop the page.
		 */
		$parent = (string) ( $raw['parent'] ?? '' );

		if ( '' !== $parent && ! isset( self::parents()[ $parent ] ) && ! str_starts_with( $parent, 'wpcmb-options-' ) ) {
			$parent = '';
		}

		return array(
			// The slug is where every value on the page is stored, under
			// `wpcmb_{slug}_{field}`, so changing it orphans the lot.
			'slug'       => sanitize_title( (string) ( $raw['slug'] ?? '' ) ),
			'menu_title' => sanitize_text_field( (string) ( $raw['menu_title'] ?? '' ) ),
			'page_title' => sanitize_text_field( (string) ( $raw['page_title'] ?? '' ) ),
			'parent'     => 'wpcmb-options-' === $parent ? '' : $parent,
			'capability' => '' !== $capability ? $capability : 'manage_options',
			'icon'       => sanitize_text_field( (string) ( $raw['icon'] ?? '' ) ),
			'position'   => (int) ( $raw['position'] ?? 0 ),
			'redirect'   => ! empty( $raw['redirect'] ),
		);
	}

	/**
	 * Why a record cannot build a page, or an empty string.
	 *
	 * @param array<string, mixed> $config  Cleaned configuration.
	 * @param int                  $post_id The record being saved.
	 */
	public function rejection( array $config, int $post_id = 0 ): string {
		$slug = (string) ( $config['slug'] ?? '' );

		if ( '' === $slug ) {
			return __( 'An options page needs a slug.', 'metafields-custom-fields' );
		}

		foreach ( $this->records( true ) as $id => $record ) {
			if ( $id !== $post_id && ( $record['slug'] ?? '' ) === $slug ) {
				return sprintf(
					/* translators: %s: the options page slug. */
					__( 'Another record already builds the page "%s".', 'metafields-custom-fields' ),
					$slug
				);
			}
		}

		// A page cannot be its own parent, which is an easy thing to do from
		// a dropdown that lists every other page.
		if ( (string) ( $config['parent'] ?? '' ) === 'wpcmb-options-' . $slug ) {
			return __( 'A page cannot be placed inside itself.', 'metafields-custom-fields' );
		}

		return '';
	}

	/**
	 * What makes one options page distinguishable: its slug.
	 *
	 * @param array<string, mixed> $record Stored configuration.
	 */
	public function identity( array $record ): string {
		return (string) ( $record['slug'] ?? '' );
	}

	/**
	 * Every page a record defines, in the shape OptionsPages::pages() uses.
	 *
	 * @return array<string, array{title: string, order: int, capability: string, icon: string, parent: string, page_title: string, record: int}>
	 */
	public function pages(): array {
		$pages = array();

		foreach ( $this->records() as $post_id => $record ) {
			$slug = (string) ( $record['slug'] ?? '' );

			if ( '' === $slug ) {
				continue;
			}

			$title = (string) ( $record['menu_title'] ?? '' );

			if ( '' === $title ) {
				$title = (string) ( $record['title'] ?? $slug );
			}

			$pages[ $slug ] = array(
				'title'      => $title,
				'order'      => (int) ( $record['position'] ?? 0 ),
				'capability' => (string) ( $record['capability'] ?? 'manage_options' ),
				'icon'       => (string) ( $record['icon'] ?? '' ),
				'parent'     => (string) ( $record['parent'] ?? '' ),
				'page_title' => (string) ( $record['page_title'] ?? '' ),
				'record'     => $post_id,
			);
		}

		return $pages;
	}

	/**
	 * The slugs records describe, including drafts.
	 *
	 * Read so a page a record owns is not also built from a location rule,
	 * which would register the same menu slug twice.
	 *
	 * @return array<int, string>
	 */
	public function claimed_slugs(): array {
		$slugs = array();

		foreach ( $this->records( true ) as $record ) {
			$slug = (string) ( $record['slug'] ?? '' );

			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}
}
