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

use A8C\SpecialProjects\ScrapperToWP\Action\Content_Scrapper;
use A8C\SpecialProjects\ScrapperToWP\Action\Post_Inserter;
use A8C\SpecialProjects\ScrapperToWP\Service\Progress_Tracker;
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
	 * [--url-list=<url-list>]
	 * : Path to the CSV file containing the URLs to scrape.
	 * default: /path/to/urls.csv
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
		$this->urls_to_import = $args;

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

		$csv_location = null;

		// If we have a valid url-list, set the urls to import.
		if ( isset( $assoc_args['url-list'] ) ) {
			// Check the url-list is a valid file.
			if ( ! file_exists( $assoc_args['url-list'] ) ) {
				\WP_CLI::error( 'The url-list file does not exist.' );
				exit( 1 );
			}

			// Check if the url-list is a valid CSV file.
			if ( ! preg_match( '#\.csv$#i', $assoc_args['url-list'] ) ) {
				\WP_CLI::error( 'The url-list file is not a valid CSV file.' );
				exit( 1 );
			}

			$csv_location = $assoc_args['url-list'];
		}

		// If csv list is not set, use the fallback.
		if ( null === $csv_location ) {
			$csv_location = A8CSP_SCRAPPER_TO_WP_DIR_PATH . '/data/urls.csv';
		}

		$this->urls_to_import = $this->get_links_from_csv( $csv_location );

		// If we have set to reset the progress, reset it.
		if ( isset( $assoc_args['reset-progress'] ) && true === $assoc_args['reset-progress'] ) {
			$this->progress_tracker->clear();
		}
	}

	/**
	 * Get all links from a CSV file.
	 *
	 * @param string $csv_file The path to the CSV file.
	 *
	 * @return array<int, string> The array of links.
	 */
	private function get_links_from_csv( string $csv_file ): array {
		$links = array();

		if ( ! file_exists( $csv_file ) ) {
			\WP_CLI::error( 'The CSV file does not exist.' );
			return $links;
		}

		if ( ! is_readable( $csv_file ) ) {
			\WP_CLI::error( 'The CSV file is not readable.' );
			return $links;
		}

		if ( false === ( $handle = fopen( $csv_file, 'r' ) ) ) {
			\WP_CLI::error( 'The CSV file could not be opened.' );
			return $links;
		}

		while ( ( $data = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
			foreach ( $data as $link ) {
				if ( filter_var( $link, FILTER_VALIDATE_URL ) ) {
					$links[] = $link;
				}
			}
		}

		fclose( $handle );

		return $links;
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
				return ! $this->progress_tracker->has_scraped( $url );
			}
		);

		// If we have no urls,
		if ( empty( $urls ) ) {
			$this->finish_import();
			return;
		}

		// Chunk based on the links per request.
		$chunks = array_chunk( $urls, $this->links_per_request );

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
			if ( ! $this->is_silent() ) {
				$bar->tick();
			}

			// Process the chunk.
			$this->process_chunk( $chunk );

			// Wait for the delay, if we have more chunks left
			if ( $key !== \array_key_last( $chunks ) ) {
				sleep( $this->delay );
			}
		}

		// If not in silent mode, finish the progress bar.
		if ( ! $this->is_silent() ) {
			$bar->finish();
		}

		// Show any errors that occurred during the import.
		if ( ! empty( $this->errors ) && ! $this->is_silent() ) {
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
			if ( ! empty( $this->errors ) ) {
				foreach ( $this->errors as $error ) {
					\WP_CLI::line( 'Error: ' . $error );
				}
			}
			if ( ! empty( $this->messages ) ) {
				foreach ( $this->messages as $message ) {
					\WP_CLI::line( 'Message: ' . $message );
				}
			}
		}
	}

	/***
	 * Process a chunk of urls.
	 *
	 * @param string[] $urls The array of URLs to process.
	 *
	 * @return void
	 */
	public function process_chunk( array $urls ): void {
		// Iterate over the links and scrape the content.
		foreach ( $urls as $url ) {
			try {
				// Create a new content scrapper instance.
				$content_scrapper = new Content_Scrapper( $url );

				// Process the URL and scrape the content.
				$content_scrapper->process();

				// If dry run, skip the import.
				if ( $this->dry_run ) {
					$this->process_as_dry_run( $content_scrapper );
					continue;
				}

				// Process the content as an import.
				$this->process_as_import( $content_scrapper );
			} catch ( \Exception $e ) {
				// Show the error message.
				if ( ! $this->is_silent() ) {
					WP_CLI::error( 'Error processing URL ' . $url . ': ' . $e->getMessage() );
				}
				$this->errors[] = 'Error processing URL ' . $url . ': ' . $e->getMessage();
			} finally {
				// Add the URL to the processed URLs.
				$this->progress_tracker->add_scraped_url( $url );
			}
		}
	}

	/**
	 * Dry run handling the import.
	 *
	 * @since 1.0.0
	 *
	 * @param Content_Scrapper $content_scrapper The content scrapper instance.
	 *
	 * @return void
	 *
	 * @throws Exception Thrown if there are errors during the dry run.
	 */
	public function process_as_dry_run( Content_Scrapper $content_scrapper ): void {
		$title   = $this->get_page_title( $content_scrapper );
		$content = $this->get_page_content( $content_scrapper );
		$author  = $this->get_content_author( $content_scrapper );
		$terms   = $this->get_terms_from_content( $content_scrapper );

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
			if ( ! empty( $this->errors ) ) {
				\WP_CLI::line( 'Errors: ' . implode( PHP_EOL, $this->errors ) );
			}
			\WP_CLI::line( 'Scraped URLs: ' . implode( PHP_EOL, $this->progress_tracker->get_scraped_urls() ) );
			\WP_CLI::line( '----------------------------------------' );
		}
		// Save the progress tracker.
		$this->progress_tracker->save_progress();
		if ( ! $this->is_silent() ) {
			\WP_CLI::line( 'Progress saved to ' . $this->progress_tracker->get_file_path() );
		}
	}

	/**
	 * Process the content as an import.
	 *
	 * @since 1.0.0
	 *
	 * @param Content_Scrapper $content_scrapper The content scrapper instance.
	 *
	 * @return void
	 */
	public function process_as_import( Content_Scrapper $content_scrapper ): void {
		$title   = $this->get_page_title( $content_scrapper );
		$content = $this->get_page_content( $content_scrapper );
		$author  = $this->get_content_author( $content_scrapper );
		$terms   = $this->get_terms_from_content( $content_scrapper );

		/******************************************
		 * Set These params as you need.
		 */

		$post_status    = 'publish'; // Set the post status.
		$post_type      = 'post'; // Set the post type.
		$post_parent    = 0; // Set the post parent ID if needed.
		$featured_image = 'https://pinkcrab.gq/wp-content/uploads/2024/12/FdoBd21WIAsdpgW_25.jpg'; // Set the featured image URL if needed.
		$meta           = array();

		$inserter = new Post_Inserter(
			$title,
			$content,
			$post_status,
			$author,
			$terms,
			$meta
		);
		$inserter = $inserter
			->set_post_type( $post_type )
			->set_parent_id( $post_parent );

		## THIS IS SOMEWHERE YOUY CAN ADD YOUR OWN CODE TO MODIFY THE INSERTION OR EDITING

		// If creating a post.
		$post = $inserter->create();

		// If updating the post
		// $post = $inserter->update( $post_id );

		###### END REGION
		// If we dont have a post, we can assume there was an error.
		if ( ! $inserter->has_post_instance() ) {
			$this->errors[] = 'Error creating post for URL: ' . $content_scrapper->get_url();
		}

		// if we have a featured image, set it.
		if ( ! empty( $featured_image ) ) {
			$inserter = $inserter->set_featured_image( $featured_image, array() ); // WP MEDIA UPLOAD ARGS.
		}

		// Go through the import log and get the types.
		$error     = array_filter(
			$inserter->get_import_log(),
			function ( $log ) {
				return isset( $log['type'] ) && 'error' === $log['type'];
			}
		);
		$not_error = array_filter(
			$inserter->get_import_log(),
			function ( $log ) {
				return isset( $log['type'] ) && 'error' !== $log['type'];
			}
		);
		// Add the errors to the errors array.
		if ( ! empty( $error ) ) {
			foreach ( $error as $err ) {
				$this->errors[] = $err['message'];
			}
		}

		// Add the messages to the messages array.
		if ( ! empty( $not_error ) ) {
			foreach ( $not_error as $msg ) {
				$this->messages[] = $msg['message'];
			}
		}
	}

	/**
	 * Get the page title from the content scrapper.
	 *
	 * @since 1.0.0
	 *
	 * @param Content_Scrapper $content_scrapper The content scrapper instance.
	 *
	 * @return string The page title.
	 */
	public function get_page_title( Content_Scrapper $content_scrapper ): string {
		// If the content scrapper has a title, return it.
		if ( $content_scrapper->is_scraped() ) {
			$processor = new \WP_HTML_Tag_Processor( $content_scrapper->get_content() );
			if ( $processor->next_tag( 'title' ) ) {
				$processor->get_tag();
				return $processor->get_modifiable_text();
			}
		}

		// Otherwise, return a default title.
		return 'Imported Content';
	}

	/**
	 * Get the pages content from the content scrapper.
	 *
	 * @since 1.0.0
	 *
	 * @param Content_Scrapper $content_scrapper The content scrapper instance.
	 *
	 * @return string The page content.
	 */
	public function get_page_content( Content_Scrapper $content_scrapper ): string {
		// If the content scrapper has content, extract the body content.
		if ( $content_scrapper->is_scraped() ) {
			libxml_use_internal_errors( true );
			$dom = new \DOMDocument();
			$dom->loadHTML( $content_scrapper->get_content(), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

			// Extract the content from the body tag.
			$body = $dom->getElementsByTagName( 'main' )->item( 0 );
			if ( ! $body ) {
				return null;
			}

			// Iterate through the body children and concatenate their HTML.
			$body_content = '';
			foreach ( $body->childNodes as $child ) { // phpcs:ignore
				// Pass through the content child node mapping function.
				// This function can be overridden to modify the content.
				$body_content .= $this->map_content_child_node( $dom->saveHTML( $child ) );
			}

			return trim( $body_content );
		}

		// Otherwise, return an empty string.
		return '';
	}

	/**
	 * Map a content child node.
	 *
	 * @since 1.0.0
	 *
	 * @param string $nodes_html The HTML of the nodes.
	 *
	 * @return string The mapped HTML.
	 */
	public function map_content_child_node( string $nodes_html ): string {
		// ADD YOUR OWN CODE IN HERE.
		// This is a placeholder function, implement your logic to map the content child nodes.
		return $nodes_html; // Placeholder, return the original HTML.
	}

	/**
	 * Get the contents author
	 *
	 * @since 1.0.0
	 *
	 * @param Content_Scrapper $content_scrapper The content scrapper instance.
	 *
	 * @return integer The user id of the author.
	 */
	public function get_content_author( Content_Scrapper $content_scrapper ): int {
		// ADD YOUR OWN CODE IN HERE.
		$content = $content_scrapper->get_content();

		return 1; // Placeholder, implement your logic to extract the author.
	}

	/**
	 * Get terms from the content.
	 *
	 * @since 1.0.0
	 *
	 * @param Content_Scrapper $content_scrapper The content scrapper instance.
	 *
	 * @return array<string, array<int, string>> An array of terms. {tag: [foo, bar], category: [baz]}
	 */
	public function get_terms_from_content( Content_Scrapper $content_scrapper ): array {
		// ADD YOUR OWN CODE IN HERE.
		$content = $content_scrapper->get_content();

		// Placeholder, implement your logic to extract terms.
		return array(
			'tag'      => array(),
			'category' => array(),
		);
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
		// If in silent mode, do not show any messages.
		if ( $this->is_silent() ) {
			return;
		}

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
