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
		self::ensure_runtime_directories();
		self::protect_runtime_storage();
		$this->load_dependencies();

		if ( is_admin() ) {
			SiteVault_Admin::instance()->boot();
		}
	}

	private function load_dependencies(): void {
		require_once SITEVAULT_PATH . 'includes/class-backup-manifest.php';
		require_once SITEVAULT_PATH . 'includes/class-database-exporter.php';
		require_once SITEVAULT_PATH . 'includes/class-content-archiver.php';
		require_once SITEVAULT_PATH . 'includes/class-checksum-manager.php';
		require_once SITEVAULT_PATH . 'includes/class-package-builder.php';
		require_once SITEVAULT_PATH . 'includes/class-backup-history.php';
		require_once SITEVAULT_PATH . 'includes/class-import-validator.php';
		require_once SITEVAULT_PATH . 'includes/class-import-manager.php';
		require_once SITEVAULT_PATH . 'includes/class-restore-workspace.php';
		require_once SITEVAULT_PATH . 'includes/class-restore-planner.php';
		require_once SITEVAULT_PATH . 'includes/class-backup-manager.php';
		require_once SITEVAULT_PATH . 'admin/class-admin.php';
	}

	public static function activate(): void {
		self::ensure_runtime_directories();
		self::protect_runtime_storage();
		update_option( 'sitevault_version', SITEVAULT_VERSION );
	}

	private static function ensure_runtime_directories(): void {
		$paths = array(
			WP_CONTENT_DIR . '/sitevault',
			WP_CONTENT_DIR . '/sitevault/backups',
			WP_CONTENT_DIR . '/sitevault/tmp',
			WP_CONTENT_DIR . '/sitevault/logs',
			WP_CONTENT_DIR . '/sitevault/imports',
			WP_CONTENT_DIR . '/sitevault/restore-plans',
		);

		foreach ( $paths as $path ) {
			if ( ! is_dir( $path ) ) {
				wp_mkdir_p( $path );
			}
		}
	}

	private static function protect_runtime_storage(): void {
		$root = WP_CONTENT_DIR . '/sitevault';

		$files = array(
			'.htaccess' => "Order allow,deny\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></system.webServer></configuration>\n",
			'index.php' => "<?php\nhttp_response_code( 403 );\nexit;\n",
		);

		foreach ( $files as $name => $contents ) {
			$file = $root . '/' . $name;

			if ( ! file_exists( $file ) ) {
				file_put_contents( $file, $contents, LOCK_EX );
			}
		}
	}

	public static function deactivate(): void {
		// Runtime data is intentionally preserved on deactivation.
	}
}
