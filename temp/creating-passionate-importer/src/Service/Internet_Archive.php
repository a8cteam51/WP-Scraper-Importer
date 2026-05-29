<?php

/**
 * Internet Archive Service Class
 *
 * Handles interactions with the Internet Archive for the Wayback Machine to WordPress Scraper plugin.
 */

namespace A8C\SpecialProjects\ScrapperToWP\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Internet_Archive {

	public static $link_cache = array();



	/**
	 * Fetches archived URLs from the Internet Archive for a given domain.
	 *
	 * @param string $domain The domain to fetch archived URLs for.
	 * @return string The archived URl (Latest)
	 */
	public static function get_latest_snapshot( string $domain ): string {

		// Check if we have this in the cache already
		if( isset( self::$link_cache[ md5( $domain ) ] ) ) {
			return self::$link_cache[ md5( $domain ) ];
		}

		$api_url = 'https://archive.org/wayback/available/?url=' .  $domain ;

		$response = wp_remote_get( $api_url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			\WP_CLI::line('Error fetching URL: ' . $domain . ' - ' . $response->get_error_message());
			return $domain;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return $domain;
		}

		// If we dont have an archived_snapshots or latest within it, return domain.
		if ( ! isset( $data['archived_snapshots'] ) || ! isset( $data['archived_snapshots']['closest'] ) ) {
			return $domain;
		}

		$url = $data['archived_snapshots']['closest']['url'];

		// Add this to the cache
		self::$link_cache[ md5($domain )] = $url;

		return $url;
	}

	/**
	 * Normalize a URL by removing the IA prefix and timestamp.
	 *
	 * @param string $url The URL to normalize.
	 *
	 * @return string The normalized URL.
	 */
	public static function normalize_url( string $url ): string {
		// Handle both HTTP and HTTPS Archive URLs
		$ia_patterns = array(
			'https://web.archive.org/web/',
			'http://web.archive.org/web/'
		);

		foreach ( $ia_patterns as $ia_prefix ) {
			if ( strpos( $url, $ia_prefix ) === 0 ) {
				// Remove the IA prefix
				$remaining = substr( $url, strlen( $ia_prefix ) );

				// The format is: timestamp/original_url
				// Find the first slash after the timestamp to get the original URL
				$slash_pos = strpos( $remaining, '/' );
				if ( $slash_pos !== false ) {
					return substr( $remaining, $slash_pos + 1 );
				}
			}
		}

		return $url;
	}
}
