<?php
/**
 * Plugin Name:     Substack Importer
 * Plugin URI:      https://github.com/wordpress/substack-importer
 * Description:     A plugin that lets you import content from Substack to your WordPress site
 * Author:          wordpressdotorg
 * Text Domain:     substack-importer
 * Version:         1.2.0
 * Requires PHP:    7.4
 * Tested up to:    6.9
 *
 * @package         SubstackImporter
 */

use SubstackImporter\Importer_Admin;


defined( 'ABSPATH' ) || exit;

/** Before the plugin is activated, check if the wxr-generator and wordpress-import plugins are loaded. */
register_activation_hook( __FILE__, 'child_plugin_activate' );
function child_plugin_activate() {

	$error_msg    = __( 'Sorry but this plugin requires the %s plugin to be installed and active.', 'substack-importer' );
	$plugins_link = '<a href="' . admin_url( 'plugins.php' ) . '">' . __( '&laquo; Return to plugins', 'substack-importer' ) . '</a>';

	if ( ! is_plugin_active( 'wordpress-importer/wordpress-importer.php' ) && current_user_can( 'activate_plugins' ) ) {
		wp_die( sprintf( $error_msg, 'WordPress Importer' ) . '<br>' . $plugins_link );
	}
}

function substack_importer_init() {

	require __DIR__ . '/vendor/autoload.php';

	load_plugin_textdomain( 'substack-importer' );

	$importer = new Importer_Admin();

	// Register the Ajax action that handles fetching additional post data through the Substack API
	add_action( 'wp_ajax_substack_progress', array( $importer, 'progress' ) );

	if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
		return;
	}

	register_importer(
		'substack',
		'Substack',
		__( 'Import content from a Substack newsletter', 'substack-importer' ),
		array(
			$importer,
			'run',
		)
	);

	if ( isset( $_GET['import'] ) && 'substack' === $_GET['import'] ) {
		wp_enqueue_script( 'substack-index-js', plugins_url( '/js/index.js', __FILE__ ) );
		wp_enqueue_style( 'substack-index-css', plugins_url( '/css/index.css', __FILE__ ) );
	}
}
add_action( 'admin_init', 'substack_importer_init' );
