<?php
/**
 * Example URL provider that reads URLs from a CSV file.
 *
 * This is an EXAMPLE implementation. It assumes column A of each row holds the
 * URL. Point FILE_PATH at your CSV (or extend this class and override the
 * constant) then register it with WP_Scraper::set_url_provider( CSV_URL_Provider::class ).
 *
 * @package     A8CSP_Scrapper_to_WP
 * @subpackage  Provider
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a list of URLs from a CSV file (column A).
 */
class CSV_URL_Provider implements URL_Provider {

	/**
	 * Path to the CSV file to read URLs from.
	 *
	 * @var string
	 */
	const FILE_PATH = A8CSP_SCRAPPER_TO_WP_DIR_PATH . 'data/urls.csv';

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function setup(): void {
		// Nothing to set up.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @param int           $limit    Hard cap on the number of URLs to return ( 0 = no cap ).
	 * @param int           $offset   Number of URLs to skip from the start.
	 * @param int           $per_page Number of URLs to return for this call ( 0 = all remaining ).
	 * @param callable|null $filter   Optional predicate ( string $url ): bool — return true to keep the URL.
	 *
	 * @return array<int, string> The array of URLs.
	 */
	public function get_urls( int $limit = 0, int $offset = 0, int $per_page = 0, ?callable $filter = null ): array {
		$urls = array();

		if ( ! file_exists( static::FILE_PATH ) || ! is_readable( static::FILE_PATH ) ) {
			return $urls;
		}

		$handle = fopen( static::FILE_PATH, 'r' ); // phpcs:ignore
		if ( false === $handle ) {
			return $urls;
		}

		// Column A of each row is the URL.
		while ( ( $row = fgetcsv( $handle, 1000, ',' ) ) !== false ) { // phpcs:ignore
			$url = $row[0] ?? '';
			if ( is_string( $url ) && false !== filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$urls[] = $url;
			}
		}

		fclose( $handle ); // phpcs:ignore

		// Apply the optional caller-supplied filter.
		if ( null !== $filter ) {
			$urls = array_values( array_filter( $urls, $filter ) );
		}

		// Apply a hard cap on the total number of URLs to consider.
		if ( $limit > 0 ) {
			$urls = array_slice( $urls, 0, $limit );
		}

		// Apply offset / per-page slicing.
		$length = $per_page > 0 ? $per_page : null;
		if ( $offset > 0 || null !== $length ) {
			$urls = array_slice( $urls, $offset, $length );
		}

		return $urls;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function teardown(): void {
		// Nothing to tear down.
	}
}
