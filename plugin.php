<?php
/**
 * Plugin Name:       WP Theme JSON Editor
 * Description:       A form-driven visual editor for WordPress theme.json files.
 * Plugin URI:        https://github.com/s3rgiosan/wp-theme-json-editor
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Version:           1.0.0
 * Author:            Sérgio Santos
 * Author URI:        https://s3rgiosan.dev/?utm_source=wp-plugins&utm_medium=wp-theme-json-editor&utm_campaign=author-uri
 * License:           GPL-3.0-only
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:        https://s3rgiosan.dev/
 * GitHub Plugin URI: https://github.com/s3rgiosan/wp-theme-json-editor
 * Text Domain:       wp-theme-json-editor
 * Domain Path:       /languages
 */

namespace S3S\WP\ThemeJSONEditor;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'S3S_THEME_JSON_EDITOR_VERSION', '1.0.0' );
define( 'S3S_THEME_JSON_EDITOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'S3S_THEME_JSON_EDITOR_URL', plugin_dir_url( __FILE__ ) );
define( 'S3S_THEME_JSON_EDITOR_BASENAME', plugin_basename( __FILE__ ) );
define( 'S3S_THEME_JSON_EDITOR_DIST_PATH', S3S_THEME_JSON_EDITOR_PATH . 'build/' );
define( 'S3S_THEME_JSON_EDITOR_DIST_URL', S3S_THEME_JSON_EDITOR_URL . 'build/' );

if ( file_exists( S3S_THEME_JSON_EDITOR_PATH . 'vendor/autoload.php' ) ) {
	require_once S3S_THEME_JSON_EDITOR_PATH . 'vendor/autoload.php';
}

if ( class_exists( PucFactory::class ) ) {
	PucFactory::buildUpdateChecker(
		'https://github.com/s3rgiosan/wp-theme-json-editor/',
		__FILE__,
		'wp-theme-json-editor'
	);
}

/**
 * Load the plugin.
 */
add_action(
	'plugins_loaded',
	function () {
		Plugin::get_instance()->setup();
	}
);
