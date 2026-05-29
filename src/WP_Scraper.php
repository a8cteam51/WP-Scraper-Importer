<?php
/**
 * Central configuration facade for the scraper.
 *
 * A clone configures the importer in code (e.g. in its plugin bootstrap) by
 * calling the static setters on this class:
 *
 *     WP_Scraper::set_url_provider( My_Provider::class );
 *     WP_Scraper::set_default_user( 5 );
 *
 * Nothing is configured out of the box, so the URL provider resolves to the
 * Noop provider and the post defaults fall back to the values below.
 *
 * @package     A8CSP_Scrapper_to_WP
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP;

use InvalidArgumentException;
use A8C\SpecialProjects\ScrapperToWP\Provider\URL_Provider;
use A8C\SpecialProjects\ScrapperToWP\Provider\Noop_URL_Provider;
use A8C\SpecialProjects\ScrapperToWP\Action\Content_Scrapper;
use A8C\SpecialProjects\ScrapperToWP\Mapper\Abstract_Content_Mapper;
use A8C\SpecialProjects\ScrapperToWP\Mapper\Default_Content_Mapper;

defined( 'ABSPATH' ) || exit;

/**
 * Static settings store for the scraper.
 */
class WP_Scraper {

	/**
	 * The registered URL provider class name.
	 *
	 * @var class-string<URL_Provider>|null
	 */
	private static $url_provider;

	/**
	 * The default (fallback) author ID for imported posts.
	 *
	 * @var int
	 */
	private static $default_user = 0;

	/**
	 * The default post type for imported posts.
	 *
	 * @var string
	 */
	private static $post_type = 'post';

	/**
	 * The default post status for imported posts.
	 *
	 * @var string
	 */
	private static $post_status = 'publish';

	/**
	 * The number of URLs to process per batch.
	 *
	 * @var int
	 */
	private static $batch_size = 25;

	/**
	 * The registered content mapper class name.
	 *
	 * @var string|null
	 */
	private static $content_mapper;

	/**
	 * Register the URL provider by class name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider_class The provider class name (e.g. My_Provider::class).
	 *
	 * @return void
	 */
	public static function set_url_provider( string $provider_class ): void {
		self::$url_provider = $provider_class;
	}

	/**
	 * Resolve the configured URL provider.
	 *
	 * Returns a Noop provider when none has been registered.
	 *
	 * @since 1.0.0
	 *
	 * @return URL_Provider
	 *
	 * @throws InvalidArgumentException When the registered class does not implement URL_Provider.
	 */
	public static function get_url_provider(): URL_Provider {
		if ( null === self::$url_provider ) {
			return new Noop_URL_Provider();
		}

		$provider = new self::$url_provider();

		if ( ! $provider instanceof URL_Provider ) {
			throw new InvalidArgumentException(
				sprintf( 'The registered URL provider "%s" must implement URL_Provider.', esc_html( self::$url_provider ) )
			);
		}

		return $provider;
	}

	/**
	 * Set the default (fallback) author ID for imported posts.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return void
	 */
	public static function set_default_user( int $user_id ): void {
		self::$default_user = $user_id;
	}

	/**
	 * Get the default (fallback) author ID for imported posts.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function get_default_user(): int {
		return self::$default_user;
	}

	/**
	 * Set the default post type for imported posts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type The post type.
	 *
	 * @return void
	 */
	public static function set_post_type( string $post_type ): void {
		self::$post_type = $post_type;
	}

	/**
	 * Get the default post type for imported posts.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_post_type(): string {
		return self::$post_type;
	}

	/**
	 * Set the default post status for imported posts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_status The post status.
	 *
	 * @return void
	 */
	public static function set_post_status( string $post_status ): void {
		self::$post_status = $post_status;
	}

	/**
	 * Get the default post status for imported posts.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_post_status(): string {
		return self::$post_status;
	}

	/**
	 * Set the number of URLs to process per batch.
	 *
	 * @since 1.0.0
	 *
	 * @param int $batch_size The batch size.
	 *
	 * @return void
	 */
	public static function set_batch_size( int $batch_size ): void {
		self::$batch_size = $batch_size;
	}

	/**
	 * Get the number of URLs to process per batch.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function get_batch_size(): int {
		return self::$batch_size;
	}

	/**
	 * Register the content mapper by class name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mapper_class The content mapper class name (e.g. My_Content_Mapper::class).
	 *
	 * @return void
	 */
	public static function set_content_mapper( string $mapper_class ): void {
		self::$content_mapper = $mapper_class;
	}

	/**
	 * Resolve the configured content mapper for a scrapper.
	 *
	 * Returns the Default_Content_Mapper when none has been registered.
	 *
	 * @since 1.0.0
	 *
	 * @param Content_Scrapper $content_scrapper The content scrapper instance.
	 *
	 * @return Abstract_Content_Mapper
	 *
	 * @throws InvalidArgumentException When the registered class does not extend Abstract_Content_Mapper.
	 */
	public static function get_content_mapper( Content_Scrapper $content_scrapper ): Abstract_Content_Mapper {
		$mapper_class = self::$content_mapper ?? Default_Content_Mapper::class;

		$mapper = new $mapper_class( $content_scrapper );

		if ( ! $mapper instanceof Abstract_Content_Mapper ) {
			throw new InvalidArgumentException(
				sprintf( 'The registered content mapper "%s" must extend Abstract_Content_Mapper.', esc_html( $mapper_class ) )
			);
		}

		return $mapper;
	}
}
