<?php
/**
 * Relationship field types.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;
use WPCMB\Fields\ObjectRef;

defined( 'ABSPATH' ) || exit;

/**
 * Fields that point at other WordPress objects.
 *
 * Post object, page link, relationship, taxonomy and user are all a select
 * over a queried list; they differ in what is queried and what is stored.
 * Options are queried once per render and capped, because a select holding
 * every post on a large site is both a slow query and an unusable control —
 * the cap is the honest failure mode, and `wpcmb/field/relationship_query`
 * is the way to narrow the list instead of raising it.
 */
final class Relationship extends FieldType {

	/**
	 * Most options to offer in one control.
	 *
	 * Deliberately a hard cap rather than a searching, paginated control.
	 * ponytail: if a site routinely exceeds this, the upgrade is an AJAX
	 * search endpoint feeding the same markup, not a bigger number.
	 */
	private const MAX_OPTIONS = 200;

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array(
			'post_object'  => __( 'Post Object', 'metafields-custom-fields' ),
			'page_link'    => __( 'Page Link', 'metafields-custom-fields' ),
			'relationship' => __( 'Relationship', 'metafields-custom-fields' ),
			'taxonomy'     => __( 'Taxonomy', 'metafields-custom-fields' ),
			'user'         => __( 'User', 'metafields-custom-fields' ),
		);
	}

	/**
	 * Editor group label.
	 */
	public function group_label(): string {
		return __( 'Relational', 'metafields-custom-fields' );
	}

	/**
	 * The Dashicon shown beside a field of this type.
	 *
	 * @param string $type The specific type.
	 */
	public function icon( string $type ): string {
		$icons = array(
			'page_link'    => 'dashicons-admin-page',
			'post_object'  => 'dashicons-admin-post',
			'relationship' => 'dashicons-admin-links',
			'taxonomy'     => 'dashicons-category',
			'user'         => 'dashicons-admin-users',
		);

		return $icons[ $type ] ?? 'dashicons-admin-links';
	}

	/**
	 * Render the control.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$choices  = $this->choices( $field );
		$multiple = $this->is_multiple( $field );
		$selected = array_map( 'strval', $this->as_list( $value ) );

		$attributes = $this->attributes(
			array(
				'name'     => $multiple ? $input_name . '[]' : $input_name,
				'id'       => $input_id,
				'class'    => 'wpcmb-input',
				'required' => ! empty( $field['required'] ),
				'multiple' => $multiple,
				'size'     => $multiple ? '8' : '',
			)
		);

		printf( '<select %s>', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.

		if ( ! $multiple && '0' !== (string) $this->setting( $field, 'allow_null', '1' ) ) {
			printf( '<option value="">%s</option>', esc_html__( '— Select —', 'metafields-custom-fields' ) );
		}

		foreach ( $choices as $option => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $option ),
				in_array( (string) $option, $selected, true ) ? ' selected' : '',
				esc_html( $label )
			);
		}

		echo '</select>';

		if ( count( $choices ) >= self::MAX_OPTIONS ) {
			printf(
				'<p class="wpcmb-field__note">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: maximum number of options. */
						__( 'Showing the first %d matches. Narrow this field\'s post type or taxonomy settings to see the rest.', 'metafields-custom-fields' ),
						self::MAX_OPTIONS
					)
				)
			);
		}
	}

	/**
	 * Keep only ids that still exist and were on offer.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function sanitize( mixed $value, array $field ): mixed {
		$allowed  = array_map( 'strval', array_keys( $this->choices( $field ) ) );
		$multiple = $this->is_multiple( $field );
		$values   = array_values( array_intersect( array_map( 'strval', $this->as_list( $value ) ), $allowed ) );

		// Page link stores a URL string; everything else stores integer ids.
		if ( 'page_link' !== ( $field['type'] ?? '' ) ) {
			$values = array_map( 'absint', $values );
		}

		return $multiple ? $values : ( $values[0] ?? '' );
	}

	/**
	 * Turn stored ids into objects, URLs or ids.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		$type   = (string) ( $field['type'] ?? '' );
		$format = (string) $this->setting( $field, 'return_format', 'id' );

		if ( 'page_link' === $type || 'id' === $format ) {
			return $value;
		}

		$mapped = array_map(
			fn( $id ) => match ( $type ) {
				'taxonomy' => get_term( (int) $id ),
				'user'     => $this->user_for_reader( (int) $id ),
				default    => $this->post_for_reader( (int) $id ),
			},
			$this->as_list( $value )
		);

		$mapped = array_values( array_filter( $mapped ) );

		return $this->is_multiple( $field ) ? $mapped : ( $mapped[0] ?? null );
	}

	/**
	 * A post, or nothing when the reader may not see it.
	 *
	 * A stored id outlives the status of what it points at: a post related
	 * while it was published can be unpublished later, and the template that
	 * prints the relationship has no idea. Returning the object anyway put a
	 * draft's title and content in front of whoever loaded the page.
	 *
	 * @param int $id Post id.
	 */
	private function post_for_reader( int $id ): ?\WP_Post {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return 'publish' === $post->post_status || current_user_can( 'read_post', $id ) ? $post : null;
	}

	/**
	 * A user, or just their id when the reader may not see the rest.
	 *
	 * A WP_User carries the email address, the login name and the registration
	 * date. A theme printing `->display_name` is the common case and is
	 * harmless; it is the rest of the object that has no business being on a
	 * public page, so a reader without `list_users` gets the id they could
	 * have worked out anyway and nothing more.
	 *
	 * @param int $id User id.
	 *
	 * @return \WP_User|int|null
	 */
	private function user_for_reader( int $id ) {
		if ( ! current_user_can( 'list_users' ) ) {
			return $id > 0 ? $id : null;
		}

		$user = get_userdata( $id );

		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	/**
	 * Assign a taxonomy field's terms to the post it was saved on.
	 *
	 * Opt-in, because a taxonomy field is often a second, editorial list that
	 * has nothing to do with the post's real terms — switching this on makes
	 * the field authoritative over them, and it replaces whatever that
	 * taxonomy held rather than adding to it.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $ref   Object written to.
	 */
	public function on_save( mixed $value, array $field, mixed $ref ): void {
		$taxonomy = $this->taxonomy_for( $field );

		if ( '' === $taxonomy || empty( $this->setting( $field, 'save_terms', '' ) ) ) {
			return;
		}

		// Terms live on posts. A field on a user or a comment has nothing to
		// assign them to.
		if ( ! $ref instanceof ObjectRef || ObjectRef::POST !== $ref->type ) {
			return;
		}

		wp_set_object_terms( (int) $ref->id, array_map( 'intval', $this->as_list( $value ) ), $taxonomy );
	}

	/**
	 * Show the post's own terms instead of the field's stored value.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $ref   Object being read.
	 *
	 * @return mixed
	 */
	public function on_load( mixed $value, array $field, mixed $ref ): mixed {
		$taxonomy = $this->taxonomy_for( $field );

		if ( '' === $taxonomy || empty( $this->setting( $field, 'load_terms', '' ) ) ) {
			return $value;
		}

		if ( ! $ref instanceof ObjectRef || ObjectRef::POST !== $ref->type ) {
			return $value;
		}

		$terms = wp_get_object_terms( (int) $ref->id, $taxonomy, array( 'fields' => 'ids' ) );

		// A taxonomy that is not registered on this post type returns an
		// error, and the stored value is a better answer than none.
		return is_wp_error( $terms ) ? $value : array_map( 'strval', $terms );
	}

	/**
	 * The taxonomy a taxonomy field points at.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	private function taxonomy_for( array $field ): string {
		if ( 'taxonomy' !== (string) ( $field['type'] ?? '' ) ) {
			return '';
		}

		$taxonomy = sanitize_key( (string) $this->setting( $field, 'taxonomy', '' ) );

		return taxonomy_exists( $taxonomy ) ? $taxonomy : '';
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		$schema = array();

		if ( in_array( $type, array( 'post_object', 'page_link', 'relationship' ), true ) ) {
			$schema['post_type'] = array(
				'label' => __( 'Post types', 'metafields-custom-fields' ),
				'type'  => 'text',
				'help'  => __( 'Comma separated. Leave empty for all public post types.', 'metafields-custom-fields' ),
			);
		}

		if ( 'taxonomy' === $type ) {
			$schema['taxonomy'] = array(
				'label' => __( 'Taxonomy', 'metafields-custom-fields' ),
				'type'  => 'text',
			);
		}

		if ( 'user' === $type ) {
			$schema['role'] = array(
				'label' => __( 'Roles', 'metafields-custom-fields' ),
				'type'  => 'text',
				'help'  => __( 'Comma separated. Leave empty for all roles.', 'metafields-custom-fields' ),
			);
		}

		$schema['multiple'] = array(
			'label' => __( 'Allow multiple', 'metafields-custom-fields' ),
			'type'  => 'toggle',
		);

		$schema['min'] = array(
			'label' => __( 'Minimum selections', 'metafields-custom-fields' ),
			'type'  => 'number',
			'tab'   => 'validation',
			'help'  => __( 'Applies when multiple selections are allowed.', 'metafields-custom-fields' ),
		);

		$schema['max'] = array(
			'label' => __( 'Maximum selections', 'metafields-custom-fields' ),
			'type'  => 'number',
			'tab'   => 'validation',
		);

		$schema['allow_null'] = array(
			'label' => __( 'Offer an empty choice', 'metafields-custom-fields' ),
			'type'  => 'toggle',
			'help'  => __( 'On by default. Off needs a default value, or the browser preselects the first item.', 'metafields-custom-fields' ),
		);

		if ( 'taxonomy' === $type ) {
			$schema['save_terms'] = array(
				'label' => __( 'Also assign the terms', 'metafields-custom-fields' ),
				'type'  => 'toggle',
				'help'  => __( 'Sets the chosen terms on the post as well as storing them in this field.', 'metafields-custom-fields' ),
			);
			$schema['load_terms'] = array(
				'label' => __( 'Load from the post\'s terms', 'metafields-custom-fields' ),
				'type'  => 'toggle',
				'help'  => __( 'Shows the terms the post already has, rather than this field\'s stored value.', 'metafields-custom-fields' ),
			);
		}

		if ( 'page_link' !== $type ) {
			$schema['return_format'] = array(
				'label'   => __( 'Return format', 'metafields-custom-fields' ),
				'type'    => 'select',
				'choices' => array(
					'id'     => __( 'ID', 'metafields-custom-fields' ),
					'object' => __( 'Object', 'metafields-custom-fields' ),
				),
			);
		}

		return $schema;
	}

	/**
	 * Whether the field accepts more than one value.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	private function is_multiple( array $field ): bool {
		if ( 'relationship' === ( $field['type'] ?? '' ) ) {
			return true;
		}

		return ! empty( $this->setting( $field, 'multiple', '' ) );
	}

	/**
	 * The selectable objects for a field.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function choices( array $field ): array {
		$choices = match ( (string) ( $field['type'] ?? '' ) ) {
			'taxonomy' => $this->terms( $field ),
			'user'     => $this->users( $field ),
			'page_link' => $this->page_links( $field ),
			default    => $this->posts( $field ),
		};

		/**
		 * Filters the objects a relationship field offers.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $choices Options keyed by id or URL.
		 * @param array<string, mixed>  $field   Field definition.
		 */
		return (array) apply_filters( 'wpcmb/field/relationship_query', $choices, $field );
	}

	/**
	 * Posts, keyed by id.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function posts( array $field ): array {
		$choices = array();

		foreach ( $this->query_posts( $field ) as $post ) {
			$choices[ (string) $post->ID ] = $this->post_label( $post );
		}

		return $choices;
	}

	/**
	 * Posts keyed by permalink, for the page link type.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function page_links( array $field ): array {
		$choices = array();

		foreach ( $this->query_posts( $field ) as $post ) {
			$choices[ (string) get_permalink( $post ) ] = $this->post_label( $post );
		}

		return $choices;
	}

	/**
	 * Run the post query behind both post-backed types.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function query_posts( array $field ): array {
		$post_types = $this->list_setting( $field, 'post_type' );

		$posts = get_posts(
			array(
				'post_type'              => array() !== $post_types ? $post_types : 'any',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => self::MAX_OPTIONS,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		/*
		 * Drafts and private posts are offered because the person choosing a
		 * related post on an edit screen should see the one they are about to
		 * publish. The same control renders on a public form, though, where
		 * every option is a title handed to whoever asked for the page — so
		 * each one is checked against the reader rather than the query.
		 *
		 * `read_post` is true for anyone on a published post, so an editor's
		 * list is unchanged and a visitor's holds nothing they could not
		 * already see.
		 */
		return array_values(
			array_filter(
				$posts,
				static fn( \WP_Post $post ): bool => 'publish' === $post->post_status
					|| current_user_can( 'read_post', $post->ID )
			)
		);
	}

	/**
	 * A post's label, disambiguated by type when several are offered.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function post_label( \WP_Post $post ): string {
		$title = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'metafields-custom-fields' );

		return sprintf( '%s — %s', $title, $post->post_type );
	}

	/**
	 * Terms, keyed by term id.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function terms( array $field ): array {
		$taxonomies = $this->list_setting( $field, 'taxonomy' );

		$terms = get_terms(
			array(
				'taxonomy'   => array() !== $taxonomies ? $taxonomies : get_taxonomies( array( 'show_ui' => true ) ),
				'hide_empty' => false,
				'number'     => self::MAX_OPTIONS,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$choices = array();

		foreach ( $terms as $term ) {
			$choices[ (string) $term->term_id ] = $term->name;
		}

		return $choices;
	}

	/**
	 * Users, keyed by user id.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function users( array $field ): array {
		$roles = $this->list_setting( $field, 'role' );

		$users = get_users(
			array(
				'number'   => self::MAX_OPTIONS,
				'orderby'  => 'display_name',
				'role__in' => array() !== $roles ? $roles : array(),
				'fields'   => array( 'ID', 'display_name' ),
			)
		);

		$choices = array();

		foreach ( $users as $user ) {
			$choices[ (string) $user->ID ] = $user->display_name;
		}

		return $choices;
	}

	/**
	 * Read a comma-separated setting as a list.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param string               $name  Setting name.
	 *
	 * @return array<int, string>
	 */
	private function list_setting( array $field, string $name ): array {
		$raw = $this->setting( $field, $name, '' );

		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', $raw ) ) );
		}

		return array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $raw ) ) ) );
	}

	/**
	 * Normalise a value to a list.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array<int, mixed>
	 */
	private function as_list( mixed $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( $value, 'is_scalar' ) );
		}

		return null === $value || '' === $value ? array() : array( $value );
	}
}
