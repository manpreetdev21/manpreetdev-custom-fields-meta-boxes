<?php
/**
 * Front-end forms a site defines through the plugin.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\PostTypes;

use WPCMB\Abstracts\RecordType;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;
use WPCMB\Frontend\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Forms defined on the Forms screen.
 *
 * A form was previously described entirely by its shortcode: every option —
 * what it creates, who may submit it, where it goes afterwards — was an
 * attribute somebody had to remember and retype, and two forms on two pages
 * could disagree about the same field group. A record is that configuration,
 * named and in one place, rendered with `[wpcmb_form id="..."]`.
 *
 * `[wpcmb_form group="..."]` still works and still means exactly what it did.
 * Those shortcodes are in published content, and the configuration they carry
 * is signed with the site's salts; nothing about them changes.
 */
final class CustomForms extends RecordType {

	/**
	 * Post type storing these records.
	 */
	public const POST_TYPE = 'wpcmb_form';

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
			'singular' => __( 'Form', 'metafields-custom-fields' ),
			'plural'   => __( 'Forms', 'metafields-custom-fields' ),
		);
	}

	/**
	 * Everything a record holds, before anybody has filled it in.
	 *
	 * The keys are the shortcode's own attributes, so a record is the same
	 * configuration in a different place and `Form::render()` needs no new
	 * vocabulary.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$defaults = Form::defaults();

		// `group` comes from the record's own field group setting, and `ajax`
		// has never been worth a switch on a screen.
		unset( $defaults['group'] );

		return array_merge( array( 'field_group' => '' ), $defaults );
	}

	/**
	 * Clean a submitted configuration.
	 *
	 * @param array<string, mixed> $raw Untrusted configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize( array $raw ): array {
		$clean = array(
			'field_group'  => sanitize_key( (string) ( $raw['field_group'] ?? '' ) ),
			'action'       => in_array( (string) ( $raw['action'] ?? '' ), array( 'post', 'values', 'user' ), true ) ? (string) $raw['action'] : 'post',
			'post_type'    => sanitize_key( (string) ( $raw['post_type'] ?? '' ) ),

			/*
			 * `publish` is honoured only for a post type nobody can view, and
			 * Submission::status_for() is what enforces that — this is the
			 * request, not the decision.
			 */
			'post_status'  => in_array( (string) ( $raw['post_status'] ?? '' ), array( 'publish', 'draft', 'pending' ), true ) ? (string) $raw['post_status'] : 'publish',
			'post_title'   => ! empty( $raw['post_title'] ) ? '1' : '0',
			'object'       => sanitize_text_field( (string) ( $raw['object'] ?? 'new' ) ),
			'guests'       => ! empty( $raw['guests'] ) ? '1' : '0',
			'submit_label' => sanitize_text_field( (string) ( $raw['submit_label'] ?? '' ) ),
			'message'      => sanitize_text_field( (string) ( $raw['message'] ?? '' ) ),

			// A redirect is a URL this site will send somebody to, so it is
			// held to the same rule as any other redirect target.
			'redirect'     => sanitize_url( (string) ( $raw['redirect'] ?? '' ) ),
			'email'        => sanitize_email( (string) ( $raw['email'] ?? '' ) ),
			'ajax'         => ! empty( $raw['ajax'] ) ? '1' : '0',
		);

		return $clean;
	}

	/**
	 * Why a record cannot render a form, or an empty string.
	 *
	 * @param array<string, mixed> $config Cleaned configuration.
	 */
	public function rejection( array $config ): string {
		if ( '' === (string) ( $config['field_group'] ?? '' ) ) {
			return __( 'A form needs a field group to take its fields from.', 'metafields-custom-fields' );
		}

		if ( null === $this->container->get( Repository::class )->get( (string) $config['field_group'] ) ) {
			return __( 'That field group no longer exists.', 'metafields-custom-fields' );
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

		return '' === $key ? null : $this->container->get( Repository::class )->get( $key );
	}

	/**
	 * One record as shortcode attributes.
	 *
	 * What `Form::render()` already takes, so a record and a shortcode go
	 * down one path and there is one place where a form is built.
	 *
	 * @param array<string, mixed> $record Stored configuration.
	 *
	 * @return array<string, string>
	 */
	public function attributes( array $record ): array {
		$record = array_merge( self::defaults(), $record );

		$attributes = array( 'group' => (string) $record['field_group'] );

		foreach ( array_keys( Form::defaults() ) as $key ) {
			if ( 'group' === $key || ! array_key_exists( $key, $record ) ) {
				continue;
			}

			$attributes[ $key ] = (string) $record[ $key ];
		}

		return $attributes;
	}

	/**
	 * What makes one form distinguishable.
	 *
	 * A form has no key of its own — nothing about it has to be unique, and
	 * two forms can legitimately render the same field group with different
	 * settings. The group it draws plus the name somebody gave it is the most
	 * stable thing available, so re-importing an export updates rather than
	 * duplicating, while two genuinely different forms stay two records.
	 *
	 * @param array<string, mixed> $record Stored configuration.
	 */
	public function identity( array $record ): string {
		$group = (string) ( $record['field_group'] ?? '' );

		return '' === $group ? '' : $group . '|' . (string) ( $record['title'] ?? '' );
	}

	/**
	 * A record by its post id, or null.
	 *
	 * @param int $post_id Record post id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function by_id( int $post_id ): ?array {
		$records = $this->records();

		return $records[ $post_id ] ?? null;
	}
}
