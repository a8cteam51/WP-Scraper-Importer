<?php
/**
 * Test scaffold configuration.
 *
 * This mirrors exactly what a real clone adds to its own plugin.php, right after
 * the `vendor/autoload.php` require: it registers a URL provider and a custom
 * content mapper on WP_Scraper. Nothing else — no functions, no hooks, no HTTP
 * mocking (that's the test's job, not the plugin's).
 *
 * In a real clone the provider/mapper live in src/ (PSR-4). Here they're test
 * doubles under tests/Support, but the registration is identical.
 *
 * @package A8CSP_Scrapper_to_WP
 */

declare( strict_types=1 );

use A8C\SpecialProjects\ScrapperToWP\WP_Scraper;
use Tests\Support\Test_URL_Provider;
use Tests\Support\Sample_Content_Mapper;

defined( 'ABSPATH' ) || exit;

WP_Scraper::set_url_provider( Test_URL_Provider::class );
WP_Scraper::set_content_mapper( Sample_Content_Mapper::class );
WP_Scraper::set_default_user( 1 );
WP_Scraper::set_post_type( 'post' );
WP_Scraper::set_post_status( 'publish' );
