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

// region OTHERS

$a8scp_scrapper_to_wp_files = glob( constant( 'A8CSP_SCRAPPER_TO_WP_DIR_PATH' ) . 'includes/*.php' );
if ( false !== $a8scp_scrapper_to_wp_files ) {
	foreach ( $a8scp_scrapper_to_wp_files as $a8scp_scrapper_to_wp_file ) {
		if ( 1 === preg_match( '#/includes/_#i', $a8scp_scrapper_to_wp_file ) ) {
			continue; // Ignore files prefixed with an underscore.
		}

		require_once $a8scp_scrapper_to_wp_file;
	}
}

// endregion
