<?php
/** Privileged, nonce-checked admin actions. */
namespace TaxonomyTidy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Actions {
	/** @var Actions|null */
	private static $instance = null;

	/** @return Actions */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Register only authenticated administrative routes. */
	public function register() {
		add_action( 'admin_post_taxonomy_tidy_scan', array( $this, 'scan' ) );
		add_action( 'admin_post_taxonomy_tidy_preview', array( $this, 'preview' ) );
		add_action( 'admin_post_taxonomy_tidy_apply', array( $this, 'apply' ) );
		add_action( 'admin_post_taxonomy_tidy_bulk_preview', array( $this, 'bulk_preview' ) );
		add_action( 'admin_post_taxonomy_tidy_bulk_apply', array( $this, 'bulk_apply' ) );
		add_action( 'admin_post_taxonomy_tidy_rollback', array( $this, 'rollback' ) );
		add_action( 'admin_post_taxonomy_tidy_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_taxonomy_tidy_export', array( $this, 'export' ) );
	}

	/** Run a local, read-only scan and save its report briefly for display. */
	public function scan() {
		check_admin_referer( 'taxonomy_tidy_scan' );
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You do not have permission to scan taxonomy terms.', 'taxonomy-tidy' ) );
		}
		$settings = get_option( 'taxonomy_tidy_settings', array() );
		$selected = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		if ( '' === $selected ) {
			$report = Scanner::scan_all( ! empty( $settings['include_private'] ) );
		} else {
			$scan   = Scanner::scan_taxonomy( $selected );
			$report = is_wp_error( $scan ) ? array() : array( $selected => $scan );
		}
		$token = wp_generate_password( 24, false, false );
		set_transient(
			'taxonomy_tidy_scan_' . $token,
			array(
				'user_id' => get_current_user_id(),
				'report'  => $report,
			),
			15 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'taxonomy-tidy',
					'scan' => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Validate a proposed change, fingerprint its preview, and redirect to review. */
	public function preview() {
		check_admin_referer( 'taxonomy_tidy_preview' );
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview taxonomy changes.', 'taxonomy-tidy' ) );
		}
		$action    = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$taxonomy  = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
		$target_id = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;
		$new_name  = isset( $_POST['new_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_name'] ) ) : '';
		$new_slug  = isset( $_POST['new_slug'] ) ? sanitize_title( wp_unslash( $_POST['new_slug'] ) ) : '';
		$redirects = isset( $_POST['create_redirects'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['create_redirects'] ) );
		$options   = array( 'create_redirects' => $redirects );
		$preview   = Audit::preview( $action, $taxonomy, $source_id, $target_id, $new_name, $new_slug, $options );
		if ( is_wp_error( $preview ) ) {
			$this->redirect_notice( $preview->get_error_message(), 'error' );
		}
		$token = wp_generate_password( 40, false, false );
		set_transient(
			'taxonomy_tidy_preview_' . $token,
			array(
				'user_id'          => get_current_user_id(),
				'action'           => $action,
				'taxonomy'         => $taxonomy,
				'source_id'        => $source_id,
				'target_id'        => $target_id,
				'new_name'         => $new_name,
				'new_slug'         => $new_slug,
				'create_redirects' => $redirects,
				'fingerprint'      => Audit::impact_fingerprint( $preview ),
			),
			20 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'taxonomy-tidy',
					'view'  => 'preview',
					'token' => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Recheck that the preview is still current before applying the user's explicit confirmation. */
	public function apply() {
		$token = isset( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_token'] ) ) : '';
		$state = $token ? get_transient( 'taxonomy_tidy_preview_' . $token ) : false;
		if ( ! is_array( $state ) || get_current_user_id() !== (int) $state['user_id'] ) {
			$this->redirect_notice( __( 'This preview has expired. Review the change again before applying it.', 'taxonomy-tidy' ), 'error' );
		}
		check_admin_referer( 'taxonomy_tidy_apply_' . $token );
		$options = array( 'create_redirects' => ! empty( $state['create_redirects'] ) );
		$preview = Audit::preview( $state['action'], $state['taxonomy'], $state['source_id'], $state['target_id'], $state['new_name'], $state['new_slug'], $options );
		if ( is_wp_error( $preview ) || ! hash_equals( $state['fingerprint'], Audit::impact_fingerprint( $preview ) ) ) {
			delete_transient( 'taxonomy_tidy_preview_' . $token );
			$this->redirect_notice( __( 'The terms or affected objects changed after the preview. Review the updated impact before applying.', 'taxonomy-tidy' ), 'error' );
		}
		if ( ! isset( $_POST['confirm'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) {
			$this->redirect_notice( __( 'Confirm that you reviewed the impact before applying this change.', 'taxonomy-tidy' ), 'error' );
		}
		delete_transient( 'taxonomy_tidy_preview_' . $token );
		if ( 'merge' === $state['action'] ) {
			$result = Audit::merge( $state['taxonomy'], $state['source_id'], $state['target_id'], $options );
		} elseif ( 'rename' === $state['action'] ) {
			$result = Audit::rename( $state['taxonomy'], $state['source_id'], $state['new_name'], $state['new_slug'] );
		} else {
			$result = Audit::delete_empty( $state['taxonomy'], $state['source_id'] );
		}
		if ( is_wp_error( $result ) ) {
			$this->redirect_notice( $result->get_error_message(), 'error' );
		}
		/* translators: %s is the unique identifier for the saved audit batch. */
		$message = sprintf( __( 'Change saved. Batch ID: %s. Review it in Activity to export or roll it back.', 'taxonomy-tidy' ), $result['batch_uuid'] );
		$this->redirect_notice( $message, 'success', 'taxonomy-tidy-activity' );
	}

	/** Validate scan-bound selections and store one fingerprinted bulk review. */
	public function bulk_preview() {
		check_admin_referer( 'taxonomy_tidy_bulk_preview' );
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview taxonomy changes.', 'taxonomy-tidy' ) );
		}
		$mode       = isset( $_POST['bulk_operation'] ) && is_scalar( $_POST['bulk_operation'] ) ? sanitize_key( wp_unslash( $_POST['bulk_operation'] ) ) : '';
		$taxonomy   = isset( $_POST['taxonomy'] ) && is_scalar( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$scan_token = isset( $_POST['scan_token'] ) && is_scalar( $_POST['scan_token'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_token'] ) ) : '';
		$scan       = $scan_token ? get_transient( 'taxonomy_tidy_scan_' . $scan_token ) : false;
		if ( ! in_array( $mode, array( 'merge', 'delete' ), true ) || ! is_array( $scan ) || get_current_user_id() !== (int) $scan['user_id'] || empty( $scan['report'][ $taxonomy ] ) ) {
			$this->redirect_notice( __( 'The scan report expired or no longer matches this selection. Run the scan again.', 'taxonomy-tidy' ), 'error' );
		}
		$report    = $scan['report'][ $taxonomy ];
		$items     = array();
		$redirects = 'merge' === $mode && isset( $_POST['create_redirects'] ) && is_scalar( $_POST['create_redirects'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['create_redirects'] ) );
		if ( 'merge' === $mode ) {
			$selected = $this->posted_id_list( 'merge_groups', true );
			if ( isset( $_POST['review_group'] ) && is_scalar( $_POST['review_group'] ) ) {
				$selected = array( absint( wp_unslash( $_POST['review_group'] ) ) );
			}
			if ( empty( $selected ) ) {
				$this->redirect_notice( __( 'Select at least one duplicate group to review.', 'taxonomy-tidy' ), 'error' );
			}
			$canonical = array();
			if ( isset( $_POST['canonical'] ) && is_array( $_POST['canonical'] ) ) {
				$submitted_canonical = map_deep( wp_unslash( $_POST['canonical'] ), 'sanitize_text_field' );
				foreach ( $submitted_canonical as $group_key => $value ) {
					if ( is_scalar( $value ) ) {
						$canonical[ absint( sanitize_text_field( (string) $group_key ) ) ] = absint( $value );
					}
				}
			}
			foreach ( $selected as $group_index ) {
				if ( ! isset( $report['groups'][ $group_index ] ) ) {
					$this->redirect_notice( __( 'A selected duplicate group is no longer in the saved scan. Run the scan again.', 'taxonomy-tidy' ), 'error' );
				}
				$group      = $report['groups'][ $group_index ];
				$target_id  = isset( $canonical[ $group_index ] ) ? (int) $canonical[ $group_index ] : 0;
				$member_ids = array_map( 'intval', wp_list_pluck( $group['members'], 'term_id' ) );
				if ( ! in_array( $target_id, $member_ids, true ) ) {
					$this->redirect_notice( __( 'Choose a canonical term from each selected duplicate group.', 'taxonomy-tidy' ), 'error' );
				}
				foreach ( $member_ids as $source_id ) {
					if ( $source_id === $target_id ) {
						continue;
					}
					$preview = Audit::preview( 'merge', $taxonomy, $source_id, $target_id, '', '', array( 'create_redirects' => $redirects ) );
					if ( is_wp_error( $preview ) ) {
						$this->redirect_notice( $preview->get_error_message(), 'error' );
					}
					$items[] = array(
						'action'           => 'merge',
						'taxonomy'         => $taxonomy,
						'source_id'        => $source_id,
						'target_id'        => $target_id,
						'create_redirects' => $redirects,
						'fingerprint'      => Audit::impact_fingerprint( $preview ),
					);
				}
			}
		} else {
			$selected  = $this->posted_id_list( 'empty_terms' );
			$empty_ids = array_map( 'intval', wp_list_pluck( $report['empty'], 'term_id' ) );
			if ( empty( $selected ) ) {
				$this->redirect_notice( __( 'Select at least one empty term to review.', 'taxonomy-tidy' ), 'error' );
			}
			foreach ( $selected as $source_id ) {
				if ( ! in_array( $source_id, $empty_ids, true ) ) {
					$this->redirect_notice( __( 'A selected term is no longer in the saved empty-term scan. Run the scan again.', 'taxonomy-tidy' ), 'error' );
				}
				$preview = Audit::preview( 'delete', $taxonomy, $source_id );
				if ( is_wp_error( $preview ) ) {
					$this->redirect_notice( $preview->get_error_message(), 'error' );
				}
				$items[] = array(
					'action'           => 'delete',
					'taxonomy'         => $taxonomy,
					'source_id'        => $source_id,
					'target_id'        => 0,
					'create_redirects' => false,
					'fingerprint'      => Audit::impact_fingerprint( $preview ),
				);
			}
		}
		if ( count( $items ) > 100 ) {
			$this->redirect_notice( __( 'One batch can contain up to 100 term changes. Select fewer groups or terms and review the remaining items in another batch.', 'taxonomy-tidy' ), 'error' );
		}
		$error = $this->validate_batch_hierarchy( $items );
		if ( is_wp_error( $error ) ) {
			$this->redirect_notice( $error->get_error_message(), 'error' );
		}
		$token = wp_generate_password( 40, false, false );
		set_transient(
			'taxonomy_tidy_bulk_preview_' . $token,
			array(
				'user_id'  => get_current_user_id(),
				'mode'     => $mode,
				'taxonomy' => $taxonomy,
				'items'    => $items,
			),
			20 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'taxonomy-tidy',
					'view'  => 'bulk-preview',
					'token' => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Rebuild every preview fingerprint before applying one shared audit batch. */
	public function bulk_apply() {
		$token = isset( $_POST['preview_token'] ) && is_scalar( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_token'] ) ) : '';
		$state = $token ? get_transient( 'taxonomy_tidy_bulk_preview_' . $token ) : false;
		if ( ! is_array( $state ) || get_current_user_id() !== (int) $state['user_id'] ) {
			$this->redirect_notice( __( 'This batch preview expired. Review the selected terms again before applying.', 'taxonomy-tidy' ), 'error' );
		}
		check_admin_referer( 'taxonomy_tidy_bulk_apply_' . $token );
		if ( ! isset( $_POST['confirm'] ) || ! is_scalar( $_POST['confirm'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) {
			$this->redirect_notice( __( 'Confirm that you reviewed every item in this batch before applying it.', 'taxonomy-tidy' ), 'error' );
		}
		$previews = array();
		foreach ( $state['items'] as $item ) {
			$preview = Audit::preview( $item['action'], $item['taxonomy'], $item['source_id'], $item['target_id'], '', '', array( 'create_redirects' => ! empty( $item['create_redirects'] ) ) );
			if ( is_wp_error( $preview ) || ! hash_equals( $item['fingerprint'], Audit::impact_fingerprint( $preview ) ) ) {
				delete_transient( 'taxonomy_tidy_bulk_preview_' . $token );
				$this->redirect_notice( __( 'At least one term or assignment changed after this review. Run the scan and prepare a new batch preview.', 'taxonomy-tidy' ), 'error' );
			}
			$previews[] = $preview;
		}
		delete_transient( 'taxonomy_tidy_bulk_preview_' . $token );
		$result = Audit::apply_batch( $previews );
		if ( is_wp_error( $result ) ) {
			$this->redirect_notice( $result->get_error_message(), 'error', 'taxonomy-tidy-activity' );
		}
		/* translators: %s is the unique identifier for the saved audit batch. */
		$message = sprintf( __( 'Batch saved. Batch ID: %s. Review Activity to export or roll back all changes together.', 'taxonomy-tidy' ), $result['batch_uuid'] );
		$this->redirect_notice( $message, 'success', 'taxonomy-tidy-activity' );
	}

	/** Reject selected operations whose parent-child changes would stale another preview. */
	private function validate_batch_hierarchy( $items ) {
		if ( count( $items ) < 2 ) {
			return true;
		}
		$participants = array();
		foreach ( $items as $item ) {
			$participants[] = array( absint( $item['source_id'] ), absint( $item['target_id'] ) );
		}
		$participant_count = count( $participants );
		for ( $left = 0; $left < $participant_count; $left++ ) {
			for ( $right = $left + 1; $right < $participant_count; $right++ ) {
				foreach ( $participants[ $left ] as $left_id ) {
					foreach ( $participants[ $right ] as $right_id ) {
						if ( ! $left_id || ! $right_id || $left_id === $right_id ) {
							continue;
						}
						if ( $this->is_ancestor_term( $left_id, $right_id, $items[0]['taxonomy'] ) || $this->is_ancestor_term( $right_id, $left_id, $items[0]['taxonomy'] ) ) {
							return new \WP_Error( 'taxonomy_tidy_batch_hierarchy_overlap', __( 'These selections include related parent and child terms. Review them in separate batches so every hierarchy preview stays exact.', 'taxonomy-tidy' ) );
						}
					}
				}
			}
		}
		return true;
	}

	/** Check ancestry without loading unrelated terms. */
	private function is_ancestor_term( $ancestor_id, $term_id, $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );
		$seen = array();
		while ( $term && ! is_wp_error( $term ) && (int) $term->parent > 0 ) {
			$parent_id = (int) $term->parent;
			if ( $parent_id === (int) $ancestor_id ) {
				return true;
			}
			if ( isset( $seen[ $parent_id ] ) ) {
				break;
			}
			$seen[ $parent_id ] = true;
			$term               = get_term( $parent_id, $taxonomy );
		}
		return false;
	}

	/** Return a unique list of positive scalar IDs from a submitted checkbox group. */
	private function posted_id_list( $key, $allow_zero = false ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This helper is called only after bulk_preview() verifies the bulk preview nonce.
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}
		$ids = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This helper is called only after bulk_preview() verifies the bulk preview nonce.
		$posted_values = map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_text_field' );
		foreach ( $posted_values as $value ) {
			if ( is_scalar( $value ) && 1 === preg_match( '/^[0-9]+$/', (string) $value ) ) {
				$id = absint( $value );
				if ( $allow_zero || $id ) {
					$ids[] = $id;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Roll back an applied batch after dynamic capability checks in the audit service. */
	public function rollback() {
		$batch_id = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		check_admin_referer( 'taxonomy_tidy_rollback_' . $batch_id );
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You do not have permission to roll back this batch.', 'taxonomy-tidy' ) );
		}
		$result = Audit::rollback_batch( $batch_id );
		if ( is_wp_error( $result ) ) {
			$this->redirect_notice( $result->get_error_message(), 'error', 'taxonomy-tidy-activity' );
		}
		$this->redirect_notice( __( 'The saved term, hierarchy, and assignment data was restored.', 'taxonomy-tidy' ), 'success', 'taxonomy-tidy-activity' );
	}

	/** Save the small set of site-local safety settings. */
	public function save_settings() {
		check_admin_referer( 'taxonomy_tidy_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'taxonomy-tidy' ) );
		}
		$settings = array(
			'include_private'          => isset( $_POST['include_private'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['include_private'] ) ),
			'redirect_merged_terms'    => isset( $_POST['redirect_merged_terms'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['redirect_merged_terms'] ) ),
			'delete_data_on_uninstall' => isset( $_POST['delete_data_on_uninstall'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['delete_data_on_uninstall'] ) ),
		);
		update_option( 'taxonomy_tidy_settings', $settings, false );
		$this->redirect_notice( __( 'Settings saved.', 'taxonomy-tidy' ), 'success', 'taxonomy-tidy-settings' );
	}

	/** Export the review-safe activity summary as JSON or CSV. */
	public function export() {
		check_admin_referer( 'taxonomy_tidy_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only an administrator can export the activity report.', 'taxonomy-tidy' ) );
		}
		$rows   = Audit::export_rows();
		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'csv';
		nocache_headers();
		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=taxonomy-tidy-audit.json' );
			echo wp_json_encode( $rows, JSON_PRETTY_PRINT );
			exit;
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=taxonomy-tidy-audit.csv' );
		$output = fopen( 'php://output', 'w' );
		if ( $output ) {
			if ( ! empty( $rows ) ) {
				fputcsv( $output, array_keys( $rows[0] ) );
				foreach ( $rows as $row ) {
					$safe_row = array();
					foreach ( $row as $value ) {
						$value = (string) $value;
						if ( ! is_numeric( $value ) && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
							$value = "'" . $value;
						}
						$safe_row[] = $value;
					}
					fputcsv( $output, $safe_row );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This is the response stream (php://output), not a filesystem file.
			fclose( $output );
		}
		exit;
	}

	/** Redirect with a short-lived, escaped notice. */
	private function redirect_notice( $message, $type, $page = 'taxonomy-tidy' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => $page,
					'notice'      => $message,
					'notice_type' => sanitize_key( $type ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
