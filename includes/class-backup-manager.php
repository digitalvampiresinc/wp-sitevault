<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Backup_Manager {

	public function create_backup(): array {
		$backup_id = 'sv-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
		$base_dir  = WP_CONTENT_DIR . '/sitevault/backups/' . $backup_id;

		if ( ! wp_mkdir_p( $base_dir ) ) {
			return array(
				'success' => false,
				'message' => 'Unable to create backup directory.',
			);
		}

		$manifest = SiteVault_Backup_Manifest::create( $backup_id );
		$written  = file_put_contents(
			$base_dir . '/manifest.json',
			wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		if ( false === $written ) {
			return array(
				'success' => false,
				'message' => 'Unable to write backup manifest.',
			);
		}

		return array(
			'success'   => true,
			'backup_id' => $backup_id,
			'path'      => $base_dir,
			'manifest'  => $manifest,
		);
	}
}
