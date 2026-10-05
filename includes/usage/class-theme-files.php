<?php
/**
 * The files of the active theme that are scanned for attachments.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the scannable files of the active theme and its parent, and notices
 * when they change.
 *
 * Theme files change through deploys and editors that fire no hook, so the
 * screens that show usage compare a fingerprint of the files' sizes and
 * modification times with the one recorded at the last scan.
 */
final class Theme_Files {

	/**
	 * Option holding the fingerprint of the files at the last scan.
	 */
	public const FINGERPRINT_OPTION = 'jcore_kirjasto_theme';

	/**
	 * Extensions of the files that can refer to an attachment.
	 */
	private const EXTENSIONS = array( 'php', 'html', 'twig', 'json', 'css', 'scss', 'js' );

	/**
	 * Directories that are never scanned, besides those starting with a dot.
	 */
	private const EXCLUDED_DIRECTORIES = array( 'node_modules', 'vendor' );

	/**
	 * Files larger than this many bytes are skipped.
	 */
	private const MAX_SIZE = 2097152;

	/**
	 * The scannable files, keyed by absolute path, with the source recorded in
	 * the index: the theme's directory name and the path within it, e.g.
	 * `kielo/patterns/hero.php`.
	 *
	 * @return array<string, string>
	 */
	public static function files(): array {
		$files = array();

		foreach ( self::roots() as $slug => $root ) {
			foreach ( self::walk( $root ) as $path ) {
				$files[ $path ] = $slug . '/' . substr( $path, strlen( $root ) + 1 );
			}
		}

		/**
		 * Filters the theme files scanned for attachments.
		 *
		 * @param array<string, string> $files Sources (`{theme}/{path}`), keyed by absolute path.
		 */
		return apply_filters( 'jcore_kirjasto_theme_files', $files );
	}

	/**
	 * A hash of which files there are, how large they are and when they changed.
	 *
	 * @return string
	 */
	public static function fingerprint(): string {
		$parts = array();

		foreach ( self::files() as $path => $source ) {
			$parts[] = $source . ':' . (int) filesize( $path ) . ':' . (int) filemtime( $path );
		}

		return md5( implode( "\n", $parts ) );
	}

	/**
	 * Re-indexes the theme right away if its files changed since the last scan,
	 * so the screen being loaded already shows the result.
	 *
	 * @return void
	 */
	public static function maybe_reindex(): void {
		static $checked = false;

		if ( $checked || Rebuild::is_running() ) {
			return;
		}
		$checked = true;

		if ( self::fingerprint() !== get_option( self::FINGERPRINT_OPTION ) ) {
			Indexer::index_theme();
		}
	}

	/**
	 * The directories of the active theme and its parent, keyed by directory name.
	 *
	 * @return array<string, string>
	 */
	private static function roots(): array {
		$roots = array(
			get_stylesheet() => get_stylesheet_directory(),
			get_template()   => get_template_directory(),
		);

		return array_filter( array_map( 'untrailingslashit', $roots ), 'is_dir' );
	}

	/**
	 * Lists the scannable files under a directory, in a stable order.
	 *
	 * @param string $root Directory.
	 *
	 * @return string[]
	 */
	private static function walk( string $root ): array {
		$directories = new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			static function ( \SplFileInfo $file ): bool {
				if ( $file->isDir() ) {
					return ! str_starts_with( $file->getFilename(), '.' ) && ! in_array( $file->getFilename(), self::EXCLUDED_DIRECTORIES, true );
				}

				return in_array( strtolower( $file->getExtension() ), self::EXTENSIONS, true ) && $file->getSize() <= self::MAX_SIZE;
			}
		);

		$files = array();
		foreach ( new \RecursiveIteratorIterator( $directories ) as $file ) {
			$files[] = $file->getPathname();
		}

		sort( $files );

		return $files;
	}
}
