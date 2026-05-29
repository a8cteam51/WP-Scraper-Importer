<?php
/**
 * Command used to compile archived data.
 *
 * @package     A8CSP_Scrapper_to_WP
 * @subpackage  Command
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP\Command;

defined( 'ABSPATH' ) || exit;

use A8C\SpecialProjects\ScrapperToWP\Archive_Compiler\Compiler;
use WP_CLI_Command;
use WP_CLI;

/**
 * Compile command.
 */
class Compile_Command extends WP_CLI_Command {

	/**
	 * Command entry point.
	 *
	 * ## OPTIONS
	 *
	 * <start-url>
	 * : The start URL to begin compilation from.
	 *
	 * ## EXAMPLES
	 * wp scrapper-to-wp compile https://example.com/start-page
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array<int, string> $args       The command arguments.
	 * @param array<int, string> $assoc_args The command associative arguments.
	 *
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ) {
		// Check if start URL is provided
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Start URL is required.' );
		}

		$start_url = $args[0];

		// Validate URL
		if ( false === filter_var( $start_url, FILTER_VALIDATE_URL ) ) {
			WP_CLI::error( 'Invalid URL provided.' );
		}

		WP_CLI::line( 'Starting compilation process...' );
		WP_CLI::line( 'Start URL: ' . $start_url );
		WP_CLI::line( '----------------------------------------' );

		// Create compiler instance
		$compiler = new Compiler();

		// Call process single method
		$compiler->process_url( $start_url );

		// Get the scraped URLs and add them to the database.
		$compiler->store_scraped_data();

		// Cleanup any uncached URLs.
		$compiler->cleanup_uncached_urls();

		// Attempt to fix any invalid cached data.
		$compiler->remove_invalid_cached_files();

		WP_CLI::success( 'Compilation process completed.' );
	}
}
