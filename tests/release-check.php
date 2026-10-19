<?php
/**
 * Enforce what the WordPress.org Plugin Directory asks of a submission.
 *
 * The review that matters happens in front of a person, and most of what they
 * reject for is mechanical: a header field left out, a readme whose stable tag
 * has drifted from the plugin version, a text domain that stopped matching the
 * slug, a missing licence, a stray `eval`. None of that is interesting to
 * discover by email three weeks later, so it is checked here.
 *
 * This says nothing about whether the plugin is any good — only that nothing
 * obvious stands between it and a review.
 *
 * Run with `php tests/release-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions -- A developer-run CLI script.

$wpcmb_root   = dirname( __DIR__ );
$wpcmb_slug   = basename( $wpcmb_root );
$wpcmb_failed = 0;
$wpcmb_passed = 0;

/**
 * Report a rule's outcome.
 *
 * @param string            $rule       What was checked.
 * @param array<int,string> $violations Anything that broke it.
 */
function wpcmb_rule( string $rule, array $violations ): void {
	if ( array() === $violations ) {
		++$GLOBALS['wpcmb_passed'];
		printf( "  ok    %s\n", $rule );

		return;
	}

	++$GLOBALS['wpcmb_failed'];

	printf( "  FAIL  %s\n", $rule );

	foreach ( array_slice( $violations, 0, 12 ) as $violation ) {
		printf( "          %s\n", $violation );
	}

	if ( count( $violations ) > 12 ) {
		printf( "          … and %d more\n", count( $violations ) - 12 );
	}
}

/**
 * Read a file below the plugin root.
 *
 * @param string $relative Relative path.
 */
function wpcmb_file( string $relative ): string {
	$path = $GLOBALS['wpcmb_root'] . '/' . $relative;

	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

/**
 * The slug the plugin is distributed under.
 *
 * Taken from the main file's own name rather than the folder, because a
 * checkout can sit in a folder called anything at all while the file that
 * WordPress loads is the one that has to carry the slug.
 */
function wpcmb_slug(): string {
	foreach ( (array) glob( $GLOBALS['wpcmb_root'] . '/*.php' ) as $path ) {
		if ( str_contains( (string) file_get_contents( (string) $path ), 'Plugin Name:' ) ) {
			return basename( (string) $path, '.php' );
		}
	}

	return '';
}

$wpcmb_slug = wpcmb_slug();
$wpcmb_main = wpcmb_file( $wpcmb_slug . '.php' );
$wpcmb_read = wpcmb_file( 'readme.txt' );

printf( "Packaging the plugin as \"%s\"\n\n", $wpcmb_slug );

/* What the package contains, which four rules below read. */
$wpcmb_shipped_php = array_merge(
	(array) glob( $wpcmb_root . '/*.php' ),
	(array) glob( $wpcmb_root . '/{includes,templates}/{,*/,*/*/}*.php', GLOB_BRACE )
);

$wpcmb_shipped = array_merge( $wpcmb_shipped_php, (array) glob( $wpcmb_root . '/assets/js/*.js' ) );

echo "The plugin header\n";

/*
 * Rule 1: every header field the directory reads is present.
 *
 * `Requires at least`, `Requires PHP` and `Tested up to` are what the
 * directory uses to decide whether to offer the plugin to a site at all, and
 * a plugin with none of them is offered to every site including the ones it
 * cannot run on.
 */
$wpcmb_headers = array(
	'Plugin Name',
	'Plugin URI',
	'Description',
	'Version',
	'Requires at least',
	'Requires PHP',
	'Author',
	'License',
	'License URI',
	'Text Domain',
);

$wpcmb_missing = array();

foreach ( $wpcmb_headers as $wpcmb_header ) {
	if ( 1 !== preg_match( '/^\s*\*\s*' . preg_quote( $wpcmb_header, '/' ) . ':\s*\S/m', $wpcmb_main ) ) {
		$wpcmb_missing[] = sprintf( 'the header has no %s', $wpcmb_header );
	}
}

wpcmb_rule( 'every header field the directory reads is filled in', $wpcmb_missing );

/*
 * Rule 2: the slug, the text domain and the main file agree.
 *
 * The directory serves language packs by slug, so a text domain that does not
 * match it means every translation silently fails to load.
 */
$wpcmb_domain = array();

if ( '' === $wpcmb_slug ) {
	$wpcmb_domain[] = 'no file in the plugin root declares a Plugin Name';
}

if ( 1 === preg_match( '/^\s*\*\s*Text Domain:\s*(\S+)/m', $wpcmb_main, $wpcmb_match ) ) {
	if ( $wpcmb_match[1] !== $wpcmb_slug ) {
		$wpcmb_domain[] = sprintf( 'the text domain is %s but the slug is %s', $wpcmb_match[1], $wpcmb_slug );
	}
}

// Every translated string has to name that same domain.
$wpcmb_strings = array();

foreach ( (array) glob( $wpcmb_root . '/{includes,templates}/{,*/,*/*/}*.php', GLOB_BRACE ) as $wpcmb_path ) {
	$wpcmb_source = (string) file_get_contents( (string) $wpcmb_path );

	if ( preg_match_all( "/\b(?:__|_e|_n|_x|_ex|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x)\(\s*[^)]*?'([a-z0-9-]+)'\s*\)/", $wpcmb_source, $wpcmb_found ) ) {
		foreach ( array_unique( $wpcmb_found[1] ) as $wpcmb_used ) {
			// The last quoted argument of a translation call is the domain,
			// and anything that is not the slug is a string nobody can
			// translate.
			if ( $wpcmb_used !== $wpcmb_slug && 'default' !== $wpcmb_used ) {
				$wpcmb_strings[] = sprintf( '%s uses the domain %s', basename( (string) $wpcmb_path ), $wpcmb_used );
			}
		}
	}
}

wpcmb_rule(
	'the slug, the text domain and every translated string agree',
	array_values( array_unique( array_merge( $wpcmb_domain, $wpcmb_strings ) ) )
);

/*
 * Rule 3: the name avoids the terms the directory restricts.
 *
 * "WordPress" cannot appear in a plugin name at all — not as a suffix,
 * not in the middle, however true it is. The others here are trademarks
 * the directory allows only as a trailing "for X", which is a judgement
 * call, so they are reported and left to a person. The directory's own
 * list is longer than this; these are the ones that get written by
 * accident.
 */
$wpcmb_restricted = array( 'wordpress', 'woocommerce', 'gutenberg', 'jetpack', 'elementor', 'facebook', 'instagram', 'google', 'youtube', 'paypal', 'stripe' );

$wpcmb_name  = array();
$wpcmb_title = '';

if ( 1 === preg_match( '/^\s*\*\s*Plugin Name:\s*(.+)$/m', $wpcmb_main, $wpcmb_declared_name ) ) {
	$wpcmb_title = trim( $wpcmb_declared_name[1] );
}

if ( '' === $wpcmb_title ) {
	$wpcmb_name[] = 'the header declares no plugin name';
}

foreach ( $wpcmb_restricted as $wpcmb_term ) {
	if ( str_contains( strtolower( $wpcmb_title ), $wpcmb_term ) ) {
		$wpcmb_name[] = sprintf( 'the plugin name contains the restricted term "%s"', $wpcmb_term );
	}
}

// The readme title is the name the directory shows, so it drifts too.
if ( 1 === preg_match( '/^=== (.+) ===/m', $wpcmb_read, $wpcmb_read_title ) ) {
	foreach ( $wpcmb_restricted as $wpcmb_term ) {
		if ( str_contains( strtolower( $wpcmb_read_title[1] ), $wpcmb_term ) ) {
			$wpcmb_name[] = sprintf( 'the readme.txt title contains the restricted term "%s"', $wpcmb_term );
		}
	}
}

wpcmb_rule( 'the plugin name avoids the terms the directory restricts', array_values( array_unique( $wpcmb_name ) ) );

/*
 * Rule 4: the translation headers and calls describe what actually ships.
 *
 * A directory-hosted plugin has its translations loaded for it, from the
 * language packs the directory builds. Calling `load_plugin_textdomain()`
 * does that work again by hand, and a `Domain Path` naming a folder that
 * is not in the package points installs at nothing.
 */
$wpcmb_i18n = array();

if ( 1 === preg_match( '/^\s*\*\s*Domain Path:\s*(\S+)/m', $wpcmb_main, $wpcmb_domain_path ) ) {
	$wpcmb_folder = $wpcmb_root . '/' . ltrim( $wpcmb_domain_path[1], '/' );

	if ( ! is_dir( $wpcmb_folder ) || array() === (array) glob( $wpcmb_folder . '/*' ) ) {
		$wpcmb_i18n[] = sprintf( 'Domain Path is %s, which ships nothing', $wpcmb_domain_path[1] );
	}
}

foreach ( $wpcmb_shipped_php as $wpcmb_path ) {
	if ( str_contains( (string) file_get_contents( (string) $wpcmb_path ), 'load_plugin_textdomain(' ) ) {
		$wpcmb_i18n[] = sprintf( '%s calls load_plugin_textdomain()', basename( (string) $wpcmb_path ) );
	}
}

wpcmb_rule( 'the translation headers and calls describe what ships', $wpcmb_i18n );

echo "\nreadme.txt\n";

/* Rule 3: the readme exists and carries the sections the directory parses. */
$wpcmb_readme = array();

if ( '' === $wpcmb_read ) {
	$wpcmb_readme[] = 'there is no readme.txt';
} else {
	foreach ( array( 'Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI' ) as $wpcmb_field ) {
		if ( 1 !== preg_match( '/^' . preg_quote( $wpcmb_field, '/' ) . ':\s*\S/m', $wpcmb_read ) ) {
			$wpcmb_readme[] = sprintf( 'readme.txt has no %s', $wpcmb_field );
		}
	}

	foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'Changelog' ) as $wpcmb_section ) {
		if ( ! str_contains( $wpcmb_read, '== ' . $wpcmb_section . ' ==' ) ) {
			$wpcmb_readme[] = sprintf( 'readme.txt has no %s section', $wpcmb_section );
		}
	}

	if ( 1 === preg_match( '/^=== (.+) ===/m', $wpcmb_read, $wpcmb_title ) ) {
		if ( ! str_contains( $wpcmb_main, 'Plugin Name:       ' . trim( $wpcmb_title[1] ) ) ) {
			$wpcmb_readme[] = sprintf( 'readme.txt is titled "%s", which is not the plugin name', trim( $wpcmb_title[1] ) );
		}
	}

	// The short description is what shows in search results, and the
	// directory truncates it at 150 characters.
	if ( 1 === preg_match( '/^License URI:.*\R+\R*(.+)/m', $wpcmb_read, $wpcmb_short ) ) {
		$wpcmb_length = strlen( trim( $wpcmb_short[1] ) );

		if ( $wpcmb_length > 150 ) {
			$wpcmb_readme[] = sprintf( 'the short description is %d characters; the limit is 150', $wpcmb_length );
		}
	}
}

wpcmb_rule( 'readme.txt carries what the directory parses', $wpcmb_readme );

/* Rule 4: one version, everywhere it is written down. */
$wpcmb_versions = array();

preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $wpcmb_main, $wpcmb_header_version );
preg_match( "/define\(\s*'WPCMB_VERSION',\s*'([^']+)'/", $wpcmb_main, $wpcmb_constant );
preg_match( '/^Stable tag:\s*(\S+)/m', $wpcmb_read, $wpcmb_stable );

$wpcmb_version = $wpcmb_header_version[1] ?? '';

if ( '' === $wpcmb_version ) {
	$wpcmb_versions[] = 'the header declares no version';
}

if ( ( $wpcmb_constant[1] ?? '' ) !== $wpcmb_version ) {
	$wpcmb_versions[] = sprintf( 'WPCMB_VERSION is %s, the header says %s', $wpcmb_constant[1] ?? 'unset', $wpcmb_version );
}

if ( ( $wpcmb_stable[1] ?? '' ) !== $wpcmb_version ) {
	$wpcmb_versions[] = sprintf( 'readme.txt Stable tag is %s, the header says %s', $wpcmb_stable[1] ?? 'unset', $wpcmb_version );
}

if ( '' !== $wpcmb_version && ! str_contains( $wpcmb_read, '= ' . $wpcmb_version . ' =' ) ) {
	$wpcmb_versions[] = sprintf( 'the changelog has no entry for %s', $wpcmb_version );
}

wpcmb_rule( 'the version is the same in the header, the constant and the readme', $wpcmb_versions );

echo "\nLicensing and content\n";

/*
 * Rule 5: the licence is stated and shipped.
 *
 * The directory takes GPL-compatible only, and asks for the full text to
 * travel with the plugin rather than a line claiming it.
 */
$wpcmb_licence = array();

if ( '' === wpcmb_file( 'LICENSE' ) && '' === wpcmb_file( 'LICENSE.txt' ) && '' === wpcmb_file( 'license.txt' ) ) {
	$wpcmb_licence[] = 'the full licence text is not shipped';
}

if ( ! str_contains( wpcmb_file( 'LICENSE' ), 'GNU GENERAL PUBLIC LICENSE' ) ) {
	$wpcmb_licence[] = 'LICENSE does not contain the GNU General Public License';
}

if ( 1 === preg_match( '/^\s*\*\s*License:\s*(.+)$/m', $wpcmb_main, $wpcmb_declared ) ) {
	if ( ! str_contains( strtolower( $wpcmb_declared[1] ), 'gpl' ) ) {
		$wpcmb_licence[] = sprintf( 'the header licence "%s" is not GPL', trim( $wpcmb_declared[1] ) );
	}
}

wpcmb_rule( 'the licence is GPL, declared and shipped in full', $wpcmb_licence );

/*
 * Rule 6: nothing the directory refuses outright.
 *
 * Obfuscation, remote code and shelling out are rejected on sight. The checks
 * read shipped code only: the test suite is not distributed, and a developer
 * script is allowed to be a developer script.
 */
$wpcmb_banned  = array(
	'eval('              => 'eval',
	'base64_decode('     => 'base64_decode',
	'gzinflate('         => 'gzinflate',
	'str_rot13('         => 'str_rot13',
	'create_function('   => 'create_function',
	'shell_exec('        => 'shell_exec',
	'proc_open('         => 'proc_open',
	'passthru('          => 'passthru',
	'$_REQUEST'          => '$_REQUEST',
);

$wpcmb_forbidden = array();


foreach ( $wpcmb_shipped as $wpcmb_path ) {
	$wpcmb_source = (string) file_get_contents( (string) $wpcmb_path );

	foreach ( $wpcmb_banned as $wpcmb_needle => $wpcmb_label ) {
		if ( str_contains( $wpcmb_source, $wpcmb_needle ) ) {
			$wpcmb_forbidden[] = sprintf( '%s uses %s', basename( (string) $wpcmb_path ), $wpcmb_label );
		}
	}
}

wpcmb_rule( 'no shipped file uses what the directory refuses', $wpcmb_forbidden );

/*
 * Rule 7: every shipped PHP file parses, and guards direct access.
 *
 * A file reachable by URL that runs without WordPress is the oldest hole in
 * the catalogue.
 */
$wpcmb_guards = array();

foreach ( $wpcmb_shipped as $wpcmb_path ) {
	if ( ! str_ends_with( (string) $wpcmb_path, '.php' ) ) {
		continue;
	}

	$wpcmb_output = array();
	$wpcmb_status = 0;

	exec( sprintf( 'php -l %s 2>&1', escapeshellarg( (string) $wpcmb_path ) ), $wpcmb_output, $wpcmb_status );

	if ( 0 !== $wpcmb_status ) {
		$wpcmb_guards[] = sprintf( '%s does not parse', basename( (string) $wpcmb_path ) );
		continue;
	}

	$wpcmb_source = (string) file_get_contents( (string) $wpcmb_path );

	if ( ! str_contains( $wpcmb_source, 'ABSPATH' ) && ! str_contains( $wpcmb_source, 'WP_UNINSTALL_PLUGIN' ) ) {
		$wpcmb_guards[] = sprintf( '%s has no direct-access guard', basename( (string) $wpcmb_path ) );
	}
}

wpcmb_rule( 'every shipped PHP file parses and refuses direct access', $wpcmb_guards );

/*
 * Rule 8: nothing in the package that should not ship.
 *
 * A zip carrying a dev folder is not rejected for it, but it is dead weight on
 * every install and every update.
 */
$wpcmb_strays = array();

foreach ( array( 'node_modules', '.git', 'tests/coverage', '.github' ) as $wpcmb_stray ) {
	if ( is_dir( $wpcmb_root . '/' . $wpcmb_stray ) ) {
		$wpcmb_strays[] = sprintf( '%s/ exists and must be excluded from the build', $wpcmb_stray );
	}
}

// Said as a reminder rather than a failure: the build excludes them.
if ( array() !== $wpcmb_strays ) {
	printf( "  note  the build excludes: %s\n", implode( ', ', array_map( 'strtok', $wpcmb_strays, array_fill( 0, count( $wpcmb_strays ), '/' ) ) ) );
}

printf( "\n%d of %d rules passed.\n", $wpcmb_passed, $wpcmb_passed + $wpcmb_failed );

exit( $wpcmb_failed > 0 ? 1 : 0 );
