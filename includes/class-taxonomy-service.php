<?php
/** Taxonomy discovery and bounded impact helpers. */
namespace TaxonomyTidy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Taxonomy_Service {
	/** Return manageable taxonomies shown in the cleanup dashboard. */
	public static function get_available( $include_private = false ) {
		$taxonomies = get_taxonomies( array( 'show_ui' => true ), 'objects' );
		$available  = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( empty( $taxonomy->name ) || empty( $taxonomy->cap->manage_terms ) ) {
				continue;
			}
			if ( empty( $taxonomy->public ) && ! $include_private ) {
				continue;
			}
			if ( ! current_user_can( $taxonomy->cap->manage_terms ) ) {
				continue;
			}
			$available[ $taxonomy->name ] = $taxonomy;
		}

		return $available;
	}

	/** Return taxonomy only after checking its registered capabilities. */
	public static function get_authorized( $taxonomy_name, $for_change = false ) {
		$taxonomy = get_taxonomy( sanitize_key( $taxonomy_name ) );
		if ( ! $taxonomy || empty( $taxonomy->show_ui ) || empty( $taxonomy->cap->manage_terms ) ) {
			return new \WP_Error( 'taxonomy_tidy_invalid_taxonomy', __( 'That taxonomy is not available for cleanup.', 'taxonomy-tidy' ) );
		}
		if ( ! current_user_can( $taxonomy->cap->manage_terms ) ) {
			return new \WP_Error( 'taxonomy_tidy_forbidden', __( 'You do not have permission to manage terms in this taxonomy.', 'taxonomy-tidy' ) );
		}
		if ( $for_change && ( empty( $taxonomy->cap->edit_terms ) || empty( $taxonomy->cap->delete_terms ) || ! current_user_can( $taxonomy->cap->edit_terms ) || ! current_user_can( $taxonomy->cap->delete_terms ) ) ) {
			return new \WP_Error( 'taxonomy_tidy_change_forbidden', __( 'Your role cannot merge, rename, or remove terms in this taxonomy.', 'taxonomy-tidy' ) );
		}

		return $taxonomy;
	}

	/** Create the conservative comparison key used by the scanner. */
	public static function normalize_name( $name ) {
		$name = remove_accents( wp_strip_all_tags( (string) $name ) );
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
		$name = preg_replace( '/[^\p{L}\p{N}]+/u', '', $name );
		return is_string( $name ) ? $name : '';
	}

	/** Return a safe term URL or an empty value for taxonomies without archives. */
	public static function term_url( $term_id, $taxonomy ) {
		$url = get_term_link( (int) $term_id, $taxonomy );
		return is_wp_error( $url ) ? '' : $url;
	}

	/** Return a few affected posts without loading all relationships into memory. */
	public static function affected_objects( $term_id, $taxonomy, $limit = 8 ) {
		global $wpdb;
		$term = get_term( (int) $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This bounded fresh relationship query supplies the exact object sample shown in the pre-change impact review.
		$object_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d ORDER BY object_id ASC LIMIT %d",
				(int) $term->term_taxonomy_id,
				absint( $limit )
			)
		);
		if ( empty( $object_ids ) ) {
			return array();
		}
		$objects = array();
		foreach ( $object_ids as $object_id ) {
			$post = get_post( $object_id );
			if ( $post ) {
				$objects[] = array(
					'id'    => $post->ID,
					'title' => get_the_title( $post ),
					'type'  => get_post_type_object( $post->post_type ) ? get_post_type_object( $post->post_type )->labels->singular_name : $post->post_type,
				);
			} else {
				$objects[] = array(
					'id'    => $object_id,
					/* translators: %d is the ID of an object registered to this taxonomy. */
					'title' => sprintf( __( 'Object #%d', 'taxonomy-tidy' ), $object_id ),
					'type'  => __( 'Registered object', 'taxonomy-tidy' ),
				);
			}
		}

		return $objects;
	}

	/** Return direct children for a hierarchical taxonomy. */
	public static function get_children( $term_id, $taxonomy ) {
		$term = get_term( (int) $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) || ! is_taxonomy_hierarchical( $taxonomy ) ) {
			return array();
		}

		$children = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'parent'     => (int) $term_id,
				'fields'     => 'all',
			)
		);

		return is_wp_error( $children ) ? array() : $children;
	}
}
