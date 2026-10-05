<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Cutover_Manager {

	private const LOCK_FILE = 'cutover.lock';

	private ?array $active_transaction = null;

	public function execute(
		array $restore_plan,
		array $safety_state,
		array $database_state,
		array $content_state,
		array $readiness_state
	): array {
		global $wpdb;

		$preflight = $this->preflight(
			$restore_plan,
			$safety_state,
			$database_state,
			$content_state,
			$readiness_state
		);

		if ( ! $preflight['success'] ) {
			return $preflight;
		}

		$plan_id = sanitize_key( (string) $restore_plan['plan_id'] );
		$token   = substr( hash( 'sha256', $plan_id . '|' . ( $safety_state['snapshot_backup_id'] ?? '' ) ), 0, 12 );
		$root    = WP_CONTENT_DIR . '/sitevault/cutover/' . $plan_id;

		if ( ! wp_mkdir_p( $root ) ) {
			return $this->error( 'Unable to create the cutover transaction workspace.' );
		}

		$transaction = array(
			'status'                    => 'starting',
			'stage'                     => 'preflight',
			'plan_id'                   => $plan_id,
			'token'                     => $token,
			'started_at'                => gmdate( 'c' ),
			'updated_at'                => gmdate( 'c' ),
			'completed_at'              => null,
			'restore_mode'              => $restore_plan['mode'] ?? '',
			'source_home_url'           => $restore_plan['source']['home_url'] ?? '',
			'target_home_url'           => $restore_plan['target']['home_url'] ?? '',
			'safety_snapshot_id'        => $safety_state['snapshot_backup_id'] ?? '',
			'rollback_root'             => $root . '/rollback-wp-content',
			'failed_source_root'        => $root . '/failed-source-wp-content',
			'plugin_preserve_root'      => $root . '/preserved-sitevault-plugin',
			'database_promoted'         => false,
			'filesystem_promoted'       => false,
			'database_rollback_map'     => array(),
			'database_promotion_map'    => array(),
			'filesystem_live_moved'     => array(),
			'filesystem_source_moved'   => array(),
			'verification'              => array(),
			'rollback_attempted'        => false,
			'rollback_success'          => null,
			'maintenance_lock'          => false,
			'admin_session_may_change'  => 'cross-domain migration' === ( $restore_plan['mode'] ?? '' ),
			'error'                     => null,
		);

		$this->active_transaction = $transaction;
		$this->save_transaction( $transaction );

		try {
			$this->enter_maintenance_lock( $transaction );
			$transaction['maintenance_lock'] = true;
			$transaction['status']           = 'running';
			$transaction['stage']            = 'preserve_plugin';
			$transaction['updated_at']       = gmdate( 'c' );
			$this->checkpoint( $transaction );

			$preserve = $this->preserve_current_plugin( $transaction['plugin_preserve_root'] );

			if ( ! $preserve['success'] ) {
				throw new RuntimeException( $preserve['message'] );
			}

			$transaction['stage']      = 'database_promotion';
			$transaction['updated_at'] = gmdate( 'c' );
			$this->checkpoint( $transaction );

			$db_result = $this->promote_database( $restore_plan, $database_state, $token, $transaction );

			if ( ! $db_result['success'] ) {
				throw new RuntimeException( $db_result['message'] );
			}

			$transaction['database_promoted']     = true;
			$transaction['database_rollback_map'] = $db_result['rollback_map'];
			$transaction['database_promotion_map']= $db_result['promotion_map'];
			$transaction['stage']                 = 'filesystem_promotion';
			$transaction['updated_at']            = gmdate( 'c' );
			$this->checkpoint( $transaction );

			$fs_result = $this->promote_filesystem( $content_state, $transaction );

			if ( ! $fs_result['success'] ) {
				throw new RuntimeException( $fs_result['message'] );
			}

			$transaction['filesystem_promoted']     = true;
			$transaction['filesystem_live_moved']   = $fs_result['live_moved'];
			$transaction['filesystem_source_moved'] = $fs_result['source_moved'];
			$transaction['stage']                   = 'verification';
			$transaction['updated_at']              = gmdate( 'c' );
			$this->checkpoint( $transaction );

			$verify = $this->verify_live_restore(
				$restore_plan,
				$database_state,
				$content_state,
				$fs_result['source_sitevault_plugin_files'],
				$fs_result['source_sitevault_plugin_bytes']
			);

			$transaction['verification'] = $verify;

			if ( ! $verify['success'] ) {
				throw new RuntimeException( $verify['message'] );
			}

			$this->reset_runtime_options_after_success();

			$transaction['status']        = 'completed';
			$transaction['stage']         = 'complete';
			$transaction['completed_at']  = gmdate( 'c' );
			$transaction['updated_at']    = gmdate( 'c' );
			$transaction['error']         = null;
			$transaction['rollback_available'] = true;

			$this->leave_maintenance_lock();
			$transaction['maintenance_lock'] = false;
			$this->checkpoint( $transaction );

			return array(
				'success' => true,
				'state'   => $transaction,
			);
		} catch ( Throwable $e ) {
			$transaction['status']      = 'failed';
			$transaction['stage']       = 'rollback';
			$transaction['error']       = $e->getMessage();
			$transaction['updated_at']  = gmdate( 'c' );
			$transaction['rollback_attempted'] = true;
			$this->checkpoint( $transaction );

			$rollback = $this->rollback( $transaction );

			$transaction['rollback_success'] = $rollback['success'];
			$transaction['status']           = $rollback['success'] ? 'rolled_back' : 'rollback_failed';
			$transaction['stage']            = $rollback['success'] ? 'rollback_complete' : 'manual_recovery_required';
			$transaction['rollback_message'] = $rollback['message'] ?? '';
			$transaction['updated_at']       = gmdate( 'c' );
			$transaction['completed_at']     = gmdate( 'c' );

			if ( $rollback['success'] ) {
				$this->leave_maintenance_lock();
				$transaction['maintenance_lock'] = false;
			}

			$this->checkpoint( $transaction );

			return array(
				'success' => false,
				'message' => $rollback['success']
					? 'Cutover failed, but SiteVault automatically restored the original target site. ' . $e->getMessage()
					: 'Cutover failed and automatic rollback was incomplete. Manual recovery is required. ' . $e->getMessage(),
				'state'   => $transaction,
			);
		}
	}

	public function get_latest_state(): array {
		$root = WP_CONTENT_DIR . '/sitevault/cutover';

		if ( ! is_dir( $root ) ) {
			return array();
		}

		$latest      = array();
		$latest_time = 0;
		$dirs        = glob( trailingslashit( $root ) . '*', GLOB_ONLYDIR );

		if ( ! is_array( $dirs ) ) {
			return array();
		}

		foreach ( $dirs as $dir ) {
			$file = trailingslashit( $dir ) . 'transaction.json';

			if ( ! is_readable( $file ) ) {
				continue;
			}

			$mtime = (int) filemtime( $file );

			if ( $mtime < $latest_time ) {
				continue;
			}

			$data = json_decode( (string) file_get_contents( $file ), true );

			if ( is_array( $data ) ) {
				$latest      = $data;
				$latest_time = $mtime;
			}
		}

		return $latest;
	}

	public static function is_locked(): bool {
		return is_file( WP_CONTENT_DIR . '/sitevault/' . self::LOCK_FILE );
	}

	public static function lock_message(): string {
		$file = WP_CONTENT_DIR . '/sitevault/' . self::LOCK_FILE;

		if ( ! is_readable( $file ) ) {
			return 'Site restore in progress. Please try again shortly.';
		}

		$data = json_decode( (string) file_get_contents( $file ), true );

		if ( is_array( $data ) && ! empty( $data['message'] ) ) {
			return (string) $data['message'];
		}

		return 'Site restore in progress. Please try again shortly.';
	}

	private function preflight(
		array $restore_plan,
		array $safety_state,
		array $database_state,
		array $content_state,
		array $readiness_state
	): array {
		$plan_id = sanitize_key( (string) ( $restore_plan['plan_id'] ?? '' ) );

		if ( '' === $plan_id || 'ready' !== ( $restore_plan['status'] ?? '' ) ) {
			return $this->error( 'Restore plan is no longer Ready.' );
		}

		if (
			'cutover_ready' !== ( $readiness_state['status'] ?? '' ) ||
			( $readiness_state['plan_id'] ?? '' ) !== $plan_id ||
			empty( $readiness_state['execution_locked'] )
		) {
			return $this->error( 'A matching sealed Cutover Ready state is required.' );
		}

		if ( ! empty( $readiness_state['prefix_remap_required'] ) ) {
			return $this->error( 'Database-prefix remapping is still required; live cutover remains blocked.' );
		}

		$gate = new SiteVault_Cutover_Readiness();
		$seal = $gate->seal( $restore_plan, $safety_state, $database_state, $content_state );

		if ( ! $seal['success'] ) {
			return $this->error( 'Final cutover preflight failed: ' . ( $seal['message'] ?? 'readiness seal could not be refreshed.' ) );
		}

		return array( 'success' => true );
	}

	private function promote_database( array $restore_plan, array $database_state, string $token, array &$transaction ): array {
		global $wpdb;

		$target_prefix = (string) ( $restore_plan['target']['db_prefix'] ?? $wpdb->prefix );
		$source_prefix = (string) ( $restore_plan['source']['db_prefix'] ?? '' );
		$table_map     = is_array( $database_state['table_map'] ?? null ) ? $database_state['table_map'] : array();

		if ( '' === $target_prefix || '' === $source_prefix || empty( $table_map ) ) {
			return $this->error( 'Database promotion metadata is incomplete.' );
		}

		$live_tables = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $target_prefix ) . '%' )
		);

		if ( ! is_array( $live_tables ) ) {
			return $this->error( 'Unable to enumerate live target database tables.' );
		}

		$rollback_map  = array();
		$promotion_map = array();
		$rename_pairs  = array();
		$index         = 0;

		foreach ( $live_tables as $live_table ) {
			$live_table = (string) $live_table;

			if ( 0 !== strpos( $live_table, $target_prefix ) ) {
				continue;
			}

			$backup = 'svbak_' . $token . '_' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT );

			if ( strlen( $backup ) > 64 ) {
				return $this->error( 'Generated rollback table name exceeded MySQL identifier limits.' );
			}

			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $backup ) )
			);

			if ( $exists ) {
				return $this->error( 'Rollback database namespace already exists: ' . $backup );
			}

			$rollback_map[ $live_table ] = $backup;
			$rename_pairs[] = $this->quote_identifier( $live_table ) . ' TO ' . $this->quote_identifier( $backup );
			$index++;
		}

		foreach ( $table_map as $source_table => $shadow_table ) {
			$source_table = (string) $source_table;
			$shadow_table = (string) $shadow_table;

			if ( 0 !== strpos( $source_table, $source_prefix ) ) {
				return $this->error( 'Source table prefix mismatch during promotion.' );
			}

			$suffix = substr( $source_table, strlen( $source_prefix ) );
			$live   = $target_prefix . $suffix;

			if (
				! preg_match( '/^[A-Za-z0-9_]+$/', $live ) ||
				! preg_match( '/^svstg_[A-Za-z0-9_]+$/', $shadow_table )
			) {
				return $this->error( 'Unsafe database identifier detected during promotion.' );
			}

			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $shadow_table ) )
			);

			if ( (string) $exists !== $shadow_table ) {
				return $this->error( 'Verified shadow table disappeared before cutover: ' . $shadow_table );
			}

			$promotion_map[ $shadow_table ] = $live;
			$rename_pairs[] = $this->quote_identifier( $shadow_table ) . ' TO ' . $this->quote_identifier( $live );
		}

		if ( empty( $rename_pairs ) ) {
			return $this->error( 'No database rename operations were generated.' );
		}

		$transaction['database_rollback_map']  = $rollback_map;
		$transaction['database_promotion_map'] = $promotion_map;
		$transaction['database_promotion_prepared'] = true;
		$transaction['updated_at'] = gmdate( 'c' );
		$this->checkpoint( $transaction );

		$result = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $rename_pairs ) );

		if ( false === $result ) {
			return $this->error( 'Atomic database promotion failed: ' . $wpdb->last_error );
		}

		return array(
			'success'       => true,
			'rollback_map'  => $rollback_map,
			'promotion_map' => $promotion_map,
		);
	}

	private function promote_filesystem( array $content_state, array &$transaction ): array {
		$shadow_root = (string) ( $content_state['staging_root'] ?? '' );
		$rollback    = (string) $transaction['rollback_root'];
		$failed      = (string) $transaction['failed_source_root'];
		$preserved   = (string) $transaction['plugin_preserve_root'];

		if ( '' === $shadow_root || ! is_dir( $shadow_root ) ) {
			return $this->error( 'Shadow wp-content directory is unavailable for promotion.' );
		}

		foreach ( array( $rollback, $failed ) as $dir ) {
			$reset = $this->reset_empty_directory( $dir );

			if ( ! $reset['success'] ) {
				return $reset;
			}
		}

		$source_plugin_stats = $this->directory_stats( trailingslashit( $shadow_root ) . 'plugins/wp-sitevault' );

		$live_children = $this->top_level_entries( WP_CONTENT_DIR, array( 'sitevault' ) );

		foreach ( $live_children as $name ) {
			$from = trailingslashit( WP_CONTENT_DIR ) . $name;
			$to   = trailingslashit( $rollback ) . $name;

			if ( ! @rename( $from, $to ) ) {
				return $this->error( 'Unable to move live wp-content entry into rollback storage: ' . $name );
			}

			$transaction['filesystem_live_moved'][] = $name;
			$this->checkpoint( $transaction );
		}

		$source_children = $this->top_level_entries( $shadow_root, array() );
		$source_moved    = array();

		foreach ( $source_children as $name ) {
			$from = trailingslashit( $shadow_root ) . $name;
			$to   = trailingslashit( WP_CONTENT_DIR ) . $name;

			if ( file_exists( $to ) || is_link( $to ) ) {
				return $this->error( 'Live destination unexpectedly exists during source promotion: ' . $name );
			}

			if ( ! @rename( $from, $to ) ) {
				return $this->error( 'Unable to promote staged wp-content entry: ' . $name );
			}

			$source_moved[] = $name;
			$transaction['filesystem_source_moved'] = $source_moved;
			$this->checkpoint( $transaction );
		}

		$live_plugin = WP_CONTENT_DIR . '/plugins/wp-sitevault';

		if ( file_exists( $live_plugin ) || is_link( $live_plugin ) ) {
			$remove = $this->remove_tree( $live_plugin );

			if ( ! $remove['success'] ) {
				return $remove;
			}
		}

		if ( ! is_dir( WP_CONTENT_DIR . '/plugins' ) && ! wp_mkdir_p( WP_CONTENT_DIR . '/plugins' ) ) {
			return $this->error( 'Unable to create live plugins directory while preserving SiteVault.' );
		}

		$copy = $this->copy_tree( $preserved, $live_plugin );

		if ( ! $copy['success'] ) {
			return $copy;
		}

		return array(
			'success'                       => true,
			'live_moved'                    => $transaction['filesystem_live_moved'],
			'source_moved'                  => $source_moved,
			'source_sitevault_plugin_files' => (int) ( $source_plugin_stats['files'] ?? 0 ),
			'source_sitevault_plugin_bytes' => (int) ( $source_plugin_stats['bytes'] ?? 0 ),
		);
	}

	private function verify_live_restore(
		array $restore_plan,
		array $database_state,
		array $content_state,
		int $source_plugin_files,
		int $source_plugin_bytes
	): array {
		global $wpdb;

		$source_prefix = (string) ( $restore_plan['source']['db_prefix'] ?? '' );
		$target_prefix = (string) ( $restore_plan['target']['db_prefix'] ?? $wpdb->prefix );
		$table_map     = is_array( $database_state['table_map'] ?? null ) ? $database_state['table_map'] : array();
		$rows          = 0;
		$tables        = 0;

		foreach ( array_keys( $table_map ) as $source_table ) {
			if ( 0 !== strpos( (string) $source_table, $source_prefix ) ) {
				return $this->error( 'Live database verification encountered an invalid source table mapping.' );
			}

			$suffix = substr( (string) $source_table, strlen( $source_prefix ) );
			$live   = $target_prefix . $suffix;
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $live ) )
			);

			if ( (string) $exists !== $live ) {
				return $this->error( 'Promoted live table is missing: ' . $live );
			}

			$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->quote_identifier( $live ) );

			if ( null === $count ) {
				return $this->error( 'Unable to count promoted live table rows: ' . $live );
			}

			$rows += (int) $count;
			$tables++;
		}

		if (
			$tables !== (int) ( $database_state['verified_tables'] ?? 0 ) ||
			$rows !== (int) ( $database_state['verified_rows'] ?? 0 )
		) {
			return $this->error( 'Promoted database table/row verification does not match shadow verification.' );
		}

		$options_table = $target_prefix . 'options';
		$home = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT option_value FROM ' . $this->quote_identifier( $options_table ) . ' WHERE option_name = %s LIMIT 1',
				'home'
			)
		);
		$siteurl = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT option_value FROM ' . $this->quote_identifier( $options_table ) . ' WHERE option_name = %s LIMIT 1',
				'siteurl'
			)
		);

		$expected_home = untrailingslashit( (string) ( $restore_plan['target']['home_url'] ?? '' ) );
		$expected_site = untrailingslashit( (string) ( $restore_plan['target']['site_url'] ?? $expected_home ) );

		if (
			$expected_home !== untrailingslashit( (string) $home ) ||
			$expected_site !== untrailingslashit( (string) $siteurl )
		) {
			return $this->error(
				'Promoted database URL verification failed. Expected target home/site URLs were not found.'
			);
		}

		$live_stats = $this->managed_live_content_stats();
		$expected_files = max( 0, (int) ( $content_state['verified_files'] ?? 0 ) - $source_plugin_files );
		$expected_bytes = max( 0, (int) ( $content_state['verified_bytes'] ?? 0 ) - $source_plugin_bytes );

		if (
			$live_stats['files'] !== $expected_files ||
			$live_stats['bytes'] !== $expected_bytes
		) {
			return $this->error(
				'Promoted wp-content verification failed. Managed source files/bytes do not match the staged source.'
			);
		}

		return array(
			'success'              => true,
			'database_tables'      => $tables,
			'database_rows'        => $rows,
			'home_url'             => (string) $home,
			'site_url'             => (string) $siteurl,
			'managed_files'        => $live_stats['files'],
			'managed_bytes'        => $live_stats['bytes'],
			'preserved_sitevault'  => true,
		);
	}

	private function rollback( array $transaction ): array {
		$messages = array();
		$success  = true;

		if ( ! empty( $transaction['filesystem_live_moved'] ) || ! empty( $transaction['filesystem_source_moved'] ) ) {
			$fs = $this->rollback_filesystem( $transaction );

			if ( ! $fs['success'] ) {
				$success = false;
				$messages[] = $fs['message'];
			}
		}

		if ( ! empty( $transaction['database_promoted'] ) ) {
			$db = $this->rollback_database(
				$transaction['database_rollback_map'] ?? array(),
				$transaction['database_promotion_map'] ?? array()
			);

			if ( ! $db['success'] ) {
				$success = false;
				$messages[] = $db['message'];
			}
		}

		return array(
			'success' => $success,
			'message' => $success
				? 'Automatic rollback completed.'
				: implode( ' ', $messages ),
		);
	}

	private function rollback_database( array $rollback_map, array $promotion_map ): array {
		global $wpdb;

		$pairs = array();

		foreach ( $promotion_map as $shadow => $live ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $live ) )
			);

			if ( (string) $exists === (string) $live ) {
				$pairs[] = $this->quote_identifier( (string) $live ) . ' TO ' . $this->quote_identifier( (string) $shadow );
			}
		}

		foreach ( $rollback_map as $live => $backup ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $backup ) )
			);

			if ( (string) $exists === (string) $backup ) {
				$pairs[] = $this->quote_identifier( (string) $backup ) . ' TO ' . $this->quote_identifier( (string) $live );
			}
		}

		if ( empty( $pairs ) ) {
			return array( 'success' => true, 'message' => 'No database rollback rename was required.' );
		}

		$result = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) );

		if ( false === $result ) {
			return $this->error( 'Automatic database rollback failed: ' . $wpdb->last_error );
		}

		return array( 'success' => true, 'message' => 'Database rollback completed.' );
	}

	private function rollback_filesystem( array $transaction ): array {
		$rollback = (string) $transaction['rollback_root'];
		$failed   = (string) $transaction['failed_source_root'];

		if ( ! is_dir( $failed ) && ! wp_mkdir_p( $failed ) ) {
			return $this->error( 'Unable to create failed-source quarantine for filesystem rollback.' );
		}

		foreach ( $this->top_level_entries( WP_CONTENT_DIR, array( 'sitevault' ) ) as $name ) {
			$from = trailingslashit( WP_CONTENT_DIR ) . $name;
			$to   = trailingslashit( $failed ) . $name;

			if ( file_exists( $to ) || is_link( $to ) ) {
				$remove = $this->remove_tree( $to );

				if ( ! $remove['success'] ) {
					return $remove;
				}
			}

			if ( ! @rename( $from, $to ) ) {
				return $this->error( 'Unable to quarantine promoted source entry during rollback: ' . $name );
			}
		}

		foreach ( $this->top_level_entries( $rollback, array() ) as $name ) {
			$from = trailingslashit( $rollback ) . $name;
			$to   = trailingslashit( WP_CONTENT_DIR ) . $name;

			if ( file_exists( $to ) || is_link( $to ) ) {
				return $this->error( 'Rollback destination unexpectedly exists: ' . $name );
			}

			if ( ! @rename( $from, $to ) ) {
				return $this->error( 'Unable to restore original target wp-content entry: ' . $name );
			}
		}

		return array( 'success' => true, 'message' => 'Filesystem rollback completed.' );
	}

	private function preserve_current_plugin( string $destination ): array {
		$reset = $this->reset_empty_directory( $destination );

		if ( ! $reset['success'] ) {
			return $reset;
		}

		return $this->copy_tree( untrailingslashit( SITEVAULT_PATH ), $destination );
	}

	private function enter_maintenance_lock( array $transaction ): void {
		$lock = array(
			'plan_id'    => $transaction['plan_id'],
			'started_at' => gmdate( 'c' ),
			'message'    => 'SiteVault restore is in progress. Please try again shortly.',
		);

		$file = WP_CONTENT_DIR . '/sitevault/' . self::LOCK_FILE;

		if ( false === file_put_contents( $file, wp_json_encode( $lock, JSON_PRETTY_PRINT ), LOCK_EX ) ) {
			throw new RuntimeException( 'Unable to create SiteVault cutover lock.' );
		}
	}

	private function leave_maintenance_lock(): void {
		$file = WP_CONTENT_DIR . '/sitevault/' . self::LOCK_FILE;

		if ( is_file( $file ) ) {
			@unlink( $file );
		}
	}

	private function reset_runtime_options_after_success(): void {
		$options = array(
			'sitevault_active_backup_id',
			'sitevault_last_import_validation',
			'sitevault_last_restore_plan',
			'sitevault_restore_safety_state',
			'sitevault_database_staging_state',
			'sitevault_content_staging_state',
			'sitevault_cutover_readiness_state',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		update_option( 'sitevault_version', SITEVAULT_VERSION, false );
	}

	private function managed_live_content_stats(): array {
		$files = 0;
		$bytes = 0;
		$root  = wp_normalize_path( WP_CONTENT_DIR );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}

			$path = wp_normalize_path( $file->getPathname() );
			$rel  = ltrim( substr( $path, strlen( $root ) ), '/' );

			if (
				'sitevault/' === substr( $rel, 0, 10 ) ||
				'plugins/wp-sitevault/' === substr( $rel, 0, 21 )
			) {
				continue;
			}

			$files++;
			$bytes += (int) $file->getSize();
		}

		return array( 'files' => $files, 'bytes' => $bytes );
	}

	private function directory_stats( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return array( 'files' => 0, 'bytes' => 0 );
		}

		$files = 0;
		$bytes = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && ! $file->isLink() ) {
				$files++;
				$bytes += (int) $file->getSize();
			}
		}

		return array( 'files' => $files, 'bytes' => $bytes );
	}

	private function top_level_entries( string $root, array $exclude ): array {
		$items = scandir( $root );

		if ( false === $items ) {
			return array();
		}

		$out = array();

		foreach ( $items as $name ) {
			if ( '.' === $name || '..' === $name || in_array( $name, $exclude, true ) ) {
				continue;
			}

			$out[] = $name;
		}

		sort( $out, SORT_STRING );

		return $out;
	}

	private function reset_empty_directory( string $root ): array {
		$allowed = trailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/sitevault/' ) );
		$path    = trailingslashit( wp_normalize_path( $root ) );

		if ( 0 !== strpos( $path, $allowed ) ) {
			return $this->error( 'Refusing to reset a directory outside SiteVault runtime storage.' );
		}

		if ( file_exists( $root ) || is_link( $root ) ) {
			$remove = $this->remove_tree( $root );

			if ( ! $remove['success'] ) {
				return $remove;
			}
		}

		if ( ! wp_mkdir_p( $root ) ) {
			return $this->error( 'Unable to create SiteVault transaction directory.' );
		}

		return array( 'success' => true );
	}

	private function copy_tree( string $source, string $destination ): array {
		if ( ! is_dir( $source ) ) {
			return $this->error( 'Directory copy source is unavailable: ' . $source );
		}

		if ( ! is_dir( $destination ) && ! wp_mkdir_p( $destination ) ) {
			return $this->error( 'Unable to create directory copy destination.' );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$source_norm = untrailingslashit( wp_normalize_path( $source ) );

		foreach ( $iterator as $item ) {
			$item_path = wp_normalize_path( $item->getPathname() );
			$relative  = ltrim( substr( $item_path, strlen( $source_norm ) ), '/' );
			$target    = trailingslashit( $destination ) . $relative;

			if ( $item->isLink() ) {
				return $this->error( 'Symlinks are not copied while preserving the active SiteVault plugin.' );
			}

			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
					return $this->error( 'Unable to create preserved SiteVault plugin directory.' );
				}
				continue;
			}

			if ( ! @copy( $item->getPathname(), $target ) ) {
				return $this->error( 'Unable to preserve SiteVault plugin file: ' . $relative );
			}
		}

		return array( 'success' => true );
	}

	private function remove_tree( string $path ): array {
		if ( is_link( $path ) || is_file( $path ) ) {
			return @unlink( $path )
				? array( 'success' => true )
				: $this->error( 'Unable to remove file during controlled cutover: ' . $path );
		}

		if ( ! is_dir( $path ) ) {
			return array( 'success' => true );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isLink() || $item->isFile() ) {
				if ( ! @unlink( $item->getPathname() ) ) {
					return $this->error( 'Unable to remove cutover file: ' . $item->getPathname() );
				}
			} elseif ( ! @rmdir( $item->getPathname() ) ) {
				return $this->error( 'Unable to remove cutover directory: ' . $item->getPathname() );
			}
		}

		if ( ! @rmdir( $path ) ) {
			return $this->error( 'Unable to remove cutover directory: ' . $path );
		}

		return array( 'success' => true );
	}

	private function checkpoint( array $transaction ): void {
		$this->active_transaction = $transaction;
		$this->save_transaction( $transaction );
	}

	private function save_transaction( array $transaction ): void {
		$plan_id = sanitize_key( (string) ( $transaction['plan_id'] ?? '' ) );

		if ( '' === $plan_id ) {
			return;
		}

		$root = WP_CONTENT_DIR . '/sitevault/cutover/' . $plan_id;

		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}

		file_put_contents(
			$root . '/transaction.json',
			wp_json_encode( $transaction, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		);
	}

	private function quote_identifier( string $identifier ): string {
		return chr( 96 ) . str_replace( chr( 96 ), chr( 96 ) . chr( 96 ), $identifier ) . chr( 96 );
	}

	private function error( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
