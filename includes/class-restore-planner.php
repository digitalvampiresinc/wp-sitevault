<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Restore_Planner {

	public function create_plan( array $workspace_state ): array {
		global $wpdb;

		$root = isset( $workspace_state['payload_root'] ) ? (string) $workspace_state['payload_root'] : '';

		if ( '' === $root || ! is_dir( $root ) ) {
			return $this->error( 'Restore workspace payload is unavailable.' );
		}

		$manifest_file = $root . '/manifest.json';
		$sql_file      = $root . '/database/database.sql';
		$content_file  = $root . '/content/wp-content.zip';

		if ( ! is_readable( $manifest_file ) || ! is_readable( $sql_file ) || ! is_readable( $content_file ) ) {
			return $this->error( 'Restore workspace is missing required payload files.' );
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_file ), true );

		if ( ! is_array( $manifest ) ) {
			return $this->error( 'Restore workspace manifest is invalid.' );
		}

		$source_home        = untrailingslashit( (string) ( $manifest['site']['home_url'] ?? '' ) );
		$source_site        = untrailingslashit( (string) ( $manifest['site']['url'] ?? '' ) );
		$target_home        = untrailingslashit( home_url() );
		$target_site        = untrailingslashit( site_url() );
		$source_prefix      = (string) ( $manifest['database']['prefix'] ?? '' );
		$target_prefix      = (string) $wpdb->prefix;
		$source_wp          = (string) ( $manifest['wordpress']['version'] ?? '' );
		$target_wp          = (string) get_bloginfo( 'version' );
		$source_php         = (string) ( $manifest['php']['version'] ?? '' );
		$target_php         = PHP_VERSION;
		$source_content_dir = wp_normalize_path( (string) ( $manifest['wordpress']['content_dir'] ?? '' ) );
		$target_content_dir = wp_normalize_path( WP_CONTENT_DIR );

		$url_replacement_required  = '' !== $source_home && $source_home !== $target_home;
		$site_url_change_required  = '' !== $source_site && $source_site !== $target_site;
		$prefix_remap_required     = '' !== $source_prefix && $source_prefix !== $target_prefix;
		$path_replacement_required = '' !== $source_content_dir && $source_content_dir !== $target_content_dir;

		$mode = ( ! $url_replacement_required && ! $site_url_change_required )
			? 'same-domain restore'
			: 'cross-domain migration';

		$sql_inspection = $this->inspect_sql( $sql_file, $source_prefix );
		$content_size   = filesize( $content_file );
		$sql_size       = filesize( $sql_file );
		$required_bytes = max( 0, (int) $content_size ) + max( 0, (int) $sql_size );
		$free_space     = @disk_free_space( WP_CONTENT_DIR );
		$recommended    = (int) ceil( $required_bytes * 2.2 );
		$disk_ok        = false === $free_space ? null : ( $free_space >= $recommended );

		$warnings = array();
		$blockers = array();

		if ( ! is_writable( WP_CONTENT_DIR ) ) {
			$blockers[] = 'Target wp-content directory is not writable.';
		}

		if ( false === $free_space ) {
			$warnings[] = 'Available disk space could not be measured.';
		} elseif ( ! $disk_ok ) {
			$blockers[] = 'Available disk space is below the current restore safety estimate.';
		}

		if ( '' !== $source_wp && version_compare( $source_wp, $target_wp, '>' ) ) {
			$warnings[] = 'Backup was created on a newer WordPress version than the target site.';
		}

		if ( '' !== $source_php && version_compare( $source_php, $target_php, '>' ) ) {
			$warnings[] = 'Backup was created on a newer PHP version than the target environment.';
		}

		if ( ! $sql_inspection['success'] ) {
			$blockers[] = $sql_inspection['message'];
		} elseif (
			(int) ( $manifest['payload']['database']['tables'] ?? 0 ) > 0 &&
			count( $sql_inspection['tables'] ?? array() ) !== (int) ( $manifest['payload']['database']['tables'] ?? 0 )
		) {
			$blockers[] = 'Database dump table count does not match the package manifest.';
		}

		if ( $url_replacement_required || $site_url_change_required ) {
			$warnings[] = 'Cross-domain restore requires serialized-data-safe URL replacement before the restored site is considered usable.';
		}

		if ( $path_replacement_required ) {
			$warnings[] = 'Source and target wp-content filesystem paths differ; path-aware migration checks will be required.';
		}

		$plan = array(
			'status'                    => empty( $blockers ) ? 'ready' : 'blocked',
			'created_at'                => gmdate( 'c' ),
			'plan_id'                   => $workspace_state['plan_id'] ?? '',
			'backup_id'                 => $manifest['backup_id'] ?? '',
			'mode'                      => $mode,
			'source'                    => array(
				'home_url'    => $source_home,
				'site_url'    => $source_site,
				'db_prefix'   => $source_prefix,
				'wordpress'   => $source_wp,
				'php'         => $source_php,
				'content_dir' => $source_content_dir,
			),
			'target'                    => array(
				'home_url'    => $target_home,
				'site_url'    => $target_site,
				'db_prefix'   => $target_prefix,
				'wordpress'   => $target_wp,
				'php'         => $target_php,
				'content_dir' => $target_content_dir,
			),
			'changes'                   => array(
				'url_replacement_required'  => $url_replacement_required,
				'site_url_change_required'  => $site_url_change_required,
				'prefix_remap_required'     => $prefix_remap_required,
				'path_replacement_required' => $path_replacement_required,
			),
			'database'                  => array(
				'manifest_tables' => (int) ( $manifest['payload']['database']['tables'] ?? 0 ),
				'manifest_rows'   => (int) ( $manifest['payload']['database']['rows_exported'] ?? 0 ),
				'sql_tables'      => $sql_inspection['tables'] ?? array(),
				'sql_table_count' => count( $sql_inspection['tables'] ?? array() ),
				'sql_size'        => false === $sql_size ? null : (int) $sql_size,
			),
			'content'                   => array(
				'manifest_files' => (int) ( $manifest['payload']['wp_content']['files_archived'] ?? 0 ),
				'archive_size'   => false === $content_size ? null : (int) $content_size,
			),
			'environment'               => array(
				'wp_content_writable' => is_writable( WP_CONTENT_DIR ),
				'free_space'          => false === $free_space ? null : (int) $free_space,
				'recommended_space'   => $recommended,
				'disk_space_ok'       => $disk_ok,
			),
			'restore_sequence'          => array(
				'Create mandatory pre-restore safety snapshot',
				'Enter controlled restore/maintenance mode',
				'Prepare isolated database import',
				'Stage wp-content replacement',
				$prefix_remap_required ? 'Remap database table prefix to target prefix' : 'Keep existing database table prefix',
				( $url_replacement_required || $site_url_change_required ) ? 'Run serialized-data-safe URL replacement' : 'Keep existing site URLs',
				$path_replacement_required ? 'Run filesystem path migration checks/replacement where required' : 'Keep wp-content filesystem paths',
				'Verify restored database, files, active plugins/theme and site URLs',
				'Exit restore mode only after verification passes',
			),
			'warnings'                  => $warnings,
			'blockers'                  => $blockers,
			'destructive_actions_taken' => false,
		);

		$workspace_root = dirname( $root );

		if ( false === file_put_contents(
			$workspace_root . '/restore-plan.json',
			wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		) ) {
			return $this->error( 'Restore plan was created but could not be saved.' );
		}

		return array(
			'success' => true,
			'plan'    => $plan,
		);
	}

	private function inspect_sql( string $sql_file, string $source_prefix ): array {
		$handle = fopen( $sql_file, 'rb' );

		if ( false === $handle ) {
			return $this->error( 'Unable to inspect database SQL dump.' );
		}

		$tables = array();

		while ( false !== ( $line = fgets( $handle ) ) ) {
			if ( preg_match( '/^CREATE TABLE IF NOT EXISTS \x60([^\x60]+)\x60/i', $line, $match ) ||
				preg_match( '/^CREATE TABLE \x60([^\x60]+)\x60/i', $line, $match ) ) {
				$table = (string) $match[1];

				if ( '' !== $source_prefix && 0 !== strpos( $table, $source_prefix ) ) {
					fclose( $handle );
					return $this->error( 'Database dump contains a table outside the manifest source prefix: ' . $table );
				}

				$tables[] = $table;
			}
		}

		fclose( $handle );
		$tables = array_values( array_unique( $tables ) );

		if ( empty( $tables ) ) {
			return $this->error( 'No CREATE TABLE statements were found in the database dump.' );
		}

		return array(
			'success' => true,
			'tables'  => $tables,
		);
	}

	private function error( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
