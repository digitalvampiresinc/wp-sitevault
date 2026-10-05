<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteVault_Backup_Manifest {

	public static function create( string $backup_id ): array {
		global $wpdb;

		return array(
			'format'         => 'sitevault',
			'format_version' => 1,
			'backup_id'      => sanitize_key( $backup_id ),
			'created_at'     => gmdate( 'c' ),
			'site'           => array(
				'url'      => site_url(),
				'home_url' => home_url(),
			),
			'wordpress'      => array(
				'version'     => get_bloginfo( 'version' ),
				'content_dir' => WP_CONTENT_DIR,
			),
			'php'            => array(
				'version' => PHP_VERSION,
			),
			'database'       => array(
				'prefix'  => $wpdb->prefix,
				'charset' => $wpdb->charset,
				'collate' => $wpdb->collate,
			),
			'plugin'         => array(
				'version' => SITEVAULT_VERSION,
			),
		);
	}
}
