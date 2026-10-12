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
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array(
			'file'    => __( 'File', 'metafields-custom-fields' ),
			'image'   => __( 'Image', 'metafields-custom-fields' ),
			'gallery' => __( 'Gallery', 'metafields-custom-fields' ),
			'video'   => __( 'Video', 'metafields-custom-fields' ),
			'audio'   => __( 'Audio', 'metafields-custom-fields' ),
		);
	}

	/**
	 * Editor group label.
	 */
	public function group_label(): string {
		return __( 'Media', 'metafields-custom-fields' );
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
			esc_html( $multiple ? __( 'Add media', 'metafields-custom-fields' ) : __( 'Select media', 'metafields-custom-fields' ) ),
			esc_html( __( 'Clear', 'metafields-custom-fields' ) )
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
			esc_attr__( 'Remove', 'metafields-custom-fields' )
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
		$ids      = $this->ids( $value );
		$format   = (string) $this->setting( $field, 'return_format', 'id' );

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
				'label'   => __( 'Library', 'metafields-custom-fields' ),
				'type'    => 'select',
				'choices' => array(
					'all'      => __( 'Everything in the media library', 'metafields-custom-fields' ),
					'uploaded' => __( 'Only what was uploaded to this item', 'metafields-custom-fields' ),
				),
			),
			'mime_types'    => array(
				'label' => __( 'Allowed file types', 'metafields-custom-fields' ),
				'type'  => 'text',
				'tab'   => 'validation',
				'help'  => __( 'Comma separated extensions or mime types, e.g. pdf, docx or image/png. Leave empty for this field type\'s own.', 'metafields-custom-fields' ),
			),
			'min_size'      => array(
				'label' => __( 'Minimum file size', 'metafields-custom-fields' ),
				'type'  => 'text',
				'tab'   => 'validation',
				'help'  => __( 'Kilobytes when bare; 2MB and 500 KB also work.', 'metafields-custom-fields' ),
			),
			'max_size'      => array(
				'label' => __( 'Maximum file size', 'metafields-custom-fields' ),
				'type'  => 'text',
				'tab'   => 'validation',
			),
			'preview_size'  => array(
				'label' => __( 'Preview size', 'metafields-custom-fields' ),
				'type'  => 'text',
				'tab'   => 'appearance',
				'help'  => __( 'A registered image size. Defaults to thumbnail.', 'metafields-custom-fields' ),
			),
			'return_format' => array(
				'label'   => __( 'Return format', 'metafields-custom-fields' ),
				'type'    => 'select',
				'choices' => array(
					'id'     => __( 'Attachment ID', 'metafields-custom-fields' ),
					'url'    => __( 'URL', 'metafields-custom-fields' ),
					'array'  => __( 'Array', 'metafields-custom-fields' ),
					'object' => __( 'WP_Post object', 'metafields-custom-fields' ),
				),
			),
		);

		if ( 'gallery' === $type ) {
			$schema['min'] = array(
				'label' => __( 'Minimum items', 'metafields-custom-fields' ),
				'type'  => 'number',
			);
			$schema['max'] = array(
				'label' => __( 'Maximum items', 'metafields-custom-fields' ),
				'type'  => 'number',
			);
		}

		// Dimensions only mean something for a type that holds an image.
		if ( in_array( $type, array( 'image', 'gallery' ), true ) ) {
			$dimensions = array(
				'min_width'  => __( 'Minimum width (px)', 'metafields-custom-fields' ),
				'max_width'  => __( 'Maximum width (px)', 'metafields-custom-fields' ),
				'min_height' => __( 'Minimum height (px)', 'metafields-custom-fields' ),
				'max_height' => __( 'Maximum height (px)', 'metafields-custom-fields' ),
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
	 * The configured extensions or mime types, cleaned.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<int, string>
	 */
	private function mime_types( array $field ): array {
		$raw = (string) $this->setting( $field, 'mime_types', '' );

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
		$allowed = $this->mime_types( $field );

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
					__( 'That file type is not allowed. Allowed: %s.', 'metafields-custom-fields' ),
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
						? __( 'That file is %1$s; the smallest allowed is %2$s.', 'metafields-custom-fields' )
						/* translators: 1: the file's size, 2: the largest allowed size. */
						: __( 'That file is %1$s; the largest allowed is %2$s.', 'metafields-custom-fields' ),
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
			'min_width'  => array( 'width', true, __( 'That image is %1$dpx wide; the narrowest allowed is %2$dpx.', 'metafields-custom-fields' ) ),
			/* translators: 1: the image's width in pixels, 2: the widest allowed width in pixels. */
			'max_width'  => array( 'width', false, __( 'That image is %1$dpx wide; the widest allowed is %2$dpx.', 'metafields-custom-fields' ) ),
			/* translators: 1: the image's height in pixels, 2: the shortest allowed height in pixels. */
			'min_height' => array( 'height', true, __( 'That image is %1$dpx tall; the shortest allowed is %2$dpx.', 'metafields-custom-fields' ) ),
			/* translators: 1: the image's height in pixels, 2: the tallest allowed height in pixels. */
			'max_height' => array( 'height', false, __( 'That image is %1$dpx tall; the tallest allowed is %2$dpx.', 'metafields-custom-fields' ) ),
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
