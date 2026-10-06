<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Restore_Finalizer {

	public function get_state(): array {
		return ( new SiteVault_Cutover_Manager() )->get_latest_state();
	}

	public function finalize(): array {
		global $wpdb;

		$manager = new SiteVault_Cutover_Manager();
		$tx = $manager->get_latest_state();

		if ( empty( $tx ) || ! is_array( $tx ) ) {
			return $this->error( 'No SiteVault cutover transaction is available to finalise.' );
		}

		if ( ! empty( $tx['finalization']['completed'] ) ) {
			return array( 'success' => true, 'state' => $tx, 'message' => 'Restore finalisation was already completed.' );
		}

		$status = (string) ( $tx['status'] ?? '' );
		if ( ! in_array( $status, array( 'completed', 'manually_rolled_back' ), true ) ) {
			return $this->error( 'Finalisation is allowed only after a verified successful restore or a verified completed manual rollback.' );
		}

		if ( SiteVault_Cutover_Manager::is_locked() || ! empty( $tx['maintenance_lock'] ) ) {
			return $this->error( 'Restore finalisation is blocked while a SiteVault restore lock is active.' );
		}

		if ( 'completed' === $status && empty( $tx['rollback_available'] ) ) {
			return $this->error( 'Completed restore is missing its expected fast rollback marker. Refusing cleanup.' );
		}

		if ( 'manually_rolled_back' === $status && 'completed' !== ( $tx['manual_rollback']['status'] ?? '' ) ) {
			return $this->error( 'Manual rollback is not in a verified completed state.' );
		}

		$plan_id = sanitize_key( (string) ( $tx['plan_id'] ?? '' ) );
		if ( '' === $plan_id ) {
			return $this->error( 'Transaction plan ID is missing.' );
		}

		$transaction_root = WP_CONTENT_DIR . '/sitevault/cutover/' . $plan_id;
		$journal = $transaction_root . '/transaction.json';
		if ( ! is_readable( $journal ) ) {
			return $this->error( 'Filesystem transaction journal is unavailable. Refusing cleanup.' );
		}

		$before_db = $this->live_database_baseline( $tx );
		if ( ! $before_db['success'] ) {
			return $before_db;
		}
		$before_files = $this->managed_live_content_stats();

		$tx['finalization'] = array(
			'completed' => false,
			'status' => 'running',
			'started_at' => gmdate( 'c' ),
			'completed_at' => null,
			'outcome' => 'completed' === $status ? 'restored_site_kept' : 'pre_restore_target_kept',
			'safety_snapshot_id' => (string) ( $tx['safety_snapshot_id'] ?? '' ),
			'safety_package_retained' => true,
			'database_tables_removed' => array(),
			'paths_removed' => array(),
			'live_database_before' => $before_db['tables'],
			'live_content_before' => $before_files,
			'error' => null,
		);
		$this->save_transaction( $journal, $tx );

		$drop = $this->drop_owned_temporary_tables( $tx );
		if ( ! $drop['success'] ) {
			return $this->fail( $journal, $tx, $drop['message'] );
		}
		$tx['finalization']['database_tables_removed'] = $drop['removed'];

		$paths = array(
			WP_CONTENT_DIR . '/sitevault/restore-staging/' . $plan_id,
			WP_CONTENT_DIR . '/sitevault/restore-plans/' . $plan_id,
			$transaction_root . '/rollback-wp-content',
			$transaction_root . '/failed-source-wp-content',
			$transaction_root . '/preserved-sitevault-plugin',
			$transaction_root . '/manual-rollback-preserved-plugin',
		);

		foreach ( $paths as $path ) {
			if ( ! file_exists( $path ) && ! is_link( $path ) ) {
				continue;
			}
			$removed = $this->remove_owned_path( $path );
			if ( ! $removed['success'] ) {
				return $this->fail( $journal, $tx, $removed['message'] );
			}
			$tx['finalization']['paths_removed'][] = wp_normalize_path( $path );
		}

		$this->clear_matching_restore_options( $plan_id );

		$after_db = $this->live_database_baseline( $tx );
		if ( ! $after_db['success'] || $after_db['tables'] !== $before_db['tables'] ) {
			return $this->fail( $journal, $tx, 'Live database baseline changed during finalisation. Cleanup stopped.' );
		}
		$after_files = $this->managed_live_content_stats();
		if ( $after_files !== $before_files ) {
			return $this->fail( $journal, $tx, 'Live managed wp-content baseline changed during finalisation. Cleanup stopped.' );
		}

		if ( ! is_dir( untrailingslashit( SITEVAULT_PATH ) ) || ! is_file( SITEVAULT_PATH . 'sitevault.php' ) ) {
			return $this->fail( $journal, $tx, 'Current SiteVault plugin could not be verified after cleanup.' );
		}

		$tx['rollback_available'] = false;
		$tx['finalization']['status'] = 'completed';
		$tx['finalization']['completed'] = true;
		$tx['finalization']['completed_at'] = gmdate( 'c' );
		$tx['finalization']['live_database_after'] = $after_db['tables'];
		$tx['finalization']['live_content_after'] = $after_files;
		$tx['finalization']['sitevault_plugin_preserved'] = true;
		$tx['updated_at'] = gmdate( 'c' );

		$this->save_transaction( $journal, $tx );
		file_put_contents(
			$transaction_root . '/finalization.json',
			wp_json_encode( $tx['finalization'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		);

		return array(
			'success' => true,
			'state' => $tx,
			'message' => 'Restore finalisation completed. Temporary restore material was removed; the safety backup and audit journal were retained.',
		);
	}

	private function drop_owned_temporary_tables( array $tx ): array {
		global $wpdb;
		$candidates = array();

		foreach ( array_values( is_array( $tx['database_rollback_map'] ?? null ) ? $tx['database_rollback_map'] : array() ) as $table ) {
			if ( preg_match( '/^svbak_[A-Za-z0-9_]+$/', (string) $table ) ) {
				$candidates[] = (string) $table;
			}
		}
		foreach ( array_keys( is_array( $tx['database_promotion_map'] ?? null ) ? $tx['database_promotion_map'] : array() ) as $table ) {
			if ( preg_match( '/^svstg_[A-Za-z0-9_]+$/', (string) $table ) ) {
				$candidates[] = (string) $table;
			}
		}

		$candidates = array_values( array_unique( $candidates ) );
		$removed = array();

		foreach ( $candidates as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( (string) $exists !== $table ) {
				continue;
			}
			$result = $wpdb->query( 'DROP TABLE ' . $this->quote_identifier( $table ) );
			if ( false === $result ) {
				return $this->error( 'Unable to remove owned temporary restore table: ' . $table . '. ' . $wpdb->last_error );
			}
			$removed[] = $table;
		}

		return array( 'success' => true, 'removed' => $removed );
	}

	private function live_database_baseline( array $tx ): array {
		global $wpdb;
		$tables = array();
		$live_names = array_values( is_array( $tx['database_promotion_map'] ?? null ) ? $tx['database_promotion_map'] : array() );

		if ( 'manually_rolled_back' === ( $tx['status'] ?? '' ) ) {
			$live_names = array_keys( is_array( $tx['database_rollback_map'] ?? null ) ? $tx['database_rollback_map'] : array() );
		}

		foreach ( array_values( array_unique( $live_names ) ) as $table ) {
			$table = (string) $table;
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				return $this->error( 'Unsafe live table identifier found in transaction journal.' );
			}
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( (string) $exists !== $table ) {
				return $this->error( 'Expected live table is missing before finalisation: ' . $table );
			}
			$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->quote_identifier( $table ) );
			if ( null === $count ) {
				return $this->error( 'Unable to verify live table before finalisation: ' . $table );
			}
			$tables[ $table ] = (int) $count;
		}
		ksort( $tables, SORT_STRING );
		return array( 'success' => true, 'tables' => $tables );
	}

	private function managed_live_content_stats(): array {
		$files = 0;
		$bytes = 0;
		$root = wp_normalize_path( WP_CONTENT_DIR );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$path = wp_normalize_path( $file->getPathname() );
			$rel = ltrim( substr( $path, strlen( $root ) ), '/' );
			if ( 'sitevault/' === substr( $rel, 0, 10 ) || 'plugins/wp-sitevault/' === substr( $rel, 0, 21 ) ) {
				continue;
			}
			$files++;
			$bytes += (int) $file->getSize();
		}
		return array( 'files' => $files, 'bytes' => $bytes );
	}

	private function clear_matching_restore_options( string $plan_id ): void {
		$options = array(
			'sitevault_last_restore_plan',
			'sitevault_restore_safety_state',
			'sitevault_database_staging_state',
			'sitevault_content_staging_state',
			'sitevault_cutover_readiness_state',
		);
		foreach ( $options as $option ) {
			$value = get_option( $option, null );
			if ( is_array( $value ) && ( $value['plan_id'] ?? '' ) === $plan_id ) {
				delete_option( $option );
			}
		}
	}

	private function remove_owned_path( string $path ): array {
		$runtime = trailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/sitevault' ) );
		$normal = wp_normalize_path( $path );
		if ( 0 !== strpos( trailingslashit( $normal ), $runtime ) || $normal === untrailingslashit( $runtime ) ) {
			return $this->error( 'Refusing to remove a path outside SiteVault runtime storage.' );
		}
		return $this->remove_tree( $path );
	}

	private function remove_tree( string $path ): array {
		if ( is_link( $path ) || is_file( $path ) ) {
			return @unlink( $path ) ? array( 'success' => true ) : $this->error( 'Unable to remove SiteVault temporary file: ' . $path );
		}
		if ( ! is_dir( $path ) ) {
			return array( 'success' => true );
		}
		$items = scandir( $path );
		if ( false === $items ) {
			return $this->error( 'Unable to read SiteVault temporary directory: ' . $path );
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$result = $this->remove_tree( trailingslashit( $path ) . $item );
			if ( ! $result['success'] ) {
				return $result;
			}
		}
		return @rmdir( $path ) ? array( 'success' => true ) : $this->error( 'Unable to remove SiteVault temporary directory: ' . $path );
	}

	private function save_transaction( string $journal, array $tx ): void {
		if ( false === file_put_contents( $journal, wp_json_encode( $tx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX ) ) {
			throw new RuntimeException( 'Unable to update SiteVault transaction journal during finalisation.' );
		}
	}

	private function fail( string $journal, array $tx, string $message ): array {
		$tx['finalization']['status'] = 'failed';
		$tx['finalization']['error'] = $message;
		$tx['finalization']['updated_at'] = gmdate( 'c' );
		$tx['updated_at'] = gmdate( 'c' );
		$this->save_transaction( $journal, $tx );
		return $this->error( $message, $tx );
	}

	private function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	private function error( string $message, array $state = array() ): array {
		return array( 'success' => false, 'message' => $message, 'state' => $state );
	}
}
