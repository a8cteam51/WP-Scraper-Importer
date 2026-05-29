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
 * 3. Use $this->content_scrapper->get_content() to access the raw HTML
 * 4. Use WordPress functions like wp_strip_all_tags(), wp_trim_words(), etc.
 *
 * @package     A8CSP_Scrapper_to_WP
 * @subpackage  Mapper
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP\Mapper;

use A8C\SpecialProjects\ScrapperToWP\Action\Content_Scrapper;
use A8C\SpecialProjects\ScrapperToWP\Service\Internet_Archive;
use A8C\SpecialProjects\ScrapperToWP\Mapper\Abstract_Content_Mapper;


defined( 'ABSPATH' ) || exit;

/**
 * Default content mapper implementation.
 *
 * This class implements the default behavior for extracting content
 * and configuring posts from scraped data.
 */
class Default_Content_Mapper extends Abstract_Content_Mapper {

	/**
	 * The content scrapper instance.
	 *
	 * @var Archive_Content_Scraper
	 */
	protected $content_scrapper;

	/**
	 * Extract the page title from the scraped content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The extracted page title.
	 */
	public function get_title(): string {
		// If the content scrapper has a title, return it.
		if ( $this->content_scrapper->is_scraped() ) {
			libxml_use_internal_errors( true );
			$dom = new \DOMDocument();
			$dom->loadHTML( $this->content_scrapper->get_content(), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			libxml_clear_errors();

			// Use XPath to find the first heading (h1, h2, h3, h4) inside div class="content"
			$xpath   = new \DOMXPath( $dom );
			$heading = $xpath->query( '//div[@class="content"]//h1 | //div[@class="content"]//h2 | //div[@class="content"]//h3 | //div[@class="content"]//h4' )->item( 0 );

			if ( $heading !== null ) {
				$title = trim( $heading->textContent );
				if ( ! empty( $title ) ) {
					return wp_strip_all_tags( $title );
				}
			}
		}

		// Otherwise, return a default title.
		return 'Imported Content';
	}

	/**
	 * Extract the main content from the scraped content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The extracted page content.
	 */
	public function get_content(): string {
		// If the content scrapper has content, extract the body content.
		if ( $this->content_scrapper->is_scraped() ) {
			libxml_use_internal_errors( true );
			$dom = new \DOMDocument();
			$dom->loadHTML( $this->content_scrapper->get_content(), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

			// Extract the content from div.content using XPath.
			$xpath = new \DOMXPath( $dom );
			$body  = $xpath->query( '//div[@class="content"]' )->item( 0 );
			if ( null === $body ) {
				return '';
			}

			// Get the full body content as one piece
			$body_content = $dom->saveHTML( $body );

			// Apply transformations to the entire content
			$body_content = $this->map_content_child_node( $body_content );

			// Remove only the permalink link from posted paragraphs, keeping the posted by information
			$body_content = preg_replace( '/(\s*\|\s*<a\s[^>]*>Permalink<\/a>)/i', '', $body_content );

			return $this->process_content( trim( $body_content ) );
		}

		// Otherwise, return an empty string.
		return '';
	}


	/**
	 * @inheritDoc
	 */
	public function process_content( string $content ): string {
		// Apply various content processing steps
		$content = $this->fix_non_own_domain_links( $content );
		$content = $this->process_image_links( $content );
		$content = $this->process_standalone_images( $content );
		$content = $this->convert_formatting_tags( $content );
		$content = $this->process_comments( $content );

		// Extract only the inner content of div id="content", removing the wrapper div
		if ( preg_match( '/<div id="content">(.*?)<\/div>/s', $content, $matches ) ) {
			$post_content = trim( $matches[1] );
		} else {
			$post_content = $content;
		}

		// Extract and append comments if they exist from the processed content
		if ( preg_match( '/<div id="comments">(.+?)<\/div>/s', $content, $comment_matches ) ) {
			// Comments removed due to conents contained.
			$post_content .= $comment_matches[1];
		}

		// Fix double-encoded UTF-8 characters
		$post_content = $this->fix_double_encoded_characters( $post_content );

		// Return the final processed content
		return $post_content;
	}

	/**
	 * Fix none own domain links.
	 * Removes the Internet Archive prefix from links that are not own domain.
	 *
	 * @param string $content The content to fix links in.
	 *
	 * @return string The content with fixed links.
	 */
	public function fix_non_own_domain_links( string $content ): string {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $content );
		libxml_clear_errors();

		$anchor_tags = $dom->getElementsByTagName( 'a' );
		foreach ( $anchor_tags as $tag ) {
			$href = $tag->getAttribute( 'href' );

			// Skip if empty href
			if ( empty( $href ) ) {
				continue;
			}

			// Only process archive.org links
			if ( ! preg_match( '/^https?:\/\/web\.archive\.org\/web\/\d+(?:im_)?\/(.+)$/', $href, $matches ) ) {
				continue;
			}

			$original_url = $matches[1];

			// Keep archive links ONLY if they point to headrush.typepad.com/creating_passionate_users
			if ( str_contains( $original_url, 'headrush.typepad.com/creating_passionate_users' ) ) {
				continue; // Keep the archive link as-is
			}

			// Remove archive prefix for all other domains
			$tag->setAttribute( 'href', $original_url );
		}

		return $dom->saveHTML();
	}

	/**
	 * Process image links - finds <a> tags containing <img> tags for modification.
	 *
	 * @param string $content The content to process image links in.
	 *
	 * @return string The content with processed image links.
	 */
	public function process_image_links( string $content ): string {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$anchor_tags  = $dom->getElementsByTagName( 'a' );
		$replacements = array();

		foreach ( $anchor_tags as $anchor ) {
			$href = $anchor->getAttribute( 'href' );

			// Check if this anchor contains an img tag
			$img_tags = $anchor->getElementsByTagName( 'img' );
			if ( $img_tags->length === 0 ) {
				continue; // Skip anchors without images
			}

			// Process each image within this anchor
			foreach ( $img_tags as $img ) {
				$img_src = $img->getAttribute( 'src' );

				// Clean archive.org prefix from image src
				if ( preg_match( '/^https?:\/\/web\.archive\.org\/web\/\d+(?:im_)?\/(.+)$/', $img_src, $matches ) ) {
					$clean_img_src = $matches[1];
				} else {
					$clean_img_src = $img_src;
				}

				// Clean archive.org prefix from anchor href
				if ( preg_match( '/^https?:\/\/web\.archive\.org\/web\/\d+(?:im_)?\/(.+)$/', $href, $matches ) ) {
					$clean_href = $matches[1];
				} else {
					$clean_href = $href;
				}

				// Use shared helper to create Gutenberg block
				$gutenberg_block = $this->create_gutenberg_image_block( $img, $clean_img_src, $clean_href );

				// Store the replacement
				$original_html                  = $dom->saveHTML( $anchor );
				$replacements[ $original_html ] = $gutenberg_block;
			}
		}

		// Apply replacements
		$processed_content = $dom->saveHTML();
		foreach ( $replacements as $original => $replacement ) {
			$processed_content = str_replace( $original, $replacement, $processed_content );
		}

		return $processed_content;
	}

	/**
	 * Process standalone images - finds <img> tags not wrapped in <a> tags.
	 *
	 * @param string $content The content to process standalone images in.
	 *
	 * @return string The content with processed standalone images.
	 */
	public function process_standalone_images( string $content ): string {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$img_tags     = $dom->getElementsByTagName( 'img' );
		$replacements = array();

		foreach ( $img_tags as $img ) {
			// Skip images that are inside anchor tags (already handled by process_image_links)
			if ( $img->parentNode->tagName === 'a' ) {
				continue;
			}

			// Skip images that are already in Gutenberg blocks (inside figure elements with wp-block-image class)
			$parent = $img->parentNode;
			while ( $parent ) {
				if ( $parent instanceof \DOMElement &&
					$parent->tagName === 'figure' &&
					$parent->getAttribute( 'class' ) &&
					strpos( $parent->getAttribute( 'class' ), 'wp-block-image' ) !== false ) {
					continue 2; // Skip this image entirely
				}
				$parent = $parent->parentNode;
			}

			$img_src = $img->getAttribute( 'src' );

			// Clean archive.org prefix from image src
			if ( preg_match( '/^https?:\/\/web\.archive\.org\/web\/\d+(?:im_)?\/(.+)$/', $img_src, $matches ) ) {
				$clean_img_src = $matches[1];
			} else {
				$clean_img_src = $img_src;
			}

			// Create Gutenberg block for standalone image (no link, lightbox enabled)
			$gutenberg_block = $this->create_gutenberg_image_block( $img, $clean_img_src );

			// Store the replacement - replace the entire parent element (usually <p>)
			$parent_html                  = $dom->saveHTML( $img->parentNode );
			$replacements[ $parent_html ] = $gutenberg_block;
		}

		// Apply replacements
		$processed_content = $dom->saveHTML();
		foreach ( $replacements as $original => $replacement ) {
			$processed_content = str_replace( $original, $replacement, $processed_content );
		}

		return $processed_content;
	}

	/**
	 * Convert <b> and <i> tags to <strong> and <em> respectively.
	 *
	 * @param string $content The content to convert formatting tags in.
	 *
	 * @return string The content with converted formatting tags.
	 */
	public function convert_formatting_tags( string $content ): string {
		// Convert <b> to <strong> (with attributes if any)
		$content = preg_replace( '/<b(\s[^>]*)?>/', '<strong$1>', $content );
		$content = str_replace( '</b>', '</strong>', $content );

		// Convert <i> to <em> (with attributes if any)
		$content = preg_replace( '/<i(\s[^>]*)?>/', '<em$1>', $content );
		$content = str_replace( '</i>', '</em>', $content );

		return $content;
	}

	/**
	 * Create a Gutenberg image block from an img element.
	 *
	 * @param \DOMElement $img       The img DOM element.
	 * @param string      $clean_src The cleaned image source URL.
	 * @param string|null $href      Optional link href for linked images.
	 *
	 * @return string The Gutenberg block HTML.
	 */
	private function create_gutenberg_image_block( \DOMElement $img, string $clean_src, ?string $href = null ): string {
		$img_alt    = $img->getAttribute( 'alt' );
		$img_title  = $img->getAttribute( 'title' );
		$img_width  = $img->getAttribute( 'width' );
		$img_height = $img->getAttribute( 'height' );

		// Attempt to find media item for the image src
		$media_item = $this->find_media_item( $clean_src );
		if ( $media_item !== null ) {
			$clean_src = $media_item['url'];
			$media_id  = $media_item['id'];
		} else {
			// Generate a mock ID if no media item found
			$media_id = rand( 10000, 99999 );
		}

		// Build img attributes string
		$img_attributes = array();
		if ( ! empty( $img_alt ) ) {
			$img_attributes[] = 'alt="' . htmlspecialchars( $img_alt, ENT_QUOTES ) . '"';
		} else {
			$img_attributes[] = 'alt=""';
		}

		$img_attributes[] = 'class="wp-image-' . $media_id . '"';

		if ( ! empty( $img_title ) ) {
			$img_attributes[] = 'title="' . htmlspecialchars( $img_title, ENT_QUOTES ) . '"';
		}
		if ( ! empty( $img_width ) ) {
			$img_attributes[] = 'width="' . htmlspecialchars( $img_width, ENT_QUOTES ) . '"';
		}
		if ( ! empty( $img_height ) ) {
			$img_attributes[] = 'height="' . htmlspecialchars( $img_height, ENT_QUOTES ) . '"';
		}

		$img_attrs_string = implode( ' ', $img_attributes );

		if ( $href !== null ) {
			// Check if link and image src are the same (or similar enough)
			if ( $href === $clean_src || $this->are_urls_similar( $href ?: '', $clean_src ?: '' ) ) {
				// Same URL - use lightbox enabled, no link destination
				return '<!-- wp:image {"lightbox":{"enabled":true,"align":"center"},"id":' . $media_id . ',"sizeSlug":"full","linkDestination":"none"} -->' . "\n" .
						'<figure class="wp-block-image aligncenter size-full"><img src="' . htmlspecialchars( $clean_src ?: '', ENT_QUOTES ) . '" ' . $img_attrs_string . '/></figure>' . "\n" .
						'<!-- /wp:image -->';
			} else {
				// Different URLs - use custom link destination, no lightbox
				return '<!-- wp:image {"lightbox":{"enabled":false,"align":"center"},"id":' . $media_id . ',"sizeSlug":"full","linkDestination":"custom"} -->' . "\n" .
						'<figure class="wp-block-image aligncenter size-full"><a href="' . htmlspecialchars( $href ?: '', ENT_QUOTES ) . '"><img src="' . htmlspecialchars( $clean_src, ENT_QUOTES ) . '" ' . $img_attrs_string . '/></a></figure>' . "\n" .
						'<!-- /wp:image -->';
			}
		} else {
			// No link - standalone image without lightbox
			return '<!-- wp:image {"id":' . $media_id . ',"sizeSlug":"full","linkDestination":"none","align":"center"} -->' . "\n" .
					'<figure class="wp-block-image size-full aligncenter"><img src="' . htmlspecialchars( $clean_src ?: '', ENT_QUOTES ) . '" ' . $img_attrs_string . '/></figure>' . "\n" .
					'<!-- /wp:image -->';
		}
	}

	/**
	 * Attempt to find media item for a given url.
	 *
	 * @param string $url The URL to check.
	 *
	 * @return array{url: string, id: int}|null
	 */
	private function find_media_item( string $url ): ?array {
		$database = a8scp_scrapper_to_wp_get_database_service();

		$sql     = 'SELECT * from `media_url_hashes` WHERE `media_url` LIKE :media_url';
		$results = $database->get_results(
			$sql,
			array( ':media_url' => '%' . $url )
		);

		// If we dont have any results, try again as http or https
		if ( 0 === count( $results ) ) {
			if ( str_starts_with( $url, 'http://' ) ) {
				$alt_url = 'https://' . substr( $url, 7 );
			} elseif ( str_starts_with( $url, 'https://' ) ) {
				$alt_url = 'http://' . substr( $url, 8 );
			} else {
				$alt_url = '';
			}

			if ( ! empty( $alt_url ) ) {
				$sql     = 'SELECT * from `media_url_hashes` WHERE `media_url` LIKE :media_url';
				$results = $database->get_results(
					$sql,
					array( ':media_url' => '%' . $alt_url )
				);
			}
		}

		// If we still have no results, return null
		if ( 0 === count( $results ) ) {
			return null;
		}

		// Extract first media item
		$media_item = $results[0]['media_id'];

		return array(
			'url' => wp_get_attachment_image_url( $media_item, 'full' ),
			'id'  => $media_item,
		);
	}

	/**
	 * Check if two URLs are similar enough to be considered the same.
	 *
	 * @param string $url1 First URL to compare.
	 * @param string $url2 Second URL to compare.
	 *
	 * @return boolean True if URLs are similar enough.
	 */
	private function are_urls_similar( string $url1, string $url2 ): bool {
		// Remove trailing slashes and convert to lowercase for comparison
		$url1 = rtrim( strtolower( $url1 ), '/' );
		$url2 = rtrim( strtolower( $url2 ), '/' );

		// Check if they're exactly the same
		if ( $url1 === $url2 ) {
			return true;
		}

		// Check if one is a variant of the other (e.g., with/without www, http/https)
		$url1_parsed = parse_url( $url1 );
		$url2_parsed = parse_url( $url2 );

		if ( isset( $url1_parsed['path'] ) && isset( $url2_parsed['path'] ) ) {
			// Compare just the path and filename
			$path1 = basename( $url1_parsed['path'] );
			$path2 = basename( $url2_parsed['path'] );

			if ( $path1 === $path2 && ! empty( $path1 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the author information from the scraped content.
	 *
	 * @since 1.0.0
	 *
	 * @return integer The author user ID.
	 */
	public function get_author(): int {
		$content = $this->get_content();

		// Find the last "Posted by {name}" at the end of the content
		// Pattern looks for the last occurrence of Posted by {name} in the content
		$pattern = '/<p class="posted">[^<]*Posted by ([^<\n]+?) on [^<]*<\/p>/s';

		// Find all matches and get the last one
		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			$last_match  = end( $matches );
			$author_name = trim( $last_match[1] );
			return $this->find_or_create_author( $author_name );
		}
		return 1;
	}

	/**
	 * Find or create author by name.
	 *
	 * @param string $author_name The author name.
	 *
	 * @return integer The author user ID.
	 */
	private function find_or_create_author( string $author_name ): int {
		// Try to find user by display name
		$user = get_users(
			array(
				'search' => $author_name,
				'fields' => 'ID',
			)
		);

		if ( ! empty( $user ) ) {
			// User found, return ID
			return (int) $user[0];
		}

		// User not found, create a new one
		$new_user = wp_insert_user(
			array(
				'user_login'    => sanitize_user( $author_name ),
				'user_nicename' => sanitize_title( $author_name ),
				'user_email'    => $author_name . '@creating_passionate_users.archived"',
				'display_name'  => $author_name,
				'user_pass'     => wp_generate_password( 12, false ),
				'role'          => 'author',
			)
		);

		return (int) $new_user;
	}

	/**
	 * Extract terms (categories, tags, etc.) from the scraped content.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<int, string>> An array of terms grouped by taxonomy.
	 */
	public function get_terms(): array {
		return array(
			'tag'      => array(), // WordPress tags
			'category' => array(), // WordPress categories
		);
	}

	/**
	 * Map/transform content child nodes.
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

		// 1. Change opening div class="content" to div id="content"
		$nodes_html = str_replace( '<div class="content">', '<div id="content">', $nodes_html );

		// 2. Remove the entire TrackBack section (from TrackBack h2 to Comments h2)
		$nodes_html = preg_replace(
			'/<h2[^>]*>\s*<a[^>]*id="trackback"[^>]*><\/a>TrackBack<\/h2>.*?(?=<h2[^>]*>\s*.*?Comments<\/h2>)/s',
			'',
			$nodes_html
		);

		// 3. Extract and restructure comments section
		if ( strpos( $nodes_html, 'Comments</h2>' ) !== false ) {
			// Pattern to match from Comments h2 to the end of the content div
			$pattern = '/(<h2[^>]*>\s*(?:<a[^>]*><\/a>)?\s*Comments<\/h2>)(.*)(<\/div>)$/s';

			if ( preg_match( $pattern, $nodes_html, $matches ) ) {
				// $matches[1] = Comments h2 header
				// $matches[2] = All comments content
				// $matches[3] = Closing </div>

				$main_content_end = strpos( $nodes_html, $matches[1] );
				$main_content     = substr( $nodes_html, 0, $main_content_end );
				$comments_content = trim( $matches[2] );

				// Rebuild: main content + closing div + comments section
				$nodes_html = $main_content . '</div>' .
								'<div id="comments">' . $comments_content . '</div>';
			}
		}

		// 4. Remove empty anchor tags with IDs
		$nodes_html = preg_replace( '/<a[^>]*id="(comments|content|trackback)"[^>]*><\/a>/', '', $nodes_html );

		return $nodes_html;
	}

	/**
	 * Get the post status for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The post status (e.g., 'publish', 'draft', 'pending').
	 */
	public function get_post_status(): string {
		return 'publish';
	}

	/**
	 * Get the post type for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The post type (e.g., 'post', 'page', 'custom_post_type').
	 */
	public function get_post_type(): string {
		return 'post';
	}

	/**
	 * Get the parent post ID for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return integer The parent post ID (0 for no parent).
	 */
	public function get_post_parent(): int {
		return 0;
	}

	/**
	 * Get the featured image URL for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The featured image URL (empty string if none).
	 */
	public function get_featured_image(): string {
		return '';
	}

	/**
	 * Get additional meta data for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Array of meta key-value pairs.
	 */
	public function get_post_meta(): array {
		$data = $this->content_scrapper->get_url_data();
		return array(
			'import_cache_key' => $data['url_hash'],
			'base_url'         => $data['page_url'],
			'archived_url'     => Internet_Archive::get_latest_snapshot( $data['page_url'] ),
		); // Return any additional meta data.
	}

	/**
	 * Process comments section - converts comments to Gutenberg quote blocks.
	 *
	 * @param string $content The content to process comments in.
	 * @return string The content with processed comments.
	 */
	public function process_comments( string $content ): string {
		// Check if there's a comments section
		// Match to end of string since comments div should be last after restructuring
		if ( ! preg_match( '/<div id="comments">(.*)<\/div>(?=<\/body>|<\/html>|\s*$)/s', $content, $matches ) ) {
			return $content;
		}

		$comments_html      = $matches[1];
		$processed_comments = '';

		// Split comments by anchor tags with IDs
		$comment_pattern = '/<a id="(c\d+)"><\/a>\s*(.+?)(?=<a id="c\d+"><\/a>|$)/s';

		if ( preg_match_all( $comment_pattern, $comments_html, $comment_matches, PREG_SET_ORDER ) ) {
			$processed_comments = "\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Comments</h2>\n<!-- /wp:heading -->\n\n";

			foreach ( $comment_matches as $comment ) {
				$comment_id      = $comment[1];
				$comment_content = trim( $comment[2] );

				// Skip comments with adult links
				if ( $this->comment_contains_adult_links( $comment_content ) ) {
					continue;
				}

				// Extract the comment text and posted info
				if ( preg_match( '/^(.+?)?<p class="posted">(.+?)<\/p>/s', $comment_content, $parts ) ) {
					$comment_text = isset( $parts[1] ) ? trim( $parts[1] ) : '';
					$posted_info  = trim( $parts[2] );

					// Skip if no actual comment content
					if ( empty( $comment_text ) ) {
						continue;
					}

					// Clean up the comment text - remove extra whitespace and newlines
					$comment_text = preg_replace( '/\s+/', ' ', $comment_text );
					$comment_text = trim( $comment_text );

					// Remove any <p> tags from the comment text since we'll wrap it ourselves
					$comment_text = preg_replace( '/<\/?p[^>]*>/', '', $comment_text );
					$comment_text = trim( $comment_text );

					// Convert to Gutenberg quote block
					$processed_comments .= "<!-- wp:quote {\"className\":\"comment comment-$comment_id\"} -->\n";
					$processed_comments .= "<blockquote class=\"wp-block-quote comment comment-$comment_id\" id=\"$comment_id\"><!-- wp:paragraph -->\n";
					$processed_comments .= "<p>$comment_text</p>\n";
					$processed_comments .= "<!-- /wp:paragraph --><cite>$posted_info</cite></blockquote>\n";
					$processed_comments .= "<!-- /wp:quote -->\n\n";
				}
			}
		}

		// Replace the original comments section content but keep the div wrapper
		// Use the already-matched content to avoid regex matching issues
		$content = str_replace( $matches[0], '<div id="comments">' . $processed_comments . '</div>', $content );

		return $content;
	}

	/**
	 * Check if the comment body contains adult links.
	 *
	 * @param string $comment_body The comment body to check.
	 *
	 * @return boolean True if adult links are found, false otherwise.
	 */
	private function comment_contains_adult_links( string $comment_body ): bool {
		$porn_keywords = array(
			'teen',
			'porn',
			'sex',
			'milf',
			'lesbian',
			'fuck',
			'bang',
			'ass',
			'cock',
			'gay',
			'slut',
			'cum',
			'tit',
			'boob',
			'adult',
			'nude',
			'facial',
			'pussy',
			'oral',
			'anal',
			'xxx',
		);

		// Get a count of all the links in the content
		$link_count = substr_count( $comment_body, '<a href=' );

		// if we have less than 50 links, return as false
		if ( $link_count < 50 ) {
			return false;
		}

		// Check all the hrefs, if we have more than 2 contains the $porn_keywords
		$hrefs = array();
		preg_match_all( '/<a href="([^"]+)"/', $comment_body, $hrefs );

		// If we have more than 2 links containing the $porn_keywords, return true
		$adult_link_count = 0;
		foreach ( $hrefs[1] as $href ) {
			foreach ( $porn_keywords as $keyword ) {
				if ( stripos( $href, $keyword ) !== false ) {
					++$adult_link_count;
					break; // No need to check other keywords for this link
				}
			}
		}

		return $adult_link_count > 2;
	}

	/**
	 * Get the post date for the imported content.
	 *
	 * @since 1.0.0
	 *
	 * @return string The post date in 'Y-m-d H:i:s' format.
	 */
	public function get_post_date(): string {
		$content = $this->get_content();

		// Find the last "Posted by" paragraph at the end of the content - capture the WHOLE p tag
		$pattern = '/<p class="posted">[^<]*<\/p>/s';
		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			// Get the last match
			$last_match     = end( $matches );
			$posted_content = $last_match[0];

			// Try different patterns for date extraction
			$date_patterns = array(
				'/Posted by [^<\n]+? on ([A-Za-z]+ \d{1,2}, \d{4})/',  // "June 16, 2005"
				'/Posted by [^<\n]+? on ([A-Za-z]+\s+\d{1,2}, \d{4})/', // "September  7, 2005" (extra spaces)
				'/Posted by [^<\n]+? on ([A-Za-z]+ \d{1,2} \d{4})/',    // Alternative format if needed
			);

			foreach ( $date_patterns as $date_pattern ) {
				if ( preg_match( $date_pattern, $posted_content, $date_matches ) ) {
					$date_str = trim( preg_replace( '/\s+/', ' ', $date_matches[1] ) ); // Normalize whitespace
					$date     = \DateTimeImmutable::createFromFormat( 'F j, Y', $date_str );

					if ( $date !== false ) {
						return $date->format( 'Y-m-d H:i:s' );
					}
					break; // Found a match, no need to try other patterns
				}
			}
		}

		return '';
	}

	/**
	 * Fix double-encoded UTF-8 characters commonly found in archived content.
	 *
	 * @param string $content The content to fix.
	 *
	 * @return string The content with fixed character encoding.
	 */
	private function fix_double_encoded_characters( string $content ): string {
		$replacements = array(
			// Common smart quotes and apostrophes
			'&acirc;&#128;&#153;' => "\u{2019}",  // Right single quotation mark (')
			'&acirc;&#128;&#152;' => "\u{2018}",  // Left single quotation mark (')
			'&acirc;&#128;&#156;' => "\u{201C}",  // Left double quotation mark (")
			'&acirc;&#128;&#157;' => "\u{201D}",  // Right double quotation mark (")
			'&acirc;&#128;&#147;' => "\u{2014}",  // Em dash (—)
			'&acirc;&#128;&#148;' => "\u{2014}",  // Em dash (—) (alternative)
			'&acirc;&#128;&#150;' => "\u{2013}",  // En dash (–)
			'&acirc;&#128;&#166;' => "\u{2026}",  // Horizontal ellipsis (…)

			// Other common double-encoded characters
			'&Acirc;&laquo;'      => '«',       // Left-pointing double angle quotation mark
			'&Acirc;&raquo;'      => '»',       // Right-pointing double angle quotation mark
			'&Acirc;&nbsp;'       => ' ',        // Non-breaking space
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $content );
	}
}
