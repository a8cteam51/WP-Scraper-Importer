<?php
/**
 * Command used to import media files.
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
use A8C\SpecialProjects\ScrapperToWP\Service\Media;

/**
 * Import media command.
 */
class Import_Media_Command extends WP_CLI_Command {

	/**
	 * Command entry point.
	 *
	 * ## EXAMPLES
	 * wp scrapper-to-wp import-media
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
		WP_CLI::line( 'Starting media import process...' );
		WP_CLI::line( '----------------------------------------' );

		// Get database service
		$db = a8scp_scrapper_to_wp_get_database_service();

		// Find all media URLs to import, that do not have a media id (NULL)
		$sql        = 'SELECT * FROM media_url_hashes WHERE media_id = 0';
		$media_urls = $db->get_results( $sql );

		// Start a progress bar
		WP_CLI::line( 'Importing ' . count( $media_urls ) . ' media items...' );

		// Import the media URLs
		foreach ( $media_urls as $index => $media_item ) {
			// Skip the ads/empty.gif URL silently
			if ( \str_ends_with( $media_item['media_url'], '/https://headrush.typepad.com/ads/empty.gif' ) ) {
				continue;
			}
			WP_CLI::line( '++ Processing media item ' . ( $index + 1 ) . '/' . count( $media_urls ) . ' (' . $media_item['media_url'] . ')' );
			$this->process_media_item( $media_item );
		}

		// TODO: Implement media import logic here
		WP_CLI::success( 'Media import process completed.' );
	}

	/**
	 * Process a single media item.
	 *
	 * @param array{id:int, media_url:string, media_id:int} $media_item The media item data.
	 *
	 * @return void
	 */
	protected function process_media_item( array $media_item ): void {
		// Normalize media URL
		$normalized_url = Internet_Archive::normalize_url( $media_item['media_url'] );
		$media_url      = $media_item['media_url'];

		// Silently skip the ads/empty.gif URL
		if ( \str_ends_with( $media_url, '/https://headrush.typepad.com/ads/empty.gif' ) ) {
			return;
		}

		if ( \str_contains( $media_url, 'http://b.scorecardresearch.com/b?c1=2&c2=6035669&c3=&c4=http%3A%2F%2Fheadrush.typepad.com%2Fcreating_passionate_users%' ) ) {
			WP_CLI::line( 'Skipping ' . $media_url . ' (scorecardresearch.com)' );
			return;
		}

		$images_to_skip = array(
			'https://headrush.typepad.com/ads/empty.gif',
			'http://i.biblio.com/b/378m/53885378-0-m.jpg',
			'http://www.ubcbotanicalgarden.org/potd/hylocomium_mycena2-thumb.jpg',
			'http://andres.bianciotto.com.ar/resserver.php?blogId=30resource=loveandhate_3.jpg',
			'http://www.assoc-amazon.com/e/ir?t=creditrepa09c-20&l=ur2&o=1',
			'http://www.rtqe.net/ObliqueStrategies/images/oblique_box.gif',
			'http://images.barnesandnoble.com/images/7350000/7353402.gif',
			'http://images.barnesandnoble.com/images/8420000/8424151.gif',
			'http://images.amazon.com/images/P/B000063T00.16._AA260_SCLZZZZZZZ_.jpg',
			'http://headrush.typepad.com/illustrations/11306_sm.jpg',
			'https://headrush.typepad.com/photos/uncategorized/yourpassion.jpg',
			'/web/20250911094711im_/https://headrush.typepad.com/storage/73302076_427e6220f5_m.jpg',
			'http://a1204.g.akamai.net/7/1204/1401/05010717011/images.barnesandnoble.com/images/8910000/8915578.jpg',
			'http://headrush.typepad.com/illustrations/TwoBrains.jpg',
			'http://b.scorecardresearch.com/b?c1=2&c2=6035669&c3=&c4=http%3A%2F%2Fheadrush.typepad.com%2Fcreating_passionate_users%2F2006%2Fweek6%2F&c5=&c6=&c15=&cv=1.3&cj=1',
			'http://b.scorecardresearch.com/b?c1=2&c2=6035669&c3=&c4=http%3A%2F%2Fheadrush.typepad.com%2Fcreating_passionate_users%2F2006%2Fweek5%2F&c5=&c6=&c15=&cv=1.3&cj=1',
			'http://b.scorecardresearch.com/b?c1=2&c2=6035669&c3=&c4=http%3A%2F%2Fheadrush.typepad.com%2Fcreating_passionate_users%2F2006%2Fweek40%2F&c5=&c6=&c15=&cv=1.3&cj=1',
		);

		// Iterate through the skipped an make s
		if ( in_array( $normalized_url, $images_to_skip, true ) ) {
			WP_CLI::line( 'Skipping ' . $normalized_url . ' (in skip list)' );
			return;
		}
		// Do a query looking for any other rows where the media_url ends with
		$sql          = 'SELECT * FROM media_url_hashes WHERE media_url LIKE :media_url_http OR media_url LIKE :media_url_https ORDER BY media_url DESC';
		$http_suffix  = '%' . \str_replace( 'https://', 'http://', $normalized_url );
		$https_suffix = '%' . \str_replace( 'http://', 'https://', $normalized_url );
		$db           = a8scp_scrapper_to_wp_get_database_service();
		$results      = $db->get_results(
			$sql,
			array(
				':media_url_http'  => $http_suffix,
				':media_url_https' => $https_suffix,
			)
		);

		// If we have no results, return.
		if ( empty( $results ) ) {
			return;
		}

		// If the media id is already set, skip.
		if ( ! empty( $results[0]['media_id'] ) && (int) $results[0]['media_id'] > 0 ) {
			WP_CLI::line( 'Media URL ' . $normalized_url . ' already has media ID ' . $results[0]['media_id'] . '. Skipping.' );
			return;
		}

		$image_map = array(
			'https://headrush.typepad.com/photos/uncategorized/graphicschartsgraphs' => 'https://glynnquelch.co.uk/wp-content/uploads/2025/11/6a00d83451b44369e200e54f7f99198834-800wi.jpg',
			'http://www.mind-map.com/_metacanvas/attach_handler.uhtml?attach_id=166&content_type=image/png' => 'https://glynnquelch.co.uk/wp-content/uploads/2025/11/mmbmm.png',
			'http://pix.merlinmann.com/hipsterpda1.png' => 'http://web.archive.org/web/20060615211129/http://www.merlinmann.com:80/pix/hipsterpda1.png',
			'https://headrush.typepad.com/photos/uncategorized/mickey.JPG' => 'http://web.archive.org/web/20250914081635im_/https://headrush.typepad.com/photos/uncategorized/mickey.JPG',
			'https://headrush.typepad.com/photos/uncategorized/slotmachinetwo.jpg' => 'http://web.archive.org/web/20250910141949im_/https://headrush.typepad.com/photos/uncategorized/slotmachinetwo.jpg',
			'https://headrush.typepad.com/photos/uncategorized/bookofcool.jpg' => 'http://web.archive.org/web/20250910210437im_/https://headrush.typepad.com/photos/uncategorized/bookofcool.jpg',
			'https://headrush.typepad.com/photos/uncategorized/eq3highlow.jpg' => 'http://web.archive.org/web/20250913002559im_/https://headrush.typepad.com/photos/uncategorized/eq3highlow.jpg',
			'https://headrush.typepad.com/photos/uncategorized/smackdown_1.jpg' => 'http://web.archive.org/web/20250912194041im_/https://headrush.typepad.com/photos/uncategorized/smackdown_1.jpg',
			'https://headrush.typepad.com/photos/uncategorized/nextlevel.jpg' => 'http://web.archive.org/web/20250910170951im_/https://headrush.typepad.com/photos/uncategorized/nextlevel.jpg',
			'https://headrush.typepad.com/photos/uncategorized/emotions.jpg' => 'http://web.archive.org/web/20250910170951im_/https://headrush.typepad.com/photos/uncategorized/emotions.jpg',
			'https://headrush.typepad.com/photos/uncategorized/nikoncover.jpg' => 'http://web.archive.org/web/20250914125348im_/https://headrush.typepad.com/photos/uncategorized/nikoncover.jpg',
			'https://headrush.typepad.com/photos/uncategorized/doublepulse.jpg' => 'http://web.archive.org/web/20250910225212im_/https://headrush.typepad.com/photos/uncategorized/doublepulse.jpg',
			'https://headrush.typepad.com/photos/uncategorized/dmr_blog_image_1.jpg' => 'http://web.archive.org/web/20250911092554im_/https://headrush.typepad.com/photos/uncategorized/dmr_blog_image_1.jpg',
			'https://headrush.typepad.com/photos/uncategorized/divafilly1.jpg' => 'http://web.archive.org/web/20250910021351im_/https://headrush.typepad.com/photos/uncategorized/divafilly1.jpg',
			'/web/20080926073244im_/http://www.typepad.com/.shared/images/tp-logo-app.gif' => 'http://web.archive.org/web/20080929171656im_/http://www.typepad.com/.shared/images/tp-logo-app.gif',
			'https://headrush.typepad.com/photos/uncategorized/andikarawalkingweb.jpg' => 'http://web.archive.org/web/20250911150923im_/https://headrush.typepad.com/photos/uncategorized/andikarawalkingweb.jpg',
			'https://headrush.typepad.com/photos/uncategorized/2007/04/06/incremental1.jpg' => 'https://glynnquelch.co.uk/wp-content/uploads/2025/11/6a00d83451b44369e200e54f21660f8834-800wi.jpg',
			'https://headrush.typepad.com/photos/uncategorized/2007/03/20/paperhatguy.jpg' => 'https://glynnquelch.co.uk/wp-content/uploads/2025/11/6a00d83451b44369e200e54f0afab38833-800wi.jpg',
			'https://headrush.typepad.com/photos/uncategorized/2007/03/18/bridgeclimb.jpg' => 'http://web.archive.org/web/20250910100808im_/https://headrush.typepad.com/photos/uncategorized/2007/03/18/bridgeclimb.jpg',
			'https://headrush.typepad.com/photos/uncategorized/2007/03/18/dinnerpartyweb.jpg' => 'http://web.archive.org/web/20250915220726im_/https://headrush.typepad.com/photos/uncategorized/2007/03/18/dinnerpartyweb.jpg',
			'https://headrush.typepad.com/photos/uncategorized/2007/03/16/twitterslots.jpg' => 'http://web.archive.org/web/20250909191913im_/https://headrush.typepad.com/photos/uncategorized/2007/03/16/twitterslots.jpg',
			'http://www.sdmagazine.com/images/jolt_logo15_sm.gif' => 'http://web.archive.org/web/20041220225103/http://www.sdmagazine.com/images/jolt_logo15_sm.gif',
			'https://headrush.typepad.com/photos/uncategorized/frankendog.png' => 'http://web.archive.org/web/20070128024035/http://headrush.typepad.com/photos/uncategorized/frankendog.png',
			'http://headrush.typepad.com/photos/uncategorized/frankendog.png' => 'http://web.archive.org/web/20070128024035/http://headrush.typepad.com/photos/uncategorized/frankendog.png',
			'http://headrush.typepad.com/photos/uncategorized/greatdane_1.jpg' => 'http://web.archive.org/web/20091014130807im_/http://headrush.typepad.com/photos/uncategorized/greatdane_1.jpg',
		);

		// Get the first URL and upload it.
		$first_url = $results[0]['media_url'];

		// If the media URL is in our image map, use the mapped URL instead.
		if ( array_key_exists( $normalized_url, $image_map ) ) {
			$first_url = $image_map[ $normalized_url ];
		}

		try {
			WP_CLI::line( 'Downloading and importing media from URL: ' . $first_url );
			$media_id = Media::from_url( $first_url );
		} catch ( \Exception $e ) {
			WP_CLI::line( 'Error importing media from URL ' . $first_url . ': ' . $e->getMessage() );
			$media_id = null;
		}

		// If we have a media ID, update all matching rows to set this media ID ONLY, we know the id.
		if ( $media_id !== null ) {
			$this->update_media_url_with_media_id( $results, $media_id );
			WP_CLI::line( 'Imported media ID ' . $media_id . ' for ' . count( $results ) . ' entries.' );
		}
	}

	/**
	 * Update a media url with a given media ID.
	 *
	 * @param array   $media_results The media with the same normalized URL.
	 * @param integer $media_id      The media ID to set.
	 *
	 * @return void
	 */
	protected function update_media_url_with_media_id( array $media_results, int $media_id ): void {
		$db = a8scp_scrapper_to_wp_get_database_service();

		// Extract all IDs from the media results.
		$ids = array_column( $media_results, 'id' );
		
		if ( empty( $ids ) ) {
			return;
		}

		// Create placeholders for the IN clause.
		$placeholders = str_repeat( '?,', count( $ids ) - 1 ) . '?';
		$update_sql   = "UPDATE media_url_hashes SET media_id = ? WHERE id IN ($placeholders)";
		
		// Prepare parameters: media_id first, then all the IDs.
		$params = array_merge( array( $media_id ), $ids );
		
		$db->query( $update_sql, $params );
	}
}
