<?php
/**
 * Location matching context.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the thing a field group is being matched against.
 *
 * Values are computed on demand and memoised, never up front. A typical
 * location rule set tests one or two parameters, and several of the others
 * cost a term or template query — so eagerly building a full description of
 * the screen would run those queries on every page load to answer a question
 * nobody asked.
 */
final class Context {

	/**
	 * The object being described.
	 *
	 * @var ObjectRef
	 */
	public readonly ObjectRef $ref;

	/**
	 * Screen details the object itself cannot supply, such as which user form
	 * is being rendered or which widget is being edited.
	 *
	 * @var array<string, string>
	 */
	private array $extra;

	/**
	 * Memoised parameter values.
	 *
	 * @var array<string, string|array<int, string>|null>
	 */
	private array $resolved = array();

	/**
	 * Constructor.
	 *
	 * @param ObjectRef             $ref   Object reference.
	 * @param array<string, string> $extra Screen details, e.g. `user_form`.
	 */
	public function __construct( ObjectRef $ref, array $extra = array() ) {
		$this->ref   = $ref;
		$this->extra = $extra;
	}

	/**
	 * Build a context for a loose identifier.
	 *
	 * @param mixed                 $identifier Object identifier, see ObjectRef::from().
	 * @param array<string, string> $extra      Screen details.
	 */
	public static function for( mixed $identifier = null, array $extra = array() ): self {
		return new self( ObjectRef::from( $identifier ), $extra );
	}

	/**
	 * A stable string form of the screen extras, for cache keys.
	 *
	 * Two contexts for the same object can still differ — the same user on
	 * the add form and on the edit form — so resolved groups cannot be cached
	 * by object reference alone.
	 */
	public function signature_extra(): string {
		$extra = $this->extra;
		ksort( $extra );

		return (string) wp_json_encode( $extra );
	}

	/**
	 * The value of a location rule parameter, or null when it does not apply.
	 *
	 * An array means "any of these match"; null means the parameter is
	 * meaningless here, which never matches.
	 *
	 * @param string $param Rule parameter.
	 *
	 * @return string|array<int, string>|null
	 */
	public function value( string $param ): string|array|null {
		if ( array_key_exists( $param, $this->resolved ) ) {
			return $this->resolved[ $param ];
		}

		$value = match ( $param ) {
			'post_type'                           => $this->post_value( 'post_type' ),
			'comment'                             => $this->comment_post_type(),
			'post_status'                         => $this->post_status(),
			'post_format'                         => $this->post_format(),
			'post_template'                       => $this->template(),
			'page_template'                       => $this->is_post_type( 'page' ) ? $this->template() : null,
			'page_type'                           => $this->page_type(),
			'page_parent'                         => $this->is_hierarchical() ? $this->post_value( 'post_parent' ) : null,
			'post'                                => $this->post_id_string(),
			'page'                                => $this->is_post_type( 'page' ) ? $this->post_id_string() : null,
			'post_category'                       => $this->term_slugs( 'category' ),
			'post_taxonomy'                       => $this->post_taxonomies(),
			'attachment'                          => $this->is_post_type( 'attachment' ) ? 'all' : null,
			'nav_menu_item'                       => $this->is_menu_item() ? 'all' : null,
			'taxonomy'                            => $this->taxonomy(),
			'nav_menu'                            => $this->nav_menu(),
			'user_role'                           => $this->user_roles(),
			'user_form'                           => $this->user_form(),
			'options_page'                        => ObjectRef::OPTION === $this->ref->type && ! isset( $this->extra['widget'] ) ? (string) $this->ref->id : null,
			'widget'                              => $this->widget(),
			'block'                               => $this->extra['block'] ?? null,
			'current_user'                        => $this->current_user(),
			'current_user_role'                   => $this->current_user_roles(),
			default                               => $this->custom( $param ),
		};

		$this->resolved[ $param ] = $value;

		return $value;
	}

	/**
	 * A property of the referenced post.
	 *
	 * @param string $property Post property.
	 */
	private function post_value( string $property ): ?string {
		$post = $this->post();

		return $post instanceof \WP_Post ? (string) $post->{$property} : null;
	}

	/**
	 * The post type a comment belongs to.
	 *
	 * The `comment` rule offers post types, so "Comment is post" means the
	 * comments on posts of that type. It used to read the post type of the
	 * *referenced* object, which is only ever set when the reference is a
	 * post — so the rule matched post edit screens, where it was never meant
	 * to apply, and never matched a comment screen, where it was.
	 */
	private function comment_post_type(): ?string {
		if ( ObjectRef::COMMENT !== $this->ref->type ) {
			return null;
		}

		$comment = get_comment( (int) $this->ref->id );

		if ( ! $comment instanceof \WP_Comment ) {
			return null;
		}

		$post = get_post( (int) $comment->comment_post_ID );

		return $post instanceof \WP_Post ? (string) $post->post_type : null;
	}

	/**
	 * The referenced post, if this context describes one.
	 *
	 * A menu item is stored as a post and is not one in any sense a rule
	 * means: "post status is published" put its fields on every item of every
	 * menu. It has a rule of its own, and answers to that one only.
	 */
	private function post(): ?\WP_Post {
		if ( ObjectRef::POST !== $this->ref->type ) {
			return null;
		}

		$post = get_post( (int) $this->ref->id );

		return $post instanceof \WP_Post && 'nav_menu_item' !== $post->post_type ? $post : null;
	}

	/**
	 * Whether the referenced object is a menu item.
	 */
	private function is_menu_item(): bool {
		return ObjectRef::POST === $this->ref->type && 'nav_menu_item' === get_post_type( (int) $this->ref->id );
	}

	/**
	 * Whether the referenced post is of a kind that can have a parent.
	 *
	 * The page tree rules ask about a page's place in a tree. A post has no
	 * such place, and answering "top level" for one — its parent being 0 like
	 * any post's — put those fields on every post, file and menu item.
	 */
	private function is_hierarchical(): bool {
		$post = $this->post();

		return $post instanceof \WP_Post && is_post_type_hierarchical( $post->post_type );
	}

	/**
	 * The post's status, where a post not saved yet is also a draft.
	 *
	 * The Add New screen holds an `auto-draft`, which is nothing an editor
	 * has ever heard of: to them it is a draft that has not been saved. A
	 * group for drafts was missing from the one screen every draft starts on.
	 *
	 * @return string|array<int, string>|null
	 */
	private function post_status(): string|array|null {
		$status = $this->post_value( 'post_status' );

		return 'auto-draft' === $status ? array( 'auto-draft', 'draft' ) : $status;
	}

	/**
	 * Which user form this is.
	 *
	 * Said by the screen when it knows, and otherwise read from the user: one
	 * that exists is being edited, one that does not is being added. Only the
	 * screen that drew the form used to say, so the group was matched when it
	 * was drawn and not when it was saved, validated or given its scripts —
	 * the fields appeared and nothing typed into them was kept.
	 */
	private function user_form(): ?string {
		if ( isset( $this->extra['user_form'] ) ) {
			return (string) $this->extra['user_form'];
		}

		if ( ObjectRef::USER !== $this->ref->type ) {
			return null;
		}

		return (int) $this->ref->id > 0 ? 'edit' : 'add';
	}

	/**
	 * Whether the referenced object is a post of the given type.
	 *
	 * @param string $post_type Post type name.
	 */
	private function is_post_type( string $post_type ): bool {
		return $post_type === $this->post_value( 'post_type' );
	}

	/**
	 * The post id as a string, for rules that compare against a specific post.
	 */
	private function post_id_string(): ?string {
		$post = $this->post();

		return $post instanceof \WP_Post ? (string) $post->ID : null;
	}

	/**
	 * The post format, falling back to `standard`.
	 *
	 * Null for a type with no formats: it has none, rather than the standard
	 * one.
	 */
	private function post_format(): ?string {
		$post = $this->post();

		if ( ! $post instanceof \WP_Post || ! post_type_supports( $post->post_type, 'post-formats' ) ) {
			return null;
		}

		$format = get_post_format( $post );

		return is_string( $format ) && '' !== $format ? $format : 'standard';
	}

	/**
	 * The assigned page template, falling back to `default`.
	 */
	private function template(): ?string {
		$post = $this->post();

		// A media file is never given a template.
		if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) {
			return null;
		}

		$template = get_page_template_slug( $post );

		return is_string( $template ) && '' !== $template ? $template : 'default';
	}

	/**
	 * Where the referenced page sits in the page tree.
	 *
	 * Returns every applicable label, so a rule may match on any one of them.
	 *
	 * @return array<int, string>|null
	 */
	private function page_type(): ?array {
		$post = $this->post();

		if ( ! $post instanceof \WP_Post || ! $this->is_hierarchical() ) {
			return null;
		}

		$types = array();

		if ( (int) get_option( 'page_on_front' ) === $post->ID ) {
			$types[] = 'front_page';
		}

		if ( (int) get_option( 'page_for_posts' ) === $post->ID ) {
			$types[] = 'posts_page';
		}

		$types[] = $post->post_parent > 0 ? 'child' : 'top_level';

		if ( 0 !== count(
			get_children(
				array(
					'post_parent' => $post->ID,
					'post_type'   => $post->post_type,
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			)
		) ) {
			$types[] = 'parent';
		}

		return $types;
	}

	/**
	 * Term slugs the referenced post belongs to, in one taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return array<int, string>|null
	 */
	private function term_slugs( string $taxonomy ): ?array {
		$post = $this->post();

		// Null, not "none of them", for a type that does not use the taxonomy:
		// an "is not" rule would otherwise match everything that has no
		// categories to be in.
		if ( ! $post instanceof \WP_Post || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return null;
		}

		$terms = get_the_terms( $post, $taxonomy );

		if ( is_array( $terms ) ) {
			return wp_list_pluck( $terms, 'slug' );
		}

		/*
		 * A post not saved yet is in no category and will be in the default
		 * one the moment it is, because WordPress puts it there. The group
		 * belongs on the Add New screen for the same reason.
		 */
		if ( 'category' === $taxonomy && 'auto-draft' === $post->post_status ) {
			$default = get_term( (int) get_option( 'default_category' ), 'category' );

			return $default instanceof \WP_Term ? array( $default->slug ) : array();
		}

		return array();
	}

	/**
	 * Taxonomies the referenced post has terms in.
	 *
	 * @return array<int, string>|null
	 */
	private function post_taxonomies(): ?array {
		$post = $this->post();

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$taxonomies = array();

		foreach ( get_object_taxonomies( $post ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );

			if ( is_array( $terms ) && array() !== $terms ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return $taxonomies;
	}

	/**
	 * The referenced term's taxonomy.
	 */
	/**
	 * The widget being rendered, as both its own type and "all".
	 *
	 * Two values rather than one, so a group can be located on `text` or on
	 * every widget. A rule matches when its value is any of the ones the
	 * context reports, which is how post categories already work.
	 *
	 * @return array<int, string>|null
	 */
	private function widget(): ?array {
		$id_base = (string) ( $this->extra['widget'] ?? '' );

		return '' !== $id_base ? array( $id_base, 'all' ) : null;
	}

	/**
	 * The taxonomy of the referenced term, or of the screen adding one.
	 */
	private function taxonomy(): ?string {
		if ( ObjectRef::TERM !== $this->ref->type ) {
			return null;
		}

		$term = get_term( (int) $this->ref->id );

		if ( $term instanceof \WP_Term ) {
			return $term->taxonomy;
		}

		/*
		 * The Add New Term form knows its taxonomy and has no term.
		 *
		 * Deriving it from the term is right everywhere else, and impossible
		 * there — so the screen says which taxonomy it is, the same way it
		 * already says which user form or widget it is. Without this, a group
		 * located on a taxonomy appeared when editing a term and not when
		 * adding one.
		 */
		return isset( $this->extra['taxonomy'] ) ? (string) $this->extra['taxonomy'] : null;
	}

	/**
	 * The referenced menu, as both its own id and "all".
	 *
	 * @return array<int, string>|null
	 */
	private function nav_menu(): ?array {
		if ( 'nav_menu' !== $this->taxonomy() ) {
			return null;
		}

		// The menu's own id and "all", so a group can be located on one menu
		// or on every one of them. Same shape as the widget rule.
		return array( (string) $this->ref->id, 'all' );
	}

	/**
	 * Roles of the referenced user.
	 *
	 * @return array<int, string>|null
	 */
	private function user_roles(): ?array {
		if ( ObjectRef::USER !== $this->ref->type ) {
			return null;
		}

		// Nobody yet: the form offers the site's default role.
		if ( 0 === (int) $this->ref->id ) {
			return array( (string) get_option( 'default_role' ) );
		}

		$user = get_userdata( (int) $this->ref->id );

		return $user instanceof \WP_User ? array_values( $user->roles ) : array();
	}

	/**
	 * Applicable labels for the logged-in user's situation.
	 *
	 * @return array<int, string>
	 */
	private function current_user(): array {
		$labels = is_user_logged_in() ? array( 'logged_in' ) : array();

		$labels[] = is_admin() ? 'viewing_back' : 'viewing_front';

		return $labels;
	}

	/**
	 * Roles of the logged-in user.
	 *
	 * @return array<int, string>
	 */
	private function current_user_roles(): array {
		$user = wp_get_current_user();

		return $user instanceof \WP_User ? array_values( $user->roles ) : array();
	}

	/**
	 * Resolve a parameter registered by third-party code.
	 *
	 * @param string $param Rule parameter.
	 *
	 * @return string|array<int, string>|null
	 */
	private function custom( string $param ): string|array|null {
		/**
		 * Filters the value of a location rule parameter this class does not know.
		 *
		 * Pair with `wpcmb/location/params` to add a rule parameter and teach
		 * the matcher how to evaluate it.
		 *
		 * @since 1.0.0
		 *
		 * @param string|array<int, string>|null $value   Parameter value.
		 * @param string                         $param   Rule parameter.
		 * @param Context                        $context Context being described.
		 */
		return apply_filters( 'wpcmb/location/value', null, $param, $this );
	}
}
