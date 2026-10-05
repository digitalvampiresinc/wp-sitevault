<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Checksum_Manager {

	public function create( string $backup_dir ): array {
		$targets = array(
			'manifest.json'          => trailingslashit( $backup_dir ) . 'manifest.json',
			'database/database.sql'  => trailingslashit( $backup_dir ) . 'database/database.sql',
			'content/wp-content.zip' => trailingslashit( $backup_dir ) . 'content/wp-content.zip',
		);

		$checksums = array(
			'algorithm'  => 'sha256',
			'created_at' => gmdate( 'c' ),
			'files'      => array(),
		);

		foreach ( $targets as $logical => $path ) {
			if ( ! is_readable( $path ) ) {
				return array(
					'success' => false,
					'message' => 'Checksum source is missing or unreadable: ' . $logical,
				);
			}

			$hash = hash_file( 'sha256', $path );

			if ( false === $hash ) {
				return array(
					'success' => false,
					'message' => 'Unable to calculate SHA-256 checksum for: ' . $logical,
				);
			}

			$size = filesize( $path );

			$checksums['files'][ $logical ] = array(
				'sha256' => $hash,
				'size'   => false === $size ? null : (int) $size,
			);
		}

		$dir = trailingslashit( $backup_dir ) . 'checksums';

		if ( ! wp_mkdir_p( $dir ) ) {
			return array(
				'success' => false,
				'message' => 'Unable to create checksum directory.',
			);
		}

		$file = $dir . '/sha256.json';

		if ( false === file_put_contents(
			$file,
			wp_json_encode( $checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		) ) {
			return array(
				'success' => false,
				'message' => 'Unable to write SHA-256 checksum file.',
			);
		}

		return array(
			'success'   => true,
			'file'      => $file,
			'checksums' => $checksums,
		);
	}
}
