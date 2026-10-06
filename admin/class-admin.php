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
		add_action( 'wp_ajax_sitevault_backup_worker_tick', array( $this, 'handle_ajax_backup_worker_tick' ) );
		add_action( 'admin_post_sitevault_download_backup', array( $this, 'handle_download_backup' ) );
		add_action( 'admin_post_sitevault_resume_backup', array( $this, 'handle_resume_backup' ) );
		add_action( 'admin_post_sitevault_delete_backup', array( $this, 'handle_delete_backup' ) );
		add_action( 'admin_post_sitevault_import_validate', array( $this, 'handle_import_validate' ) );
		add_action( 'admin_post_sitevault_validate_existing', array( $this, 'handle_validate_existing' ) );
		add_action( 'admin_post_sitevault_prepare_restore_plan', array( $this, 'handle_prepare_restore_plan' ) );
		add_action( 'admin_post_sitevault_start_restore_safety', array( $this, 'handle_start_restore_safety' ) );
		add_action( 'wp_ajax_sitevault_restore_safety_database', array( $this, 'handle_ajax_restore_safety_database' ) );
		add_action( 'wp_ajax_sitevault_restore_safety_content', array( $this, 'handle_ajax_restore_safety_content' ) );
		add_action( 'wp_ajax_sitevault_restore_safety_package', array( $this, 'handle_ajax_restore_safety_package' ) );
		add_action( 'admin_post_sitevault_start_database_staging', array( $this, 'handle_start_database_staging' ) );
		add_action( 'wp_ajax_sitevault_database_stage_import', array( $this, 'handle_ajax_database_stage_import' ) );
		add_action( 'wp_ajax_sitevault_database_stage_verify', array( $this, 'handle_ajax_database_stage_verify' ) );
		add_action( 'wp_ajax_sitevault_database_stage_transform', array( $this, 'handle_ajax_database_stage_transform' ) );
		add_action( 'wp_ajax_sitevault_database_stage_verify_transform', array( $this, 'handle_ajax_database_stage_verify_transform' ) );
		add_action( 'admin_post_sitevault_start_content_staging', array( $this, 'handle_start_content_staging' ) );
		add_action( 'wp_ajax_sitevault_content_stage_extract', array( $this, 'handle_ajax_content_stage_extract' ) );
		add_action( 'wp_ajax_sitevault_content_stage_verify', array( $this, 'handle_ajax_content_stage_verify' ) );
		add_action( 'admin_post_sitevault_seal_cutover_readiness', array( $this, 'handle_seal_cutover_readiness' ) );
		add_action( 'admin_post_sitevault_execute_cutover', array( $this, 'handle_execute_cutover' ) );
		add_action( 'admin_post_sitevault_execute_manual_rollback', array( $this, 'handle_execute_manual_rollback' ) );
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
		wp_enqueue_script(
			'sitevault-admin-actions',
			SITEVAULT_URL . 'admin/assets/js/admin-actions.js',
			array(),
			SITEVAULT_VERSION,
			true
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

		SiteVault_Backup_Worker::schedule( $result['backup_id'] );

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

	public function handle_ajax_backup_worker_tick(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to process SiteVault backups.' ), 403 );
		}
		check_ajax_referer( 'sitevault_backup_worker_tick', 'nonce' );
		$backup_id = sanitize_key( (string) get_option( 'sitevault_active_backup_id', '' ) );
		if ( '' === $backup_id ) {
			wp_send_json_success( array( 'status' => 'idle', 'continue' => false ) );
		}
		$result = ( new SiteVault_Backup_Worker() )->tick( $backup_id );
		if ( ! $result['success'] ) {
			wp_send_json_error( $result, 500 );
		}
		if ( ! empty( $result['continue'] ) ) {
			SiteVault_Backup_Worker::schedule( $backup_id );
		}
		wp_send_json_success( $result );
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

	public function handle_resume_backup(): void {
		$this->authorise_request( 'sitevault_resume_backup' );
		$backup_id = isset( $_POST['backup_id'] ) ? sanitize_key( wp_unslash( $_POST['backup_id'] ) ) : '';
		$history   = new SiteVault_Backup_History();
		$backup_dir= $history->backup_directory( $backup_id );
		if ( null === $backup_dir || ! is_dir( $backup_dir ) ) {
			$this->redirect_with_message( 'error', 'The selected backup could not be found.' );
		}
		$result = ( new SiteVault_Content_Archiver() )->resume_failed( $backup_dir );
		if ( ! $result['success'] ) {
			$this->redirect_with_message( 'error', $result['message'] ?? 'Backup could not be resumed.' );
		}
		SiteVault_Backup_Worker::schedule( $backup_id );
		$this->redirect_with_message( 'started', 'Backup resumed from its last saved checkpoint. Background continuation has been scheduled.' );
	}

	public function handle_delete_backup(): void {
		$this->authorise_request( 'sitevault_delete_backup' );
		$backup_id = isset( $_POST['backup_id'] ) ? sanitize_key( wp_unslash( $_POST['backup_id'] ) ) : '';
		$result    = ( new SiteVault_Backup_History() )->delete_backup( $backup_id );
		if ( ! $result['success'] ) {
			$this->redirect_with_message( 'error', $result['message'] ?? 'Backup could not be deleted.' );
		}
		if ( $backup_id === sanitize_key( (string) get_option( 'sitevault_active_backup_id', '' ) ) ) {
			delete_option( 'sitevault_active_backup_id' );
		}
		$this->redirect_with_message( 'complete', 'Backup deleted.' );
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

	public function handle_import_validate(): void {
		$this->authorise_request( 'sitevault_import_validate' );

		if ( empty( $_FILES['sitevault_package'] ) || ! is_array( $_FILES['sitevault_package'] ) ) {
			$this->redirect_with_message( 'error', 'Please choose a .sitevault package to validate.' );
		}

		$manager = new SiteVault_Import_Manager();
		$result  = $manager->create_from_upload( $_FILES['sitevault_package'] );

		if ( ! $result['success'] ) {
			update_option( 'sitevault_last_import_validation', $result['state'] ?? array(
				'status' => 'invalid',
				'error'  => $result['message'] ?? 'Package validation failed.',
			), false );
			$this->redirect_with_message( 'error', $result['message'] ?? 'Package validation failed.' );
		}

		update_option( 'sitevault_last_import_validation', $result['state'], false );
		$this->redirect_with_message( 'complete', 'SiteVault package validated successfully. No restore changes were made.' );
	}

	public function handle_validate_existing(): void {
		$this->authorise_request( 'sitevault_validate_existing' );

		$backup_id = isset( $_POST['backup_id'] ) ? sanitize_key( wp_unslash( $_POST['backup_id'] ) ) : '';

		if ( '' === $backup_id ) {
			$this->redirect_with_message( 'error', 'Backup ID is missing.' );
		}

		$manager = new SiteVault_Import_Manager();
		$result  = $manager->validate_existing_backup( $backup_id );

		if ( ! $result['success'] ) {
			update_option( 'sitevault_last_import_validation', array(
				'status'    => 'invalid',
				'backup_id' => $backup_id,
				'error'     => $result['message'] ?? 'Package validation failed.',
			), false );
			$this->redirect_with_message( 'error', $result['message'] ?? 'Package validation failed.' );
		}

		update_option( 'sitevault_last_import_validation', $result['state'], false );
		$this->redirect_with_message( 'complete', 'Existing SiteVault backup validated successfully for restore compatibility.' );
	}

	public function handle_prepare_restore_plan(): void {
		$this->authorise_request( 'sitevault_prepare_restore_plan' );

		$validation = get_option( 'sitevault_last_import_validation', array() );

		if (
			! is_array( $validation ) ||
			'validated' !== ( $validation['status'] ?? '' ) ||
			empty( $validation['ready_for_restore'] )
		) {
			$this->redirect_with_message( 'error', 'Validate a SiteVault package before preparing a restore plan.' );
		}

		$workspace = new SiteVault_Restore_Workspace();
		$prepared  = $workspace->prepare( $validation );

		if ( ! $prepared['success'] ) {
			update_option(
				'sitevault_last_restore_plan',
				array(
					'status' => 'failed',
					'error'  => $prepared['message'] ?? 'Restore workspace preparation failed.',
				),
				false
			);
			$this->redirect_with_message( 'error', $prepared['message'] ?? 'Restore workspace preparation failed.' );
		}

		$planner = new SiteVault_Restore_Planner();
		$result  = $planner->create_plan( $prepared['state'] );

		if ( ! $result['success'] ) {
			update_option(
				'sitevault_last_restore_plan',
				array(
					'status'  => 'failed',
					'plan_id' => $prepared['state']['plan_id'] ?? '',
					'error'   => $result['message'] ?? 'Restore compatibility planning failed.',
				),
				false
			);
			$this->redirect_with_message( 'error', $result['message'] ?? 'Restore compatibility planning failed.' );
		}

		$plan = $result['plan'];
		$plan['workspace'] = array(
			'plan_id'            => $prepared['state']['plan_id'] ?? '',
			'extracted_entries'  => (int) ( $prepared['state']['extracted_entries'] ?? 0 ),
			'integrity_verified' => (bool) ( $prepared['state']['integrity_verified'] ?? false ),
		);
		update_option( 'sitevault_last_restore_plan', $plan, false );

		$this->redirect_with_message(
			'ready' === ( $plan['status'] ?? '' ) ? 'complete' : 'error',
			'ready' === ( $plan['status'] ?? '' )
				? 'Restore workspace prepared and compatibility plan completed. No restore changes were made.'
				: 'Restore plan completed with blockers. Review the compatibility report before continuing.'
		);
	}

	public function handle_start_restore_safety(): void {
		$this->authorise_request( 'sitevault_start_restore_safety' );

		$plan = get_option( 'sitevault_last_restore_plan', array() );

		if ( ! is_array( $plan ) || 'ready' !== ( $plan['status'] ?? '' ) ) {
			$this->redirect_with_message( 'error', 'A ready restore compatibility plan is required before creating the safety snapshot.' );
		}

		$manager = new SiteVault_Restore_Safety_Manager();
		$result  = $manager->start( $plan );

		if ( ! $result['success'] ) {
			$this->redirect_with_message( 'error', $result['message'] ?? 'Unable to start the pre-restore safety snapshot.' );
		}

		$this->redirect_with_message( 'started', 'Pre-restore safety snapshot started. Keep this page open while SiteVault completes and verifies it.' );
	}

	public function handle_ajax_restore_safety_database(): void {
		$this->authorise_ajax( 'sitevault_restore_safety_database' );

		$manager = new SiteVault_Restore_Safety_Manager();
		$result  = $manager->process_database_batch();

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Safety database snapshot failed.' ), 500 );
		}

		wp_send_json_success( $this->restore_safety_payload( $result['state'] ) );
	}

	public function handle_ajax_restore_safety_content(): void {
		$this->authorise_ajax( 'sitevault_restore_safety_content' );

		$manager = new SiteVault_Restore_Safety_Manager();
		$result  = $manager->process_content_batch();

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Safety wp-content snapshot failed.' ), 500 );
		}

		wp_send_json_success( $this->restore_safety_payload( $result['state'] ) );
	}

	public function handle_ajax_restore_safety_package(): void {
		$this->authorise_ajax( 'sitevault_restore_safety_package' );

		$plan = get_option( 'sitevault_last_restore_plan', array() );

		if ( ! is_array( $plan ) || 'ready' !== ( $plan['status'] ?? '' ) ) {
			wp_send_json_error( array( 'message' => 'Restore plan is no longer ready. Recreate the restore plan.' ), 409 );
		}

		$manager = new SiteVault_Restore_Safety_Manager();
		$result  = $manager->build_package_and_seal( $plan );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Safety snapshot package verification failed.' ), 500 );
		}

		wp_send_json_success( $this->restore_safety_payload( $result['state'] ) );
	}

	public function handle_start_database_staging(): void {
		$this->authorise_request( 'sitevault_start_database_staging' );

		$plan   = get_option( 'sitevault_last_restore_plan', array() );
		$safety = ( new SiteVault_Restore_Safety_Manager() )->get_state();

		$stager = new SiteVault_Database_Stager();
		$result = $stager->start( is_array( $plan ) ? $plan : array(), is_array( $safety ) ? $safety : array() );

		if ( ! $result['success'] ) {
			$this->redirect_with_message( 'error', $result['message'] ?? 'Unable to initialise staged database import.' );
		}

		$this->redirect_with_message( 'started', 'Shadow database staging started. Live WordPress tables are not being modified.' );
	}

	public function handle_ajax_database_stage_import(): void {
		$this->authorise_ajax( 'sitevault_database_stage_import' );
		$result = ( new SiteVault_Database_Stager() )->process_import_batch();

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Shadow database import failed.' ), 500 );
		}

		wp_send_json_success( $this->database_staging_payload( $result['state'] ) );
	}

	public function handle_ajax_database_stage_verify(): void {
		$this->authorise_ajax( 'sitevault_database_stage_verify' );
		$result = ( new SiteVault_Database_Stager() )->verify_import();

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Shadow database verification failed.' ), 500 );
		}

		wp_send_json_success( $this->database_staging_payload( $result['state'] ) );
	}

	public function handle_ajax_database_stage_transform(): void {
		$this->authorise_ajax( 'sitevault_database_stage_transform' );
		$plan = get_option( 'sitevault_last_restore_plan', array() );
		$result = ( new SiteVault_Database_Stager() )->process_transform_batch( is_array( $plan ) ? $plan : array() );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Shadow database migration transform failed.' ), 500 );
		}

		wp_send_json_success( $this->database_staging_payload( $result['state'] ) );
	}

	public function handle_ajax_database_stage_verify_transform(): void {
		$this->authorise_ajax( 'sitevault_database_stage_verify_transform' );
		$plan = get_option( 'sitevault_last_restore_plan', array() );
		$result = ( new SiteVault_Database_Stager() )->verify_transform( is_array( $plan ) ? $plan : array() );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Shadow database transform verification failed.' ), 500 );
		}

		wp_send_json_success( $this->database_staging_payload( $result['state'] ) );
	}

	public function handle_start_content_staging(): void {
		$this->authorise_request( 'sitevault_start_content_staging' );

		$plan      = get_option( 'sitevault_last_restore_plan', array() );
		$safety    = ( new SiteVault_Restore_Safety_Manager() )->get_state();
		$database  = ( new SiteVault_Database_Stager() )->get_state();

		$stager = new SiteVault_Content_Stager();
		$result = $stager->start(
			is_array( $plan ) ? $plan : array(),
			is_array( $safety ) ? $safety : array(),
			is_array( $database ) ? $database : array()
		);

		if ( ! $result['success'] ) {
			$this->redirect_with_message( 'error', $result['message'] ?? 'Unable to initialise wp-content staging.' );
		}

		$this->redirect_with_message( 'started', 'Shadow wp-content staging started. Live WordPress files are not being modified.' );
	}

	public function handle_ajax_content_stage_extract(): void {
		$this->authorise_ajax( 'sitevault_content_stage_extract' );
		$result = ( new SiteVault_Content_Stager() )->process_batch();

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Shadow wp-content extraction failed.' ), 500 );
		}

		wp_send_json_success( $this->content_staging_payload( $result['state'] ) );
	}

	public function handle_ajax_content_stage_verify(): void {
		$this->authorise_ajax( 'sitevault_content_stage_verify' );
		$result = ( new SiteVault_Content_Stager() )->verify();

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Shadow wp-content verification failed.' ), 500 );
		}

		wp_send_json_success( $this->content_staging_payload( $result['state'] ) );
	}

	public function handle_seal_cutover_readiness(): void {
		$this->authorise_request( 'sitevault_seal_cutover_readiness' );

		$plan      = get_option( 'sitevault_last_restore_plan', array() );
		$safety    = ( new SiteVault_Restore_Safety_Manager() )->get_state();
		$database  = ( new SiteVault_Database_Stager() )->get_state();
		$content   = ( new SiteVault_Content_Stager() )->get_state();

		$gate   = new SiteVault_Cutover_Readiness();
		$result = $gate->seal(
			is_array( $plan ) ? $plan : array(),
			is_array( $safety ) ? $safety : array(),
			is_array( $database ) ? $database : array(),
			is_array( $content ) ? $content : array()
		);

		if ( ! $result['success'] ) {
			$this->redirect_with_message( 'error', $result['message'] ?? 'Cutover readiness sealing failed.' );
		}

		$this->redirect_with_message(
			'complete',
			'Cutover readiness sealed successfully. Live restore execution is still locked.'
		);
	}

	public function handle_execute_cutover(): void {
		$this->authorise_request( 'sitevault_execute_cutover' );

		$phrase = isset( $_POST['sitevault_confirm_phrase'] )
			? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['sitevault_confirm_phrase'] ) ) ) )
			: '';
		$acknowledged = isset( $_POST['sitevault_cutover_ack'] ) && '1' === (string) $_POST['sitevault_cutover_ack'];

		if ( 'RESTORE' !== $phrase || ! $acknowledged ) {
			$this->redirect_with_message(
				'error',
				'Live cutover was not started. Tick the acknowledgement and type RESTORE exactly.'
			);
		}

		$plan      = get_option( 'sitevault_last_restore_plan', array() );
		$safety    = ( new SiteVault_Restore_Safety_Manager() )->get_state();
		$database  = ( new SiteVault_Database_Stager() )->get_state();
		$content   = ( new SiteVault_Content_Stager() )->get_state();
		$readiness = ( new SiteVault_Cutover_Readiness() )->get_state();

		$manager = new SiteVault_Cutover_Manager();
		$result  = $manager->execute(
			is_array( $plan ) ? $plan : array(),
			is_array( $safety ) ? $safety : array(),
			is_array( $database ) ? $database : array(),
			is_array( $content ) ? $content : array(),
			is_array( $readiness ) ? $readiness : array()
		);

		if ( ! $result['success'] ) {
			$state = is_array( $result['state'] ?? null ) ? $result['state'] : array();

			if ( ! empty( $state['rollback_success'] ) ) {
				$this->redirect_with_message( 'error', $result['message'] ?? 'Cutover failed and was rolled back.' );
			}

			wp_die(
				esc_html( $result['message'] ?? 'Cutover failed and automatic rollback was incomplete.' ),
				'SiteVault recovery required',
				array( 'response' => 500 )
			);
		}

		$target = esc_url( (string) ( $result['state']['target_home_url'] ?? home_url( '/' ) ) );
		$message = '<h1>SiteVault restore completed</h1>';
		$message .= '<p>The live database and wp-content promotion passed verification.</p>';
		$message .= '<p><strong>Important:</strong> a cross-domain restore can replace the WordPress users table, so your previous target-site admin session may no longer be valid.</p>';
		$message .= '<p><a class="button button-primary" href="' . $target . '">Open restored website</a></p>';
		$message .= '<p>The pre-restore safety package and fast rollback material have been retained.</p>';

		wp_die(
			wp_kses_post( $message ),
			'SiteVault restore complete',
			array( 'response' => 200 )
		);
	}

	public function handle_execute_manual_rollback(): void {
		$this->authorise_request( 'sitevault_execute_manual_rollback' );

		$phrase = isset( $_POST['sitevault_rollback_phrase'] )
			? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['sitevault_rollback_phrase'] ) ) ) )
			: '';
		$acknowledged = isset( $_POST['sitevault_rollback_ack'] ) && '1' === (string) $_POST['sitevault_rollback_ack'];

		if ( 'ROLLBACK' !== $phrase || ! $acknowledged ) {
			$this->redirect_with_message(
				'error',
				'Manual rollback was not started. Tick the acknowledgement and type ROLLBACK exactly.'
			);
		}

		$manager = new SiteVault_Cutover_Manager();
		$result  = $manager->execute_manual_rollback();

		if ( ! $result['success'] ) {
			wp_die(
				esc_html( $result['message'] ?? 'Manual rollback did not complete safely.' ),
				'SiteVault rollback recovery required',
				array( 'response' => 500 )
			);
		}

		$target = esc_url( (string) ( $result['state']['target_home_url'] ?? home_url( '/' ) ) );
		$message = '<h1>SiteVault rollback completed</h1>';
		$message .= '<p>The original pre-restore target database and wp-content were restored and verified.</p>';
		$message .= '<p><strong>Important:</strong> the original target users table is live again, so your current restored-source login may no longer be valid.</p>';
		$message .= '<p><a class="button button-primary" href="' . $target . '">Open rolled-back website</a></p>';
		$message .= '<p>The SiteVault transaction journal and safety package remain retained for audit/recovery.</p>';

		wp_die(
			wp_kses_post( $message ),
			'SiteVault rollback complete',
			array( 'response' => 200 )
		);
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
		$import_validation = get_option( 'sitevault_last_import_validation', array() );
		$restore_plan      = get_option( 'sitevault_last_restore_plan', array() );
		$safety_manager    = new SiteVault_Restore_Safety_Manager();
		$restore_safety    = $safety_manager->get_state();
		$database_stager   = new SiteVault_Database_Stager();
		$database_staging  = $database_stager->get_state();
		$content_stager    = new SiteVault_Content_Stager();
		$content_staging   = $content_stager->get_state();
		$cutover_gate      = new SiteVault_Cutover_Readiness();
		$cutover_readiness = $cutover_gate->get_state();
		$cutover_manager   = new SiteVault_Cutover_Manager();
		$cutover_transaction = $cutover_manager->get_latest_state();

		require SITEVAULT_PATH . 'admin/views/dashboard.php';
	}

	private function content_staging_payload( array $state ): array {
		return array(
			'status'               => $state['status'] ?? '',
			'stage'                => $state['stage'] ?? '',
			'expected_files'       => (int) ( $state['expected_files'] ?? 0 ),
			'expected_bytes'       => isset( $state['expected_bytes'] ) ? (int) $state['expected_bytes'] : null,
			'files_staged'         => (int) ( $state['files_staged'] ?? 0 ),
			'bytes_staged'         => (int) ( $state['bytes_staged'] ?? 0 ),
			'verified_files'       => (int) ( $state['verified_files'] ?? 0 ),
			'verified_bytes'       => (int) ( $state['verified_bytes'] ?? 0 ),
			'target_before_files'  => (int) ( $state['target_before_files'] ?? 0 ),
			'target_before_bytes'  => (int) ( $state['target_before_bytes'] ?? 0 ),
			'live_files_modified'  => (bool) ( $state['live_files_modified'] ?? false ),
			'ready_for_promotion'  => (bool) ( $state['ready_for_promotion'] ?? false ),
			'error'                => $state['error'] ?? null,
		);
	}

	private function database_staging_payload( array $state ): array {
		$transform = is_array( $state['transform_state'] ?? null ) ? $state['transform_state'] : array();

		return array(
			'status'                   => $state['status'] ?? '',
			'stage'                    => $state['stage'] ?? '',
			'staging_prefix'           => $state['staging_prefix'] ?? '',
			'expected_tables'          => (int) ( $state['expected_tables'] ?? 0 ),
			'tables_created'           => (int) ( $state['tables_created'] ?? 0 ),
			'manifest_rows'            => (int) ( $state['manifest_rows'] ?? 0 ),
			'inserted_rows'            => (int) ( $state['inserted_rows'] ?? 0 ),
			'verified_tables'          => (int) ( $state['verified_tables'] ?? 0 ),
			'verified_rows'            => (int) ( $state['verified_rows'] ?? 0 ),
			'statements_executed'      => (int) ( $state['statements_executed'] ?? 0 ),
			'transform_required'       => (bool) ( $state['transform_required'] ?? false ),
			'transform_table_index'    => (int) ( $transform['table_index'] ?? 0 ),
			'transform_rows_scanned'   => (int) ( $transform['rows_scanned'] ?? 0 ),
			'transform_rows_changed'   => (int) ( $transform['rows_changed'] ?? 0 ),
			'transform_cells_changed'  => (int) ( $transform['cells_changed'] ?? 0 ),
			'transform_replacements'   => (int) ( $transform['replacements'] ?? 0 ),
			'live_tables_modified'     => (bool) ( $state['live_tables_modified'] ?? false ),
			'ready_for_live_promotion' => (bool) ( $state['ready_for_live_promotion'] ?? false ),
			'promotion_blocker'        => $state['promotion_blocker'] ?? null,
			'error'                    => $state['error'] ?? null,
		);
	}

	private function restore_safety_payload( array $state ): array {
		$db          = is_array( $state['database'] ?? null ) ? $state['database'] : array();
		$content     = is_array( $state['content'] ?? null ) ? $state['content'] : array();
		$package     = is_array( $state['package'] ?? null ) ? $state['package'] : array();
		$table_total = count( $db['tables'] ?? array() );
		$table_done  = min( (int) ( $db['table_index'] ?? 0 ), $table_total );

		return array(
			'status'              => $state['status'] ?? '',
			'stage'               => $state['stage'] ?? '',
			'snapshot_backup_id'  => $state['snapshot_backup_id'] ?? '',
			'table_done'          => $table_done,
			'table_total'         => $table_total,
			'rows_exported'       => (int) ( $db['rows_exported'] ?? 0 ),
			'content_phase'       => $content['phase'] ?? 'scanning',
			'files_discovered'    => (int) ( $content['files_discovered'] ?? 0 ),
			'files_archived'      => (int) ( $content['files_archived'] ?? 0 ),
			'bytes_archived'      => (int) ( $content['bytes_archived'] ?? 0 ),
			'archive_verified'    => (bool) ( $content['archive_verified'] ?? false ),
			'package_verified'    => (bool) ( $package['verified'] ?? false ),
			'package_name'        => $package['package_name'] ?? '',
			'package_size'        => (int) ( $package['package_size'] ?? 0 ),
			'safety_ready'        => 'complete' === ( $state['status'] ?? '' ) && 'safety_ready' === ( $state['staging']['status'] ?? '' ),
			'execution_locked'    => (bool) ( $state['staging']['restore_execution_locked'] ?? true ),
			'error'               => $state['error'] ?? null,
		);
	}

	private function authorise_ajax( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to perform this SiteVault operation.' ), 403 );
		}

		check_ajax_referer( $action, 'nonce' );
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
