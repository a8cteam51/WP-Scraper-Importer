<?php
/**
 * A URL provider fixture used in tests.
 *
 * Returns a fixed set of URLs so the import stack can be driven without any
 * external source. Demonstrates the intended extension point: a clone writes a
 * class implementing URL_Provider and registers it via WP_Scraper.
 */

declare( strict_types=1 );

namespace Tests\Support;

use A8C\SpecialProjects\ScrapperToWP\Provider\URL_Provider;

/**
 * Fixed-list URL provider fixture.
 */
class Test_URL_Provider implements URL_Provider {

	/**
	 * The URLs this provider returns.
	 *
	 * @var array<int, string>
	 */
	public static $urls = array(
		'https://example.test/sample',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function setup(): void {
		// Nothing to set up.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int           $limit    Hard cap ( 0 = no cap ).
	 * @param int           $offset   Number to skip.
	 * @param int           $per_page Number to return ( 0 = all ).
	 * @param callable|null $filter   Optional predicate.
	 *
	 * @return array<int, string>
	 */
	public function get_urls( int $limit = 0, int $offset = 0, int $per_page = 0, ?callable $filter = null ): array {
		$urls = self::$urls;

		if ( null !== $filter ) {
			$urls = array_values( array_filter( $urls, $filter ) );
		}
		if ( $offset > 0 || $per_page > 0 ) {
			$urls = array_slice( $urls, $offset, $per_page > 0 ? $per_page : null );
		}
		if ( $limit > 0 ) {
			$urls = array_slice( $urls, 0, $limit );
		}

		return $urls;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function teardown(): void {
		// Nothing to tear down.
	}
}
