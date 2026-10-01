<?php
/**
 * Uninstall is deliberately conservative: election records are public records and
 * must only be erased after an explicit opt-in in the plugin settings.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Estado do antigo relatório do Slack (removido do plugin). Não são registros eleitorais,
// então saem mesmo sem o opt-in abaixo.
foreach ( array( 'ae_slack_report_last_sent', 'ae_slack_report_problem_state', 'ae_slack_report_last_alert' ) as $legacy_option ) {
	delete_option( $legacy_option );
}

if ( ! get_option( 'ae_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;
$prefix = $wpdb->prefix . 'ae_';
foreach ( array( 'result_rows', 'snapshots', 'candidates', 'contests', 'elections', 'jobs', 'logs' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
delete_option( 'ae_delete_data_on_uninstall' );
