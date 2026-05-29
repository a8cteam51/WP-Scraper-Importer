<?php
/**
 * Command used to scrape a list of links to scrape the content.
 *
 * @package     A8CSP_Scrapper_to_WP
 * @subpackage  Command
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP\Command;

defined( 'ABSPATH' ) || exit;

use A8C\SpecialProjects\ScrapperToWP\Action\Archive_Content_Scraper;
use A8C\SpecialProjects\ScrapperToWP\Action\Content_Scrapper;
use A8C\SpecialProjects\ScrapperToWP\Action\Post_Inserter;
use A8C\SpecialProjects\ScrapperToWP\Service\Progress_Tracker;
use A8C\SpecialProjects\ScrapperToWP\Mapper\Default_Content_Mapper;
use WP_CLI_Command;
use Exception;
use WP;
use WP_CLI;
use WP_CLI\Fetchers\Post;

use function WP_CLI\Utils\make_progress_bar;

/**
 * Scrapper command.
 */
class Import_Command extends WP_CLI_Command {

	/**
	 * Holds access to the raq scraped content.
	 *
	 * @var array<string, mixed>
	 */
	public $downloaded = array();

	/**
	 * Holds any errors that occur during the import.
	 *
	 * @var array<int, string>
	 */
	public $errors = array();

	/**
	 * Holds any messages that are output during the import.
	 *
	 * @var array<int, string>
	 */
	public $messages = array();

	/**
	 * The URLs to import.
	 *
	 * @var array<int, string>
	 */
	public $urls_to_import = array();

	/**
	 * Holds the urls that have been processed.
	 *
	 * @var array<int, string>
	 */
	public $processed_urls = array();

	/**
	 * Is this a dry run?
	 *
	 * @var boolean
	 */
	public $dry_run = false;

	/**
	 * Delay between requests.
	 * - Time in seconds to wait between requests.
	 * - This is to prevent the server from being overloaded.
	 *
	 * @var integer
	 */
	public $delay = 30;

	/**
	 * Should the command run in silent mode?
	 *
	 * @var boolean
	 */
	public $silent = false;

	/**
	 * The number of links per request.
	 *
	 * - This is to prevent the server from being overloaded.
	 * - This is to prevent the server from being overloaded.
	 *
	 * @var integer
	 */
	public $links_per_request = 25;

	/**
	 * The link progress tracker.
	 *
	 * @var Progress_Tracker
	 */
	protected $progress_tracker;

	/**
	 * Command entry point.
	 *
	 * ## OPTIONS
	 *
	 *
	 * [--dry-run]
	 * : Whether to run the command in dry-run mode.
	 * default: false
	 *
	 * [--delay=<delay>]
	 * : The delay between requests in seconds.
	 * default: 30
	 *
	 * [--per=<per-request>]
	 * : The number of links to scrape per request.
	 * default: 25
	 *
	 * [--silent]
	 * : Whether to run the command in silent mode.
	 * default: false
	 *
	 * [--reset-progress]
	 * : Whether to reset the progress tracker.
	 * default: false
	 *
	 * ## EXAMPLES
	 * wp scrapper-to-wp import --dry-run --delay=30 --per=25 --url-list=/path/to/urls.csv
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
		// Set the progress tracker.
		$this->progress_tracker = new Progress_Tracker();

		// Set the params based on the command line arguments.
		$this->set_params( $args, $assoc_args );

		// Show pre run messages.
		$this->show_pre_run_messages();

		// Process the links.
		$this->process_urls();
	}

	/**
	 * Sets the params based on the command line arguments.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array<int, string> $args       The command arguments.
	 * @param array<int, string> $assoc_args The command associative arguments.
	 *
	 * @return void
	 */
	private function set_params( array $args, array $assoc_args ) {

		if ( isset( $assoc_args['dry-run'] ) ) {
			$this->dry_run = true;
		}

		if ( isset( $assoc_args['delay'] ) ) {
			$this->delay = (int) $assoc_args['delay'];
		}

		if ( isset( $assoc_args['per'] ) ) {
			$this->links_per_request = (int) $assoc_args['per'];
		}

		if ( isset( $assoc_args['silent'] ) ) {
			$this->silent = true;
		}

		$this->urls_to_import = $this->get_urls_to_import();

		// If we have set to reset the progress, reset it.
		if ( isset( $assoc_args['reset-progress'] ) && true === $assoc_args['reset-progress'] ) {
			$this->progress_tracker->clear();
		}
	}


	/**
	 * Gets the url and hash lis from the temp database.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> The array of URLs to import.
	 */
	private function get_urls_to_import(): array {
		$database = a8scp_scrapper_to_wp_get_database_service();

		$urls = $database->get_results( 'SELECT * FROM page_url_hashes WHERE post_id = 0' );
		$urls = \array_filter(
			$urls,
			function ( $item ) {
				return str_ends_with( $item['page_url'], '.html' );
			}
		);

		// Remove any http urls where the https version also exists.
		$https_urls    = array();
		$filtered_urls = array();

		// First pass: collect all HTTPS URLs
		foreach ( $urls as $url_record ) {
			$url = $url_record['page_url'];
			if ( strpos( $url, 'https://' ) === 0 ) {
				// Convert HTTPS to HTTP equivalent for comparison
				$http_equivalent                = str_replace( 'https://', 'http://', $url );
				$https_urls[ $http_equivalent ] = true;
			}
		}

		// Second pass: filter out HTTP URLs that have HTTPS equivalents
		foreach ( $urls as $url_record ) {
			$url = $url_record['page_url'];

			// If this is an HTTP URL and we have its HTTPS equivalent, skip it
			if ( strpos( $url, 'http://' ) === 0 && isset( $https_urls[ $url ] ) ) {
				continue; // Skip this HTTP URL as HTTPS version exists
			}

			// Keep this URL (either HTTPS, or HTTP without HTTPS equivalent)
			$filtered_urls[] = $url_record;
		}

		return $filtered_urls;
	}

	/**
	 * Process the links.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function process_urls(): void {

		$current_count = 0;

		// Filter out any previously scraped URLs.
		$urls = array_filter(
			$this->urls_to_import,
			function ( $url ) {
				return ! $this->progress_tracker->has_scraped( $url['page_url'] );
			}
		);

		// Remove any archives from the list.
		$urls = array_filter(
			$urls,
			function ( $url ) {

				if ( \str_ends_with( $url['page_url'], '/books/index.html' ) ) {
					return false;
				}

				// Exclude creating_passionate_users/archives.html
				if ( \str_ends_with( $url['page_url'], 'creating_passionate_users/archives.html' ) ) {
					return false;
				}

				// Exclude creating_passionate_users/{word}/index.html patterns
				if ( preg_match( '#/creating_passionate_users/[^/]+/index\.html$#', $url['page_url'] ) ) {
					return false;
				}

				// Look for /year/mm/index.html or /year/week{number}/index.html at the end of the url and remove them.
				return ! preg_match( '#/\d{4}/(?:\d{2}|week\d+)/index\.html$#', $url['page_url'] );
			}
		);

		// If we have no urls,
		if ( 0 === count( $urls ) ) {
			$this->finish_import();
			return;
		}

		// Chunk based on the links per request.
		$chunks = array_chunk( $urls, max( 1, $this->links_per_request ) );

		// if no silent mode, show the chunk count.
		if ( ! $this->is_silent() ) {
			\WP_CLI::line( 'Processing ' . count( $chunks ) . ' chunks of upto ' . $this->links_per_request . ' links per batch.' );

			// Start the progress bar.
			$bar = make_progress_bar(
				'Processing URLs',
				count( $chunks )
			);
		}
		// Iterate over the chunks.
		foreach ( $chunks as $key => $chunk ) {

			// Increase the tracker
			if ( ! $this->is_silent() && isset( $bar ) ) {
				$bar->tick();
			}

			// Process the chunk.
			$this->process_chunk( $chunk );

			// Wait for the delay, if we have more chunks left
			if ( \array_key_last( $chunks ) !== $key ) {
				sleep( $this->delay );
			}
		}

		// If not in silent mode, finish the progress bar.
		if ( ! $this->is_silent() && isset( $bar ) ) {
			$bar->finish();
		}

		// Show any errors that occurred during the import.
		if ( 0 !== count( $this->errors ) && ! $this->is_silent() ) {
			\WP_CLI::warning( 'Errors occurred during the import:' );
			foreach ( $this->errors as $error ) {
				\WP_CLI::warning( $error );
			}
		}

		// Show the final import message.
		if ( ! $this->is_silent() ) {
			\WP_CLI::line( 'Import process completed.' );
			\WP_CLI::line( 'Processed URLs: ' . $this->progress_tracker->get_scraped_count() );
			\WP_CLI::line( 'Errors: ' . count( $this->errors ) );
			if ( 0 !== count( $this->errors ) ) {
				foreach ( $this->errors as $error ) {
					\WP_CLI::line( 'Error: ' . $error );
				}
			}
			if ( 0 !== count( $this->messages ) ) {
				foreach ( $this->messages as $message ) {
					\WP_CLI::line( 'Message: ' . $message );
				}
			}
		}
	}

	/**
	 * Process a chunk of urls.
	 *
	 * @param array<int, string> $url_chunk The array of URLs to process.
	 *
	 * @return void
	 */
	/** @phpstan-ignore-next-line phpcs:ignore */
	public function process_chunk( array $url_chunk ): void {
		// Iterate over the links and scrape the content.
		foreach ( $url_chunk as $url_data ) {
			try {

				// Create a new content scrapper instance.
				$content_scrapper = new Archive_Content_Scraper( $url_data );

				// Process the URL and scrape the content.
				$content_scrapper->process();

				// If dry run, skip the import.
				if ( $this->dry_run ) {
					$this->process_as_dry_run( $content_scrapper, $url_data );
					continue;
				}

				// Process the content as an import.
				$this->process_as_import( $content_scrapper, $url_data );
			} catch ( \Exception $e ) {

				// Show the error message.
				if ( ! $this->is_silent() ) {
					WP_CLI::error( 'Error processing URL ' . $url_data['page_url'] . ': ' . $e->getMessage() );
				}
				$this->errors[] = 'Error processing URL ' . $url_data['page_url'] . ': ' . $e->getMessage();
			} finally {
				// Add the URL to the processed URLs.
				$url = $url_data['page_url'];

				$this->progress_tracker->add_scraped_url( $url_data['page_url'] );
			}
		}
	}

	/**
	 * Dry run handling the import.
	 *
	 * @since 1.0.0
	 *
	 * @param Content_Scrapper $content_scrapper The content scrapper instance.
	 * @param array            $url_data         The URL data array.
	 *
	 * @return void
	 *
	 * @throws Exception Thrown if there are errors during the dry run.
	 */
	public function process_as_dry_run( Content_Scrapper $content_scrapper, array $url_data ): void {
		$mapper = new Default_Content_Mapper( $content_scrapper );

		$title   = $mapper->get_title();
		$content = $mapper->get_content();
		$author  = $mapper->get_author();
		$terms   = $mapper->get_terms();

		// Check if we have any errors.
		if ( $content_scrapper->has_errors() ) {
			// Compile the error message.
			$message = PHP_EOL . 'Dry run errors for URL ' . $content_scrapper->get_url() . PHP_EOL . implode( PHP_EOL, $content_scrapper->get_errors() );

			throw new Exception( esc_attr( $message ) );
		}

		// Show the message.
		$message = sprintf(
			'Dry run for URL %s completed successfully. Title: %s, Body: %s, Author: %s, Terms: %s',
			$content_scrapper->get_url(),
			wp_trim_words( \wp_strip_all_tags( $content ), 25, '...' ),
			$title,
			$author,
			wp_json_encode( $terms )
		);

		if ( ! $this->is_silent() ) {
			\WP_CLI::line( $message );
		}
	}

	/**
	 * Finish the import process.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function finish_import(): void {
		if ( ! $this->is_silent() ) {
			\WP_CLI::line( 'Import process completed.' );
			\WP_CLI::line( 'Processed URLs: ' . count( $this->processed_urls ) );
			\WP_CLI::line( 'Errors: ' . count( $this->errors ) );
			if ( 0 !== count( $this->errors ) ) {
				\WP_CLI::line( 'Errors: ' . implode( PHP_EOL, $this->errors ) );
			}
			\WP_CLI::line( 'Scraped URLs: ' . implode( PHP_EOL, $this->progress_tracker->get_scraped_urls() ) );
			\WP_CLI::line( '----------------------------------------' );
		}
		// Progress is automatically saved when URLs are added
	}

	/**
	 * Process the content as an import.
	 *
	 * @since 1.0.0
	 *
	 * @param Content_Scrapper $content_scrapper The content scrapper instance.
	 * @param array            $url_data         The URL data array.
	 *
	 * @return void
	 */
	public function process_as_import( Content_Scrapper $content_scrapper, array $url_data ): void {

		$mapper = new Default_Content_Mapper( $content_scrapper );

		$title     = $mapper->get_title();
		$content   = $mapper->get_content();
		$author    = $mapper->get_author();
		$terms     = $mapper->get_terms();
		$post_date = $mapper->get_post_date();

		$this->messages[] = 'Importing with date ' . $post_date . ' for URL ' . $content_scrapper->get_url();

		// Get post configuration from mapper
		$post_status    = $mapper->get_post_status();
		$post_type      = $mapper->get_post_type();
		$post_parent    = $mapper->get_post_parent();
		$featured_image = $mapper->get_featured_image();
		$meta           = $mapper->get_post_meta();

		// Remove the initial title and opening links.
		$content = preg_replace( '/<p align="right">.*?<\/p>/s', '', $content );

		// Remove the title header.
		$content = preg_replace( '/<h[1-4]>(.*?)<\/h[1-4]>/s', '', $content, 1 );

		$inserter = new Post_Inserter(
			$title,
			$content,
			$post_status,
			$author,
			$post_date,
			$terms,
			$meta
		);
		$inserter = $inserter
			->set_post_type( $post_type )
			->set_parent_id( $post_parent );

		// If creating a post.
		$post = $inserter->create();

		// If we dont have a post, we can assume there was an error.
		if ( ! $inserter->has_post_instance() ) {
			$this->errors[] = 'Error creating post for URL: ' . $content_scrapper->get_url();
		}

		// if we have a featured image, set it.
		if ( '' !== $featured_image ) {
			$inserter = $inserter->set_featured_image( $featured_image, array() ); // WP MEDIA UPLOAD ARGS.
		}

		// Run any meta.
		$inserter = $inserter->set_meta();

		// Go through the import log and get the types.
		$error     = array_filter(
			$inserter->get_import_log(),
			function ( $log ) {
				return 'error' === $log['type'];
			}
		);
		$not_error = array_filter(
			$inserter->get_import_log(),
			function ( $log ) {
				return 'error' !== $log['type'];
			}
		);
		// Add the errors to the errors array.
		if ( 0 !== count( $error ) ) {
			foreach ( $error as $err ) {
				$this->errors[] = $err['message'];
			}
		}

		// Add the messages to the messages array.
		if ( 0 !== count( $not_error ) ) {
			foreach ( $not_error as $msg ) {
				$this->messages[] = $msg['message'];
			}
		}

		// Update the post as processed.
		$this->mark_post_as_processed( $inserter->get_post_id(), $url_data );
	}

	/**
	 * Mark a post as processed.
	 *
	 * @param integer $post_id  The post ID to mark as processed.
	 * @param array   $url_data The URL data array.
	 *
	 * @return void
	 */
	public function mark_post_as_processed( int $post_id, array $url_data ): void {
		$database = a8scp_scrapper_to_wp_get_database_service();

		$urls = array(
			$url_data['page_url'],
			str_replace( 'http://', 'https://', $url_data['page_url'] ),
			str_replace( 'https://', 'http://', $url_data['page_url'] ),
		);

		// sqlite query to update the post_id where the page_url matches.
		$sql = 'UPDATE page_url_hashes SET post_id = :post_id WHERE page_url = :page_url';

		// Success noted
		$success_message = '';

		foreach ( $urls as $url ) {
			$rows = $database->query(
				$sql,
				array(
					':post_id'  => $post_id,
					':page_url' => $url,
				)
			)->rowCount();

			if ( $rows > 0 ) {
				$success_message .= sprintf( 'URL %s updated %d rows', $url, $rows );
			}
		}

		// If the success message is not empty, add it to messages.
		if ( '' !== $success_message ) {
			$this->messages[] = $success_message;
		}
	}

	/**
	 * Checks if we are in silent mode.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @return boolean
	 */
	public function is_silent(): bool {
		return $this->silent;
	}

	/**
	 * Show pre run messages.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @return void
	 */
	private function show_pre_run_messages() {
		\WP_CLI::line( 'Scrapper to WP - Import Command' );
		\WP_CLI::line( '----------------------------------------' );
		\WP_CLI::line( 'This command will scrape the content from the URLs provided.' );
		\WP_CLI::line( 'The content will be imported into the WordPress site.' );
		\WP_CLI::line( '----------------------------------------' );
		\WP_CLI::line( 'Dry run: ' . ( $this->dry_run ? 'true' : 'false' ) );
		\WP_CLI::line( 'Delay: ' . absint( $this->delay ) . ' seconds' );
		\WP_CLI::line( 'Links per request: ' . absint( $this->links_per_request ) );
		\WP_CLI::line( 'Silent mode: ' . ( $this->is_silent() ? 'true' : 'false' ) );
		\WP_CLI::line( 'Previously Scraped: ' . ( $this->progress_tracker->get_scraped_count() ) );
		\WP_CLI::line( '----------------------------------------' );
		\WP_CLI::line( 'URLs to import:' . count( $this->urls_to_import ) );
		\WP_CLI::line( '----------------------------------------' );
	}
}
