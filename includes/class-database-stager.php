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

	public function process_transform_batch( array $restore_plan ): array {
		global $wpdb;

		$state = $this->get_state();

		if ( 'imported' !== ( $state['status'] ?? '' ) || 'transform' !== ( $state['stage'] ?? '' ) ) {
			return $this->error( 'Database staging transform is not ready to process.', $state );
		}

		if ( empty( $state['transform_state'] ) || ! is_array( $state['transform_state'] ) ) {
			$state['transform_state'] = array(
				'table_index'       => 0,
				'row_offset'        => 0,
				'rows_scanned'      => 0,
				'rows_changed'      => 0,
				'cells_changed'     => 0,
				'replacements'      => 0,
			);
		}

		$tables = array_values( $state['table_map'] );
		$index  = (int) $state['transform_state']['table_index'];

		if ( ! isset( $tables[ $index ] ) ) {
			$state['status']       = 'transformed';
			$state['stage']        = 'verify_transform';
			$state['updated_at']   = gmdate( 'c' );
			$this->save_state( $state );
			return array( 'success' => true, 'state' => $state );
		}

		$table = (string) $tables[ $index ];
		$meta  = $this->table_transform_meta( $table );

		if ( ! $meta['success'] ) {
			return $this->fail( $state, $meta['message'] );
		}

		if ( empty( $meta['text_columns'] ) ) {
			$state['transform_state']['table_index']++;
			$state['transform_state']['row_offset'] = 0;
			$state['updated_at'] = gmdate( 'c' );
			$this->save_state( $state );
			return array( 'success' => true, 'state' => $state );
		}

		if ( empty( $meta['key_columns'] ) ) {
			return $this->fail( $state, 'Cannot safely transform staged table without a primary or unique key: ' . $table );
		}

		$select_columns = array_values( array_unique( array_merge( $meta['key_columns'], $meta['text_columns'] ) ) );
		$table_sql      = $this->quote_identifier( $table );
		$columns_sql    = implode( ', ', array_map( array( $this, 'quote_identifier' ), $select_columns ) );
		$order_sql      = implode( ', ', array_map( array( $this, 'quote_identifier' ), $meta['key_columns'] ) );
		$offset         = max( 0, (int) $state['transform_state']['row_offset'] );
		$limit          = 100;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$columns_sql} FROM {$table_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $this->fail( $state, 'Unable to read staged rows for migration transform: ' . $table );
		}

		if ( empty( $rows ) ) {
			$state['transform_state']['table_index']++;
			$state['transform_state']['row_offset'] = 0;
			$state['updated_at'] = gmdate( 'c' );
			$this->save_state( $state );
			return array( 'success' => true, 'state' => $state );
		}

		$pairs = $this->replacement_pairs( $restore_plan );
		$rows_changed = 0;

		foreach ( $rows as $row ) {
			$data  = array();
			$where = array();

			foreach ( $meta['key_columns'] as $key ) {
				$where[ $key ] = $row[ $key ];
			}

			foreach ( $meta['text_columns'] as $column ) {
				if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] ) {
					continue;
				}

				$transformed = $this->transform_value( (string) $row[ $column ], $pairs, 0 );

				if ( ! $transformed['success'] ) {
					return $this->fail( $state, $transformed['message'] . ' Table: ' . $table . ', column: ' . $column );
				}

				if ( $transformed['value'] !== (string) $row[ $column ] ) {
					$data[ $column ] = $transformed['value'];
					$state['transform_state']['cells_changed']++;
					$state['transform_state']['replacements'] += (int) $transformed['replacements'];
				}
			}

			if ( $data ) {
				$updated = $wpdb->update( $table, $data, $where );

				if ( false === $updated ) {
					return $this->fail( $state, 'Unable to update staged migration data: ' . $wpdb->last_error );
				}

				$rows_changed++;
			}
		}

		$count = count( $rows );
		$state['transform_state']['rows_scanned'] += $count;
		$state['transform_state']['rows_changed'] += $rows_changed;
		$state['transform_state']['row_offset'] += $count;
		$state['updated_at'] = gmdate( 'c' );

		if ( $count < $limit ) {
			$state['transform_state']['table_index']++;
			$state['transform_state']['row_offset'] = 0;
		}

		$this->save_state( $state );

		return array( 'success' => true, 'state' => $state );
	}

	public function verify_transform( array $restore_plan ): array {
		global $wpdb;

		$state = $this->get_state();

		if ( 'transformed' !== ( $state['status'] ?? '' ) || 'verify_transform' !== ( $state['stage'] ?? '' ) ) {
			return $this->error( 'Database staging transform is not ready for verification.', $state );
		}

		$total_rows = 0;

		foreach ( $state['table_map'] as $table ) {
			$table_sql = $this->quote_identifier( (string) $table );
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_sql}" );

			if ( null === $count ) {
				return $this->fail( $state, 'Unable to verify transformed staging table: ' . $table );
			}

			$total_rows += (int) $count;
		}

		if ( $total_rows !== (int) $state['manifest_rows'] ) {
			return $this->fail( $state, 'Row count changed during migration transform. Staged database is unsafe.' );
		}

		$state['verified_rows'] = $total_rows;
		$state['status']        = 'verified';
		$state['stage']         = 'complete';
		$state['completed_at']  = gmdate( 'c' );
		$state['updated_at']    = gmdate( 'c' );
		$state['live_tables_modified'] = false;
		$state['ready_for_live_promotion'] = empty( $restore_plan['changes']['prefix_remap_required'] );
		$state['promotion_blocker'] = ! empty( $restore_plan['changes']['prefix_remap_required'] )
			? 'Database-prefix data remapping must be implemented before live promotion.'
			: null;

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

	private function table_transform_meta( string $table ): array {
		global $wpdb;

		$table_sql = $this->quote_identifier( $table );
		$columns   = $wpdb->get_results( "SHOW COLUMNS FROM {$table_sql}", ARRAY_A );
		$indexes   = $wpdb->get_results( "SHOW INDEX FROM {$table_sql}", ARRAY_A );

		if ( ! is_array( $columns ) || ! is_array( $indexes ) ) {
			return $this->error( 'Unable to inspect staged table schema: ' . $table );
		}

		$text_columns = array();

		foreach ( $columns as $column ) {
			$type = strtolower( (string) ( $column['Type'] ?? '' ) );
			if ( preg_match( '/(?:char|text|blob|json|enum|set)/', $type ) ) {
				$text_columns[] = (string) $column['Field'];
			}
		}

		$primary = array();
		$unique  = array();

		foreach ( $indexes as $index ) {
			$key  = (string) ( $index['Key_name'] ?? '' );
			$col  = (string) ( $index['Column_name'] ?? '' );
			$seq  = (int) ( $index['Seq_in_index'] ?? 0 );
			$non  = (int) ( $index['Non_unique'] ?? 1 );

			if ( '' === $col || $seq < 1 ) {
				continue;
			}

			if ( 'PRIMARY' === $key ) {
				$primary[ $seq ] = $col;
			} elseif ( 0 === $non ) {
				if ( ! isset( $unique[ $key ] ) ) {
					$unique[ $key ] = array();
				}
				$unique[ $key ][ $seq ] = $col;
			}
		}

		$key_columns = array();

		if ( $primary ) {
			ksort( $primary );
			$key_columns = array_values( $primary );
		} elseif ( $unique ) {
			$first = reset( $unique );
			ksort( $first );
			$key_columns = array_values( $first );
		}

		return array(
			'success'      => true,
			'text_columns' => $text_columns,
			'key_columns'  => $key_columns,
		);
	}

	private function replacement_pairs( array $restore_plan ): array {
		$pairs = array();

		$raw = array(
			(string) ( $restore_plan['source']['home_url'] ?? '' ) => (string) ( $restore_plan['target']['home_url'] ?? '' ),
			(string) ( $restore_plan['source']['site_url'] ?? '' ) => (string) ( $restore_plan['target']['site_url'] ?? '' ),
			(string) ( $restore_plan['source']['content_dir'] ?? '' ) => (string) ( $restore_plan['target']['content_dir'] ?? '' ),
		);

		foreach ( $raw as $from => $to ) {
			if ( '' === $from || $from === $to ) {
				continue;
			}

			$pairs[ $from ] = $to;
			$escaped_from = str_replace( '/', '\\/', $from );
			$escaped_to   = str_replace( '/', '\\/', $to );

			if ( $escaped_from !== $from ) {
				$pairs[ $escaped_from ] = $escaped_to;
			}
		}

		uksort(
			$pairs,
			static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a )
		);

		return $pairs;
	}

	private function transform_value( string $value, array $pairs, int $depth ): array {
		if ( $depth > 12 ) {
			return $this->error( 'Serialized migration data exceeded the safe recursion depth.' );
		}

		if ( is_serialized( $value ) ) {
			$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );

			if ( false === $decoded && 'b:0;' !== $value ) {
				return $this->error( 'Serialized migration value could not be decoded safely.' );
			}

			if ( is_object( $decoded ) ) {
				return $this->error( 'Serialized object data requires the dedicated object-safe migration layer before live restore.' );
			}

			$nested = $this->transform_mixed( $decoded, $pairs, $depth + 1 );

			if ( ! $nested['success'] ) {
				return $nested;
			}

			return array(
				'success'      => true,
				'value'        => serialize( $nested['value'] ),
				'replacements' => $nested['replacements'],
			);
		}

		$count = 0;
		$result = str_replace( array_keys( $pairs ), array_values( $pairs ), $value, $count );

		return array(
			'success'      => true,
			'value'        => $result,
			'replacements' => $count,
		);
	}

	private function transform_mixed( $value, array $pairs, int $depth ): array {
		if ( $depth > 12 ) {
			return $this->error( 'Serialized migration data exceeded the safe recursion depth.' );
		}

		if ( is_string( $value ) ) {
			return $this->transform_value( $value, $pairs, $depth );
		}

		if ( is_array( $value ) ) {
			$out = array();
			$total = 0;

			foreach ( $value as $key => $item ) {
				$key_result = is_string( $key )
					? $this->transform_value( $key, $pairs, $depth + 1 )
					: array( 'success' => true, 'value' => $key, 'replacements' => 0 );

				if ( ! $key_result['success'] ) {
					return $key_result;
				}

				$item_result = $this->transform_mixed( $item, $pairs, $depth + 1 );

				if ( ! $item_result['success'] ) {
					return $item_result;
				}

				$out[ $key_result['value'] ] = $item_result['value'];
				$total += (int) $key_result['replacements'] + (int) $item_result['replacements'];
			}

			return array( 'success' => true, 'value' => $out, 'replacements' => $total );
		}

		if ( is_object( $value ) ) {
			return $this->error( 'Serialized object data requires the dedicated object-safe migration layer before live restore.' );
		}

		return array( 'success' => true, 'value' => $value, 'replacements' => 0 );
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
