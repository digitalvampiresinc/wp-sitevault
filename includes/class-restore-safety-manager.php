<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Restore_Safety_Manager {

	private const OPTION = 'sitevault_restore_safety_state';

	public function start( array $restore_plan ): array {
		if ( 'ready' !== ( $restore_plan['status'] ?? '' ) ) {
			return $this->error( 'Restore plan is not ready for safety snapshot creation.' );
		}

		$existing = $this->get_state();

		if (
			is_array( $existing ) &&
			in_array( $existing['status'] ?? '', array( 'running', 'complete' ), true ) &&
			( $existing['plan_id'] ?? '' ) === ( $restore_plan['plan_id'] ?? '' )
		) {
			return array( 'success' => true, 'state' => $existing );
		}

		$manager = new SiteVault_Backup_Manager();
		$result  = $manager->create_backup(
			'pre_restore',
			array(
				'plan_id'          => $restore_plan['plan_id'] ?? '',
				'source_backup_id' => $restore_plan['backup_id'] ?? '',
				'target_home_url'  => $restore_plan['target']['home_url'] ?? home_url(),
				'restore_mode'     => $restore_plan['mode'] ?? '',
				'purpose'          => 'mandatory restore rollback snapshot',
			)
		);

		if ( ! $result['success'] ) {
			return $this->error( $result['message'] ?? 'Unable to initialise pre-restore safety snapshot.' );
		}

		$state = array(
			'status'             => 'running',
			'stage'              => 'database',
			'started_at'         => gmdate( 'c' ),
			'updated_at'         => gmdate( 'c' ),
			'completed_at'       => null,
			'plan_id'            => $restore_plan['plan_id'] ?? '',
			'source_backup_id'   => $restore_plan['backup_id'] ?? '',
			'snapshot_backup_id' => $result['backup_id'],
			'target_home_url'    => $restore_plan['target']['home_url'] ?? home_url(),
			'restore_mode'       => $restore_plan['mode'] ?? '',
			'database'           => $result['database'],
			'content'            => $result['content'],
			'package'            => null,
			'staging'            => null,
			'error'              => null,
		);

		$this->save_state( $state );

		return array(
			'success' => true,
			'state'   => $state,
		);
	}

	public function process_database_batch(): array {
		$state = $this->get_state();

		if ( ! $this->is_running_stage( $state, 'database' ) ) {
			return $this->error( 'No pre-restore database snapshot stage is ready to process.', $state );
		}

		$backup_dir = $this->backup_dir( $state );
		$exporter   = new SiteVault_Database_Exporter();
		$result     = $exporter->process_batch( $backup_dir );

		if ( ! $result['success'] ) {
			return $this->fail( $state, $result['message'] ?? 'Pre-restore database snapshot failed.' );
		}

		$state['database']   = $result['state'];
		$state['updated_at'] = gmdate( 'c' );

		if ( 'complete' === ( $result['state']['status'] ?? '' ) ) {
			$state['stage'] = 'content';
		}

		$this->save_state( $state );

		return array( 'success' => true, 'state' => $state );
	}

	public function process_content_batch(): array {
		$state = $this->get_state();

		if ( ! $this->is_running_stage( $state, 'content' ) ) {
			return $this->error( 'No pre-restore content snapshot stage is ready to process.', $state );
		}

		$backup_dir = $this->backup_dir( $state );
		$archiver   = new SiteVault_Content_Archiver();
		$result     = $archiver->process_batch( $backup_dir );

		if ( ! $result['success'] ) {
			return $this->fail( $state, $result['message'] ?? 'Pre-restore wp-content snapshot failed.' );
		}

		$state['content']    = $result['state'];
		$state['updated_at'] = gmdate( 'c' );

		if ( 'complete' === ( $result['state']['status'] ?? '' ) ) {
			$state['stage'] = 'package';
		}

		$this->save_state( $state );

		return array( 'success' => true, 'state' => $state );
	}

	public function build_package_and_seal( array $restore_plan ): array {
		$state = $this->get_state();

		if ( ! $this->is_running_stage( $state, 'package' ) ) {
			return $this->error( 'Pre-restore safety snapshot is not ready for packaging.', $state );
		}

		if ( ( $state['plan_id'] ?? '' ) !== ( $restore_plan['plan_id'] ?? '' ) ) {
			return $this->error( 'Restore plan changed after safety snapshot started. Recreate the safety snapshot.', $state );
		}

		$backup_dir = $this->backup_dir( $state );
		$builder    = new SiteVault_Package_Builder();
		$result     = $builder->build( $backup_dir );

		if ( ! $result['success'] || empty( $result['state']['verified'] ) ) {
			return $this->fail( $state, $result['message'] ?? 'Pre-restore safety package verification failed.' );
		}

		$staging = $this->seal_restore_staging( $restore_plan, $state, $result['state'] );

		if ( ! $staging['success'] ) {
			return $this->fail( $state, $staging['message'] ?? 'Unable to seal controlled restore staging.' );
		}

		$state['package']      = $result['state'];
		$state['staging']      = $staging['state'];
		$state['stage']        = 'complete';
		$state['status']       = 'complete';
		$state['completed_at'] = gmdate( 'c' );
		$state['updated_at']   = gmdate( 'c' );

		$this->save_state( $state );

		return array( 'success' => true, 'state' => $state );
	}

	public function get_state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private function seal_restore_staging( array $restore_plan, array $safety_state, array $package_state ): array {
		$plan_id = sanitize_key( (string) ( $restore_plan['plan_id'] ?? '' ) );

		if ( '' === $plan_id ) {
			return $this->error( 'Restore plan ID is missing.' );
		}

		$root = WP_CONTENT_DIR . '/sitevault/restore-staging/' . $plan_id;

		if ( ! wp_mkdir_p( $root ) ) {
			return $this->error( 'Unable to create controlled restore staging directory.' );
		}

		$plan_json = wp_json_encode( $restore_plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$plan_hash = hash( 'sha256', (string) $plan_json );

		$state = array(
			'status'                   => 'safety_ready',
			'sealed_at'                => gmdate( 'c' ),
			'plan_id'                  => $plan_id,
			'plan_sha256'              => $plan_hash,
			'source_backup_id'         => $restore_plan['backup_id'] ?? '',
			'target_home_url'          => $restore_plan['target']['home_url'] ?? home_url(),
			'restore_mode'             => $restore_plan['mode'] ?? '',
			'safety_snapshot_id'       => $safety_state['snapshot_backup_id'] ?? '',
			'safety_package_file'      => $package_state['package_file'] ?? '',
			'safety_package_sha256'    => $package_state['package_sha256'] ?? '',
			'safety_package_verified'  => (bool) ( $package_state['verified'] ?? false ),
			'restore_execution_locked' => true,
			'next_stage'               => 'database-and-filesystem-restore-execution',
			'destructive_actions_taken'=> false,
		);

		if ( false === file_put_contents(
			$root . '/staging-state.json',
			wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		) ) {
			return $this->error( 'Unable to save controlled restore staging state.' );
		}

		return array( 'success' => true, 'state' => $state );
	}

	private function is_running_stage( array $state, string $stage ): bool {
		return 'running' === ( $state['status'] ?? '' ) && $stage === ( $state['stage'] ?? '' );
	}

	private function backup_dir( array $state ): string {
		return WP_CONTENT_DIR . '/sitevault/backups/' . sanitize_key( (string) ( $state['snapshot_backup_id'] ?? '' ) );
	}

	private function save_state( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	private function fail( array $state, string $message ): array {
		$state['status']     = 'failed';
		$state['error']      = $message;
		$state['updated_at'] = gmdate( 'c' );
		$this->save_state( $state );
		return $this->error( $message, $state );
	}

	private function error( string $message, ?array $state = null ): array {
		return array(
			'success' => false,
			'message' => $message,
			'state'   => $state,
		);
	}
}
