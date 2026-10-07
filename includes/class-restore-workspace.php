<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Restore_Workspace {

	private const BASE_ENTRIES = array( 'manifest.json', 'database/database.sql', 'checksums/sha256.json' );

	public function prepare( array $validation_state ): array {
		$package_file = isset( $validation_state['package_file'] ) ? (string) $validation_state['package_file'] : '';

		if ( '' === $package_file || ! $this->is_allowed_package_path( $package_file ) ) {
			return $this->error( 'Validated package path is unavailable or outside SiteVault runtime storage.' );
		}

		$validator   = new SiteVault_Import_Validator();
		$validation = $validator->validate( $package_file, 'restore-plan-preflight' );

		if ( ! $validation['success'] ) {
			return $this->error( 'Package revalidation failed before extraction: ' . ( $validation['message'] ?? 'Unknown validation error.' ) );
		}

		$plan_id = 'plan-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$root    = WP_CONTENT_DIR . '/sitevault/restore-plans/' . $plan_id;
		$payload = $root . '/payload';

		if ( ! wp_mkdir_p( $payload . '/database' ) || ! wp_mkdir_p( $payload . '/content' ) || ! wp_mkdir_p( $payload . '/checksums' ) ) {
			return $this->error( 'Unable to create isolated restore-plan workspace.' );
		}

		$zip  = new ZipArchive();
		$open = $zip->open( $package_file );

		if ( true !== $open ) {
			return $this->error( 'Unable to open validated SiteVault package for extraction.' );
		}

		foreach ( self::ENTRIES as $entry ) {
			$target = $payload . '/' . $entry;
			$result = $this->copy_zip_entry( $zip, $entry, $target );

			if ( ! $result['success'] ) {
				$zip->close();
				return $result;
			}
		}

		$zip->close();

		$integrity = $this->verify_extracted_payload( $payload );

		if ( ! $integrity['success'] ) {
			return $integrity;
		}

		$state = array(
			'status'             => 'prepared',
			'plan_id'            => $plan_id,
			'prepared_at'        => gmdate( 'c' ),
			'workspace_root'     => $root,
			'payload_root'       => $payload,
			'package_file'       => $package_file,
			'backup_id'          => $validation['state']['backup_id'] ?? '',
			'extracted_entries'  => count( self::ENTRIES ),
			'integrity_verified' => true,
			'error'              => null,
		);

		if ( false === file_put_contents(
			$root . '/workspace-state.json',
			wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		) ) {
			return $this->error( 'Restore workspace was prepared but state could not be saved.' );
		}

		return array(
			'success' => true,
			'state'   => $state,
		);
	}

	private function copy_zip_entry( ZipArchive $zip, string $entry, string $target ): array {
		$stream = $zip->getStream( $entry );

		if ( false === $stream ) {
			return $this->error( 'Unable to read package entry: ' . $entry );
		}

		$tmp = $target . '.tmp';
		$out = fopen( $tmp, 'wb' );

		if ( false === $out ) {
			fclose( $stream );
			return $this->error( 'Unable to create restore workspace file: ' . $entry );
		}

		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 1048576 );

			if ( false === $chunk ) {
				fclose( $stream );
				fclose( $out );
				@unlink( $tmp );
				return $this->error( 'Unable to read package entry during extraction: ' . $entry );
			}

			if ( '' !== $chunk && false === fwrite( $out, $chunk ) ) {
				fclose( $stream );
				fclose( $out );
				@unlink( $tmp );
				return $this->error( 'Unable to write restore workspace file: ' . $entry );
			}
		}

		fclose( $stream );
		fclose( $out );

		if ( ! @rename( $tmp, $target ) ) {
			@unlink( $tmp );
			return $this->error( 'Unable to finalise restore workspace file: ' . $entry );
		}

		return array( 'success' => true );
	}

	private function verify_extracted_payload( string $payload_root ): array {
		$checksum_file = $payload_root . '/checksums/sha256.json';

		if ( ! is_readable( $checksum_file ) ) {
			return $this->error( 'Extracted checksum file is unreadable.' );
		}

		$checksums = json_decode( (string) file_get_contents( $checksum_file ), true );

		if ( ! is_array( $checksums ) || 'sha256' !== strtolower( (string) ( $checksums['algorithm'] ?? '' ) ) ) {
			return $this->error( 'Extracted checksum metadata is invalid.' );
		}

		foreach ( array( 'manifest.json', 'database/database.sql', 'content/wp-content.zip' ) as $entry ) {
			$file     = $payload_root . '/' . $entry;
			$expected = $checksums['files'][ $entry ] ?? null;

			if ( ! is_readable( $file ) || ! is_array( $expected ) || empty( $expected['sha256'] ) ) {
				return $this->error( 'Extracted payload verification metadata is incomplete for: ' . $entry );
			}

			$actual_hash = hash_file( 'sha256', $file );
			$actual_size = filesize( $file );

			if ( false === $actual_hash || ! hash_equals( strtolower( (string) $expected['sha256'] ), strtolower( $actual_hash ) ) ) {
				return $this->error( 'Extracted payload checksum mismatch for: ' . $entry );
			}

			if ( isset( $expected['size'] ) && null !== $expected['size'] && (int) $expected['size'] !== (int) $actual_size ) {
				return $this->error( 'Extracted payload size mismatch for: ' . $entry );
			}
		}

		return array( 'success' => true );
	}

	private function is_allowed_package_path( string $file ): bool {
		$real = realpath( $file );
		$root = realpath( WP_CONTENT_DIR . '/sitevault' );

		if ( false === $real || false === $root || ! is_file( $real ) ) {
			return false;
		}

		return 0 === strpos(
			wp_normalize_path( $real ),
			trailingslashit( wp_normalize_path( $root ) )
		);
	}

	private function error( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
