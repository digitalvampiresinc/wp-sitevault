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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_sitevault_start_backup', array( $this, 'handle_start_backup' ) );
		add_action( 'admin_post_sitevault_continue_database_export', array( $this, 'handle_continue_database_export' ) );
		add_action( 'wp_ajax_sitevault_process_database_batch', array( $this, 'handle_ajax_database_batch' ) );
		add_action( 'wp_ajax_sitevault_process_content_batch', array( $this, 'handle_ajax_content_batch' ) );
		add_action( 'wp_ajax_sitevault_build_package', array( $this, 'handle_ajax_build_package' ) );
		add_action( 'admin_post_sitevault_download_backup', array( $this, 'handle_download_backup' ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_sitevault' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'sitevault-admin',
			SITEVAULT_URL . 'admin/assets/css/admin.css',
			array(),
			SITEVAULT_VERSION
		);
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

	public function handle_start_backup(): void {
		$this->authorise_request( 'sitevault_start_backup' );

		$manager = new SiteVault_Backup_Manager();
		$result  = $manager->create_backup();

		if ( ! $result['success'] ) {
			$this->redirect_with_message( 'error', $result['message'] ?? 'Backup could not be started.' );
		}

		update_option( 'sitevault_active_backup_id', $result['backup_id'], false );

		$this->redirect_with_message( 'started', 'Backup initialised. Database export is ready to process.' );
	}

	public function handle_continue_database_export(): void {
		$this->authorise_request( 'sitevault_continue_database_export' );

		$backup_id = sanitize_key( (string) get_option( 'sitevault_active_backup_id', '' ) );

		if ( '' === $backup_id ) {
			$this->redirect_with_message( 'error', 'No active backup was found.' );
		}

		$backup_dir = WP_CONTENT_DIR . '/sitevault/backups/' . $backup_id;
		$exporter   = new SiteVault_Database_Exporter();
		$result     = $exporter->process_batch( $backup_dir );

		if ( ! $result['success'] ) {
			$this->redirect_with_message( 'error', $result['message'] ?? 'Database export failed.' );
		}

		$status = $result['state']['status'] ?? 'running';

		$this->redirect_with_message(
			'complete' === $status ? 'complete' : 'progress',
			'complete' === $status ? 'Database export completed.' : 'Database export batch completed.'
		);
	}

	public function handle_ajax_database_batch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to perform this SiteVault operation.' ), 403 );
		}

		check_ajax_referer( 'sitevault_process_database_batch', 'nonce' );

		$backup_id = sanitize_key( (string) get_option( 'sitevault_active_backup_id', '' ) );

		if ( '' === $backup_id ) {
			wp_send_json_error( array( 'message' => 'No active backup was found.' ), 404 );
		}

		$backup_dir = WP_CONTENT_DIR . '/sitevault/backups/' . $backup_id;
		$exporter   = new SiteVault_Database_Exporter();
		$result     = $exporter->process_batch( $backup_dir );

		if ( ! $result['success'] ) {
			wp_send_json_error(
				array(
					'message' => $result['message'] ?? 'Database export failed.',
					'state'   => $result['state'] ?? null,
				),
				500
			);
		}

		$state       = $result['state'];
		$table_total = count( $state['tables'] ?? array() );
		$table_done  = min( (int) ( $state['table_index'] ?? 0 ), $table_total );

		wp_send_json_success(
			array(
				'status'        => $state['status'] ?? 'running',
				'table_done'    => $table_done,
				'table_total'   => $table_total,
				'rows_exported' => (int) ( $state['rows_exported'] ?? 0 ),
				'error'         => $state['error'] ?? null,
			)
		);
	}

	public function handle_ajax_content_batch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to perform this SiteVault operation.' ), 403 );
		}

		check_ajax_referer( 'sitevault_process_content_batch', 'nonce' );

		$backup_id = sanitize_key( (string) get_option( 'sitevault_active_backup_id', '' ) );

		if ( '' === $backup_id ) {
			wp_send_json_error( array( 'message' => 'No active backup was found.' ), 404 );
		}

		$backup_dir = WP_CONTENT_DIR . '/sitevault/backups/' . $backup_id;
		$archiver   = new SiteVault_Content_Archiver();
		$result     = $archiver->process_batch( $backup_dir );

		if ( ! $result['success'] ) {
			wp_send_json_error(
				array(
					'message' => $result['message'] ?? 'wp-content backup failed.',
					'state'   => $result['state'] ?? null,
				),
				500
			);
		}

		$state = $result['state'];

		wp_send_json_success(
			array(
				'status'              => $state['status'] ?? 'running',
				'phase'               => $state['phase'] ?? 'scanning',
				'directories_scanned' => (int) ( $state['directories_scanned'] ?? 0 ),
				'files_discovered'    => (int) ( $state['files_discovered'] ?? 0 ),
				'bytes_discovered'    => (int) ( $state['bytes_discovered'] ?? 0 ),
				'files_archived'      => (int) ( $state['files_archived'] ?? 0 ),
				'bytes_archived'      => (int) ( $state['bytes_archived'] ?? 0 ),
				'files_skipped'       => (int) ( $state['files_skipped'] ?? 0 ),
				'archive_verified'    => (bool) ( $state['archive_verified'] ?? false ),
				'archive_entries'     => (int) ( $state['archive_entries'] ?? 0 ),
				'self_backup_excluded'=> (bool) ( $state['self_backup_excluded'] ?? false ),
				'error'               => $state['error'] ?? null,
			)
		);
	}

	public function handle_ajax_build_package(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to build SiteVault packages.' ), 403 );
		}

		check_ajax_referer( 'sitevault_build_package', 'nonce' );

		$backup_id = sanitize_key( (string) get_option( 'sitevault_active_backup_id', '' ) );

		if ( '' === $backup_id ) {
			wp_send_json_error( array( 'message' => 'No active backup was found.' ), 404 );
		}

		$backup_dir = WP_CONTENT_DIR . '/sitevault/backups/' . $backup_id;
		$exporter   = new SiteVault_Database_Exporter();
		$archiver   = new SiteVault_Content_Archiver();
		$db_state   = $exporter->get_state( $backup_dir );
		$content    = $archiver->get_state( $backup_dir );

		if ( 'complete' !== ( $db_state['status'] ?? '' ) || 'complete' !== ( $content['status'] ?? '' ) ) {
			wp_send_json_error( array( 'message' => 'Database and wp-content stages must complete before packaging.' ), 409 );
		}

		$builder = new SiteVault_Package_Builder();
		$result  = $builder->build( $backup_dir );

		if ( ! $result['success'] ) {
			wp_send_json_error(
				array(
					'message' => $result['message'] ?? 'SiteVault package creation failed.',
					'state'   => $result['state'] ?? null,
				),
				500
			);
		}

		$state = $result['state'];

		wp_send_json_success(
			array(
				'status'         => $state['status'] ?? 'complete',
				'package_name'   => $state['package_name'] ?? '',
				'package_size'   => (int) ( $state['package_size'] ?? 0 ),
				'package_sha256' => $state['package_sha256'] ?? '',
				'verified'       => (bool) ( $state['verified'] ?? false ),
				'entries'        => (int) ( $state['entries'] ?? 0 ),
			)
		);
	}

	public function handle_download_backup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to download SiteVault backups.', 'sitevault' ) );
		}

		$backup_id = isset( $_GET['backup_id'] ) ? sanitize_key( wp_unslash( $_GET['backup_id'] ) ) : '';

		if ( '' === $backup_id ) {
			wp_die( esc_html__( 'Backup ID is missing.', 'sitevault' ) );
		}

		check_admin_referer( 'sitevault_download_backup_' . $backup_id );

		$history = new SiteVault_Backup_History();
		$file    = $history->get_package_file( $backup_id );

		if ( null === $file ) {
			wp_die( esc_html__( 'The requested SiteVault package is unavailable.', 'sitevault' ) );
		}

		$size = filesize( $file );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		@set_time_limit( 0 );
		ignore_user_abort( true );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( basename( $file ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		if ( false !== $size ) {
			header( 'Content-Length: ' . (string) $size );
		}

		$handle = fopen( $file, 'rb' );

		if ( false === $handle ) {
			wp_die( esc_html__( 'Unable to open the SiteVault package for download.', 'sitevault' ) );
		}

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 1048576 );

			if ( false === $chunk ) {
				break;
			}

			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			flush();
		}

		fclose( $handle );
		exit;
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_backup_id = sanitize_key( (string) get_option( 'sitevault_active_backup_id', '' ) );
		$database_state   = null;
		$content_state    = null;
		$package_state    = null;

		if ( '' !== $active_backup_id ) {
			$backup_dir     = WP_CONTENT_DIR . '/sitevault/backups/' . $active_backup_id;
			$exporter       = new SiteVault_Database_Exporter();
			$archiver       = new SiteVault_Content_Archiver();
			$builder        = new SiteVault_Package_Builder();
			$database_state = $exporter->get_state( $backup_dir );
			$content_state  = $archiver->get_state( $backup_dir );
			$package_state  = $builder->get_state( $backup_dir );
		}

		$history_reader = new SiteVault_Backup_History();
		$backup_history = $history_reader->get_backups( 20 );

		require SITEVAULT_PATH . 'admin/views/dashboard.php';
	}

	private function authorise_request( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this SiteVault operation.', 'sitevault' ) );
		}

		check_admin_referer( $action );
	}

	private function redirect_with_message( string $status, string $message ): void {
		$url = add_query_arg(
			array(
				'page'             => 'sitevault',
				'sitevault_status' => sanitize_key( $status ),
				'sitevault_msg'    => $message,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
