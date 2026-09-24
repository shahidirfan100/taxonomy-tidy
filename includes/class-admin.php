<?php
/** Accessible administration pages and preview-first forms. */
namespace TaxonomyTidy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {
	/** @var Admin|null */
	private static $instance = null;

	/** @return Admin */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Register menu pages and scoped assets. */
	public function register() {
		add_menu_page( __( 'Taxonomy Tidy', 'taxonomy-tidy' ), __( 'Taxonomy Tidy', 'taxonomy-tidy' ), 'read', 'taxonomy-tidy', array( $this, 'render_dashboard' ), 'dashicons-category', 58 );
		add_submenu_page( 'taxonomy-tidy', __( 'Dashboard', 'taxonomy-tidy' ), __( 'Dashboard', 'taxonomy-tidy' ), 'read', 'taxonomy-tidy', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'taxonomy-tidy', __( 'Activity', 'taxonomy-tidy' ), __( 'Activity', 'taxonomy-tidy' ), 'read', 'taxonomy-tidy-activity', array( $this, 'render_activity' ) );
		add_submenu_page( 'taxonomy-tidy', __( 'Settings', 'taxonomy-tidy' ), __( 'Settings', 'taxonomy-tidy' ), 'manage_options', 'taxonomy-tidy-settings', array( $this, 'render_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/** Load plugin assets only on its own screens. */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'taxonomy-tidy' ) ) {
			return;
		}
		wp_enqueue_style( 'taxonomy-tidy-admin', TAXONOMY_TIDY_URL . 'assets/css/admin.css', array(), TAXONOMY_TIDY_VERSION );
		wp_enqueue_script( 'taxonomy-tidy-admin', TAXONOMY_TIDY_URL . 'assets/js/admin.js', array(), TAXONOMY_TIDY_VERSION, true );
	}

	/** Render the dashboard, scan results, term finder, or a pre-change review. */
	public function render_dashboard() {
		if ( ! current_user_can( 'read' ) ) {
			return;
		}
		$this->page_header( 'dashboard' );
		$this->notice();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only selects a read-only admin view.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( 'preview' === $view ) {
			$this->render_preview();
		} elseif ( 'terms' === $view ) {
			$this->render_term_search();
		} elseif ( 'rename' === $view ) {
			$this->render_rename_form();
		} else {
			$this->render_scan_dashboard();
		}
		$this->page_footer();
	}

	/** Render the scan start and findings dashboard. */
	private function render_scan_dashboard() {
		$settings   = get_option( 'taxonomy_tidy_settings', array() );
		$taxonomies = Taxonomy_Service::get_available( ! empty( $settings['include_private'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read-only, user-bound scan token only selects a saved report.
		$scan_state = isset( $_GET['scan'] ) ? get_transient( 'taxonomy_tidy_scan_' . sanitize_text_field( wp_unslash( $_GET['scan'] ) ) ) : false;
		$report     = is_array( $scan_state ) && get_current_user_id() === (int) $scan_state['user_id'] ? $scan_state['report'] : false;
		?>
		<section class="tt-intro">
			<div>
				<p class="tt-eyebrow"><?php esc_html_e( 'A careful review before every change', 'taxonomy-tidy' ); ?></p>
				<h1><?php esc_html_e( 'Clean up terms with a clear record', 'taxonomy-tidy' ); ?></h1>
				<p><?php esc_html_e( 'Find duplicate labels and unused terms across categories, tags, products, and other registered taxonomies. See the impact first, then apply or restore a change.', 'taxonomy-tidy' ); ?></p>
			</div>
			<a class="button tt-secondary-button" href="<?php echo esc_url( admin_url( 'admin.php?page=taxonomy-tidy-activity' ) ); ?>"><?php esc_html_e( 'View activity', 'taxonomy-tidy' ); ?></a>
		</section>

		<section class="tt-scan-panel" aria-labelledby="tt-scan-title">
			<div>
				<h2 id="tt-scan-title"><?php esc_html_e( 'Scan your terms', 'taxonomy-tidy' ); ?></h2>
				<p><?php esc_html_e( 'Scanning reads your site data and makes no changes. Similarity checks only match labels that differ by case, spacing, punctuation, or accents.', 'taxonomy-tidy' ); ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-scan-form">
				<input type="hidden" name="action" value="taxonomy_tidy_scan">
				<?php wp_nonce_field( 'taxonomy_tidy_scan' ); ?>
				<label class="screen-reader-text" for="tt-scan-taxonomy"><?php esc_html_e( 'Choose a taxonomy to scan', 'taxonomy-tidy' ); ?></label>
				<select id="tt-scan-taxonomy" name="taxonomy">
					<option value=""><?php esc_html_e( 'All available taxonomies', 'taxonomy-tidy' ); ?></option>
					<?php foreach ( $taxonomies as $name => $taxonomy ) : ?>
						<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $taxonomy->labels->name . ' (' . $name . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button button-primary tt-primary-button" type="submit"><?php esc_html_e( 'Run scan', 'taxonomy-tidy' ); ?></button>
			</form>
		</section>
		<?php
		if ( ! $report ) {
			$this->empty_state( __( 'No scan report yet', 'taxonomy-tidy' ), __( 'Choose a taxonomy and run a read-only scan. Taxonomy Tidy will list possible duplicates and empty terms for you to review.', 'taxonomy-tidy' ) );
			$this->render_term_finder_link( $taxonomies );
			return;
		}

		$totals = array(
			'terms'  => 0,
			'groups' => 0,
			'empty'  => 0,
		);
		foreach ( $report as $result ) {
			$totals['terms']  += (int) $result['total'];
			$totals['groups'] += (int) $result['group_total'];
			$totals['empty']  += (int) $result['empty_total'];
		}
		?>
		<section class="tt-metrics" aria-label="<?php esc_attr_e( 'Scan summary', 'taxonomy-tidy' ); ?>">
			<div class="tt-metric"><span><?php esc_html_e( 'Terms reviewed', 'taxonomy-tidy' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['terms'] ) ); ?></strong><small><?php echo esc_html( $this->count_label( count( $report ), 'taxonomy' ) ); ?></small></div>
			<div class="tt-metric tt-metric-attention"><span><?php esc_html_e( 'Duplicate groups', 'taxonomy-tidy' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['groups'] ) ); ?></strong><small><?php esc_html_e( 'Labels worth reviewing', 'taxonomy-tidy' ); ?></small></div>
			<div class="tt-metric"><span><?php esc_html_e( 'Empty terms', 'taxonomy-tidy' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['empty'] ) ); ?></strong><small><?php esc_html_e( 'Nothing is assigned', 'taxonomy-tidy' ); ?></small></div>
		</section>
		<?php
		foreach ( $report as $taxonomy_name => $result ) {
			$this->render_taxonomy_findings( $taxonomy_name, $result );
		}
		$this->render_term_finder_link( $taxonomies );
	}

	/** Render likely duplicate and empty-term findings for one taxonomy. */
	private function render_taxonomy_findings( $taxonomy_name, $result ) {
		$taxonomy_label = $result['taxonomy']->labels->name;
		?>
		<section class="tt-section">
			<div class="tt-section-heading">
				<div><p class="tt-eyebrow"><?php esc_html_e( 'Taxonomy', 'taxonomy-tidy' ); ?></p><h2><?php echo esc_html( $taxonomy_label ); ?></h2></div>
			<code><?php echo esc_html( $taxonomy_name ); ?></code>
		</div>
		<?php if ( empty( $result['groups'] ) ) : ?>
			<p class="tt-inline-empty"><?php esc_html_e( 'No matching labels found in this taxonomy.', 'taxonomy-tidy' ); ?></p>
		<?php else : ?>
			<div class="tt-table-wrap"><table class="widefat striped tt-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Finding', 'taxonomy-tidy' ); ?></th><th scope="col"><?php esc_html_e( 'Terms and assignments', 'taxonomy-tidy' ); ?></th><th scope="col"><?php esc_html_e( 'Review', 'taxonomy-tidy' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $result['groups'] as $group ) : ?>
					<tr>
						<td><span class="tt-badge <?php echo 'exact' === $group['kind'] ? 'tt-badge-info' : 'tt-badge-warning'; ?>"><?php echo esc_html( 'exact' === $group['kind'] ? __( 'Exact label', 'taxonomy-tidy' ) : __( 'Formatting variant', 'taxonomy-tidy' ) ); ?></span><p class="tt-finding-note"><?php esc_html_e( 'Same comparison key', 'taxonomy-tidy' ); ?></p></td>
						<td>
							<ul class="tt-term-list">
							<?php foreach ( $group['members'] as $term ) : ?>
								<li><strong><?php echo esc_html( $term->name ); ?></strong><span><?php echo esc_html( $this->count_label( (int) $term->count, 'assignment' ) ); ?></span>
								<?php
								if ( is_taxonomy_hierarchical( $taxonomy_name ) && $term->parent ) :
									?>
									<small><?php esc_html_e( 'Has a parent term', 'taxonomy-tidy' ); ?></small><?php endif; ?></li>
							<?php endforeach; ?>
							</ul>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-review-form">
								<input type="hidden" name="action" value="taxonomy_tidy_preview"><input type="hidden" name="operation" value="merge"><input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy_name ); ?>"><?php wp_nonce_field( 'taxonomy_tidy_preview' ); ?>
								<label><span><?php esc_html_e( 'Term to merge', 'taxonomy-tidy' ); ?></span><select name="source_id" required>
								<?php
								foreach ( $group['members'] as $index => $term ) :
									?>
									<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( 1 === $index ); ?>><?php echo esc_html( $term->name . ' · ' . number_format_i18n( (int) $term->count ) ); ?></option><?php endforeach; ?></select></label>
								<label><span><?php esc_html_e( 'Keep this term', 'taxonomy-tidy' ); ?></span><select name="target_id" required>
								<?php
								foreach ( $group['members'] as $index => $term ) :
									?>
									<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( 0 === $index ); ?>><?php echo esc_html( $term->name . ' · ' . number_format_i18n( (int) $term->count ) ); ?></option><?php endforeach; ?></select></label>
								<button type="submit" class="button tt-secondary-button"><?php esc_html_e( 'Review impact', 'taxonomy-tidy' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<?php if ( $result['group_total'] > count( $result['groups'] ) ) : ?>
				<?php
				/* translators: 1: number of displayed groups, 2: total number of duplicate groups. */
				$group_summary = sprintf( __( 'Showing the first %1$s of %2$s duplicate groups. Scan one taxonomy at a time to focus the report.', 'taxonomy-tidy' ), number_format_i18n( count( $result['groups'] ) ), number_format_i18n( $result['group_total'] ) );
				?>
				<p class="description"><?php echo esc_html( $group_summary ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<h3 class="tt-subheading"><?php esc_html_e( 'Empty terms', 'taxonomy-tidy' ); ?> <span class="tt-count"><?php echo esc_html( number_format_i18n( $result['empty_total'] ) ); ?></span></h3>
		<?php if ( empty( $result['empty'] ) ) : ?>
			<p class="tt-inline-empty"><?php esc_html_e( 'No empty terms found.', 'taxonomy-tidy' ); ?></p>
		<?php else : ?>
			<div class="tt-empty-list">
				<?php foreach ( $result['empty'] as $term ) : ?>
					<div class="tt-empty-row"><div><strong><?php echo esc_html( $term->name ); ?></strong><span><?php esc_html_e( 'Term ID', 'taxonomy-tidy' ); ?> <?php echo esc_html( $term->term_id ); ?></span></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="taxonomy_tidy_preview"><input type="hidden" name="operation" value="delete"><input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy_name ); ?>"><input type="hidden" name="source_id" value="<?php echo esc_attr( $term->term_id ); ?>"><?php wp_nonce_field( 'taxonomy_tidy_preview' ); ?><button type="submit" class="button tt-quiet-button"><?php esc_html_e( 'Review removal', 'taxonomy-tidy' ); ?></button></form></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		</section>
		<?php
	}

	/** Show a one-use preview only to the user who created it. */
	private function render_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read-only, user-bound token displays a preview; apply requires a separate nonce.
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$state = $token ? get_transient( 'taxonomy_tidy_preview_' . $token ) : false;
		if ( ! is_array( $state ) || get_current_user_id() !== (int) $state['user_id'] ) {
			$this->empty_state( __( 'Review expired', 'taxonomy-tidy' ), __( 'This review has expired or belongs to another user. Return to the dashboard and review the impact again.', 'taxonomy-tidy' ) );
			return;
		}
		$preview = Audit::preview( $state['action'], $state['taxonomy'], $state['source_id'], $state['target_id'], $state['new_name'], $state['new_slug'] );
		if ( is_wp_error( $preview ) || ! hash_equals( $state['fingerprint'], Audit::impact_fingerprint( $preview ) ) ) {
			$this->empty_state( __( 'The terms changed', 'taxonomy-tidy' ), __( 'The site changed after this review was prepared. Start a new review to see the current impact.', 'taxonomy-tidy' ) );
			return;
		}
		$source          = $preview['source'];
		$target          = $preview['target'];
		$operation_label = array(
			'merge'  => __( 'Merge term', 'taxonomy-tidy' ),
			'rename' => __( 'Rename term', 'taxonomy-tidy' ),
			'delete' => __( 'Remove empty term', 'taxonomy-tidy' ),
		);
		?>
		<section class="tt-section tt-review-page">
			<p class="tt-eyebrow"><?php echo esc_html( $operation_label[ $state['action'] ] ); ?> · <?php echo esc_html( $preview['taxonomy']->labels->name ); ?></p>
			<h2><?php esc_html_e( 'Review the impact before applying', 'taxonomy-tidy' ); ?></h2>
			<p class="tt-review-description"><?php echo esc_html( sprintf( __( 'This preview is read-only. It expires after 20 minutes and will be checked again when you apply the change.', 'taxonomy-tidy' ) ) ); ?></p>
			<div class="tt-change-summary">
				<div><span><?php esc_html_e( 'Current term', 'taxonomy-tidy' ); ?></span><strong><?php echo esc_html( $source->name ); ?></strong><code><?php echo esc_html( $source->slug ); ?></code></div>
				<?php
				if ( 'merge' === $state['action'] ) :
					?>
					<div class="tt-summary-arrow" aria-hidden="true">→</div><div><span><?php esc_html_e( 'Term to keep', 'taxonomy-tidy' ); ?></span><strong><?php echo esc_html( $target->name ); ?></strong><code><?php echo esc_html( $target->slug ); ?></code></div>
					<?php
elseif ( 'rename' === $state['action'] ) :
	?>
					<div class="tt-summary-arrow" aria-hidden="true">→</div><div><span><?php esc_html_e( 'New name and slug', 'taxonomy-tidy' ); ?></span><strong><?php echo esc_html( $preview['new_name'] ); ?></strong><code><?php echo esc_html( $preview['new_slug'] ); ?></code></div><?php endif; ?>
			</div>
			<div class="tt-impact-grid">
				<div class="tt-impact-item"><span><?php esc_html_e( 'Assigned objects', 'taxonomy-tidy' ); ?></span><strong><?php echo esc_html( number_format_i18n( $preview['object_count'] ) ); ?></strong></div>
				<div class="tt-impact-item"><span><?php esc_html_e( 'Child terms', 'taxonomy-tidy' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $preview['children'] ) ) ); ?></strong></div>
				<div class="tt-impact-item"><span><?php esc_html_e( 'Taxonomy', 'taxonomy-tidy' ); ?></span><strong><?php echo esc_html( $preview['taxonomy']->labels->name ); ?></strong></div>
			</div>
			<h3><?php esc_html_e( 'Affected objects', 'taxonomy-tidy' ); ?></h3>
			<?php
			if ( empty( $preview['objects'] ) ) :
				?>
				<p class="tt-inline-empty"><?php esc_html_e( 'No assigned objects were found.', 'taxonomy-tidy' ); ?></p><?php else : ?>
				<div class="tt-table-wrap"><table class="widefat striped tt-table"><thead><tr><th><?php esc_html_e( 'Object', 'taxonomy-tidy' ); ?></th><th><?php esc_html_e( 'Type', 'taxonomy-tidy' ); ?></th><th><?php esc_html_e( 'ID', 'taxonomy-tidy' ); ?></th></tr></thead><tbody>
												<?php
												foreach ( $preview['objects'] as $object ) :
													?>
					<tr><td><?php echo esc_html( $object['title'] ); ?></td><td><?php echo esc_html( $object['type'] ); ?></td><td><?php echo esc_html( $object['id'] ); ?></td></tr><?php endforeach; ?></tbody></table></div>
												<?php if ( $preview['object_count'] > count( $preview['objects'] ) ) : ?>
													<?php
													/* translators: 1: number of displayed objects, 2: total number of affected objects. */
													$object_summary = sprintf( __( 'Showing %1$s of %2$s assigned objects.', 'taxonomy-tidy' ), number_format_i18n( count( $preview['objects'] ) ), number_format_i18n( $preview['object_count'] ) );
													?>
					<p class="description"><?php echo esc_html( $object_summary ); ?></p>
				<?php endif; ?>
							<?php endif; ?>
			<?php if ( ! empty( $preview['hierarchy_changes'] ) ) : ?>
				<h3><?php esc_html_e( 'Hierarchy changes', 'taxonomy-tidy' ); ?></h3>
				<ul class="tt-child-list">
					<?php foreach ( $preview['hierarchy_changes'] as $change ) : ?>
						<?php
						/* translators: 1: child term name, 2: child term ID, 3: new parent term name. */
						$hierarchy_message = sprintf( __( '%1$s (ID %2$d) will move under %3$s.', 'taxonomy-tidy' ), $change['term']->name, $change['term']->term_id, $change['parent_name'] );
						?>
						<li><?php echo esc_html( $hierarchy_message ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( ! empty( $preview['children'] ) ) : ?>
				<h3><?php esc_html_e( 'Hierarchy impact', 'taxonomy-tidy' ); ?></h3>
				<p class="tt-inline-empty"><?php esc_html_e( 'The child terms keep their current parents.', 'taxonomy-tidy' ); ?></p>
			<?php endif; ?>
			<h3><?php esc_html_e( 'Archive URL', 'taxonomy-tidy' ); ?></h3>
			<div class="tt-url-list"><p><span><?php esc_html_e( 'Current', 'taxonomy-tidy' ); ?></span>
			<?php
			if ( $preview['old_url'] ) :
				?>
				<a href="<?php echo esc_url( $preview['old_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $preview['old_url'] ); ?></a>
				<?php
else :
	?>
				<em><?php esc_html_e( 'This taxonomy has no public term archive.', 'taxonomy-tidy' ); ?></em><?php endif; ?></p><p><span><?php esc_html_e( 'After change', 'taxonomy-tidy' ); ?></span>
				<?php
				if ( 'merge' === $state['action'] && $preview['new_url'] ) :
					?>
	<a href="<?php echo esc_url( $preview['new_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $preview['new_url'] ); ?></a>
					<?php
elseif ( 'rename' === $state['action'] && $preview['new_url'] ) :
	?>
	<a href="<?php echo esc_url( $preview['new_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $preview['new_url'] ); ?></a>
	<?php
else :
	?>
	<em><?php esc_html_e( 'The source archive will no longer exist.', 'taxonomy-tidy' ); ?></em><?php endif; ?></p></div>
			<?php
			if ( $preview['old_url'] && ( 'delete' === $state['action'] || $preview['old_url'] !== $preview['new_url'] ) ) :
				?>
				<div class="notice notice-warning inline tt-url-warning"><p><?php esc_html_e( 'The archive URL changes. Taxonomy Tidy records the old and new URLs in the audit report; it does not create redirects.', 'taxonomy-tidy' ); ?></p></div><?php endif; ?>
			<div class="tt-review-footer">
				<a class="button tt-secondary-button" href="<?php echo esc_url( admin_url( 'admin.php?page=taxonomy-tidy' ) ); ?>"><?php esc_html_e( 'Back to dashboard', 'taxonomy-tidy' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-apply-form">
					<input type="hidden" name="action" value="taxonomy_tidy_apply"><input type="hidden" name="preview_token" value="<?php echo esc_attr( $token ); ?>"><?php wp_nonce_field( 'taxonomy_tidy_apply_' . $token ); ?>
					<label><input type="checkbox" name="confirm" value="1" required> <?php echo esc_html( $this->confirmation_text( $state['action'], $source, $target ) ); ?></label>
					<button type="submit" class="button button-primary tt-primary-button"><?php echo esc_html( $this->apply_button_text( $state['action'] ) ); ?></button>
				</form>
			</div>
		</section>
		<?php
	}

	/** Add the term search entry point. */
	private function render_term_finder_link( $taxonomies ) {
		if ( empty( $taxonomies ) ) {
			return;
		}
		?>
		<section class="tt-section tt-find-section"><div><h2><?php esc_html_e( 'Rename a term', 'taxonomy-tidy' ); ?></h2><p><?php esc_html_e( 'Search the selected taxonomy, then review the URL and assignment impact before saving a new label.', 'taxonomy-tidy' ); ?></p></div>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="tt-find-form"><input type="hidden" name="page" value="taxonomy-tidy"><input type="hidden" name="view" value="terms"><label><span><?php esc_html_e( 'Taxonomy', 'taxonomy-tidy' ); ?></span><select name="taxonomy" required>
			<?php
			foreach ( $taxonomies as $name => $taxonomy ) :
				?>
				<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $taxonomy->labels->name ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Term name', 'taxonomy-tidy' ); ?></span><input type="search" name="s" required></label><button type="submit" class="button tt-secondary-button"><?php esc_html_e( 'Find terms', 'taxonomy-tidy' ); ?></button></form>
		</section>
		<?php
	}

	/** Search a single manageable taxonomy and show rename review links. */
	private function render_term_search() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only filters read-only term search results.
		$taxonomy_name = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		$taxonomy      = Taxonomy_Service::get_authorized( $taxonomy_name );
		if ( is_wp_error( $taxonomy ) ) {
			$this->empty_state( __( 'Taxonomy unavailable', 'taxonomy-tidy' ), __( 'Choose a taxonomy that your account can manage.', 'taxonomy-tidy' ) );
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only filters read-only term search results.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$terms  = get_terms(
			array(
				'taxonomy'   => $taxonomy_name,
				'hide_empty' => false,
				'number'     => 50,
				'search'     => $search,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		?>
		<section class="tt-section"><p class="tt-eyebrow"><?php echo esc_html( $taxonomy->labels->name ); ?></p><h2><?php esc_html_e( 'Choose the term to rename', 'taxonomy-tidy' ); ?></h2>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="tt-search-form"><input type="hidden" name="page" value="taxonomy-tidy"><input type="hidden" name="view" value="terms"><input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy_name ); ?>"><label for="tt-term-search"><?php esc_html_e( 'Search term names', 'taxonomy-tidy' ); ?></label><input id="tt-term-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>"><button type="submit" class="button tt-secondary-button"><?php esc_html_e( 'Search', 'taxonomy-tidy' ); ?></button></form>
			<?php
			if ( is_wp_error( $terms ) || empty( $terms ) ) :
				?>
				<p class="tt-inline-empty"><?php esc_html_e( 'No matching terms were found.', 'taxonomy-tidy' ); ?></p>
				<?php
else :
	?>
				<div class="tt-table-wrap"><table class="widefat striped tt-table"><thead><tr><th><?php esc_html_e( 'Term', 'taxonomy-tidy' ); ?></th><th><?php esc_html_e( 'Assignments', 'taxonomy-tidy' ); ?></th><th><?php esc_html_e( 'Slug', 'taxonomy-tidy' ); ?></th><th><?php esc_html_e( 'Action', 'taxonomy-tidy' ); ?></th></tr></thead><tbody>
				<?php
				foreach ( $terms as $term ) :
					?>
	<tr><td><strong><?php echo esc_html( $term->name ); ?></strong></td><td><?php echo esc_html( number_format_i18n( (int) $term->count ) ); ?></td><td><code><?php echo esc_html( $term->slug ); ?></code></td><td><a class="button tt-quiet-button" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page'     => 'taxonomy-tidy',
								'view'     => 'rename',
								'taxonomy' => $taxonomy_name,
								'term_id'  => $term->term_id,
							),
							admin_url( 'admin.php' )
						)
					);
					?>
	"><?php esc_html_e( 'Review rename', 'taxonomy-tidy' ); ?></a></td></tr><?php endforeach; ?></tbody></table></div>
	<?php
	if ( count( $terms ) === 50 ) :
		?>
	<p class="description"><?php esc_html_e( 'Showing up to 50 results. Add more of the term name to narrow the search.', 'taxonomy-tidy' ); ?></p><?php endif; ?><?php endif; ?>
		</section>
		<?php
	}

	/** Render the first step in a safe rename preview. */
	private function render_rename_form() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- These read-only values only select the rename form state.
		$taxonomy_name = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only selects which term appears in a read-only form.
		$term_id  = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
		$taxonomy = Taxonomy_Service::get_authorized( $taxonomy_name, true );
		$term     = is_wp_error( $taxonomy ) ? false : get_term( $term_id, $taxonomy_name );
		if ( is_wp_error( $taxonomy ) || ! $term || is_wp_error( $term ) ) {
			$this->empty_state( __( 'Term unavailable', 'taxonomy-tidy' ), __( 'The term may have been removed or your account cannot edit it. Return to the search and try again.', 'taxonomy-tidy' ) );
			return;
		}
		?>
		<section class="tt-section tt-rename-panel"><p class="tt-eyebrow"><?php echo esc_html( $taxonomy->labels->name ); ?></p><h2><?php esc_html_e( 'Rename a term', 'taxonomy-tidy' ); ?></h2><p><?php esc_html_e( 'The name and slug update together. Review affected objects and the new archive URL before applying.', 'taxonomy-tidy' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-rename-form"><input type="hidden" name="action" value="taxonomy_tidy_preview"><input type="hidden" name="operation" value="rename"><input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy_name ); ?>"><input type="hidden" name="source_id" value="<?php echo esc_attr( $term_id ); ?>"><?php wp_nonce_field( 'taxonomy_tidy_preview' ); ?><label><span><?php esc_html_e( 'New term name', 'taxonomy-tidy' ); ?></span><input type="text" name="new_name" value="<?php echo esc_attr( $term->name ); ?>" required></label><label><span><?php esc_html_e( 'New URL slug', 'taxonomy-tidy' ); ?></span><input type="text" name="new_slug" value="<?php echo esc_attr( $term->slug ); ?>"><small><?php esc_html_e( 'Leave the existing slug to change the label only. Enter a new slug to change the archive URL.', 'taxonomy-tidy' ); ?></small></label><button type="submit" class="button button-primary tt-primary-button"><?php esc_html_e( 'Preview rename', 'taxonomy-tidy' ); ?></button></form>
		</section>
		<?php
	}

	/** Render the activity log and available rollback actions. */
	public function render_activity() {
		if ( ! current_user_can( 'read' ) ) {
			return;
		}
		$this->page_header( 'activity' );
		$this->notice();
		$can_export = current_user_can( 'manage_options' );
		$batches    = Audit::recent_batches( 50 );
		?>
		<section class="tt-intro"><div><p class="tt-eyebrow"><?php esc_html_e( 'Local change history', 'taxonomy-tidy' ); ?></p><h1><?php esc_html_e( 'Activity and restore points', 'taxonomy-tidy' ); ?></h1><p><?php esc_html_e( 'Each saved operation keeps the original term ID, assignments, hierarchy, and metadata. Rollback checks for later changes before restoring anything.', 'taxonomy-tidy' ); ?></p></div>
		<?php
		if ( $can_export ) :
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-export-form"><input type="hidden" name="action" value="taxonomy_tidy_export"><?php wp_nonce_field( 'taxonomy_tidy_export' ); ?><label class="screen-reader-text" for="tt-export-format"><?php esc_html_e( 'Export format', 'taxonomy-tidy' ); ?></label><select id="tt-export-format" name="format"><option value="csv">CSV</option><option value="json">JSON</option></select><button type="submit" class="button tt-secondary-button"><?php esc_html_e( 'Export report', 'taxonomy-tidy' ); ?></button></form><?php endif; ?></section>
		<?php if ( empty( $batches ) ) : ?>
			<?php $this->empty_state( __( 'No changes have been made', 'taxonomy-tidy' ), __( 'After you apply a reviewed merge, rename, or removal, its batch and rollback action will appear here.', 'taxonomy-tidy' ) ); ?>
		<?php else : ?>
			<div class="tt-table-wrap"><table class="widefat striped tt-table tt-activity-table"><thead><tr><th><?php esc_html_e( 'Batch', 'taxonomy-tidy' ); ?></th><th><?php esc_html_e( 'Change', 'taxonomy-tidy' ); ?></th><th><?php esc_html_e( 'Objects', 'taxonomy-tidy' ); ?></th><th><?php esc_html_e( 'Status', 'taxonomy-tidy' ); ?></th><th><?php esc_html_e( 'Restore', 'taxonomy-tidy' ); ?></th></tr></thead><tbody>
			<?php foreach ( $batches as $batch ) : ?>
				<?php
				$summary        = json_decode( $batch->summary, true );
				$has_taxonomy   = is_array( $summary ) && ! empty( $summary['taxonomy'] );
				$authorization  = $has_taxonomy ? Taxonomy_Service::get_authorized( $summary['taxonomy'], true ) : null;
				$action_labels  = array(
					'merge'  => __( 'Merge term', 'taxonomy-tidy' ),
					'rename' => __( 'Rename term', 'taxonomy-tidy' ),
					'delete' => __( 'Remove empty term', 'taxonomy-tidy' ),
				);
				$action_label   = is_array( $summary ) && isset( $summary['action'], $action_labels[ $summary['action'] ] ) ? $action_labels[ $summary['action'] ] : '';
				$source_name    = is_array( $summary ) && isset( $summary['source_name'] ) ? $summary['source_name'] : '';
				$taxonomy_label = $has_taxonomy ? $summary['taxonomy'] : '';
				$target_label   = '';
				if ( is_array( $summary ) && ! empty( $summary['target_name'] ) ) {
					/* translators: %s is the destination term name in a merge. */
					$target_label = sprintf( __( 'to %s', 'taxonomy-tidy' ), $summary['target_name'] );
				}
				$affected_count = is_array( $summary ) && isset( $summary['affected_count'] ) ? absint( $summary['affected_count'] ) : 0;
				$can_view_batch = ! $has_taxonomy || ! is_wp_error( $authorization );
				?>
				<?php if ( $can_view_batch ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $batch->batch_uuid ); ?></strong><small><?php echo esc_html( get_date_from_gmt( $batch->created_at, 'Y-m-d H:i' ) ); ?></small></td>
						<td>
						<?php
						if ( is_array( $summary ) ) :
							?>
							<span class="tt-action-label"><?php echo esc_html( $action_label ); ?></span><strong><?php echo esc_html( $source_name ); ?></strong>
							<?php
							if ( $target_label ) :
								?>
							<span><?php echo esc_html( $target_label ); ?></span><?php endif; ?><small><?php echo esc_html( $taxonomy_label ); ?></small><?php endif; ?></td>
						<td><?php echo esc_html( $this->count_label( (int) $batch->action_count, 'operation' ) ); ?><small><?php echo esc_html( $this->count_label( $affected_count, 'assignment' ) ); ?></small></td>
						<td><span class="tt-status tt-status-<?php echo esc_attr( sanitize_html_class( $batch->status ) ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $batch->status ) ) ); ?></span></td>
						<td>
							<?php
							$recoverable = in_array( $batch->status, array( 'applying', 'rollback_failed', 'rolling_back' ), true );
							if ( ( 'applied' === $batch->status && is_array( $summary ) ) || $recoverable ) :
								?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php echo esc_attr__( 'Resume recovery for this batch? If an apply or rollback request is still running, wait for it to finish first. Taxonomy Tidy will stop if it detects conflicting changes.', 'taxonomy-tidy' ); ?>">
									<input type="hidden" name="action" value="taxonomy_tidy_rollback">
									<input type="hidden" name="batch_id" value="<?php echo esc_attr( $batch->id ); ?>">
									<?php wp_nonce_field( 'taxonomy_tidy_rollback_' . $batch->id ); ?>
									<button type="submit" class="button tt-rollback-button">
										<?php
										if ( $recoverable ) {
											esc_html_e( 'Recover interrupted change', 'taxonomy-tidy' );
										} else {
											esc_html_e( 'Roll back', 'taxonomy-tidy' );
										}
										?>
									</button>
								</form>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'No action available', 'taxonomy-tidy' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
			<?php endforeach; ?>
			</tbody></table></div>
		<?php endif; ?>
		<?php
		$this->page_footer();
	}

	/** Render administrator-only settings. */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->page_header( 'settings' );
		$this->notice();
		$settings = get_option( 'taxonomy_tidy_settings', array() );
		?>
		<section class="tt-section tt-settings-panel"><p class="tt-eyebrow"><?php esc_html_e( 'Site-local preferences', 'taxonomy-tidy' ); ?></p><h2><?php esc_html_e( 'Settings', 'taxonomy-tidy' ); ?></h2><p><?php esc_html_e( 'Taxonomy Tidy works with the terms registered on this site. It does not send site data to an external service.', 'taxonomy-tidy' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="taxonomy_tidy_save_settings"><?php wp_nonce_field( 'taxonomy_tidy_save_settings' ); ?>
				<label class="tt-setting-row"><span><strong><?php esc_html_e( 'Include non-public taxonomies', 'taxonomy-tidy' ); ?></strong><small><?php esc_html_e( 'Show admin-visible taxonomies marked private by their registering plugin. Actions remain limited to taxonomies your role can manage.', 'taxonomy-tidy' ); ?></small></span><input type="checkbox" name="include_private" value="1" <?php checked( ! empty( $settings['include_private'] ) ); ?>></label>
				<label class="tt-setting-row"><span><strong><?php esc_html_e( 'Delete audit data when this plugin is uninstalled', 'taxonomy-tidy' ); ?></strong><small><?php esc_html_e( 'Off by default so rollback records remain available if the plugin is removed by mistake. When enabled, uninstall removes this site’s settings and audit tables.', 'taxonomy-tidy' ); ?></small></span><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?>></label>
				<p class="submit"><button type="submit" class="button button-primary tt-primary-button"><?php esc_html_e( 'Save settings', 'taxonomy-tidy' ); ?></button></p>
			</form>
		</section>
		<?php
		$this->page_footer();
	}

	/** Show a settings or operation notice. */
	private function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a sanitized, escaped status message after a nonce-protected POST redirect.
		if ( empty( $_GET['notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This only styles the sanitized status message from a nonce-protected redirect.
		$type = isset( $_GET['notice_type'] ) && 'error' === sanitize_key( wp_unslash( $_GET['notice_type'] ) ) ? 'error' : 'success';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The decoded message is sanitized below and escaped when rendered.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a sanitized, escaped status message from a nonce-protected redirect.
		$message = sanitize_text_field( wp_unslash( $_GET['notice'] ) );
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
		<?php
	}

	/** Shared page title and local navigation. */
	private function page_header( $active ) {
		$tabs = array(
			'dashboard' => array(
				'label' => __( 'Dashboard', 'taxonomy-tidy' ),
				'url'   => admin_url( 'admin.php?page=taxonomy-tidy' ),
			),
			'activity'  => array(
				'label' => __( 'Activity', 'taxonomy-tidy' ),
				'url'   => admin_url( 'admin.php?page=taxonomy-tidy-activity' ),
			),
			'settings'  => array(
				'label' => __( 'Settings', 'taxonomy-tidy' ),
				'url'   => admin_url( 'admin.php?page=taxonomy-tidy-settings' ),
			),
		);
		?>
		<div class="wrap tt-wrap"><div class="tt-brand"><span class="tt-brand-mark" aria-hidden="true"><svg viewBox="0 0 36 36" focusable="false"><path d="M18 3.8 30.2 11v14L18 32.2 5.8 25V11L18 3.8Z"/><path d="m12.2 18.2 3.8 3.8 8-9"/></svg></span><span><strong><?php esc_html_e( 'Taxonomy Tidy', 'taxonomy-tidy' ); ?></strong><small><?php esc_html_e( 'Safe term cleanup', 'taxonomy-tidy' ); ?></small></span></div>
			<nav class="tt-tabs" aria-label="<?php esc_attr_e( 'Taxonomy Tidy pages', 'taxonomy-tidy' ); ?>">
			<?php
			foreach ( $tabs as $key => $tab ) :
				?>
				<a href="<?php echo esc_url( $tab['url'] ); ?>" class="<?php echo $key === $active ? 'is-active' : ''; ?>" <?php echo $key === $active ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $tab['label'] ); ?></a><?php endforeach; ?></nav>
		<?php
	}

	/** Close the shared admin wrapper. */
	private function page_footer() {
		?>
		</div>
		<?php
	}

	/** Render a useful no-results state. */
	private function empty_state( $title, $description ) {
		?>
		<section class="tt-empty-state"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><h2><?php echo esc_html( $title ); ?></h2><p><?php echo esc_html( $description ); ?></p></section>
		<?php
	}

	/** Return explicit confirmation copy for each destructive action. */
	private function confirmation_text( $action, $source, $target ) {
		if ( 'merge' === $action ) {
			/* translators: 1: source term name, 2: destination term name. */
			return sprintf( __( 'I reviewed the impact and want to merge “%1$s” into “%2$s”.', 'taxonomy-tidy' ), $source->name, $target->name );
		}
		if ( 'rename' === $action ) {
			/* translators: %s is the term name that will be renamed. */
			return sprintf( __( 'I reviewed the impact and want to rename “%s”.', 'taxonomy-tidy' ), $source->name );
		}
		/* translators: %s is the empty term name that will be removed. */
		return sprintf( __( 'I reviewed the impact and want to remove the empty term “%s”.', 'taxonomy-tidy' ), $source->name );
	}

	/** Format a localized number followed by its singular or plural label. */
	private function count_label( $count, $label ) {
		$labels = array(
			'taxonomy'   => _n( 'taxonomy', 'taxonomies', $count, 'taxonomy-tidy' ),
			'assignment' => _n( 'assignment', 'assignments', $count, 'taxonomy-tidy' ),
			'operation'  => _n( 'operation', 'operations', $count, 'taxonomy-tidy' ),
		);
		return number_format_i18n( $count ) . ' ' . ( isset( $labels[ $label ] ) ? $labels[ $label ] : '' );
	}

	/** Action button copy. */
	private function apply_button_text( $action ) {
		if ( 'merge' === $action ) {
			return __( 'Apply merge', 'taxonomy-tidy' );
		}
		if ( 'rename' === $action ) {
			return __( 'Apply rename', 'taxonomy-tidy' );
		}
		return __( 'Remove empty term', 'taxonomy-tidy' );
	}
}
