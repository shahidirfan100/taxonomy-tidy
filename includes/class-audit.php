<?php
/** Durable audit, guarded mutations, and restoration. */
namespace TaxonomyTidy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Audit {
	/** @return string */
	private static function table( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . 'taxonomy_tidy_' . $suffix;
	}

	/** @return array|\WP_Error */
	public static function preview( $action, $taxonomy_name, $source_id, $target_id = 0, $new_name = '', $new_slug = '', $options = array() ) {
		if ( ! in_array( $action, array( 'merge', 'rename', 'delete' ), true ) ) {
			return new \WP_Error( 'taxonomy_tidy_invalid_action', __( 'Choose a supported taxonomy change.', 'taxonomy-tidy' ) );
		}
		$taxonomy = Taxonomy_Service::get_authorized( $taxonomy_name, true );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$source = get_term( absint( $source_id ), $taxonomy_name );
		if ( ! $source || is_wp_error( $source ) ) {
			return new \WP_Error( 'taxonomy_tidy_source_missing', __( 'The source term no longer exists. Run the scan again.', 'taxonomy-tidy' ) );
		}

		$target = null;
		if ( in_array( $action, array( 'merge', 'delete' ), true ) && self::is_default_term( $source, $taxonomy_name ) ) {
			return new \WP_Error( 'taxonomy_tidy_default_term', __( 'The default term for this taxonomy cannot be merged or removed. You can choose it as the term to keep.', 'taxonomy-tidy' ) );
		}

		if ( 'merge' === $action ) {
			$target = get_term( absint( $target_id ), $taxonomy_name );
			if ( ! $target || is_wp_error( $target ) || (int) $target->term_id === (int) $source->term_id ) {
				return new \WP_Error( 'taxonomy_tidy_target_invalid', __( 'Choose a different existing term in the same taxonomy to keep.', 'taxonomy-tidy' ) );
			}
		}
		if ( 'rename' === $action && '' === trim( $new_name ) ) {
			return new \WP_Error( 'taxonomy_tidy_name_required', __( 'Enter a name for the term.', 'taxonomy-tidy' ) );
		}
		if ( 'delete' === $action && ( (int) $source->count > 0 || self::has_relationships( $source ) ) ) {
			return new \WP_Error( 'taxonomy_tidy_not_empty', __( 'This term has assignments. Refresh the scan before removing it.', 'taxonomy-tidy' ) );
		}

		$children = Taxonomy_Service::get_children( $source->term_id, $taxonomy_name );
		if ( 'merge' === $action && ! self::hierarchy_is_safe( $source, $target, $children, $taxonomy_name ) ) {
			return new \WP_Error( 'taxonomy_tidy_hierarchy_cycle', __( 'This merge could create a parent loop. Move the child terms first, then review the merge again.', 'taxonomy-tidy' ) );
		}

		$new_name = sanitize_text_field( $new_name );
		$new_slug = sanitize_title( $new_slug ? $new_slug : $new_name );
		$new_url  = $target ? Taxonomy_Service::term_url( $target->term_id, $taxonomy_name ) : '';
		if ( 'rename' === $action ) {
			$future_term           = clone $source;
			$future_term->name     = $new_name;
			$new_slug              = wp_unique_term_slug( $new_slug, $source );
			$future_term->slug     = $new_slug;
			$future_term->taxonomy = $taxonomy_name;
			$future_url            = get_term_link( $future_term, $taxonomy_name );
			$new_url               = is_wp_error( $future_url ) ? '' : $future_url;
		}
		$hierarchy_changes = array();
		$include_objects   = ! array_key_exists( 'include_objects', $options ) || ! empty( $options['include_objects'] );
		if ( ! empty( $children ) && 'rename' !== $action ) {
			$target_is_child = $target && (int) $target->parent === (int) $source->term_id;
			foreach ( $children as $child ) {
				$parent_id = 'delete' === $action ? (int) $source->parent : (int) $target->term_id;
				if ( $target_is_child ) {
					$parent_id = (int) $child->term_id === (int) $target->term_id ? (int) $source->parent : (int) $target->term_id;
				}
				$parent              = $parent_id ? get_term( $parent_id, $taxonomy_name ) : null;
				$hierarchy_changes[] = array(
					'term'        => $child,
					'parent_id'   => $parent_id,
					'parent_name' => $parent && ! is_wp_error( $parent ) ? $parent->name : __( 'Top level', 'taxonomy-tidy' ),
				);
			}
		}
		return array(
			'action'            => $action,
			'taxonomy'          => $taxonomy,
			'source'            => $source,
			'target'            => $target,
			'children'          => $children,
			'hierarchy_changes' => $hierarchy_changes,
			'object_count'      => self::relationship_count( $source ),
			'objects'           => $include_objects ? Taxonomy_Service::affected_objects( $source->term_id, $taxonomy_name, 8 ) : array(),
			'old_url'           => Taxonomy_Service::term_url( $source->term_id, $taxonomy_name ),
			'new_url'           => $new_url,
			'new_name'          => $new_name,
			'new_slug'          => $new_slug,
			'create_redirects'  => 'merge' === $action && ! empty( $options['create_redirects'] ),
		);
	}

	/** Fingerprint the exact source assignment set and hierarchy shown in the preview. */
	public static function impact_fingerprint( $preview ) {
		global $wpdb;
		$context = array(
			'action'   => $preview['action'],
			'taxonomy' => $preview['taxonomy']->name,
			'source'   => array( (int) $preview['source']->term_id, $preview['source']->name, $preview['source']->slug, (int) $preview['source']->parent, (int) $preview['source']->count, (int) $preview['source']->term_taxonomy_id ),
			'target'   => $preview['target'] ? array( (int) $preview['target']->term_id, $preview['target']->name, $preview['target']->slug, (int) $preview['target']->parent, (int) $preview['target']->term_taxonomy_id ) : array(),
			'children' => array(),
			'new_name' => isset( $preview['new_name'] ) ? $preview['new_name'] : '',
			'new_slug' => isset( $preview['new_slug'] ) ? $preview['new_slug'] : '',
			'redirect' => ! empty( $preview['create_redirects'] ),
		);
		foreach ( $preview['children'] as $child ) {
			$context['children'][] = array( (int) $child->term_id, (int) $child->parent );
		}
		$hash = hash_init( 'sha256' );
		hash_update( $hash, wp_json_encode( $context ) );
		$last_id = 0;
		while ( true ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT object_id, term_order FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d AND object_id > %d ORDER BY object_id ASC LIMIT 500", (int) $preview['source']->term_taxonomy_id, $last_id ),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				hash_update( $hash, (string) $row['object_id'] . ':' . (string) $row['term_order'] . ';' );
				$last_id = (int) $row['object_id'];
			}
			if ( count( $rows ) < 500 ) {
				break;
			}
		}
		return hash_final( $hash );
	}

	/** Merge one term into a chosen canonical term, with an exact-ID rollback record. */
	public static function merge( $taxonomy_name, $source_id, $target_id, $options = array() ) {
		$preview = self::preview( 'merge', $taxonomy_name, $source_id, $target_id, '', '', $options );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		return self::apply_saved_operation( $preview );
	}

	/** Rename one term, preserving its prior values for rollback. */
	public static function rename( $taxonomy_name, $source_id, $new_name, $new_slug = '' ) {
		$preview = self::preview( 'rename', $taxonomy_name, $source_id, 0, $new_name, $new_slug );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		return self::apply_saved_operation( $preview );
	}

	/** Remove an empty term after preserving its prior rows and child parents. */
	public static function delete_empty( $taxonomy_name, $source_id ) {
		$preview = self::preview( 'delete', $taxonomy_name, $source_id );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		return self::apply_saved_operation( $preview );
	}

	/** Apply a reviewed set of same-taxonomy operations under one reversible batch. */
	public static function apply_batch( $previews ) {
		global $wpdb;
		if ( ! is_array( $previews ) || empty( $previews ) || count( $previews ) > 100 ) {
			return new \WP_Error( 'taxonomy_tidy_batch_size_invalid', __( 'Choose between 1 and 100 operations for one reviewed batch.', 'taxonomy-tidy' ) );
		}
		$taxonomy_name = $previews[0]['taxonomy']->name;
		$action        = $previews[0]['action'];
		$source_ids    = array();
		$target_ids    = array();
		$affected      = 0;
		$redirects     = 0;
		foreach ( $previews as $preview ) {
			if ( ! isset( $preview['taxonomy']->name ) || $taxonomy_name !== $preview['taxonomy']->name || $action !== $preview['action'] || ! in_array( $action, array( 'merge', 'delete' ), true ) ) {
				return new \WP_Error( 'taxonomy_tidy_batch_mixed', __( 'A batch must contain one action for one taxonomy.', 'taxonomy-tidy' ) );
			}
			$source_id = (int) $preview['source']->term_id;
			$target_id = $preview['target'] ? (int) $preview['target']->term_id : 0;
			if ( isset( $source_ids[ $source_id ] ) || ( $target_id && isset( $source_ids[ $target_id ] ) ) ) {
				return new \WP_Error( 'taxonomy_tidy_batch_overlap', __( 'A term cannot be changed and kept in the same batch. Choose separate groups and review them again.', 'taxonomy-tidy' ) );
			}
			$source_ids[ $source_id ] = true;
			if ( $target_id ) {
				$target_ids[ $target_id ] = true;
			}
			$affected  += (int) $preview['object_count'];
			$redirects += ! empty( $preview['create_redirects'] ) ? 1 : 0;
		}
		foreach ( $target_ids as $target_id => $unused ) {
			if ( isset( $source_ids[ $target_id ] ) ) {
				return new \WP_Error( 'taxonomy_tidy_batch_overlap', __( 'A canonical term cannot also be merged or removed in the same batch. Choose separate groups and review them again.', 'taxonomy-tidy' ) );
			}
		}

		$uuid          = wp_generate_uuid4();
		$batch_table   = self::table( 'batches' );
		$preview_count = count( $previews );
		/* translators: %s: number of source terms in this batch. */
		$term_summary = sprintf( _n( '%s term', '%s terms', $preview_count, 'taxonomy-tidy' ), number_format_i18n( $preview_count ) );
		$summary      = wp_json_encode(
			array(
				'action'         => 'merge' === $action ? 'bulk_merge' : 'bulk_delete',
				'taxonomy'       => $taxonomy_name,
				'source_name'    => $term_summary,
				'target_name'    => '',
				'affected_count' => $affected,
				'redirect_count' => $redirects,
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This local row is the parent restore point for all operations in the reviewed batch.
		if ( false === $wpdb->insert(
			$batch_table,
			array(
				'batch_uuid'   => $uuid,
				'user_id'      => get_current_user_id(),
				'status'       => 'pending',
				'action_count' => count( $previews ),
				'summary'      => $summary,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s' )
		) ) {
			return new \WP_Error( 'taxonomy_tidy_audit_failed', __( 'The batch audit record could not be created, so no terms were changed.', 'taxonomy-tidy' ) );
		}
		$batch_id = (int) $wpdb->insert_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mark the parent restore point before the first mutation begins.
		if ( 1 !== $wpdb->update(
			$batch_table,
			array( 'status' => 'applying' ),
			array(
				'id'     => $batch_id,
				'status' => 'pending',
			),
			array( '%s' ),
			array( '%d', '%s' )
		) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Remove the empty parent row because no operation was started.
			$wpdb->delete( $batch_table, array( 'id' => $batch_id ), array( '%d' ) );
			return new \WP_Error( 'taxonomy_tidy_apply_start_failed', __( 'The batch could not be marked ready. No taxonomy change was attempted; review Activity before retrying.', 'taxonomy-tidy' ) );
		}

		$applied = 0;
		foreach ( $previews as $index => $preview ) {
			$result = self::apply_saved_operation(
				$preview,
				array(
					'batch_id'    => $batch_id,
					'batch_uuid'  => $uuid,
					'sequence_no' => $index + 1,
				)
			);
			if ( is_wp_error( $result ) ) {
				$status = 'taxonomy_tidy_apply_and_restore_failed' === $result->get_error_code() ? 'rollback_failed' : ( $applied ? 'apply_failed' : 'failed' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserve partial-apply recovery state after the operation reports a failure.
				$wpdb->update(
					$batch_table,
					array(
						'status'       => $status,
						'completed_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $batch_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				$message = $result->get_error_message();
				if ( $applied || 'rollback_failed' === $status ) {
					/* translators: 1: original operation error, 2: audit batch UUID. */
					$message = sprintf( __( '%1$s Earlier changes are saved in batch %2$s. Open Activity to roll back or recover the whole batch.', 'taxonomy-tidy' ), $message, $uuid );
				}
				return new \WP_Error( $result->get_error_code(), $message );
			}
			++$applied;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete the shared audit batch after all operations have been saved.
		$wpdb->update(
			$batch_table,
			array(
				'status'       => 'applied',
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $batch_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return array(
			'batch_id'   => $batch_id,
			'batch_uuid' => $uuid,
			'action'     => 'bulk',
		);
	}

	/** Build the durable pre-change snapshot, apply the operation, and compensate on failure. */
	private static function apply_saved_operation( $preview, $batch_context = null ) {
		global $wpdb;
		$source        = $preview['source'];
		$taxonomy      = $preview['taxonomy'];
		$taxonomy_name = $taxonomy->name;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		$source_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT term_id, name, slug, term_group FROM {$wpdb->terms} WHERE term_id = %d", $source->term_id ),
			ARRAY_A
		);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		$tt_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT term_taxonomy_id, term_id, taxonomy, description, parent, count FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $source->term_id, $taxonomy_name ),
			ARRAY_A
		);
		if ( ! $source_row || ! $tt_row ) {
			return new \WP_Error( 'taxonomy_tidy_snapshot_failed', __( 'The term changed before it could be saved. Refresh the page and try again.', 'taxonomy-tidy' ) );
		}

		$meta_rows = array();
		if ( isset( $wpdb->termmeta ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
			$meta_rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d ORDER BY meta_id ASC", $source->term_id ),
				ARRAY_A
			);
		}
		$snapshot = array(
			'term_row'         => $source_row,
			'taxonomy_row'     => $tt_row,
			'term_meta'        => $meta_rows,
			'children'         => array(),
			'before'           => array(
				'name'        => $source->name,
				'slug'        => $source->slug,
				'description' => $source->description,
				'parent'      => (int) $source->parent,
			),
			'requested'        => array(
				'name' => $preview['new_name'],
				'slug' => $preview['new_slug'],
			),
			'target_before'    => $preview['target'] ? array(
				'id'          => (int) $preview['target']->term_id,
				'name'        => $preview['target']->name,
				'slug'        => $preview['target']->slug,
				'description' => $preview['target']->description,
				'parent'      => (int) $preview['target']->parent,
				'tt_id'       => (int) $preview['target']->term_taxonomy_id,
			) : array(),
			'after'            => array(),
			'old_url'          => $preview['old_url'],
			'new_url'          => $preview['new_url'],
			'create_redirects' => ! empty( $preview['create_redirects'] ),
		);
		foreach ( $preview['children'] as $child ) {
			$after_parent = (int) $child->parent;
			foreach ( $preview['hierarchy_changes'] as $change ) {
				if ( (int) $change['term']->term_id === (int) $child->term_id ) {
					$after_parent = (int) $change['parent_id'];
					break;
				}
			}
			$snapshot['children'][] = array(
				'term_id'      => (int) $child->term_id,
				'parent'       => (int) $child->parent,
				'after_parent' => $after_parent,
			);
		}

		$owns_batch         = ! is_array( $batch_context );
		$uuid               = $owns_batch ? wp_generate_uuid4() : $batch_context['batch_uuid'];
		$batch_table        = self::table( 'batches' );
		$operation_table    = self::table( 'operations' );
		$relationship_table = self::table( 'relationships' );
		if ( $owns_batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
			$inserted = $wpdb->insert(
				$batch_table,
				array(
					'batch_uuid'   => $uuid,
					'user_id'      => get_current_user_id(),
					'status'       => 'pending',
					'action_count' => 1,
					'created_at'   => current_time( 'mysql', true ),
				),
				array( '%s', '%d', '%s', '%d', '%s' )
			);
			if ( false === $inserted ) {
				return new \WP_Error( 'taxonomy_tidy_audit_failed', __( 'The audit record could not be created, so no change was made.', 'taxonomy-tidy' ) );
			}
			$batch_id = (int) $wpdb->insert_id;
		} else {
			$batch_id = (int) $batch_context['batch_id'];
		}
		$sequence_no = $owns_batch ? 1 : (int) $batch_context['sequence_no'];
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
		$inserted = $wpdb->insert(
			$operation_table,
			array(
				'batch_id'       => $batch_id,
				'sequence_no'    => $sequence_no,
				'action'         => $preview['action'],
				'taxonomy'       => $taxonomy_name,
				'source_term_id' => (int) $source->term_id,
				'target_term_id' => $preview['target'] ? (int) $preview['target']->term_id : 0,
				'snapshot'       => wp_json_encode( $snapshot ),
				'affected_count' => (int) $preview['object_count'],
				'result'         => 'pending',
			),
			array( '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s' )
		);
		if ( false === $inserted ) {
			if ( $owns_batch ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only the empty audit batch created immediately above after its operation insert failed.
				$wpdb->delete( $batch_table, array( 'id' => $batch_id ), array( '%d' ) );
			}
			return new \WP_Error( 'taxonomy_tidy_audit_failed', __( 'The operation record could not be created, so no change was made.', 'taxonomy-tidy' ) );
		}
		$operation_id = (int) $wpdb->insert_id;

		if ( 'merge' === $preview['action'] ) {
			$saved = self::save_relationship_snapshot( $operation_id, $source, $preview['target'], $relationship_table );
			if ( is_wp_error( $saved ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Discard only the relationship snapshot for the operation that could not be fully journaled.
				$wpdb->delete( $operation_table, array( 'id' => $operation_id ), array( '%d' ) );
				if ( $owns_batch ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only the empty audit batch created by this operation after snapshot creation failed.
					$wpdb->delete( $batch_table, array( 'id' => $batch_id ), array( '%d' ) );
				}
				return $saved;
			}
		}
		$route_saved = Routes::create_for_operation( $operation_id, $preview );
		if ( is_wp_error( $route_saved ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Discard only the relationship snapshot for an operation that has not started.
			$wpdb->delete( $relationship_table, array( 'operation_id' => $operation_id ), array( '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Discard the operation row because no taxonomy mutation has begun.
			$wpdb->delete( $operation_table, array( 'id' => $operation_id ), array( '%d' ) );
			if ( $owns_batch ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Discard the empty parent batch because the single operation did not start.
				$wpdb->delete( $batch_table, array( 'id' => $batch_id ), array( '%d' ) );
			}
			return $route_saved;
		}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mark the durable snapshot complete before any taxonomy mutation begins.
		$operation_started = $wpdb->update(
			$operation_table,
			array( 'result' => 'applying' ),
			array(
				'id'     => $operation_id,
				'result' => 'pending',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mark the batch before mutations so an interrupted apply is visible and recoverable.
		$batch_started = $owns_batch ? $wpdb->update(
			$batch_table,
			array( 'status' => 'applying' ),
			array(
				'id'     => $batch_id,
				'status' => 'pending',
			),
			array( '%s' ),
			array( '%d', '%s' )
		) : 1;
		if ( 1 !== $operation_started || 1 !== $batch_started ) {
			return new \WP_Error( 'taxonomy_tidy_apply_start_failed', __( 'The saved snapshot could not be marked ready. No taxonomy change was attempted; review Activity before retrying.', 'taxonomy-tidy' ) );
		}

		try {
			if ( 'merge' === $preview['action'] ) {
				self::perform_merge( $preview, $operation_id );
			} elseif ( 'rename' === $preview['action'] ) {
				$result = wp_update_term(
					$source->term_id,
					$taxonomy_name,
					array(
						'name' => $preview['new_name'],
						'slug' => $preview['new_slug'],
					)
				);
				if ( is_wp_error( $result ) ) {
					throw new \RuntimeException( $result->get_error_message() );
				}
				$updated           = get_term( $source->term_id, $taxonomy_name );
				$snapshot['after'] = array(
					'name'        => $updated->name,
					'slug'        => $updated->slug,
					'description' => $updated->description,
					'parent'      => (int) $updated->parent,
				);
			} elseif ( 'delete' === $preview['action'] ) {
				self::move_children_before_delete( $preview );
				$result = wp_delete_term( $source->term_id, $taxonomy_name );
				if ( ! $result || is_wp_error( $result ) ) {
					throw new \RuntimeException( __( 'WordPress could not remove the empty term.', 'taxonomy-tidy' ) );
				}
			} else {
				throw new \RuntimeException( __( 'This operation is not supported.', 'taxonomy-tidy' ) );
			}
		} catch ( \Throwable $error ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
			$wpdb->update(
				$operation_table,
				array(
					'snapshot' => wp_json_encode( $snapshot ),
					'result'   => 'failed',
				),
				array( 'id' => $operation_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$restore = self::restore_operation( $operation_id, true );
			if ( is_wp_error( $restore ) ) {
				$restore_status = 'restore_failed';
			} else {
				Routes::remove_for_operation( $operation_id );
				$restore_status = 'rolled_back';
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Record whether the interrupted operation was restored before reporting its batch state.
			$wpdb->update( $operation_table, array( 'result' => $restore_status ), array( 'id' => $operation_id ), array( '%s' ), array( '%d' ) );
			if ( $owns_batch ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
				$wpdb->update(
					$batch_table,
					array(
						'status'       => is_wp_error( $restore ) ? 'rollback_failed' : 'failed',
						'completed_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $batch_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
			if ( is_wp_error( $restore ) ) {
				/* translators: 1: original change error, 2: restoration error, 3: audit batch ID. */
				return new \WP_Error( 'taxonomy_tidy_apply_and_restore_failed', sprintf( __( 'The change failed (%1$s) and automatic restoration needs attention: %2$s. Batch ID: %3$s', 'taxonomy-tidy' ), $error->getMessage(), $restore->get_error_message(), $uuid ) );
			}
			/* translators: %s is the original change error after the saved state was restored. */
			return new \WP_Error( 'taxonomy_tidy_apply_failed', sprintf( __( 'The change failed and the saved state was restored: %1$s', 'taxonomy-tidy' ), $error->getMessage() ) );
		}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
		$wpdb->update(
			$operation_table,
			array(
				'snapshot' => wp_json_encode( $snapshot ),
				'result'   => 'applied',
			),
			array( 'id' => $operation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
		if ( $owns_batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete the local single-operation audit batch after the mutation has been recorded.
			$wpdb->update(
				$batch_table,
				array(
					'status'       => 'applied',
					'summary'      => self::summary_json( $preview ),
					'completed_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $batch_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		return array(
			'batch_id'   => $batch_id,
			'batch_uuid' => $uuid,
			'action'     => $preview['action'],
		);
	}

	/** Persist every source relationship before touching WordPress terms. */
	private static function save_relationship_snapshot( $operation_id, $source, $target, $table ) {
		global $wpdb;
		$last_id      = 0;
		$tt_id        = (int) $source->term_taxonomy_id;
		$target_tt_id = (int) $target->term_taxonomy_id;
		while ( true ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT object_id, term_order FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d AND object_id > %d ORDER BY object_id ASC LIMIT 500", $tt_id, $last_id ),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$object_id = (int) $row['object_id'];
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
				$had_target = (bool) $wpdb->get_var(
					$wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id = %d", $object_id, $target_tt_id )
				);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
				if ( false === $wpdb->insert(
					$table,
					array(
						'operation_id' => $operation_id,
						'object_id'    => $object_id,
						'term_order'   => (int) $row['term_order'],
						'had_target'   => $had_target ? 1 : 0,
					),
					array( '%d', '%d', '%d', '%d' )
				) ) {
					$wpdb->delete( $table, array( 'operation_id' => $operation_id ), array( '%d' ) );
					return new \WP_Error( 'taxonomy_tidy_snapshot_failed', __( 'The affected-object snapshot could not be saved, so no change was made.', 'taxonomy-tidy' ) );
				}
				$last_id = $object_id;
			}
			if ( count( $rows ) < 500 ) {
				break;
			}
		}
		return true;
	}

	/** Add the canonical term first to avoid WordPress inserting the default category. */
	private static function perform_merge( $preview, $operation_id ) {
		global $wpdb;
		$source             = $preview['source'];
		$target             = $preview['target'];
		$relationship_table = self::table( 'relationships' );
		$last_id            = 0;
		while ( true ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, object_id FROM %i WHERE operation_id = %d AND id > %d ORDER BY id ASC LIMIT 250', $relationship_table, $operation_id, $last_id ),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$result = wp_set_object_terms( (int) $row['object_id'], (int) $target->term_id, $preview['taxonomy']->name, true );
				if ( is_wp_error( $result ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; the notice is escaped at the admin output boundary.
					throw new \RuntimeException( $result->get_error_message() );
				}
				$last_id = (int) $row['id'];
			}
			if ( count( $rows ) < 250 ) {
				break;
			}
		}

		self::move_children_before_delete( $preview );
		$result = wp_delete_term( $source->term_id, $preview['taxonomy']->name );
		if ( ! $result || is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; the notice is escaped at the admin output boundary.
			throw new \RuntimeException( __( 'WordPress could not remove the merged source term.', 'taxonomy-tidy' ) );
		}
	}

	/** Apply the exact child-parent changes shown in the preview. */
	private static function move_children_before_delete( $preview ) {
		foreach ( $preview['hierarchy_changes'] as $change ) {
			$child_id  = (int) $change['term']->term_id;
			$parent_id = (int) $change['parent_id'];
			$result    = wp_update_term( $child_id, $preview['taxonomy']->name, array( 'parent' => $parent_id ) );
			if ( is_wp_error( $result ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; the notice is escaped at the admin output boundary.
				throw new \RuntimeException( $result->get_error_message() );
			}
			$updated_child = get_term( $child_id, $preview['taxonomy']->name );
			if ( ! $updated_child || is_wp_error( $updated_child ) || (int) $updated_child->parent !== $parent_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; the notice is escaped at the admin output boundary.
				throw new \RuntimeException( __( 'WordPress did not apply the reviewed child-term hierarchy.', 'taxonomy-tidy' ) );
			}
		}
	}

	/** Check whether moving children under the canonical term would make a loop. */
	private static function hierarchy_is_safe( $source, $target, $children, $taxonomy_name ) {
		if ( ! is_taxonomy_hierarchical( $taxonomy_name ) || empty( $children ) ) {
			return true;
		}
		$direct_child = ( (int) $target->parent === (int) $source->term_id );
		if ( $direct_child ) {
			return true;
		}
		foreach ( $children as $child ) {
			$current = (int) $target->parent;
			$visited = array();
			while ( $current > 0 && ! isset( $visited[ $current ] ) ) {
				if ( $current === (int) $child->term_id ) {
					return false;
				}
				$visited[ $current ] = true;
				$parent              = get_term( $current, $taxonomy_name );
				$current             = ( $parent && ! is_wp_error( $parent ) ) ? (int) $parent->parent : 0;
			}
		}
		return true;
	}

	/** Return whether a term has any assigned objects. */
	private static function has_relationships( $term ) {
		global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d LIMIT 1", $term->term_taxonomy_id )
		);
	}

	/** Count source relationships without loading their IDs. */
	private static function relationship_count( $term ) {
		global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $term->term_taxonomy_id )
		);
	}

	/** Restore all operations in reverse order after checking for later changes. */
	public static function rollback_batch( $batch_id ) {
		global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		$batch = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( 'batches' ), absint( $batch_id ) ) );
		if ( ! $batch || ! in_array( $batch->status, array( 'applied', 'applying', 'apply_failed', 'rollback_failed', 'rolling_back' ), true ) ) {
			return new \WP_Error( 'taxonomy_tidy_batch_not_rollbackable', __( 'This batch is not available to roll back.', 'taxonomy-tidy' ) );
		}
		$resume = in_array( $batch->status, array( 'applying', 'apply_failed', 'rollback_failed', 'rolling_back' ), true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Persist the recovery state before restoring so an interrupted rollback can be resumed.
		$wpdb->update(
			self::table( 'batches' ),
			array( 'status' => 'rolling_back' ),
			array(
				'id'     => (int) $batch->id,
				'status' => $batch->status,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		$ops = $wpdb->get_results( $wpdb->prepare( 'SELECT id, taxonomy, result FROM %i WHERE batch_id = %d ORDER BY sequence_no DESC', self::table( 'operations' ), $batch->id ) );
		if ( empty( $ops ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This direct write records that a malformed, empty batch cannot be restored.
			$wpdb->update( self::table( 'batches' ), array( 'status' => 'failed' ), array( 'id' => (int) $batch->id ), array( '%s' ), array( '%d' ) );
			return new \WP_Error( 'taxonomy_tidy_operations_missing', __( 'The batch has no saved operations to restore. No term data was changed.', 'taxonomy-tidy' ) );
		}
		foreach ( $ops as $operation ) {
			if ( 'rolled_back' === $operation->result ) {
				continue;
			}
			if ( 'pending' === $operation->result ) {
				// The operation never passed the durable-snapshot phase, so no term mutation began.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mark the untouched operation as safely skipped during recovery.
				$wpdb->update( self::table( 'operations' ), array( 'result' => 'rolled_back' ), array( 'id' => (int) $operation->id ), array( '%s' ), array( '%d' ) );
				Routes::remove_for_operation( (int) $operation->id );
				continue;
			}
			$authorized = Taxonomy_Service::get_authorized( $operation->taxonomy, true );
			if ( is_wp_error( $authorized ) ) {
				return new \WP_Error( 'taxonomy_tidy_rollback_forbidden', __( 'Your role no longer has permission to restore terms in this taxonomy.', 'taxonomy-tidy' ) );
			}
			$result = self::restore_operation( (int) $operation->id, false, $resume );
			if ( is_wp_error( $result ) ) {
				if ( 'taxonomy_tidy_rollback_conflict' !== $result->get_error_code() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
					$wpdb->update( self::table( 'batches' ), array( 'status' => 'rollback_failed' ), array( 'id' => (int) $batch->id ), array( '%s' ), array( '%d' ) );
				}
				return $result;
			}
			Routes::remove_for_operation( (int) $operation->id );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
			$wpdb->update( self::table( 'operations' ), array( 'result' => 'rolled_back' ), array( 'id' => (int) $operation->id ), array( '%s' ), array( '%d' ) );
		}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
		$wpdb->update(
			self::table( 'batches' ),
			array(
				'status'       => 'rolled_back',
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $batch->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/** Restore a single saved operation; preserve term IDs and refuse conflicting later edits. */
	private static function restore_operation( $operation_id, $automatic, $resume = false ) {
		global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		$operation = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( 'operations' ), absint( $operation_id ) ) );
		if ( ! $operation ) {
			return new \WP_Error( 'taxonomy_tidy_operation_missing', __( 'The audit operation could not be found.', 'taxonomy-tidy' ) );
		}
		$snapshot = json_decode( $operation->snapshot, true );
		if ( ! is_array( $snapshot ) || empty( $snapshot['term_row'] ) || empty( $snapshot['taxonomy_row'] ) ) {
			return new \WP_Error( 'taxonomy_tidy_snapshot_invalid', __( 'The saved restoration data is incomplete.', 'taxonomy-tidy' ) );
		}
		$taxonomy_name  = $operation->taxonomy;
		$source_id      = (int) $operation->source_term_id;
		$current        = get_term( $source_id, $taxonomy_name );
		$children_ready = self::preflight_child_hierarchy( $operation, $snapshot, $taxonomy_name, $automatic || $resume );
		if ( is_wp_error( $children_ready ) ) {
			return $children_ready;
		}
		if ( 'merge' === $operation->action && ! empty( $snapshot['target_before']['id'] ) ) {
			$target = get_term( (int) $snapshot['target_before']['id'], $taxonomy_name );
			if ( ! $target || is_wp_error( $target ) || $target->name !== $snapshot['target_before']['name'] || $target->slug !== $snapshot['target_before']['slug'] || ( isset( $snapshot['target_before']['description'] ) && $target->description !== $snapshot['target_before']['description'] ) || ( isset( $snapshot['target_before']['parent'] ) && (int) $target->parent !== (int) $snapshot['target_before']['parent'] ) ) {
				return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'The canonical term was changed or removed after the merge. Review it before rolling back this batch.', 'taxonomy-tidy' ) );
			}
			if ( ! $automatic && ! $current ) {
				$relationships_ready = self::preflight_merge_relationships( $operation_id, (int) $snapshot['target_before']['tt_id'] );
				if ( is_wp_error( $relationships_ready ) ) {
					return $relationships_ready;
				}
			}
			if ( $resume && $current && ! is_wp_error( $current ) ) {
				$relationships_ready = self::preflight_interrupted_merge( $operation_id, (int) $snapshot['target_before']['tt_id'] );
				if ( is_wp_error( $relationships_ready ) ) {
					return $relationships_ready;
				}
			}
		}
		if ( 'rename' === $operation->action ) {
			$expected_after    = ! empty( $snapshot['after'] ) ? $snapshot['after'] : array(
				'name'        => $snapshot['requested']['name'],
				'slug'        => $snapshot['requested']['slug'],
				'description' => $snapshot['before']['description'],
				'parent'      => (int) $snapshot['before']['parent'],
			);
			$snapshot['after'] = $expected_after;
			if ( $automatic ) {
				if ( $current && ! is_wp_error( $current ) && $current->name === $snapshot['before']['name'] && $current->slug === $snapshot['before']['slug'] && $current->description === $snapshot['before']['description'] && (int) $current->parent === (int) $snapshot['before']['parent'] ) {
					return true;
				}
				if ( ! $current || is_wp_error( $current ) || $current->name !== $snapshot['requested']['name'] || $current->slug !== $snapshot['requested']['slug'] || $current->description !== $snapshot['before']['description'] || (int) $current->parent !== (int) $snapshot['before']['parent'] ) {
					return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'The term no longer matches the requested rename. Automatic restoration stopped without overwriting it.', 'taxonomy-tidy' ) );
				}
				return self::restore_renamed_term( $source_id, $taxonomy_name, $snapshot );
			}
			if ( $resume && $current && ! is_wp_error( $current ) && $current->name === $snapshot['before']['name'] && $current->slug === $snapshot['before']['slug'] && $current->description === $snapshot['before']['description'] && (int) $current->parent === (int) $snapshot['before']['parent'] ) {
				return true;
			}
			if ( ! $current || is_wp_error( $current ) || $current->name !== $expected_after['name'] || $current->slug !== $expected_after['slug'] || $current->description !== $expected_after['description'] || (int) $current->parent !== (int) $expected_after['parent'] ) {
				return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'This term was changed after the batch. Restore it manually or review the current values before retrying.', 'taxonomy-tidy' ) );
			}
			return self::restore_renamed_term( $source_id, $taxonomy_name, $snapshot );
		}

		if ( $current && ! is_wp_error( $current ) ) {
			if ( ! $automatic ) {
				$original_term_is_present = $resume && $current->name === $snapshot['before']['name'] && $current->slug === $snapshot['before']['slug'] && $current->description === $snapshot['before']['description'] && (int) $current->parent === (int) $snapshot['before']['parent'] && (int) $current->term_taxonomy_id === (int) $snapshot['taxonomy_row']['term_taxonomy_id'];
				if ( ! $original_term_is_present ) {
					return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'A term with the original ID now exists. The batch was left unchanged.', 'taxonomy-tidy' ) );
				}
			} elseif ( 'merge' === $operation->action && self::source_matches_before( $current, $snapshot ) ) {
				return self::undo_partial_merge( $operation_id, $snapshot, $current, $taxonomy_name );
			} elseif ( 'delete' === $operation->action && self::source_matches_before( $current, $snapshot ) ) {
				return self::restore_child_hierarchy( $operation, $snapshot, $taxonomy_name, true );
			} else {
				return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'The term still exists, so automatic restoration stopped before overwriting it.', 'taxonomy-tidy' ) );
			}
		}

		$term_row = $snapshot['term_row'];
		$tt_row   = $snapshot['taxonomy_row'];
		$tt_id    = (int) $tt_row['term_taxonomy_id'];
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		$existing_tt = $wpdb->get_row( $wpdb->prepare( "SELECT term_taxonomy_id, term_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ), ARRAY_A );
		if ( $existing_tt && ( (int) $existing_tt['term_id'] !== $source_id || $existing_tt['taxonomy'] !== $taxonomy_name ) ) {
			return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'The original taxonomy row ID is now in use. Nothing was overwritten.', 'taxonomy-tidy' ) );
		}
		if ( ! self::term_slug_available( $term_row['slug'], $source_id, $taxonomy_name ) ) {
			return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'Another term now uses the original slug. The batch was left unchanged.', 'taxonomy-tidy' ) );
		}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		$existing_term = $wpdb->get_row( $wpdb->prepare( "SELECT term_id, name, slug, term_group FROM {$wpdb->terms} WHERE term_id = %d", $source_id ), ARRAY_A );
		if ( $existing_term ) {
			if ( $existing_term['name'] !== $term_row['name'] || $existing_term['slug'] !== $term_row['slug'] ) {
				return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'The original term ID has been reused with different data. Nothing was overwritten.', 'taxonomy-tidy' ) );
			}
		} elseif ( ! self::insert_row( $wpdb->terms, $term_row ) ) {
				return new \WP_Error( 'taxonomy_tidy_restore_failed', __( 'WordPress could not restore the original term row.', 'taxonomy-tidy' ) );
		}

		if ( ! $existing_tt && ! self::insert_row( $wpdb->term_taxonomy, $tt_row ) ) {
			return new \WP_Error( 'taxonomy_tidy_restore_failed', __( 'WordPress could not restore the original taxonomy row.', 'taxonomy-tidy' ) );
		}

		if ( isset( $wpdb->termmeta ) ) {
			$expected_meta = array();
			wp_cache_delete( $source_id, 'term_meta' );
			$existing_meta        = get_metadata( 'term', $source_id );
			$existing_meta_counts = array();
			foreach ( $existing_meta as $existing_key => $existing_values ) {
				foreach ( (array) $existing_values as $existing_value ) {
					$existing_identity                          = hash( 'sha256', (string) $existing_key . "\0" . maybe_serialize( $existing_value ) );
					$existing_meta_counts[ $existing_identity ] = isset( $existing_meta_counts[ $existing_identity ] ) ? $existing_meta_counts[ $existing_identity ] + 1 : 1;
				}
			}
			foreach ( (array) $snapshot['term_meta'] as $meta ) {
				$meta_key                   = (string) $meta['meta_key'];
				$meta_value                 = (string) $meta['meta_value'];
				$identity                   = hash( 'sha256', $meta_key . "\0" . $meta_value );
				$expected_meta[ $identity ] = isset( $expected_meta[ $identity ] ) ? $expected_meta[ $identity ] + 1 : 1;
				if ( ! isset( $existing_meta_counts[ $identity ] ) || $existing_meta_counts[ $identity ] < $expected_meta[ $identity ] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- This snapshot reads metadata only for the single term being changed so rollback can restore its exact metadata rows.
					if ( false === $wpdb->insert(
						$wpdb->termmeta,
						array(
							'term_id'    => $source_id,
							// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Restoring an exact saved termmeta key for this single term is required for rollback.
							'meta_key'   => $meta_key,
							// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Restoring an exact saved termmeta value for this single term is required for rollback.
							'meta_value' => $meta_value,
						),
						array( '%d', '%s', '%s' )
					) ) {
						return new \WP_Error( 'taxonomy_tidy_restore_failed', __( 'The term was restored, but some term metadata could not be restored.', 'taxonomy-tidy' ) );
					}
					$existing_meta_counts[ $identity ] = isset( $existing_meta_counts[ $identity ] ) ? $existing_meta_counts[ $identity ] + 1 : 1;
				}
				if ( $existing_meta_counts[ $identity ] < $expected_meta[ $identity ] ) {
					return new \WP_Error( 'taxonomy_tidy_restore_failed', __( 'The term was restored, but some term metadata could not be restored.', 'taxonomy-tidy' ) );
				}
			}
			wp_cache_delete( $source_id, 'term_meta' );
		}

		$children_restored = self::restore_child_hierarchy( $operation, $snapshot, $taxonomy_name, $resume );
		if ( is_wp_error( $children_restored ) ) {
			return $children_restored;
		}

		$relations = self::table( 'relationships' );
		$last_id   = 0;
		while ( true ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, object_id, term_order, had_target FROM %i WHERE operation_id = %d AND id > %d ORDER BY id ASC LIMIT 500', $relations, $operation_id, $last_id ),
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
				$existing_relationship = $wpdb->get_row(
					$wpdb->prepare( "SELECT object_id, term_order FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id = %d", (int) $row['object_id'], $tt_id ),
					ARRAY_A
				);
				if ( $existing_relationship && (int) $existing_relationship['term_order'] !== (int) $row['term_order'] ) {
					return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'An original assignment was changed after the merge. Review it before rolling back this batch.', 'taxonomy-tidy' ) );
				}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
				if ( ! $existing_relationship && false === $wpdb->insert(
					$wpdb->term_relationships,
					array(
						'object_id'        => (int) $row['object_id'],
						'term_taxonomy_id' => $tt_id,
						'term_order'       => (int) $row['term_order'],
					),
					array( '%d', '%d', '%d' )
				) ) {
					return new \WP_Error( 'taxonomy_tidy_restore_failed', __( 'The term was restored, but an original object assignment could not be restored.', 'taxonomy-tidy' ) );
				}
				if ( 'merge' === $operation->action && ! (int) $row['had_target'] && (int) $operation->target_term_id > 0 ) {
					$removed = wp_remove_object_terms( (int) $row['object_id'], (int) $operation->target_term_id, $taxonomy_name );
					if ( is_wp_error( $removed ) ) {
						return $removed;
					}
				}
				$last_id = (int) $row['id'];
			}
			if ( count( $rows ) < 500 ) {
				break;
			}
		}

		wp_update_term_count_now( array( $tt_id ), $taxonomy_name );
		if ( (int) $operation->target_term_id > 0 ) {
			$target = get_term( (int) $operation->target_term_id, $taxonomy_name );
			if ( $target && ! is_wp_error( $target ) ) {
				wp_update_term_count_now( array( (int) $target->term_taxonomy_id ), $taxonomy_name );
			}
		}
		self::refresh_caches( $source_id, $taxonomy_name );
		return true;
	}

	/** Undo only relationships added by an interrupted merge while the source still exists. */
	private static function undo_partial_merge( $operation_id, $snapshot, $source, $taxonomy_name ) {
		global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		$operation          = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( 'operations' ), absint( $operation_id ) ) );
		$hierarchy_restored = self::restore_child_hierarchy( $operation, $snapshot, $taxonomy_name, true );
		if ( is_wp_error( $hierarchy_restored ) ) {
			return $hierarchy_restored;
		}
		$relations = self::table( 'relationships' );
		$target    = (int) $snapshot['target_before']['id'];
		$last_id   = 0;
		while ( true ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, object_id, term_order, had_target FROM %i WHERE operation_id = %d AND id > %d ORDER BY id ASC LIMIT 500', $relations, $operation_id, $last_id ), ARRAY_A );
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$object_id = (int) $row['object_id'];
				$result    = wp_set_object_terms( $object_id, (int) $source->term_id, $taxonomy_name, true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
				$wpdb->update(
					$wpdb->term_relationships,
					array( 'term_order' => (int) $row['term_order'] ),
					array(
						'object_id'        => $object_id,
						'term_taxonomy_id' => (int) $source->term_taxonomy_id,
					),
					array( '%d' ),
					array( '%d', '%d' )
				);
				if ( ! (int) $row['had_target'] && $target > 0 ) {
					$result = wp_remove_object_terms( $object_id, $target, $taxonomy_name );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				$last_id = (int) $row['id'];
			}
			if ( count( $rows ) < 500 ) {
				break;
			}
		}
		foreach ( (array) $snapshot['children'] as $child ) {
			if ( term_exists( (int) $child['term_id'], $taxonomy_name ) ) {
				wp_update_term( (int) $child['term_id'], $taxonomy_name, array( 'parent' => (int) $child['parent'] ) );
			}
		}
		wp_update_term_count_now( array( (int) $source->term_taxonomy_id ), $taxonomy_name );
		if ( $target > 0 ) {
			$target_term = get_term( $target, $taxonomy_name );
			if ( $target_term && ! is_wp_error( $target_term ) ) {
				wp_update_term_count_now( array( (int) $target_term->term_taxonomy_id ), $taxonomy_name );
			}
		}
		self::refresh_caches( (int) $source->term_id, $taxonomy_name );
		return true;
	}

	/** Protect WordPress default terms from being removed as merge sources. */
	private static function is_default_term( $term, $taxonomy_name ) {
		if ( 'category' === $taxonomy_name && (int) get_option( 'default_category' ) === (int) $term->term_id ) {
			return true;
		}
		$taxonomy = get_taxonomy( $taxonomy_name );
		return $taxonomy && ! empty( $taxonomy->default_term ) && (int) get_option( 'default_term_' . $taxonomy_name ) === (int) $term->term_id;
	}

	/** Determine the intended post-apply parent, including snapshots created before this field existed. */
	private static function expected_child_parent( $operation, $snapshot, $child ) {
		if ( isset( $child['after_parent'] ) ) {
			return (int) $child['after_parent'];
		}
		if ( 'delete' === $operation->action ) {
			return (int) $snapshot['before']['parent'];
		}
		return (int) $child['term_id'] === (int) $operation->target_term_id ? (int) $snapshot['before']['parent'] : (int) $operation->target_term_id;
	}

	/** Refuse hierarchy rollback if a child was deleted or changed after the previewed operation. */
	private static function preflight_child_hierarchy( $operation, $snapshot, $taxonomy_name, $allow_before ) {
		foreach ( (array) $snapshot['children'] as $child ) {
			$term = get_term( (int) $child['term_id'], $taxonomy_name );
			if ( ! $term || is_wp_error( $term ) ) {
				return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'A child term was removed after this change. Review the hierarchy before rolling back.', 'taxonomy-tidy' ) );
			}
			$current_parent = (int) $term->parent;
			$before_parent  = (int) $child['parent'];
			$after_parent   = self::expected_child_parent( $operation, $snapshot, $child );
			if ( $current_parent !== $after_parent && ( ! $allow_before || $current_parent !== $before_parent ) ) {
				return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'A child term was moved after this change. Review its parent before rolling back.', 'taxonomy-tidy' ) );
			}
		}
		return true;
	}

	/** Restore saved child parents after preflight and verify each WordPress update. */
	private static function restore_child_hierarchy( $operation, $snapshot, $taxonomy_name, $allow_before ) {
		$ready = self::preflight_child_hierarchy( $operation, $snapshot, $taxonomy_name, $allow_before );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		foreach ( (array) $snapshot['children'] as $child ) {
			$child_id      = (int) $child['term_id'];
			$before_parent = (int) $child['parent'];
			$current       = get_term( $child_id, $taxonomy_name );
			if ( (int) $current->parent === $before_parent ) {
				continue;
			}
			$result = wp_update_term( $child_id, $taxonomy_name, array( 'parent' => $before_parent ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$restored = get_term( $child_id, $taxonomy_name );
			if ( ! $restored || is_wp_error( $restored ) || (int) $restored->parent !== $before_parent ) {
				return new \WP_Error( 'taxonomy_tidy_restore_failed', __( 'The source term is restored, but a child parent could not be restored.', 'taxonomy-tidy' ) );
			}
		}
		return true;
	}

	/** Check that the canonical assignments created by the merge are still present before rollback. */
	private static function preflight_merge_relationships( $operation_id, $target_tt_id ) {
		global $wpdb;
		$relations = self::table( 'relationships' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		$missing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT saved.object_id FROM %i AS saved LEFT JOIN %i AS live ON live.object_id = saved.object_id AND live.term_taxonomy_id = %d WHERE saved.operation_id = %d AND live.object_id IS NULL LIMIT 1',
				$relations,
				$wpdb->term_relationships,
				(int) $target_tt_id,
				(int) $operation_id
			)
		);
		if ( null !== $missing ) {
			return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'A canonical assignment changed after the merge. Restore it or review the batch before rolling back.', 'taxonomy-tidy' ) );
		}
		return true;
	}

	/** Refuse interrupted merge recovery when target assignments changed during the interruption. */
	private static function preflight_interrupted_merge( $operation_id, $target_tt_id ) {
		global $wpdb;
		$relations = self::table( 'relationships' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare saved target-assignment state with live relationships before resuming an interrupted merge.
		$conflict = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT saved.object_id FROM %i AS saved LEFT JOIN %i AS live ON live.object_id = saved.object_id AND live.term_taxonomy_id = %d WHERE saved.operation_id = %d AND ((saved.had_target = 1 AND live.object_id IS NULL) OR (saved.had_target = 0 AND live.object_id IS NOT NULL)) LIMIT 1',
				$relations,
				$wpdb->term_relationships,
				(int) $target_tt_id,
				(int) $operation_id
			)
		);
		if ( null !== $conflict ) {
			return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'A canonical assignment changed while the merge was interrupted. Review that object assignment manually, then retry recovery.', 'taxonomy-tidy' ) );
		}
		return true;
	}

	/** Check whether a taxonomy can still receive an exact copy of a saved slug. */
	private static function term_slug_available( $slug, $source_id, $taxonomy_name ) {
		$existing = get_term_by( 'slug', (string) $slug, $taxonomy_name );
		return ! $existing || is_wp_error( $existing ) || (int) $existing->term_id === (int) $source_id;
	}

	/** Confirm that a source term still contains its pre-apply identity before compensating a partial delete/merge. */
	private static function source_matches_before( $term, $snapshot ) {
		return $term->name === $snapshot['before']['name'] && $term->slug === $snapshot['before']['slug'] && $term->description === $snapshot['before']['description'] && (int) $term->parent === (int) $snapshot['before']['parent'] && (int) $term->term_taxonomy_id === (int) $snapshot['taxonomy_row']['term_taxonomy_id'];
	}

	/** Restore a rename only when its old slug is free, and verify WordPress kept it exact. */
	private static function restore_renamed_term( $source_id, $taxonomy_name, $snapshot ) {
		if ( ! self::term_slug_available( $snapshot['before']['slug'], $source_id, $taxonomy_name ) ) {
			return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'Another term now uses the original slug. The rename was left unchanged.', 'taxonomy-tidy' ) );
		}
		$restored = wp_update_term(
			$source_id,
			$taxonomy_name,
			array(
				'name'        => $snapshot['before']['name'],
				'slug'        => $snapshot['before']['slug'],
				'description' => $snapshot['before']['description'],
				'parent'      => (int) $snapshot['before']['parent'],
			)
		);
		if ( is_wp_error( $restored ) ) {
			return $restored;
		}
		$current = get_term( $source_id, $taxonomy_name );
		if ( $current && ! is_wp_error( $current ) && $current->slug === $snapshot['before']['slug'] && $current->name === $snapshot['before']['name'] && $current->description === $snapshot['before']['description'] && (int) $current->parent === (int) $snapshot['before']['parent'] ) {
			return true;
		}
		if ( ! $current || is_wp_error( $current ) || $current->name !== $snapshot['before']['name'] || $current->description !== $snapshot['before']['description'] || (int) $current->parent !== (int) $snapshot['before']['parent'] ) {
			return new \WP_Error( 'taxonomy_tidy_restore_failed', __( 'The original slug became unavailable during rollback. Review the term manually before retrying.', 'taxonomy-tidy' ) );
		}
		$compensated = wp_update_term(
			$source_id,
			$taxonomy_name,
			array(
				'name'        => $snapshot['after']['name'],
				'slug'        => $snapshot['after']['slug'],
				'description' => $snapshot['after']['description'],
				'parent'      => (int) $snapshot['after']['parent'],
			)
		);
		$current     = get_term( $source_id, $taxonomy_name );
		if ( is_wp_error( $compensated ) || ! $current || is_wp_error( $current ) || $current->slug !== $snapshot['after']['slug'] ) {
			return new \WP_Error( 'taxonomy_tidy_restore_failed', __( 'The original slug became unavailable during rollback, and WordPress could not restore the renamed state. Review this term manually.', 'taxonomy-tidy' ) );
		}
		return new \WP_Error( 'taxonomy_tidy_rollback_conflict', __( 'The original slug became unavailable during rollback. The renamed term was restored to its saved state.', 'taxonomy-tidy' ) );
	}

	/** Insert an exact saved core row while checking the database result. */
	private static function insert_row( $table, $row ) {
		global $wpdb;
		$formats = array();
		foreach ( $row as $key => $value ) {
			$formats[] = in_array( $key, array( 'term_id', 'term_taxonomy_id', 'term_group', 'parent', 'count' ), true ) ? '%d' : '%s';
		}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This direct write is required for the local audit journal or exact ID-preserving rollback; the site has no equivalent WordPress API for these rows.
		return false !== $wpdb->insert( $table, $row, $formats );
	}

	/** Clear term and object caches after exact row restoration. */
	private static function refresh_caches( $term_id, $taxonomy_name ) {
		clean_term_cache( array( (int) $term_id ), $taxonomy_name, true );
		$taxonomy = get_taxonomy( $taxonomy_name );
		if ( $taxonomy ) {
			$objects = get_objects_in_term( (int) $term_id, $taxonomy_name );
			if ( ! is_wp_error( $objects ) && $taxonomy->object_type ) {
				foreach ( $taxonomy->object_type as $object_type ) {
					clean_object_term_cache( $objects, $object_type );
				}
			}
		}
	}

	/** Build a small, export-safe batch summary. */
	private static function summary_json( $preview ) {
		return wp_json_encode(
			array(
				'action'         => $preview['action'],
				'taxonomy'       => $preview['taxonomy']->name,
				'source_name'    => $preview['source']->name,
				'target_name'    => $preview['target'] ? $preview['target']->name : ( isset( $preview['new_name'] ) ? $preview['new_name'] : '' ),
				'affected_count' => (int) $preview['object_count'],
				'old_url'        => $preview['old_url'],
				'new_url'        => $preview['new_url'],
			)
		);
	}

	/** List recent batches for the activity table. */
	public static function recent_batches( $limit = 50 ) {
		global $wpdb;
		$table = self::table( 'batches' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, absint( $limit ) ) );
	}

	/** Export summaries without exposing serialized rollback snapshots. */
	public static function export_rows() {
		global $wpdb;
		$batches = self::table( 'batches' );
		$ops     = self::table( 'operations' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This fresh, prepared read is required to verify the exact term and relationship state before applying or restoring a change.
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT b.id, b.batch_uuid, b.status, b.created_at, o.action, o.taxonomy, o.source_term_id, o.target_term_id, o.affected_count, o.snapshot FROM %i b LEFT JOIN %i o ON o.batch_id = b.id ORDER BY b.id DESC', $batches, $ops ), ARRAY_A );
		$output = array();
		foreach ( $rows as $row ) {
			$snapshot = json_decode( $row['snapshot'], true );
			$output[] = array(
				'batch_id'         => $row['batch_uuid'],
				'status'           => $row['status'],
				'action'           => $row['action'],
				'taxonomy'         => $row['taxonomy'],
				'source_term_id'   => (int) $row['source_term_id'],
				'source_before'    => isset( $snapshot['before']['name'] ) ? $snapshot['before']['name'] : '',
				'target_term_id'   => (int) $row['target_term_id'],
				'target_name'      => isset( $snapshot['target_before']['name'] ) ? $snapshot['target_before']['name'] : ( isset( $snapshot['after']['name'] ) ? $snapshot['after']['name'] : '' ),
				'affected_objects' => (int) $row['affected_count'],
				'created_at_utc'   => $row['created_at'],
				'old_archive_url'  => isset( $snapshot['old_url'] ) ? $snapshot['old_url'] : '',
				'new_archive_url'  => isset( $snapshot['new_url'] ) ? $snapshot['new_url'] : '',
			);
		}
		return $output;
	}
}
