<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Content_Archiver {

	private const SCAN_DIR_BATCH = 25;
	private const ARCHIVE_FILE_BATCH = 100;
	private const ARCHIVE_BYTE_BATCH = 20971520; // 20 MB.

	public function initialise( string $backup_dir ): array {
		$content_dir = trailingslashit( $backup_dir ) . 'content';

		if ( ! wp_mkdir_p( $content_dir ) ) {
			return $this->error( 'Unable to create content backup directory.' );
		}

		$inventory_file = $content_dir . '/inventory.jsonl';
		$state_file     = $content_dir . '/archive-state.json';
		$archive_file   = $content_dir . '/wp-content.zip';

		if ( false === file_put_contents( $inventory_file, '', LOCK_EX ) ) {
			return $this->error( 'Unable to initialise the wp-content inventory.' );
		}

		$state = array(
			'status'             => 'running',
			'phase'              => 'scanning',
			'started_at'         => gmdate( 'c' ),
			'updated_at'         => gmdate( 'c' ),
			'completed_at'       => null,
			'directory_queue'    => array( '' ),
			'directories_scanned'=> 0,
			'files_discovered'   => 0,
			'bytes_discovered'   => 0,
			'files_archived'     => 0,
			'bytes_archived'     => 0,
			'files_skipped'      => 0,
			'archive_verified'   => false,
			'archive_entries'    => 0,
			'self_backup_excluded'=> true,
			'inventory_offset'   => 0,
			'inventory_file'     => $inventory_file,
			'archive_file'       => $archive_file,
			'error'              => null,
		);

		if ( ! $this->save_state( $state_file, $state ) ) {
			return $this->error( 'Unable to save wp-content archive state.' );
		}

		return array( 'success' => true, 'state' => $state );
	}

	public function process_batch( string $backup_dir ): array {
		$content_dir = trailingslashit( $backup_dir ) . 'content';
		$state_file  = $content_dir . '/archive-state.json';
		$state       = $this->load_state( $state_file );

		if ( ! $state ) {
			return $this->error( 'wp-content archive state could not be loaded.' );
		}

		if ( 'complete' === ( $state['status'] ?? '' ) ) {
			return array( 'success' => true, 'state' => $state );
		}

		if ( 'scanning' === ( $state['phase'] ?? '' ) ) {
			return $this->process_scan_batch( $state_file, $state );
		}

		if ( 'archiving' === ( $state['phase'] ?? '' ) ) {
			return $this->process_archive_batch( $state_file, $state );
		}

		return $this->fail_state( $state_file, $state, 'Unknown wp-content archive phase.' );
	}

	public function get_state( string $backup_dir ): ?array {
		return $this->load_state( trailingslashit( $backup_dir ) . 'content/archive-state.json' );
	}

	private function process_scan_batch( string $state_file, array $state ): array {
		$root      = wp_normalize_path( WP_CONTENT_DIR );
		$processed = 0;
		$lines     = '';

		while ( $processed < self::SCAN_DIR_BATCH && ! empty( $state['directory_queue'] ) ) {
			$relative_dir = array_shift( $state['directory_queue'] );
			$absolute_dir = '' === $relative_dir
				? WP_CONTENT_DIR
				: WP_CONTENT_DIR . '/' . $relative_dir;

			if ( ! is_dir( $absolute_dir ) || ! is_readable( $absolute_dir ) ) {
				$state['files_skipped']++;
				$processed++;
				continue;
			}

			$entries = scandir( $absolute_dir );

			if ( false === $entries ) {
				$state['files_skipped']++;
				$processed++;
				continue;
			}

			sort( $entries, SORT_STRING );

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$relative = ltrim( ( '' === $relative_dir ? '' : $relative_dir . '/' ) . $entry, '/' );

				if ( 'sitevault' === $relative || 0 === strpos( $relative, 'sitevault/' ) ) {
					continue;
				}

				$absolute = WP_CONTENT_DIR . '/' . $relative;

				if ( is_link( $absolute ) ) {
					$state['files_skipped']++;
					continue;
				}

				if ( is_dir( $absolute ) ) {
					$state['directory_queue'][] = $relative;
					continue;
				}

				if ( ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
					$state['files_skipped']++;
					continue;
				}

				$real = realpath( $absolute );

				if ( false === $real ) {
					$state['files_skipped']++;
					continue;
				}

				$real_normalized = wp_normalize_path( $real );

				if ( 0 !== strpos( $real_normalized, trailingslashit( $root ) ) ) {
					$state['files_skipped']++;
					continue;
				}

				$size = filesize( $absolute );

				if ( false === $size ) {
					$state['files_skipped']++;
					continue;
				}

				$lines .= wp_json_encode(
					array(
						'path' => $relative,
						'size' => (int) $size,
					),
					JSON_UNESCAPED_SLASHES
				) . "\n";

				$state['files_discovered']++;
				$state['bytes_discovered'] += (int) $size;
			}

			$state['directories_scanned']++;
			$processed++;
		}

		if ( '' !== $lines && false === file_put_contents( $state['inventory_file'], $lines, FILE_APPEND | LOCK_EX ) ) {
			return $this->fail_state( $state_file, $state, 'Unable to write wp-content inventory entries.' );
		}

		$state['updated_at'] = gmdate( 'c' );

		if ( empty( $state['directory_queue'] ) ) {
			$state['phase'] = 'archiving';
			unset( $state['directory_queue'] );
		}

		if ( ! $this->save_state( $state_file, $state ) ) {
			return $this->error( 'wp-content scan progressed, but state could not be saved.' );
		}

		return array( 'success' => true, 'state' => $state );
	}

	private function process_archive_batch( string $state_file, array $state ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return $this->fail_state( $state_file, $state, 'PHP ZipArchive is not available on this server.' );
		}

		$inventory = fopen( $state['inventory_file'], 'rb' );

		if ( false === $inventory ) {
			return $this->fail_state( $state_file, $state, 'Unable to read wp-content inventory.' );
		}

		if ( 0 !== fseek( $inventory, (int) $state['inventory_offset'] ) ) {
			fclose( $inventory );
			return $this->fail_state( $state_file, $state, 'Unable to resume wp-content inventory position.' );
		}

		$zip = new ZipArchive();
		$open_result = $zip->open( $state['archive_file'], ZipArchive::CREATE );

		if ( true !== $open_result ) {
			fclose( $inventory );
			return $this->fail_state( $state_file, $state, 'Unable to open wp-content ZIP archive. Code: ' . (int) $open_result );
		}

		$files_this_batch = 0;
		$bytes_this_batch = 0;
		$root             = wp_normalize_path( WP_CONTENT_DIR );
		$reached_eof      = false;

		while ( $files_this_batch < self::ARCHIVE_FILE_BATCH && $bytes_this_batch < self::ARCHIVE_BYTE_BATCH ) {
			$line_start = ftell( $inventory );
			$line       = fgets( $inventory );

			if ( false === $line ) {
				$reached_eof = true;
				break;
			}

			$entry = json_decode( trim( $line ), true );

			if ( ! is_array( $entry ) || empty( $entry['path'] ) ) {
				$state['files_skipped']++;
				$state['inventory_offset'] = ftell( $inventory );
				continue;
			}

			$relative = ltrim( (string) $entry['path'], '/' );
			$absolute = WP_CONTENT_DIR . '/' . $relative;
			$real     = realpath( $absolute );

			if ( false === $real || ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
				$state['files_skipped']++;
				$state['inventory_offset'] = ftell( $inventory );
				continue;
			}

			$real_normalized = wp_normalize_path( $real );

			if ( 0 !== strpos( $real_normalized, trailingslashit( $root ) ) ) {
				$state['files_skipped']++;
				$state['inventory_offset'] = ftell( $inventory );
				continue;
			}

			$size = filesize( $absolute );

			if ( false === $size ) {
				$state['files_skipped']++;
				$state['inventory_offset'] = ftell( $inventory );
				continue;
			}

			if ( $files_this_batch > 0 && $bytes_this_batch + (int) $size > self::ARCHIVE_BYTE_BATCH ) {
				fseek( $inventory, $line_start );
				break;
			}

			if ( ! $zip->addFile( $absolute, 'wp-content/' . $relative ) ) {
				$zip->close();
				fclose( $inventory );
				return $this->fail_state( $state_file, $state, 'Unable to add file to archive: ' . $relative );
			}

			$state['files_archived']++;
			$state['bytes_archived'] += (int) $size;
			$files_this_batch++;
			$bytes_this_batch += (int) $size;
			$state['inventory_offset'] = ftell( $inventory );
		}

		$zip_closed = $zip->close();
		fclose( $inventory );

		if ( ! $zip_closed ) {
			return $this->fail_state( $state_file, $state, 'Unable to finalise the current wp-content ZIP batch.' );
		}

		$state['updated_at'] = gmdate( 'c' );

		if ( $reached_eof ) {
			$verification = $this->verify_archive( $state );

			if ( ! $verification['success'] ) {
				return $this->fail_state(
					$state_file,
					$state,
					$verification['message'] ?? 'wp-content archive verification failed.'
				);
			}

			$state['archive_verified']    = true;
			$state['archive_entries']     = (int) $verification['entries'];
			$state['self_backup_excluded']= (bool) $verification['self_backup_excluded'];
			$state['phase']               = 'complete';
			$state['status']              = 'complete';
			$state['completed_at']        = gmdate( 'c' );
		}

		if ( ! $this->save_state( $state_file, $state ) ) {
			return $this->error( 'wp-content archive progressed, but state could not be saved.' );
		}

		return array( 'success' => true, 'state' => $state );
	}

	private function verify_archive( array $state ): array {
		if ( empty( $state['archive_file'] ) || ! is_readable( $state['archive_file'] ) ) {
			return array(
				'success' => false,
				'message' => 'Completed wp-content archive is not readable.',
			);
		}

		$zip = new ZipArchive();
		$open_result = $zip->open( $state['archive_file'] );

		if ( true !== $open_result ) {
			return array(
				'success' => false,
				'message' => 'Unable to reopen completed wp-content archive for verification. Code: ' . (int) $open_result,
			);
		}

		$entries = (int) $zip->numFiles;
		$self_backup_excluded = true;

		for ( $i = 0; $i < $entries; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( false === $name ) {
				continue;
			}

			$normalized = ltrim( wp_normalize_path( $name ), '/' );

			if ( 'wp-content/sitevault' === $normalized || 0 === strpos( $normalized, 'wp-content/sitevault/' ) ) {
				$self_backup_excluded = false;
				break;
			}
		}

		$zip->close();

		if ( ! $self_backup_excluded ) {
			return array(
				'success' => false,
				'message' => 'Archive verification found SiteVault runtime backup data inside wp-content.zip.',
			);
		}

		if ( $entries !== (int) ( $state['files_archived'] ?? 0 ) ) {
			return array(
				'success' => false,
				'message' => 'Archive entry count does not match the number of files reported as archived.',
			);
		}

		$expected = (int) ( $state['files_discovered'] ?? 0 ) - (int) ( $state['files_skipped'] ?? 0 );

		if ( $entries !== $expected ) {
			return array(
				'success' => false,
				'message' => 'Archive entry count does not match the expected inventory total.',
			);
		}

		return array(
			'success'              => true,
			'entries'              => $entries,
			'self_backup_excluded' => true,
		);
	}

	private function load_state( string $state_file ): ?array {
		if ( ! is_readable( $state_file ) ) {
			return null;
		}

		$decoded = json_decode( (string) file_get_contents( $state_file ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function save_state( string $state_file, array $state ): bool {
		return false !== file_put_contents(
			$state_file,
			wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		);
	}

	private function fail_state( string $state_file, array $state, string $message ): array {
		$state['status']     = 'failed';
		$state['error']      = $message;
		$state['updated_at'] = gmdate( 'c' );
		$this->save_state( $state_file, $state );
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
