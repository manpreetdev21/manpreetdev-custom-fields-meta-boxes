<?php
/**
 * Build the zip that goes to WordPress.org.
 *
 * Two things make a package uploadable: the folder inside the zip is named
 * after the slug, whatever the checkout is called, and nothing that is not
 * part of the plugin travels with it. Everything the directory checks is
 * verified first, because a zip built from a package that fails those checks
 * is a zip nobody should upload.
 *
 * Run with `php bin/build.php`. The zip lands in the plugin root.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions -- A developer-run CLI script.

$wpcmb_root = dirname( __DIR__ );

/**
 * A human-readable byte count.
 *
 * Spelled out here rather than borrowed from WordPress, because the one
 * thing this script has to be able to do is run from a plain PHP CLI with
 * no WordPress loaded at all.
 *
 * @param int $bytes Byte count.
 */
function wpcmb_size( int $bytes ): string {
	foreach ( array(
		'GB' => 1073741824,
		'MB' => 1048576,
		'KB' => 1024,
	) as $unit => $size ) {
		if ( $bytes >= $size ) {
			return round( $bytes / $size, 1 ) . ' ' . $unit;
		}
	}

	return $bytes . ' B';
}

/**
 * The slug, taken from the file that declares the plugin.
 */
$wpcmb_slug = '';

foreach ( (array) glob( $wpcmb_root . '/*.php' ) as $wpcmb_path ) {
	if ( str_contains( (string) file_get_contents( (string) $wpcmb_path ), 'Plugin Name:' ) ) {
		$wpcmb_slug = basename( (string) $wpcmb_path, '.php' );
		break;
	}
}

if ( '' === $wpcmb_slug ) {
	fwrite( STDERR, "No file in the plugin root declares a Plugin Name.\n" );
	exit( 1 );
}

$wpcmb_main = (string) file_get_contents( $wpcmb_root . '/' . $wpcmb_slug . '.php' );

preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $wpcmb_main, $wpcmb_found );

$wpcmb_version = $wpcmb_found[1] ?? '0.0.0';

printf( "Building %s %s\n\n", $wpcmb_slug, $wpcmb_version );

/*
 * Nothing is packaged until the submission checks pass. The build is the last
 * moment anybody looks, so it is the right place to insist.
 */
$wpcmb_output = array();
$wpcmb_status = 0;

exec( sprintf( 'php %s 2>&1', escapeshellarg( $wpcmb_root . '/tests/release-check.php' ) ), $wpcmb_output, $wpcmb_status );

if ( 0 !== $wpcmb_status ) {
	echo implode( "\n", $wpcmb_output ), "\n\n";
	fwrite( STDERR, "The submission checks failed. Nothing was built.\n" );
	exit( 1 );
}

echo "submission checks: passed\n";

/*
 * What ships.
 *
 * An allow list, not a deny list: a deny list quietly ships whatever is added
 * to the checkout next, and the thing that gets shipped by accident is always
 * the thing nobody meant to publish.
 */
$wpcmb_include = array(
	$wpcmb_slug . '.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'includes',
	'templates',
	'assets',
);

/**
 * Every file below a path, as relative paths.
 *
 * @param string $root Plugin root.
 * @param string $path Relative file or directory.
 *
 * @return array<int, string>
 */
function wpcmb_collect( string $root, string $path ): array {
	$absolute = $root . '/' . $path;

	if ( is_file( $absolute ) ) {
		return array( $path );
	}

	if ( ! is_dir( $absolute ) ) {
		return array();
	}

	$files = array();

	$directory = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $absolute, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $directory as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}

		$relative = str_replace( '\\', '/', substr( (string) $file->getPathname(), strlen( $root ) + 1 ) );

		// Editor and OS leftovers live in working directories, not packages.
		if ( 1 === preg_match( '/(^|\/)(\.|~)|\.(bak|log|map|orig)$|Thumbs\.db$/', $relative ) ) {
			continue;
		}

		$files[] = $relative;
	}

	sort( $files );

	return $files;
}

$wpcmb_files = array();

foreach ( $wpcmb_include as $wpcmb_entry ) {
	$wpcmb_files = array_merge( $wpcmb_files, wpcmb_collect( $wpcmb_root, $wpcmb_entry ) );
}

if ( array() === $wpcmb_files ) {
	fwrite( STDERR, "Nothing to package.\n" );
	exit( 1 );
}

/*
 * The package sits in the plugin root, beside the file it is built from.
 *
 * It is never packaged into itself: what ships is an allow list, and a zip
 * in the root is not on it. Any older zip of the same version is replaced
 * rather than left to be uploaded by mistake.
 */
$wpcmb_zip_path = sprintf( '%s/%s.%s.zip', $wpcmb_root, $wpcmb_slug, $wpcmb_version );

if ( file_exists( $wpcmb_zip_path ) && ! unlink( $wpcmb_zip_path ) ) {
	fwrite( STDERR, "Could not replace the existing zip.\n" );
	exit( 1 );
}

$wpcmb_archive = new ZipArchive();

if ( true !== $wpcmb_archive->open( $wpcmb_zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not open the zip for writing.\n" );
	exit( 1 );
}

$wpcmb_bytes = 0;

foreach ( $wpcmb_files as $wpcmb_file ) {
	// Everything sits under a folder named after the slug, which is what
	// WordPress unpacks into wp-content/plugins.
	$wpcmb_archive->addFile( $wpcmb_root . '/' . $wpcmb_file, $wpcmb_slug . '/' . $wpcmb_file );

	$wpcmb_bytes += (int) filesize( $wpcmb_root . '/' . $wpcmb_file );
}

$wpcmb_archive->close();

printf( "\n%d files, %s uncompressed\n", count( $wpcmb_files ), wpcmb_size( $wpcmb_bytes ) );
printf( "zip: %s (%s)\n", basename( $wpcmb_zip_path ), wpcmb_size( (int) filesize( $wpcmb_zip_path ) ) );

/*
 * One zip in the root, and it is this one.
 *
 * Two packages side by side is how the wrong version gets uploaded: they
 * differ by three characters in the middle of a long filename. Only this
 * plugin's own packages are removed, and only after the new one is on disk.
 */
foreach ( (array) glob( $wpcmb_root . '/' . $wpcmb_slug . '.*.zip' ) as $wpcmb_old ) {
	$wpcmb_old = (string) $wpcmb_old;

	if ( realpath( $wpcmb_old ) === realpath( $wpcmb_zip_path ) ) {
		continue;
	}

	if ( unlink( $wpcmb_old ) ) {
		printf( "removed: %s\n", basename( $wpcmb_old ) );
		continue;
	}

	printf( "could not remove: %s\n", basename( $wpcmb_old ) );
}
