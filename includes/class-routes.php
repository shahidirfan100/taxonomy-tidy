<?php
/** Same-site archive redirects and gone responses created by reviewed operations. */
namespace TaxonomyTidy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Routes {
	/** Return the current site's route table. */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'taxonomy_tidy_routes';
	}

	/** Save the URL rule before its term is mutated, so an interrupted apply remains recoverable. */
	public static function create_for_operation( $operation_id, $preview ) {
		global $wpdb;
		$rule_type = '';
		$target    = null;
		if ( 'merge' === $preview['action'] && ! empty( $preview['create_redirects'] ) ) {
			$rule_type = 'redirect';
			$target    = $preview['target'];
		} elseif ( 'delete' === $preview['action'] ) {
			$rule_type = 'gone';
		} else {
			return true;
		}

		if ( empty( $preview['old_url'] ) ) {
			return true;
		}
		$path = self::normalize_path( $preview['old_url'] );
		if ( '' === $path ) {
			return true;
		}
		$row = array(
			'operation_id'   => absint( $operation_id ),
			'rule_type'      => $rule_type,
			'source_hash'    => hash( 'sha256', $path ),
			'source_path'    => $path,
			'taxonomy'       => $preview['taxonomy']->name,
			'source_term_id' => (int) $preview['source']->term_id,
			'source_tt_id'   => (int) $preview['source']->term_taxonomy_id,
			'target_term_id' => $target ? (int) $target->term_id : 0,
			'target_tt_id'   => $target ? (int) $target->term_taxonomy_id : 0,
			'created_at'     => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This local route is part of the durable operation record and is required before deleting its term.
		if ( false === $wpdb->insert( self::table(), $row, array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' ) ) ) {
			return new \WP_Error( 'taxonomy_tidy_route_save_failed', __( 'The archive response rule could not be saved, so the term was left unchanged.', 'taxonomy-tidy' ) );
		}
		return true;
	}

	/** Remove the redirect or gone response when its operation is restored. */
	public static function remove_for_operation( $operation_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove only the route owned by the restored local audit operation.
		$wpdb->delete( self::table(), array( 'operation_id' => absint( $operation_id ) ), array( '%d' ) );
	}

	/** Serve saved rules only for front-end 404 requests, before canonical guessing runs. */
	public static function handle_404() {
		if ( Schema::VERSION !== get_option( Schema::OPTION ) || is_admin() || ! is_404() || empty( $_SERVER['REQUEST_METHOD'] ) || ! in_array( strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ), array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The request URI is parsed and reduced to a same-site path by normalize_path() before matching.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = self::normalize_path( $request_uri );
		if ( '' === $path ) {
			return;
		}
		$rules = self::rules_for_path( $path );
		foreach ( $rules as $rule ) {
			$source_path = (string) $rule->source_path;
			if ( false === strpos( $source_path, '?' ) && self::path_only( $path ) !== $source_path ) {
				continue;
			}
			if ( false !== strpos( $source_path, '?' ) && $path !== $source_path ) {
				continue;
			}
			if ( self::source_still_exists( $rule->source_tt_id, $rule->taxonomy ) ) {
				continue;
			}
			if ( 'gone' === $rule->rule_type ) {
				self::serve_gone();
				return;
			}
			$target_url = self::resolve_redirect( $rule );
			if ( 'gone' === $target_url ) {
				self::serve_gone();
				return;
			}
			if ( is_string( $target_url ) && '' !== $target_url && self::path_only( $path ) !== self::path_only( self::normalize_path( $target_url ) ) && wp_safe_redirect( $target_url, 301, 'Taxonomy Tidy' ) ) {
				exit;
			}
		}
	}

	/** Find newest exact-path rules; older rules remain available if a source term was restored. */
	private static function rules_for_path( $path ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This indexed local lookup runs only for a front-end 404 and uses an exact normalized-path hash.
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE source_hash = %s ORDER BY id DESC', self::table(), hash( 'sha256', $path ) )
		);
	}

	/** Check that the removed taxonomy row has not been restored or reused. */
	private static function source_still_exists( $term_taxonomy_id, $taxonomy ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify the identity of one historical source row before serving a saved route.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND taxonomy = %s", (int) $term_taxonomy_id, $taxonomy )
		);
	}

	/** Resolve a saved canonical term or follow later merges without creating redirect chains. */
	private static function resolve_redirect( $rule ) {
		$seen = array();
		for ( $hop = 0; $hop < 10; $hop++ ) {
			$target_tt_id = (int) $rule->target_tt_id;
			if ( isset( $seen[ $target_tt_id ] ) ) {
				return '';
			}
			$seen[ $target_tt_id ] = true;
			$target                = get_term( (int) $rule->target_term_id, $rule->taxonomy );
			if ( $target && ! is_wp_error( $target ) && (int) $target->term_taxonomy_id === $target_tt_id ) {
				$url = get_term_link( $target );
				return is_wp_error( $url ) ? '' : $url;
			}
			$next = self::rule_for_source( $target_tt_id, $rule->taxonomy );
			if ( ! $next || self::source_still_exists( $next->source_tt_id, $next->taxonomy ) ) {
				return '';
			}
			if ( 'gone' === $next->rule_type ) {
				return 'gone';
			}
			if ( 'redirect' !== $next->rule_type ) {
				return '';
			}
			$rule = $next;
		}
		return '';
	}

	/** Find a later redirect whose source is the previous canonical term. */
	private static function rule_for_source( $term_taxonomy_id, $taxonomy ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This indexed local lookup follows a previously audited merge target.
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE source_tt_id = %d AND taxonomy = %s ORDER BY id DESC LIMIT 1', self::table(), absint( $term_taxonomy_id ), $taxonomy )
		);
	}

	/** Mark a deleted term archive Gone while allowing the site's normal 404 template to render. */
	private static function serve_gone() {
		status_header( 410 );
		header( 'X-Robots-Tag: noindex', true );
		remove_action( 'template_redirect', 'redirect_canonical' );
	}

	/** Normalize a same-site URL or request URI to its home-relative path. */
	private static function normalize_path( $url ) {
		$parts = wp_parse_url( (string) $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) ) {
			return '';
		}
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? '/' . trim( $home_path, '/' ) : '';
		if ( '/' !== $home_path && '' !== $home_path && 0 === strpos( $path, $home_path ) && ( strlen( $path ) === strlen( $home_path ) || '/' === substr( $path, strlen( $home_path ), 1 ) ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = preg_replace( '#/+#', '/', $path );
		$path = preg_replace_callback(
			'/%[0-9a-f]{2}/i',
			static function ( $matches ) {
				return strtoupper( $matches[0] );
			},
			$path
		);
		$path = '/' . trim( $path, '/' );
		$path = '/' === $path ? '/' : $path;
		if ( empty( $parts['query'] ) ) {
			return $path;
		}
		wp_parse_str( $parts['query'], $query );
		foreach ( array_keys( $query ) as $key ) {
			if ( 0 === strpos( strtolower( $key ), 'utm_' ) || in_array( strtolower( $key ), array( 'fbclid', 'gclid', 'msclkid', '_ga', '_gl' ), true ) ) {
				unset( $query[ $key ] );
			}
		}
		if ( empty( $query ) ) {
			return $path;
		}
		ksort( $query );
		return $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/** Return the normalized path without its query string. */
	private static function path_only( $normalized_path ) {
		$query_position = strpos( $normalized_path, '?' );
		return false === $query_position ? $normalized_path : substr( $normalized_path, 0, $query_position );
	}
}
