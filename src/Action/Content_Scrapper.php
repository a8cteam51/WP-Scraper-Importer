<?php
/**
 * Content scraper actions.
 * Takes a url and returns back the content of the page.
 *
 * @package     A8CSP_Scrapper_to_WP
 * @subpackage  Command
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP\Action;

use RuntimeException;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Action class for content scrapping.
 */
class Content_Scrapper {

	/**
	 * Hold the URL to scrape.
	 *
	 * @var string
	 */
	protected $url;

	/**
	 * The final url after following redirects.
	 *
	 * @var string
	 */
	protected $final_url;

	/**
	 * Denotes if the page has been scraped.
	 *
	 * @var boolean
	 */
	protected $scraped = false;

	/**
	 * Errors that occurred during the scraping.
	 *
	 * @var array<int, string>
	 */
	protected $errors = array();

	/**
	 * Page header
	 *
	 * @var array<string, string>
	 */
	protected $header = array();

	/**
	 * Page content.
	 *
	 * @var string
	 */
	protected $content = '';

	/**
	 * Constructor.
	 *
	 * @param string $url The URL to scrape.
	 */
	public function __construct( string $url ) {
		$this->url = $url;
	}

	/**
	 * Process the URL and scrape the content.
	 *
	 * @return void
	 */
	public function process(): void {
		// If we dont have a URL, we can't scrape.
		if ( empty( $this->url ) ) {
			throw new InvalidArgumentException( 'URL cannot be empty.' );
		}

		// Get the remove content.
		try {
			$response = $this->follow_redirects( $this->url );
		} catch ( \Throwable $th ) {
			$this->errors[] = $th->getMessage();
			$response       = null;
		} finally {
			$this->scraped = true;
		}

		// If we have a response, set the content and header.
		if ( null !== $response ) {
			$this->header    = $response['header'];
			$this->content   = $response['content'];
			$this->final_url = $response['url'];
		} else {
			$this->errors[] = 'Failed to retrieve content from the URL.';
		}
	}

	/**
	 * Checks if we have scraped the content.
	 *
	 * @return boolean
	 */
	public function is_scraped(): bool {
		return $this->scraped;
	}

	/**
	 * Has errors occurred during the scraping.
	 *
	 * @return boolean
	 */
	public function has_errors(): bool {
		return ! empty( $this->errors );
	}

	/**
	 * Get the errors that occurred during the scraping.
	 *
	 * @return array<int, string>
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/**
	 * Get the scraped content.
	 *
	 * @return string
	 */
	public function get_content(): string {
		if ( ! $this->scraped ) {
			throw new RuntimeException( 'Content has not been scraped yet.' );
		}
		return $this->content;
	}

	/**
	 * Get the page header.
	 *
	 * @return array<string, string>
	 */
	public function get_header(): array {
		if ( ! $this->scraped ) {
			throw new RuntimeException( 'Content has not been scraped yet.' );
		}
		return $this->header;
	}

	/**
	 * Get the URL that was scraped.
	 *
	 * @return string
	 */
	public function get_url(): string {
		if ( ! $this->scraped ) {
			throw new RuntimeException( 'Content has not been scraped yet.' );
		}
		return $this->url;
	}

	/**
	 * Get the final URL after following redirects.
	 *
	 * @return string
	 */
	public function get_final_url(): string {
		if ( ! $this->scraped ) {
			throw new RuntimeException( 'Content has not been scraped yet.' );
		}
		return $this->final_url;
	}

	/**
	 * Checks if there was any redirects during the scraping.
	 *
	 * @return boolean
	 */
	public function had_redirected(): bool {
		if ( ! $this->scraped ) {
			throw new RuntimeException( 'Content has not been scraped yet.' );
		}
		return \untrailingslashit( $this->url ) !== \untrailingslashit( $this->final_url );
	}

	/**
	 * Recursively follow redirects.
	 *
	 * @param string  $url            The URL to follow.
	 * @param integer $redirect_count The current redirect count.
	 *
	 * @return array|null
	 */
	private function follow_redirects( string $url, int $redirect_count = 0 ): ?array {
		$redirect_codes = array( 301, 302, 303, 307, 308 );
		if ( $redirect_count >= 5 ) {
			return null; // Max redirects reached.
		}
		$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			return null; // Error fetching URL.
		}
		if ( in_array( $response['response']['code'], $redirect_codes, true ) ) {
			$redirect_url = $response['response']['location'];
			return $this->follow_redirects( $redirect_url, $redirect_count + 1 );
		}

		return array(
			'header'    => $response['headers'],
			'content'   => wp_remote_retrieve_body( $response ),
			'http_code' => $response['response']['code'],
			'url'       => $url,
		);
	}
}
