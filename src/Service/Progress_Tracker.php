<?php
/**
 * Service for tracking the progress of content scraping.
 *
 * @package     A8CSP_Scrapper_to_WP
 * @subpackage  Command
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP\Service;

/**
 * Service class for tracking the progress of content scraping.
 *
 * This class is responsible for managing the progress of scraping operations,
 */
class Progress_Tracker {

	/**
	 * File path for storing progress data.
	 *
	 * @var string
	 */
	protected $file_path;

	/**
	 * The list of scraped URLs.
	 *
	 * @var array<string>|null
	 */
	protected $scraped_urls = null;

	/**
	 * Access to the WP File System for file operations.
	 *
	 * @var \WP_Filesystem_Base
	 */
	protected $wp_filesystem;

	/**
	 * Constructor to initialize the progress tracker with a file path.
	 *
	 * @param string|null $file_path Path to the file where progress data will be stored.
	 */
	public function __construct( ?string $file_path = null ) {
		if ( null === $file_path ) {
			// Create a temp file in the WP Uploads directory.
			$upload_dir = wp_upload_dir();
			$file_path  = $upload_dir['basedir'] . '/scrapper_progress.json';
		}
		$this->file_path = $file_path;
	}

	/**
	 * Attempts to load the progress data from the file.
	 *
	 * @return void
	 *
	 * @throws \Exception If the file does not exist or if there is a JSON parsing error.
	 */
	public function load_progress(): void {
		// if wp_filesystem is not set, initialize it.
		if ( null === $this->wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
			$this->wp_filesystem = $GLOBALS['wp_filesystem'] ?? null;
		}

		// If the file does not exist, create it.
		if ( ! $this->wp_filesystem->exists( $this->file_path ) ) {
			$this->wp_filesystem->put_contents( $this->file_path, wp_json_encode( array() ) );
		}
		// Read the file contents.
		$contents = $this->wp_filesystem->get_contents( $this->file_path );

		// Attempt to decode the JSON data.
		$data = json_decode( $contents, true );

		// Throw an exception if the data is not an array or we have a parsing error.
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \Exception( 'JSON parsing error: ' . esc_html( json_last_error_msg() ) );
		}

		if ( ! is_array( $data ) ) {
			throw new \Exception( 'Invalid progress data format.' );
		}
		// Set the scraped URLs from the loaded data.
		$this->scraped_urls = array_map( 'sanitize_text_field', $data );
	}

	/**
	 * Check if a URL has been scraped.
	 *
	 * @param string $url The URL to check.
	 *
	 * @return boolean True if the URL has been scraped, false otherwise.
	 */
	public function has_scraped( string $url ): bool {
		if ( null === $this->scraped_urls ) {
			$this->load_progress();
		}

		// Ensure $scraped_urls is not null after load_progress()
		if ( null === $this->scraped_urls ) {
			return false;
		}

		return in_array( $url, $this->scraped_urls, true );
	}

	/**
	 * Add a URL to the list of scraped URLs.
	 *
	 * @param string $url The URL to add.
	 *
	 * @return void
	 */
	public function add_scraped_url( string $url ): void {
		if ( null === $this->scraped_urls ) {
			$this->load_progress();
		}

		// Ensure $scraped_urls is not null after load_progress()
		if ( null === $this->scraped_urls ) {
			return;
		}

		if ( ! in_array( $url, $this->scraped_urls, true ) ) {
			$this->scraped_urls[] = sanitize_text_field( $url );
			$this->save_progress();
		}
	}

	/**
	 * Save the current progress to the file.
	 *
	 * @return void
	 *
	 * @throws \Exception If the file does not exist or if saving fails.
	 */
	protected function save_progress(): void {
		if ( null === $this->wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
			$this->wp_filesystem = $GLOBALS['wp_filesystem'] ?? null;

			// If we still don't have a wp_filesystem, throw an exception.
			if ( null === $this->wp_filesystem ) {
				throw new \Exception( 'Failed to initialize WP_Filesystem.' );
			}
		}

		// Ensure $scraped_urls is not null before saving
		if ( null === $this->scraped_urls ) {
			$this->load_progress();
		}

		// Throw an exception if the file in file path does not exist.
		if ( ! $this->wp_filesystem->exists( $this->file_path ) ) {
			throw new \Exception( 'Progress file does not exist at ' . esc_html( $this->file_path ) );
		}

		// Encode the scraped URLs to JSON and save to the file.
		$json_data = wp_json_encode( $this->scraped_urls, JSON_PRETTY_PRINT );
		if ( ! is_string( $json_data ) ) {
			$json_data = '[]';
		}
		if ( false === $this->wp_filesystem->put_contents( $this->file_path, $json_data ) ) {
			throw new \Exception( 'Failed to save progress data to ' . esc_html( $this->file_path ) );
		}
	}

	/**
	 * Get all the scraped URLs.
	 *
	 * @return array<string> The list of scraped URLs.
	 */
	public function get_scraped_urls(): array {
		if ( null === $this->scraped_urls ) {
			$this->load_progress();
		}

		// Ensure $scraped_urls is not null after load_progress()
		if ( null === $this->scraped_urls ) {
			return array();
		}

		return $this->scraped_urls;
	}

	/**
	 * Get the count of scraped URLs.
	 *
	 * @return int The count of scraped URLs.
	 */
	public function get_scraped_count(): int {
		if ( null === $this->scraped_urls ) {
			$this->load_progress();
		}

		// Ensure $scraped_urls is not null after load_progress()
		if ( null === $this->scraped_urls ) {
			return 0;
		}

		return count( $this->scraped_urls );
	}

	/**
	 * Clear the progress data.
	 *
	 * @return void
	 *
	 * @throws \Exception If the file cannot be cleared.
	 */
	public function clear(): void {
		if ( null === $this->scraped_urls ) {
			$this->load_progress();
		}

		// Clear the scraped URLs.
		$this->scraped_urls = array();

		// Save the empty progress data to the file.
		$encoded_data = wp_json_encode( $this->scraped_urls );
		if ( ! is_string( $encoded_data ) ) {
			$encoded_data = '[]';
		}
		if ( false === $this->wp_filesystem->put_contents( $this->file_path, $encoded_data ) ) {
			throw new \Exception( 'Failed to clear progress data in ' . esc_html( $this->file_path ) );
		}
	}
}
