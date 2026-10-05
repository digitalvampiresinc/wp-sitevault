<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Package_Builder {

	public function build( string $backup_dir ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return $this->error( 'PHP ZipArchive is not available on this server.' );
		}

		$backup_id = basename( wp_normalize_path( $backup_dir ) );
		$state_file = trailingslashit( $backup_dir ) . 'package-state.json';

		$manifest_result = $this->finalise_manifest( $backup_dir, $backup_id );

		if ( ! $manifest_result['success'] ) {
			return $this->fail_state( $state_file, $manifest_result['message'] );
		}

		$checksum_manager = new SiteVault_Checksum_Manager();
		$checksum_result  = $checksum_manager->create( $backup_dir );

		if ( ! $checksum_result['success'] ) {
			return $this->fail_state( $state_file, $checksum_result['message'] ?? 'Unable to create checksums.' );
		}

		$package_dir = trailingslashit( $backup_dir ) . 'package';

		if ( ! wp_mkdir_p( $package_dir ) ) {
			return $this->fail_state( $state_file, 'Unable to create package directory.' );
		}

		$package_file = $package_dir . '/' . $backup_id . '.sitevault';
		$temp_file    = $package_file . '.tmp';

		if ( file_exists( $temp_file ) ) {
			@unlink( $temp_file );
		}

		$zip = new ZipArchive();
		$open = $zip->open( $temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		if ( true !== $open ) {
			return $this->fail_state( $state_file, 'Unable to initialise SiteVault package. Code: ' . (int) $open );
		}

		$entries = array(
			'manifest.json'          => trailingslashit( $backup_dir ) . 'manifest.json',
			'database/database.sql'  => trailingslashit( $backup_dir ) . 'database/database.sql',
			'content/wp-content.zip' => trailingslashit( $backup_dir ) . 'content/wp-content.zip',
			'checksums/sha256.json'  => trailingslashit( $backup_dir ) . 'checksums/sha256.json',
		);

		foreach ( $entries as $logical => $source ) {
			if ( ! is_readable( $source ) || ! $zip->addFile( $source, $logical ) ) {
				$zip->close();
				@unlink( $temp_file );
				return $this->fail_state( $state_file, 'Unable to add package entry: ' . $logical );
			}

			if ( 'content/wp-content.zip' === $logical && method_exists( $zip, 'setCompressionName' ) ) {
				$zip->setCompressionName( $logical, ZipArchive::CM_STORE );
			}
		}

		if ( ! $zip->close() ) {
			@unlink( $temp_file );
			return $this->fail_state( $state_file, 'Unable to finalise SiteVault package.' );
		}

		if ( ! @rename( $temp_file, $package_file ) ) {
			@unlink( $temp_file );
			return $this->fail_state( $state_file, 'Unable to move the completed SiteVault package into place.' );
		}

		$verification = $this->verify_package( $package_file, array_keys( $entries ) );

		if ( ! $verification['success'] ) {
			return $this->fail_state( $state_file, $verification['message'] );
		}

		$package_hash = hash_file( 'sha256', $package_file );
		$package_size = filesize( $package_file );

		$state = array(
			'status'        => 'complete',
			'completed_at'  => gmdate( 'c' ),
			'package_file'  => $package_file,
			'package_name'  => basename( $package_file ),
			'package_size'  => false === $package_size ? null : (int) $package_size,
			'package_sha256'=> false === $package_hash ? null : $package_hash,
			'entries'       => $verification['entries'],
			'verified'      => true,
			'error'         => null,
		);

		if ( ! $this->save_state( $state_file, $state ) ) {
			return $this->error( 'Package was created, but package state could not be saved.', $state );
		}

		return array(
			'success' => true,
			'state'   => $state,
		);
	}

	public function get_state( string $backup_dir ): ?array {
		$file = trailingslashit( $backup_dir ) . 'package-state.json';

		if ( ! is_readable( $file ) ) {
			return null;
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function finalise_manifest( string $backup_dir, string $backup_id ): array {
		$file = trailingslashit( $backup_dir ) . 'manifest.json';

		if ( ! is_readable( $file ) ) {
			return array( 'success' => false, 'message' => 'Backup manifest is missing.' );
		}

		$manifest = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $manifest ) ) {
			return array( 'success' => false, 'message' => 'Backup manifest is invalid.' );
		}

		$db_state_file      = trailingslashit( $backup_dir ) . 'database/export-state.json';
		$content_state_file = trailingslashit( $backup_dir ) . 'content/archive-state.json';
		$db_state           = is_readable( $db_state_file ) ? json_decode( (string) file_get_contents( $db_state_file ), true ) : array();
		$content_state      = is_readable( $content_state_file ) ? json_decode( (string) file_get_contents( $content_state_file ), true ) : array();

		$manifest['completed_at'] = gmdate( 'c' );
		$manifest['backup_type']  = 'full';
		$manifest['payload']      = array(
			'database' => array(
				'file'          => 'database/database.sql',
				'tables'        => is_array( $db_state['tables'] ?? null ) ? count( $db_state['tables'] ) : null,
				'rows_exported' => (int) ( $db_state['rows_exported'] ?? 0 ),
			),
			'wp_content' => array(
				'file'             => 'content/wp-content.zip',
				'files_discovered' => (int) ( $content_state['files_discovered'] ?? 0 ),
				'files_archived'   => (int) ( $content_state['files_archived'] ?? 0 ),
				'bytes_archived'   => (int) ( $content_state['bytes_archived'] ?? 0 ),
				'archive_verified' => (bool) ( $content_state['archive_verified'] ?? false ),
			),
			'checksums' => array(
				'file'      => 'checksums/sha256.json',
				'algorithm' => 'sha256',
			),
		);
		$manifest['package'] = array(
			'file'   => $backup_id . '.sitevault',
			'format' => 'zip',
		);

		if ( false === file_put_contents(
			$file,
			wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		) ) {
			return array( 'success' => false, 'message' => 'Unable to finalise backup manifest.' );
		}

		return array( 'success' => true, 'manifest' => $manifest );
	}

	private function verify_package( string $package_file, array $required_entries ): array {
		$zip = new ZipArchive();
		$open = $zip->open( $package_file );

		if ( true !== $open ) {
			return array( 'success' => false, 'message' => 'Unable to reopen SiteVault package for verification.' );
		}

		foreach ( $required_entries as $entry ) {
			if ( false === $zip->locateName( $entry, ZipArchive::FL_NOCASE ) ) {
				$zip->close();
				return array( 'success' => false, 'message' => 'Package verification failed. Missing entry: ' . $entry );
			}
		}

		$count = (int) $zip->numFiles;
		$zip->close();

		if ( count( $required_entries ) !== $count ) {
			return array( 'success' => false, 'message' => 'Package verification found unexpected or missing entries.' );
		}

		return array( 'success' => true, 'entries' => $count );
	}

	private function fail_state( string $state_file, string $message ): array {
		$state = array(
			'status'     => 'failed',
			'updated_at' => gmdate( 'c' ),
			'error'      => $message,
		);

		$this->save_state( $state_file, $state );
		return $this->error( $message, $state );
	}

	private function save_state( string $file, array $state ): bool {
		return false !== file_put_contents(
			$file,
			wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		);
	}

	private function error( string $message, ?array $state = null ): array {
		return array(
			'success' => false,
			'message' => $message,
			'state'   => $state,
		);
	}
}
