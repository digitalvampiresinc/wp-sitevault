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
		add_action( 'admin_post_sitevault_start_backup', array( $this, 'handle_start_backup' ) );
		add_action( 'admin_post_sitevault_continue_database_export', array( $this, 'handle_continue_database_export' ) );
		add_action( 'wp_ajax_sitevault_process_database_batch', array( $this, 'handle_ajax_database_batch' ) );
		add_action( 'wp_ajax_sitevault_process_content_batch', array( $this, 'handle_ajax_content_batch' ) );
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
				'error'               => $state['error'] ?? null,
			)
		);
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_backup_id = sanitize_key( (string) get_option( 'sitevault_active_backup_id', '' ) );
		$database_state   = null;
		$content_state    = null;

		if ( '' !== $active_backup_id ) {
			$backup_dir     = WP_CONTENT_DIR . '/sitevault/backups/' . $active_backup_id;
			$exporter       = new SiteVault_Database_Exporter();
			$archiver       = new SiteVault_Content_Archiver();
			$database_state = $exporter->get_state( $backup_dir );
			$content_state  = $archiver->get_state( $backup_dir );
		}

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
