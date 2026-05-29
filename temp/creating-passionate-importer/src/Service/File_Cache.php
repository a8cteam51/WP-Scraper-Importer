<?php

/**
 * File Cache Class
 *
 * Handles caching of files for the Wayback Machine to WordPress Scraper plugin.
 */

namespace A8C\SpecialProjects\ScrapperToWP\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class File_Cache
 */
class File_Cache {
	/**
	 * Location of the cache directory.
	 *
	 * @var string
	 */
	private $cache_dir;

	/**
	 * constructor.
	 *
	 * @param string $cache_dir The directory to store cached files.
	 */
	public function __construct( $cache_dir ) {
		$this->cache_dir = trailingslashit( $cache_dir );
		if ( ! file_exists( $this->cache_dir ) ) {
			mkdir( $this->cache_dir, 0755, true );
		}
	}

	/**
	 * Generate the hash for a given cache key.
	 *
	 * @param string $key The cache key (filename).
	 * @return string The generated hash.
	 */
	private function generate_hash( $key ) {
		return md5( $key );
	}

	/**
	 * Checks if a given file is cached.
	 *
	 * @param string $key The cache key (filename).
	 * @param boolean $is_hash Whether the key is already a hash (default: false).
	 *
	 * @return boolean True if cached, false otherwise.
	 */
	public function is_cached( $key, $is_hash = false ) {
		$file_path = $this->cache_dir . ( $is_hash ? $key : $this->generate_hash( $key ) );
		return file_exists( $file_path );
	}

	/**
	 * Retrieve a cached file's contents.
	 *
	 * @param string $key The cache key (filename).
	 * @param boolean $is_hash Whether the key is already a hash (default: false).
	 *
	 * @return string|false The file contents or false if not found.
	 */
	public function get_cached( $key, $is_hash = false ) {
		$file_path = $this->cache_dir . ( $is_hash
			? $key
			: $this->generate_hash( $key ) );
		if ( file_exists( $file_path ) ) {
			$contents = file_get_contents( $file_path );

			// If empty or false, return false.
			if ( false === $contents || '' === $contents ) {
				return false;
			}
			return $contents;
		}
		return false;
	}


	/**
	 * Store contents in the cache.
	 *
	 * @param string $key     The cache key (filename).
	 * @param string $content The content to cache.
	 * @param boolean $is_hash Whether the key is already a hash (default: false).
	 *
	 * @return void
	 */
	public function set_cache( $key, $content, $is_hash = false ) {
		$file_path = $this->cache_dir . ( $is_hash ? $key : $this->generate_hash( $key ) );
		file_put_contents( $file_path, $content );
	}


}
