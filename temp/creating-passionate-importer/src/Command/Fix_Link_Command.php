<?php
/**
 * Command used to fix links in imported content.
 *
 * @package     A8CSP_Scrapper_to_WP
 * @subpackage  Command
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP\Command;

defined( 'ABSPATH' ) || exit;

use WP_CLI;
use WP_CLI_Command;
use A8C\SpecialProjects\ScrapperToWP\Service\Database;
use A8C\SpecialProjects\ScrapperToWP\Service\Internet_Archive;

/**
 * Fix links command.
 */
class Fix_Link_Command extends WP_CLI_Command {

	/**
	 * Access to the database.
	 *
	 * @var Database
	 */
	protected $database;

	/**
	 * Log
	 *
	 * @var string[]
	 */
	protected $log = array();

	/**
	 * Command entry point.
	 *
	 * ## EXAMPLES
	 * wp scrapper-to-wp fix-links
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 *
	 * @param array<int, string> $args       The command arguments.
	 * @param array<int, string> $assoc_args The command associative arguments.
	 *
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ) {
		$this->database = \a8scp_scrapper_to_wp_get_database_service();
		$post_ids       = $this->get_all_new_post_ids();

		WP_CLI::log( 'Found ' . count( $post_ids ) . ' posts to process.' );

		foreach ( $post_ids as $post_id ) {
			$this->process_post( $post_id );
		}

		// Output the log
		if ( ! empty( $this->log ) ) {
			WP_CLI::log( 'Processing complete. Log:' );
			foreach ( $this->log as $log_entry ) {
				WP_CLI::log( $log_entry );
			}
		} else {
			WP_CLI::log( 'No archive links found in any posts.' );
		}
	}

	/**
	 * Process a single post.
	 *
	 * @param integer $post_id The post ID.
	 *
	 * @return void
	 */
	protected function process_post( int $post_id ): void {
		// Get the post content.
		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->log[] = "Post ID {$post_id} not found.";
			return;
		}

		$content         = $post->post_content;
		$initial_content = $content;

		// If the content has a link to video files, log it.
		if ( preg_match( '/\.(?:mp4|mpg|avi|mov|wmv|flv|webm|mkv|m4v)$/i', $content ) ) {
			$this->log[] = "!!!!!! Post ID {$post_id} contains video file links.";
		}

		// If the content has a link to PDF files, log it.
		if ( preg_match( '/\.pdf$/i', $content ) ) {
			$this->log[] = "!!!!!! Post ID {$post_id} contains PDF file links.";
		}

		// Extract all links in the content.
		preg_match_all( '/<a href=["\']([^"\']+)["\']/i', $content, $matches );
		$links = $matches[1] ?? array();

		$internal_links = \array_filter(
			$links,
			function ( $link ) {
				return \str_contains( $link, 'headrush.typepad.com/creating_passionate_users/' );
			}
		);

		// Remove #comments or #tackbacks from links.
		$internal_links = \array_map(
			function ( $link ) {
				// If the url ends in #comments, remove it.
				if ( \str_ends_with( $link, '#comments' ) ) {
					$link = \substr( $link, 0, -9 );
				}

				// If the url ends in #tackbacks, remove it.
				if ( \str_ends_with( $link, '#trackback' ) ) {
					$link = \substr( $link, 0, -10 );
				}
				return $link;
			},
			$internal_links
		);

		// If we have no self links, skip.
		if ( empty( $internal_links ) ) {
			WP_CLI::log( "Post ID {$post_id} contains no internal links. Skipping." );
			return;
		}

		// Attempt to look for the new urls of the internal links.
		foreach ( $internal_links as $link ) {
			$ids = $this->database->get_results(
				"SELECT post_id FROM 'page_url_hashes' WHERE page_url = :page_url",
				array( ':page_url' => Internet_Archive::normalize_url( $link ) )
			);

			// If we have no ids, log and continue.
			if ( empty( $ids ) ) {
				$this->log[] = "Post ID {$post_id} link {$link} has no target post.";
				continue;
			}

			// Extract the ids.
			$ids = \array_values(
				\array_unique(
					array_map(
						function ( $row ) {
							return (int) $row['post_id'];
						},
						$ids
					)
				)
			);

			// If we have more than 1 post id, for this, log.
			if ( count( $ids ) > 1 ) {
				$this->log[] = "!! Post ID {$post_id} link {$link} has multiple target posts: " . implode( ', ', $ids );
				continue;
			}
			$id        = $ids[0];
			$permalink = get_permalink( $id );
			if ( ! $permalink ) {
				$this->log[] = "Post ID {$post_id} link {$link} target post ID {$id} permalink not found.";
				continue;
			}

			// Replace the link in the content.
			$content = str_replace( $link, $permalink, $content );
		}

		if ( $initial_content === $content ) {
			$this->log[] = "Post ID {$post_id} content unchanged after processing links.";
			return;
		}

		// Update the post content, do not change the post and modified dates.
		$result = wp_update_post(
			array(
				'ID'                => $post_id,
				'post_content'      => $content,
				'post_date'         => $post->post_date,
				'post_date_gmt'     => $post->post_date_gmt,
				'post_modified'     => $post->post_modified,
				'post_modified_gmt' => $post->post_modified_gmt,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->log[] = "Post ID {$post_id} update failed: " . $result->get_error_message();
			return;
		}

		// Log the successful update.
		WP_CLI::log( "Post ID {$post_id} updated successfully." );
	}



	/**
	 * Extract archive links from content.
	 *
	 * @param string $content The post content.
	 *
	 * @return array<array> Array of link pairs [archive_url, original_url].
	 */
	protected function extract_archive_links( string $content ): array {
		$links = array();

		// Pattern to match web.archive.org URLs pointing to headrush.typepad.com
		// Handles both http and https for both archive URL and original URL
		$pattern = '/https?:\/\/web\.archive\.org\/web\/\d+\/https?:\/\/headrush\.typepad\.com\/creating_passionate_users\/[^"\s>]+/i';

		// Find all matches
		if ( preg_match_all( $pattern, $content, $matches ) ) {
			foreach ( $matches[0] as $archive_url ) {
				// Extract the original URL from the archive URL
				// Pattern: {http|https}://web.archive.org/web/{timestamp}/{http|https}://headrush.typepad.com/...
				if ( preg_match( '/https?:\/\/web\.archive\.org\/web\/\d+\/(https?:\/\/headrush\.typepad\.com\/creating_passionate_users\/[^"\s>]+)/i', $archive_url, $url_matches ) ) {
					$original_url = $url_matches[1];

					$links[] = array( $archive_url, $original_url );
				}
			}
		}

		return $links;
	}

	/**
	 * Get all the new post ids.
	 *
	 * @return array<int>
	 */
	protected function get_all_new_post_ids(): array {
		$results = $this->database->get_results( "SELECT post_id FROM 'page_url_hashes' where post_id != 0" );

		return array_values(
			array_unique(
				array_map(
					function ( $row ) {
						return (int) $row['post_id'];
					},
					$results
				)
			)
		);
	}
}
