<?php
/**
 * Integration tests for the scrape -> map stack.
 *
 * The remote HTTP call inside Content_Scrapper::follow_redirects() is mocked via
 * the `pre_http_request` filter, returning a sample HTML page. This lets us drive
 * the full scraper + mapper stack without any network access.
 */

declare( strict_types=1 );

namespace Tests\Integration;

use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Sample_Content_Mapper;
use A8C\SpecialProjects\ScrapperToWP\WP_Scraper;
use A8C\SpecialProjects\ScrapperToWP\Action\Content_Scrapper;
use A8C\SpecialProjects\ScrapperToWP\Mapper\Default_Content_Mapper;
use A8C\SpecialProjects\ScrapperToWP\Mapper\Abstract_Content_Mapper;

/**
 * @group scrapper
 */
class Content_Scrapper_Test extends WPTestCase {

	/**
	 * Remove any mocked HTTP responses after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Load the sample HTML fixture.
	 *
	 * @return string
	 */
	private function sample_html(): string {
		return (string) file_get_contents( codecept_data_dir( 'sample-page.html' ) );
	}

	/**
	 * Build a WP-HTTP-style response array.
	 *
	 * @param int                   $code    The HTTP status code.
	 * @param string                $body    The response body.
	 * @param array<string, string> $headers The response headers.
	 *
	 * @return array<string, mixed>
	 */
	private function http_response( int $code, string $body, array $headers = array() ): array {
		return array(
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Short-circuit wp_remote_get with the given responder.
	 *
	 * @param callable $responder Receives the request URL, returns a response array.
	 *
	 * @return void
	 */
	private function mock_http( callable $responder ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $parsed_args, $url ) use ( $responder ) {
				return $responder( $url );
			},
			10,
			3
		);
	}

	/**
	 * The scraper captures the body, url and scraped state.
	 */
	public function test_scrapes_sample_html(): void {
		$html = $this->sample_html();
		$this->mock_http( fn( $url ) => $this->http_response( 200, $html ) );

		$scrapper = new Content_Scrapper( 'https://example.test/sample' );
		$scrapper->process();

		$this->assertTrue( $scrapper->is_scraped() );
		$this->assertFalse( $scrapper->has_errors() );
		$this->assertStringContainsString( 'Sample Heading', $scrapper->get_content() );
		$this->assertSame( 'https://example.test/sample', $scrapper->get_url() );
		$this->assertFalse( $scrapper->had_redirected() );
	}

	/**
	 * The default mapper pulls the <title> and the <main> content only.
	 */
	public function test_default_mapper_extracts_title_and_main_content(): void {
		$html = $this->sample_html();
		$this->mock_http( fn( $url ) => $this->http_response( 200, $html ) );

		$scrapper = new Content_Scrapper( 'https://example.test/sample' );
		$scrapper->process();

		$mapper = new Default_Content_Mapper( $scrapper );

		$this->assertSame( 'Sample Imported Page', $mapper->get_title() );

		$content = $mapper->get_content();
		$this->assertStringContainsString( 'First paragraph of the sample content.', $content );
		// <main> only — header/footer must be excluded.
		$this->assertStringNotContainsString( 'Site navigation', $content );
		$this->assertStringNotContainsString( 'Footer text', $content );
	}

	/**
	 * A custom mapper overrides only what it needs and works in the same stack.
	 */
	public function test_custom_mapper_overrides(): void {
		$html = $this->sample_html();
		$this->mock_http( fn( $url ) => $this->http_response( 200, $html ) );

		$scrapper = new Content_Scrapper( 'https://example.test/sample' );
		$scrapper->process();

		$mapper = new Sample_Content_Mapper( $scrapper );

		// Title now comes from the <h1>, not the <title>.
		$this->assertSame( 'Sample Heading', $mapper->get_title() );
		$this->assertSame( 'sample_doc', $mapper->get_post_type() );
		$this->assertSame( 'draft', $mapper->get_post_status() );
		$this->assertSame( '2020-01-02 03:04:05', $mapper->get_post_date() );
		// Non-overridden behaviour still falls through to the default mapper.
		$this->assertStringContainsString( 'First paragraph of the sample content.', $mapper->get_content() );
	}

	/**
	 * WP_Scraper resolves the registered custom mapper class.
	 */
	public function test_wp_scraper_resolves_custom_mapper(): void {
		$this->mock_http( fn( $url ) => $this->http_response( 200, $this->sample_html() ) );

		$scrapper = new Content_Scrapper( 'https://example.test/sample' );
		$scrapper->process();

		WP_Scraper::set_content_mapper( Sample_Content_Mapper::class );
		$mapper = WP_Scraper::get_content_mapper( $scrapper );

		$this->assertInstanceOf( Sample_Content_Mapper::class, $mapper );
		$this->assertInstanceOf( Abstract_Content_Mapper::class, $mapper );
		$this->assertSame( 'sample_doc', $mapper->get_post_type() );
	}

	/**
	 * The scraper follows a redirect to the final page.
	 */
	public function test_follows_redirects(): void {
		$html = $this->sample_html();
		$this->mock_http(
			function ( $url ) use ( $html ) {
				if ( 'https://example.test/old' === $url ) {
					return $this->http_response( 301, '', array( 'location' => 'https://example.test/new' ) );
				}
				return $this->http_response( 200, $html );
			}
		);

		$scrapper = new Content_Scrapper( 'https://example.test/old' );
		$scrapper->process();

		$this->assertTrue( $scrapper->is_scraped() );
		$this->assertTrue( $scrapper->had_redirected() );
		$this->assertSame( 'https://example.test/new', $scrapper->get_final_url() );
		$this->assertStringContainsString( 'Sample Heading', $scrapper->get_content() );
	}
}
