<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Import_Validator {

	private const V1_REQUIRED_ENTRIES = array( 'manifest.json', 'database/database.sql', 'content/wp-content.zip', 'checksums/sha256.json' );

	public function validate( string $package_file, ?string $import_id = null ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return $this->error( 'PHP ZipArchive is not available on this server.' );
		}

		if ( ! is_readable( $package_file ) || ! is_file( $package_file ) ) {
			return $this->error( 'The SiteVault package is missing or unreadable.' );
		}

		$zip = new ZipArchive();
		$open = $zip->open( $package_file );

		if ( true !== $open ) {
			return $this->error( 'The selected file is not a readable SiteVault/ZIP package.' );
		}

		$entries = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( false === $name ) {
				$zip->close();
				return $this->error( 'Unable to read one or more package entries.' );
			}

			$normalized = ltrim( wp_normalize_path( $name ), '/' );

			if ( $this->is_unsafe_path( $normalized ) ) {
				$zip->close();
				return $this->error( 'Unsafe package path detected: ' . $normalized );
			}

			$entries[] = $normalized;
		}

		$manifest_raw  = $zip->getFromName( 'manifest.json' );
		$checksums_raw = $zip->getFromName( 'checksums/sha256.json' );

		if ( false === $manifest_raw || false === $checksums_raw ) {
			$zip->close();
			return $this->error( 'Package metadata could not be read.' );
		}

		$manifest  = json_decode( $manifest_raw, true );
		$checksums = json_decode( $checksums_raw, true );

		if ( ! is_array( $manifest ) || ! is_array( $checksums ) ) {
			$zip->close();
			return $this->error( 'Manifest or checksum metadata is invalid JSON.' );
		}

		$format_version = (int) ( $manifest['format_version'] ?? 0 );
		if ( 'sitevault' !== ( $manifest['format'] ?? '' ) || ! in_array( $format_version, array( 1, 2 ), true ) ) {
			$zip->close();
			return $this->error( 'Unsupported SiteVault package format or format version.' );
		}
		$required = self::V1_REQUIRED_ENTRIES;
		if ( 2 === $format_version ) {
			$required = array( 'manifest.json', 'database/database.sql', 'checksums/sha256.json' );
			foreach ( (array) ( $manifest['payload']['wp_content']['chunks'] ?? array() ) as $chunk ) {
				$file = (string) ( $chunk['file'] ?? '' );
				if ( '' === $file || 0 !== strpos( $file, 'content/wp-content-part-' ) || ! str_ends_with( $file, '.zip' ) ) {
					$zip->close(); return $this->error( 'Invalid V2 content chunk metadata.' );
				}
				$required[] = $file;
			}
			if ( count( $required ) < 4 ) { $zip->close(); return $this->error( 'V2 package contains no content chunks.' ); }
		}
		sort( $entries ); sort( $required );
		if ( $entries !== $required ) { $zip->close(); return $this->error( 'Package structure does not match its SiteVault manifest.' ); }

		if ( 'sha256' !== strtolower( (string) ( $checksums['algorithm'] ?? '' ) ) ) {
			$zip->close();
			return $this->error( 'Unsupported checksum algorithm in package.' );
		}

		$payloads = array( 'manifest.json', 'database/database.sql' );
		if ( 1 === $format_version ) {
			$payloads[] = 'content/wp-content.zip';
		} else {
			foreach ( (array) ( $manifest['payload']['wp_content']['chunks'] ?? array() ) as $chunk ) $payloads[] = (string) $chunk['file'];
		}

		$verified_payloads = array();

		foreach ( $payloads as $entry ) {
			$expected = $checksums['files'][ $entry ] ?? null;

			if ( ! is_array( $expected ) || empty( $expected['sha256'] ) ) {
				$zip->close();
				return $this->error( 'Missing checksum metadata for: ' . $entry );
			}

			$actual = $this->hash_zip_entry( $zip, $entry );

			if ( ! $actual['success'] ) {
				$zip->close();
				return $actual;
			}

			if ( ! hash_equals( strtolower( (string) $expected['sha256'] ), strtolower( $actual['sha256'] ) ) ) {
				$zip->close();
				return $this->error( 'SHA-256 mismatch for: ' . $entry );
			}

			if ( isset( $expected['size'] ) && null !== $expected['size'] && (int) $expected['size'] !== (int) $actual['size'] ) {
				$zip->close();
				return $this->error( 'Payload size mismatch for: ' . $entry );
			}

			$verified_payloads[ $entry ] = array(
				'sha256' => $actual['sha256'],
				'size'   => $actual['size'],
			);
		}

		$nested = 1 === $format_version ? $this->validate_nested_content( $zip, $manifest, $import_id ) : $this->validate_chunked_content( $zip, $manifest );
		$zip->close();

		if ( ! $nested['success'] ) {
			return $nested;
		}

		$package_size = filesize( $package_file );
		$package_hash = hash_file( 'sha256', $package_file );

		$result = array(
			'status'             => 'validated',
			'validated_at'       => gmdate( 'c' ),
			'import_id'          => $import_id,
			'package_file'       => $package_file,
			'package_name'       => basename( $package_file ),
			'package_size'       => false === $package_size ? null : (int) $package_size,
			'package_sha256'     => false === $package_hash ? null : $package_hash,
			'backup_id'          => sanitize_key( (string) ( $manifest['backup_id'] ?? '' ) ),
			'source_home_url'    => (string) ( $manifest['site']['home_url'] ?? '' ),
			'source_site_url'    => (string) ( $manifest['site']['url'] ?? '' ),
			'created_at'         => $manifest['created_at'] ?? null,
			'completed_at'       => $manifest['completed_at'] ?? null,
			'format_version'     => (int) ( $manifest['format_version'] ?? 0 ),
			'wordpress_version'  => (string) ( $manifest['wordpress']['version'] ?? '' ),
			'php_version'        => (string) ( $manifest['php']['version'] ?? '' ),
			'database_prefix'    => (string) ( $manifest['database']['prefix'] ?? '' ),
			'database_tables'    => (int) ( $manifest['payload']['database']['tables'] ?? 0 ),
			'database_rows'      => (int) ( $manifest['payload']['database']['rows_exported'] ?? 0 ),
			'content_files'      => (int) ( $manifest['payload']['wp_content']['files_archived'] ?? 0 ),
			'content_bytes'      => (int) ( $manifest['payload']['wp_content']['bytes_archived'] ?? 0 ),
			'nested_entries'     => (int) $nested['entries'],
			'runtime_excluded'   => (bool) $nested['runtime_excluded'],
			'checksums_verified' => count( $verified_payloads ),
			'ready_for_restore'  => true,
			'error'              => null,
		);

		return array(
			'success' => true,
			'state'   => $result,
		);
	}

	public function save_state( string $state_file, array $state ): bool {
		return false !== file_put_contents(
			$state_file,
			wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		);
	}

	public function get_state( string $state_file ): ?array {
		if ( ! is_readable( $state_file ) ) {
			return null;
		}

		$data = json_decode( (string) file_get_contents( $state_file ), true );
		return is_array( $data ) ? $data : null;
	}

	private function validate_nested_content( ZipArchive $outer, array $manifest, ?string $import_id ): array {
		$stream = $outer->getStream( 'content/wp-content.zip' );

		if ( false === $stream ) {
			return $this->error( 'Unable to open nested wp-content archive.' );
		}

		$tmp_root = WP_CONTENT_DIR . '/sitevault/tmp/import-validation';

		if ( ! wp_mkdir_p( $tmp_root ) ) {
			fclose( $stream );
			return $this->error( 'Unable to create temporary import validation directory.' );
		}

		$suffix = $import_id ? sanitize_key( $import_id ) : wp_generate_password( 10, false, false );
		$tmp    = $tmp_root . '/content-' . $suffix . '.zip';
		$out    = fopen( $tmp, 'wb' );

		if ( false === $out ) {
			fclose( $stream );
			return $this->error( 'Unable to create temporary nested archive.' );
		}

		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 1048576 );

			if ( false === $chunk ) {
				fclose( $stream );
				fclose( $out );
				@unlink( $tmp );
				return $this->error( 'Unable to read nested wp-content archive.' );
			}

			if ( '' !== $chunk && false === fwrite( $out, $chunk ) ) {
				fclose( $stream );
				fclose( $out );
				@unlink( $tmp );
				return $this->error( 'Unable to write temporary nested archive.' );
			}
		}

		fclose( $stream );
		fclose( $out );

		$nested = new ZipArchive();
		$open   = $nested->open( $tmp );

		if ( true !== $open ) {
			@unlink( $tmp );
			return $this->error( 'Nested wp-content archive is corrupt or unreadable.' );
		}

		$runtime_excluded = true;

		for ( $i = 0; $i < $nested->numFiles; $i++ ) {
			$name = $nested->getNameIndex( $i );

			if ( false === $name ) {
				$nested->close();
				@unlink( $tmp );
				return $this->error( 'Unable to inspect nested wp-content archive.' );
			}

			$normalized = ltrim( wp_normalize_path( $name ), '/' );

			if ( $this->is_unsafe_path( $normalized ) ) {
				$nested->close();
				@unlink( $tmp );
				return $this->error( 'Unsafe path detected inside wp-content archive: ' . $normalized );
			}

			if ( 'wp-content/sitevault' === $normalized || 0 === strpos( $normalized, 'wp-content/sitevault/' ) ) {
				$runtime_excluded = false;
				break;
			}
		}

		$entries = (int) $nested->numFiles;
		$nested->close();
		@unlink( $tmp );

		if ( ! $runtime_excluded ) {
			return $this->error( 'The package contains SiteVault runtime data inside wp-content.' );
		}

		$expected_files = (int) ( $manifest['payload']['wp_content']['files_archived'] ?? 0 );

		if ( $expected_files > 0 && $entries !== $expected_files ) {
			return $this->error( 'Nested wp-content entry count does not match the manifest.' );
		}

		return array(
			'success'          => true,
			'entries'          => $entries,
			'runtime_excluded' => true,
		);
	}

	private function validate_chunked_content( ZipArchive $outer, array $manifest ): array {
		$total = 0;
		foreach ( (array) ( $manifest['payload']['wp_content']['chunks'] ?? array() ) as $chunk ) {
			$file = (string) ( $chunk['file'] ?? '' );
			$stream = $outer->getStream( $file );
			if ( false === $stream ) return $this->error( 'Unable to open V2 content chunk: ' . $file );
			$tmp = wp_tempnam( basename( $file ) );
			$out = $tmp ? fopen( $tmp, 'wb' ) : false;
			if ( false === $out ) { fclose( $stream ); return $this->error( 'Unable to create temporary V2 chunk validation file.' ); }
			stream_copy_to_stream( $stream, $out ); fclose( $stream ); fclose( $out );
			$nested = new ZipArchive(); $open = $nested->open( $tmp );
			if ( true !== $open ) { @unlink($tmp); return $this->error( 'V2 content chunk is corrupt: ' . $file ); }
			for ( $i=0; $i<$nested->numFiles; $i++ ) {
				$name=ltrim(wp_normalize_path((string)$nested->getNameIndex($i)),'/');
				if($this->is_unsafe_path($name)||0!==strpos($name,'wp-content/')||'wp-content/sitevault'===rtrim($name,'/')||0===strpos($name,'wp-content/sitevault/')){$nested->close();@unlink($tmp);return $this->error('Unsafe path detected inside V2 content chunk.');}
			}
			$total += (int)$nested->numFiles; $nested->close(); @unlink($tmp);
		}
		$expected=(int)($manifest['payload']['wp_content']['files_archived']??0);
		if($expected>0&&$total!==$expected)return $this->error('V2 content chunk entry count does not match the manifest.');
		return array('success'=>true,'entries'=>$total,'runtime_excluded'=>true);
	}

	private function hash_zip_entry( ZipArchive $zip, string $entry ): array {
		$stream = $zip->getStream( $entry );

		if ( false === $stream ) {
			return $this->error( 'Unable to read package payload: ' . $entry );
		}

		$hash = hash_init( 'sha256' );
		$size = 0;

		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 1048576 );

			if ( false === $chunk ) {
				fclose( $stream );
				return $this->error( 'Unable to read package payload: ' . $entry );
			}

			if ( '' === $chunk ) {
				continue;
			}

			hash_update( $hash, $chunk );
			$size += strlen( $chunk );
		}

		fclose( $stream );

		return array(
			'success' => true,
			'sha256'  => hash_final( $hash ),
			'size'    => $size,
		);
	}

	private function is_unsafe_path( string $path ): bool {
		return '' === $path ||
			0 === strpos( $path, '/' ) ||
			false !== strpos( $path, '../' ) ||
			false !== strpos( $path, '..\\' ) ||
			(bool) preg_match( '/^[a-zA-Z]:[\\\\\/]/', $path );
	}

	private function error( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
