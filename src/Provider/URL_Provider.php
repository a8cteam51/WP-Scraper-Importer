<?php
/**
 * Interface for URL providers.
 *
 * A URL provider supplies the list of URLs the importer should scrape. The base
 * ships a Noop provider (does nothing) and an optional CSV provider. A clone
 * registers its own provider via WP_Scraper::set_url_provider( My_Provider::class ).
 *
 * Providers are resolved by class name and instantiated with no constructor
 * arguments, so any setup (opening a database connection, reading a file) must
 * happen in setup() and be released in teardown().
 *
 * @package     A8CSP_Scraper_to_WP
 * @subpackage  Provider
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScraperToWP\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for anything that can supply URLs to the importer.
 */
interface URL_Provider {

	/**
	 * Initialise any resources required to provide URLs.
	 *
	 * Called once before get_urls(). Use this to open a database connection,
	 * read and validate a file, etc.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function setup(): void;

	/**
	 * Get the list of URLs to scrape.
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
	public function get_urls( int $limit = 0, int $offset = 0, int $per_page = 0, ?callable $filter = null ): array;

	/**
	 * Release any resources opened in setup().
	 *
	 * Called once after the URLs have been consumed. Use this to close a
	 * database connection, free file handles, etc.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function teardown(): void;
}
