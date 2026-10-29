<?php
/**
 * Smoke-check bulk preview object sampling inside the WordPress test site.
 */

$taxonomy = 'category';
$source_id = 0;
$target_id = 0;
$post_id   = 0;

try {
	$admin_ids = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	if ( empty( $admin_ids ) ) {
		throw new RuntimeException( 'No administrator user is available for the smoke check.' );
	}
	wp_set_current_user( (int) $admin_ids[0] );

	$suffix = wp_generate_password( 8, false, false );
	$source = wp_insert_term( 'TT Smoke Source ' . $suffix, $taxonomy );
	if ( is_wp_error( $source ) ) {
		throw new RuntimeException( $source->get_error_message() );
	}
	$source_id = (int) $source['term_id'];

	$target = wp_insert_term( 'TT Smoke Target ' . $suffix, $taxonomy );
	if ( is_wp_error( $target ) ) {
		throw new RuntimeException( $target->get_error_message() );
	}
	$target_id = (int) $target['term_id'];

	$inserted_post = wp_insert_post(
		array(
			'post_title'  => 'Taxonomy Tidy preview smoke ' . $suffix,
			'post_status' => 'publish',
			'post_type'   => 'post',
		),
		true
	);
	if ( is_wp_error( $inserted_post ) ) {
		throw new RuntimeException( $inserted_post->get_error_message() );
	}
	$post_id = (int) $inserted_post;

	wp_set_object_terms( $post_id, array( $source_id ), $taxonomy, false );

	$bulk_preview = \TaxonomyTidy\Audit::preview(
		'merge',
		$taxonomy,
		$source_id,
		$target_id,
		'',
		'',
		array( 'include_objects' => false )
	);
	$single_preview = \TaxonomyTidy\Audit::preview( 'merge', $taxonomy, $source_id, $target_id );

	if ( is_wp_error( $bulk_preview ) ) {
		throw new RuntimeException( $bulk_preview->get_error_message() );
	}
	if ( is_wp_error( $single_preview ) ) {
		throw new RuntimeException( $single_preview->get_error_message() );
	}
	if ( ! empty( $bulk_preview['objects'] ) ) {
		throw new RuntimeException( 'Bulk preview unexpectedly included affected objects.' );
	}
	if ( empty( $single_preview['objects'] ) ) {
		throw new RuntimeException( 'Single preview did not include its affected object sample.' );
	}
	if ( \TaxonomyTidy\Audit::impact_fingerprint( $bulk_preview ) !== \TaxonomyTidy\Audit::impact_fingerprint( $single_preview ) ) {
		throw new RuntimeException( 'Omitting object samples changed the impact fingerprint.' );
	}

	echo "Bulk preview smoke check: PASS\n";
} finally {
	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}
	if ( $source_id ) {
		wp_delete_term( $source_id, $taxonomy );
	}
	if ( $target_id ) {
		wp_delete_term( $target_id, $taxonomy );
	}
}
