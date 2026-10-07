<?php
/**
 * Plugin Name: SiteVault – Backup, Restore & Migration
 * Description: Reliable WordPress backup, restore and migration with chunked processing and portable backup packages.
 * Version: 0.1.1-dev
 * Author: Digital Vampires Inc.
 * Text Domain: sitevault
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITEVAULT_VERSION', '0.1.1-dev' );
define( 'SITEVAULT_FILE', __FILE__ );
define( 'SITEVAULT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SITEVAULT_URL', plugin_dir_url( __FILE__ ) );

require_once SITEVAULT_PATH . 'includes/class-sitevault.php';

register_activation_hook( __FILE__, array( 'SiteVault', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SiteVault', 'deactivate' ) );

SiteVault::instance()->boot();
