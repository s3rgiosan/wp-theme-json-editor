<?php
/**
 * Fires when the plugin is deleted from the WordPress admin.
 *
 * Removes anything the plugin owns outside its own files: cached
 * theme.json schema transients (one per WP version) and the option
 * that indexes them. User Global Styles posts are intentionally
 * left untouched — they belong to the theme, not this plugin.
 *
 * @package S3S\WP\ThemeJSONEditor
 */

namespace S3S\WP\ThemeJSONEditor;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Drop every schema transient recorded in the plugin's index option,
 * then delete the index itself. Runs against whichever site is
 * currently switched in.
 *
 * @return void
 */
function clear_cache_on_uninstall() {

	$transient_prefix = 'wptje_schema_';
	$versions_option  = 'wptje_cached_schema_versions';

	$tracked = (array) get_option( $versions_option, [] );

	foreach ( $tracked as $version ) {
		if ( ! is_string( $version ) || '' === $version ) {
			continue;
		}
		delete_transient( $transient_prefix . $version );
	}

	delete_option( $versions_option );
}

if ( is_multisite() ) {
	$site_ids = get_sites( [ 'fields' => 'ids' ] );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		clear_cache_on_uninstall();
		restore_current_blog();
	}
} else {
	clear_cache_on_uninstall();
}
