<?php
/**
 * Field groups on edit screens.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Context;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Resolver;
use WPCMB\Fields\Validator;
use WPCMB\Fields\Values;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves field groups on post, term, user, comment and media screens.
 *
 * Every screen ends at the same two methods: render a group for an object
 * reference, and save a submission for one. The per-screen hooks differ only
 * in how they name the object, so the rendering and the save path — nonce,
 * capability check, sanitize, validate, store — exist once.
 */
final class MetaBoxes extends Module {

	/**
	 * Nonce action for every field submission.
	 */
	private const NONCE = 'wpcmb_save_values';

	/**
	 * Transient prefix for validation errors carried across a redirect.
	 */
	private const ERROR_PREFIX = 'wpcmb_errors_';

	/**
	 * Transient prefix recording that a publish was refused.
	 */
	private const BLOCKED_PREFIX = 'wpcmb_blocked_';

	/**
	 * Hideable elements core does not render as a meta box.
	 *
	 * The permalink row and the content editor are part of the edit form
	 * itself, so there is nothing for remove_meta_box() to remove. They are
	 * hidden by a class on the body instead.
	 */
	private const SCRIPTED_ELEMENTS = array( 'permalink', 'the_content' );

	/**
	 * Which of those elements this screen's groups ask to hide.
	 *
	 * Filled while meta boxes are registered, read when the body class is
	 * filtered — which core does later, in the admin header.
	 *
	 * @var array<int, string>
	 */
	private array $scripted = array();

	/**
	 * Whether guard_publish() has already recorded this request's errors.
	 *
	 * It validates every field that applies, including ones the submission
	 * left out, so its list is the fuller of the two and the save path must
	 * not replace it with the narrower one a moment later.
	 *
	 * @var bool
	 */
	private bool $guarded = false;

	/**
	 * Only load in the admin.
	 */
	public function is_enabled(): bool {
		return is_admin();
	}

	/**
	 * Register hooks for each supported screen.
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_post_meta_boxes' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'guard_publish' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
		add_action( 'edit_attachment', array( $this, 'save_attachment' ) );

		add_action( 'admin_init', array( $this, 'add_taxonomy_hooks' ) );
		add_action( 'edited_term', array( $this, 'save_term' ), 10, 3 );
		add_action( 'created_term', array( $this, 'save_term' ), 10, 3 );

		add_action( 'show_user_profile', array( $this, 'render_user' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user' ) );
		add_action( 'personal_options_update', array( $this, 'save_user' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user' ) );

		add_action( 'add_meta_boxes_comment', array( $this, 'add_comment_meta_boxes' ) );
		add_action( 'edit_comment', array( $this, 'save_comment' ) );

		add_action( 'admin_notices', array( $this, 'render_errors' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Carry the hide-on-screen elements core does not expose as meta boxes.
	 *
	 * @param string|string[] $classes Existing body classes.
	 */
	public function body_class( $classes ): string {
		$classes = is_array( $classes ) ? implode( ' ', $classes ) : (string) $classes;

		foreach ( $this->scripted as $element ) {
			$classes .= 'permalink' === $element ? ' wpcmb-hide-permalink' : ' wpcmb-hide-content';
		}

		return $classes;
	}

	/**
	 * Add a meta box per matching group on a post edit screen.
	 *
	 * @param string        $post_type Post type name.
	 * @param \WP_Post|null $post      Post being edited.
	 */
	public function add_post_meta_boxes( $post_type, $post = null ): void {
		if ( FieldGroupPostType::POST_TYPE === $post_type || ! $post instanceof \WP_Post ) {
			return;
		}

		$ref = new ObjectRef( ObjectRef::POST, $post->ID );

		foreach ( $this->groups( $ref ) as $group ) {
			$this->add_meta_box( $group, $ref, $post_type );
		}

		$this->hide_screen_elements( $ref );
	}

	/**
	 * Add a meta box per matching group on the comment edit screen.
	 *
	 * @param \WP_Comment $comment Comment being edited.
	 */
	public function add_comment_meta_boxes( $comment ): void {
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$ref = new ObjectRef( ObjectRef::COMMENT, (int) $comment->comment_ID );

		foreach ( $this->groups( $ref ) as $group ) {
			$this->add_meta_box( $group, $ref, 'comment' );
		}
	}

	/**
	 * Register one meta box for a group.
	 *
	 * @param FieldGroup $group  Field group.
	 * @param ObjectRef  $ref    Object reference.
	 * @param string     $screen Screen id.
	 */
	private function add_meta_box( FieldGroup $group, ObjectRef $ref, string $screen ): void {
		/*
		 * The display title is what the editor shows; the group title is what
		 * the field group list shows. They are separate so a group can be
		 * called "Product — pricing" where it is administered and "Pricing"
		 * where it is filled in.
		 */
		$heading = (string) ( $group->settings['display_title'] ?? '' );

		if ( '' === $heading ) {
			$heading = '' !== $group->title ? $group->title : __( 'Fields', 'metafields-custom-fields' );
		}

		add_meta_box(
			'wpcmb-' . $group->key,
			$heading,
			function () use ( $group, $ref ): void {
				$this->render_group( $group, $ref );
			},
			$screen,
			(string) ( $group->settings['position'] ?? 'normal' ),
			'default',
			array( '__block_editor_compatible_meta_box' => true )
		);

		$seamless = 'seamless' === ( $group->settings['style'] ?? 'default' );

		// Our own class on our own box, so the stylesheet can give it the same
		// chrome as the fields inside it without ever matching a core or
		// third-party meta box.
		add_filter(
			'postbox_classes_' . $screen . '_wpcmb-' . $group->key,
			static function ( $classes ) use ( $seamless ): array {
				$classes   = is_array( $classes ) ? $classes : array();
				$classes[] = 'wpcmb-metabox';

				if ( $seamless ) {
					$classes[] = 'wpcmb-seamless';
				}

				return $classes;
			}
		);
	}

	/**
	 * Register term form hooks for every taxonomy with a matching group.
	 *
	 * Registered for all public taxonomies rather than only matching ones:
	 * knowing which taxonomies match would need a term id, and there is none
	 * until the form is being rendered.
	 */
	public function add_taxonomy_hooks(): void {
		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_term' ) );
		}
	}

	/**
	 * Render matching groups on a term edit form.
	 *
	 * @param \WP_Term $term Term being edited.
	 */
	public function render_term( $term ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$ref = new ObjectRef( ObjectRef::TERM, $term->term_id );

		foreach ( $this->groups( $ref ) as $group ) {
			printf(
				'<tr class="form-field"><th scope="row">%s</th><td>',
				esc_html( $group->title )
			);
			$this->render_group( $group, $ref );
			echo '</td></tr>';
		}
	}

	/**
	 * Render matching groups on a user profile.
	 *
	 * @param \WP_User $user User being edited.
	 */
	public function render_user( $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$ref     = new ObjectRef( ObjectRef::USER, $user->ID );
		$context = new Context( $ref, array( 'user_form' => 'edit' ) );

		foreach ( $this->container->get( Resolver::class )->groups( $context ) as $group ) {
			printf( '<h2>%s</h2>', esc_html( $group->title ) );
			echo '<table class="form-table" role="presentation"><tr><td>';
			$this->render_group( $group, $ref );
			echo '</td></tr></table>';
		}
	}

	/**
	 * Render a group's fields with a nonce.
	 *
	 * @param FieldGroup $group Field group.
	 * @param ObjectRef  $ref   Object reference.
	 */
	private function render_group( FieldGroup $group, ObjectRef $ref ): void {
		static $nonce_printed = false;

		if ( ! $nonce_printed ) {
			wp_nonce_field( self::NONCE, 'wpcmb_values_nonce' );
			$nonce_printed = true;
		}

		$renderer = $this->container->get( Renderer::class );
		$renderer->set_errors( $this->take_errors( $ref ) );
		$renderer->group( $group, $ref );
	}

	/**
	 * Save field values on a post edit submission.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function save_post( $post_id, $post = null ): void {
		$post_id = (int) $post_id;

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( $post instanceof \WP_Post && FieldGroupPostType::POST_TYPE === $post->post_type ) {
			return;
		}

		$this->save( new ObjectRef( ObjectRef::POST, $post_id ), 'edit_post' );
	}

	/**
	 * Save field values on an attachment edit submission.
	 *
	 * @param int $post_id Attachment id.
	 */
	public function save_attachment( $post_id ): void {
		$this->save( new ObjectRef( ObjectRef::POST, (int) $post_id ), 'edit_post' );
	}

	/**
	 * Save field values on a term submission.
	 *
	 * `edit_term` rather than `manage_categories`: it is a meta capability, so
	 * WordPress maps it to whichever capabilities the taxonomy was registered
	 * with. A taxonomy with its own — `manage_product_terms`, say — is then
	 * honoured, where the category capability would have let anyone holding it
	 * write term values for a taxonomy they cannot otherwise touch.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function save_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		$this->save( new ObjectRef( ObjectRef::TERM, (int) $term_id ), 'edit_term' );
	}

	/**
	 * Save field values on a user profile submission.
	 *
	 * @param int $user_id User id.
	 */
	public function save_user( $user_id ): void {
		$this->save( new ObjectRef( ObjectRef::USER, (int) $user_id ), 'edit_user', (int) $user_id );
	}

	/**
	 * Save field values on a comment submission.
	 *
	 * @param int $comment_id Comment id.
	 */
	public function save_comment( $comment_id ): void {
		$this->save( new ObjectRef( ObjectRef::COMMENT, (int) $comment_id ), 'edit_comment', (int) $comment_id );
	}

	/**
	 * The shared save path.
	 *
	 * Values are stored even when validation fails. Losing what somebody
	 * typed is worse than storing something imperfect: the errors are shown
	 * on the next screen with the values still in the form, so the problem is
	 * visible and fixable rather than silently discarded.
	 *
	 * @param ObjectRef $ref        Object being saved.
	 * @param string    $capability Capability required.
	 * @param int|null  $object_id  Id to pass to the capability check.
	 */
	private function save( ObjectRef $ref, string $capability, ?int $object_id = null ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['wpcmb_values_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpcmb_values_nonce'] ) ), self::NONCE )
		) {
			return;
		}

		$allowed = null === $object_id
			? current_user_can( $capability, (int) $ref->id )
			: current_user_can( $capability, $object_id );

		if ( ! $allowed ) {
			return;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is validated and then sanitized by its own field type below; a blanket sanitizer here would flatten the arrays that composite types post.
		$submitted = isset( $_POST[ Renderer::INPUT_PREFIX ] ) && is_array( $_POST[ Renderer::INPUT_PREFIX ] )
			? wp_unslash( $_POST[ Renderer::INPUT_PREFIX ] )
			: array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$renderer = $this->container->get( Renderer::class );
		$values   = $this->container->get( Values::class );
		$fields   = $this->container->get( Resolver::class )->fields( new Context( $ref ) );
		$present  = array();

		foreach ( $fields as $name => $field ) {
			if ( ! $renderer->stores_value( $field ) ) {
				continue;
			}

			// A field absent from the submission was not on the form. Writing
			// an empty value for it would wipe data the user never saw — the
			// quick-edit and REST paths post partial submissions.
			if ( ! array_key_exists( $name, $submitted ) ) {
				continue;
			}

			$present[ $name ] = $submitted[ $name ];
		}

		// Validate what was actually typed, before sanitizing. A sanitizer
		// discards what it cannot make safe, so validating afterwards would
		// see a malformed email as an empty optional field and report
		// nothing — the user would lose the value with no explanation.
		$errors = $this->container->get( Validator::class )->validate(
			array_intersect_key( $fields, $present ),
			$present
		);

		$clean = array();

		foreach ( $present as $name => $value ) {
			$clean[ $name ] = $renderer->sanitize( $value, $fields[ $name ] );

			// update() runs the type's sanitizer again through the value
			// pipeline. That is intentional and safe: sanitizers are required
			// to be idempotent, so every write is clean whether it came from
			// this form, the REST API or a call in a theme.
			$values->update( $name, $clean[ $name ], $ref );
		}

		if ( ! $this->guarded ) {
			$this->store_errors( $ref, $errors );
		}

		/**
		 * Fires after an object's field values have been saved.
		 *
		 * @since 1.0.0
		 *
		 * @param ObjectRef             $ref    Object saved.
		 * @param array<string, mixed>  $clean  Sanitized values that were stored.
		 * @param array<string, string> $errors Validation errors, if any.
		 */
		do_action( 'wpcmb/values/saved', $ref, $clean, $errors );
	}

	/**
	 * Remember validation errors until the next screen renders.
	 *
	 * Keyed by user as well as object so two people editing the same object
	 * never see each other's errors.
	 *
	 * @param ObjectRef             $ref    Object reference.
	 * @param array<string, string> $errors Errors keyed by field name.
	 */
	private function store_errors( ObjectRef $ref, array $errors ): void {
		if ( array() === $errors ) {
			delete_transient( $this->error_key( $ref ) );

			return;
		}

		set_transient( $this->error_key( $ref ), $errors, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Read and clear the stored errors for an object.
	 *
	 * @param ObjectRef $ref Object reference.
	 *
	 * @return array<string, string>
	 */
	private function take_errors( ObjectRef $ref ): array {
		$errors = get_transient( $this->error_key( $ref ) );

		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * Keep a post out of a public status while its fields are invalid.
	 *
	 * The browser gate in validate.js is what an editor experiences: the
	 * Publish button stays quiet until the fields are filled. This is the half
	 * that does not depend on a script having run — with JavaScript off, or a
	 * form posted directly, the status is put back to what it was and the
	 * reason is reported on the next screen.
	 *
	 * It runs on `wp_insert_post_data`, before the row is written, so nothing
	 * is ever briefly public. And it only ever refuses a *transition* into a
	 * public status: a post already published is left published, because an
	 * edit that happens to leave a required field empty must not take a live
	 * page off the site. A published post with an empty required field is
	 * reported, not unpublished.
	 *
	 * @param array<string, mixed> $data    Post data about to be written.
	 * @param array<string, mixed> $postarr Raw post array, including the submitted status.
	 *
	 * @return array<string, mixed>
	 */
	public function guard_publish( $data, $postarr = array() ): array {
		$data = is_array( $data ) ? $data : array();

		if ( ! is_array( $postarr ) ) {
			return $data;
		}

		$id = (int) ( $postarr['ID'] ?? 0 );

		if ( 0 === $id || ! $this->becomes_public( $data, $postarr, $id ) ) {
			return $data;
		}

		$ref = new ObjectRef( ObjectRef::POST, $id );

		/*
		 * An editor screen sends the values with it, so the submission is
		 * what gets judged. Quick Edit and Bulk Edit send none of them —
		 * they publish straight from the list — so there what is stored is
		 * all there is to go on, and it is enough: no values are arriving
		 * later in those requests, which is what makes reading storage safe
		 * here and wrong for the block editor's own save.
		 */
		if ( $this->is_field_submission() ) {
			$errors = $this->submitted_errors( $ref );
		} elseif ( $this->is_list_edit() ) {
			$errors = $this->stored_errors( $ref );
		} else {
			return $data;
		}

		if ( array() === $errors ) {
			return $data;
		}

		// Back to where it came from. An auto-draft has nowhere to go back to,
		// so it becomes a draft: the values are still saved and the post is
		// still there to finish.
		$previous = (string) ( $postarr['original_post_status'] ?? '' );

		$data['post_status'] = in_array( $previous, array( '', 'auto-draft', 'new' ), true ) ? 'draft' : $previous;

		// Recorded here rather than left to the save path: a submission that
		// carried no values at all produces no errors there, and a refusal
		// nobody explains is worse than the publish it prevented.
		$this->guarded = true;

		// A refusal from a list table has no post screen to report itself on,
		// so it leaves a count behind for the list to pick up. Without it the
		// row simply stays a draft and reads as a button that did nothing.
		if ( ! $this->is_field_submission() ) {
			$key   = self::BLOCKED_PREFIX . 'list_' . get_current_user_id();
			$count = (int) get_transient( $key );

			set_transient( $key, $count + 1, MINUTE_IN_SECONDS * 5 );
		}

		$this->store_errors( $ref, $errors );

		set_transient( $this->blocked_key( $ref ), true, MINUTE_IN_SECONDS * 5 );

		return $data;
	}

	/**
	 * Whether this request is one of our forms, with values to check.
	 *
	 * The nonce is what makes that certain: without it this would also fire on
	 * programmatic inserts and on other plugins' saves, which carry no values
	 * of ours and must not be judged against our fields.
	 */
	private function is_field_submission(): bool {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		return isset( $_POST['wpcmb_values_nonce'] )
			&& (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpcmb_values_nonce'] ) ), self::NONCE );
	}

	/**
	 * Whether this save moves the post into a status the public can see.
	 *
	 * `pending` is not one of them: submitting unfinished work for review is
	 * exactly when a required field is expected to still be empty.
	 *
	 * @param array<string, mixed> $data    Post data about to be written.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @param int                  $id      Post being saved.
	 */
	private function becomes_public( array $data, array $postarr, int $id ): bool {
		$public = array( 'publish', 'future', 'private' );
		$next   = (string) ( $data['post_status'] ?? '' );

		if ( ! in_array( $next, $public, true ) ) {
			return false;
		}

		/*
		 * The status the request says it is coming from, when it says. An
		 * editor form carries it, and it has to win: after the block editor
		 * publishes over REST the row already reads `publish`, and its meta
		 * box post arriving a moment later would otherwise look like no
		 * change at all — which is the case the demote exists for.
		 *
		 * Quick Edit and Bulk Edit carry nothing, so the row itself is asked.
		 * This runs before the write, so the row still holds the status the
		 * post is leaving.
		 */
		$previous = (string) ( $postarr['original_post_status'] ?? '' );

		if ( '' === $previous ) {
			$status   = get_post_status( $id );
			$previous = is_string( $status ) ? $status : '';
		}

		if ( '' === $previous ) {
			return false;
		}

		return ! in_array( $previous, $public, true );
	}

	/**
	 * Whether this request publishes from a list table.
	 *
	 * Quick Edit and Bulk Edit are the two ways a person changes a status
	 * without opening the post, and neither sends a field with it. Narrowed
	 * to those two on purpose: every other route that carries no values is
	 * either the block editor's own save, whose values are moments behind,
	 * or code, whose author can be assumed to mean it.
	 */
	private function is_list_edit(): bool {
		// phpcs:disable WordPress.Security.NonceVerification -- Reading which route this is; core verified the nonce for both of them.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$bulk   = isset( $_POST['bulk_edit'] );
		// phpcs:enable WordPress.Security.NonceVerification

		return 'inline-save' === $action || $bulk;
	}

	/**
	 * Validate what is already stored against the fields that apply.
	 *
	 * @param ObjectRef $ref Object being published.
	 *
	 * @return array<string, string>
	 */
	private function stored_errors( ObjectRef $ref ): array {
		$renderer = $this->container->get( Renderer::class );
		$values   = $this->container->get( Values::class );
		$fields   = array();
		$stored   = array();

		foreach ( $this->container->get( Resolver::class )->fields( new Context( $ref ) ) as $name => $field ) {
			if ( ! $renderer->stores_value( $field ) ) {
				continue;
			}

			$fields[ $name ] = $field;
			$stored[ $name ] = $values->get( $name, $ref, false );
		}

		return $this->container->get( Validator::class )->validate( $fields, $stored );
	}

	/**
	 * Validate the submitted values for an object, as the gate would.
	 *
	 * Every field that applies is checked, including ones the request left
	 * out: an absent required field is the thing being looked for, and unlike
	 * the save path there is nothing here to overwrite by noticing it.
	 *
	 * @param ObjectRef $ref Object being saved.
	 *
	 * @return array<string, string>
	 */
	private function submitted_errors( ObjectRef $ref ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- is_field_submission() verified the nonce; every value is read by the validator, not stored here.
		$submitted = isset( $_POST[ Renderer::INPUT_PREFIX ] ) && is_array( $_POST[ Renderer::INPUT_PREFIX ] )
			? wp_unslash( $_POST[ Renderer::INPUT_PREFIX ] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$renderer = $this->container->get( Renderer::class );
		$fields   = array();
		$values   = array();

		foreach ( $this->container->get( Resolver::class )->fields( new Context( $ref ) ) as $name => $field ) {
			if ( ! $renderer->stores_value( $field ) ) {
				continue;
			}

			$fields[ $name ] = $field;
			$values[ $name ] = $submitted[ $name ] ?? null;
		}

		return $this->container->get( Validator::class )->validate( $fields, $values );
	}

	/**
	 * Report anything a list table refused to publish.
	 *
	 * Quick Edit and Bulk Edit change a status without opening the post, so
	 * a refusal there has nowhere of its own to be explained: the row goes
	 * back to being a draft and nothing says why. This is the line that
	 * says why, and it is a count rather than a list because the two routes
	 * can refuse several posts at once for several different reasons.
	 */
	private function render_list_notice(): void {
		$key   = self::BLOCKED_PREFIX . 'list_' . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( 0 === $count ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of items that stayed unpublished. */
					_n(
						'%d item was not published: open it and fill in its required fields first.',
						'%d items were not published: open them and fill in their required fields first.',
						$count,
						'metafields-custom-fields'
					),
					$count
				)
			)
		);
	}

	/**
	 * The transient key holding one user's errors for one object.
	 *
	 * @param ObjectRef $ref Object reference.
	 */
	private function error_key( ObjectRef $ref ): string {
		return self::ERROR_PREFIX . get_current_user_id() . '_' . $ref;
	}

	/**
	 * The transient key recording a refused publish for an object.
	 *
	 * @param ObjectRef $ref Object reference.
	 */
	private function blocked_key( ObjectRef $ref ): string {
		return self::BLOCKED_PREFIX . get_current_user_id() . '_' . $ref;
	}

	/**
	 * Show a summary notice when the last save had validation errors.
	 */
	public function render_errors(): void {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}

		if ( 'edit' === $screen->base ) {
			/*
			 * Not on the plugin's own list screens.
			 *
			 * The notice is about content a list table refused to publish, and
			 * the transient is one per user rather than one per post type — so
			 * a refusal on Pages was being announced on Block Types, where it
			 * reads as a complaint about the blocks.
			 */
			if ( ! str_starts_with( (string) $screen->post_type, 'wpcmb_' ) ) {
				$this->render_list_notice();
			}

			return;
		}

		if ( ! in_array( $screen->base, array( 'post', 'term', 'user-edit', 'profile', 'comment' ), true ) ) {
			return;
		}

		$ref = $this->current_ref( $screen );

		if ( null === $ref ) {
			return;
		}

		$errors = $this->take_errors( $ref );

		// A refused publish and a saved-anyway warning are different events,
		// and the second wording would be a lie about the first: the values
		// were stored either way, but the post did not go live.
		$blocked = (bool) get_transient( $this->blocked_key( $ref ) );

		if ( array() === $errors && ! $blocked ) {
			return;
		}

		delete_transient( $this->error_key( $ref ) );
		delete_transient( $this->blocked_key( $ref ) );

		printf(
			'<div class="notice notice-%s"><p><strong>%s</strong></p><ul class="wpcmb-error-list">',
			$blocked ? 'error' : 'warning',
			$blocked
				? esc_html__( 'This was not published: fill in the required fields first. Your other changes were saved.', 'metafields-custom-fields' )
				: esc_html__( 'Your changes were saved, but some fields need attention:', 'metafields-custom-fields' )
		);

		foreach ( $errors as $message ) {
			printf( '<li>%s</li>', esc_html( $message ) );
		}

		echo '</ul></div>';
	}

	/**
	 * The object the current admin screen is editing.
	 *
	 * @param \WP_Screen $screen Current screen.
	 */
	private function current_ref( \WP_Screen $screen ): ?ObjectRef {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading which object the screen is showing, not acting on it.
		$id = static function ( string $key ): int {
			return isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
		};

		return match ( $screen->base ) {
			'post'      => 0 !== $id( 'post' ) ? new ObjectRef( ObjectRef::POST, $id( 'post' ) ) : null,
			'term'      => 0 !== $id( 'tag_ID' ) ? new ObjectRef( ObjectRef::TERM, $id( 'tag_ID' ) ) : null,
			'user-edit' => 0 !== $id( 'user_id' ) ? new ObjectRef( ObjectRef::USER, $id( 'user_id' ) ) : null,
			'profile'   => new ObjectRef( ObjectRef::USER, get_current_user_id() ),
			'comment'   => 0 !== $id( 'c' ) ? new ObjectRef( ObjectRef::COMMENT, $id( 'c' ) ) : null,
			default     => null,
		};
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Matching groups for an object.
	 *
	 * @param ObjectRef $ref Object reference.
	 *
	 * @return array<string, FieldGroup>
	 */
	private function groups( ObjectRef $ref ): array {
		return $this->container->get( Resolver::class )->groups( new Context( $ref ) );
	}

	/**
	 * Hide the editor elements the matching groups ask to hide.
	 *
	 * @param ObjectRef $ref Object reference.
	 */
	private function hide_screen_elements( ObjectRef $ref ): void {
		$hidden = array();

		foreach ( $this->groups( $ref ) as $group ) {
			$hidden = array_merge( $hidden, (array) ( $group->settings['hide_on_screen'] ?? array() ) );
		}

		$hidden = array_values( array_unique( array_map( 'strval', $hidden ) ) );

		foreach ( $hidden as $element ) {
			/*
			 * The permalink row and the content editor are not meta boxes, so
			 * remove_meta_box() was quietly doing nothing for them — the two
			 * options have been in the list since the start and have never
			 * hidden anything. They are passed to the script instead, which
			 * hides the elements core does render.
			 */
			if ( in_array( $element, self::SCRIPTED_ELEMENTS, true ) ) {
				continue;
			}

			remove_meta_box( $this->meta_box_id( $element ), get_current_screen(), $this->meta_box_context( $element ) );
		}

		$this->scripted = array_values( array_intersect( self::SCRIPTED_ELEMENTS, $hidden ) );
	}

	/**
	 * The core meta box id for a hideable element.
	 *
	 * @param string $element Element name.
	 */
	private function meta_box_id( string $element ): string {
		return match ( $element ) {
			'excerpt'         => 'postexcerpt',
			'custom_fields'   => 'postcustom',
			'page_attributes' => 'pageparentdiv',
			'discussion'      => 'commentstatusdiv',
			'comments'        => 'commentsdiv',
			'revisions'       => 'revisionsdiv',
			'slug'            => 'slugdiv',
			'author'          => 'authordiv',
			'format'          => 'formatdiv',
			'featured_image'  => 'postimagediv',
			'categories'      => 'categorydiv',
			'tags'            => 'tagsdiv-post_tag',
			'send-trackbacks' => 'trackbacksdiv',
			default           => $element,
		};
	}

	/**
	 * Which meta box context a hideable element lives in.
	 *
	 * @param string $element Element name.
	 */
	private function meta_box_context( string $element ): string {
		return in_array( $element, array( 'categories', 'tags', 'featured_image', 'format', 'author', 'page_attributes' ), true ) ? 'side' : 'normal';
	}
}
