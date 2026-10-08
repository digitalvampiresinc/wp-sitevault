<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Cutover_Readiness {

	private const OPTION = 'sitevault_cutover_readiness_state';

	public function seal(
		array $restore_plan,
		array $safety_state,
		array $database_state,
		array $content_state
	): array {
		global $wpdb;

		$plan_id = sanitize_key( (string) ( $restore_plan['plan_id'] ?? '' ) );

		if ( '' === $plan_id || 'ready' !== ( $restore_plan['status'] ?? '' ) ) {
			return $this->error( 'Restore plan is not ready for cutover sealing.' );
		}

		if (
			'complete' !== ( $safety_state['status'] ?? '' ) ||
			'safety_ready' !== ( $safety_state['staging']['status'] ?? '' ) ||
			( $safety_state['plan_id'] ?? '' ) !== $plan_id ||
			empty( $safety_state['package']['verified'] )
		) {
			return $this->error( 'Matching verified target safety snapshot is required.' );
		}

		if (
			'verified' !== ( $database_state['status'] ?? '' ) ||
			( $database_state['plan_id'] ?? '' ) !== $plan_id ||
			! empty( $database_state['live_tables_modified'] ) ||
			empty( $database_state['ready_for_live_promotion'] )
		) {
			return $this->error( 'Matching verified shadow database is not ready for promotion.' );
		}

		if (
			'verified' !== ( $content_state['status'] ?? '' ) ||
			( $content_state['plan_id'] ?? '' ) !== $plan_id ||
			! empty( $content_state['live_files_modified'] ) ||
			empty( $content_state['ready_for_promotion'] )
		) {
			return $this->error( 'Matching verified shadow wp-content is not ready for promotion.' );
		}

		$staging = is_array( $safety_state['staging'] ?? null ) ? $safety_state['staging'] : array();
		$plan_json = wp_json_encode( $restore_plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$plan_hash = hash( 'sha256', (string) $plan_json );

		if (
			empty( $staging['plan_sha256'] ) ||
			! hash_equals( strtolower( (string) $staging['plan_sha256'] ), strtolower( $plan_hash ) )
		) {
			return $this->error( 'Restore plan changed after the safety staging seal was created.' );
		}

		$safety_package = (string) ( $safety_state['package']['package_file'] ?? '' );
		$safety_hash    = (string) ( $safety_state['package']['package_sha256'] ?? '' );

		if ( ! is_readable( $safety_package ) || '' === $safety_hash ) {
			return $this->error( 'Verified safety rollback package is no longer available.' );
		}

		$actual_safety_hash = hash_file( 'sha256', $safety_package );

		if ( false === $actual_safety_hash || ! hash_equals( strtolower( $safety_hash ), strtolower( $actual_safety_hash ) ) ) {
			return $this->error( 'Safety rollback package fingerprint changed after verification.' );
		}

		$database_check = $this->verify_shadow_database( $database_state );

		if ( ! $database_check['success'] ) {
			return $database_check;
		}

		$placeholder_check = $this->verify_no_placeholder_escapes( $database_state );
		if ( ! $placeholder_check['success'] ) {
			return $placeholder_check;
		}

		$content_check = $this->verify_shadow_content( $content_state );

		if ( ! $content_check['success'] ) {
			return $content_check;
		}

		$source_parts = array_values( array_filter(
			(array) ( $restore_plan['content']['parts'] ?? array() ),
			static fn( $part ) => is_string( $part ) && '' !== $part
		) );

		if ( empty( $source_parts ) ) {
			$source_parts[] = 'content/wp-content.zip';
		}

		$source_part_hashes = array();

		foreach ( $source_parts as $logical_part ) {
			$source_file = WP_CONTENT_DIR . '/sitevault/restore-plans/' . $plan_id . '/payload/' . ltrim( $logical_part, '/' );

			if ( ! is_readable( $source_file ) || ! is_file( $source_file ) ) {
				return $this->error( 'Restore source wp-content archive part is unavailable: ' . $logical_part );
			}

			$part_hash = hash_file( 'sha256', $source_file );

			if ( false === $part_hash ) {
				return $this->error( 'Unable to fingerprint restore source wp-content archive part: ' . $logical_part );
			}

			$source_part_hashes[ $logical_part ] = strtolower( $part_hash );
		}

		$source_content_hash = hash(
			'sha256',
			wp_json_encode( $source_part_hashes, JSON_UNESCAPED_SLASHES )
		);

		$state = array(
			'status'                    => 'cutover_ready',
			'sealed_at'                 => gmdate( 'c' ),
			'plan_id'                   => $plan_id,
			'plan_sha256'               => $plan_hash,
			'source_backup_id'          => $restore_plan['backup_id'] ?? '',
			'restore_mode'              => $restore_plan['mode'] ?? '',
			'source_home_url'           => $restore_plan['source']['home_url'] ?? '',
			'target_home_url'           => $restore_plan['target']['home_url'] ?? '',
			'safety_snapshot_id'        => $safety_state['snapshot_backup_id'] ?? '',
			'safety_package_sha256'     => $actual_safety_hash,
			'shadow_database_prefix'    => $database_state['staging_prefix'] ?? '',
			'shadow_database_tables'    => (int) ( $database_check['tables'] ?? 0 ),
			'shadow_database_rows'      => (int) ( $database_check['rows'] ?? 0 ),
			'shadow_content_root'       => $content_state['staging_root'] ?? '',
			'shadow_content_files'      => (int) ( $content_check['files'] ?? 0 ),
			'shadow_content_bytes'      => (int) ( $content_check['bytes'] ?? 0 ),
			'source_content_sha256'     => $source_content_hash,
			'source_content_parts'      => $source_part_hashes,
			'url_replacement_required'  => (bool) ( $restore_plan['changes']['url_replacement_required'] ?? false ),
			'prefix_remap_required'     => (bool) ( $restore_plan['changes']['prefix_remap_required'] ?? false ),
			'path_replacement_required' => (bool) ( $restore_plan['changes']['path_replacement_required'] ?? false ),
			'live_tables_modified'      => false,
			'live_files_modified'       => false,
			'destructive_actions_taken' => false,
			'execution_locked'          => true,
			'next_stage'                => 'controlled-live-cutover',
		);

		$seal_root = WP_CONTENT_DIR . '/sitevault/restore-staging/' . $plan_id;

		if ( ! wp_mkdir_p( $seal_root ) ) {
			return $this->error( 'Unable to access controlled restore staging directory.' );
		}

		if ( false === file_put_contents(
			$seal_root . '/cutover-readiness.json',
			wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		) ) {
			return $this->error( 'Cutover readiness checks passed, but the readiness seal could not be saved.' );
		}

		update_option( self::OPTION, $state, false );

		return array(
			'success' => true,
			'state'   => $state,
		);
	}

	public function get_state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private function verify_shadow_database( array $state ): array {
		global $wpdb;

		$tables = is_array( $state['table_map'] ?? null ) ? array_values( $state['table_map'] ) : array();

		if ( empty( $tables ) ) {
			return $this->error( 'Shadow database table map is empty.' );
		}

		$rows = 0;

		foreach ( $tables as $table ) {
			if ( ! preg_match( '/^svstg_[A-Za-z0-9_]+$/', (string) $table ) ) {
				return $this->error( 'Unexpected shadow table identifier detected.' );
			}

			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $table ) )
			);

			if ( (string) $exists !== (string) $table ) {
				return $this->error( 'A verified shadow database table is now missing: ' . $table );
			}

			$table_sql = $this->quote_identifier( (string) $table );
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_sql}" );

			if ( null === $count ) {
				return $this->error( 'Unable to recount shadow database rows for cutover readiness.' );
			}

			$rows += (int) $count;
		}

		if (
			count( $tables ) !== (int) ( $state['verified_tables'] ?? 0 ) ||
			$rows !== (int) ( $state['verified_rows'] ?? 0 )
		) {
			return $this->error( 'Shadow database changed after verification.' );
		}

		return array(
			'success' => true,
			'tables'  => count( $tables ),
			'rows'    => $rows,
		);
	}

	private function verify_no_placeholder_escapes( array $state ): array {
		global $wpdb;

		$tables = is_array( $state['table_map'] ?? null ) ? array_values( $state['table_map'] ) : array();

		foreach ( $tables as $table ) {
			if ( ! preg_match( '/^svstg_[A-Za-z0-9_]+$/', (string) $table ) ) {
				return $this->error( 'Unexpected shadow table identifier during placeholder readiness check.' );
			}

			$table_sql = $this->quote_identifier( (string) $table );
			$columns   = $wpdb->get_results( "SHOW COLUMNS FROM {$table_sql}", ARRAY_A );

			if ( ! is_array( $columns ) ) {
				return $this->error( 'Unable to inspect shadow database for placeholder readiness.' );
			}

			foreach ( $columns as $column ) {
				$type = strtolower( (string) ( $column['Type'] ?? '' ) );
				$name = (string) ( $column['Field'] ?? '' );

				if ( '' === $name || ! preg_match( '/(?:char|text|blob|json|enum|set)/', $type ) ) {
					continue;
				}

				$column_sql = $this->quote_identifier( $name );
				$count = $wpdb->get_var(
					"SELECT COUNT(*) FROM {$table_sql} WHERE {$column_sql} REGEXP '\\\\{[0-9A-Fa-f]{64}\\\\}'"
				);

				if ( null === $count ) {
					return $this->error( 'Unable to complete shadow placeholder readiness scan.' );
				}

				if ( (int) $count > 0 ) {
					return $this->error( 'Cutover blocked: unsafe WordPress percent placeholder escapes remain in the shadow database.' );
				}
			}
		}

		return array( 'success' => true );
	}

	private function verify_shadow_content( array $state ): array {
		$root = isset( $state['staging_root'] ) ? (string) $state['staging_root'] : '';

		if ( '' === $root || ! is_dir( $root ) ) {
			return $this->error( 'Verified shadow wp-content directory is unavailable.' );
		}

		$normalized_root = trailingslashit( wp_normalize_path( $root ) );
		$allowed_root    = trailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/sitevault/restore-staging/' ) );

		if ( 0 !== strpos( $normalized_root, $allowed_root ) ) {
			return $this->error( 'Shadow wp-content directory is outside controlled restore staging.' );
		}

		$files = 0;
		$bytes = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}

			$path = wp_normalize_path( $file->getPathname() );

			if ( 0 !== strpos( $path, $normalized_root ) ) {
				return $this->error( 'Shadow file escaped controlled restore staging.' );
			}

			$files++;
			$bytes += (int) $file->getSize();
		}

		if (
			$files !== (int) ( $state['verified_files'] ?? 0 ) ||
			$bytes !== (int) ( $state['verified_bytes'] ?? 0 )
		) {
			return $this->error( 'Shadow wp-content changed after verification.' );
		}

		return array(
			'success' => true,
			'files'   => $files,
			'bytes'   => $bytes,
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
