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

/**
 * Get the Database connection.
 *
 * @return \A8C\SpecialProjects\ScrapperToWP\Service\Database
 */
function a8scp_scrapper_to_wp_get_database_service(): \A8C\SpecialProjects\ScrapperToWP\Service\Database {
	$uploads = wp_upload_dir();
	$base    = trailingslashit( $uploads['basedir'] ) . 'wbm-import/';
	$db_path = $base . 'db.sqlite';
	if ( ! \file_exists( $db_path ) ) {
		$database = new \A8C\SpecialProjects\ScrapperToWP\Service\Database( $db_path );

			// Create the database schema if it doesn't exist.

			// Hash Page URL table.
			$create_table_sql = 'CREATE TABLE IF NOT EXISTS page_url_hashes (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				page_url TEXT NOT NULL,
				url_hash TEXT NOT NULL UNIQUE,
				post_id TEXT NOT NULL
			);';
			$database->query( $create_table_sql );

			// Media URL Hash table.
			$create_table_sql = 'CREATE TABLE IF NOT EXISTS media_url_hashes (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				media_url TEXT NOT NULL,
				media_id TEXT NOT NULL
			);';
			$database->query( $create_table_sql );
	} else {
		$database = new \A8C\SpecialProjects\ScrapperToWP\Service\Database( $db_path );
	}
		return $database;
}

// endregion
