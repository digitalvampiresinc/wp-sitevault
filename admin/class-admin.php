<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Admin {

	private static ?SiteVault_Admin $instance = null;

	public static function instance(): SiteVault_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			'SiteVault',
			'SiteVault',
			'manage_options',
			'sitevault',
			array( $this, 'render_dashboard' ),
			'dashicons-database-export',
			75
		);
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require SITEVAULT_PATH . 'admin/views/dashboard.php';
	}
}
