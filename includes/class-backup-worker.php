<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Backup_Worker {

	private const EVENT = 'sitevault_backup_worker_tick';
	private const LOCK_TTL = 900;

	public static function register(): void {
		add_action( self::EVENT, array( __CLASS__, 'run_scheduled' ) );
	}

	public static function schedule( string $backup_id ): void {
		$backup_id = sanitize_key( $backup_id );
		if ( '' === $backup_id ) {
			return;
		}
		update_option( 'sitevault_active_backup_id', $backup_id, false );
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_single_event( time() + 5, self::EVENT );
		}
		spawn_cron();
	}

	public static function run_scheduled(): void {
		$backup_id = sanitize_key( (string) get_option( 'sitevault_active_backup_id', '' ) );
		if ( '' === $backup_id ) {
			return;
		}
		$result = ( new self() )->tick( $backup_id );
		if ( ! empty( $result['continue'] ) ) {
			if ( ! wp_next_scheduled( self::EVENT ) ) {
				wp_schedule_single_event( time() + 20, self::EVENT );
			}
		}
	}

	public function tick( string $backup_id ): array {
		$history = new SiteVault_Backup_History();
		$dir = $history->backup_directory( $backup_id );
		if ( null === $dir || ! is_dir( $dir ) ) {
			return array( 'success' => false, 'continue' => false, 'message' => 'Backup job directory was not found.' );
		}

		$lock = $dir . '/worker.lock';
		if ( ! $this->acquire_lock( $lock ) ) {
			return array( 'success' => true, 'continue' => true, 'message' => 'Another SiteVault worker is processing this backup.' );
		}

		try {
			$db = new SiteVault_Database_Exporter();
			$content = new SiteVault_Content_Archiver();
			$db_state = $db->get_state( $dir );
			$content_state = $content->get_state( $dir );

			if ( 'failed' === ( $db_state['status'] ?? '' ) || 'failed' === ( $content_state['status'] ?? '' ) ) {
				return array( 'success' => false, 'continue' => false, 'message' => 'Backup is paused after an error and requires Resume.', 'database' => $db_state, 'content' => $content_state );
			}

			if ( 'complete' !== ( $db_state['status'] ?? '' ) ) {
				$result = $db->process_batch( $dir );
				return array( 'success' => (bool) $result['success'], 'continue' => ! empty( $result['success'] ), 'stage' => 'database', 'state' => $result['state'] ?? null, 'message' => $result['message'] ?? null );
			}

			if ( 'complete' !== ( $content_state['status'] ?? '' ) ) {
				$result = $content->process_batch( $dir );
				return array( 'success' => (bool) $result['success'], 'continue' => ! empty( $result['success'] ), 'stage' => 'content', 'state' => $result['state'] ?? null, 'message' => $result['message'] ?? null );
			}

			$package = ( new SiteVault_Package_Builder() )->build( $dir );
			if ( ! $package['success'] ) {
				return array( 'success' => false, 'continue' => false, 'stage' => 'package', 'state' => $package['state'] ?? null, 'message' => $package['message'] ?? 'Package creation failed.' );
			}

			delete_option( 'sitevault_active_backup_id' );
			return array( 'success' => true, 'continue' => false, 'stage' => 'complete', 'state' => $package['state'] );
		} finally {
			@unlink( $lock );
		}
	}

	private function acquire_lock( string $file ): bool {
		if ( is_file( $file ) && ( time() - (int) @filemtime( $file ) ) < self::LOCK_TTL ) {
			return false;
		}
		$created = @fopen( $file, 'x' );
		if ( false === $created ) {
			if ( is_file( $file ) && ( time() - (int) @filemtime( $file ) ) >= self::LOCK_TTL ) {
				@unlink( $file );
				$created = @fopen( $file, 'x' );
			}
			if ( false === $created ) {
				return false;
			}
		}
		fwrite( $created, (string) time() );
		fclose( $created );
		return true;
	}
}
