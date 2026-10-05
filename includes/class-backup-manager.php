<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Backup_Manager {

	public function create_backup( string $backup_type = 'full', array $context = array() ): array {
		$backup_id = 'sv-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$base_dir  = WP_CONTENT_DIR . '/sitevault/backups/' . $backup_id;

		if ( ! wp_mkdir_p( $base_dir ) ) {
			return array(
				'success' => false,
				'message' => 'Unable to create backup directory.',
			);
		}

		$manifest = SiteVault_Backup_Manifest::create( $backup_id );
		$manifest['backup_type'] = sanitize_key( $backup_type );
		$manifest['context']     = $context;
		$written  = file_put_contents(
			$base_dir . '/manifest.json',
			wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		);

		if ( false === $written ) {
			return array(
				'success' => false,
				'message' => 'Unable to write backup manifest.',
			);
		}

		$exporter = new SiteVault_Database_Exporter();
		$database = $exporter->initialise( $base_dir );

		if ( ! $database['success'] ) {
			return array(
				'success' => false,
				'message' => $database['message'] ?? 'Unable to initialise database export.',
			);
		}

		$archiver = new SiteVault_Content_Archiver();
		$content  = $archiver->initialise( $base_dir );

		if ( ! $content['success'] ) {
			return array(
				'success' => false,
				'message' => $content['message'] ?? 'Unable to initialise wp-content backup.',
			);
		}

		return array(
			'success'   => true,
			'backup_id' => $backup_id,
			'path'      => $base_dir,
			'manifest'  => $manifest,
			'database'  => $database['state'],
			'content'   => $content['state'],
		);
	}
}
