<?php
/** Read-only taxonomy issue scan. */
namespace TaxonomyTidy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scanner {
	/** Scan one taxonomy and return conservative review groups and empty terms. */
	public static function scan_taxonomy( $taxonomy_name, $limit = 100 ) {
		global $wpdb;
		$taxonomy = Taxonomy_Service::get_authorized( $taxonomy_name );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$taxonomy_name = $taxonomy->name;

		$seen_ids      = array();
		$groups_by_key = array();
		$empty         = array();
		$empty_total   = 0;
		$total         = 0;
		$offset        = 0;
		$page_size     = 500;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A read-only paged scan avoids loading every term object into memory or priming a site-wide term cache.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count FROM %i AS t INNER JOIN %i AS tt ON t.term_id = tt.term_id WHERE tt.taxonomy = %s ORDER BY t.name ASC, t.term_id ASC LIMIT %d OFFSET %d',
					$wpdb->terms,
					$wpdb->term_taxonomy,
					$taxonomy_name,
					$page_size,
					$offset
				),
				OBJECT
			);
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$term = new \WP_Term( $row );
				$term->filter( 'raw' );
				++$total;
				if ( 0 === (int) $term->count ) {
					++$empty_total;
					if ( count( $empty ) < absint( $limit ) ) {
						$empty[] = $term;
					}
				}
				$key = Taxonomy_Service::normalize_name( $term->name );
				if ( '' === $key ) {
					continue;
				}
				if ( isset( $groups_by_key[ $key ] ) ) {
					$groups_by_key[ $key ][] = $term;
				} elseif ( isset( $seen_ids[ $key ] ) ) {
					$first_term = get_term( $seen_ids[ $key ], $taxonomy_name );
					if ( ! $first_term || is_wp_error( $first_term ) ) {
						return new \WP_Error( 'taxonomy_tidy_scan_changed', __( 'A term changed while the scan was running. Run the scan again.', 'taxonomy-tidy' ) );
					}
					$groups_by_key[ $key ] = array( $first_term, $term );
					unset( $seen_ids[ $key ] );
				} else {
					$seen_ids[ $key ] = (int) $term->term_id;
				}
			}
			$offset += count( $rows );
			if ( count( $rows ) < $page_size ) {
				break;
			}
		}

		$duplicates = array();
		foreach ( $groups_by_key as $key => $members ) {
			$distinct_names = array_unique( wp_list_pluck( $members, 'name' ) );
			$duplicates[]   = array(
				'kind'    => 1 < count( $distinct_names ) ? 'similar' : 'exact',
				'key'     => $key,
				'members' => $members,
			);
		}

		usort(
			$duplicates,
			static function ( $left, $right ) {
				if ( $left['kind'] !== $right['kind'] ) {
					return 'exact' === $left['kind'] ? -1 : 1;
				}
				return strcasecmp( $left['members'][0]->name, $right['members'][0]->name );
			}
		);

		return array(
			'taxonomy'    => $taxonomy,
			'total'       => $total,
			'groups'      => array_slice( $duplicates, 0, absint( $limit ) ),
			'group_total' => count( $duplicates ),
			'empty'       => $empty,
			'empty_total' => $empty_total,
		);
	}

	/** Run a scan across each taxonomy the current user can manage. */
	public static function scan_all( $include_private = false ) {
		$results = array();
		foreach ( Taxonomy_Service::get_available( $include_private ) as $name => $taxonomy ) {
			$result = self::scan_taxonomy( $name );
			if ( ! is_wp_error( $result ) ) {
				$results[ $name ] = $result;
			}
		}
		return $results;
	}
}
