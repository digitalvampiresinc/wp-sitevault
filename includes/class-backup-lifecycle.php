<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Backup_Lifecycle {

	private function backup_root(): string {
		return WP_CONTENT_DIR . '/sitevault/backups';
	}

	private function backup_dir( string $backup_id ): ?string {
		$backup_id = sanitize_key( $backup_id );

		if ( ! preg_match( '/^sv-[a-z0-9-]+$/', $backup_id ) ) {
			return null;
		}

		$root = realpath( $this->backup_root() );

		if ( false === $root ) {
			return null;
		}

		$dir = $root . '/' . $backup_id;

		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$real = realpath( $dir );

		if ( false === $real || 0 !== strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
			return null;
		}

		return $real;
	}

	public function resume( string $backup_id ): array {
		$dir = $this->backup_dir( $backup_id );

		if ( null === $dir ) {
			return $this->error( 'Backup could not be found.' );
		}

		$db_file      = trailingslashit( $dir ) . 'database/export-state.json';
		$content_file = trailingslashit( $dir ) . 'content/archive-state.json';
		$package_file = trailingslashit( $dir ) . 'package-state.json';

		$db      = $this->read_json( $db_file );
		$content = $this->read_json( $content_file );
		$package = $this->read_json( $package_file );

		if ( 'complete' !== ( $db['status'] ?? '' ) ) {
			if ( empty( $db ) ) {
				return $this->error( 'Database export state is missing; this backup cannot be resumed safely.' );
			}
			$db['status']     = 'running';
			$db['error']      = null;
			$db['updated_at'] = gmdate( 'c' );
			if ( ! $this->write_json( $db_file, $db ) ) {
				return $this->error( 'Unable to reset database export state for resume.' );
			}
		}

		if ( 'complete' !== ( $content['status'] ?? '' ) ) {
			if ( empty( $content ) ) {
				return $this->error( 'wp-content archive state is missing; this backup cannot be resumed safely.' );
			}
			$content['status']     = 'running';
			$content['error']      = null;
			$content['updated_at'] = gmdate( 'c' );
			if ( ! $this->write_json( $content_file, $content ) ) {
				return $this->error( 'Unable to reset wp-content state for resume.' );
			}
		}

		if ( 'failed' === ( $package['status'] ?? '' ) ) {
			@unlink( $package_file );
		}

		update_option( 'sitevault_active_backup_id', $backup_id, false );

		return array(
			'success'   => true,
			'backup_id' => $backup_id,
			'message'   => 'Backup marked ready to resume from its saved progress.',
		);
	}

	public function discard_active( string $backup_id ): array {
		$active = sanitize_key( (string) get_option( 'sitevault_active_backup_id', '' ) );

		if ( $active !== sanitize_key( $backup_id ) ) {
			return $this->error( 'This backup is not the active SiteVault job.' );
		}

		delete_option( 'sitevault_active_backup_id' );

		return array(
			'success' => true,
			'message' => 'Active backup was discarded from the worker queue. Its files remain in Backup History until deleted.',
		);
	}

	public function delete( string $backup_id ): array {
		$dir = $this->backup_dir( $backup_id );

		if ( null === $dir ) {
			return $this->error( 'Backup could not be found.' );
		}

		$active = sanitize_key( (string) get_option( 'sitevault_active_backup_id', '' ) );

		if ( $active === sanitize_key( $backup_id ) ) {
			delete_option( 'sitevault_active_backup_id' );
		}

		$removed = $this->remove_tree( $dir );

		if ( ! $removed['success'] ) {
			return $removed;
		}

		return array(
			'success' => true,
			'message' => 'Backup deleted permanently.',
		);
	}

	public function restart( string $backup_id ): array {
		$deleted = $this->delete( $backup_id );

		if ( ! $deleted['success'] ) {
			return $deleted;
		}

		$manager = new SiteVault_Backup_Manager();
		$result  = $manager->create_backup();

		if ( ! $result['success'] ) {
			return $result;
		}

		update_option( 'sitevault_active_backup_id', $result['backup_id'], false );

		return array(
			'success'   => true,
			'backup_id' => $result['backup_id'],
			'message'   => 'Previous backup was removed and a fresh backup was started.',
		);
	}

	private function read_json( string $file ): array {
		if ( ! is_readable( $file ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : array();
	}

	private function write_json( string $file, array $data ): bool {
		return false !== file_put_contents(
			$file,
			wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			LOCK_EX
		);
	}

	private function remove_tree( string $path ): array {
		$root = realpath( $this->backup_root() );
		$real = realpath( $path );

		if ( false === $root || false === $real ) {
			return $this->error( 'Backup path could not be resolved.' );
		}

		if ( 0 !== strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
			return $this->error( 'Refusing to delete a directory outside SiteVault backup storage.' );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isLink() || $item->isFile() ) {
				if ( ! @unlink( $item->getPathname() ) ) {
					return $this->error( 'Unable to delete backup file: ' . $item->getFilename() );
				}
			} elseif ( ! @rmdir( $item->getPathname() ) ) {
				return $this->error( 'Unable to delete backup directory: ' . $item->getFilename() );
			}
		}

		if ( ! @rmdir( $real ) ) {
			return $this->error( 'Unable to remove backup directory.' );
		}

		return array( 'success' => true );
	}

	private function error( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
