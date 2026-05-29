<?php

/**
 * Handles the compiling of the archived data into a usable format.
 *
 * @package A8C\SpecialProjects\ScrapperToWP\Archive_Compiler
 */


namespace A8C\SpecialProjects\ScrapperToWP\Archive_Compiler;

use WP;
use A8C\SpecialProjects\ScrapperToWP\Service\Internet_Archive;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Compiler {

	/**
	 * Access the database service.
	 *
	 * @var \A8C\SpecialProjects\ScrapperToWP\Service\Database
	 */
	protected $database;

	/**
	 * The starting URL.
	 *
	 * @var string
	 */
	protected $starting_url;

	/**
	 * URLs scraped.
	 *
	 * @var array
	 */
	protected $scraped_urls = array();

	/**
	 * Media URLs found.
	 *
	 * @var array
	 */
	protected $media_urls = array();

	/**
	 * Access the file cache service.
	 *
	 * @var \A8C\SpecialProjects\ScrapperToWP\Service\File_Cache
	 */
	protected $file_cache;

	public function __construct() {
		// Get the base path.
		$upload_dir = wp_upload_dir();
			$base   = trailingslashit( $upload_dir['basedir'] ) . 'wbm-import/';
		$cache_dir  = $base . 'cache/';

		// Check dirs exist, create if not.
		if ( ! file_exists( $base ) ) {
			mkdir( $base, 0755, true );
		}
		if ( ! file_exists( $cache_dir ) ) {
			mkdir( $cache_dir, 0755, true );
		}

		// Initialize the services.
		$this->file_cache = new \A8C\SpecialProjects\ScrapperToWP\Service\File_Cache( $cache_dir );
		$this->database   = a8scp_scrapper_to_wp_get_database_service();
	}

	/**
	 * Process a single URL.
	 *
	 * @param string $url The URL to process.
	 *
	 * @return void
	 */
	public function process_url( string $url ): void {

		$base_url = $url;

		// Normalize the URL.
		$url = \A8C\SpecialProjects\ScrapperToWP\Service\Internet_Archive::normalize_url( $url );

		// If we have already scraped this URL, return.
		if ( in_array( $url, $this->scraped_urls, true ) ) {
			return;
		}

		// Mark the URL as scraped.
		$this->scraped_urls[] = $url;

		// Get the latest snapshot of the URL from IA.
		$url = \A8C\SpecialProjects\ScrapperToWP\Service\Internet_Archive::get_latest_snapshot( $url );

		// Get the content of the URL, from cache if available.
		if ( $this->file_cache->is_cached( $url ) ) {
			$content = $this->file_cache->get_cached( $url );
		} else {
			$content = $this->get_content( $url );

			// if the content is the post close captcha page, try the base url.
			if ( str_contains( $content, '<section id="captcha">' )
			|| str_contains( $content, 'Please complete the security check to access NetworkSolutions.com' )
			) {
				WP_CLI::line( 'Captcha detected for URL: ' . $url . ' - trying base URL: ' . $base_url );
				$content = $this->get_content( $base_url );
			}

			if ( str_contains( $content, '<section id="captcha">' )
			|| str_contains( $content, 'Please complete the security check to access NetworkSolutions.com' )
			) {
				WP_CLI::line( 'Failed to bypass captcha for URL: ' . $url . ' and base URL: ' . $base_url . ' SKIPPING!!!!' );
			}

			$this->file_cache->set_cache( $url, $content );
		}

		// Get the all the links from the content.
		$links = $this->extract_links( $content );

		$media            = $this->extract_media_urls( $content );
		$this->media_urls = array_merge( $this->media_urls, $media );

		\WP_CLI::line( '** Found ' . count( $links ) . ' links and ' . count( $media ) . ' media URLs. from ' . $url . ( $this->file_cache->is_cached( $url ) ? ' (from cache)' : ' (fetching)' ) );

		// Process each link.
		foreach ( $links as $link ) {
			$this->process_url( $link );

			// If the link ends in .pdf, .jpg, .png, .gif, skip, add to media urls.
			if ( preg_match( '/\.(pdf|jpg|jpeg|png|gif)$/i', $link ) ) {
				$this->media_urls[] = $link;
				continue;
			}
		}

		\WP_CLI::line( '-- Total Media: ' . count( $this->media_urls ) . ' from ' . count( $this->scraped_urls ) . ' scraped URLs so far.' );
	}

	/**
	 * Attempt to clean up any none cached urls.
	 *
	 * @return void
	 */
	public function cleanup_uncached_urls(): void {
		// Get all the pages urls from the database.
		$sql       = 'SELECT * FROM page_url_hashes';
		$page_urls = $this->database->get_results( $sql );

		// Loop through each page URL and check if it's cached.
		foreach ( $page_urls as $page_url ) {
			$url_hash = $page_url['url_hash'];
			if ( ! $this->file_cache->is_cached( $url_hash, true ) ) {
				$url = Internet_Archive::get_latest_snapshot( $page_url['page_url'] );
				// Get the content and cache it.
				$content = $this->get_content( $url );
				if ( '' === $content ) {
					\WP_CLI::line( 'Failed to fetch content for URL: ' . $url );

					$content = $this->get_content( $page_url['page_url'] );
					if ( '' === $content ) {
						\WP_CLI::line( 'Also failed to fetch content for original URL: ' . $page_url['page_url'] );
						continue;
					}
				}
				$this->file_cache->set_cache( $url_hash, $content, true );
				\WP_CLI::line(
					sprintf(
						'%s - Cached content for URL: %s (%d characters)',
						$url_hash,
						$url,
						strlen( $content )
					)
				);
			}
		}
	}

	/**
	 * Try to remove any invalid cached files.
	 *
	 * @return void
	 */
	public function remove_invalid_cached_files(): void {
		// Get all the page url hashes from the database.
		$sql       = 'SELECT * FROM page_url_hashes';
		$page_urls = $this->database->get_results( $sql );

		// Iterate through each page URL and check if the cached file is valid.
		foreach ( $page_urls as $page_url ) {
			$url_hash = $page_url['url_hash'];
			$contents = $this->file_cache->get_cached( $url_hash, true );

			if ( str_contains( $contents, '<section id="captcha">' )
			|| str_contains( $contents, 'Please complete the security check to access NetworkSolutions.com' )
			|| '' === $contents
			) {

				// Attempt to get the content again.
				$content = $this->get_content( Internet_Archive::get_latest_snapshot( $page_url['page_url'] ) );

				// If this is not the same as we have, update the cache.
				if ( $content !== $contents && '' !== $content ) {
					$this->file_cache->set_cache( $url_hash, $content, true );
					\WP_CLI::line( 'Updated invalid cache for URL: ' . $page_url['page_url'] . '(' . $page_url['url_hash'] . ')' );
				} elseif ( str_contains( $contents, '<section id="captcha">' )
				|| str_contains( $contents, 'Please complete the security check to access NetworkSolutions.com' ) ) {
					\WP_CLI::line( '!!!!!!!!! Content is still invalid for URL: ' . $page_url['page_url'] );
				} else {
					\WP_CLI::line( '!!!!!!!!! Content has not changed for URL: ' . $page_url['page_url'] );
				}
			}
		}
	}

	/**
	 * Extract links from the given content.
	 *
	 * @param string $content The content to extract links from.
	 *
	 * @return array The extracted links.
	 */
	public function extract_links( string $content ): array {
		$links = array();
		$dom   = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $content );
		libxml_clear_errors();
		$anchor_tags = $dom->getElementsByTagName( 'a' );
		foreach ( $anchor_tags as $tag ) {
			$href = $tag->getAttribute( 'href' );
			if ( ! empty( $href ) && ! in_array( $href, $links, true ) ) {
				// If the link doesnt contain 'headrush.typepad.com/creating_passionate_users', skip it.
				if ( ! \str_contains( $href, 'headrush.typepad.com/creating_passionate_users' ) ) {
					continue;
				}

				// If link ends in #comments or #trackback, skip it.
				if ( str_ends_with( $href, '#comments' ) || str_ends_with( $href, '#trackback' ) ) {
					continue;
				}

				$links[] = $href;
			}
		}
		return $links;
	}

	/**
	 * Extract media URLs from the given content.
	 *
	 * @param string $content The content to extract media URLs from.
	 *
	 * @return array The extracted media URLs.
	 */
	public function extract_media_urls( string $content ): array {
		$media_urls = array();
		$dom        = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $content );
		libxml_clear_errors();
		$img_tags = $dom->getElementsByTagName( 'img' );
		foreach ( $img_tags as $tag ) {
			$src = $tag->getAttribute( 'src' );
			if ( ! empty( $src ) && ! in_array( $src, $media_urls, true ) ) {
				$media_urls[] = $src;
			}
		}
		return $media_urls;
	}



	/**
	 * Get the content from the given URL.
	 *
	 * @param string $url The URL to fetch content from.
	 *
	 * @return string The content of the URL.
	 */
	public function get_content( string $url ): string {
		// Ensure a long timeout for fetching content.
		$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Store the scraped URLs and media URLs into the database.
	 *
	 * @return void
	 */
	public function store_scraped_data(): void {
		// Store page URL hashes.
		foreach ( $this->scraped_urls as $page_url ) {
			$url_hash = md5( $page_url );
			// Insert into database.
			$insert_sql = 'INSERT OR IGNORE INTO page_url_hashes (page_url, url_hash, post_id) VALUES (:page_url, :url_hash, :post_id)';
			$this->database->query(
				$insert_sql,
				array(
					':page_url' => $page_url,
					':url_hash' => $url_hash,
					':post_id'  => '0', // Placeholder, to be updated later.
				)
			);
		}

		// Store media URL hashes.
		foreach ( $this->media_urls as $media_url ) {
			// Insert into database.
			$insert_sql = 'INSERT OR IGNORE INTO media_url_hashes (media_url, media_id) VALUES (:media_url, :media_id)';
			$this->database->query(
				$insert_sql,
				array(
					':media_url' => $media_url,
					':media_id'  => 0,
				)
			);
		}
	}
}
