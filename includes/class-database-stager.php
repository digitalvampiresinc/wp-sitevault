<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Database_Stager {

	private const OPTION = 'sitevault_database_staging_state';
	private const STATEMENTS_PER_BATCH = 12;

	public function start( array $restore_plan, array $safety_state ): array {
		global $wpdb;

		if ( 'ready' !== ( $restore_plan['status'] ?? '' ) ) {
			return $this->error( 'Restore plan is not ready for database staging.' );
		}

		if (
			'complete' !== ( $safety_state['status'] ?? '' ) ||
			'safety_ready' !== ( $safety_state['staging']['status'] ?? '' ) ||
			empty( $safety_state['package']['verified'] )
		) {
			return $this->error( 'A verified pre-restore safety snapshot is required before database staging.' );
		}

		if ( ( $safety_state['plan_id'] ?? '' ) !== ( $restore_plan['plan_id'] ?? '' ) ) {
			return $this->error( 'Safety snapshot does not belong to the current restore plan.' );
		}

		$plan_id = sanitize_key( (string) ( $restore_plan['plan_id'] ?? '' ) );
		$sql_file = WP_CONTENT_DIR . '/sitevault/restore-plans/' . $plan_id . '/payload/database/database.sql';

		if ( ! is_readable( $sql_file ) ) {
			return $this->error( 'Restore-plan database SQL file is unavailable.' );
		}

		$expected_tables = $restore_plan['database']['sql_tables'] ?? array();

		if ( ! is_array( $expected_tables ) || empty( $expected_tables ) ) {
			return $this->error( 'Restore plan does not contain the expected database table list.' );
		}

		$source_prefix = (string) ( $restore_plan['source']['db_prefix'] ?? '' );

		if ( '' === $source_prefix ) {
			return $this->error( 'Source database prefix is missing from the restore plan.' );
		}

		$existing = $this->get_state();

		if (
			is_array( $existing ) &&
			in_array( $existing['status'] ?? '', array( 'running', 'imported', 'transformed', 'verified' ), true ) &&
			( $existing['plan_id'] ?? '' ) === $plan_id
		) {
			return array( 'success' => true, 'state' => $existing );
		}

		$token = substr( hash( 'sha256', $plan_id . '|' . ( $safety_state['snapshot_backup_id'] ?? '' ) ), 0, 10 );
		$staging_prefix = 'svstg_' . $token . '_';
		$table_map = array();

		foreach ( $expected_tables as $source_table ) {
			$source_table = (string) $source_table;

			if ( 0 !== strpos( $source_table, $source_prefix ) ) {
				return $this->error( 'Unexpected source table outside source prefix: ' . $source_table );
			}

			$suffix = substr( $source_table, strlen( $source_prefix ) );
			$staging_table = $staging_prefix . $suffix;

			if ( strlen( $staging_table ) > 64 || ! preg_match( '/^[A-Za-z0-9_]+$/', $staging_table ) ) {
				return $this->error( 'A generated staging table name is invalid or too long: ' . $staging_table );
			}

			$table_map[ $source_table ] = $staging_table;
		}

		$this->drop_staging_tables( array_values( $table_map ) );

		$state = array(
			'status'             => 'running',
			'stage'              => 'import',
			'plan_id'            => $plan_id,
			'started_at'         => gmdate( 'c' ),
			'updated_at'         => gmdate( 'c' ),
			'completed_at'       => null,
			'sql_file'           => $sql_file,
			'sql_offset'         => 0,
			'parser_state'       => $this->empty_parser_state(),
			'source_prefix'      => $source_prefix,
			'target_prefix'      => (string) ( $restore_plan['target']['db_prefix'] ?? $wpdb->prefix ),
			'staging_prefix'     => $staging_prefix,
			'table_map'          => $table_map,
			'expected_tables'    => count( $table_map ),
			'manifest_rows'      => (int) ( $restore_plan['database']['manifest_rows'] ?? 0 ),
			'statements_executed'=> 0,
			'inserted_rows'      => 0,
			'tables_created'     => 0,
			'verified_tables'    => 0,
			'verified_rows'      => 0,
			'transform_required' => ! empty( $restore_plan['changes']['url_replacement_required'] ) ||
				! empty( $restore_plan['changes']['site_url_change_required'] ) ||
				! empty( $restore_plan['changes']['path_replacement_required'] ),
			'transforms'         => array(
				'url'  => 0,
				'path' => 0,
			),
			'error'              => null,
		);

		$this->save_state( $state );

		return array( 'success' => true, 'state' => $state );
	}

	public function process_import_batch(): array {
		global $wpdb;

		$state = $this->get_state();

		if ( 'running' !== ( $state['status'] ?? '' ) || 'import' !== ( $state['stage'] ?? '' ) ) {
			return $this->error( 'No database staging import is ready to process.', $state );
		}

		$handle = fopen( $state['sql_file'], 'rb' );

		if ( false === $handle ) {
			return $this->fail( $state, 'Unable to open staged database SQL file.' );
		}

		if ( 0 !== fseek( $handle, (int) $state['sql_offset'] ) ) {
			fclose( $handle );
			return $this->fail( $state, 'Unable to resume database staging SQL position.' );
		}

		$parser = is_array( $state['parser_state'] ?? null ) ? $state['parser_state'] : $this->empty_parser_state();
		$executed = 0;
		$eof = false;

		while ( $executed < self::STATEMENTS_PER_BATCH ) {
			$statement = $this->read_next_statement( $handle, $parser );

			if ( null === $statement ) {
				$eof = true;
				break;
			}

			$trimmed = trim( $statement );

			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) ) {
				continue;
			}

			if (
				0 === stripos( $trimmed, 'SET SQL_MODE' ) ||
				0 === stripos( $trimmed, 'SET FOREIGN_KEY_CHECKS' ) ||
				0 === stripos( $trimmed, 'SET UNIQUE_CHECKS' )
			) {
				continue;
			}

			$transformed = $this->rewrite_table_identifiers( $statement, $state['table_map'] );

			if ( false === $transformed ) {
				fclose( $handle );
				return $this->fail( $state, 'Database staging encountered an unexpected live/source table identifier.' );
			}

			$query_result = $wpdb->query( $transformed );

			if ( false === $query_result ) {
				fclose( $handle );
				return $this->fail( $state, 'Database staging SQL failed: ' . $wpdb->last_error );
			}

			$executed++;
			$state['statements_executed']++;

			if ( preg_match( '/^CREATE\s+TABLE/i', ltrim( $transformed ) ) ) {
				$state['tables_created']++;
			}

			if ( preg_match( '/^INSERT\s+INTO/i', ltrim( $transformed ) ) ) {
				$state['inserted_rows'] += max( 0, (int) $wpdb->rows_affected );
			}
		}

		$state['sql_offset']   = ftell( $handle );
		$state['parser_state'] = $parser;
		$state['updated_at']   = gmdate( 'c' );
		fclose( $handle );

		if ( $eof ) {
			$state['stage']  = 'verify_import';
			$state['status'] = 'imported';
		}

		$this->save_state( $state );

		return array( 'success' => true, 'state' => $state );
	}

	public function verify_import(): array {
		global $wpdb;

		$state = $this->get_state();

		if ( 'imported' !== ( $state['status'] ?? '' ) || 'verify_import' !== ( $state['stage'] ?? '' ) ) {
			return $this->error( 'Database staging import is not ready for verification.', $state );
		}

		$total_rows = 0;
		$verified_tables = 0;

		foreach ( $state['table_map'] as $source => $table ) {
			$table_sql = $this->quote_identifier( (string) $table );
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $table ) )
			);

			if ( (string) $exists !== (string) $table ) {
				return $this->fail( $state, 'Expected staging table is missing: ' . $table );
			}

			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_sql}" );

			if ( null === $count ) {
				return $this->fail( $state, 'Unable to count rows in staging table: ' . $table );
			}

			$total_rows += (int) $count;
			$verified_tables++;
		}

		if ( $verified_tables !== (int) $state['expected_tables'] ) {
			return $this->fail( $state, 'Staging table count does not match the restore plan.' );
		}

		if ( $total_rows !== (int) $state['manifest_rows'] ) {
			return $this->fail(
				$state,
				'Staging row total does not match the backup manifest. Expected ' .
				(int) $state['manifest_rows'] . ', found ' . $total_rows . '.'
			);
		}

		$state['verified_tables'] = $verified_tables;
		$state['verified_rows']   = $total_rows;
		$state['status']          = $state['transform_required'] ? 'imported' : 'verified';
		$state['stage']           = $state['transform_required'] ? 'transform' : 'complete';
		$state['completed_at']    = $state['transform_required'] ? null : gmdate( 'c' );
		$state['updated_at']      = gmdate( 'c' );

		$this->save_state( $state );

		return array( 'success' => true, 'state' => $state );
	}

	public function get_state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private function read_next_statement( $handle, array &$parser ): ?string {
		$statement = (string) ( $parser['buffer'] ?? '' );
		$in_single = (bool) ( $parser['in_single'] ?? false );
		$in_double = (bool) ( $parser['in_double'] ?? false );
		$in_backtick = (bool) ( $parser['in_backtick'] ?? false );
		$escaped = (bool) ( $parser['escaped'] ?? false );
		$line_comment = false;

		while ( ! feof( $handle ) ) {
			$char = fgetc( $handle );

			if ( false === $char ) {
				break;
			}

			$statement .= $char;

			if ( $line_comment ) {
				if ( "\n" === $char ) {
					$line_comment = false;
				}
				continue;
			}

			if ( $escaped ) {
				$escaped = false;
				continue;
			}

			if ( '\\' === $char && ( $in_single || $in_double ) ) {
				$escaped = true;
				continue;
			}

			if ( ! $in_single && ! $in_double && ! $in_backtick && '-' === $char ) {
				$pos = strlen( $statement );
				if ( $pos >= 2 && '-' === $statement[ $pos - 2 ] ) {
					$line_comment = true;
					continue;
				}
			}

			if ( "'" === $char && ! $in_double && ! $in_backtick ) {
				$in_single = ! $in_single;
				continue;
			}

			if ( '"' === $char && ! $in_single && ! $in_backtick ) {
				$in_double = ! $in_double;
				continue;
			}

			if ( chr( 96 ) === $char && ! $in_single && ! $in_double ) {
				$in_backtick = ! $in_backtick;
				continue;
			}

			if ( ';' === $char && ! $in_single && ! $in_double && ! $in_backtick ) {
				$parser = $this->empty_parser_state();
				return $statement;
			}
		}

		$parser = array(
			'buffer'      => $statement,
			'in_single'   => $in_single,
			'in_double'   => $in_double,
			'in_backtick' => $in_backtick,
			'escaped'     => $escaped,
		);

		if ( '' !== trim( $statement ) ) {
			$parser = $this->empty_parser_state();
			return $statement;
		}

		return null;
	}

	private function rewrite_table_identifiers( string $statement, array $table_map ) {
		$rewritten = $statement;

		foreach ( $table_map as $source => $staging ) {
			$source_q  = $this->quote_identifier( (string) $source );
			$staging_q = $this->quote_identifier( (string) $staging );
			$rewritten = str_replace( $source_q, $staging_q, $rewritten );
		}

		foreach ( array_keys( $table_map ) as $source ) {
			if ( false !== strpos( $rewritten, $this->quote_identifier( (string) $source ) ) ) {
				return false;
			}
		}

		return $rewritten;
	}

	private function drop_staging_tables( array $tables ): void {
		global $wpdb;

		foreach ( $tables as $table ) {
			if ( ! preg_match( '/^svstg_[A-Za-z0-9_]+$/', (string) $table ) ) {
				continue;
			}

			$table_sql = $this->quote_identifier( (string) $table );
			$wpdb->query( "DROP TABLE IF EXISTS {$table_sql}" );
		}
	}

	private function empty_parser_state(): array {
		return array(
			'buffer'      => '',
			'in_single'   => false,
			'in_double'   => false,
			'in_backtick' => false,
			'escaped'     => false,
		);
	}

	private function quote_identifier( string $identifier ): string {
		return chr( 96 ) . str_replace( chr( 96 ), chr( 96 ) . chr( 96 ), $identifier ) . chr( 96 );
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
