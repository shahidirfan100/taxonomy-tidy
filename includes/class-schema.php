<?php
/** Per-site schema setup. */
namespace TaxonomyTidy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schema {
	const VERSION = '1.0.0';
	const OPTION  = 'taxonomy_tidy_schema_version';

	/** Install or upgrade tables for the current site only. */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$batches         = $wpdb->prefix . 'taxonomy_tidy_batches';
		$operations      = $wpdb->prefix . 'taxonomy_tidy_operations';
		$relationships   = $wpdb->prefix . 'taxonomy_tidy_relationships';

		$sql_batches = "CREATE TABLE {$batches} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_uuid char(36) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL DEFAULT 'pending',
			action_count smallint(5) unsigned NOT NULL DEFAULT 0,
			summary longtext NULL,
			created_at datetime NOT NULL,
			completed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY batch_uuid (batch_uuid),
			KEY status_created (status,created_at)
		) {$charset_collate};";

		$sql_operations = "CREATE TABLE {$operations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id bigint(20) unsigned NOT NULL,
			sequence_no smallint(5) unsigned NOT NULL DEFAULT 1,
			action varchar(16) NOT NULL,
			taxonomy varchar(32) NOT NULL,
			source_term_id bigint(20) unsigned NOT NULL,
			target_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
			snapshot longtext NOT NULL,
			affected_count bigint(20) unsigned NOT NULL DEFAULT 0,
			result varchar(24) NOT NULL DEFAULT 'pending',
			PRIMARY KEY  (id),
			UNIQUE KEY batch_sequence (batch_id,sequence_no),
			KEY batch_id (batch_id),
			KEY source_taxonomy (source_term_id,taxonomy)
		) {$charset_collate};";

		$sql_relationships = "CREATE TABLE {$relationships} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_id bigint(20) unsigned NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			term_order int(11) NOT NULL DEFAULT 0,
			had_target tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_object (operation_id,object_id),
			KEY object_id (object_id)
		) {$charset_collate};";

		dbDelta( $sql_batches );
		dbDelta( $sql_operations );
		dbDelta( $sql_relationships );
		$expected_tables = array( $batches, $operations, $relationships );
		foreach ( $expected_tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify the actual table after dbDelta before marking this schema complete.
			$found_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $table !== $found_table ) {
				return false;
			}
		}
		update_option( self::OPTION, self::VERSION, false );
		return true;
	}

	/** Ensure this site has the current schema, including on network-activated installs. */
	public static function maybe_upgrade() {
		if ( self::VERSION !== get_option( self::OPTION ) ) {
			self::install();
		}
	}
}
