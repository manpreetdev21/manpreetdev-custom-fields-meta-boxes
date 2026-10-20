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
			array( 'Options Pages', 'manage_options', 'edit.php?post_type=wpcmb_options_page' ),
			array( 'Taxonomies', 'edit_posts', 'edit.php?post_type=wpcmb_taxonomy' ),
			array( 'Forms', 'edit_posts', 'edit.php?post_type=wpcmb_form' ),
			array( 'Block Types', 'edit_posts', 'edit.php?post_type=wpcmb_block' ),
		);

		$menu->order_submenu();

		$slugs = array_map( static fn( array $item ): string => $item[2], $GLOBALS['submenu']['wpcmb'] );

		wpcmb_is(
			array(
				'wpcmb',
				'edit.php?post_type=wpcmb_field_group',
				'edit.php?post_type=wpcmb_post_type',
				'edit.php?post_type=wpcmb_taxonomy',
				'edit.php?post_type=wpcmb_block',
				'edit.php?post_type=wpcmb_form',
				'edit.php?post_type=wpcmb_options_page',
				'wpcmb-settings',
				'wpcmb-tools',
				'edit.php?post_type=wpcmb_a_form_0001',
				'edit.php?post_type=wpcmb_b_form_0002',
				'wpcmb-options-site',
			) === $slugs,
			'the six builders, then Settings and Tools, then what a site accumulates'
		);

		/*
		 * A form's submissions type and a generated options page both match
		 * the same loose patterns as the record screens, and both must still
		 * land below Settings and Tools rather than among the builders.
		 */
		wpcmb_is(
			array_search( 'wpcmb-tools', $slugs, true ) < array_search( 'edit.php?post_type=wpcmb_a_form_0001', $slugs, true ),
			'a submissions screen sorts below Tools'
		);

		wpcmb_is(
			array_search( 'wpcmb-tools', $slugs, true ) < array_search( 'wpcmb-options-site', $slugs, true ),
			'and so does a generated options page'
		);

		// Two forms keep the order they registered in rather than swapping
		// places between page loads.
		wpcmb_is(
			array_search( 'edit.php?post_type=wpcmb_a_form_0001', $slugs, true )
				< array_search( 'edit.php?post_type=wpcmb_b_form_0002', $slugs, true ),
			'and entries of the same kind keep their relative order'
		);

		// Nothing is lost or invented on the way through.
		wpcmb_is( 12 === count( $GLOBALS['submenu']['wpcmb'] ), 'every entry survives the sort' );

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

wpcmb_group(
	'a post type and a taxonomy join up from either side',
	static function (): void {
		$types = wpcmb()->container()->get( WPCMB\PostTypes\CustomPostTypes::class );
		$taxes = wpcmb()->container()->get( WPCMB\PostTypes\CustomTaxonomies::class );

		$suffix    = strtolower( wp_generate_password( 4, false, false ) );
		$post_type = 'ipt_' . $suffix;
		$taxonomy  = 'itax_' . $suffix;

		/**
		 * Store one record of either kind.
		 *
		 * @param WPCMB\Abstracts\RecordType $type   Record type.
		 * @param string                     $title  Record title.
		 * @param array<string, mixed>       $config Configuration.
		 */
		$record = static function ( $type, string $title, array $config ): int {
			$post_id = (int) wp_insert_post(
				array(
					'post_type'   => $type->post_type(),
					'post_status' => 'publish',
					'post_title'  => $title,
				)
			);

			$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => $post_id );

			$type->save( $post_id, $type->sanitize( $config ) );

			return $post_id;
		};

		/*
		 * The join written only on the post type — the case that silently did
		 * nothing, because both halves register at the same moment and the
		 * taxonomy did not exist yet when the post type asked for it.
		 */
		$record( $types, 'Integration type', array( 'name' => $post_type, 'taxonomies' => array( $taxonomy ) ) );
		$record( $taxes, 'Integration taxonomy', array( 'name' => $taxonomy, 'post_types' => array() ) );

		// A fresh pass in the order `init` runs them: post types, taxonomies,
		// then the late attachment.
		$types->apply_records();
		$taxes->apply_records();
		$types->attach_late();

		wpcmb_is( post_type_exists( $post_type ), 'the post type is registered' );
		wpcmb_is( taxonomy_exists( $taxonomy ), 'the taxonomy is registered' );
		wpcmb_is( in_array( $taxonomy, get_object_taxonomies( $post_type ), true ), 'and the join declared on the post type holds' );

		// The other direction: a taxonomy naming its own post types.
		$other = 'itax2_' . $suffix;

		$record( $taxes, 'Integration taxonomy two', array( 'name' => $other, 'post_types' => array( $post_type ) ) );

		$taxes->apply_records();

		wpcmb_is( in_array( $other, get_object_taxonomies( $post_type ), true ), 'and so does one declared on the taxonomy' );

		/*
		 * A taxonomy that is not registered must not be conjured into
		 * existence by a post type naming it — that would shadow the real
		 * record with an empty one under the same key.
		 */
		$record( $types, 'Integration ghost', array( 'name' => 'ipt_ghost_' . $suffix, 'taxonomies' => array( 'itax_nothing_' . $suffix ) ) );

		$types->apply_records();
		$types->attach_late();

		wpcmb_is( ! taxonomy_exists( 'itax_nothing_' . $suffix ), 'a named but undefined taxonomy is not invented' );

		// The editor needs what records define, registered or not.
		$defined = $taxes->defined();

		wpcmb_is( isset( $defined[ $taxonomy ] ), 'the post type editor is offered record-defined taxonomies' );
	}
);

wpcmb_group(
	'a block record registers a block, and the old group settings still do',
	static function (): void {
		$records  = wpcmb()->container()->get( WPCMB\PostTypes\CustomBlocks::class );
		$registry = wpcmb()->container()->get( WPCMB\Blocks\BlockRegistry::class );
		$suffix   = strtolower( wp_generate_password( 4, false, false ) );

		$group = wpcmb_group_fixture(
			array(
				'title'    => 'Integration block group',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
				'fields'   => array( array( 'name' => 'iblk_text', 'label' => 'Text', 'type' => 'text' ) ),
			)
		);

		$record_id = (int) wp_insert_post(
			array(
				'post_type'   => WPCMB\PostTypes\CustomBlocks::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Integration block',
			)
		);

		$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => $record_id );

		$records->save(
			$record_id,
			$records->sanitize(
				array(
					'field_group' => $group->key,
					'name'        => 'iblk_' . $suffix,
					'description' => 'From a record.',
					'supports'    => array( 'align' ),
				)
			)
		);

		$blocks = $registry->blocks();
		$name   = 'wpcmb/iblk-' . $suffix;

		wpcmb_is( isset( $blocks[ $name ] ), 'the record produces a block' );

		/*
		 * The record hands the registry a field group with its settings
		 * written over the top, so everything downstream — attributes,
		 * templates, the editor form — reads what it always read.
		 */
		$as_group = $blocks[ $name ] ?? null;

		wpcmb_is( null !== $as_group && 'Integration block' === $as_group->title, 'with the record title, not the group title' );
		wpcmb_is( null !== $as_group && 'From a record.' === ( $as_group->settings['block_description'] ?? '' ), 'and the record description' );
		wpcmb_is( null !== $as_group && ! empty( $as_group->settings['block_enabled'] ), 'and marked as a block however the group was saved' );

		/*
		 * A group with the old `block_*` settings and no record is still
		 * registered. Those blocks are in published content; an upgrade that
		 * dropped them would empty somebody's pages.
		 */
		$legacy = wpcmb_group_fixture(
			array(
				'title'    => 'Integration legacy block',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
				'fields'   => array( array( 'name' => 'ileg_text', 'label' => 'Text', 'type' => 'text' ) ),
				'settings' => array( 'block_enabled' => '1', 'block_name' => 'ileg_' . $suffix ),
			)
		);

		$blocks = $registry->blocks();

		wpcmb_is( isset( $blocks[ 'wpcmb/ileg-' . $suffix ] ), 'a group with the old settings and no record still registers' );

		// And once a record claims that group, it is not registered twice
		// from two descriptions of the same thing.
		$claim_id = (int) wp_insert_post(
			array(
				'post_type'   => WPCMB\PostTypes\CustomBlocks::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Integration claim',
			)
		);

		$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => $claim_id );

		$records->save( $claim_id, $records->sanitize( array( 'field_group' => $legacy->key, 'name' => 'iclaim_' . $suffix ) ) );

		$blocks = $registry->blocks();

		wpcmb_is( ! isset( $blocks[ 'wpcmb/ileg-' . $suffix ] ), 'and the old settings stand down once a record claims the group' );
		wpcmb_is( isset( $blocks[ 'wpcmb/iclaim-' . $suffix ] ), 'in favour of what the record says' );
	}
);

wpcmb_group(
	'a form record renders the same form its shortcode did',
	static function (): void {
		$records = wpcmb()->container()->get( WPCMB\PostTypes\CustomForms::class );

		$group = wpcmb_group_fixture(
			array(
				'title'    => 'Integration form group',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'wpcmb_nothing' ) ) ),
				'fields'   => array( array( 'name' => 'ifrm_name', 'label' => 'Name', 'type' => 'text', 'required' => true ) ),
			)
		);

		$record_id = (int) wp_insert_post(
			array(
				'post_type'   => WPCMB\PostTypes\CustomForms::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Integration form',
			)
		);

		$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => $record_id );

		$records->save( $record_id, $records->sanitize( array( 'field_group' => $group->key, 'guests' => '1', 'submit_label' => 'Send' ) ) );

		$html = do_shortcode( sprintf( '[wpcmb_form id="%d"]', $record_id ) );

		wpcmb_is( str_contains( $html, '<form' ), 'the record renders a form' );
		wpcmb_is( str_contains( $html, 'ifrm_name' ), 'with the group\'s fields' );
		wpcmb_is( str_contains( $html, 'Send' ), 'and the record\'s submit label' );

		// An attribute on the shortcode still wins, so one record can be
		// reused with one thing different.
		$override = do_shortcode( sprintf( '[wpcmb_form id="%d" submit_label="Other"]', $record_id ) );

		wpcmb_is( str_contains( $override, 'Other' ), 'a shortcode attribute overrides the record' );

		// A record that has been deleted says so rather than rendering
		// nothing at all, which looks like a broken page.
		$gone = do_shortcode( '[wpcmb_form id="99999999"]' );

		wpcmb_is( '' !== trim( wp_strip_all_tags( $gone ) ), 'a missing record explains itself' );
		wpcmb_is( ! str_contains( $gone, '<form' ), 'and renders no form' );
	}
);

wpcmb_group(
	'a group located on a taxonomy renders when adding a term, not only when editing one',
	static function (): void {
		$taxonomy = 'itax_add_' . strtolower( wp_generate_password( 4, false, false ) );

		register_taxonomy( $taxonomy, 'post', array( 'show_ui' => true, 'public' => true ) );

		$GLOBALS['wpcmb_created_taxonomies'][] = $taxonomy;

		wpcmb_group_fixture(
			array(
				'title'    => 'Integration term group',
				'location' => array( array( array( 'param' => 'taxonomy', 'operator' => '==', 'value' => $taxonomy ) ) ),
				'fields'   => array( array( 'name' => 'iterm_text', 'label' => 'Note', 'type' => 'text' ) ),
			)
		);

		$resolver = wpcmb()->container()->get( WPCMB\Fields\Resolver::class );

		/*
		 * The edit form has a term, so the taxonomy comes from it. This always
		 * worked.
		 */
		$term = wp_insert_term( 'Integration term', $taxonomy );

		wpcmb_is( ! is_wp_error( $term ), 'the fixture term was created' );

		if ( is_wp_error( $term ) ) {
			return;
		}

		$editing = $resolver->groups(
			new WPCMB\Fields\Context( new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::TERM, (int) $term['term_id'] ) )
		);

		wpcmb_is( array() !== $editing, 'the group resolves when editing a term' );

		/*
		 * The Add New Term form has no term, so the taxonomy cannot be derived
		 * from one. Without the screen saying which taxonomy it is, the rule
		 * matched nothing and the fields appeared only on the edit screen —
		 * which is exactly what was reported.
		 */
		$bare = $resolver->groups(
			new WPCMB\Fields\Context( new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::TERM, 0 ) )
		);

		wpcmb_is( array() === $bare, 'term 0 alone resolves nothing, since no taxonomy can be derived' );

		$adding = $resolver->groups(
			new WPCMB\Fields\Context(
				new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::TERM, 0 ),
				array( 'taxonomy' => $taxonomy )
			)
		);

		wpcmb_is( array() !== $adding, 'and resolves when the screen supplies the taxonomy' );
		wpcmb_is( count( $adding ) === count( $editing ), 'to the same groups the edit form shows' );

		// The add form renders through its own hook, with its own markup.
		$boxes = new WPCMB\Admin\MetaBoxes( wpcmb()->container() );

		ob_start();
		$boxes->render_new_term( $taxonomy );
		$html = (string) ob_get_clean();

		wpcmb_is( str_contains( $html, 'iterm_text' ), 'the add form renders the fields' );
		wpcmb_is( str_contains( $html, 'wpcmb_values_nonce' ), 'with the nonce the save path checks' );
		wpcmb_is( str_contains( $html, 'form-field' ), 'in the markup that form uses' );

		// A taxonomy no group points at renders nothing at all.
		ob_start();
		$boxes->render_new_term( 'post_tag' );
		$other = (string) ob_get_clean();

		wpcmb_is( ! str_contains( $other, 'iterm_text' ), 'and nothing on a taxonomy it was not located on' );

		/*
		 * And the renderer is actually hooked up.
		 *
		 * Calling it directly proves it works and not that anything calls it —
		 * the bug being fixed here was a missing hook, not a broken renderer,
		 * so the hook is what has to be asserted.
		 */
		$boxes->add_taxonomy_hooks();

		wpcmb_is(
			false !== has_action( $taxonomy . '_add_form_fields', array( $boxes, 'render_new_term' ) ),
			'the add form hook is registered for every taxonomy with a UI'
		);

		wpcmb_is(
			false !== has_action( $taxonomy . '_edit_form_fields', array( $boxes, 'render_term' ) ),
			'and so is the edit form hook'
		);

		remove_action( $taxonomy . '_add_form_fields', array( $boxes, 'render_new_term' ) );
		remove_action( $taxonomy . '_edit_form_fields', array( $boxes, 'render_term' ) );

		wp_delete_term( (int) $term['term_id'], $taxonomy );
	}
);

wpcmb_group(
	'a group located on a menu renders on the menu and saves as term meta',
	static function (): void {
		$menu_name = 'Integration menu ' . wp_generate_password( 6, false, false );
		$menu_id   = wp_create_nav_menu( $menu_name );

		wpcmb_is( ! is_wp_error( $menu_id ), 'the fixture menu was created' );

		if ( is_wp_error( $menu_id ) ) {
			return;
		}

		$menu_id = (int) $menu_id;

		wpcmb_group_fixture(
			array(
				'title'    => 'Integration menu group',
				'location' => array( array( array( 'param' => 'nav_menu', 'operator' => '==', 'value' => 'all' ) ) ),
				'fields'   => array( array( 'name' => 'imenu_note', 'label' => 'Note', 'type' => 'text' ) ),
			)
		);

		$resolver = wpcmb()->container()->get( WPCMB\Fields\Resolver::class );
		$ref      = new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::TERM, $menu_id );

		$matched = $resolver->groups( new WPCMB\Fields\Context( $ref ) );

		wpcmb_is( array() !== $matched, 'a menu resolves the group located on all menus' );

		// A menu located on one specific menu must not appear on another.
		wpcmb_group_fixture(
			array(
				'title'    => 'Integration one menu only',
				'location' => array( array( array( 'param' => 'nav_menu', 'operator' => '==', 'value' => (string) $menu_id ) ) ),
				'fields'   => array( array( 'name' => 'imenu_only', 'label' => 'Only', 'type' => 'text' ) ),
			)
		);

		$fields = $resolver->fields( new WPCMB\Fields\Context( $ref ) );

		wpcmb_is( isset( $fields['imenu_only'] ), 'and the group located on that one menu' );

		$other = wp_create_nav_menu( 'Integration other ' . wp_generate_password( 6, false, false ) );

		if ( ! is_wp_error( $other ) ) {
			$others = $resolver->fields(
				new WPCMB\Fields\Context( new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::TERM, (int) $other ) )
			);

			wpcmb_is( ! isset( $others['imenu_only'] ), 'but not on a different menu' );
			wpcmb_is( isset( $others['imenu_note'] ), 'while the all-menus group appears on both' );

			wp_delete_nav_menu( (int) $other );
		}

		/*
		 * The screen has no hook inside the menu form, so the markup is
		 * printed at the end of the page and moved in by script. What is
		 * asserted here is that the markup, the nonce and the relocation
		 * script are all printed for the menu core says is selected.
		 */
		global $nav_menu_selected_id;

		$was                  = $nav_menu_selected_id;
		$nav_menu_selected_id = $menu_id;

		$settings = new WPCMB\Admin\NavMenuSettings( wpcmb()->container() );

		ob_start();
		$settings->render();
		$html = (string) ob_get_clean();

		wpcmb_is( str_contains( $html, 'imenu_note' ), 'the menu fields are printed' );
		wpcmb_is( str_contains( $html, 'wpcmb_menu_settings_nonce' ), 'with their own nonce' );
		wpcmb_is( str_contains( $html, 'update-nav-menu' ), 'and the script that moves them into the menu form' );

		// Menu items post wpcmb_values[12][x] on the same form, so a menu's
		// own fields use a key of their own or an id collision reads the
		// wrong values.
		wpcmb_is( str_contains( $html, 'wpcmb_menu_values[imenu_note]' ), 'under a request key of their own' );

		/* Saving, as the Save Menu button does it. */
		$_POST['wpcmb_menu_settings_nonce'] = wp_create_nonce( 'wpcmb_save_nav_menu_settings' );
		$_POST['wpcmb_menu_values']         = array( 'imenu_note' => '  Spaced  ' );

		$settings->save( $menu_id );

		wpcmb_is( 'Spaced' === get_term_meta( $menu_id, 'imenu_note', true ), 'a submitted value is sanitized and stored as term meta' );

		/*
		 * A field that was not on the form keeps what it had.
		 *
		 * A menu can be saved from a screen showing a narrower set of fields
		 * than the one that filled them in — a group deactivated, a location
		 * rule changed — and writing an empty value for everything missing
		 * would wipe values nobody was shown.
		 */
		$_POST['wpcmb_menu_values'] = array();

		$settings->save( $menu_id );

		wpcmb_is( 'Spaced' === get_term_meta( $menu_id, 'imenu_note', true ), 'a field absent from the submission is left alone' );

		// Without the nonce nothing is written, which is the whole reason the
		// markup carries one of its own.
		unset( $_POST['wpcmb_menu_settings_nonce'] );
		$_POST['wpcmb_menu_values'] = array( 'imenu_note' => 'Unsigned' );

		$settings->save( $menu_id );

		wpcmb_is( 'Spaced' === get_term_meta( $menu_id, 'imenu_note', true ), 'and an unsigned submission writes nothing' );

		unset( $_POST['wpcmb_menu_values'] );

		$nav_menu_selected_id = $was;

		wp_delete_nav_menu( $menu_id );
	}
);

wpcmb_group(
	'an export imports onto another site, and importing it twice changes nothing',
	function (): void {
		$portability = wpcmb()->container()->get( WPCMB\Database\Portability::class );
		$types       = wpcmb()->container()->get( WPCMB\PostTypes\CustomPostTypes::class );

		/*
		 * Built by hand rather than exported from this site: an import's job
		 * is to read somebody else's file, and a payload this site produced
		 * would hide a mismatch between what export writes and what import
		 * reads.
		 */
		$payload = array(
			'format'       => WPCMB\Database\Portability::FORMAT,
			'version'      => '1.0.0',
			'field_groups' => array(
				array(
					'key'    => 'group_imported_check',
					'title'  => 'Imported group',
					'fields' => array(
						array( 'name' => 'ifield', 'label' => 'Imported field', 'type' => 'text' ),
					),
				),
			),
			'post_types'   => array(
				array( 'name' => 'iportable', 'title' => 'Portable Things' ),
			),
		);

		$before = array_keys( $types->records( true ) );

		$first = $portability->import( $payload );

		wpcmb_is( 1 === ( $first['field_groups'] ?? 0 ), 'the field group in the file was written' );
		wpcmb_is( 1 === ( $first['post_types'] ?? 0 ), 'and so was the post type record' );

		/* Remember what the import made, so the run cleans up after itself. */
		$made = array_diff( array_keys( $types->records( true ) ), $before );

		foreach ( $made as $id ) {
			$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => (int) $id );
		}

		$repository = wpcmb()->container()->get( WPCMB\Fields\Repository::class );
		$repository->flush();

		$group = $repository->get( 'group_imported_check' );

		wpcmb_is( $group instanceof WPCMB\Fields\FieldGroup, 'the group can be found by the key the file gave it' );

		if ( $group instanceof WPCMB\Fields\FieldGroup ) {
			$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => $group->id );

			// Inactive on arrival: a file somebody uploaded must not start
			// putting fields on post screens before anyone has looked at it.
			wpcmb_is( 'draft' === get_post_status( $group->id ), 'and arrives inactive' );
		}

		$record = $types->find( 'iportable' );

		wpcmb_is( null !== $record, 'the record can be found by its own identity' );
		wpcmb_is( null !== $record && 'draft' === get_post_status( $record['id'] ), 'and it is inactive too' );

		/*
		 * The same file again. This is what somebody actually does — export,
		 * import, notice a typo, export, import — and a second copy of every
		 * post type is the failure that makes an import tool useless.
		 */
		$second = $portability->import( $payload );

		wpcmb_is( 1 === ( $second['post_types'] ?? 0 ), 'importing the file again writes one record, not two' );

		$ids = array();

		foreach ( $types->records( true ) as $id => $config ) {
			if ( 'iportable' === ( $config['name'] ?? '' ) ) {
				$ids[] = $id;
			}
		}

		wpcmb_is( 1 === count( $ids ), 'and there is still exactly one record with that key' );
		wpcmb_is( null !== $record && array( $record['id'] ) === $ids, 'the same one, updated in place' );

		/* A changed title follows the file rather than being left behind. */
		$payload['post_types'][0]['title'] = 'Renamed Things';

		$portability->import( $payload );

		wpcmb_is( null !== $record && 'Renamed Things' === get_post_field( 'post_title', $record['id'] ), 'a renamed record is renamed here' );

		/*
		 * `wp wpcmb import --activate` is for a deploy script that already
		 * knows what it is importing, so it publishes what it creates.
		 */
		$payload['post_types'][0]['name'] = 'iportable2';

		$portability->import( $payload, 'publish' );

		$activated = $types->find( 'iportable2' );

		wpcmb_is( null !== $activated, 'an activating import creates the record' );

		if ( null !== $activated ) {
			$GLOBALS['wpcmb_created'][] = array( 'type' => 'post', 'id' => $activated['id'] );

			wpcmb_is( 'publish' === get_post_status( $activated['id'] ), 'and publishes it' );
		}

		/* And a file that is not ours is refused before anything is written. */
		wpcmb_is( null === WPCMB\Database\Portability::normalise( array( 'format' => 'other-plugin' ) ), "another plugin's export is refused" );
	}
);
wpcmb_group(
	'a value posted for a conditionally hidden field is not stored',
	function (): void {
		/*
		 * Found by posting to a real form with the hidden input re-enabled.
		 * The browser disables a hidden field's inputs, so an honest
		 * submission never carries them — but that is advice to a browser,
		 * not a rule about what may arrive, and the value was being stored in
		 * a field the form had never offered.
		 */
		$group = wpcmb_group_fixture(
			array(
				'key'      => 'group_ihidden',
				'title'    => 'Integration hidden',
				'fields'   => array(
					array( 'key' => 'field_ipick000001', 'name' => 'ipick', 'label' => 'Pick', 'type' => 'select', 'settings' => array( 'choices' => "a : A\nb : B" ) ),
					array(
						'key'         => 'field_ionlyb00001',
						'name'        => 'ionly_b',
						'label'       => 'Only for B',
						'type'        => 'text',
						'conditional' => array(
							'action' => 'show',
							'groups' => array( array( array( 'field' => 'field_ipick000001', 'operator' => '==', 'value' => 'b' ) ) ),
						),
					),
				),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			)
		);

		$page = wpcmb_post_fixture( 'page', 'Integration hidden field' );

		/**
		 * Save through the admin path, as an edit screen posts it.
		 *
		 * @param array<string, mixed> $values Field values.
		 */
		$save = static function ( array $values ) use ( $page ): void {
			$_POST = array(
				'wpcmb_values_nonce'   => wp_create_nonce( 'wpcmb_save_values' ),
				'wpcmb_values'         => $values,
				'post_ID'              => $page,
				'post_status'          => 'publish',
				'original_post_status' => 'publish',
			);

			$_REQUEST = $_POST;

			wp_update_post( array( 'ID' => $page, 'post_title' => 'Integration hidden field' ) );

			$_POST    = array();
			$_REQUEST = array();
		};

		/* The condition is not met, so the second field was never on the form. */
		$save( array( 'ipick' => 'a', 'ionly_b' => 'smuggled' ) );

		wpcmb_is( 'a' === wpcmb_get_field( 'ipick', $page, false ), 'the visible field is stored' );
		wpcmb_is( '' === (string) wpcmb_get_field( 'ionly_b', $page, false ), 'and the hidden one is not, got: ' . var_export( wpcmb_get_field( 'ionly_b', $page, false ), true ) );

		/* Met in the same request: the field was on the form, so it is stored. */
		$save( array( 'ipick' => 'b', 'ionly_b' => 'a real answer' ) );

		wpcmb_is( 'a real answer' === wpcmb_get_field( 'ionly_b', $page, false ), 'a visible field is stored as normal' );

		/*
		 * And it can be cleared while still visible. A guard that dropped
		 * empty values would make a conditional field impossible to empty.
		 */
		$save( array( 'ipick' => 'b', 'ionly_b' => '' ) );

		wpcmb_is( '' === (string) wpcmb_get_field( 'ionly_b', $page, false ), 'a visible field can be emptied' );

		/*
		 * Switching the answer away hides the field again. Whatever it held
		 * stays put rather than being wiped: the value is not the submission's
		 * to change once the field is no longer on the form.
		 */
		$save( array( 'ipick' => 'b', 'ionly_b' => 'kept' ) );
		$save( array( 'ipick' => 'a', 'ionly_b' => 'overwritten' ) );

		wpcmb_is( 'kept' === wpcmb_get_field( 'ionly_b', $page, false ), 'a field hidden again keeps what it had' );
	}
);
wpcmb_group(
	'a term on a hidden taxonomy does not hand out its values',
	function (): void {
		/*
		 * Reported by a plugin reviewer: every object type weighed up whether
		 * its values were public except terms, which answered yes to anybody
		 * for any term. A term id was all it took over REST, including for a
		 * taxonomy registered to hold things the site never shows.
		 */
		register_taxonomy(
			'wpcmb_itest_open',
			'post',
			array( 'public' => true, 'label' => 'Open', 'capabilities' => array( 'assign_terms' => 'edit_posts' ) )
		);

		register_taxonomy(
			'wpcmb_itest_hidden',
			'post',
			array( 'public' => false, 'label' => 'Hidden', 'capabilities' => array( 'assign_terms' => 'manage_options' ) )
		);

		$open   = wp_insert_term( 'Open term ' . wp_generate_password( 5, false, false ), 'wpcmb_itest_open' );
		$hidden = wp_insert_term( 'Hidden term ' . wp_generate_password( 5, false, false ), 'wpcmb_itest_hidden' );

		if ( is_wp_error( $open ) || is_wp_error( $hidden ) ) {
			wpcmb_is( false, 'could not create the terms to test with' );

			return;
		}

		$open_ref   = new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::TERM, (int) $open['term_id'] );
		$hidden_ref = new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::TERM, (int) $hidden['term_id'] );

		$was = get_current_user_id();

		/* An administrator sees both, as before. */
		wpcmb_is( WPCMB\Fields\Permissions::can_read( $open_ref ), 'an administrator reads a public term' );
		wpcmb_is( WPCMB\Fields\Permissions::can_read( $hidden_ref ), 'and one on a hidden taxonomy' );

		/* A logged-out visitor is the case that was wrong. */
		wp_set_current_user( 0 );

		wpcmb_is(
			WPCMB\Fields\Permissions::can_read( $open_ref ),
			'a visitor still reads a term on a public taxonomy, which is public content'
		);

		wpcmb_is(
			! WPCMB\Fields\Permissions::can_read( $hidden_ref ),
			'but not one on a taxonomy that is not public'
		);

		/* And an id nobody has is refused rather than assumed. */
		wpcmb_is(
			! WPCMB\Fields\Permissions::can_read( new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::TERM, 99999999 ) ),
			'a term that does not exist is not readable'
		);

		wp_set_current_user( $was );

		/*
		 * Writing was already gated on the taxonomy's own capability, so the
		 * fix must not have loosened it.
		 */
		wp_set_current_user( 0 );

		wpcmb_is( ! WPCMB\Fields\Permissions::can_edit( $open_ref ), 'a visitor still cannot write to a public term' );

		wp_set_current_user( $was );

		wp_delete_term( (int) $open['term_id'], 'wpcmb_itest_open' );
		wp_delete_term( (int) $hidden['term_id'], 'wpcmb_itest_hidden' );

		unregister_taxonomy( 'wpcmb_itest_open' );
		unregister_taxonomy( 'wpcmb_itest_hidden' );
	}
);
wpcmb_group(
	'an options page hands its hook sanitized values, and skips hidden fields',
	function (): void {
		/*
		 * Two things a plugin reviewer was right about. The saved hook was
		 * handed the request as it arrived, so an extension reading it was
		 * reading unsanitized input while reasonably assuming otherwise. And
		 * this third save path never got the conditional-logic guard the edit
		 * screen and the front-end form have, so a value posted for a field
		 * the page was hiding was stored.
		 */
		$group = wpcmb_group_fixture(
			array(
				'key'      => 'group_iopt_hook',
				'title'    => 'Integration options hook',
				'fields'   => array(
					array( 'key' => 'field_iomode00001', 'name' => 'io_mode', 'label' => 'Mode', 'type' => 'select', 'settings' => array( 'choices' => "plain : Plain\nfancy : Fancy" ) ),
					array( 'key' => 'field_ionote00001', 'name' => 'io_note', 'label' => 'Note', 'type' => 'text' ),
					array(
						'key'         => 'field_iofancy0001',
						'name'        => 'io_fancy',
						'label'       => 'Fancy only',
						'type'        => 'text',
						'conditional' => array(
							'action' => 'show',
							'groups' => array( array( array( 'field' => 'field_iomode00001', 'operator' => '==', 'value' => 'fancy' ) ) ),
						),
					),
				),
				'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'iopt-hook' ) ) ),
				'settings' => array( 'options_page' => 'iopt-hook' ),
			)
		);

		$module = new WPCMB\Admin\OptionsPages( wpcmb()->container() );
		$ref    = new WPCMB\Fields\ObjectRef( WPCMB\Fields\ObjectRef::OPTION, 'iopt-hook' );

		$seen = null;

		$listener = static function ( $slug, $values ) use ( &$seen ): void {
			$seen = $values;
		};

		add_action( 'wpcmb/options_page/saved', $listener, 10, 2 );

		/* The redirect at the end of the save would exit the test run. */
		$stop = static function (): void {
			throw new \RuntimeException( 'redirected' );
		};

		add_filter( 'wp_redirect', $stop );

		$_POST = array(
			'wpcmb_options_nonce' => wp_create_nonce( 'wpcmb_save_options' ),
			'_wp_http_referer'    => '/wp-admin/admin.php?page=wpcmb-options-iopt-hook',
			'wpcmb_values'        => array(
				'io_mode'  => 'plain',
				// Tags in a text field, to show the hook is handed the clean value.
				'io_note'  => '<script>alert(1)</script>Note',
				// Hidden: io_mode is "plain", so the page never showed this.
				'io_fancy' => 'SMUGGLED',
			),
		);

		$_REQUEST = $_POST;

		$save = new ReflectionMethod( $module, 'handle_save' );

		$save->setAccessible( true );

		try {
			$save->invoke( $module, 'iopt-hook' );
		} catch ( \RuntimeException $e ) {
			// The redirect, which means the save ran to the end.
			$seen = $seen;
		}

		remove_filter( 'wp_redirect', $stop );
		remove_action( 'wpcmb/options_page/saved', $listener, 10 );

		$_POST    = array();
		$_REQUEST = array();

		wpcmb_is( is_array( $seen ), 'the saved hook fired' );

		if ( is_array( $seen ) ) {
			wpcmb_is(
				isset( $seen['io_note'] ) && ! str_contains( (string) $seen['io_note'], '<script' ),
				'and was handed the sanitized value, not the request: ' . var_export( $seen['io_note'] ?? null, true )
			);

			wpcmb_is( ! array_key_exists( 'io_fancy', $seen ), 'with the hidden field left out of it' );
		}

		$values = wpcmb()->container()->get( WPCMB\Fields\Values::class );

		wpcmb_is( 'plain' === $values->get( 'io_mode', $ref, false ), 'the visible select is stored' );
		wpcmb_is( ! str_contains( (string) $values->get( 'io_note', $ref, false ), '<script' ), 'the stored note is clean' );
		wpcmb_is( '' === (string) $values->get( 'io_fancy', $ref, false ), 'and the hidden field was not written' );

		/*
		 * Every option this page holds, not just this group's fields: another
		 * fixture is located on "not some other options page", which matches
		 * this one too, so the save wrote its field here as well.
		 */
		global $wpdb;

		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpcmb_iopt-hook_' ) . '%' ) ) as $option ) {
			delete_option( $option );
		}
		delete_transient( 'wpcmb_options_result_iopt-hook' );
	}
);
wpcmb_group(
	'values posted to the block form renderer are escaped, not executed',
	function (): void {
		/*
		 * A plugin reviewer noted that json_decode() does not sanitize, and
		 * the block preview endpoint decodes the values it is handed. It does
		 * not store them — it renders a form with them — so what matters is
		 * that every field type escapes on the way out. Asserted against the
		 * real renderer rather than inferred from reading it.
		 */
		$group = wpcmb_group_fixture(
			array(
				'key'      => 'group_iesc_probe',
				'title'    => 'Integration escaping',
				'fields'   => array(
					array( 'key' => 'field_ietext00001', 'name' => 'ie_text', 'label' => 'Text', 'type' => 'text' ),
					array( 'key' => 'field_iearea00001', 'name' => 'ie_area', 'label' => 'Area', 'type' => 'textarea' ),
					array( 'key' => 'field_iesel000001', 'name' => 'ie_sel', 'label' => 'Sel', 'type' => 'select', 'settings' => array( 'choices' => "a : A" ) ),
					array( 'key' => 'field_ieurl000001', 'name' => 'ie_url', 'label' => 'Url', 'type' => 'url' ),
				),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
			)
		);

		$payloads = array(
			'ie_text' => '"><script>alert(1)</script>',
			'ie_area' => '</textarea><script>alert(2)</script>',
			'ie_sel'  => '"><img src=x onerror=alert(3)>',
			'ie_url'  => 'javascript:alert(4)',
		);

		$html = wpcmb()->container()->get( WPCMB\Blocks\BlockRegistry::class )->render_form( $group, $payloads );

		wpcmb_is( '' !== $html, 'the form rendered' );

		foreach ( array( '<script>alert(1)', '<script>alert(2)', 'onerror=alert(3)' ) as $needle ) {
			wpcmb_is( ! str_contains( $html, $needle ), 'no executable markup survived: ' . $needle );
		}

		// A closing tag inside a textarea would end it early and let the rest
		// of the payload out as markup.
		wpcmb_is( ! str_contains( $html, '</textarea><script' ), 'a textarea cannot be closed from its own value' );

		// javascript: in an href is the other way a value becomes executable.
		wpcmb_is( ! str_contains( $html, 'href="javascript:' ), 'a javascript URL is not rendered as a link' );
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
