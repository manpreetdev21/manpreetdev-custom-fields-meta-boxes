<?php
/**
 * Integration checks against a real WordPress install.
 *
 * The standalone checks run against hand-written shims, which has twice been
 * a problem: `sanitize_url()` and `wp_strip_all_tags()` both behaved more
 * leniently in the shim than in WordPress, so a check passed on behaviour
 * production did not have. This file exists to cover the places where being
 * wrong about WordPress would matter, using the real functions.
 *
 * Non-destructive by design. It creates its own posts, cleans them up, and
 * never touches anything it did not make — unlike the WordPress test suite,
 * which empties the database it is pointed at.
 *
 * Usage: php tests/integration.php
 *
 * @package WPCMB
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- A developer-run CLI script.

$wpcmb_wp = dirname( __DIR__, 4 );

if ( ! is_readable( $wpcmb_wp . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not find WordPress above the plugin.\n" );
	exit( 1 );
}

define( 'WP_ADMIN', true );
require_once $wpcmb_wp . '/wp-load.php';

if ( ! function_exists( 'wpcmb' ) ) {
	fwrite( STDERR, "The plugin is not active on this install.\n" );
	exit( 1 );
}

wp_set_current_user( 1 );

/**
 * Everything this run created, so it can all be removed again.
 *
 * @var array<int, array{type: string, id: int}>
 */
$GLOBALS['wpcmb_created'] = array();

$GLOBALS['wpcmb_passed'] = 0;
$GLOBALS['wpcmb_failed'] = 0;

/**
 * Assert something, recording the outcome.
 *
 * @param bool   $condition   What must be true.
 * @param string $description What it means.
 */
function wpcmb_is( bool $condition, string $description ): void {
	if ( $condition ) {
		++$GLOBALS['wpcmb_passed'];

		return;
	}

	++$GLOBALS['wpcmb_failed'];

	printf( "  FAIL  %s\n", $description );
}

/**
 * Group a set of assertions under a heading.
 *
 * @param string   $name  Heading.
 * @param callable $tests The assertions.
 */
function wpcmb_group( string $name, callable $tests ): void {
	$before = $GLOBALS['wpcmb_failed'];

	printf( "%s\n", $name );

	try {
		$tests();
	} catch ( \Throwable $error ) {
		++$GLOBALS['wpcmb_failed'];
		printf( "  FAIL  threw: %s\n", $error->getMessage() );
	}

	if ( $before === $GLOBALS['wpcmb_failed'] ) {
		printf( "  ok\n" );
	}
}

/**
 * Create a field group, remembering it for cleanup.
 *
 * @param array<string, mixed> $config Group configuration.
 */
function wpcmb_group_fixture( array $config ): WPCMB\Fields\FieldGroup {
	$repository = wpcmb()->container()->get( WPCMB\Fields\Repository::class );

	$post_id = wp_insert_post(
		array(
			'post_type'   => WPCMB\PostTypes\FieldGroupPostType::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => (string) ( $config['title'] ?? 'Integration group' ),
		)
	);

	$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => (int) $post_id );

	$repository->save( (int) $post_id, WPCMB\Fields\FieldGroup::sanitize( $config ) );
	$repository->flush();

	return $repository->get( (int) $post_id );
}

/**
 * Create a post, remembering it for cleanup.
 *
 * @param string $type  Post type.
 * @param string $title Post title.
 */
function wpcmb_post_fixture( string $type = 'page', string $title = 'Integration target' ): int {
	$post_id = wp_insert_post(
		array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title )
	);

	$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => (int) $post_id );

	return (int) $post_id;
}

/* -------------------------------------------------------------------------
 * Sanitizing, against the real WordPress functions.
 *
 * These are the exact cases where the shims were once more lenient than
 * WordPress, so they are worth pinning to the real thing.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'sanitizing matches real WordPress',
	static function (): void {
		$input = wpcmb()->container()->get( WPCMB\Fields\Registry::class )->get( 'text' );
		$field = array( 'type' => 'url', 'name' => 'u', 'settings' => array() );

		// sanitize_url() prepends a scheme to anything, so free text would be
		// stored as http://not%20a%20url without the field's own guard.
		wpcmb_is( '' === $input->sanitize( 'not a url', $field ), 'free text is not stored as a URL' );
		wpcmb_is(
			'https://example.com/a?b=1' === $input->sanitize( ' https://example.com/a?b=1 ', $field ),
			'a real URL survives'
		);

		foreach ( array( '/about', '#top', 'mailto:a@b.com', 'tel:+441234567890' ) as $relative ) {
			wpcmb_is( '' !== $input->sanitize( $relative, $field ), "a URL field accepts {$relative}" );
		}

		// wp_strip_all_tags() removes script contents, not only the tags.
		$clean = WPCMB\Fields\FieldGroup::sanitize( array( 'title' => '<script>alert(1)</script>Hello' ) );

		wpcmb_is( 'Hello' === $clean['title'], 'a script element loses its contents as well as its tags' );

		// Field names must survive the same normalisation the builder applies.
		wpcmb_is(
			'first_name' === WPCMB\Fields\FieldGroup::sanitize_field_name( 'First Name' ),
			'a label becomes a meta-key-safe name'
		);
	}
);

/* -------------------------------------------------------------------------
 * Values, through real metadata.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'values round-trip through real metadata',
	static function (): void {
		wpcmb_group_fixture(
			array(
				'title'    => 'Integration values',
				'fields'   => array(
					array( 'name' => 'itest_text', 'label' => 'Text', 'type' => 'text' ),
					array( 'name' => 'itest_rows', 'label' => 'Rows', 'type' => 'repeater', 'sub_fields' => array(
						array( 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
					) ),
				),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			)
		);

		$page = wpcmb_post_fixture();

		// update_metadata() unslashes what it is given, so a value written
		// without slashing loses every backslash in it.
		$tricky = 'C:\\Users\\test "quoted" \\n literal';

		wpcmb_update_field( 'itest_text', $tricky, $page );

		wpcmb_is( $tricky === wpcmb_get_field( 'itest_text', $page ), 'backslashes survive a write and read' );
		wpcmb_is( $tricky === get_post_meta( $page, 'itest_text', true ), 'and are intact in the meta table itself' );

		$rows = array( array( 'title' => 'One' ), array( 'title' => 'Two' ) );
		wpcmb_update_field( 'itest_rows', $rows, $page );

		wpcmb_is( $rows === wpcmb_get_field( 'itest_rows', $page ), 'a repeater round-trips' );
		wpcmb_is( 1 === count( get_post_meta( $page, 'itest_rows', false ) ), 'a repeater uses one meta row' );

		wpcmb_delete_field( 'itest_text', $page );

		wpcmb_is( ! wpcmb_has_field( 'itest_text', $page ), 'a deleted value is gone' );
		wpcmb_is( ! metadata_exists( 'post', $page, 'itest_text' ), 'and left no empty row behind' );
	}
);

/* -------------------------------------------------------------------------
 * Location matching against real post types and objects.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'location rules match real objects',
	static function (): void {
		wpcmb_group_fixture(
			array(
				'title'    => 'Integration pages only',
				'fields'   => array( array( 'name' => 'itest_page_only', 'label' => 'Pages', 'type' => 'text' ) ),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			)
		);

		$page = wpcmb_post_fixture( 'page' );
		$post = wpcmb_post_fixture( 'post' );

		$on_page = array_map( static fn( $g ) => $g->title, wpcmb_get_field_groups( $page ) );
		$on_post = array_map( static fn( $g ) => $g->title, wpcmb_get_field_groups( $post ) );

		wpcmb_is( in_array( 'Integration pages only', $on_page, true ), 'the group applies to a page' );
		wpcmb_is( ! in_array( 'Integration pages only', $on_post, true ), 'and not to a post' );
	}
);

/* -------------------------------------------------------------------------
 * Rendering every registered type, looking for notices.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'every field type renders without a notice',
	static function (): void {
		$registry = wpcmb()->container()->get( WPCMB\Fields\Registry::class );
		$renderer = wpcmb()->container()->get( WPCMB\Fields\Renderer::class );

		$fields = array();

		foreach ( array_keys( $registry->all() ) as $type ) {
			$fields[] = array(
				'name'     => 'itest_' . $type,
				'label'    => ucfirst( $type ),
				'type'     => $type,
				'settings' => array( 'choices' => "a : Alpha\nb : Beta", 'message' => 'Hi' ),
			);
		}

		$group = wpcmb_group_fixture(
			array(
				'title'    => 'Integration all types',
				'fields'   => $fields,
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			)
		);

		$notices = array();

		set_error_handler(
			static function ( int $number, string $message ) use ( &$notices ): bool {
				$notices[] = $message;

				return true;
			}
		);

		ob_start();
		$renderer->group( $group, new WPCMB\Fields\ObjectRef( 'post', wpcmb_post_fixture() ) );
		$html = (string) ob_get_clean();

		restore_error_handler();

		wpcmb_is( array() === $notices, 'no PHP notices: ' . implode( '; ', array_slice( $notices, 0, 3 ) ) );
		wpcmb_is( strlen( $html ) > 1000, 'the group produced markup' );
		wpcmb_is( count( $registry->all() ) >= 50, 'every type is registered' );
	}
);

/* -------------------------------------------------------------------------
 * REST, through the real server.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'REST enforces its permission boundaries',
	static function (): void {
		wpcmb_group_fixture(
			array(
				'title'    => 'Integration REST',
				'fields'   => array( array( 'name' => 'itest_rest', 'label' => 'Rest', 'type' => 'text' ) ),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			)
		);

		$page = wpcmb_post_fixture();

		do_action( 'init' );
		$server = rest_get_server();
		do_action( 'rest_api_init', $server );

		/**
		 * Dispatch a request.
		 *
		 * @param string                    $method Method.
		 * @param string                    $route  Route.
		 * @param array<string, mixed>|null $body   Body parameters.
		 */
		$dispatch = static function ( string $method, string $route, ?array $body = null ) use ( $server ) {
			$request = new WP_REST_Request( $method, $route );

			if ( null !== $body ) {
				$request->set_body_params( $body );
			}

			return $server->dispatch( $request );
		};

		wp_set_current_user( 0 );

		wpcmb_is( 401 === $dispatch( 'GET', '/wpcmb/v1/field-groups' )->get_status(), 'anon cannot list groups' );
		wpcmb_is( 200 === $dispatch( 'GET', '/wpcmb/v1/values/post/' . $page )->get_status(), 'anon can read a published page' );
		wpcmb_is(
			401 === $dispatch( 'POST', '/wpcmb/v1/values/post/' . $page, array( 'values' => array( 'itest_rest' => 'x' ) ) )->get_status(),
			'anon cannot write'
		);

		wp_set_current_user( 1 );

		$write = $dispatch( 'POST', '/wpcmb/v1/values/post/' . $page, array( 'values' => array( 'itest_rest' => '  spaced  ' ) ) );

		wpcmb_is( 200 === $write->get_status(), 'an admin can write' );
		wpcmb_is( 'spaced' === $write->get_data()['values']['itest_rest'], 'the value is sanitized on the way in' );

		$core = $dispatch( 'GET', '/wp/v2/pages/' . $page );
		$meta = $core->get_data()['meta'] ?? array();

		wpcmb_is( 'spaced' === ( $meta['itest_rest'] ?? null ), 'the value appears on the core endpoint too' );
	}
);

/* -------------------------------------------------------------------------
 * The publish guard.
 *
 * The browser gate is what an editor experiences, but it is a script, and a
 * script can be off, broken or bypassed. This is the half that holds anyway,
 * so it is checked against real posts with real statuses rather than shims.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'a post cannot be published while a required field is empty',
	static function (): void {
		$page = wpcmb_post_fixture( 'page', 'Integration required' );

		wpcmb_group_fixture(
			array(
				'title'    => 'Integration required',
				'fields'   => array(
					array( 'name' => 'itest_needed', 'label' => 'Needed', 'type' => 'text', 'required' => true ),
					array( 'name' => 'itest_spare', 'label' => 'Spare', 'type' => 'text' ),
				),
				'location' => array( array( array( 'param' => 'page', 'operator' => '==', 'value' => (string) $page ) ) ),
			)
		);

		/**
		 * Try to publish the page with the given values submitted.
		 *
		 * Builds the request a form submission would, because that is what the
		 * guard reads: the nonce is what tells it the values are ours to judge.
		 *
		 * @param array<string, mixed> $values Field values.
		 *
		 * @return string The status the post ended up with.
		 */
		$publish = static function ( array $values ) use ( $page ): string {
			wp_update_post( array( 'ID' => $page, 'post_status' => 'draft' ) );

			$_POST = array(
				'wpcmb_values_nonce'   => wp_create_nonce( 'wpcmb_save_values' ),
				'wpcmb_values'         => $values,
				'post_ID'              => $page,
				'post_status'          => 'publish',
				'original_post_status' => 'draft',
			);

			$_REQUEST = $_POST;

			wp_update_post(
				array(
					'ID'                   => $page,
					'post_status'          => 'publish',
					'original_post_status' => 'draft',
				)
			);

			$status = (string) get_post( $page )->post_status;

			$_POST    = array();
			$_REQUEST = array();

			return $status;
		};

		wpcmb_is( 'draft' === $publish( array() ), 'publishing with nothing submitted is refused' );
		wpcmb_is( 'draft' === $publish( array( 'itest_needed' => '' ) ), 'publishing with the field empty is refused' );

		/*
		 * Quick Edit and Bulk Edit publish from the list without opening the
		 * post, so they carry none of the plugin's fields and the browser
		 * gate is not there at all. They used to sail straight past the
		 * guard: the whole point of a required field is that this cannot
		 * happen, whichever button does it.
		 */
		$list_publish = static function ( array $request ) use ( $page ): string {
			wp_update_post( array( 'ID' => $page, 'post_status' => 'draft' ) );
			delete_post_meta( $page, 'itest_needed' );

			$_POST    = $request;
			$_REQUEST = $request;

			// No `original_post_status`: neither route sends one, so the guard
			// has to read the status off the row it is about to overwrite.
			wp_update_post( array( 'ID' => $page, 'post_status' => 'publish' ) );

			$status = (string) get_post( $page )->post_status;

			$_POST    = array();
			$_REQUEST = array();

			return $status;
		};

		wpcmb_is(
			'draft' === $list_publish( array( 'action' => 'inline-save', 'post_status' => 'publish' ) ),
			'Quick Edit cannot publish past a required field'
		);

		wpcmb_is(
			'draft' === $list_publish( array( 'action' => 'edit', 'bulk_edit' => 'Update', '_status' => 'publish' ) ),
			'and neither can Bulk Edit'
		);

		// With the field filled in, both routes go through.
		wp_update_post( array( 'ID' => $page, 'post_status' => 'draft' ) );
		update_post_meta( $page, 'itest_needed', 'filled in' );

		$_POST    = array( 'action' => 'inline-save', 'post_status' => 'publish' );
		$_REQUEST = $_POST;

		wp_update_post( array( 'ID' => $page, 'post_status' => 'publish' ) );

		wpcmb_is( 'publish' === get_post( $page )->post_status, 'Quick Edit publishes once it is filled in' );

		$_POST    = array();
		$_REQUEST = array();

		delete_post_meta( $page, 'itest_needed' );

		// The refusal has to explain itself, or it reads as a broken button.
		$errors = get_transient( 'wpcmb_errors_' . get_current_user_id() . '_post_' . $page );

		wpcmb_is( is_array( $errors ) && isset( $errors['itest_needed'] ), 'the reason is recorded for the notice' );
		wpcmb_is(
			(bool) get_transient( 'wpcmb_blocked_' . get_current_user_id() . '_post_' . $page ),
			'the refusal is recorded, so the notice can say "not published"'
		);

		// Whatever else was typed is still kept: refusing to publish must not
		// also throw away the work.
		$publish( array( 'itest_spare' => 'kept' ) );

		wpcmb_is( 'kept' === get_post_meta( $page, 'itest_spare', true ), 'other values are still saved' );

		wpcmb_is( 'publish' === $publish( array( 'itest_needed' => 'filled in' ) ), 'publishing works once it is filled in' );

		// A draft is work in progress. Saving one must never be refused, or a
		// half-written page cannot be kept at all.
		wp_update_post( array( 'ID' => $page, 'post_status' => 'publish' ) );

		$_POST = array(
			'wpcmb_values_nonce'   => wp_create_nonce( 'wpcmb_save_values' ),
			'wpcmb_values'         => array( 'itest_needed' => '' ),
			'post_ID'              => $page,
			'post_status'          => 'draft',
			'original_post_status' => 'publish',
		);

		$_REQUEST = $_POST;

		wp_update_post( array( 'ID' => $page, 'post_status' => 'draft', 'original_post_status' => 'publish' ) );

		wpcmb_is( 'draft' === get_post( $page )->post_status, 'saving as a draft is never refused' );

		// An already-public post is left public. An edit that happens to leave
		// a required field empty must not take a live page off the site.
		wp_update_post( array( 'ID' => $page, 'post_status' => 'publish' ) );

		$_POST = array(
			'wpcmb_values_nonce'   => wp_create_nonce( 'wpcmb_save_values' ),
			'wpcmb_values'         => array( 'itest_needed' => '' ),
			'post_ID'              => $page,
			'post_status'          => 'publish',
			'original_post_status' => 'publish',
		);

		$_REQUEST = $_POST;

		wp_update_post( array( 'ID' => $page, 'post_status' => 'publish', 'original_post_status' => 'publish' ) );

		wpcmb_is( 'publish' === get_post( $page )->post_status, 'a published page is never unpublished by the guard' );

		$_POST    = array();
		$_REQUEST = array();

		// Without the nonce this is somebody else's save, carrying none of our
		// values, and must pass through untouched.
		wp_update_post( array( 'ID' => $page, 'post_status' => 'draft' ) );
		wp_update_post( array( 'ID' => $page, 'post_status' => 'publish', 'original_post_status' => 'draft' ) );

		wpcmb_is( 'publish' === get_post( $page )->post_status, 'a save that is not ours is not judged' );
	}
);

/* -------------------------------------------------------------------------
 * Options pages.
 *
 * The screen is discovered from the location rules, so what matters is that
 * the discovery agrees with the rules, that the menu entry really appears,
 * and that values land on the object the page is named after. Those are all
 * WordPress-side facts, which is why they are checked here.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'options pages are created from the rules that name them',
	static function (): void {
		wpcmb_group_fixture(
			array(
				'title'    => 'Integration Options',
				'fields'   => array(
					array( 'name' => 'itest_company', 'label' => 'Company', 'type' => 'text' ),
					array( 'name' => 'itest_flag', 'label' => 'Flag', 'type' => 'true_false' ),
				),
				'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'itest-options' ) ) ),
			)
		);

		// A rule saying where a group is *not* describes no page to build.
		wpcmb_group_fixture(
			array(
				'title'    => 'Integration Options Excluded',
				'fields'   => array( array( 'name' => 'itest_nowhere', 'label' => 'Nowhere', 'type' => 'text' ) ),
				'location' => array( array( array( 'param' => 'options_page', 'operator' => '!=', 'value' => 'itest-absent' ) ) ),
			)
		);

		// A group belonging to a post type must not follow the fields onto an
		// options screen, which is the leak worth checking for.
		wpcmb_group_fixture(
			array(
				'title'    => 'Integration Options Foreign',
				'fields'   => array( array( 'name' => 'itest_foreign', 'label' => 'Foreign', 'type' => 'text' ) ),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			)
		);

		$module = wpcmb()->container()->get( WPCMB\Admin\OptionsPages::class );
		$pages  = $module->pages();

		wpcmb_is( isset( $pages['itest-options'] ), 'the page named by the rule is discovered' );
		wpcmb_is( 'Integration Options' === ( $pages['itest-options']['title'] ?? '' ), 'it takes the group title' );
		wpcmb_is( ! isset( $pages['itest-absent'] ), 'a "is not" rule creates nothing' );

		// The menu entry itself, because a page nobody can reach is no page.
		$module->register();

		wpcmb_is(
			isset( $GLOBALS['admin_page_hooks']['wpcmb-options-itest-options'] ),
			'the admin menu entry is registered'
		);

		$titles = wp_list_pluck( $GLOBALS['menu'] ?? array(), 0 );
		$label  = '';

		foreach ( $titles as $title ) {
			if ( is_string( $title ) && str_contains( $title, 'Integration Options' ) ) {
				$label = $title;
			}
		}

		wpcmb_is( str_contains( $label, 'itest-options' ), 'the menu label carries the slug, got: ' . $label );

		// Values belong to the page, under the plugin's own option names, and
		// come back through the same reference a theme would use.
		$ref = new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::OPTION, 'itest-options' );

		wpcmb()->container()->get( WPCMB\Fields\Values::class )->update( 'itest_company', '  Spaced Ltd  ', $ref );

		wpcmb_is( 'Spaced Ltd' === get_option( 'wpcmb_itest-options_itest_company' ), 'the value is stored as a prefixed option' );
		wpcmb_is( 'Spaced Ltd' === wpcmb_get_field( 'itest_company', 'options_itest-options' ), 'and reads back through the template function' );

		delete_option( 'wpcmb_itest-options_itest_company' );

		// The page resolves the same fields the renderer will draw, which is
		// what makes "no custom code in the theme" true.
		$fields = wpcmb()->container()->get( WPCMB\Fields\Resolver::class )->fields(
			new WPCMB\Fields\Context( $ref )
		);

		wpcmb_is( isset( $fields['itest_company'], $fields['itest_flag'] ), 'the page resolves its own fields' );
		wpcmb_is( ! isset( $fields['itest_foreign'] ), 'a post type group does not leak onto it' );

		// "Is not some other options page" is true here, so that group does
		// belong on this screen. It is the same rule the meta boxes follow.
		wpcmb_is( isset( $fields['itest_nowhere'] ), 'an "is not" rule still matches a different page' );
	}
);

/* -------------------------------------------------------------------------
 * Every location rule parameter, against a real object.
 *
 * A rule that resolves to null on the object it describes can never match
 * anything, and nothing says so: the group simply never appears, which reads
 * as "the plugin does not work" rather than as a bug in one rule. `comment`
 * was exactly that — it read the post type of the referenced object, which is
 * only set when the reference is a post, so it matched post screens and never
 * comment screens.
 *
 * So each parameter is checked three ways: it resolves on its own object, a
 * rule naming that value matches, and a rule naming something else does not.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'every location rule parameter matches its own object',
	static function (): void {
		$post = wpcmb_post_fixture( 'post', 'Location rules probe' );
		$root = wpcmb_post_fixture( 'page', 'Location rules parent' );

		$page = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Location rules child',
				'post_parent' => $root,
			)
		);

		$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => (int) $page );

		update_post_meta( $page, '_wp_page_template', 'itest-template.php' );

		$attachment = wpcmb_post_fixture( 'attachment', 'Location rules file' );

		set_post_format( $post, 'aside' );

		$term = wp_insert_term( 'Location rules term', 'category' );
		$term_id = is_wp_error( $term ) ? 0 : (int) $term['term_id'];

		wp_set_post_terms( $post, array( $term_id ), 'category' );

		$comment = wp_insert_comment(
			array( 'comment_post_ID' => $post, 'comment_content' => 'probe', 'comment_approved' => 1 )
		);

		$menu = wp_create_nav_menu( 'Location rules menu' );
		$menu_id = is_wp_error( $menu ) ? 0 : (int) $menu;

		$item = 0 === $menu_id ? 0 : wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Probe item',
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
				'menu-item-url'    => '/',
			)
		);

		$ref = static fn( string $type, $id ) => new WPCMB\Fields\ObjectRef( $type, $id );
		$ctx = static fn( WPCMB\Fields\ObjectRef $object, array $extra = array() ) => new WPCMB\Fields\Context( $object, $extra );

		$post_ref = $ref( WPCMB\Fields\ObjectRef::POST, (int) $post );
		$page_ref = $ref( WPCMB\Fields\ObjectRef::POST, (int) $page );

		$cases = array(
			'post_type'         => array( $ctx( $post_ref ), 'post' ),
			'post_status'       => array( $ctx( $post_ref ), 'publish' ),
			'post_format'       => array( $ctx( $post_ref ), 'aside' ),
			'post_category'     => array( $ctx( $post_ref ), 'location-rules-term' ),
			'post_taxonomy'     => array( $ctx( $post_ref ), 'category' ),
			'post'              => array( $ctx( $post_ref ), (string) $post ),
			'post_template'     => array( $ctx( $page_ref ), 'itest-template.php' ),
			'page_template'     => array( $ctx( $page_ref ), 'itest-template.php' ),
			'page_type'         => array( $ctx( $page_ref ), 'child' ),
			'page_parent'       => array( $ctx( $page_ref ), (string) $root ),
			'page'              => array( $ctx( $page_ref ), (string) $page ),
			'attachment'        => array( $ctx( $ref( WPCMB\Fields\ObjectRef::POST, (int) $attachment ) ), 'all' ),
			'comment'           => array( $ctx( $ref( WPCMB\Fields\ObjectRef::COMMENT, (int) $comment ) ), 'post' ),
			'taxonomy'          => array( $ctx( $ref( WPCMB\Fields\ObjectRef::TERM, $term_id ) ), 'category' ),
			'user_role'         => array( $ctx( $ref( WPCMB\Fields\ObjectRef::USER, 1 ) ), 'administrator' ),
			'user_form'         => array( $ctx( $ref( WPCMB\Fields\ObjectRef::USER, 1 ), array( 'user_form' => 'edit' ) ), 'edit' ),
			'options_page'      => array( $ctx( $ref( WPCMB\Fields\ObjectRef::OPTION, 'itest-options-rule' ) ), 'itest-options-rule' ),
			'widget'            => array( $ctx( $post_ref, array( 'widget' => 'text' ) ), 'text' ),
			'block'             => array( $ctx( $post_ref, array( 'block' => 'itest/block' ) ), 'itest/block' ),
			'current_user'      => array( $ctx( $post_ref ), 'logged_in' ),
			'current_user_role' => array( $ctx( $post_ref ), 'administrator' ),
		);

		if ( 0 !== $item ) {
			$cases['nav_menu_item'] = array( $ctx( $ref( WPCMB\Fields\ObjectRef::POST, (int) $item ) ), 'all' );
		}

		if ( 0 !== $menu_id ) {
			$cases['nav_menu'] = array( $ctx( $ref( WPCMB\Fields\ObjectRef::TERM, $menu_id ) ), (string) $menu_id );
		}

		foreach ( $cases as $param => $case ) {
			list( $context, $expected ) = $case;

			$matches = WPCMB\Fields\Locations::match(
				array( array( array( 'param' => $param, 'operator' => '==', 'value' => $expected ) ) ),
				$context
			);

			$anything = WPCMB\Fields\Locations::match(
				array( array( array( 'param' => $param, 'operator' => '==', 'value' => 'itest-not-this-value' ) ) ),
				$context
			);

			wpcmb_is( $matches, sprintf( '%s matches its own object', $param ) );
			wpcmb_is( ! $anything, sprintf( '%s does not match anything else', $param ) );
		}

		// The two halves of the comment bug, named rather than left implied.
		$comment_rule = array( array( array( 'param' => 'comment', 'operator' => '==', 'value' => 'post' ) ) );

		wpcmb_is(
			! WPCMB\Fields\Locations::match( $comment_rule, $ctx( $post_ref ) ),
			'a comment rule does not follow the fields onto a post screen'
		);

		wpcmb_is(
			! WPCMB\Fields\Locations::match(
				array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
				$ctx( $ref( WPCMB\Fields\ObjectRef::COMMENT, (int) $comment ) )
			),
			'and a post type rule does not follow them onto a comment screen'
		);

		wp_delete_comment( (int) $comment, true );

		if ( 0 !== $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		if ( 0 !== $item ) {
			wp_delete_post( (int) $item, true );
		}

		if ( 0 !== $menu_id ) {
			wp_delete_nav_menu( $menu_id );
		}
	}
);

/* -------------------------------------------------------------------------
 * Validation on an options page.
 *
 * An options page is the one screen where the fields are not on a post, so it
 * has its own save path — and a validation rule that works on a post proves
 * nothing about it. Every kind of rule is submitted here, and the errors are
 * followed through the redirect that carries them.
 * ---------------------------------------------------------------------- */

wpcmb_group(
	'options page fields are validated, and their errors are shown once',
	static function (): void {
		$slug = 'itest-opt-validation';

		wpcmb_group_fixture(
			array(
				'title'    => 'Integration Options Validation',
				'fields'   => array(
					array( 'name' => 'iov_required', 'label' => 'Required', 'type' => 'text', 'required' => true ),
					array( 'name' => 'iov_email', 'label' => 'Email', 'type' => 'email' ),
					array( 'name' => 'iov_number', 'label' => 'Number', 'type' => 'number', 'settings' => array( 'min' => '5', 'max' => '10' ) ),
					array(
						'name'       => 'iov_rows',
						'label'      => 'Rows',
						'type'       => 'repeater',
						'sub_fields' => array(
							array( 'name' => 'iov_row_text', 'label' => 'Row text', 'type' => 'text', 'required' => true ),
						),
					),
				),
				'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => $slug ) ) ),
			)
		);

		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$module = wpcmb()->container()->get( WPCMB\Admin\OptionsPages::class );

		$module->register();

		$hook = 'toplevel_page_wpcmb-options-' . $slug;
		$key  = 'wpcmb_options_result_' . get_current_user_id() . '_' . $slug;

		// The handler ends in a redirect and an exit. Turning the redirect into
		// an exception stops the request exactly where the browser would be
		// sent away, with everything it wrote still there to look at.
		$stop = static function ( $location ) {
			throw new RuntimeException( (string) $location );
		};

		add_filter( 'wp_redirect', $stop );

		/**
		 * Submit the page, and report where it redirected.
		 *
		 * @param array<string, mixed> $values Field values.
		 */
		$submit = static function ( array $values ) use ( $hook, $key ): string {
			delete_transient( $key );

			$_POST = array(
				'wpcmb_options_nonce' => wp_create_nonce( 'wpcmb_save_options' ),
				'wpcmb_values'        => $values,
			);

			$_REQUEST = $_POST;
			$landed   = '';

			try {
				do_action( 'load-' . $hook );
			} catch ( RuntimeException $stopped ) {
				$landed = $stopped->getMessage();
			}

			$_POST    = array();
			$_REQUEST = array();

			return $landed;
		};

		$errors_of = static function () use ( $key ): array {
			$errors = get_transient( $key );

			return is_array( $errors ) ? $errors : array();
		};

		// Every rule, on the screen that has its own save path.
		$submit( array( 'iov_required' => '', 'iov_rows' => array( array( 'iov_row_text' => 'ok' ) ) ) );
		wpcmb_is( isset( $errors_of()['iov_required'] ), 'a required field is caught' );

		$submit( array( 'iov_required' => 'set', 'iov_email' => 'not-an-email', 'iov_rows' => array( array( 'iov_row_text' => 'ok' ) ) ) );
		wpcmb_is( isset( $errors_of()['iov_email'] ), 'a malformed email is caught' );

		$submit( array( 'iov_required' => 'set', 'iov_number' => '99', 'iov_rows' => array( array( 'iov_row_text' => 'ok' ) ) ) );
		wpcmb_is( isset( $errors_of()['iov_number'] ), 'a number out of range is caught' );

		$submit( array( 'iov_required' => 'set', 'iov_rows' => array( array( 'iov_row_text' => '' ) ) ) );
		$row_error = $errors_of()['iov_rows'] ?? '';
		wpcmb_is( '' !== $row_error, 'a required sub field is caught' );
		wpcmb_is( str_contains( $row_error, 'Row 1' ), 'and the message names the row' );

		$landed = $submit( array( 'iov_required' => 'set', 'iov_rows' => array( array( 'iov_row_text' => 'ok' ) ) ) );
		wpcmb_is( array() === $errors_of(), 'a valid save reports nothing' );
		wpcmb_is( str_contains( $landed, 'wpcmb-saved=1' ), 'and says so in the redirect' );
		wpcmb_is( 'set' === get_option( 'wpcmb_' . $slug . '_iov_required' ), 'the value is stored' );

		// The errors belong to the screen the save redirects to. Left in place
		// they went on marking fields red for five minutes, so the next person
		// to open the page met errors about values they had never touched.
		$submit( array( 'iov_required' => '' ) );

		wpcmb_is( array() !== $errors_of(), 'a failed save leaves its errors for the next screen' );

		$render = static function () use ( $hook ): string {
			ob_start();
			do_action( $hook );

			return (string) ob_get_clean();
		};

		$_GET['wpcmb-saved'] = '0';

		$landing = $render();

		wpcmb_is( str_contains( $landing, 'Required is required.' ), 'the landing screen shows them' );
		wpcmb_is( array() === $errors_of(), 'and consumes them' );

		unset( $_GET['wpcmb-saved'] );

		/*
		 * A later visit is a later request, and in one process the module above
		 * is still holding this request's result — so its render callback is
		 * taken off the hook and a fresh module put on in its place. Without
		 * that, the test would measure the harness rather than the page.
		 */
		remove_all_actions( $hook );

		$later = new WPCMB\Admin\OptionsPages( wpcmb()->container() );

		$later->register();

		ob_start();
		do_action( $hook );
		$next = (string) ob_get_clean();

		wpcmb_is( ! str_contains( $next, 'Required is required.' ), 'a later visit shows no stale errors' );

		remove_filter( 'wp_redirect', $stop );

		foreach ( array( 'iov_required', 'iov_email', 'iov_number', 'iov_rows' ) as $name ) {
			delete_option( 'wpcmb_' . $slug . '_' . $name );
		}

		delete_transient( $key );
	}
);

wpcmb_group(
	'a group kept out of the REST API registers no meta',
	static function (): void {
		$exposed = wpcmb_group_fixture(
			array(
				'title'    => 'REST exposed',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
				'fields'   => array( array( 'name' => 'irest_shown', 'label' => 'Shown', 'type' => 'text' ) ),
			)
		);

		$private = wpcmb_group_fixture(
			array(
				'title'    => 'REST private',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
				'fields'   => array( array( 'name' => 'irest_hidden', 'label' => 'Hidden', 'type' => 'text' ) ),
				'settings' => array( 'show_in_rest' => '0' ),
			)
		);

		wpcmb_is( true === $exposed->settings['show_in_rest'], 'a group is exposed unless told otherwise' );
		wpcmb_is( false === $private->settings['show_in_rest'], 'and hidden when it is' );

		/*
		 * register_meta() is what puts a field in the REST API and in the
		 * block editor both, so the check is whether the registration
		 * happened — not what a request returns.
		 */
		$registrar = new WPCMB\REST\MetaRegistrar( wpcmb()->container() );

		$registrar->register();

		$registered = get_registered_meta_keys( 'post', 'post' );

		wpcmb_is( isset( $registered['irest_shown'] ), 'an exposed field is registered' );
		wpcmb_is( ! isset( $registered['irest_hidden'] ), 'a hidden field is not' );

		unregister_meta_key( 'post', 'irest_shown', 'post' );
	}
);

wpcmb_group(
	'an options page honours its own capability and menu parent',
	static function (): void {
		$slug = 'icap-' . strtolower( wp_generate_password( 6, false, false ) );

		wpcmb_group_fixture(
			array(
				'title'    => 'Capability page',
				'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => $slug ) ) ),
				'fields'   => array( array( 'name' => 'icap_text', 'label' => 'Text', 'type' => 'text' ) ),
				'settings' => array(
					'options_capability' => 'edit_pages',
					'options_parent'     => 'tools.php',
					'options_icon'       => 'dashicons-cart',
				),
			)
		);

		$pages = ( new WPCMB\Admin\OptionsPages( wpcmb()->container() ) )->pages();

		wpcmb_is( isset( $pages[ $slug ] ), 'the page is discovered' );
		wpcmb_is( 'edit_pages' === ( $pages[ $slug ]['capability'] ?? '' ), 'with its capability' );
		wpcmb_is( 'tools.php' === ( $pages[ $slug ]['parent'] ?? '' ), 'and its menu parent' );

		/*
		 * The gate has to agree with the menu. While the capability lived in
		 * Permissions as a constant, lowering it here would have shown the
		 * item to an editor and then refused them at the door.
		 */
		$module = new WPCMB\Admin\OptionsPages( wpcmb()->container() );

		$module->boot();

		$ref = new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::OPTION, $slug );

		$editor = wp_insert_user(
			array(
				'user_login' => 'wpcmb_editor_' . wp_generate_password( 6, false, false ),
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => 'editor',
			)
		);

		if ( is_wp_error( $editor ) ) {
			wpcmb_is( false, 'could not create an editor to test with' );

			return;
		}

		$was = get_current_user_id();

		wp_set_current_user( (int) $editor );

		wpcmb_is( WPCMB\Fields\Permissions::can_edit( $ref ), 'an editor may open a page set to edit_pages' );

		$other = new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::OPTION, 'not-one-of-ours' );

		wpcmb_is( ! WPCMB\Fields\Permissions::can_edit( $other ), 'an options object we did not create keeps the default rule' );

		wp_set_current_user( $was );
		require_once ABSPATH . 'wp-admin/includes/user.php';

		wp_delete_user( (int) $editor );

		remove_filter( 'wpcmb/permissions/read', array( $module, 'allow' ), 10 );
		remove_filter( 'wpcmb/permissions/edit', array( $module, 'allow' ), 10 );
	}
);

wpcmb_group(
	'instruction placement moves the instructions, and nothing else',
	static function (): void {
		$render = static function ( string $placement ): string {
			$group = wpcmb_group_fixture(
				array(
					'title'    => 'Instructions ' . $placement,
					'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
					'fields'   => array(
						array(
							'name'         => 'iinstr_text',
							'label'        => 'Text',
							'type'         => 'text',
							'instructions' => 'Say something here.',
						),
					),
					'settings' => array( 'instruction_placement' => $placement ),
				)
			);

			$renderer = wpcmb()->container()->get( WPCMB\Fields\Renderer::class );
			$ref      = new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::POST, wpcmb_post_fixture( 'post' ) );

			ob_start();
			$renderer->group( $group, $ref );

			return (string) ob_get_clean();
		};

		$below = $render( 'field' );
		$above = $render( 'label' );

		$position = static fn( string $html ): int => (int) strpos( $html, 'wpcmb-field__instructions' );
		$control  = static fn( string $html ): int => (int) strpos( $html, 'wpcmb-field__control' );

		wpcmb_is( str_contains( $below, 'Say something here.' ), 'the instructions render' );
		wpcmb_is( $position( $below ) > $control( $below ), 'below the control by default' );
		wpcmb_is( $position( $above ) < $control( $above ), 'and above it when asked' );
		wpcmb_is( 1 === substr_count( $above, 'Say something here.' ), 'in one place, not both' );
	}
);

wpcmb_group(
	'the plugin menu keeps one order, whatever registered first',
	static function (): void {
		$menu = new WPCMB\Admin\Menu( wpcmb()->container() );

		/*
		 * Deliberately in the worst order: this is what the live menu looked
		 * like, because post types register on `init` and pages on
		 * `admin_menu`, and a submissions type does not exist at all until
		 * its first submission arrives.
		 */
		$GLOBALS['submenu']['wpcmb'] = array(
			array( 'Field Groups', 'edit_posts', 'edit.php?post_type=wpcmb_field_group' ),
			array( 'A form', 'edit_posts', 'edit.php?post_type=wpcmb_a_form_0001' ),
			array( 'Overview', 'edit_posts', 'wpcmb' ),
			array( 'An options page', 'manage_options', 'wpcmb-options-site' ),
			array( 'Settings', 'manage_options', 'wpcmb-settings' ),
			array( 'Tools', 'manage_options', 'wpcmb-tools' ),
			array( 'Another form', 'edit_posts', 'edit.php?post_type=wpcmb_b_form_0002' ),
			array( 'Post Types', 'edit_posts', 'edit.php?post_type=wpcmb_post_type' ),
		);

		$menu->order_submenu();

		$slugs = array_map( static fn( array $item ): string => $item[2], $GLOBALS['submenu']['wpcmb'] );

		wpcmb_is(
			array(
				'wpcmb',
				'edit.php?post_type=wpcmb_field_group',
				'edit.php?post_type=wpcmb_post_type',
				'wpcmb-settings',
				'wpcmb-tools',
				'edit.php?post_type=wpcmb_a_form_0001',
				'edit.php?post_type=wpcmb_b_form_0002',
				'wpcmb-options-site',
			) === $slugs,
			'Overview, what is built here, the plugin screens, then forms and options pages'
		);

		// Two forms keep the order they registered in rather than swapping
		// places between page loads.
		wpcmb_is(
			array_search( 'edit.php?post_type=wpcmb_a_form_0001', $slugs, true )
				< array_search( 'edit.php?post_type=wpcmb_b_form_0002', $slugs, true ),
			'and entries of the same kind keep their relative order'
		);

		// Nothing is lost or invented on the way through.
		wpcmb_is( 8 === count( $GLOBALS['submenu']['wpcmb'] ), 'every entry survives the sort' );

		$menu->order_submenu();

		wpcmb_is( $slugs === array_map( static fn( array $item ): string => $item[2], $GLOBALS['submenu']['wpcmb'] ), 'sorting twice changes nothing' );

		unset( $GLOBALS['submenu']['wpcmb'] );

		// An empty menu is the normal state on a screen the plugin is not on.
		$menu->order_submenu();

		wpcmb_is( ! isset( $GLOBALS['submenu']['wpcmb'] ), 'a menu that is not there is left alone' );
	}
);

wpcmb_group(
	'a relationship field offers and returns only what the reader may see',
	static function (): void {
		$draft = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Integration draft ' . wp_generate_password( 6, false, false ),
			)
		);

		$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => (int) $draft );

		$published = wpcmb_post_fixture( 'post', 'Integration published' );

		$registry = wpcmb()->container()->get( WPCMB\Fields\Registry::class );
		$handler  = $registry->get( 'post_object' );
		$field    = array( 'type' => 'post_object', 'name' => 'r', 'label' => 'Related', 'settings' => array( 'post_type' => 'post' ) );

		$render = static function () use ( $handler, $field ): string {
			ob_start();
			$handler->render( $field, '', 'wpcmb_values[r]', 'wpcmb-r' );

			return (string) ob_get_clean();
		};

		$title = get_post( (int) $draft )->post_title;

		// An editor is choosing what to relate, and should see the draft they
		// are about to publish.
		wpcmb_is( str_contains( $render(), esc_html( $title ) ), 'an administrator is offered a draft' );

		$was = get_current_user_id();

		wp_set_current_user( 0 );

		/*
		 * The same control renders on a public form, where every option is a
		 * title handed to whoever asked for the page.
		 */
		$public = $render();

		wpcmb_is( ! str_contains( $public, esc_html( $title ) ), 'a logged-out visitor is not' );
		wpcmb_is( str_contains( $public, esc_html( 'Integration published' ) ), 'and is still offered published posts' );

		// A stored id outlives the status of what it points at.
		$object = array( 'type' => 'post_object', 'name' => 'r', 'settings' => array( 'return_format' => 'object' ) );

		wpcmb_is( null === $handler->format( $draft, $object ), 'a draft is not returned to a logged-out reader' );
		wpcmb_is( get_post( $published ) == $handler->format( $published, $object ), 'a published post still is' );

		// A WP_User carries the email address and the login name.
		$user = array( 'type' => 'user', 'name' => 'u', 'settings' => array( 'return_format' => 'object' ) );
		$id   = $registry->get( 'user' )->format( 1, $user );

		wpcmb_is( ! $id instanceof WP_User, 'a user is not returned whole without list_users' );
		wpcmb_is( 1 === $id, 'only the id the reader could have guessed' );

		wp_set_current_user( $was );

		wpcmb_is( $registry->get( 'user' )->format( 1, $user ) instanceof WP_User, 'and an administrator still gets the object' );
	}
);

/* -------------------------------------------------------------------------
 * Clean up everything this run created.
 * ---------------------------------------------------------------------- */

foreach ( $GLOBALS['wpcmb_created'] as $wpcmb_item ) {
	wp_delete_post( $wpcmb_item['id'], true );
}

printf(
	"\n%d assertions passed, %d failed. %d fixtures removed.\n",
	$GLOBALS['wpcmb_passed'],
	$GLOBALS['wpcmb_failed'],
	count( $GLOBALS['wpcmb_created'] )
);

exit( $GLOBALS['wpcmb_failed'] > 0 ? 1 : 0 );
