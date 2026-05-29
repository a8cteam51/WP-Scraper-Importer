<?php
/**
 * No-op URL provider.
 *
 * The default provider when none has been registered via WP_Scraper. It returns
 * no URLs, so the importer runs but does nothing — true to the scaffold being a
 * base that is cloned and built upon rather than used directly.
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
 * A provider that supplies no URLs.
 */
class Noop_URL_Provider implements URL_Provider {

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
	 * @param int           $limit    Unused.
	 * @param int           $offset   Unused.
	 * @param int           $per_page Unused.
	 * @param callable|null $filter   Unused.
	 *
	 * @return array<int, string> Always an empty array.
	 */
	public function get_urls( int $limit = 0, int $offset = 0, int $per_page = 0, ?callable $filter = null ): array {
		return array();
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
