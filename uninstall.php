<?php
/** Remove site-local data only when an administrator opted in. */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function taxonomy_tidy_remove_site_data() {
	global $wpdb;

	$settings = get_option( 'taxonomy_tidy_settings', array() );
	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- This destructive cleanup runs only after the site administrator opted in to delete local plugin data during uninstall.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'taxonomy_tidy_relationships' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- This destructive cleanup runs only after the site administrator opted in to delete local plugin data during uninstall.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'taxonomy_tidy_operations' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- This destructive cleanup runs only after the site administrator opted in to delete local plugin data during uninstall.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'taxonomy_tidy_batches' );
	delete_option( 'taxonomy_tidy_schema_version' );
	delete_option( 'taxonomy_tidy_settings' );
}

if ( is_multisite() ) {
	$taxonomy_tidy_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $taxonomy_tidy_site_ids as $taxonomy_tidy_site_id ) {
		switch_to_blog( (int) $taxonomy_tidy_site_id );
		taxonomy_tidy_remove_site_data();
		restore_current_blog();
	}
} else {
	taxonomy_tidy_remove_site_data();
}
