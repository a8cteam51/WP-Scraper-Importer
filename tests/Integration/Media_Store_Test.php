<?php
/**
 * Integration tests for media persistence.
 *
 * Media_Store maps source URL => attachment ID so the same image is never
 * re-imported. Media::from_url() consults that store and returns the cached ID
 * before doing any upload, which is the behaviour proven here (no WP-CLI/network
 * required because a store hit short-circuits the upload).
 */

declare( strict_types=1 );

namespace Tests\Integration;

use lucatume\WPBrowser\TestCase\WPTestCase;
use A8C\SpecialProjects\ScraperToWP\Service\Media;
use A8C\SpecialProjects\ScraperToWP\Service\Media_Store;

/**
 * @group media
 */
class Media_Store_Test extends WPTestCase {

	/**
	 * The store persists a URL => ID mapping and reads it back.
	 */
	public function test_store_persists_and_reads_back(): void {
		$url = 'https://example.test/images/' . uniqid( 'a', true ) . '.jpg';

		$store = new Media_Store();
		$this->assertNull( $store->get( $url ) );

		$store->set( $url, 4242 );
		$this->assertSame( 4242, $store->get( $url ) );

		// A fresh instance reads the same persisted value (file-backed).
		$fresh = new Media_Store();
		$this->assertSame( 4242, $fresh->get( $url ) );
	}

	/**
	 * Media::from_url() returns the stored ID without uploading when cached.
	 */
	public function test_from_url_returns_cached_id_without_uploading(): void {
		$url = 'https://example.test/images/' . uniqid( 'b', true ) . '.jpg';

		// Pre-seed the shared store; from_url() must short-circuit to this ID.
		( new Media_Store() )->set( $url, 9001 );

		// If this hit the uploader it would call WP-CLI and fail in this context;
		// returning 9001 proves the dedup path ran first.
		$this->assertSame( 9001, Media::from_url( $url ) );
	}
}
