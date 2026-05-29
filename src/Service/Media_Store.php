<?php
/**
 * Persistent media map store.
 *
 * Persists a mapping of source image URL => attachment ID so the same remote
 * image is never imported twice. Uses SQLite when the pdo_sqlite extension is
 * available, otherwise falls back to a CSV file. The store file lives in the
 * uploads directory.
 *
 * @package     A8CSP_Scraper_to_WP
 * @subpackage  Service
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScraperToWP\Service;

defined( 'ABSPATH' ) || exit;

/**
 * A simple source-URL => attachment-ID store (SQLite or CSV).
 */
class Media_Store {

	/**
	 * Whether the store has been initialised.
	 *
	 * @var bool
	 */
	private $ready = false;

	/**
	 * The active backend ( 'sqlite' or 'csv' ).
	 *
	 * @var string
	 */
	private $backend = '';

	/**
	 * The full path to the store file.
	 *
	 * @var string
	 */
	private $path = '';

	/**
	 * The PDO connection (SQLite backend only).
	 *
	 * @var \PDO|null
	 */
	private $pdo;

	/**
	 * Lazily initialise the store on first use.
	 *
	 * Kept out of the constructor so constructing the object has no side effects:
	 * the uploads directory, store file and SQLite table are only created when
	 * the store is actually read from or written to.
	 *
	 * @return void
	 */
	private function boot(): void {
		if ( $this->ready ) {
			return;
		}
		$this->ready = true;

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'wp-scraper-importer/';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( extension_loaded( 'pdo_sqlite' ) ) {
			$this->backend = 'sqlite';
			$this->path    = $dir . 'media-map.sqlite';
			$this->pdo     = new \PDO( 'sqlite:' . $this->path ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
			$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
			$this->pdo->exec( 'CREATE TABLE IF NOT EXISTS media_map ( url TEXT PRIMARY KEY, media_id INTEGER NOT NULL )' );
		} else {
			$this->backend = 'csv';
			$this->path    = $dir . 'media-map.csv';
			if ( ! file_exists( $this->path ) ) {
				touch( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * Get the stored attachment ID for a source URL.
	 *
	 * @param string $url The source image URL.
	 *
	 * @return int|null The attachment ID, or null if not stored.
	 */
	public function get( string $url ): ?int {
		$this->boot();

		if ( 'sqlite' === $this->backend ) {
			$stmt = $this->pdo->prepare( 'SELECT media_id FROM media_map WHERE url = :url LIMIT 1' );
			$stmt->execute( array( ':url' => $url ) );
			$id = $stmt->fetchColumn();

			return false === $id ? null : (int) $id;
		}

		// CSV backend.
		$handle = fopen( $this->path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			return null;
		}

		$found = null;
		while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) { // phpcs:ignore
			if ( isset( $row[0], $row[1] ) && $row[0] === $url ) {
				$found = (int) $row[1];
				break;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $found;
	}

	/**
	 * Store the attachment ID for a source URL.
	 *
	 * @param string $url      The source image URL.
	 * @param int    $media_id The attachment ID to store.
	 *
	 * @return void
	 */
	public function set( string $url, int $media_id ): void {
		$this->boot();

		if ( 'sqlite' === $this->backend ) {
			$stmt = $this->pdo->prepare( 'INSERT OR REPLACE INTO media_map ( url, media_id ) VALUES ( :url, :media_id )' );
			$stmt->execute(
				array(
					':url'      => $url,
					':media_id' => $media_id,
				)
			);

			return;
		}

		// CSV backend — don't write a duplicate row.
		if ( null !== $this->get( $url ) ) {
			return;
		}

		$handle = fopen( $this->path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			return;
		}

		fputcsv( $handle, array( $url, $media_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
