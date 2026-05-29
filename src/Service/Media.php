<?php
/**
 * Media service class.
 *
 * This class is responsible for handling media uploads and featured images.
 *
 * @package     A8CSP_Scrapper_to_WP
 * @subpackage  Service
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP\Service;

use RuntimeException;
use InvalidArgumentException;
use WP_CLI;
use WP_Post;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Class for handling media operations.
 */
class Media {

	/**
	 * Cached media store used to dedupe uploads.
	 *
	 * @var Media_Store|null
	 */
	private static $media_store;

	/**
	 * Get the (cached) media store.
	 *
	 * @return Media_Store
	 */
	private static function media_store(): Media_Store {
		if ( null === self::$media_store ) {
			self::$media_store = new Media_Store();
		}

		return self::$media_store;
	}

	/**
	 * Upload image from URL.
	 *
	 * @param string               $image_url The URL of the image to upload.
	 * @param array<string, mixed> $args      Additional arguments for the media import command.
	 *
	 * @return integer|null
	 *
	 * @throws InvalidArgumentException If the image URL is invalid.
	 * @throws RuntimeException If the upload fails.
	 */
	public static function from_url( string $image_url, array $args = array() ): ?int {

		if ( '' === $image_url ||
			filter_var( $image_url, FILTER_VALIDATE_URL ) === false ) {
			throw new InvalidArgumentException( 'Invalid image URL provided for upload.' );
		}

		// If we have already imported this URL, return the stored attachment ID.
		$store    = self::media_store();
		$existing = $store->get( $image_url );
		if ( null !== $existing ) {
			return $existing;
		}

		// List of allowed WP-CLI flags for media import
		$allowed_args = array(
			'post_name',
			'file_name',
			'title',
			'caption',
			'alt',
			'desc',
			'skip-copy',
			'preserve-filetime',
			'porcelain', // only used internally, but we always include it
		);
		// Build base command
		$command = sprintf(
			'media import %s --porcelain',
			escapeshellarg( $image_url )
		);
		// Sanitize and include only allowed arguments
		foreach ( $args as $key => $value ) {
			$key = sanitize_key( $key );

			// Convert underscores to dashes (in case user passes 'skip_copy' etc.)
			$key_dash = str_replace( '_', '-', $key );

			if ( in_array( $key_dash, $allowed_args, true ) ) {
				if ( is_bool( $value ) && $value ) {
					$command .= " --$key_dash"; // flag without value
				} elseif ( is_scalar( $value ) ) {
					$command .= " --$key_dash=" . escapeshellarg( (string) $value );
				}
			}
		}
		try {
			// Run the command and capture attachment ID
			$attachment_id = WP_CLI::runcommand(
				$command,
				array(
					'return'     => true,
					'exit_error' => true,
				)
			);

			$attachment_id = trim( $attachment_id );

			if ( ! is_numeric( $attachment_id ) ) {
				throw new RuntimeException( 'Failed to import image from URL. Command output: ' . $attachment_id );
			}

			$attachment_id = (int) $attachment_id;

			// Persist the URL => attachment ID mapping so we don't re-import it.
			$store->set( $image_url, $attachment_id );

			return $attachment_id;
		} catch ( \Exception $e ) {
			throw new RuntimeException( 'Failed to upload image from URL: ' . esc_attr( $e->getMessage() ) );
		}
	}
}
