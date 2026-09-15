<?php
defined( 'ABSPATH' ) || exit;

final class AE_Schema {
	public const VERSION = '2.0.2';

	/** Checks both the migration marker and the physical tables before use. */
	public static function is_ready(): bool {
		global $wpdb;
		$p = $wpdb->prefix . 'ae_';
		$required = array( 'elections', 'contests', 'candidates', 'snapshots', 'result_rows', 'jobs', 'logs' );
		foreach ( $required as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $table ) ) !== $p . $table ) { return false; }
		}
		return self::VERSION === get_option( 'ae_schema_version' );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p = $wpdb->prefix . 'ae_';
		$queries = array(
			"CREATE TABLE {$p}elections (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(100) NOT NULL,
				name varchar(190) NOT NULL,
				year smallint unsigned NOT NULL,
				timezone varchar(64) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				config_json longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY year_status (year,status)
			) {$charset};",
			"CREATE TABLE {$p}contests (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				election_id bigint unsigned NOT NULL,
				external_id varchar(80) NOT NULL,
				round_no tinyint unsigned NOT NULL,
				position_code varchar(20) NOT NULL,
				position_name varchar(100) NOT NULL,
				scope_type varchar(10) NOT NULL,
				scope_code varchar(20) NOT NULL,
				scope_name varchar(100) NOT NULL,
				seats tinyint unsigned NOT NULL DEFAULT 1,
				active tinyint(1) NOT NULL DEFAULT 1,
				config_json text NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY election_external (election_id,external_id),
				KEY lookup (election_id,round_no,position_code,scope_code),
				KEY active (election_id,active)
			) {$charset};",
			"CREATE TABLE {$p}candidates (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				election_id bigint unsigned NOT NULL,
				external_id varchar(80) NOT NULL,
				contest_id bigint unsigned NULL,
				ballot_name varchar(150) NOT NULL,
				full_name varchar(190) NOT NULL,
				ballot_number varchar(20) NOT NULL,
				party varchar(30) NULL,
				situation varchar(80) NULL,
				photo_url varchar(500) NULL,
				data_json longtext NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY election_candidate (election_id,external_id),
				KEY contest_number (contest_id,ballot_number),
				KEY election_name (election_id,ballot_name(80))
			) {$charset};",
			"CREATE TABLE {$p}snapshots (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				contest_id bigint unsigned NOT NULL,
				source varchar(20) NOT NULL,
				source_url varchar(1000) NOT NULL,
				source_sha256 char(64) NOT NULL,
				captured_at datetime NOT NULL,
				generated_at datetime NULL,
				sequence_no bigint unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL,
				totals_json longtext NOT NULL,
				raw_json longtext NULL,
				valid_until datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY contest_sequence (contest_id,sequence_no),
				KEY latest_valid (contest_id,status,captured_at),
				KEY captured (captured_at)
			) {$charset};",
			"CREATE TABLE {$p}result_rows (
				snapshot_id bigint unsigned NOT NULL,
				candidate_id bigint unsigned NULL,
				external_candidate_id varchar(80) NOT NULL,
				rank_no smallint unsigned NULL,
				votes bigint unsigned NOT NULL DEFAULT 0,
				percentage decimal(7,4) NOT NULL DEFAULT 0,
				elected tinyint(1) NOT NULL DEFAULT 0,
				situation varchar(80) NULL,
				PRIMARY KEY  (snapshot_id,external_candidate_id),
				KEY candidate (candidate_id),
				KEY ranking (snapshot_id,rank_no)
			) {$charset};",
			"CREATE TABLE {$p}jobs (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				type varchar(40) NOT NULL,
				payload_json longtext NOT NULL,
				cursor_json longtext NULL,
				state varchar(20) NOT NULL DEFAULT 'queued',
				attempts tinyint unsigned NOT NULL DEFAULT 0,
				run_after datetime NOT NULL,
				locked_until datetime NULL,
				lock_token char(36) NULL,
				last_error text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY runnable (state,run_after,locked_until),
				KEY lock_token_idx (lock_token)
			) {$charset};",
			"CREATE TABLE {$p}logs (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				level varchar(10) NOT NULL,
				event varchar(80) NOT NULL,
				context_json longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event_date (event,created_at),
				KEY level_date (level,created_at)
			) {$charset};",
		);
		foreach ( $queries as $query ) { dbDelta( $query ); }
		$required = array( 'elections', 'contests', 'candidates', 'snapshots', 'result_rows', 'jobs', 'logs' );
		$missing = array_filter( $required, static fn( string $table ): bool => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $table ) ) !== $p . $table );
		if ( $missing ) {
			delete_option( 'ae_schema_version' );
			error_log( 'Apuracao Eleitoral: tabelas nao criadas: ' . implode( ', ', $missing ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}
		update_option( 'ae_schema_version', self::VERSION, false );
	}
}
