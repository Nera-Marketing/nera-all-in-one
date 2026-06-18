<?php
/**
 * Uninstall handler. Destructive — runs ONLY when explicitly opted in via
 * define( 'NERA_AIO_REMOVE_ALL_DATA', true ). Never touches module-owned data
 * in the MVP (modules keep their own data; downgrade/uninstall is data-safe).
 *
 * @package Nera\AllInOne
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'NERA_AIO_REMOVE_ALL_DATA' ) || ! NERA_AIO_REMOVE_ALL_DATA ) {
	return;
}

// Core options/transients owned by Nera All In One only.
delete_option( 'nera_aio_license_key' );
delete_option( 'nera_aio_entitlement_lkg' );
delete_transient( 'nera_aio_entitlement' );

// Per-module first-boot activation flags.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'nera_aio_module_activated_%'" );
