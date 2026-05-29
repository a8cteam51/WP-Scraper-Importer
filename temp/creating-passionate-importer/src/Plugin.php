<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\ScrapperToWP;

use A8C\SpecialProjects\ScrapperToWP\Command\Import_Command;
use A8C\SpecialProjects\ScrapperToWP\Command\Compile_Command;
use A8C\SpecialProjects\ScrapperToWP\Command\Import_Media_Command;
use A8C\SpecialProjects\ScrapperToWP\Command\Fix_Link_Command;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
class Plugin {
	// region FIELDS AND CONSTANTS


	// endregion

	// region MAGIC METHODS

	/**
	 * Plugin constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	protected function __construct() {
		/* Empty on purpose. */
	}

	/**
	 * Prevent cloning.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function __clone() {
		/* Empty on purpose. */
	}

	/**
	 * Prevent unserializing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function __wakeup() {
		/* Empty on purpose. */
	}

	// endregion

	// region METHODS

	/**
	 * Returns the singleton instance of the plugin.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Plugin
	 */
	public static function get_instance(): self {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Returns true if all the plugin's dependencies are met.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  true|\WP_Error
	 */
	public function is_active(): bool|\WP_Error {
		return true;
	}

	/**
	 * Initializes the plugin components.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function initialize(): void {
		// IF WP CLI is available, register the commands.
		if ( \defined( 'WP_CLI' ) && WP_CLI ) {
			$this->register_commands();
		}
	}

	// endregion

	// region HOOKS

	/**
	 * Registers the plugin's commands.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function register_commands(): void {
		if ( ! \class_exists( 'WP_CLI' ) ) {
			return;
		}

		// Register the import command.
		\WP_CLI::add_command( 'scrapper-to-wp import', Import_Command::class, );

		// Register the compile command.
		\WP_CLI::add_command( 'scrapper-to-wp compile', Compile_Command::class, );

		// Register the import media command.
		\WP_CLI::add_command( 'scrapper-to-wp import-media', Import_Media_Command::class, );

		// Register the fix links command.
		\WP_CLI::add_command( 'scrapper-to-wp fix-links', Fix_Link_Command::class, );
	}

	/**
	 * Initializes the plugin components if WooCommerce is activated.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function maybe_initialize(): void {
		$is_active = $this->is_active();
		if ( is_wp_error( $is_active ) ) {
			a8scp_scrapper_to_wp_output_requirements_error( $is_active );
			return;
		}

		$this->initialize();
	}

	// endregion
}
