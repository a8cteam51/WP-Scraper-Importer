<?php
/**
 * Command used to scrape a list of links to scrape the content.
 *
 * @package     A8CSP_Scraper_to_WP
 * @subpackage  Command
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScraperToWP\Command;

defined( 'ABSPATH' ) || exit;

use A8C\SpecialProjects\ScraperToWP\WP_Scraper;
use A8C\SpecialProjects\ScraperToWP\Action\Content_Scraper;
use A8C\SpecialProjects\ScraperToWP\Action\Post_Inserter;
use A8C\SpecialProjects\ScraperToWP\Service\Progress_Tracker;
use WP_CLI_Command;
use Exception;
use WP;
use WP_CLI;
use WP_CLI\Fetchers\Post;

use function WP_CLI\Utils\make_progress_bar;

/**
 * Scraper command.
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
	 * wp scraper-to-wp import --dry-run --delay=30 --per=25
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

		// Default the batch size from WP_Scraper; --per overrides it.
		$this->links_per_request = WP_Scraper::get_batch_size();
		if ( isset( $assoc_args['per'] ) ) {
			$this->links_per_request = (int) $assoc_args['per'];
		}

		if ( isset( $assoc_args['silent'] ) ) {
			$this->silent = true;
		}

		// Get the URLs to import from the configured provider (Noop by default).
		$provider = WP_Scraper::get_url_provider();
		$provider->setup();
		try {
			$this->urls_to_import = $provider->get_urls();
		} finally {
			$provider->teardown();
		}

		// If we have set to reset the progress, reset it.
		if ( isset( $assoc_args['reset-progress'] ) && true === $assoc_args['reset-progress'] ) {
			$this->progress_tracker->clear();
		}
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
		foreach ( $url_chunk as $url ) {
			try {
				// Create a new content scraper instance.
				$content_scraper = new Content_Scraper( $url );

				// Process the URL and scrape the content.
				$content_scraper->process();

				// If dry run, skip the import.
				if ( $this->dry_run ) {
					$this->process_as_dry_run( $content_scraper );
					continue;
				}

				// Process the content as an import.
				$this->process_as_import( $content_scraper );
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
	 * @param Content_Scraper $content_scraper The content scraper instance.
	 *
	 * @return void
	 *
	 * @throws Exception Thrown if there are errors during the dry run.
	 */
	public function process_as_dry_run( Content_Scraper $content_scraper ): void {
		$mapper = WP_Scraper::get_content_mapper( $content_scraper );

		$title   = $mapper->get_title();
		$content = $mapper->get_content();
		$author  = $mapper->get_author();
		$terms   = $mapper->get_terms();

		// Check if we have any errors.
		if ( $content_scraper->has_errors() ) {
			// Compile the error message.
			$message = PHP_EOL . 'Dry run errors for URL ' . $content_scraper->get_url() . PHP_EOL . implode( PHP_EOL, $content_scraper->get_errors() );

			throw new Exception( esc_attr( $message ) );
		}

		// Show the message.
		$message = sprintf(
			'Dry run for URL %s completed successfully. Title: %s, Body: %s, Author: %s, Terms: %s',
			$content_scraper->get_url(),
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
	 * @param Content_Scraper $content_scraper The content scraper instance.
	 *
	 * @return void
	 */
	public function process_as_import( Content_Scraper $content_scraper ): void {
		// ====================================================================
		// EXTENSION POINT 1: CUSTOMIZE THE CONTENT MAPPER
		// ====================================================================

		/*
		 * This is where you can change which mapper class to use for processing
		 * the scraped content. You have several options:
		 *
		 * OPTION 1: Use a different mapper class entirely
		 * $mapper = new Custom_Blog_Mapper( $content_scraper );
		 * $mapper = new Product_Mapper( $content_scraper );
		 * $mapper = new News_Article_Mapper( $content_scraper );
		 *
		 * OPTION 2: Choose mapper based on URL or content
		 * $url = $content_scraper->get_url();
		 * if ( strpos( $url, '/products/' ) !== false ) {
		 *     $mapper = new Product_Mapper( $content_scraper );
		 * } elseif ( strpos( $url, '/news/' ) !== false ) {
		 *     $mapper = new News_Mapper( $content_scraper );
		 * } else {
		 *     $mapper = new Default_Content_Mapper( $content_scraper );
		 * }
		 *
		 * OPTION 3: Choose mapper based on content analysis
		 * $content = $content_scraper->get_content();
		 * if ( strpos( $content, 'class="recipe"' ) !== false ) {
		 *     $mapper = new Recipe_Mapper( $content_scraper );
		 * } elseif ( strpos( $content, 'class="event"' ) !== false ) {
		 *     $mapper = new Event_Mapper( $content_scraper );
		 * } else {
		 *     $mapper = new Default_Content_Mapper( $content_scraper );
		 * }
		 *
		 * OPTION 4: Dynamic mapper selection with fallbacks
		 * $mapper_classes = [
		 *     'Specialized_Mapper',
		 *     'Fallback_Mapper',
		 *     'Default_Content_Mapper'
		 * ];
		 * foreach ( $mapper_classes as $class ) {
		 *     if ( class_exists( $class ) ) {
		 *         $mapper = new $class( $content_scraper );
		 *         break;
		 *     }
		 * }
		 *
		 * CREATE YOUR OWN MAPPER: Extend Default_Content_Mapper or Abstract_Content_Mapper
		 * See /src/Mapper/Default_Content_Mapper.php for detailed customization examples
		 */
		// ====================================================================

		$mapper = WP_Scraper::get_content_mapper( $content_scraper );

		$title   = $mapper->get_title();
		$content = $mapper->get_content();
		$author  = $mapper->get_author();
		$terms   = $mapper->get_terms();

		// Get post configuration from mapper
		$post_status    = $mapper->get_post_status();
		$post_type      = $mapper->get_post_type();
		$post_parent    = $mapper->get_post_parent();
		$featured_image = $mapper->get_featured_image();
		$meta           = $mapper->get_post_meta();
		$post_date      = $mapper->get_post_date();

		// Fall back to the WP_Scraper defaults when the mapper supplies nothing.
		if ( $author <= 0 ) {
			$author = WP_Scraper::get_default_user();
		}
		if ( '' === $post_type ) {
			$post_type = WP_Scraper::get_post_type();
		}
		if ( '' === $post_status ) {
			$post_status = WP_Scraper::get_post_status();
		}

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

		// ====================================================================
		// EXTENSION POINT 2: CUSTOMIZE POST INSERTION & PROCESSING
		// ====================================================================
		//
		// This is the perfect place to modify the Post_Inserter object before
		// creating or updating posts. You can add custom logic here:
		//
		// EXAMPLE 1: Add custom post meta based on conditions
		// if ( $post_type === 'product' ) {
		// $inserter->add_meta( '_product_sku', uniqid( 'SKU-' ) );
		// $inserter->add_meta( '_product_source', 'imported' );
		// }
		//
		// EXAMPLE 2: Set publish date based on content
		// if ( preg_match('/published[:\s]+([0-9-]+)/', $content, $matches) ) {
		// $inserter->set_post_date( $matches[1] );
		// }
		//
		// EXAMPLE 3: Add to specific taxonomy terms
		// if ( strpos( $content_scraper->get_url(), 'electronics' ) !== false ) {
		// $inserter->add_terms( 'product_category', ['Electronics', 'Imported'] );
		// }
		//
		// EXAMPLE 4: Set custom post format
		// if ( strpos( $content, '<blockquote>' ) !== false ) {
		// $inserter->set_post_format( 'quote' );
		// }
		//
		// EXAMPLE 5: Conditional processing based on content quality
		// $word_count = str_word_count( wp_strip_all_tags( $content ) );
		// if ( $word_count < 100 ) {
		// $inserter->set_post_status( 'draft' );
		// $inserter->add_meta( '_import_note', 'Short content - needs review' );
		// }
		//
		// EXAMPLE 6: Set menu order for pages
		// if ( $post_type === 'page' ) {
		// $url_segments = explode( '/', trim( parse_url( $content_scraper->get_url(), PHP_URL_PATH ), '/' ) );
		// $inserter->set_menu_order( count( $url_segments ) );
		// }
		//
		// EXAMPLE 7: Add custom hooks for third-party integrations
		// do_action( 'scraper_before_post_insert', $inserter, $content_scraper, $mapper );
		//
		// EXAMPLE 8: Set excerpt if not automatically generated
		// if ( empty( $inserter->get_post_excerpt() ) ) {
		// $excerpt = wp_trim_words( wp_strip_all_tags( $content ), 30 );
		// $inserter->set_post_excerpt( $excerpt );
		// }
		//
		// EXAMPLE 9: Handle duplicates - check if post already exists
		// $existing_post = get_page_by_title( $title, OBJECT, $post_type );
		// if ( $existing_post ) {
		// Update instead of create
		// $post = $inserter->update( $existing_post->ID );
		// } else {
		// $post = $inserter->create();
		// }
		//
		// EXAMPLE 10: Log import details for debugging
		// error_log( sprintf(
		// 'Importing: %s | Type: %s | Status: %s | URL: %s',
		// $title,
		// $post_type,
		// $post_status,
		// $content_scraper->get_url()
		// ) );
		//
		// ADD YOUR CUSTOM POST PROCESSING LOGIC HERE
		// ====================================================================

		// If creating a post.
		$post = $inserter->create();

		// If updating the post phpcs:ignore
		// $post = $inserter->update( $post_id );

		// ====================================================================
		// EXTENSION POINT 3: POST-CREATION PROCESSING
		// ====================================================================
		//
		// After the post is created, you can add additional processing:
		//
		// EXAMPLE 1: Send notifications
		// if ( $inserter->has_post_instance() ) {
		// wp_mail( 'admin@site.com', 'New Import', 'Post imported: ' . $title );
		// }
		//
		// EXAMPLE 2: Clear caches
		// if ( function_exists( 'wp_cache_flush' ) ) {
		// wp_cache_flush();
		// }
		//
		// EXAMPLE 3: Trigger webhooks
		// if ( $inserter->has_post_instance() ) {
		// wp_remote_post( 'https://webhook.site/your-webhook', [
		// 'body' => json_encode([
		// 'action' => 'post_imported',
		// 'post_id' => $inserter->get_post_id(),
		// 'title' => $title,
		// 'url' => $content_scraper->get_url()
		// ])
		// ]);
		// }
		// ====================================================================

		// If we dont have a post, we can assume there was an error.
		if ( ! $inserter->has_post_instance() ) {
			$this->errors[] = 'Error creating post for URL: ' . $content_scraper->get_url();
		}

		// if we have a featured image, set it.
		if ( '' !== $featured_image ) {
			$inserter = $inserter->set_featured_image( $featured_image, array() ); // WP MEDIA UPLOAD ARGS.
		}

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
		\WP_CLI::line( 'Scraper to WP - Import Command' );
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
