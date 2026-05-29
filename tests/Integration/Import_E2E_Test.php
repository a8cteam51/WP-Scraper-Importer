<?php
/**
 * Full end-to-end test of the configured import pipeline.
 *
 * Loads tests/Support/mock-plugin.php — the same inline WP_Scraper config a real
 * clone puts in its plugin.php (register a URL provider + a custom content mapper)
 * — then drives the same steps the import command performs and proves a post is
 * created with the mapped data. The remote fetch is mocked here in the test (a
 * test concern), not in the plugin config.
 */

declare( strict_types=1 );

namespace Tests\Integration;

use WP_Post;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Test_URL_Provider;
use A8C\SpecialProjects\ScrapperToWP\WP_Scraper;
use A8C\SpecialProjects\ScrapperToWP\Action\Content_Scrapper;
use A8C\SpecialProjects\ScrapperToWP\Action\Post_Inserter;

/**
 * @group e2e
 */
class Import_E2E_Test extends WPTestCase {

	/**
	 * Apply the scaffold config (as a clone's plugin.php would) and mock the fetch.
	 */
	public function setUp(): void {
		parent::setUp();

		// Configure WP_Scraper exactly as a clone's plugin.php does.
		require_once dirname( __DIR__ ) . '/Support/mock-plugin.php';

		// Serve the sample page offline — a test concern, not the plugin's.
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( ! in_array( $url, Test_URL_Provider::$urls, true ) ) {
					return $pre;
				}
				return array(
					'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
					'body'     => (string) file_get_contents( dirname( __DIR__ ) . '/Support/Data/sample-page.html' ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Tear down the mocked HTTP responses.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * The configured stack imports the provider's URL into a mapped post.
	 */
	public function test_configured_stack_creates_mapped_post(): void {
		// 1. URLs come from the registered provider.
		$provider = WP_Scraper::get_url_provider();
		$provider->setup();
		$urls = $provider->get_urls();
		$provider->teardown();

		$this->assertNotEmpty( $urls, 'The configured provider should return URLs.' );

		$created_ids = array();

		foreach ( $urls as $url ) {
			// 2. Scrape (HTTP is mocked to serve the sample page).
			$scrapper = new Content_Scrapper( $url );
			$scrapper->process();
			$this->assertFalse( $scrapper->has_errors(), 'Scraping should not error.' );

			// 3. Map via the registered custom mapper.
			$mapper = WP_Scraper::get_content_mapper( $scrapper );

			$author = $mapper->get_author();
			if ( $author <= 0 ) {
				$author = WP_Scraper::get_default_user();
			}

			// 4. Insert the post.
			$inserter = new Post_Inserter(
				$mapper->get_title(),
				$mapper->get_content(),
				$mapper->get_post_status(),
				$author,
				$mapper->get_post_date(),
				$mapper->get_terms(),
				$mapper->get_post_meta()
			);
			$inserter->set_post_type( $mapper->get_post_type() );
			$inserter->create();

			$created_ids[] = $inserter->get_post_id();
		}

		// 5. Prove the post landed with the custom-mapped data.
		$this->assertCount( 1, $created_ids );
		$post_id = $created_ids[0];
		$this->assertIsInt( $post_id );

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'Sample Heading', $post->post_title );   // custom mapper: from <h1>
		$this->assertSame( 'sample_doc', $post->post_type );        // custom mapper override
		$this->assertSame( 'draft', $post->post_status );           // custom mapper override
		$this->assertSame( '2020-01-02 03:04:05', $post->post_date ); // custom mapper override
		$this->assertSame( 1, (int) $post->post_author );           // WP_Scraper default user
		$this->assertStringContainsString( 'First paragraph of the sample content.', $post->post_content );
	}
}
