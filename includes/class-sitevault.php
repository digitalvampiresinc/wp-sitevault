<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault {

	private static ?SiteVault $instance = null;

	public static function instance(): SiteVault {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		$this->load_dependencies();

		if ( is_admin() ) {
			SiteVault_Admin::instance()->boot();
		}
	}

	private function load_dependencies(): void {
		require_once SITEVAULT_PATH . 'includes/class-backup-manifest.php';
		require_once SITEVAULT_PATH . 'includes/class-database-exporter.php';
		require_once SITEVAULT_PATH . 'includes/class-content-archiver.php';
		require_once SITEVAULT_PATH . 'includes/class-backup-manager.php';
		require_once SITEVAULT_PATH . 'admin/class-admin.php';
	}

	public static function activate(): void {
		$paths = array(
			WP_CONTENT_DIR . '/sitevault',
			WP_CONTENT_DIR . '/sitevault/backups',
			WP_CONTENT_DIR . '/sitevault/tmp',
			WP_CONTENT_DIR . '/sitevault/logs',
		);

		foreach ( $paths as $path ) {
			if ( ! is_dir( $path ) ) {
				wp_mkdir_p( $path );
			}
		}

		update_option( 'sitevault_version', SITEVAULT_VERSION );
	}

	public static function deactivate(): void {
		// Runtime data is intentionally preserved on deactivation.
	}
}
