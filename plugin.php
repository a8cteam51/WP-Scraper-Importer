<?php
/**
 * The A8CSP Plugin Scaffold bootstrap file.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @package     A8C\SpecialProjects\Plugins
 * @author      WordPress.com Special Projects
 * @license     GPL-3.0-or-later
 *
 * @noinspection    ALL
 *
 * @wordpress-plugin
 * Plugin Name:             A8CSP Scrapper to WP
 * Plugin URI:              https://wpspecialprojects.wordpress.com
 * Description:             A plugin that can take a collection of URLs, scrape the content and import them. Please note this is a starting point and will need to be edited for each site its used on!!!
 * Version:                 1.0.0
 * Requires at least:       6.7
 * Tested up to:            6.7
 * Requires PHP:            8.3
 * Author:                  WordPress.com Special Projects
 * Author URI:              https://wpspecialprojects.wordpress.com
 * License:                 GPL v3 or later
 * License URI:             https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:             a8csp-scaffold
 * Domain Path:             /languages
 * WC requires at least:    9.5
 * WC tested up to:         9.5
 **/

defined( 'ABSPATH' ) || exit;

// Define plugin constants.
define( 'A8CSP_SCRAPPER_TO_WP_BASENAME', plugin_basename( __FILE__ ) );
define( 'A8CSP_SCRAPPER_TO_WP_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'A8CSP_SCRAPPER_TO_WP_DIR_URL', plugin_dir_url( __FILE__ ) );

// Load the rest of the bootstrap functions.
require_once A8CSP_SCRAPPER_TO_WP_DIR_PATH . '/functions-bootstrap.php';
// Load plugin translations so they are available even for the error admin notices.
add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			a8scp_scrapper_to_wp_get_plugin_metadata( 'TextDomain' ),
			false,
			dirname( A8CSP_SCRAPPER_TO_WP_BASENAME ) . a8scp_scrapper_to_wp_get_plugin_metadata( 'DomainPath' )
		);
	}
);

// Declare compatibility with WC features.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// Load the autoloader.
if ( ! is_file( A8CSP_SCRAPPER_TO_WP_DIR_PATH . '/vendor/autoload.php' ) ) {
	a8scp_scrapper_to_wp_output_requirements_error( new WP_Error( 'missing_autoloader' ) );
	return;
}
require_once A8CSP_SCRAPPER_TO_WP_DIR_PATH . '/vendor/autoload.php';

// Bootstrap the plugin (maybe)!
define( 'A8CSP_SCRAPPER_TO_WP_REQUIREMENTS', a8scp_scrapper_to_wp_validate_requirements() );

if ( is_wp_error( A8CSP_SCRAPPER_TO_WP_REQUIREMENTS ) ) {
	a8scp_scrapper_to_wp_output_requirements_error( A8CSP_SCRAPPER_TO_WP_REQUIREMENTS );
} else {
	require_once A8CSP_SCRAPPER_TO_WP_DIR_PATH . '/functions.php';
	add_action( 'plugins_loaded', array( a8scp_scrapper_to_wp_get_plugin_instance(), 'maybe_initialize' ) );
}



// Register AJAX action (no auth needed)
add_action('init', function () {
    if (isset($_GET['action']) && $_GET['action'] === 'multi_redirect') {
        multi_redirect_handler();
        exit;
    }
});

function multi_redirect_handler() {
    $base_url = isset($_GET['url']) ? esc_url_raw($_GET['url']) : '';
    $count    = isset($_GET['count']) ? intval($_GET['count']) : 0;
    $step     = isset($_GET['step']) ? intval($_GET['step']) : 1;
    $param    = isset($_GET['param']) ? sanitize_text_field($_GET['param']) : '';

    if (empty($base_url) || $count <= 0 || $step > $count) {
        wp_die('Invalid parameters.');
    }

    // Build next redirect URL
    $query = array(
        'step'  => $step,
        'param' => $param
    );
    $redirect_url = add_query_arg($query, $base_url);

    // Build self URL to trigger next redirect
    $next_step = $step + 1;
    if ($next_step <= $count) {
        $self_url = add_query_arg(array(
            'action' => 'multi_redirect',
            'url'    => $base_url,
            'count'  => $count,
            'step'   => $next_step,
            'param'  => $param,
        ), admin_url('admin-ajax.php'));

        // First redirect to target with param, then redirect back to self
        wp_redirect($self_url);
        exit;
    } else {
		// Set the headers.
		$headers = array(
			'Content-Type' => 'text/html; charset=UTF-8',
		);

		// set the headers.
		foreach ( $headers as $key => $value ) {
			header( sprintf( '%s: %s', $key, $value ) );
		}
		// Set the response code.
		http_response_code(200);

		// Echo the content.
		$content = sprintf(
			'<html><head><title>Final Redirect</title></head><body><h1>Final Redirect</h1><p>Redirected to: %s</p><p>Final Param: %s</p>',
			esc_html($base_url),
			esc_html($param)
		);

		// Add the details to the content.
		$content .= sprintf('<p>Step: %d of %d</p></body></html>', $step, $count);

		// Output the content


		echo $content;
        // Final step: redirect to last URL with final params
        // wp_redirect($redirect_url);
        exit;
    }
}