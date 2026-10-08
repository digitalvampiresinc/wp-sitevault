<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Database_Exporter {

	private const DEFAULT_BATCH_SIZE = 500;

	public function initialise( string $backup_dir ): array {
		global $wpdb;

		$database_dir = trailingslashit( $backup_dir ) . 'database';

		if ( ! wp_mkdir_p( $database_dir ) ) {
			return $this->error( 'Unable to create database backup directory.' );
		}

		$dump_file  = $database_dir . '/database.sql';
		$state_file = $database_dir . '/export-state.json';
		$like       = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables     = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $like )
		);

		if ( ! is_array( $tables ) ) {
			return $this->error( 'Unable to read the WordPress database table list.' );
		}

		$header  = "-- SiteVault database export\n";
		$header .= '-- Created: ' . gmdate( 'c' ) . "\n";
		$header .= '-- Source: ' . home_url() . "\n\n";
		$header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
		$header .= "SET FOREIGN_KEY_CHECKS = 0;\n";
		$header .= "SET UNIQUE_CHECKS = 0;\n\n";

		if ( false === file_put_contents( $dump_file, $header, LOCK_EX ) ) {
			return $this->error( 'Unable to initialise the SQL dump file.' );
		}

		$state = array(
			'status'        => 'running',
			'started_at'    => gmdate( 'c' ),
			'updated_at'    => gmdate( 'c' ),
			'completed_at'  => null,
			'tables'        => array_values( $tables ),
			'table_index'   => 0,
			'row_offset'    => 0,
			'table_started' => false,
			'rows_exported' => 0,
			'batch_size'    => self::DEFAULT_BATCH_SIZE,
			'dump_file'     => $dump_file,
			'error'         => null,
		);

		if ( ! $this->save_state( $state_file, $state ) ) {
			return $this->error( 'Unable to save database export state.' );
		}

		return array( 'success' => true, 'state' => $state );
	}

	public function process_batch( string $backup_dir ): array {
		global $wpdb;

		$database_dir = trailingslashit( $backup_dir ) . 'database';
		$dump_file    = $database_dir . '/database.sql';
		$state_file   = $database_dir . '/export-state.json';
		$state        = $this->load_state( $state_file );

		if ( ! $state ) {
			return $this->error( 'Database export state could not be loaded.' );
		}

		if ( 'complete' === $state['status'] ) {
			return array( 'success' => true, 'state' => $state );
		}

		if ( ! isset( $state['tables'][ $state['table_index'] ] ) ) {
			return $this->complete_export( $state_file, $dump_file, $state );
		}

		$table      = (string) $state['tables'][ $state['table_index'] ];
		$table_sql  = $this->quote_identifier( $table );
		$batch_size = max( 50, min( 2000, (int) $state['batch_size'] ) );
		$offset     = max( 0, (int) $state['row_offset'] );

		if ( ! $state['table_started'] ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE {$table_sql}", ARRAY_N );

			if ( ! $create || empty( $create[1] ) ) {
				return $this->fail_state( $state_file, $state, 'Unable to read CREATE statement for table: ' . $table );
			}

			$prefix  = "\n-- Table: {$table}\n";
			$prefix .= "DROP TABLE IF EXISTS {$table_sql};\n";
			$prefix .= $create[1] . ";\n\n";

			if ( false === file_put_contents( $dump_file, $prefix, FILE_APPEND | LOCK_EX ) ) {
				return $this->fail_state( $state_file, $state, 'Unable to write table structure for: ' . $table );
			}

			$state['table_started'] = true;
		}

		$order_by = $this->get_order_by_clause( $table_sql );

		$query = $wpdb->prepare(
			"SELECT * FROM {$table_sql}{$order_by} LIMIT %d OFFSET %d",
			$batch_size,
			$offset
		);

		$rows = $wpdb->get_results( $query, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return $this->fail_state( $state_file, $state, 'Unable to export rows from table: ' . $table );
		}

		if ( empty( $rows ) ) {
			$state['table_index']++;
			$state['row_offset']    = 0;
			$state['table_started'] = false;
			$state['updated_at']    = gmdate( 'c' );
			$this->save_state( $state_file, $state );

			if ( ! isset( $state['tables'][ $state['table_index'] ] ) ) {
				return $this->complete_export( $state_file, $dump_file, $state );
			}

			return array( 'success' => true, 'state' => $state );
		}

		$columns = array_keys( $rows[0] );
		$sql     = 'INSERT INTO ' . $table_sql . ' (' .
			implode( ', ', array_map( array( $this, 'quote_identifier' ), $columns ) ) .
			") VALUES\n";

		$value_sets = array();

		foreach ( $rows as $row ) {
			$values = array();

			foreach ( $columns as $column ) {
				$values[] = $this->sql_value( $row[ $column ] );
			}

			$value_sets[] = '(' . implode( ', ', $values ) . ')';
		}

		$sql .= implode( ",\n", $value_sets ) . ";\n";

		if ( false === file_put_contents( $dump_file, $sql, FILE_APPEND | LOCK_EX ) ) {
			return $this->fail_state( $state_file, $state, 'Unable to write rows for table: ' . $table );
		}

		$count                   = count( $rows );
		$state['row_offset']    += $count;
		$state['rows_exported'] += $count;
		$state['updated_at']     = gmdate( 'c' );

		if ( $count < $batch_size ) {
			$state['table_index']++;
			$state['row_offset']    = 0;
			$state['table_started'] = false;
		}

		if ( ! $this->save_state( $state_file, $state ) ) {
			return $this->error( 'Database rows were written, but export progress could not be saved.' );
		}

		if ( ! isset( $state['tables'][ $state['table_index'] ] ) ) {
			return $this->complete_export( $state_file, $dump_file, $state );
		}

		return array( 'success' => true, 'state' => $state );
	}

	public function get_state( string $backup_dir ): ?array {
		return $this->load_state( trailingslashit( $backup_dir ) . 'database/export-state.json' );
	}

	private function complete_export( string $state_file, string $dump_file, array $state ): array {
		$footer = "\nSET UNIQUE_CHECKS = 1;\nSET FOREIGN_KEY_CHECKS = 1;\n";

		if ( false === file_put_contents( $dump_file, $footer, FILE_APPEND | LOCK_EX ) ) {
			return $this->fail_state( $state_file, $state, 'Unable to finalise the SQL dump file.' );
		}

		$state['status']       = 'complete';
		$state['completed_at'] = gmdate( 'c' );
		$state['updated_at']   = gmdate( 'c' );
		$this->save_state( $state_file, $state );

		return array( 'success' => true, 'state' => $state );
	}

	private function get_order_by_clause( string $table_sql ): string {
		global $wpdb;

		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table_sql}", ARRAY_A );

		if ( ! is_array( $indexes ) || empty( $indexes ) ) {
			return '';
		}

		$primary = array();
		$unique  = array();

		foreach ( $indexes as $index ) {
			$key_name = isset( $index['Key_name'] ) ? (string) $index['Key_name'] : '';
			$column   = isset( $index['Column_name'] ) ? (string) $index['Column_name'] : '';
			$seq      = isset( $index['Seq_in_index'] ) ? (int) $index['Seq_in_index'] : 0;
			$nonuniq  = isset( $index['Non_unique'] ) ? (int) $index['Non_unique'] : 1;

			if ( '' === $column || $seq < 1 ) {
				continue;
			}

			if ( 'PRIMARY' === $key_name ) {
				$primary[ $seq ] = $column;
			} elseif ( 0 === $nonuniq ) {
				if ( ! isset( $unique[ $key_name ] ) ) {
					$unique[ $key_name ] = array();
				}
				$unique[ $key_name ][ $seq ] = $column;
			}
		}

		$columns = array();

		if ( $primary ) {
			ksort( $primary );
			$columns = array_values( $primary );
		} elseif ( $unique ) {
			$first = reset( $unique );
			ksort( $first );
			$columns = array_values( $first );
		}

		if ( ! $columns ) {
			return '';
		}

		return ' ORDER BY ' . implode(
			', ',
			array_map(
				fn( string $column ): string => $this->quote_identifier( $column ) . ' ASC',
				$columns
			)
		);
	}

	private function sql_value( $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}

		/*
		 * Do not use wpdb::_real_escape() or esc_sql() for dump serialization.
		 * WordPress adds temporary placeholder-escape hashes around literal percent
		 * signs for prepared-query safety. Those placeholders are normally removed
		 * only when WordPress executes a query; writing them directly into a dump
		 * permanently corrupts %, including CSS, Yoast variables and serialized data.
		 */
		$escaped = strtr(
			(string) $value,
			array(
				"\\"   => "\\\\",
				"\0"   => "\\0",
				"\n"   => "\\n",
				"\r"   => "\\r",
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => "\\Z",
			)
		);

		return "'" . $escaped . "'";
	}

	private function quote_identifier( string $identifier ): string {
		return chr( 96 ) . str_replace( chr( 96 ), chr( 96 ) . chr( 96 ), $identifier ) . chr( 96 );
	}

	private function load_state( string $state_file ): ?array {
		if ( ! is_readable( $state_file ) ) {
			return null;
		}

		$decoded = json_decode( (string) file_get_contents( $state_file ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function save_state( string $state_file, array $state ): bool {
		return false !== file_put_contents(
			$state_file,
			wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		);
	}

	private function fail_state( string $state_file, array $state, string $message ): array {
		$state['status']     = 'failed';
		$state['error']      = $message;
		$state['updated_at'] = gmdate( 'c' );
		$this->save_state( $state_file, $state );
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
