<?php
/** Plugin lifecycle and component wiring. */
namespace TaxonomyTidy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	/** @var Plugin|null */
	private static $instance = null;

	/** @return Plugin */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Register site-local behavior. */
	public function run() {
		add_action( 'init', array( 'TaxonomyTidy\\Schema', 'maybe_upgrade' ), 1 );
		add_action( 'template_redirect', array( 'TaxonomyTidy\\Routes', 'handle_404' ), 0 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( Admin::instance(), 'register' ) );
			Actions::instance()->register();
		}
	}
}
