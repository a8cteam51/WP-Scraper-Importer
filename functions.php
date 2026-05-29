<?php declare( strict_types=1 );

use A8C\SpecialProjects\ScrapperToWP\Plugin;

defined( 'ABSPATH' ) || exit;

// region META

/**
 * Returns the plugin's main class instance.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Plugin
 */
function a8scp_scrapper_to_wp_get_plugin_instance(): Plugin {
	return Plugin::get_instance();
}

// endregion
