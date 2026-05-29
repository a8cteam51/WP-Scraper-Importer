<?php
/**
 * A sample custom content mapper used in tests.
 *
 * Demonstrates the intended extension point: a clone extends Default_Content_Mapper
 * and overrides only the methods it needs. Used to prove the scrape -> custom-map
 * stack works end to end.
 */

declare( strict_types=1 );

namespace Tests\Support;

use A8C\SpecialProjects\ScrapperToWP\Mapper\Default_Content_Mapper;

/**
 * Custom mapper fixture.
 */
class Sample_Content_Mapper extends Default_Content_Mapper {

	/**
	 * Pull the title from the <h1> rather than the <title> tag.
	 *
	 * @return string
	 */
	public function get_title(): string {
		// --- WP HTML API example ---
		// Tag_Processor only stops on tags, so to read an element's inner text we
		// use WP_HTML_Processor and accumulate the #text tokens between <h1>…</h1>.
		$html = $this->content_scrapper->get_content();

		$processor = method_exists( '\WP_HTML_Processor', 'create_full_parser' )
			? \WP_HTML_Processor::create_full_parser( $html )
			: \WP_HTML_Processor::create_fragment( $html );

		if ( null !== $processor ) {
			$text  = '';
			$in_h1 = false;
			while ( $processor->next_token() ) {
				if ( '#tag' === $processor->get_token_type() && 'H1' === $processor->get_tag() ) {
					if ( $processor->is_tag_closer() ) {
						break;
					}
					$in_h1 = true;
					continue;
				}
				if ( $in_h1 && '#text' === $processor->get_token_type() ) {
					$text .= $processor->get_modifiable_text();
				}
			}
			$text = trim( $text );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return parent::get_title();
	}

	/**
	 * Extract the main content.
	 *
	 * --- DOMDocument example ---
	 * The same job the parent does, shown explicitly here to demonstrate the
	 * DOM approach alongside the WP HTML API used in get_title().
	 *
	 * @return string
	 */
	public function get_content(): string {
		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$dom->loadHTML( $this->content_scrapper->get_content() );
		libxml_clear_errors();

		$main = $dom->getElementsByTagName( 'main' )->item( 0 );
		if ( null === $main ) {
			return parent::get_content();
		}

		$inner = '';
		foreach ( $main->childNodes as $child ) { // phpcs:ignore
			$inner .= (string) $dom->saveHTML( $child );
		}

		return trim( $inner );
	}

	/**
	 * Use a custom post type.
	 *
	 * @return string
	 */
	public function get_post_type(): string {
		return 'sample_doc';
	}

	/**
	 * Import as a draft.
	 *
	 * @return string
	 */
	public function get_post_status(): string {
		return 'draft';
	}

	/**
	 * Use a fixed original publication date.
	 *
	 * @return string
	 */
	public function get_post_date(): string {
		return '2020-01-02 03:04:05';
	}
}
