<?php
/**
 * Plugin Name: Taxonomy Tidy
 * Description: Preview taxonomy changes, keep a local audit trail, and restore merged terms when needed.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxonomy-tidy
 *
 * @package TaxonomyTidy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TAXONOMY_TIDY_VERSION', '1.0.0' );
define( 'TAXONOMY_TIDY_FILE', __FILE__ );
define( 'TAXONOMY_TIDY_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAXONOMY_TIDY_URL', plugin_dir_url( __FILE__ ) );

require_once TAXONOMY_TIDY_DIR . 'includes/class-schema.php';
require_once TAXONOMY_TIDY_DIR . 'includes/class-taxonomy-service.php';
require_once TAXONOMY_TIDY_DIR . 'includes/class-scanner.php';
require_once TAXONOMY_TIDY_DIR . 'includes/class-audit.php';
require_once TAXONOMY_TIDY_DIR . 'includes/class-actions.php';
require_once TAXONOMY_TIDY_DIR . 'includes/class-admin.php';
require_once TAXONOMY_TIDY_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'TaxonomyTidy\\Schema', 'install' ) );

add_action(
	'plugins_loaded',
	static function () {
		\TaxonomyTidy\Plugin::instance()->run();
	}
);
