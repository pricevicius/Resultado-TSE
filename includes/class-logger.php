<?php
defined( 'ABSPATH' ) || exit;

final class TSE_Logger {
	public static function write( string $level, string $event, array $context = array() ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'ae_logs', array(
			'level' => substr( sanitize_key( $level ), 0, 10 ),
			'event' => substr( sanitize_key( $event ), 0, 80 ),
			'context_json' => wp_json_encode( $context ),
			'created_at' => current_time( 'mysql', true ),
		), array( '%s', '%s', '%s', '%s' ) );
	}
}
