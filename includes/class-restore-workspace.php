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

		$entries = self::BASE_ENTRIES;
		$manifest_raw = $zip->getFromName( 'manifest.json' );
		$manifest = false === $manifest_raw ? array() : json_decode( $manifest_raw, true );
		$format_version = is_array( $manifest ) ? (int) ( $manifest['format_version'] ?? 1 ) : 1;

		if ( 2 === $format_version ) {
			foreach ( (array) ( $manifest['payload']['wp_content']['chunks'] ?? array() ) as $chunk ) {
				$file = (string) ( $chunk['file'] ?? '' );
				if ( '' !== $file ) {
					$entries[] = $file;
				}
			}
		} else {
			$entries[] = 'content/wp-content.zip';
		}

		foreach ( $entries as $entry ) {
			$target = $payload . '/' . $entry;
			$parent = dirname( $target );
			if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
				$zip->close();
				return $this->error( 'Unable to create restore workspace directory.' );
			}
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
			'extracted_entries'  => count( $entries ),
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

	public function initialise( array $validation_state ): array {
		$package_file = isset( $validation_state['package_file'] ) ? (string) $validation_state['package_file'] : '';
		if ( '' === $package_file || ! $this->is_allowed_package_path( $package_file ) ) {
			return $this->error( 'Validated package path is unavailable or outside SiteVault runtime storage.' );
		}
		$zip = new ZipArchive();
		$open = $zip->open( $package_file );
		if ( true !== $open ) return $this->error( 'Unable to open validated SiteVault package.' );
		$manifest_raw = $zip->getFromName( 'manifest.json' );
		$manifest = false === $manifest_raw ? array() : json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) ) { $zip->close(); return $this->error( 'Package manifest is invalid.' ); }
		$entries = self::BASE_ENTRIES;
		if ( 2 === (int) ( $manifest['format_version'] ?? 1 ) ) {
			foreach ( (array) ( $manifest['payload']['wp_content']['chunks'] ?? array() ) as $chunk ) {
				$file = (string) ( $chunk['file'] ?? '' );
				if ( '' !== $file ) $entries[] = $file;
			}
		} else {
			$entries[] = 'content/wp-content.zip';
		}
		$zip->close();
		$plan_id = 'plan-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$root = WP_CONTENT_DIR . '/sitevault/restore-plans/' . $plan_id;
		$payload = $root . '/payload';
		if ( ! wp_mkdir_p( $payload . '/database' ) || ! wp_mkdir_p( $payload . '/content' ) || ! wp_mkdir_p( $payload . '/checksums' ) ) {
			return $this->error( 'Unable to create isolated restore-plan workspace.' );
		}
		$state = array(
			'status' => 'running',
			'stage' => 'extract',
			'plan_id' => $plan_id,
			'workspace_root' => $root,
			'payload_root' => $payload,
			'package_file' => $package_file,
			'backup_id' => $validation_state['backup_id'] ?? ( $manifest['backup_id'] ?? '' ),
			'entries' => array_values( $entries ),
			'entry_index' => 0,
			'verify_index' => 0,
			'extracted_entries' => 0,
			'verified_entries' => 0,
			'integrity_verified' => false,
			'created_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
			'error' => null,
		);
		if ( ! $this->save_workspace_state( $state ) ) return $this->error( 'Unable to save restore workspace state.' );
		return array( 'success' => true, 'state' => $state );
	}

	public function process_batch( string $plan_id ): array {
		$state = $this->load_workspace_state( $plan_id );
		if ( ! is_array( $state ) ) return $this->error( 'Restore workspace state is unavailable.' );
		if ( 'prepared' === ( $state['status'] ?? '' ) ) return array( 'success' => true, 'state' => $state );
		if ( 'failed' === ( $state['status'] ?? '' ) ) return $this->error( (string) ( $state['error'] ?? 'Restore workspace preparation failed.' ) );
		$stage = (string) ( $state['stage'] ?? 'extract' );
		if ( 'extract' === $stage ) {
			$index = (int) ( $state['entry_index'] ?? 0 );
			$entries = (array) ( $state['entries'] ?? array() );
			if ( $index >= count( $entries ) ) {
				$state['stage'] = 'verify';
				$state['updated_at'] = gmdate( 'c' );
				$this->save_workspace_state( $state );
				return array( 'success' => true, 'state' => $state );
			}
			$entry = (string) $entries[ $index ];
			$zip = new ZipArchive();
			$open = $zip->open( (string) $state['package_file'] );
			if ( true !== $open ) return $this->fail_workspace( $state, 'Unable to reopen SiteVault package.' );
			$target = rtrim( (string) $state['payload_root'], '/' ) . '/' . $entry;
			$parent = dirname( $target );
			if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) { $zip->close(); return $this->fail_workspace( $state, 'Unable to create restore workspace directory.' ); }
			$result = $this->copy_zip_entry( $zip, $entry, $target );
			$zip->close();
			if ( ! $result['success'] ) return $this->fail_workspace( $state, $result['message'] ?? 'Payload extraction failed.' );
			$state['entry_index'] = $index + 1;
			$state['extracted_entries'] = (int) $state['extracted_entries'] + 1;
			if ( $state['entry_index'] >= count( $entries ) ) $state['stage'] = 'verify';
			$state['updated_at'] = gmdate( 'c' );
			$this->save_workspace_state( $state );
			return array( 'success' => true, 'state' => $state );
		}
		if ( 'verify' === $stage ) {
			$payload = (string) $state['payload_root'];
			$checksum_file = $payload . '/checksums/sha256.json';
			if ( ! is_readable( $checksum_file ) ) return $this->fail_workspace( $state, 'Extracted checksum file is unreadable.' );
			$checksums = json_decode( (string) file_get_contents( $checksum_file ), true );
			if ( ! is_array( $checksums ) || 'sha256' !== strtolower( (string) ( $checksums['algorithm'] ?? '' ) ) ) return $this->fail_workspace( $state, 'Extracted checksum metadata is invalid.' );
			$verify_entries = array_values( array_filter( (array) $state['entries'], static fn( $e ) => 'checksums/sha256.json' !== $e ) );
			$index = (int) ( $state['verify_index'] ?? 0 );
			if ( $index >= count( $verify_entries ) ) {
				$state['stage'] = 'complete';
				$state['status'] = 'prepared';
				$state['integrity_verified'] = true;
				$state['prepared_at'] = gmdate( 'c' );
				$state['updated_at'] = gmdate( 'c' );
				$this->save_workspace_state( $state );
				return array( 'success' => true, 'state' => $state );
			}
			$entry = (string) $verify_entries[ $index ];
			$file = $payload . '/' . $entry;
			$expected = $checksums['files'][ $entry ] ?? null;
			if ( ! is_readable( $file ) || ! is_array( $expected ) || empty( $expected['sha256'] ) ) return $this->fail_workspace( $state, 'Verification metadata is incomplete for: ' . $entry );
			$actual_hash = hash_file( 'sha256', $file );
			$actual_size = filesize( $file );
			if ( false === $actual_hash || ! hash_equals( strtolower( (string) $expected['sha256'] ), strtolower( $actual_hash ) ) ) return $this->fail_workspace( $state, 'Extracted payload checksum mismatch for: ' . $entry );
			if ( isset( $expected['size'] ) && null !== $expected['size'] && (int) $expected['size'] !== (int) $actual_size ) return $this->fail_workspace( $state, 'Extracted payload size mismatch for: ' . $entry );
			$state['verify_index'] = $index + 1;
			$state['verified_entries'] = (int) $state['verified_entries'] + 1;
			if ( $state['verify_index'] >= count( $verify_entries ) ) {
				$state['stage'] = 'complete';
				$state['status'] = 'prepared';
				$state['integrity_verified'] = true;
				$state['prepared_at'] = gmdate( 'c' );
			}
			$state['updated_at'] = gmdate( 'c' );
			$this->save_workspace_state( $state );
			return array( 'success' => true, 'state' => $state );
		}
		return $this->error( 'Unknown restore workspace stage.' );
	}

	private function load_workspace_state( string $plan_id ): ?array {
		$plan_id = sanitize_key( $plan_id );
		if ( ! preg_match( '/^plan-[a-z0-9-]+$/', $plan_id ) ) return null;
		$file = WP_CONTENT_DIR . '/sitevault/restore-plans/' . $plan_id . '/workspace-state.json';
		if ( ! is_readable( $file ) ) return null;
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}

	private function save_workspace_state( array $state ): bool {
		$root = (string) ( $state['workspace_root'] ?? '' );
		return '' !== $root && false !== file_put_contents( $root . '/workspace-state.json', wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
	}

	private function fail_workspace( array $state, string $message ): array {
		$state['status'] = 'failed';
		$state['error'] = $message;
		$state['updated_at'] = gmdate( 'c' );
		$this->save_workspace_state( $state );
		return array( 'success' => false, 'message' => $message, 'state' => $state );
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

		$manifest_file = $payload_root . '/manifest.json';
		$manifest = is_readable( $manifest_file ) ? json_decode( (string) file_get_contents( $manifest_file ), true ) : array();
		$entries = array( 'manifest.json', 'database/database.sql' );
		if ( 2 === (int) ( $manifest['format_version'] ?? 1 ) ) {
			foreach ( (array) ( $manifest['payload']['wp_content']['chunks'] ?? array() ) as $chunk ) {
				if ( ! empty( $chunk['file'] ) ) {
					$entries[] = (string) $chunk['file'];
				}
			}
		} else {
			$entries[] = 'content/wp-content.zip';
		}

		foreach ( $entries as $entry ) {
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
