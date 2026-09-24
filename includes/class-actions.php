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
		$preview   = Audit::preview( $action, $taxonomy, $source_id, $target_id, $new_name, $new_slug );
		if ( is_wp_error( $preview ) ) {
			$this->redirect_notice( $preview->get_error_message(), 'error' );
		}
		$token = wp_generate_password( 40, false, false );
		set_transient(
			'taxonomy_tidy_preview_' . $token,
			array(
				'user_id'     => get_current_user_id(),
				'action'      => $action,
				'taxonomy'    => $taxonomy,
				'source_id'   => $source_id,
				'target_id'   => $target_id,
				'new_name'    => $new_name,
				'new_slug'    => $new_slug,
				'fingerprint' => Audit::impact_fingerprint( $preview ),
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
		$preview = Audit::preview( $state['action'], $state['taxonomy'], $state['source_id'], $state['target_id'], $state['new_name'], $state['new_slug'] );
		if ( is_wp_error( $preview ) || ! hash_equals( $state['fingerprint'], Audit::impact_fingerprint( $preview ) ) ) {
			delete_transient( 'taxonomy_tidy_preview_' . $token );
			$this->redirect_notice( __( 'The terms or affected objects changed after the preview. Review the updated impact before applying.', 'taxonomy-tidy' ), 'error' );
		}
		if ( ! isset( $_POST['confirm'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) {
			$this->redirect_notice( __( 'Confirm that you reviewed the impact before applying this change.', 'taxonomy-tidy' ), 'error' );
		}
		delete_transient( 'taxonomy_tidy_preview_' . $token );
		if ( 'merge' === $state['action'] ) {
			$result = Audit::merge( $state['taxonomy'], $state['source_id'], $state['target_id'] );
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
