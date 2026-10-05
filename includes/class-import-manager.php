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

	private function error( string $message, ?array $state = null ): array {
		return array(
			'success' => false,
			'message' => $message,
			'state'   => $state,
		);
	}
}
