<?php
defined( 'ABSPATH' ) || exit;

final class TSE_Job_Runner {
	private static ?TSE_Job_Runner $instance = null;
	private const LOCK_KEY = 'ae_job_runner_lock';
	private const LOCK_TTL = 55;

	public static function instance(): TSE_Job_Runner { return self::$instance ??= new self(); }

	public static function enqueue( string $type, array $payload, array $cursor = array(), int $delay = 0 ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( $wpdb->prefix . 'ae_jobs', array(
			'type' => sanitize_key( $type ), 'payload_json' => wp_json_encode( $payload ), 'cursor_json' => wp_json_encode( $cursor ),
			'state' => 'queued', 'attempts' => 0, 'run_after' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
			'created_at' => $now, 'updated_at' => $now,
		), array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	public function tick(): void {
		if ( ! wp_cache_add( self::LOCK_KEY, 1, 'apuracao-eleitoral', self::LOCK_TTL ) ) { return; }
		try {
			// Do not create or retry a burst of jobs while the TSE circuit breaker is active.
			if ( absint( get_option( 'ae_tse_blocked_until', 0 ) ) > time() ) { return; }
			$this->enqueue_due_collections();
			$deadline = microtime( true ) + 40;
			while ( microtime( true ) < $deadline ) {
				// A 403/429 received during this same cycle opens the breaker immediately.
				if ( absint( get_option( 'ae_tse_blocked_until', 0 ) ) > time() ) { break; }
				$job = $this->claim();
				if ( ! $job ) { break; }
				$this->run( $job );
			}
		} finally { wp_cache_delete( self::LOCK_KEY, 'apuracao-eleitoral' ); }
	}

	/** Enqueues one collection per due contest and avoids duplicates already in flight. */
	private function enqueue_due_collections(): void {
		global $wpdb;
		$p = $wpdb->prefix . 'ae_';
		$contests = $wpdb->get_results( "SELECT id,config_json FROM {$p}contests WHERE active=1 AND config_json IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $contests as $contest ) {
			$config = json_decode( (string) $contest->config_json, true );
			$collection = is_array( $config ) ? ( $config['collection'] ?? array() ) : array();
			if ( empty( $collection['enabled'] ) || empty( $collection['source_url'] ) ) { continue; }
			$interval = max( 30, min( 900, absint( $collection['interval'] ?? 60 ) ) );
			$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(captured_at) FROM {$p}snapshots WHERE contest_id=%d AND status='valid'", $contest->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $last && strtotime( $last . ' UTC' ) > time() - $interval ) { continue; }
			$needle = '%"contest_id":' . (int) $contest->id . '%';
			$pending = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}jobs WHERE type='collect_results' AND state IN ('queued','running','retry') AND payload_json LIKE %s LIMIT 1", $needle ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $pending ) { continue; }
			self::enqueue( 'collect_results', array( 'contest_id' => (int) $contest->id, 'kind' => strtoupper( sanitize_key( $collection['kind'] ?? 'EA20' ) ), 'source_url' => esc_url_raw( $collection['source_url'] ) ) );
		}
	}

	private function claim(): ?object {
		global $wpdb;
		$t = $wpdb->prefix . 'ae_jobs'; $now = current_time( 'mysql', true ); $token = wp_generate_uuid4();
		// The conditional UPDATE makes claim safe across concurrent cron/web workers.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE state IN ('queued','retry') AND run_after <= %s AND (locked_until IS NULL OR locked_until < %s) ORDER BY run_after ASC, id ASC LIMIT 1", $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $id ) { return null; }
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET state='running', lock_token=%s, locked_until=%s, attempts=attempts+1, updated_at=%s WHERE id=%d AND state IN ('queued','retry') AND (locked_until IS NULL OR locked_until < %s)", $token, gmdate( 'Y-m-d H:i:s', time() + 600 ), $now, $id, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $updated ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d AND lock_token=%s", $id, $token ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function run( object $job ): void {
		try {
			$payload = json_decode( $job->payload_json, true, 512, JSON_THROW_ON_ERROR );
			$cursor = $job->cursor_json ? json_decode( $job->cursor_json, true, 512, JSON_THROW_ON_ERROR ) : array();
			$done = match ( $job->type ) {
				'import_candidates' => TSE_Client::instance()->import_candidates_page( $payload, $cursor, $job->id ),
				'collect_results' => TSE_Client::instance()->collect_results( $payload ),
				'sync_tse' => TSE_Discovery::sync( $payload ),
				default => throw new RuntimeException( 'Unsupported job type: ' . $job->type ),
			};
			$this->finish( $job, $done );
		} catch ( Throwable $e ) {
			$this->retry_or_fail( $job, $e );
		}
	}

	private function finish( object $job, array $result ): void {
		global $wpdb; $t = $wpdb->prefix . 'ae_jobs'; $now = current_time( 'mysql', true );
		if ( empty( $result['complete'] ) ) {
			$wpdb->update( $t, array( 'state' => 'queued', 'cursor_json' => wp_json_encode( $result['cursor'] ?? array() ), 'run_after' => $now, 'locked_until' => null, 'lock_token' => null, 'updated_at' => $now ), array( 'id' => $job->id ), array( '%s','%s','%s','%s','%s','%s' ), array( '%d' ) );
			return;
		}
		$wpdb->update( $t, array( 'state' => 'completed', 'locked_until' => null, 'lock_token' => null, 'updated_at' => $now ), array( 'id' => $job->id ), array( '%s','%s','%s','%s' ), array( '%d' ) );
		TSE_Logger::write( 'info', 'job_completed', array( 'job_id' => (int) $job->id, 'type' => $job->type ) );
	}

	private function retry_or_fail( object $job, Throwable $e ): void {
		global $wpdb; $t = $wpdb->prefix . 'ae_jobs'; $attempts = (int) $job->attempts;
		$state = $attempts >= 5 ? 'failed' : 'retry'; $delay = min( 900, 30 * ( 2 ** max( 0, $attempts - 1 ) ) );
		$wpdb->update( $t, array( 'state' => $state, 'run_after' => gmdate( 'Y-m-d H:i:s', time() + $delay ), 'locked_until' => null, 'lock_token' => null, 'last_error' => $e->getMessage(), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id ), array( '%s','%s','%s','%s','%s','%s' ), array( '%d' ) );
		TSE_Logger::write( 'error', 'job_' . $state, array( 'job_id' => (int) $job->id, 'error' => $e->getMessage() ) );
	}
}
