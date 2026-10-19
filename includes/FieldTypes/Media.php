<?php
/**
 * Media field types.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Fields that reference attachments: file, image, gallery, video and audio.
 *
 * All of them store attachment ids and open the WordPress media library,
 * which already provides upload, drag and drop, multi-select, sorting, alt
 * text, captions and image editing. Reimplementing any of that would produce
 * a second, worse media library that does not share the user's uploads.
 *
 * The markup is a hidden input holding a comma-separated id list plus a
 * preview list; the script upgrades it to a media picker. Without JavaScript
 * the ids remain editable and the value survives a save.
 */
final class Media extends FieldType {

	/**
	 * Option holding the upload types the field editor offers.
	 */
	public const TYPES_OPTION = 'wpcmb_upload_types';

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array(
			'file'    => __( 'File', 'manpreetdev-custom-fields-meta-boxes' ),
			'image'   => __( 'Image', 'manpreetdev-custom-fields-meta-boxes' ),
			'gallery' => __( 'Gallery', 'manpreetdev-custom-fields-meta-boxes' ),
			'video'   => __( 'Video', 'manpreetdev-custom-fields-meta-boxes' ),
			'audio'   => __( 'Audio', 'manpreetdev-custom-fields-meta-boxes' ),
		);
	}

	/**
	 * Editor group label.
	 */
	public function group_label(): string {
		return __( 'Media', 'manpreetdev-custom-fields-meta-boxes' );
	}

	/**
	 * The Dashicon shown beside a field of this type.
	 *
	 * @param string $type The specific type.
	 */
	public function icon( string $type ): string {
		$icons = array(
			'audio'   => 'dashicons-format-audio',
			'file'    => 'dashicons-media-default',
			'gallery' => 'dashicons-images-alt2',
			'image'   => 'dashicons-format-image',
			'video'   => 'dashicons-format-video',
		);

		return $icons[ $type ] ?? 'dashicons-media-default';
	}

	/**
	 * Render the picker.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$type     = (string) ( $field['type'] ?? 'file' );
		$multiple = 'gallery' === $type;
		$ids      = $this->ids( $value );

		/*
		 * The picker is told what to offer; the validator decides what is
		 * allowed. Both read the same settings, because a filtered library
		 * is a convenience and the check on save is the rule.
		 */
		printf(
			'<div class="wpcmb-media" data-wpcmb-media="%s" data-wpcmb-multiple="%s" data-wpcmb-mime="%s" data-wpcmb-library="%s" data-wpcmb-preview="%s">',
			esc_attr( $type ),
			esc_attr( $multiple ? '1' : '0' ),
			esc_attr( $this->mime_filter( $field, $type ) ),
			esc_attr( 'uploaded' === (string) $this->setting( $field, 'library', 'all' ) ? 'uploaded' : 'all' ),
			esc_attr( (string) $this->setting( $field, 'preview_size', 'thumbnail' ) )
		);

		printf(
			'<input type="hidden" class="wpcmb-media__ids" name="%s" id="%s" value="%s"%s />',
			esc_attr( $input_name ),
			esc_attr( $input_id ),
			esc_attr( implode( ',', $ids ) ),
			! empty( $field['required'] ) ? ' required' : ''
		);

		echo '<ul class="wpcmb-media__list">';

		$preview = (string) $this->setting( $field, 'preview_size', 'thumbnail' );

		foreach ( $ids as $id ) {
			$this->render_item( $id, $preview );
		}

		echo '</ul>';

		printf(
			'<p class="wpcmb-media__actions">
				<button type="button" class="wpcmb-btn wpcmb-media__select">%s</button>
				<button type="button" class="wpcmb-btn wpcmb-btn--quiet wpcmb-btn--danger wpcmb-media__clear">%s</button>
			</p>
			</div>',
			esc_html( $multiple ? __( 'Add media', 'manpreetdev-custom-fields-meta-boxes' ) : __( 'Select media', 'manpreetdev-custom-fields-meta-boxes' ) ),
			esc_html( __( 'Clear', 'manpreetdev-custom-fields-meta-boxes' ) )
		);
	}

	/**
	 * Render one attachment preview.
	 *
	 * @param int    $id   Attachment id.
	 * @param string $size Registered image size for the preview.
	 */
	private function render_item( int $id, string $size = 'thumbnail' ): void {
		$title = get_the_title( $id );
		$thumb = wp_get_attachment_image( $id, '' !== $size ? $size : 'thumbnail', true, array( 'loading' => 'lazy' ) );

		printf(
			'<li class="wpcmb-media__item" data-wpcmb-id="%s">%s<span class="wpcmb-media__title">%s</span>
			<button type="button" class="wpcmb-media__remove" aria-label="%s">&times;</button></li>',
			esc_attr( (string) $id ),
			wp_kses_post( $thumb ),
			esc_html( '' !== $title ? $title : (string) $id ),
			esc_attr__( 'Remove', 'manpreetdev-custom-fields-meta-boxes' )
		);
	}

	/**
	 * Keep only ids that refer to attachments the current user may see.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return int|array<int, int>|string
	 */
	public function sanitize( mixed $value, array $field ): int|array|string {
		$multiple = 'gallery' === ( $field['type'] ?? '' );

		$ids = array_values(
			array_filter(
				$this->ids( $value ),
				static fn( int $id ): bool => 'attachment' === get_post_type( $id )
			)
		);

		if ( $multiple ) {
			return $ids;
		}

		return $ids[0] ?? '';
	}

	/**
	 * Turn stored ids into whatever the field is configured to return.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		$multiple = 'gallery' === ( $field['type'] ?? '' );
		$format   = (string) $this->setting( $field, 'return_format', 'id' );

		/*
		 * An attachment inherits the status of whatever it is attached to, so
		 * one uploaded to a draft is not public — and the object carries its
		 * caption, description and parent id. A file in the library with no
		 * parent reads as published, which is the ordinary case and is
		 * unaffected.
		 *
		 * This is not a seal on the file itself: a web server hands that over
		 * by URL whatever WordPress thinks. It keeps the metadata off a page
		 * the reader should not be seeing.
		 */
		$ids = array_values(
			array_filter(
				$this->ids( $value ),
				static fn( int $id ): bool => current_user_can( 'read_post', $id )
			)
		);

		$mapped = array_map(
			fn( int $id ) => match ( $format ) {
				'url'    => wp_get_attachment_url( $id ),
				'array'  => $this->attachment_array( $id ),
				'object' => get_post( $id ),
				default  => $id,
			},
			$ids
		);

		if ( $multiple ) {
			return $mapped;
		}

		return $mapped[0] ?? ( 'id' === $format ? 0 : null );
	}

	/**
	 * A plain array describing an attachment.
	 *
	 * @param int $id Attachment id.
	 *
	 * @return array<string, mixed>
	 */
	private function attachment_array( int $id ): array {
		return array(
			'id'      => $id,
			'url'     => wp_get_attachment_url( $id ),
			'alt'     => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'   => get_the_title( $id ),
			'caption' => wp_get_attachment_caption( $id ),
			'mime'    => get_post_mime_type( $id ),
			'sizes'   => wp_get_attachment_metadata( $id )['sizes'] ?? array(),
		);
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		$schema = array(
			'library'       => array(
				'label'   => __( 'Library', 'manpreetdev-custom-fields-meta-boxes' ),
				'type'    => 'select',
				'choices' => array(
					'all'      => __( 'Everything in the media library', 'manpreetdev-custom-fields-meta-boxes' ),
					'uploaded' => __( 'Only what was uploaded to this item', 'manpreetdev-custom-fields-meta-boxes' ),
				),
			),
			'mime_preset'   => array(
				'label'   => __( 'Upload type', 'manpreetdev-custom-fields-meta-boxes' ),
				'type'    => 'select',
				'tab'     => 'validation',
				'choices' => array( '' => __( 'Whatever this field is for', 'manpreetdev-custom-fields-meta-boxes' ) ) + self::upload_types(),
				'help'    => __( 'Chosen from the list on the plugin\'s Settings screen. An image field only ever accepts images; this narrows it further.', 'manpreetdev-custom-fields-meta-boxes' ),
			),
			'mime_types'    => array(
				'label' => __( 'Allowed file types', 'manpreetdev-custom-fields-meta-boxes' ),
				'type'  => 'text',
				'tab'   => 'validation',
				'help'  => __( 'For something the list above does not cover. Comma separated extensions or mime types, e.g. pdf, docx or image/png. Overrides the upload type.', 'manpreetdev-custom-fields-meta-boxes' ),
			),
			'min_size'      => array(
				'label' => __( 'Minimum file size', 'manpreetdev-custom-fields-meta-boxes' ),
				'type'  => 'text',
				'tab'   => 'validation',
				'help'  => __( 'Kilobytes when bare; 2MB and 500 KB also work.', 'manpreetdev-custom-fields-meta-boxes' ),
			),
			'max_size'      => array(
				'label' => __( 'Maximum file size', 'manpreetdev-custom-fields-meta-boxes' ),
				'type'  => 'text',
				'tab'   => 'validation',
			),
			'preview_size'  => array(
				'label' => __( 'Preview size', 'manpreetdev-custom-fields-meta-boxes' ),
				'type'  => 'text',
				'tab'   => 'appearance',
				'help'  => __( 'A registered image size. Defaults to thumbnail.', 'manpreetdev-custom-fields-meta-boxes' ),
			),
			'return_format' => array(
				'label'   => __( 'Return format', 'manpreetdev-custom-fields-meta-boxes' ),
				'type'    => 'select',
				'choices' => array(
					'id'     => __( 'Attachment ID', 'manpreetdev-custom-fields-meta-boxes' ),
					'url'    => __( 'URL', 'manpreetdev-custom-fields-meta-boxes' ),
					'array'  => __( 'Array', 'manpreetdev-custom-fields-meta-boxes' ),
					'object' => __( 'WP_Post object', 'manpreetdev-custom-fields-meta-boxes' ),
				),
			),
		);

		if ( 'gallery' === $type ) {
			$schema['min'] = array(
				'label' => __( 'Minimum items', 'manpreetdev-custom-fields-meta-boxes' ),
				'type'  => 'number',
			);
			$schema['max'] = array(
				'label' => __( 'Maximum items', 'manpreetdev-custom-fields-meta-boxes' ),
				'type'  => 'number',
			);
		}

		// Dimensions only mean something for a type that holds an image.
		if ( in_array( $type, array( 'image', 'gallery' ), true ) ) {
			$dimensions = array(
				'min_width'  => __( 'Minimum width (px)', 'manpreetdev-custom-fields-meta-boxes' ),
				'max_width'  => __( 'Maximum width (px)', 'manpreetdev-custom-fields-meta-boxes' ),
				'min_height' => __( 'Minimum height (px)', 'manpreetdev-custom-fields-meta-boxes' ),
				'max_height' => __( 'Maximum height (px)', 'manpreetdev-custom-fields-meta-boxes' ),
			);

			foreach ( $dimensions as $name => $label ) {
				$schema[ $name ] = array(
					'label' => $label,
					'type'  => 'number',
					'tab'   => 'validation',
				);
			}
		}

		return $schema;
	}

	/**
	 * The media library filter for a type.
	 *
	 * @param string $type Type name.
	 */
	private function mime( string $type ): string {
		return match ( $type ) {
			'image', 'gallery' => 'image',
			'video'            => 'video',
			'audio'            => 'audio',
			default            => '',
		};
	}

	/**
	 * What the picker should offer: the configured types, or the type's own.
	 *
	 * A file field restricted to `pdf,doc` is more useful than one restricted
	 * to everything, and the type's own family is the right default — an image
	 * field has no business offering a zip.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param string               $type  Field type.
	 */
	private function mime_filter( array $field, string $type ): string {
		$configured = $this->mime_types( $field );

		return array() === $configured ? $this->mime( $type ) : implode( ',', $configured );
	}

	/**
	 * The file types this field will actually accept.
	 *
	 * The configured list, or — when there is none — the type's own family,
	 * because an image field means an image whether or not anybody filled in
	 * the setting. The picker was already filtered this way; the check on save
	 * was not, so an image field accepted a PDF from anything that did not go
	 * through the picker: a crafted request, a REST write, or
	 * `wpcmb_update_field()`.
	 *
	 * A file field has no family and stays open, which is what a file field is.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<int, string>
	 */
	private function allowed_types( array $field ): array {
		$configured = $this->mime_types( $field );

		if ( array() !== $configured ) {
			return $configured;
		}

		// A preset chosen from the site's list, which is the same thing said
		// by picking rather than typing.
		$preset = $this->split_types( (string) $this->setting( $field, 'mime_preset', '' ) );

		if ( array() !== $preset ) {
			return $preset;
		}

		$family = $this->mime( (string) ( $field['type'] ?? '' ) );

		return '' === $family ? array() : array( $family );
	}

	/**
	 * The configured extensions or mime types, cleaned.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<int, string>
	 */
	private function mime_types( array $field ): array {
		return $this->split_types( (string) $this->setting( $field, 'mime_types', '' ) );
	}

	/**
	 * Split a comma-separated list of extensions or mime types.
	 *
	 * @param string $raw Configured list.
	 *
	 * @return array<int, string>
	 */
	private function split_types( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}

		$types = array_map(
			// `.PDF` and `pdf` and ` PDF ` are all the same answer.
			static fn( string $part ): string => strtolower( ltrim( trim( $part ), '.' ) ),
			explode( ',', $raw )
		);

		return array_values( array_filter( $types ) );
	}

	/**
	 * The upload types a field may be restricted to, as the editor offers them.
	 *
	 * Site-wide, because "what this site accepts" is one answer, not one per
	 * field: a site that takes PDFs and web images wants that list in front of
	 * whoever builds the next field group, not retyped from memory.
	 *
	 * @return array<string, string> Comma-separated extensions, keyed to their label.
	 */
	public static function upload_types(): array {
		$configured = (string) get_option( self::TYPES_OPTION, '' );

		$presets = array();

		foreach ( explode( "\n", $configured ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			// `jpg, png : Web images` — the same `value : Label` the choices
			// field already uses, so there is one syntax to learn.
			$parts = explode( ':', $line, 2 );
			$value = trim( $parts[0] );

			if ( '' === $value ) {
				continue;
			}

			$presets[ $value ] = '' !== trim( $parts[1] ?? '' ) ? trim( $parts[1] ) : $value;
		}

		if ( array() === $presets ) {
			$presets = self::default_upload_types();
		}

		/**
		 * Filters the upload types the field editor offers.
		 *
		 * @since 1.9.2
		 *
		 * @param array<string, string> $presets Comma-separated extensions, keyed to their label.
		 */
		return (array) apply_filters( 'wpcmb/media/upload_types', $presets );
	}

	/**
	 * The list a site gets before anybody edits it.
	 *
	 * Families first, because "any image" is the common answer, then the
	 * groupings people actually ask for.
	 *
	 * @return array<string, string>
	 */
	public static function default_upload_types(): array {
		return array(
			'image'                     => __( 'Any image', 'manpreetdev-custom-fields-meta-boxes' ),
			'jpg, jpeg, png, webp, gif' => __( 'Web images (JPG, PNG, WebP, GIF)', 'manpreetdev-custom-fields-meta-boxes' ),
			'svg'                       => __( 'SVG', 'manpreetdev-custom-fields-meta-boxes' ),
			'video'                     => __( 'Any video', 'manpreetdev-custom-fields-meta-boxes' ),
			'audio'                     => __( 'Any audio', 'manpreetdev-custom-fields-meta-boxes' ),
			'pdf'                       => __( 'PDF', 'manpreetdev-custom-fields-meta-boxes' ),
			'pdf, doc, docx, odt, rtf'  => __( 'Documents', 'manpreetdev-custom-fields-meta-boxes' ),
			'csv, xls, xlsx, ods'       => __( 'Spreadsheets', 'manpreetdev-custom-fields-meta-boxes' ),
			'zip, gz, rar'              => __( 'Archives', 'manpreetdev-custom-fields-meta-boxes' ),
		);
	}

	/**
	 * Reject any attachment that breaks this field's limits.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 */
	public function validate( mixed $value, array $field ): string {
		foreach ( $this->ids( $value ) as $id ) {
			$rejection = $this->rejection( $id, $field );

			if ( '' !== $rejection ) {
				return $rejection;
			}
		}

		return '';
	}

	/**
	 * Why an attachment is not allowed in this field, or an empty string.
	 *
	 * Every limit is checked here rather than in the picker: the picker is a
	 * convenience that can be bypassed by typing an id, and a gallery can be
	 * filled by code that never opens one.
	 *
	 * @param int                  $id    Attachment id.
	 * @param array<string, mixed> $field Field definition.
	 */
	public function rejection( int $id, array $field ): string {
		$allowed = $this->allowed_types( $field );

		if ( array() !== $allowed ) {
			$file      = (string) get_post_mime_type( $id );
			$extension = strtolower( (string) pathinfo( (string) get_attached_file( $id ), PATHINFO_EXTENSION ) );

			$matches = in_array( $extension, $allowed, true )
				|| in_array( $file, $allowed, true )
				// `image` matches `image/png`, the way the picker filters.
				|| in_array( explode( '/', $file )[0], $allowed, true );

			if ( ! $matches ) {
				return sprintf(
					/* translators: %s: comma-separated list of allowed file types. */
					__( 'That file type is not allowed. Allowed: %s.', 'manpreetdev-custom-fields-meta-boxes' ),
					implode( ', ', $allowed )
				);
			}
		}

		$path = (string) get_attached_file( $id );
		$size = is_file( $path ) ? (int) filesize( $path ) : 0;

		foreach ( array(
			'min_size' => true,
			'max_size' => false,
		) as $setting => $is_min ) {
			$limit = $this->kilobytes( (string) $this->setting( $field, $setting, '' ) );

			if ( 0 === $limit || 0 === $size ) {
				continue;
			}

			if ( $is_min ? $size < $limit : $size > $limit ) {
				return sprintf(
					$is_min
						/* translators: 1: the file's size, 2: the smallest allowed size. */
						? __( 'That file is %1$s; the smallest allowed is %2$s.', 'manpreetdev-custom-fields-meta-boxes' )
						/* translators: 1: the file's size, 2: the largest allowed size. */
						: __( 'That file is %1$s; the largest allowed is %2$s.', 'manpreetdev-custom-fields-meta-boxes' ),
					size_format( $size ),
					size_format( $limit )
				);
			}
		}

		return $this->dimension_rejection( $id, $field );
	}

	/**
	 * Why an image's dimensions are not allowed, or an empty string.
	 *
	 * @param int                  $id    Attachment id.
	 * @param array<string, mixed> $field Field definition.
	 */
	private function dimension_rejection( int $id, array $field ): string {
		$meta = wp_get_attachment_metadata( $id );

		// Not an image, or an image WordPress could not measure: there is
		// nothing to compare, and refusing it would be a guess.
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return '';
		}

		$actual = array(
			'width'  => (int) $meta['width'],
			'height' => (int) $meta['height'],
		);

		$limits = array(
			/* translators: 1: the image's width in pixels, 2: the narrowest allowed width in pixels. */
			'min_width'  => array( 'width', true, __( 'That image is %1$dpx wide; the narrowest allowed is %2$dpx.', 'manpreetdev-custom-fields-meta-boxes' ) ),
			/* translators: 1: the image's width in pixels, 2: the widest allowed width in pixels. */
			'max_width'  => array( 'width', false, __( 'That image is %1$dpx wide; the widest allowed is %2$dpx.', 'manpreetdev-custom-fields-meta-boxes' ) ),
			/* translators: 1: the image's height in pixels, 2: the shortest allowed height in pixels. */
			'min_height' => array( 'height', true, __( 'That image is %1$dpx tall; the shortest allowed is %2$dpx.', 'manpreetdev-custom-fields-meta-boxes' ) ),
			/* translators: 1: the image's height in pixels, 2: the tallest allowed height in pixels. */
			'max_height' => array( 'height', false, __( 'That image is %1$dpx tall; the tallest allowed is %2$dpx.', 'manpreetdev-custom-fields-meta-boxes' ) ),
		);

		foreach ( $limits as $setting => $rule ) {
			list( $axis, $is_min, $message ) = $rule;

			$limit = (int) $this->setting( $field, $setting, 0 );

			if ( $limit <= 0 ) {
				continue;
			}

			if ( $is_min ? $actual[ $axis ] < $limit : $actual[ $axis ] > $limit ) {
				return sprintf( $message, $actual[ $axis ], $limit );
			}
		}

		return '';
	}

	/**
	 * A size limit in bytes, from a number of kilobytes or a size with a unit.
	 *
	 * Kilobytes when bare, because that is what the media library's own
	 * limits are written in; `2MB` and `500 KB` also work, because that is
	 * what people type.
	 *
	 * @param string $value Configured limit.
	 */
	private function kilobytes( string $value ): int {
		$value = strtolower( trim( $value ) );

		if ( '' === $value ) {
			return 0;
		}

		$number = (float) $value;
		$unit   = 1024;

		if ( str_contains( $value, 'mb' ) ) {
			$unit = 1024 * 1024;
		} elseif ( str_contains( $value, 'gb' ) ) {
			$unit = 1024 * 1024 * 1024;
		} elseif ( str_contains( $value, 'b' ) && ! str_contains( $value, 'kb' ) ) {
			$unit = 1;
		}

		return (int) round( $number * $unit );
	}

	/**
	 * Normalise a value to a list of positive integer ids.
	 *
	 * Accepts the comma-separated string the control posts, an array from
	 * code, or a single id.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array<int, int>
	 */
	private function ids( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = '' === $value ? array() : explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		return array_values(
			array_filter(
				array_map( 'absint', array_filter( $value, 'is_scalar' ) ),
				static fn( int $id ): bool => $id > 0
			)
		);
	}
}
