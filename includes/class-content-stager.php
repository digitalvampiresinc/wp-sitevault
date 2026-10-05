<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Content_Stager {

	private const OPTION = 'sitevault_content_staging_state';
	private const FILES_PER_BATCH = 100;
	private const BYTES_PER_BATCH = 20971520; // 20 MB.

	public function start( array $restore_plan, array $safety_state, array $database_state ): array {
		if ( 'ready' !== ( $restore_plan['status'] ?? '' ) ) {
			return $this->error( 'Restore plan is not ready for wp-content staging.' );
		}

		$plan_id = sanitize_key( (string) ( $restore_plan['plan_id'] ?? '' ) );

		if (
			'complete' !== ( $safety_state['status'] ?? '' ) ||
			'safety_ready' !== ( $safety_state['staging']['status'] ?? '' ) ||
			( $safety_state['plan_id'] ?? '' ) !== $plan_id
		) {
			return $this->error( 'A matching verified target safety snapshot is required before wp-content staging.' );
		}

		if (
			'verified' !== ( $database_state['status'] ?? '' ) ||
			( $database_state['plan_id'] ?? '' ) !== $plan_id
		) {
			return $this->error( 'A matching verified shadow database is required before wp-content staging.' );
		}

		$archive = WP_CONTENT_DIR . '/sitevault/restore-plans/' . $plan_id . '/payload/content/wp-content.zip';

		if ( ! is_readable( $archive ) || ! is_file( $archive ) ) {
			return $this->error( 'Restore-plan wp-content archive is unavailable.' );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return $this->error( 'PHP ZipArchive is not available on this server.' );
		}

		$existing = $this->get_state();

		if (
			is_array( $existing ) &&
			in_array( $existing['status'] ?? '', array( 'running', 'verified' ), true ) &&
			( $existing['plan_id'] ?? '' ) === $plan_id
		) {
			return array( 'success' => true, 'state' => $existing );
		}

		$root = WP_CONTENT_DIR . '/sitevault/restore-staging/' . $plan_id . '/shadow-wp-content';

		$clean = $this->reset_directory( $root );

		if ( ! $clean['success'] ) {
			return $clean;
		}

		$zip  = new ZipArchive();
		$open = $zip->open( $archive );

		if ( true !== $open ) {
			return $this->error( 'Unable to open staged wp-content archive.' );
		}

		$entry_count = (int) $zip->numFiles;
		$zip->close();

		$expected_files = (int) ( $restore_plan['content']['manifest_files'] ?? 0 );
		$expected_bytes = $this->manifest_content_bytes( $plan_id );

		if ( $expected_files > 0 && $entry_count !== $expected_files ) {
			return $this->error( 'wp-content archive entry count no longer matches the restore plan.' );
		}

		$state = array(
			'status'                 => 'running',
			'stage'                  => 'extract',
			'plan_id'                => $plan_id,
			'started_at'             => gmdate( 'c' ),
			'updated_at'             => gmdate( 'c' ),
			'completed_at'           => null,
			'archive_file'           => $archive,
			'staging_root'           => $root,
			'zip_index'              => 0,
			'expected_files'         => $expected_files,
			'expected_bytes'         => $expected_bytes,
			'files_staged'           => 0,
			'bytes_staged'           => 0,
			'directories_created'    => 0,
			'verified_files'         => 0,
			'verified_bytes'         => 0,
			'target_before_files'    => (int) ( $safety_state['content']['files_archived'] ?? 0 ),
			'target_before_bytes'    => (int) ( $safety_state['content']['bytes_archived'] ?? 0 ),
			'live_files_modified'    => false,
			'ready_for_promotion'    => false,
			'error'                  => null,
		);

		$this->save_state( $state );

		return array( 'success' => true, 'state' => $state );
	}

	public function process_batch(): array {
		$state = $this->get_state();

		if ( 'running' !== ( $state['status'] ?? '' ) || 'extract' !== ( $state['stage'] ?? '' ) ) {
			return $this->error( 'No wp-content staging extraction is ready to process.', $state );
		}

		$zip  = new ZipArchive();
		$open = $zip->open( $state['archive_file'] );

		if ( true !== $open ) {
			return $this->fail( $state, 'Unable to reopen wp-content archive for staging.' );
		}

		$processed_files = 0;
		$processed_bytes = 0;
		$index           = (int) $state['zip_index'];
		$total_entries   = (int) $zip->numFiles;

		while (
			$index < $total_entries &&
			$processed_files < self::FILES_PER_BATCH &&
			$processed_bytes < self::BYTES_PER_BATCH
		) {
			$name = $zip->getNameIndex( $index );
			$stat = $zip->statIndex( $index );

			if ( false === $name || false === $stat ) {
				$zip->close();
				return $this->fail( $state, 'Unable to inspect wp-content archive entry #' . $index . '.' );
			}

			$normalized = ltrim( wp_normalize_path( $name ), '/' );

			if ( ! $this->is_safe_content_entry( $normalized ) ) {
				$zip->close();
				return $this->fail( $state, 'Unsafe or unexpected wp-content path blocked: ' . $normalized );
			}

			$relative = substr( $normalized, strlen( 'wp-content/' ) );

			if ( '' === $relative ) {
				$index++;
				$state['zip_index'] = $index;
				continue;
			}

			if ( str_ends_with( $normalized, '/' ) ) {
				$dir = trailingslashit( $state['staging_root'] ) . untrailingslashit( $relative );

				if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
					$zip->close();
					return $this->fail( $state, 'Unable to create staged directory: ' . $relative );
				}

				$state['directories_created']++;
				$index++;
				$state['zip_index'] = $index;
				continue;
			}

			$entry_size = (int) ( $stat['size'] ?? 0 );

			if (
				$processed_files > 0 &&
				$processed_bytes + $entry_size > self::BYTES_PER_BATCH
			) {
				break;
			}

			$target = trailingslashit( $state['staging_root'] ) . $relative;
			$parent = dirname( $target );

			if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
				$zip->close();
				return $this->fail( $state, 'Unable to create staged file directory: ' . dirname( $relative ) );
			}

			$result = $this->extract_entry( $zip, $name, $target, $entry_size );

			if ( ! $result['success'] ) {
				$zip->close();
				return $this->fail( $state, $result['message'] );
			}

			$state['files_staged']++;
			$state['bytes_staged'] += (int) $result['bytes'];
			$processed_files++;
			$processed_bytes += (int) $result['bytes'];
			$index++;
			$state['zip_index'] = $index;
		}

		$zip->close();
		$state['updated_at'] = gmdate( 'c' );

		if ( $index >= $total_entries ) {
			$state['stage'] = 'verify';
		}

		$this->save_state( $state );

		return array( 'success' => true, 'state' => $state );
	}

	public function verify(): array {
		$state = $this->get_state();

		if ( 'running' !== ( $state['status'] ?? '' ) || 'verify' !== ( $state['stage'] ?? '' ) ) {
			return $this->error( 'wp-content staging is not ready for verification.', $state );
		}

		$count = 0;
		$bytes = 0;
		$root  = wp_normalize_path( $state['staging_root'] );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$state['staging_root'],
				FilesystemIterator::SKIP_DOTS
			),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}

			$path = wp_normalize_path( $file->getPathname() );

			if ( 0 !== strpos( $path, trailingslashit( $root ) ) ) {
				return $this->fail( $state, 'Staged file resolved outside the controlled wp-content staging root.' );
			}

			$count++;
			$bytes += (int) $file->getSize();
		}

		if ( $count !== (int) $state['expected_files'] ) {
			return $this->fail(
				$state,
				'Staged wp-content file count does not match the manifest. Expected ' .
				(int) $state['expected_files'] . ', found ' . $count . '.'
			);
		}

		if ( null !== $state['expected_bytes'] && $bytes !== (int) $state['expected_bytes'] ) {
			return $this->fail(
				$state,
				'Staged wp-content byte total does not match the manifest. Expected ' .
				(int) $state['expected_bytes'] . ', found ' . $bytes . '.'
			);
		}

		$state['verified_files']      = $count;
		$state['verified_bytes']      = $bytes;
		$state['status']              = 'verified';
		$state['stage']               = 'complete';
		$state['completed_at']        = gmdate( 'c' );
		$state['updated_at']          = gmdate( 'c' );
		$state['live_files_modified'] = false;
		$state['ready_for_promotion'] = true;

		$this->save_state( $state );

		return array( 'success' => true, 'state' => $state );
	}

	public function get_state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private function extract_entry( ZipArchive $zip, string $entry, string $target, int $expected_size ): array {
		$stream = $zip->getStream( $entry );

		if ( false === $stream ) {
			return $this->error( 'Unable to read staged archive entry: ' . $entry );
		}

		$tmp = $target . '.sitevault-tmp';
		$out = fopen( $tmp, 'wb' );

		if ( false === $out ) {
			fclose( $stream );
			return $this->error( 'Unable to create staged file: ' . $entry );
		}

		$written = 0;

		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 1048576 );

			if ( false === $chunk ) {
				fclose( $stream );
				fclose( $out );
				@unlink( $tmp );
				return $this->error( 'Unable to read staged archive data: ' . $entry );
			}

			if ( '' === $chunk ) {
				continue;
			}

			$bytes = fwrite( $out, $chunk );

			if ( false === $bytes || $bytes !== strlen( $chunk ) ) {
				fclose( $stream );
				fclose( $out );
				@unlink( $tmp );
				return $this->error( 'Unable to write complete staged file data: ' . $entry );
			}

			$written += $bytes;
		}

		fclose( $stream );
		fclose( $out );

		if ( $written !== $expected_size ) {
			@unlink( $tmp );
			return $this->error( 'Staged file size mismatch for: ' . $entry );
		}

		if ( file_exists( $target ) && ! @unlink( $target ) ) {
			@unlink( $tmp );
			return $this->error( 'Unable to replace an existing staged file: ' . $entry );
		}

		if ( ! @rename( $tmp, $target ) ) {
			@unlink( $tmp );
			return $this->error( 'Unable to finalise staged file: ' . $entry );
		}

		return array(
			'success' => true,
			'bytes'   => $written,
		);
	}

	private function is_safe_content_entry( string $path ): bool {
		if ( '' === $path || 0 !== strpos( $path, 'wp-content/' ) ) {
			return false;
		}

		if (
			false !== strpos( $path, '../' ) ||
			false !== strpos( $path, '..\\' ) ||
			(bool) preg_match( '/^[a-zA-Z]:[\\\\\/]/', $path )
		) {
			return false;
		}

		if ( 'wp-content/sitevault' === rtrim( $path, '/' ) || 0 === strpos( $path, 'wp-content/sitevault/' ) ) {
			return false;
		}

		return true;
	}

	private function manifest_content_bytes( string $plan_id ): ?int {
		$file = WP_CONTENT_DIR . '/sitevault/restore-plans/' . $plan_id . '/payload/manifest.json';

		if ( ! is_readable( $file ) ) {
			return null;
		}

		$manifest = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $manifest ) || ! isset( $manifest['payload']['wp_content']['bytes_archived'] ) ) {
			return null;
		}

		return (int) $manifest['payload']['wp_content']['bytes_archived'];
	}

	private function reset_directory( string $root ): array {
		$allowed_root = wp_normalize_path( WP_CONTENT_DIR . '/sitevault/restore-staging/' );
		$normalized   = trailingslashit( wp_normalize_path( $root ) );

		if ( 0 !== strpos( $normalized, trailingslashit( $allowed_root ) ) ) {
			return $this->error( 'Refusing to reset a directory outside SiteVault restore staging.' );
		}

		if ( is_dir( $root ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $iterator as $item ) {
				if ( $item->isLink() ) {
					@unlink( $item->getPathname() );
				} elseif ( $item->isDir() ) {
					@rmdir( $item->getPathname() );
				} else {
					@unlink( $item->getPathname() );
				}
			}

			if ( ! @rmdir( $root ) ) {
				return $this->error( 'Unable to reset previous wp-content staging directory.' );
			}
		}

		if ( ! wp_mkdir_p( $root ) ) {
			return $this->error( 'Unable to create isolated wp-content staging directory.' );
		}

		return array( 'success' => true );
	}

	private function save_state( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	private function fail( array $state, string $message ): array {
		$state['status']     = 'failed';
		$state['error']      = $message;
		$state['updated_at'] = gmdate( 'c' );
		$this->save_state( $state );
		return $this->error( $message, $state );
	}

	private function error( string $message, ?array $state = null ): array {
		return array(
			'success' => false,
			'message' => $message,
			'state'   => $state,
		);
	}
}
