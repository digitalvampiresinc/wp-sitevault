<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Backup_History {

	public function get_backups( int $limit = 20 ): array {
		$root = WP_CONTENT_DIR . '/sitevault/backups';

		if ( ! is_dir( $root ) || ! is_readable( $root ) ) {
			return array();
		}

		$entries = scandir( $root );

		if ( false === $entries ) {
			return array();
		}

		$items = array();

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || ! preg_match( '/^sv-[a-zA-Z0-9-]+$/', $entry ) ) {
				continue;
			}

			$dir = $root . '/' . $entry;

			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$manifest = $this->read_json( $dir . '/manifest.json' );
			$package  = $this->read_json( $dir . '/package-state.json' );
			$content  = $this->read_json( $dir . '/content/archive-state.json' );
			$database = $this->read_json( $dir . '/database/export-state.json' );

			$package_file = $package['package_file'] ?? '';
			$has_package  = 'complete' === ( $package['status'] ?? '' ) &&
				is_string( $package_file ) &&
				is_readable( $package_file );

			$items[] = array(
				'backup_id'      => $entry,
				'created_at'     => $manifest['created_at'] ?? null,
				'completed_at'   => $package['completed_at'] ?? ( $content['completed_at'] ?? null ),
				'database_rows'  => (int) ( $database['rows_exported'] ?? 0 ),
				'files_archived' => (int) ( $content['files_archived'] ?? 0 ),
				'package_status' => $package['status'] ?? ( 'complete' === ( $content['status'] ?? '' ) ? 'needs_package' : 'incomplete' ),
				'package_size'   => $has_package ? (int) ( $package['package_size'] ?? filesize( $package_file ) ) : null,
				'package_sha256' => $has_package ? ( $package['package_sha256'] ?? null ) : null,
				'downloadable'   => $has_package,
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
			}
		);

		return array_slice( $items, 0, max( 1, $limit ) );
	}

	public function get_package_file( string $backup_id ): ?string {
		$backup_id = sanitize_key( $backup_id );

		if ( ! preg_match( '/^sv-[a-z0-9-]+$/', $backup_id ) ) {
			return null;
		}

		$root = realpath( WP_CONTENT_DIR . '/sitevault/backups' );

		if ( false === $root ) {
			return null;
		}

		$state_file = $root . '/' . $backup_id . '/package-state.json';
		$state      = $this->read_json( $state_file );
		$file       = $state['package_file'] ?? '';

		if ( ! is_string( $file ) || '' === $file || ! is_readable( $file ) ) {
			return null;
		}

		$real = realpath( $file );

		if ( false === $real ) {
			return null;
		}

		$root_normalized = trailingslashit( wp_normalize_path( $root ) );
		$real_normalized = wp_normalize_path( $real );

		if ( 0 !== strpos( $real_normalized, $root_normalized ) ) {
			return null;
		}

		return $real;
	}

	private function read_json( string $file ): array {
		if ( ! is_readable( $file ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : array();
	}
}
