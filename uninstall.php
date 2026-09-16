<?php
/**
 * Uninstall is deliberately conservative: election records are public records and
 * must only be erased after an explicit opt-in in the plugin settings.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'ae_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;
$prefix = $wpdb->prefix . 'ae_';
foreach ( array( 'result_rows', 'snapshots', 'candidates', 'contests', 'elections', 'jobs', 'logs' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
delete_option( 'ae_delete_data_on_uninstall' );
