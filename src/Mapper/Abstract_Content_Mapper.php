<?php
/**
 * Abstract base class for content mapping and compilation.
 *
 * This class defines the interface for mapping scraped content to WordPress post data.
 * Extend this class to implement custom content mapping logic.
 *
 * @package     A8CSP_Scrapper_to_WP
 * @subpackage  Mapper
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP\Mapper;

use A8C\SpecialProjects\ScrapperToWP\Action\Content_Scrapper;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for content mapping.
 *
 * This class provides the interface that all content mappers must implement.
 * It allows for extensible content processing and mapping from scraped data.
 */
abstract class Abstract_Content_Mapper {

	/**
	 * The content scrapper instance.
	 *
	 * @var Content_Scrapper
	 */
	protected $content_scrapper;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Content_Scrapper $content_scrapper The content scrapper instance.
	 */
	public function __construct( Content_Scrapper $content_scrapper ) {
		$this->content_scrapper = $content_scrapper;
	}

	/**
	 * Extract the page title from the scraped content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The extracted page title.
	 */
	abstract public function get_title(): string;

	/**
	 * Extract the main content from the scraped content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The extracted page content.
	 */
	abstract public function get_content(): string;

	/**
	 * Extract the author information from the scraped content.
	 *
	 * @since 1.0.0
	 *
	 * @return int The author user ID.
	 */
	abstract public function get_author(): int;

	/**
	 * Extract terms (categories, tags, etc.) from the scraped content.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<int, string>> An array of terms grouped by taxonomy.
	 */
	abstract public function get_terms(): array;

	/**
	 * Map/transform content child nodes.
	 *
	 * This method allows for custom processing of individual HTML nodes
	 * during content extraction. Override this method to implement
	 * custom content transformations.
	 *
	 * @since 1.0.0
	 *
	 * @param string $nodes_html The HTML of the nodes.
	 *
	 * @return string The transformed HTML.
	 */
	abstract public function map_content_child_node( string $nodes_html ): string;

	/**
	 * Compile all the content data into a structured array.
	 *
	 * This is a convenience method that gathers all the mapped content
	 * into a single array for easy consumption.
	 *
	 * @since 1.0.0
	 *
	 * @return array{
	 *     title: string,
	 *     content: string,
	 *     author: int,
	 *     terms: array<string, array<int, string>>
	 * } The compiled content data.
	 */
	public function compile_content(): array {
		return array(
			'title'   => $this->get_title(),
			'content' => $this->get_content(),
			'author'  => $this->get_author(),
			'terms'   => $this->get_terms(),
		);
	}

	/**
	 * Get the post status for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The post status (e.g., 'publish', 'draft', 'pending').
	 */
	abstract public function get_post_status(): string;

	/**
	 * Get the post type for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The post type (e.g., 'post', 'page', 'custom_post_type').
	 */
	abstract public function get_post_type(): string;

	/**
	 * Get the parent post ID for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return int The parent post ID (0 for no parent).
	 */
	abstract public function get_post_parent(): int;

	/**
	 * Get the featured image URL for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The featured image URL (empty string if none).
	 */
	abstract public function get_featured_image(): string;

	/**
	 * Get additional meta data for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Array of meta key-value pairs.
	 */
	abstract public function get_post_meta(): array;

	/**
	 * Compile all the post configuration data into a structured array.
	 *
	 * This method gathers all the post configuration settings
	 * into a single array for easy consumption.
	 *
	 * @since 1.0.0
	 *
	 * @return array{
	 *     post_status: string,
	 *     post_type: string,
	 *     post_parent: int,
	 *     featured_image: string,
	 *     meta: array<string, mixed>
	 * } The compiled post configuration data.
	 */
	public function compile_post_config(): array {
		return array(
			'post_status'    => $this->get_post_status(),
			'post_type'      => $this->get_post_type(),
			'post_parent'    => $this->get_post_parent(),
			'featured_image' => $this->get_featured_image(),
			'meta'           => $this->get_post_meta(),
		);
	}

	/**
	 * Compile everything into a complete post data array.
	 *
	 * This method combines both content and configuration data
	 * into a single comprehensive array.
	 *
	 * @since 1.0.0
	 *
	 * @return array{
	 *     title: string,
	 *     content: string,
	 *     author: int,
	 *     terms: array<string, array<int, string>>,
	 *     post_status: string,
	 *     post_type: string,
	 *     post_parent: int,
	 *     featured_image: string,
	 *     meta: array<string, mixed>
	 * } The complete compiled post data.
	 */
	public function compile_all(): array {
		return array_merge(
			$this->compile_content(),
			$this->compile_post_config()
		);
	}
}
