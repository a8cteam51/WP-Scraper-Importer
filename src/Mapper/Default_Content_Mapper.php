<?php
/**
 * Default content mapper implementation.
 *
 * This class provides a default implementation for mapping scraped content to WordPress posts.
 * It can be extended to customize the content mapping behavior.
 *
 * ========================================
 * CUSTOMIZATION GUIDE
 * ========================================
 *
 * This class is designed to be EXTENDED and CUSTOMIZED for your specific scraping needs.
 * Each method below contains examples and guidance on how to modify it.
 *
 * COMMON CUSTOMIZATION SCENARIOS:
 * - Different HTML structure on target sites
 * - Extracting metadata from specific tags
 * - Custom post types and taxonomies
 * - Dynamic author assignment
 * - Featured image extraction from content
 * - Custom meta field population
 *
 * TO EXTEND THIS CLASS:
 * 1. Create a new class that extends Default_Content_Mapper
 * 2. Override only the methods you need to customize
 * 3. Use $this->content_scraper->get_content() to access the raw HTML
 * 4. Use WordPress functions like wp_strip_all_tags(), wp_trim_words(), etc.
 *
 * @package     A8CSP_Scraper_to_WP
 * @subpackage  Mapper
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScraperToWP\Mapper;

use A8C\SpecialProjects\ScraperToWP\Action\Content_Scraper;

defined( 'ABSPATH' ) || exit;

/**
 * Default content mapper implementation.
 *
 * This class implements the default behavior for extracting content
 * and configuring posts from scraped data.
 */
class Default_Content_Mapper extends Abstract_Content_Mapper {

	/**
	 * Extract the page title from the scraped content.
	 *
	 * ========================================
	 * CUSTOMIZATION EXAMPLES:
	 * ========================================
	 *
	 * EXAMPLE 1: Extract from H1 tag instead of <title>
	 * $processor = new \WP_HTML_Tag_Processor( $this->content_scraper->get_content() );
	 * if ( $processor->next_tag( 'h1' ) ) {
	 *     return $processor->get_modifiable_text();
	 * }
	 *
	 * EXAMPLE 2: Extract from meta property
	 * $dom = new \DOMDocument();
	 * $dom->loadHTML( $this->content_scraper->get_content() );
	 * $xpath = new \DOMXPath( $dom );
	 * $title_node = $xpath->query('//meta[@property="og:title"]/@content');
	 * if ( $title_node->length ) {
	 *     return $title_node->item(0)->nodeValue;
	 * }
	 *
	 * EXAMPLE 3: Clean up title (remove site name, etc.)
	 * $title = $processor->get_modifiable_text();
	 * $title = str_replace( ' - MySite.com', '', $title );
	 * return trim( $title );
	 *
	 * EXAMPLE 4: Extract from JSON-LD structured data
	 * if ( preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches) ) {
	 *     $json_data = json_decode( $matches[1], true );
	 *     if ( isset( $json_data['headline'] ) ) {
	 *         return $json_data['headline'];
	 *     }
	 * }
	 *
	 * @since 1.0.0
	 *
	 * @return string The extracted page title.
	 */
	public function get_title(): string {
		// If the content scraper has a title, return it.
		if ( $this->content_scraper->is_scraped() ) {
			$processor = new \WP_HTML_Tag_Processor( $this->content_scraper->get_content() );
			if ( $processor->next_tag( array( 'tag_name' => 'title' ) ) ) {
				$processor->get_tag();
				return $processor->get_modifiable_text();
			}
		}

		// Otherwise, return a default title.
		return 'Imported Content';
	}

	/**
	 * Extract the main content from the scraped content.
	 *
	 * ========================================
	 * CUSTOMIZATION EXAMPLES:
	 * ========================================
	 *
	 * EXAMPLE 1: Different content containers
	 * Change 'main' to: 'article', 'div', '.content', '#main-content', '.post-body'
	 * $body = $dom->getElementsByTagName( 'article' )->item( 0 );
	 *
	 * EXAMPLE 2: Multiple possible containers (fallback)
	 * $selectors = ['main', 'article', '.content', '#post-content'];
	 * foreach ( $selectors as $selector ) {
	 *     $body = $dom->getElementsByTagName( $selector )->item( 0 );
	 *     if ( $body ) break;
	 * }
	 *
	 * EXAMPLE 3: Using CSS selectors with DOMXPath
	 * $xpath = new \DOMXPath( $dom );
	 * $body = $xpath->query('//div[@class="post-content"]')->item( 0 );
	 *
	 * EXAMPLE 4: Remove unwanted elements before processing
	 * $unwanted = $dom->getElementsByTagName( 'script' );
	 * while ( $unwanted->length ) {
	 *     $unwanted->item(0)->parentNode->removeChild( $unwanted->item(0) );
	 * }
	 *
	 * EXAMPLE 5: Extract specific sections only
	 * $paragraphs = $body->getElementsByTagName( 'p' );
	 * $content = '';
	 * foreach ( $paragraphs as $p ) {
	 *     $content .= $dom->saveHTML( $p );
	 * }
	 *
	 * EXAMPLE 6: Convert relative URLs to absolute
	 * $content = preg_replace('/src="\//', 'src="https://example.com/', $content);
	 * $content = preg_replace('/href="\//', 'href="https://example.com/', $content);
	 *
	 * @since 1.0.0
	 *
	 * @return string The extracted page content.
	 */
	public function get_content(): string {
		// If the content scraper has content, extract the body content.
		if ( $this->content_scraper->is_scraped() ) {
			libxml_use_internal_errors( true );
			$dom = new \DOMDocument();
			$dom->loadHTML( $this->content_scraper->get_content(), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

			// Extract the content from the body tag.
			$body = $dom->getElementsByTagName( 'main' )->item( 0 ); // <--- THIS IS WHERE YOU CAN CHANGE THE TAG NAME TO EXTRACT THE CONTENT FROM.
			if ( null === $body ) {
				return '';
			}

			// Iterate through the body children and concatenate their HTML.
			$body_content = '';
			foreach ( $body->childNodes as $child ) { // phpcs:ignore
				// Pass through the content child node mapping function.
				// This function can be overridden to modify the content.
				$child_html = $dom->saveHTML( $child );
				if ( false !== $child_html ) {
					$body_content .= $this->map_content_child_node( $child_html );
				}
			}

			return trim( $body_content );
		}

		// Otherwise, return an empty string.
		return '';
	}

	/**
	 * Extract the author information from the scraped content.
	 *
	 * ========================================
	 * CUSTOMIZATION EXAMPLES:
	 * ========================================
	 *
	 * EXAMPLE 1: Extract from meta tags
	 * if ( preg_match('/<meta name="author" content="([^"]+)"/i', $content, $matches) ) {
	 *     $author_name = $matches[1];
	 *     $user = get_user_by( 'display_name', $author_name );
	 *     return $user ? $user->ID : 1;
	 * }
	 *
	 * EXAMPLE 2: Extract from specific HTML element
	 * $dom = new \DOMDocument();
	 * $dom->loadHTML( $content );
	 * $xpath = new \DOMXPath( $dom );
	 * $author_node = $xpath->query('//span[@class="author-name"]')->item( 0 );
	 * if ( $author_node ) {
	 *     $author_name = $author_node->textContent;
	 *     // Look up WordPress user by name
	 * }
	 *
	 * EXAMPLE 3: Map based on URL domain
	 * $url = $this->content_scraper->get_url();
	 * $domain = parse_url( $url, PHP_URL_HOST );
	 * $author_mapping = [
	 *     'blog1.com' => 2,
	 *     'blog2.com' => 3,
	 *     'default' => 1
	 * ];
	 * return $author_mapping[$domain] ?? $author_mapping['default'];
	 *
	 * EXAMPLE 4: Extract from JSON-LD
	 * if ( preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches) ) {
	 *     $json_data = json_decode( $matches[1], true );
	 *     if ( isset( $json_data['author']['name'] ) ) {
	 *         $author_name = $json_data['author']['name'];
	 *         // Look up or create WordPress user
	 *     }
	 * }
	 *
	 * EXAMPLE 5: Create new user if not exists
	 * if ( ! empty( $author_name ) && ! username_exists( $author_name ) ) {
	 *     $user_id = wp_create_user( $author_name, wp_generate_password(), $author_name . '@example.com' );
	 *     return is_wp_error( $user_id ) ? 1 : $user_id;
	 * }
	 *
	 * @since 1.0.0
	 *
	 * @return int The author user ID.
	 */
	public function get_author(): int {
		// ============================================
		// ADD YOUR OWN AUTHOR EXTRACTION LOGIC HERE
		// ============================================
		$content = $this->content_scraper->get_content();

		// PLACEHOLDER: Always returns user ID 1 (admin)
		// CUSTOMIZE THIS: Extract author info from the scraped content
		return 1; // Placeholder, implement your logic to extract the author.
	}

	/**
	 * Extract terms (categories, tags, etc.) from the scraped content.
	 *
	 * ========================================
	 * CUSTOMIZATION EXAMPLES:
	 * ========================================
	 *
	 * EXAMPLE 1: Extract from meta keywords
	 * if ( preg_match('/<meta name="keywords" content="([^"]+)"/i', $content, $matches) ) {
	 *     $keywords = explode( ',', $matches[1] );
	 *     return [
	 *         'post_tag' => array_map( 'trim', $keywords ),
	 *         'category' => []
	 *     ];
	 * }
	 *
	 * EXAMPLE 2: Extract from specific HTML elements
	 * $dom = new \DOMDocument();
	 * $dom->loadHTML( $content );
	 * $xpath = new \DOMXPath( $dom );
	 *
	 * // Get categories
	 * $cat_nodes = $xpath->query('//div[@class="categories"]//a');
	 * $categories = [];
	 * foreach ( $cat_nodes as $node ) {
	 *     $categories[] = trim( $node->textContent );
	 * }
	 *
	 * // Get tags
	 * $tag_nodes = $xpath->query('//div[@class="tags"]//a');
	 * $tags = [];
	 * foreach ( $tag_nodes as $node ) {
	 *     $tags[] = trim( $node->textContent );
	 * }
	 *
	 * return [
	 *     'category' => $categories,
	 *     'post_tag' => $tags
	 * ];
	 *
	 * EXAMPLE 3: Auto-categorize based on content keywords
	 * $content_text = wp_strip_all_tags( $content );
	 * $categories = [];
	 *
	 * if ( stripos( $content_text, 'tutorial' ) !== false ) {
	 *     $categories[] = 'Tutorials';
	 * }
	 * if ( stripos( $content_text, 'news' ) !== false ) {
	 *     $categories[] = 'News';
	 * }
	 *
	 * EXAMPLE 4: Map based on URL structure
	 * $url = $this->content_scraper->get_url();
	 * if ( strpos( $url, '/technology/' ) !== false ) {
	 *     return [ 'category' => ['Technology'], 'post_tag' => [] ];
	 * }
	 *
	 * EXAMPLE 5: Extract from JSON-LD structured data
	 * if ( preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches) ) {
	 *     $json_data = json_decode( $matches[1], true );
	 *     if ( isset( $json_data['keywords'] ) ) {
	 *         $keywords = is_array( $json_data['keywords'] )
	 *             ? $json_data['keywords']
	 *             : explode( ',', $json_data['keywords'] );
	 *         return [ 'post_tag' => $keywords, 'category' => [] ];
	 *     }
	 * }
	 *
	 * CUSTOM TAXONOMIES:
	 * For custom post types, you might want to return custom taxonomies:
	 * return [
	 *     'product_category' => ['Electronics'],
	 *     'product_tag' => ['smartphone', 'android'],
	 *     'brand' => ['Samsung']
	 * ];
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<int, string>> An array of terms grouped by taxonomy.
	 */
	public function get_terms(): array {
		// ============================================
		// ADD YOUR OWN TERMS EXTRACTION LOGIC HERE
		// ============================================
		$content = $this->content_scraper->get_content();

		// PLACEHOLDER: Returns empty arrays
		// CUSTOMIZE THIS: Extract categories, tags, or custom taxonomy terms
		return array(
			'tag'      => array(), // WordPress tags
			'category' => array(), // WordPress categories
			// Add custom taxonomies as needed:
			// 'custom_taxonomy' => array(),
		);
	}

	/**
	 * Map/transform content child nodes.
	 *
	 * ========================================
	 * CUSTOMIZATION EXAMPLES:
	 * ========================================
	 *
	 * EXAMPLE 1: Remove unwanted elements
	 * $nodes_html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $nodes_html);
	 * $nodes_html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $nodes_html);
	 *
	 * EXAMPLE 2: Convert relative URLs to absolute
	 * $base_url = parse_url( $this->content_scraper->get_url(), PHP_URL_SCHEME ) . '://' .
	 *             parse_url( $this->content_scraper->get_url(), PHP_URL_HOST );
	 * $nodes_html = preg_replace('/src="\//', 'src="' . $base_url . '/', $nodes_html);
	 * $nodes_html = preg_replace('/href="\//', 'href="' . $base_url . '/', $nodes_html);
	 *
	 * EXAMPLE 3: Add WordPress-specific classes
	 * $nodes_html = str_replace('<img ', '<img class="wp-image-imported" ', $nodes_html);
	 * $nodes_html = str_replace('<blockquote>', '<blockquote class="wp-block-quote">', $nodes_html);
	 *
	 * EXAMPLE 4: Convert to WordPress blocks
	 * if ( strpos( $nodes_html, '<img' ) !== false ) {
	 *     $nodes_html = preg_replace(
	 *         '/<img([^>]+)>/i',
	 *         '<!-- wp:image --><figure class="wp-block-image"><img$1/></figure><!-- /wp:image -->',
	 *         $nodes_html
	 *     );
	 * }
	 *
	 * EXAMPLE 5: Remove specific unwanted elements
	 * $unwanted_patterns = [
	 *     '/<div class="advertisement">.*?<\/div>/s',
	 *     '/<aside class="sidebar">.*?<\/aside>/s',
	 *     '/<div class="social-share">.*?<\/div>/s'
	 * ];
	 * foreach ( $unwanted_patterns as $pattern ) {
	 *     $nodes_html = preg_replace( $pattern, '', $nodes_html );
	 * }
	 *
	 * EXAMPLE 6: Download and replace external images
	 * if ( preg_match_all('/src="(https?:\/\/[^"]+)"/', $nodes_html, $matches) ) {
	 *     foreach ( $matches[1] as $img_url ) {
	 *         $attachment_id = media_sideload_image( $img_url, 0, '', 'id' );
	 *         if ( ! is_wp_error( $attachment_id ) ) {
	 *             $new_url = wp_get_attachment_url( $attachment_id );
	 *             $nodes_html = str_replace( $img_url, $new_url, $nodes_html );
	 *         }
	 *     }
	 * }
	 *
	 * @since 1.0.0
	 *
	 * @param string $nodes_html The HTML of the nodes.
	 *
	 * @return string The transformed HTML.
	 */
	public function map_content_child_node( string $nodes_html ): string {
		// ============================================
		// ADD YOUR OWN CONTENT TRANSFORMATION HERE
		// ============================================

		// PLACEHOLDER: Returns the original HTML unchanged
		// CUSTOMIZE THIS: Transform, clean, or modify the HTML content
		return $nodes_html; // Placeholder, return the original HTML.
	}

	/**
	 * Get the post status for the imported content.
	 *
	 * ========================================
	 * CUSTOMIZATION EXAMPLES:
	 * ========================================
	 *
	 * EXAMPLE 1: Different status based on content quality
	 * $content = $this->content_scraper->get_content();
	 * $word_count = str_word_count( wp_strip_all_tags( $content ) );
	 * return $word_count > 300 ? 'publish' : 'draft';
	 *
	 * EXAMPLE 2: Status based on URL pattern
	 * $url = $this->content_scraper->get_url();
	 * if ( strpos( $url, '/draft/' ) !== false ) {
	 *     return 'draft';
	 * }
	 * return 'publish';
	 *
	 * EXAMPLE 3: Pending review for external content
	 * return 'pending';
	 *
	 * AVAILABLE STATUSES:
	 * - 'publish' (published and visible)
	 * - 'draft' (saved but not published)
	 * - 'pending' (waiting for review)
	 * - 'private' (visible only to users with edit_posts capability)
	 * - 'trash' (in trash)
	 *
	 * @since 1.0.0
	 *
	 * @return string The post status (e.g., 'publish', 'draft', 'pending').
	 */
	public function get_post_status(): string {
		// ============================================
		// CUSTOMIZE POST STATUS LOGIC HERE
		// ============================================

		return 'publish'; // CUSTOMIZE THIS: Set the post status based on your requirements.
	}

	/**
	 * Get the post type for the imported content.
	 *
	 * ========================================
	 * CUSTOMIZATION EXAMPLES:
	 * ========================================
	 *
	 * EXAMPLE 1: Different post types based on URL structure
	 * $url = $this->content_scraper->get_url();
	 * if ( strpos( $url, '/products/' ) !== false ) {
	 *     return 'product';
	 * } elseif ( strpos( $url, '/events/' ) !== false ) {
	 *     return 'event';
	 * }
	 * return 'post';
	 *
	 * EXAMPLE 2: Post type based on content indicators
	 * $content = $this->content_scraper->get_content();
	 * if ( strpos( $content, 'class="recipe"' ) !== false ) {
	 *     return 'recipe';
	 * }
	 *
	 * EXAMPLE 3: Static custom post type
	 * return 'imported_article';
	 *
	 * COMMON POST TYPES:
	 * - 'post' (blog posts)
	 * - 'page' (static pages)
	 * - 'product' (WooCommerce)
	 * - 'event' (Events)
	 * - Custom post types you've registered
	 *
	 * @since 1.0.0
	 *
	 * @return string The post type (e.g., 'post', 'page', 'custom_post_type').
	 */
	public function get_post_type(): string {
		// ============================================
		// CUSTOMIZE POST TYPE LOGIC HERE
		// ============================================

		return 'post'; // CUSTOMIZE THIS: Set the post type based on content or URL structure.
	}

	/**
	 * Get the parent post ID for the imported content.
	 *
	 * ========================================
	 * CUSTOMIZATION EXAMPLES:
	 * ========================================
	 *
	 * EXAMPLE 1: Set parent based on URL structure
	 * $url = $this->content_scraper->get_url();
	 * if ( strpos( $url, '/documentation/' ) !== false ) {
	 *     $parent = get_page_by_path( 'documentation' );
	 *     return $parent ? $parent->ID : 0;
	 * }
	 *
	 * EXAMPLE 2: Create hierarchy based on categories
	 * $url_parts = explode( '/', trim( parse_url( $url, PHP_URL_PATH ), '/' ) );
	 * if ( count( $url_parts ) > 1 ) {
	 *     $parent_slug = $url_parts[0];
	 *     $parent = get_page_by_path( $parent_slug );
	 *     return $parent ? $parent->ID : 0;
	 * }
	 *
	 * EXAMPLE 3: Fixed parent for all imported content
	 * return 123; // Replace with actual parent post ID
	 *
	 * EXAMPLE 4: Dynamic parent creation
	 * $parent_title = 'Imported Content';
	 * $parent = get_page_by_title( $parent_title, OBJECT, 'page' );
	 * if ( ! $parent ) {
	 *     $parent_id = wp_insert_post([
	 *         'post_title' => $parent_title,
	 *         'post_type' => 'page',
	 *         'post_status' => 'publish'
	 *     ]);
	 *     return is_wp_error( $parent_id ) ? 0 : $parent_id;
	 * }
	 * return $parent->ID;
	 *
	 * @since 1.0.0
	 *
	 * @return int The parent post ID (0 for no parent).
	 */
	public function get_post_parent(): int {
		// ============================================
		// CUSTOMIZE PARENT POST LOGIC HERE
		// ============================================

		return 0; // CUSTOMIZE THIS: Set parent post ID if you want hierarchical structure.
	}

	/**
	 * Get the featured image URL for the imported content.
	 *
	 * ========================================
	 * CUSTOMIZATION EXAMPLES:
	 * ========================================
	 *
	 * EXAMPLE 1: Extract from meta tags (Open Graph)
	 * $content = $this->content_scraper->get_content();
	 * if ( preg_match('/<meta property="og:image" content="([^"]+)"/i', $content, $matches) ) {
	 *     return $matches[1];
	 * }
	 *
	 * EXAMPLE 2: Extract from Twitter Card meta
	 * if ( preg_match('/<meta name="twitter:image" content="([^"]+)"/i', $content, $matches) ) {
	 *     return $matches[1];
	 * }
	 *
	 * EXAMPLE 3: Find first image in content
	 * $dom = new \DOMDocument();
	 * $dom->loadHTML( $content );
	 * $images = $dom->getElementsByTagName( 'img' );
	 * if ( $images->length > 0 ) {
	 *     $first_img = $images->item( 0 );
	 *     $src = $first_img->getAttribute( 'src' );
	 *     if ( ! empty( $src ) ) {
	 *         // Convert relative URL to absolute if needed
	 *         if ( strpos( $src, 'http' ) !== 0 ) {
	 *             $base_url = parse_url( $this->content_scraper->get_url(), PHP_URL_SCHEME ) . '://' .
	 *                        parse_url( $this->content_scraper->get_url(), PHP_URL_HOST );
	 *             $src = $base_url . $src;
	 *         }
	 *         return $src;
	 *     }
	 * }
	 *
	 * EXAMPLE 4: Static featured image for all imports
	 * return 'https://yoursite.com/default-featured-image.jpg';
	 *
	 * EXAMPLE 5: Featured image based on category
	 * $terms = $this->get_terms();
	 * if ( in_array( 'Technology', $terms['category'] ) ) {
	 *     return 'https://yoursite.com/tech-featured.jpg';
	 * }
	 *
	 * EXAMPLE 6: Extract from JSON-LD
	 * if ( preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches) ) {
	 *     $json_data = json_decode( $matches[1], true );
	 *     if ( isset( $json_data['image'] ) ) {
	 *         return is_array( $json_data['image'] ) ? $json_data['image'][0] : $json_data['image'];
	 *     }
	 * }
	 *
	 * @since 1.0.0
	 *
	 * @return string The featured image URL (empty string if none).
	 */
	public function get_featured_image(): string {
		// ============================================
		// CUSTOMIZE FEATURED IMAGE EXTRACTION HERE
		// ============================================

		return ''; // CUSTOMIZE THIS: Extract featured image URL from content or return static URL.
	}

	/**
	 * Get additional meta data for the imported content.
	 *
	 * ========================================
	 * CUSTOMIZATION EXAMPLES:
	 * ========================================
	 *
	 * EXAMPLE 1: Store original URL and import date
	 * return [
	 *     'original_url' => $this->content_scraper->get_url(),
	 *     'import_date' => current_time( 'mysql' ),
	 *     'import_source' => 'content_scraper'
	 * ];
	 *
	 * EXAMPLE 2: Extract custom fields from content
	 * $content = $this->content_scraper->get_content();
	 * $meta = [];
	 *
	 * // Extract price from content
	 * if ( preg_match('/\$([0-9,]+\.\d{2})/', $content, $matches) ) {
	 *     $meta['price'] = $matches[1];
	 * }
	 *
	 * // Extract rating
	 * if ( preg_match('/rating["\s:]+([0-9.]+)/', $content, $matches) ) {
	 *     $meta['rating'] = $matches[1];
	 * }
	 *
	 * return $meta;
	 *
	 * EXAMPLE 3: Extract from meta tags
	 * $meta = [];
	 * if ( preg_match('/<meta name="description" content="([^"]+)"/i', $content, $matches) ) {
	 *     $meta['seo_description'] = $matches[1];
	 * }
	 *
	 * if ( preg_match('/<meta name="keywords" content="([^"]+)"/i', $content, $matches) ) {
	 *     $meta['seo_keywords'] = $matches[1];
	 * }
	 *
	 * EXAMPLE 4: WooCommerce product meta
	 * if ( $this->get_post_type() === 'product' ) {
	 *     return [
	 *         '_regular_price' => '99.99',
	 *         '_stock_status' => 'instock',
	 *         '_manage_stock' => 'yes',
	 *         '_stock' => '10'
	 *     ];
	 * }
	 *
	 * EXAMPLE 5: ACF (Advanced Custom Fields) data
	 * return [
	 *     'field_custom_field_1' => 'value1',
	 *     'field_custom_field_2' => 'value2',
	 *     'field_gallery' => ['image1.jpg', 'image2.jpg']
	 * ];
	 *
	 * EXAMPLE 6: Extract from JSON-LD structured data
	 * if ( preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches) ) {
	 *     $json_data = json_decode( $matches[1], true );
	 *     $meta = [];
	 *
	 *     if ( isset( $json_data['datePublished'] ) ) {
	 *         $meta['original_publish_date'] = $json_data['datePublished'];
	 *     }
	 *
	 *     if ( isset( $json_data['wordCount'] ) ) {
	 *         $meta['word_count'] = $json_data['wordCount'];
	 *     }
	 *
	 *     return $meta;
	 * }
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Array of meta key-value pairs.
	 */
	public function get_post_meta(): array {
		// ============================================
		// CUSTOMIZE POST META EXTRACTION HERE
		// ============================================

		// PLACEHOLDER: Returns empty array
		// CUSTOMIZE THIS: Extract custom fields, SEO data, or other metadata
		return array(); // Return any additional meta data.
	}

	/**
	 * Get the post date for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The post date (e.g., '2024-01-31 12:00:00') or empty string.
	 */
	public function get_post_date(): string {
		// ============================================
		// CUSTOMIZE POST DATE EXTRACTION HERE
		// ============================================

		// PLACEHOLDER: Returns empty string so WordPress uses the current time.
		// CUSTOMIZE THIS: Parse the original publication date from the scraped content.
		return '';
	}
}
