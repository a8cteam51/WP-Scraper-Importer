<?php
/**
 * Post Inserter class.
 *
 * This class is responsible for inserting posts into WordPress.
 *
 * @package     A8CSP_Scrapper_to_WP
 * @subpackage  Command
 * @since       1.0.0
 * @version     1.0.0
 */

declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP\Action;

use RuntimeException;
use InvalidArgumentException;
use WP_CLI;
use WP_Post;
use WP_Error;
use WP_Term;
use A8C\SpecialProjects\ScrapperToWP\Service\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Class for inserting posts into WordPress.
 */
class Post_Inserter {

	/**
	 * The post data to be inserted.
	 *
	 * @var array{
	 *  title: string,
	 *  content: string,
	 *  status: string,
	 *  author: int,
	 *  taxonomies: array<string, string[]>,
	 *  meta: array<string, mixed>
	 * }
	 */
	protected $post_data;

	/**
	 * Post Type
	 *
	 * @var string
	 */
	protected $post_type = 'post';

	/**
	 * Parent post ID.
	 *
	 * @var int
	 */
	protected $parent_id = 0;

	/**
	 * The post instance or WP_Error after creation or update.
	 *
	 * @var WP_Post|WP_Error
	 */
	protected $post_instance;

	/**
	 * The import log.
	 *
	 * @var array<int, array{type: string, message: string}>
	 */
	protected $import_log = array();

	/**
	 * If errors are so severe that the import should be stopped.
	 *
	 * @var boolean
	 */
	protected $has_fatal_errors = false;

	/**
	 * Constructor.
	 *
	 * @param string                  $title      The title of the post.
	 * @param string                  $content    The content of the post.
	 * @param string                  $status     The status of the post (e.g., 'publish', 'draft').
	 * @param integer                 $author     The ID of the author.
	 * @param array<string, string[]> $taxonomies An associative array of taxonomies and their terms.
	 * @param array<string, mixed>    $meta       An associative array of post meta data.
	 */
	public function __construct( string $title = '', string $content = '', string $status = 'publish', int $author = 0, array $taxonomies = array(), array $meta = array() ) {

		$this->post_data = array(
			'title'      => sanitize_text_field( $title ),
			'content'    => wp_kses_post( $content ),
			'status'     => sanitize_key( $status ),
			'author'     => absint( $author ),
			'taxonomies' => $taxonomies,
			'meta'       => $meta,                // YOU WILL NEED TO SANITIZE THIS BASED ON YOUR NEEDS.
		);
	}

	/**
	 * Add to the import log.
	 *
	 * @param string $message The message to log.
	 * @param string $type    The type of the log entry (e.g., 'info', 'error').
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException If the log type is invalid.
	 */
	public function add_to_import_log( string $message, string $type = 'info' ): self {
		if ( ! in_array( $type, array( 'info', 'error', 'warning' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid log type. Allowed types are: info, error, warning.' );
		}

		$this->import_log[] = array(
			'type'    => $type,
			'message' => sanitize_text_field( $message ),
		);

		return $this;
	}

	/**
	 * Log an error.
	 *
	 * @param string  $message The error message to log.
	 * @param string  $process The process that caused the error.
	 * @param boolean $fatal   If the error is fatal and should stop the import.
	 *
	 * @return self
	 */
	public function log_error( string $message, string $process = 'import', bool $fatal = false ): self {
		$this->add_to_import_log( sprintf( 'Error in %s: %s', $process, $message ), 'error' );

		$this->has_fatal_errors = $fatal;

		return $this;
	}

	/**
	 * Checks if there has been any errors during the import.
	 *
	 * @return boolean
	 */
	public function has_errors(): bool {
		return $this->has_fatal_errors;
	}

	/**
	 * Get the import log.
	 *
	 * @return array<int, array{type: string, message: string}>
	 */
	public function get_import_log(): array {
		return $this->import_log;
	}

	/**
	 * Set the post type.
	 *
	 * @param string $post_type The post type to set.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException If the post type is empty.
	 */
	public function set_post_type( string $post_type ): self {
		if ( '' === $post_type ) {
			throw new InvalidArgumentException( 'Post type cannot be empty.' );
		}

		$this->post_type = sanitize_text_field( $post_type );

		return $this;
	}

	/**
	 * Set the parent post ID.
	 *
	 * @param integer $parent_id The parent post ID to set.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException If the parent ID is not a valid integer.
	 */
	public function set_parent_id( int $parent_id ): self {
		if ( $parent_id < 0 ) {
			throw new InvalidArgumentException( 'Parent ID must be a valid non-negative integer.' );
		}

		$this->parent_id = $parent_id;

		return $this;
	}

	/**
	 * Create as a new post.
	 *
	 * @return self
	 */
	public function create() {
		// Create the post data array.
		$post_data = array(
			'post_title'   => $this->post_data['title'],
			'post_content' => $this->post_data['content'],
			'post_status'  => $this->post_data['status'],
			'post_author'  => $this->post_data['author'],
			'post_type'    => $this->post_type,
			'post_parent'  => $this->parent_id, // Set the parent ID if provided.
		);

		// Insert the post into the database.
		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			$this->post_instance = $post_id; // Store the error in the post instance.
			return $this;
		}

		// Get the post instance.
		$found_post = get_post( $post_id );
		if ( ! $found_post instanceof \WP_Post ) {
			$this->log_error( 'Failed to retrieve the post instance after insertion.', 'post_insertion', true );
			// If this is a wp Error, we return it.
			if ( is_wp_error( $found_post ) ) {
				return $this;
			}

			$this->post_instance = new \WP_Error( 'post_insertion_failed', 'Failed to retrieve the post instance after insertion.' );
		} else {
			$this->post_instance = $found_post;

			$this->add_to_import_log(
				sprintf( 'Post "%s" created with ID %d.', $this->post_instance->post_title, $this->post_instance->ID ),
				'info'
			);
		}

		return $this;
	}

	/**
	 * Update an existing post.
	 *
	 * @param integer $post_id The ID of the post to update.
	 *
	 * @return self
	 */
	public function update( int $post_id ) {
		// If we have a post id less than 1, we cannot update.
		if ( 1 > $post_id ) {
			$this->log_error( 'Invalid post ID provided for update.', 'post_update', true );
			return $this;
		}

		// Prepare the post data for update.
		$post_data = array(
			'ID'           => $post_id,
			'post_title'   => $this->post_data['title'],
			'post_content' => $this->post_data['content'],
			'post_status'  => $this->post_data['status'],
			'post_author'  => $this->post_data['author'],
			'post_type'    => $this->post_type,
			'post_parent'  => $this->parent_id, // Set the parent ID if provided.
		);

		// Update the post in the database.
		$post_id = wp_update_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			$this->post_instance = $post_id; // Store the error in the post instance.
			return $this;
		}

		// Get the updated post instance.
		$found = get_post( $post_id );

		if ( ! $found instanceof \WP_Post ) {
			$this->log_error( 'Failed to retrieve the post instance after update.', 'post_update', true );
			// If this is a wp Error, we return it.
			if ( is_wp_error( $found ) ) {
				$this->post_instance = $found;
			} else {
				$this->post_instance = new \WP_Error( 'post_update_failed', 'Failed to retrieve the post instance after update.' );
			}

			return $this;
		} else {
			$this->post_instance = $found;
		}

		return $this;
	}

	/**
	 * Set the terms for the post's taxonomies.
	 *
	 * @return self
	 */
	public function set_taxonomies(): self {
		// If we dont have a post instance, we cannot set the taxonomies.
		if ( ! $this->post_instance instanceof \WP_Post ) {
			$this->log_error( 'Post instance is not set or invalid.', 'taxonomy_set', false );
			return $this;
		}

		// Loop through the taxonomies and set the terms.
		foreach ( $this->post_data['taxonomies'] as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$this->add_to_import_log(
					sprintf( 'Taxonomy "%s" does not exist. Skipping.', $taxonomy ),
					'warning'
				);
				continue; // Skip if the taxonomy does not exist.
			}

			// Iterate through each term and find or create it.
			foreach ( $terms as $term ) {
				$term = $this->find_or_create_term( $taxonomy, $term );
				// If we have a valid term, set it for the post.
				if ( ! is_wp_error( $term ) && $term instanceof \WP_Term ) {
					// Set the term for the post.
					wp_set_post_terms( $this->post_instance->ID, array( $term->term_id ), $taxonomy, true );
					$this->add_to_import_log(
						sprintf( 'Set term "%s" for taxonomy "%s" on post ID %d.', $term->name, $taxonomy, $this->post_instance->ID ),
						'info'
					);

					/*********************************************************
					 * If you want to set the term meta, you can do it here *
					 *
					 * Example: update_term_meta( $term->term_id, 'meta_key', 'meta_value' );
					 */
				}
			}
		}

		return $this;
	}

	/**
	 * Find or create a term in a taxonomy.
	 *
	 * @param string $taxonomy The taxonomy to find or create the term in.
	 * @param string $term     The term to find or create.
	 *
	 * @return WP_Term|WP_Error
	 */
	public function find_or_create_term( string $taxonomy, string $term ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$this->add_to_import_log(
				sprintf( 'Taxonomy "%s" does not exist. Cannot find or create term "%s".', $taxonomy, $term ),
				'error'
			);
			return new \WP_Error( 'invalid_taxonomy', 'The specified taxonomy does not exist.' );
		}

		// Check if the term exists.
		$term_instance = get_term_by( 'name', $term, $taxonomy );
		if ( $term_instance instanceof \WP_Term ) {
			return $term_instance; // Return the existing term.
		}

		// If the term does not exist, create it.
		$term_inserted = wp_insert_term( sanitize_text_field( $term ), $taxonomy );
		if ( is_wp_error( $term_inserted ) ) {
			$this->add_to_import_log(
				sprintf(
					'Failed to create term "%s" in taxonomy "%s": %s',
					$term,
					$taxonomy,
					(string) $term_inserted->get_error_message()
				),
				'error'
			);
			return $term_inserted; // Return the error if term creation failed.
		}

		// Get the newly created term.
		$new_term = get_term( $term_inserted['term_id'], $taxonomy );
		if ( ! $new_term instanceof \WP_Term ) {
			$this->add_to_import_log(
				sprintf( 'Failed to retrieve the newly created term "%s" in taxonomy "%s".', $term, $taxonomy ),
				'error'
			);
			return new \WP_Error( 'term_retrieval_failed', 'Failed to retrieve the newly created term.' );
		}

		return $new_term;
	}

	/**
	 * Set the meta data for the post.
	 *
	 * @return self
	 */
	public function set_meta(): self {
		// If we dont have a post instance, we cannot set the meta.
		if ( ! $this->post_instance instanceof \WP_Post ) {
			$this->log_error( 'Post instance is not set or invalid.', 'meta_set', false );
			return $this;
		}

		// Loop through the meta data and set it.
		foreach ( $this->post_data['meta'] as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				$this->add_to_import_log(
					sprintf( 'Meta key "%s" is invalid. Skipping.', $key ),
					'warning'
				);
				continue; // Skip if the meta key is invalid.
			}

			update_post_meta( $this->post_instance->ID, sanitize_key( $key ), $value );
			$this->add_to_import_log(
				sprintf( 'Set meta "%s" for post ID %d.', sanitize_key( $key ), $this->post_instance->ID ),
				'info'
			);
		}

		return $this;
	}

	/**
	 * Perform an operation with the post.
	 *
	 * @param callable $callback The callback to perform with the post instance.
	 *
	 * @return self
	 *
	 * @throws RuntimeException If the post instance is not set or invalid.
	 */
	public function with_post( callable $callback ): self {
		if ( ! $this->post_instance instanceof \WP_Post && ! $this->post_instance instanceof \WP_Error ) {
			throw new RuntimeException( 'Post instance is not set or invalid.' );
		}

		$this->post_instance = $callback( $this->post_instance );

		return $this;
	}

	/**
	 * Set a featured image from URL.
	 *
	 * @param string               $image_url The URL of the image to set as featured image.
	 * @param array<string, mixed> $args      Additional arguments for the Media service.
	 *
	 * @return self
	 */
	public function set_featured_image( string $image_url, array $args ): self {
		if ( ! $this->post_instance instanceof \WP_Post ) {
			$this->log_error( 'Post instance is not set or invalid.', 'featured_image_set', false );
			return $this;
		}

		try {
			// Attempt to upload the image from the URL.
			$attachment_id = Media::from_url( $image_url, $args );
			if ( null === $attachment_id ) {
				$this->log_error( 'Failed to upload image from URL: No attachment ID returned.', 'featured_image_set' );
				return $this;
			}
		} catch ( InvalidArgumentException $e ) {
			// Catch invalid URL.
			$this->log_error( 'Invalid image URL provided for featured image: ' . $e->getMessage(), 'featured_image_set' );
			return $this;
		} catch ( RuntimeException $e ) {
			// Catch upload failure.
			$this->log_error( 'Failed to upload image from URL: ' . $e->getMessage(), 'featured_image_set' );
			return $this;
		}

		// If we dont have a valid attached image ID, log an error and return.
		if ( $attachment_id <= 0 ) {
			$this->log_error( 'Invalid attachment ID returned from image upload.', 'featured_image_set', true );
			return $this;
		}

		// Set as featured image.
		$result = set_post_thumbnail( $this->post_instance->ID, $attachment_id );

		if ( true === $result ) {
			// If the result is true, the featured image was set successfully.
			$this->add_to_import_log(
				sprintf( 'Set featured image for post ID %d from URL %s.', $this->post_instance->ID, esc_url( $image_url ) ),
				'info'
			);
		} else {
			// If the result is false, there was an error setting the featured image.
			$this->log_error( 'Failed to set featured image for post ID ' . $this->post_instance->ID, 'featured_image_set', true );
		}
		return $this;
	}

	/**
	 * Check if we have a post instance.
	 *
	 * @return boolean
	 */
	public function has_post_instance(): bool {
		return $this->post_instance instanceof \WP_Post;
	}
}
