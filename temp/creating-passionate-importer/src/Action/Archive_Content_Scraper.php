<?php

/**
 * Custom content scraper this is used to work with the Archived and cached content already connected.
 *
 * @package     A8CSP_Scrapper_to_WP
 */
declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP\Action;

defined( 'ABSPATH' ) || exit;

/**
 * Archive content scraper class.
 */
class Archive_Content_Scraper extends Content_Scrapper {

	/**
	 * File Cache instance.
	 *
	 * @var \A8C\SpecialProjects\ScrapperToWP\Service\File_Cache
	 */
	protected $file_cache;

	/**
	 * Access to the URL data.
	 *
	 * @var array{id:int, page_url:string, url_hash:string, post_id:int|null}
	 */
	protected $url_data;

	/**
	 * Constructor.
	 *
	 * @param array{id:int, page_url:string, url_hash:string, post_id:int|null} $url_data The URL data to scrape.
	 */
	public function __construct( array $url_data ) {
		$this->url_data = $url_data;
		$this->url      = $url_data['page_url'];
		$this->file_cache = $this->set_file_cache();
	}

	/**
	 * Get the URL data.
	 *
	 * @return array{id:int, page_url:string, url_hash:string, post_id:int|null}
	 */
	public function get_url_data(): array {
		return $this->url_data;
	}

	/**
	 * Access to the cache.
	 *
	 * @return File_Cache
	 */
	protected function set_file_cache(): \A8C\SpecialProjects\ScrapperToWP\Service\File_Cache {
		// Get the base path.
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . 'wbm-import/';
		$cache_dir  = $base . 'cache/';

		return new \A8C\SpecialProjects\ScrapperToWP\Service\File_Cache( $cache_dir );
	}

		/**
	 * Process the URL and scrape the content.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the URL is empty.
	 */
	public function process(): void {
		// If we dont have a URL, we can't scrape.
		if ( '' === $this->url ) {
			throw new \InvalidArgumentException( 'URL cannot be empty.' );
		}

		// Get the remove content.
		try {
			$response = $this->file_cache->get_cached( $this->url_data['url_hash'], true )
				? $this->file_cache->get_cached( $this->url_data['url_hash'], true )
				: null;
		} catch ( \Throwable $th ) {
			$this->errors[] = $th->getMessage();
			$response       = null;
		} finally {
			$this->scraped = true;
		}


		// If we have a response, set the content and header.
		if ( null !== $response ) {
			$this->header    = [];
			$this->content   = $response;
			$this->final_url = $this->url_data['page_url'];
		} else {
			$this->errors[] = 'Failed to retrieve content from the URL.';
		}
	}



}
