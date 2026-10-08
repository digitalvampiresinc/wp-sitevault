<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Import_Manager {

	public function imports_root(): string {
		return WP_CONTENT_DIR . '/sitevault/imports';
	}

	public function create_from_upload( array $file ): array {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return $this->error( 'No valid uploaded SiteVault package was received.' );
		}

		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );

		if ( 'sitevault' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return $this->error( 'Please select a .sitevault package.' );
		}

		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return $this->error( 'Upload failed with PHP upload error code: ' . (int) $file['error'] );
		}

		$root = $this->imports_root();

		if ( ! wp_mkdir_p( $root ) ) {
			return $this->error( 'Unable to create SiteVault import directory.' );
		}

		$import_id = 'import-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$dir       = $root . '/' . $import_id;

		if ( ! wp_mkdir_p( $dir ) ) {
			return $this->error( 'Unable to create import workspace.' );
		}

		$package = $dir . '/package.sitevault';

		if ( ! move_uploaded_file( $file['tmp_name'], $package ) ) {
			return $this->error( 'Unable to move uploaded package into the SiteVault import workspace.' );
		}

		$validator = new SiteVault_Import_Validator();
		$result    = $validator->validate( $package, $import_id );

		if ( ! $result['success'] ) {
			$state = array(
				'status'       => 'invalid',
				'import_id'    => $import_id,
				'validated_at' => gmdate( 'c' ),
				'package_file' => $package,
				'package_name' => $name,
				'error'        => $result['message'] ?? 'Package validation failed.',
			);
			$validator->save_state( $dir . '/import-state.json', $state );
			return $this->error( $state['error'], $state );
		}

		$state = $result['state'];
		$state['package_name'] = $name;
		$validator->save_state( $dir . '/import-state.json', $state );

		return array(
			'success' => true,
			'state'   => $state,
		);
	}

	public function validate_existing_backup( string $backup_id ): array {
		$history = new SiteVault_Backup_History();
		$file    = $history->get_package_file( $backup_id );

		if ( null === $file ) {
			return $this->error( 'The selected backup package is unavailable.' );
		}

		$validator = new SiteVault_Import_Validator();
		return $validator->validate( $file, 'local-' . sanitize_key( $backup_id ) );
	}

	public function initialise_chunk_upload( string $name, int $size, string $resume_id = '' ): array {
		$name = sanitize_file_name( $name );
		if ( 'sitevault' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return $this->error( 'Please select a .sitevault package.' );
		}
		if ( $size < 1 ) {
			return $this->error( 'The selected package is empty.' );
		}
		$root = $this->imports_root();
		if ( ! wp_mkdir_p( $root ) ) {
			return $this->error( 'Unable to create SiteVault import directory.' );
		}
		if ( '' !== $resume_id && preg_match( '/^upload-[a-z0-9-]+$/', $resume_id ) ) {
			$dir = $root . '/' . $resume_id;
			$state = $this->read_upload_state( $dir );
			if ( is_array( $state ) && (int) ( $state['expected_size'] ?? 0 ) === $size && (string) ( $state['original_name'] ?? '' ) === $name ) {
				return array( 'success' => true, 'state' => $state );
			}
		}
		$upload_id = 'upload-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 8, false, false ) );
		$dir = $root . '/' . $upload_id;
		if ( ! wp_mkdir_p( $dir ) ) {
			return $this->error( 'Unable to create chunked upload workspace.' );
		}
		$state = array(
			'status' => 'uploading',
			'upload_id' => $upload_id,
			'original_name' => $name,
			'expected_size' => $size,
			'received_size' => 0,
			'next_index' => 0,
			'created_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
			'package_file' => $dir . '/package.sitevault.part',
			'error' => null,
		);
		if ( ! $this->save_upload_state( $dir, $state ) ) {
			return $this->error( 'Unable to initialise chunked upload state.' );
		}
		return array( 'success' => true, 'state' => $state );
	}

	public function append_upload_chunk( string $upload_id, int $index, array $file ): array {
		$upload_id = sanitize_key( $upload_id );
		if ( ! preg_match( '/^upload-[a-z0-9-]+$/', $upload_id ) ) {
			return $this->error( 'Invalid upload session.' );
		}
		$dir = $this->imports_root() . '/' . $upload_id;
		$state = $this->read_upload_state( $dir );
		if ( ! is_array( $state ) || 'uploading' !== ( $state['status'] ?? '' ) ) {
			return $this->error( 'Upload session is unavailable.' );
		}
		if ( $index !== (int) ( $state['next_index'] ?? 0 ) ) {
			return $this->error( 'Unexpected chunk index. Resume from chunk ' . (int) ( $state['next_index'] ?? 0 ) . '.', $state );
		}
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return $this->error( 'No valid upload chunk was received.', $state );
		}
		$incoming = filesize( $file['tmp_name'] );
		if ( false === $incoming || $incoming < 1 ) {
			return $this->error( 'Received chunk is empty.', $state );
		}
		$package = (string) $state['package_file'];
		$out = fopen( $package, 'ab' );
		$in = fopen( $file['tmp_name'], 'rb' );
		if ( false === $out || false === $in ) {
			if ( is_resource( $out ) ) fclose( $out );
			if ( is_resource( $in ) ) fclose( $in );
			return $this->error( 'Unable to open chunk upload streams.', $state );
		}
		if ( ! flock( $out, LOCK_EX ) ) {
			fclose( $in ); fclose( $out );
			return $this->error( 'Unable to lock upload workspace.', $state );
		}
		$written = stream_copy_to_stream( $in, $out );
		fflush( $out ); flock( $out, LOCK_UN ); fclose( $in ); fclose( $out );
		if ( false === $written || (int) $written !== (int) $incoming ) {
			return $this->error( 'Chunk could not be written completely.', $state );
		}
		$state['received_size'] = (int) $state['received_size'] + (int) $written;
		$state['next_index'] = $index + 1;
		$state['updated_at'] = gmdate( 'c' );
		if ( $state['received_size'] > (int) $state['expected_size'] ) {
			$state['status'] = 'failed';
			$state['error'] = 'Uploaded data exceeded expected package size.';
			$this->save_upload_state( $dir, $state );
			return $this->error( $state['error'], $state );
		}
		$this->save_upload_state( $dir, $state );
		return array( 'success' => true, 'state' => $state );
	}

	public function finalise_chunk_upload( string $upload_id ): array {
		$upload_id = sanitize_key( $upload_id );
		if ( ! preg_match( '/^upload-[a-z0-9-]+$/', $upload_id ) ) return $this->error( 'Invalid upload session.' );
		$dir = $this->imports_root() . '/' . $upload_id;
		$state = $this->read_upload_state( $dir );
		if ( ! is_array( $state ) ) return $this->error( 'Upload session is unavailable.' );
		if ( (int) ( $state['received_size'] ?? 0 ) !== (int) ( $state['expected_size'] ?? -1 ) ) {
			return $this->error( 'Upload is incomplete.', $state );
		}
		$part = (string) $state['package_file'];
		$package = $dir . '/package.sitevault';
		if ( ! is_file( $part ) || ! @rename( $part, $package ) ) {
			return $this->error( 'Unable to finalise uploaded package.', $state );
		}
		$validator = new SiteVault_Import_Validator();
		$result = $validator->validate( $package, $upload_id );
		if ( ! $result['success'] ) {
			$state['status'] = 'invalid';
			$state['package_file'] = $package;
			$state['error'] = $result['message'] ?? 'Package validation failed.';
			$this->save_upload_state( $dir, $state );
			return $this->error( $state['error'], $state );
		}
		$validated = $result['state'];
		$validated['package_name'] = (string) ( $state['original_name'] ?? basename( $package ) );
		$validator->save_state( $dir . '/import-state.json', $validated );
		$state['status'] = 'complete';
		$state['package_file'] = $package;
		$state['completed_at'] = gmdate( 'c' );
		$this->save_upload_state( $dir, $state );
		return array( 'success' => true, 'state' => $validated );
	}

	private function read_upload_state( string $dir ): ?array {
		$file = trailingslashit( $dir ) . 'upload-state.json';
		if ( ! is_readable( $file ) ) return null;
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}

	private function save_upload_state( string $dir, array $state ): bool {
		return false !== file_put_contents( trailingslashit( $dir ) . 'upload-state.json', wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
	}

	private function error( string $message, ?array $state = null ): array {
		return array(
			'success' => false,
			'message' => $message,
			'state'   => $state,
		);
	}
}
