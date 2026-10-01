<?php
defined( 'ABSPATH' ) || exit;

final class AE_Job_Runner {
	private static $instance = null;
	/** A MySQL named lock is shared by CLI, WP-Cron and web workers without Redis. */
	private const LOCK_NAME = 'ae_apuracao_job_runner';
	/** Bounded opportunistic drain triggered by real page/REST traffic, so results keep moving even when WP-Cron's own loopback never fires (common on hosts that block self-requests) without needing any server-side cron setup. */
	private const KICK_BUDGET_SECONDS = 3;
	private const KICK_MIN_INTERVAL = 5;
	/** Completed/failed jobs older than this are removed so the queue table doesn't need manual cleanup. */
	private const JOB_RETENTION_DAYS = 30;
	private const PURGE_MIN_INTERVAL = DAY_IN_SECONDS;
	/** Sem nenhum tick por mais que isso, com disputas ligadas, a coleta é considerada parada. */
	private const TICK_STALE_SECONDS = 300;
	/** O cron de sistema dispara a cada minuto; passou disso sem tick de CLI, ele parou. */
	private const CLI_TICK_STALE_SECONDS = 120;

	public static function instance(): AE_Job_Runner { if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }

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

	/**
	 * Estado do disparo do worker, para a tela de saúde e a REST de saúde.
	 *
	 * Dois sinais separados de propósito: o WP-Cron por tráfego mantém "algum tick" fresco
	 * mesmo quando o cron de sistema (bin/tse-tick-loop.sh) morreu em silêncio, e é justamente
	 * esse caso que precisa aparecer. Por isso o tick vindo de CLI é registrado à parte.
	 *
	 * @return array{last_tick_at:?int,age:?int,source:string,last_cli_tick_at:?int,cli_age:?int,enabled_contests:int,stale:bool,cli_stopped:bool,cli_never:bool}
	 */
	public static function tick_status(): array {
		$last = (int) get_option( 'ae_last_tick_at', 0 );
		$last_cli = (int) get_option( 'ae_last_cli_tick_at', 0 );
		$age = $last ? time() - $last : null;
		$cli_age = $last_cli ? time() - $last_cli : null;
		$enabled = self::count_collecting_contests();
		$limit = (int) apply_filters( 'ae_tick_stale_seconds', self::TICK_STALE_SECONDS );
		$cli_limit = (int) apply_filters( 'ae_cli_tick_stale_seconds', self::CLI_TICK_STALE_SECONDS );
		return array(
			'last_tick_at'     => $last ?: null,
			'age'              => $age,
			'source'           => (string) get_option( 'ae_last_tick_source', '' ),
			'last_cli_tick_at' => $last_cli ?: null,
			'cli_age'          => $cli_age,
			'enabled_contests' => $enabled,
			// Nenhum tick (de qualquer origem) há tempo demais, com disputas ligadas para coletar.
			'stale'            => $enabled > 0 && null !== $age && $age > $limit,
			// O cron de sistema já funcionou neste site e parou: o caso silencioso que já aconteceu.
			'cli_stopped'      => $enabled > 0 && null !== $cli_age && $cli_age > $cli_limit,
			// Nunca houve tick de CLI: o site depende só do WP-Cron por tráfego.
			'cli_never'        => $enabled > 0 && null === $cli_age,
		);
	}

	/** Disputas que o worker de fato coleta (mesmo critério de enqueue_due_collections). */
	private static function count_collecting_contests(): int {
		global $wpdb;
		$rows = $wpdb->get_col( "SELECT config_json FROM {$wpdb->prefix}ae_contests WHERE active=1 AND config_json IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = 0;
		foreach ( $rows as $config_json ) {
			$config = json_decode( (string) $config_json, true );
			$collection = is_array( $config ) && is_array( $config['collection'] ?? null ) ? $config['collection'] : array();
			if ( ! empty( $collection['enabled'] ) && ! empty( $collection['source_url'] ) ) { $count++; }
		}
		return $count;
	}

	public function tick(): void {
		// Batimento registrado antes do lock: outro worker segurando o lock também prova que o disparo está vivo.
		$is_cli = 'cli' === PHP_SAPI;
		update_option( 'ae_last_tick_at', time(), false );
		update_option( 'ae_last_tick_source', $is_cli ? 'cli' : 'web', false );
		if ( $is_cli ) { update_option( 'ae_last_cli_tick_at', time(), false ); }
		if ( ! $this->acquire_lock() ) { return; }
		try {
			// Do not create or retry a burst of jobs while the TSE circuit breaker is active.
			if ( absint( get_option( 'ae_tse_blocked_until', 0 ) ) > time() ) { return; }
			$this->recover_expired_jobs();
			$this->enqueue_due_collections();
			$this->maybe_enqueue_scheduled_import();
			$this->maybe_purge_old_jobs();
			$started = microtime( true );
			$jobs = $this->drain( 40 );
			// Só registra ticks que trabalharam: um tick vazio (fila em dia) não diz nada sobre o tempo que o lock fica preso.
			if ( $jobs > 0 ) { AE_Perf::record( 'tick', microtime( true ) - $started, 0, $jobs ); }
		} finally { $this->release_lock(); }
	}

	/**
	 * Opportunistic, bounded drain called from real visitor/admin requests (REST results,
	 * admin pages). WP-Cron's scheduled event still does the full-size tick, but this gives
	 * the queue a way to advance even on hosts where WP-Cron's self-loopback request never
	 * fires (no crontab/DISABLE_WP_CRON change needed on any environment).
	 */
	public function kick(): void {
		$last = (int) get_option( 'ae_last_kick_at', 0 );
		if ( $last > time() - self::KICK_MIN_INTERVAL ) { return; }
		global $wpdb;
		$table = $wpdb->prefix . 'ae_jobs';
		$now = current_time( 'mysql', true );
		$pending = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE state IN ('queued','retry') AND run_after <= %s LIMIT 1", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $pending ) { return; }
		update_option( 'ae_last_kick_at', time(), false );
		// Callers only invoke kick() from 'shutdown', once the visitor's response is already
		// queued/sent; finish that connection now so this drain never adds request latency.
		if ( function_exists( 'fastcgi_finish_request' ) ) { fastcgi_finish_request(); }
		if ( ! $this->acquire_lock() ) { return; }
		try {
			if ( absint( get_option( 'ae_tse_blocked_until', 0 ) ) > time() ) { return; }
			$this->drain( self::KICK_BUDGET_SECONDS );
		} finally { $this->release_lock(); }
	}

	/** @return int Quantos jobs foram executados. */
	private function drain( int $seconds ): int {
		$deadline = microtime( true ) + $seconds;
		$ran = 0;
		while ( microtime( true ) < $deadline ) {
			// A 403/429 received during this same cycle opens the breaker immediately.
			if ( absint( get_option( 'ae_tse_blocked_until', 0 ) ) > time() ) { break; }
			$job = $this->claim();
			if ( ! $job ) { break; }
			$this->run( $job );
			$ran++;
		}
		return $ran;
	}

	/** Keeps the jobs table from growing without bound so nobody has to purge it by hand. */
	private function maybe_purge_old_jobs(): void {
		$last = (int) get_option( 'ae_last_purge_at', 0 );
		if ( $last > time() - self::PURGE_MIN_INTERVAL ) { return; }
		update_option( 'ae_last_purge_at', time(), false );
		global $wpdb;
		$table = $wpdb->prefix . 'ae_jobs';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::JOB_RETENTION_DAYS * DAY_IN_SECONDS );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE state IN ('completed','failed') AND updated_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $deleted ) {
			AE_Logger::write( 'info', 'old_jobs_purged', array( 'count' => (int) $deleted, 'retention_days' => self::JOB_RETENTION_DAYS ) );
		}
	}

	/**
	 * The default WordPress object cache is request-local, so wp_cache_add() cannot
	 * coordinate overlapping system-cron processes. The database is shared by all
	 * workers and therefore provides a real process-wide mutex.
	 */
	private function acquire_lock(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK_NAME ) );
	}

	private function release_lock(): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
	}

	/** Return work abandoned by a terminated PHP process to the normal retry path. */
	private function recover_expired_jobs(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ae_jobs';
		$now = current_time( 'mysql', true );
		$recovered = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET state='retry', run_after=%s, locked_until=NULL, lock_token=NULL, last_error=%s, updated_at=%s WHERE state='running' AND locked_until IS NOT NULL AND locked_until < %s",
			$now,
			'Worker interrompido; job recuperado automaticamente após expirar o lock.',
			$now,
			$now
		) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $recovered ) {
			AE_Logger::write( 'warning', 'expired_jobs_recovered', array( 'count' => (int) $recovered ) );
		}
	}

	/**
	 * Reimportação agendada dos candidatos. O TSE regenera o CSV todos os dias (renúncias,
	 * substituições); opt-in por eleição em ae_auto_import_election (0 = desligada). O intervalo
	 * padrão é de 6 h (4 por dia), ajustável por ae_auto_import_interval. Nunca importa o Brasil
	 * inteiro sozinho: sem disputa ligada, não agenda. O download do ZIP prende o worker enquanto
	 * dura, então convém desligar na noite da eleição.
	 */
	private function maybe_enqueue_scheduled_import(): void {
		$election_id = absint( get_option( 'ae_auto_import_election', 0 ) );
		if ( ! $election_id ) { return; }
		$interval = max( HOUR_IN_SECONDS, (int) apply_filters( 'ae_auto_import_interval', 6 * HOUR_IN_SECONDS ) );
		if ( (int) get_option( 'ae_auto_import_last_at', 0 ) > time() - $interval ) { return; }
		global $wpdb;
		$pending = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}ae_jobs WHERE type='import_candidates' AND state IN ('queued','running','retry') LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $pending ) { return; }
		$payload = AE_Collection_Policy::import_payload( $election_id );
		if ( ! $payload || empty( $payload['ufs'] ) ) { return; }
		// Marca antes de enfileirar: se a importação falhar, a próxima tentativa só vem no intervalo seguinte.
		update_option( 'ae_auto_import_last_at', time(), false );
		$id = self::enqueue( 'import_candidates', $payload );
		AE_Logger::write( 'info', 'candidate_import_scheduled', array( 'job_id' => $id, 'election_id' => $election_id, 'ufs' => $payload['ufs'] ) );
	}

	/** Enqueues one collection per due contest and avoids duplicates already in flight. */
	private function enqueue_due_collections(): void {
		global $wpdb;
		$p = $wpdb->prefix . 'ae_';
		$contests = $wpdb->get_results( "SELECT id,position_code,scope_code,config_json FROM {$p}contests WHERE active=1 AND config_json IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$multi_uf = AE_Collection_Policy::count_enabled_ufs( $contests ) > 1;
		// Leves (Presidente, Governador, Senador) entram na fila antes das pesadas (Deputados): a fila atende por run_after e id.
		usort( $contests, static function ( $a, $b ) {
			$weight = (int) AE_Collection_Policy::is_heavy( (string) $a->position_code ) - (int) AE_Collection_Policy::is_heavy( (string) $b->position_code );
			return 0 !== $weight ? $weight : (int) $a->id - (int) $b->id;
		} );
		foreach ( $contests as $contest ) {
			$config = json_decode( (string) $contest->config_json, true );
			$collection = is_array( $config ) ? ( $config['collection'] ?? array() ) : array();
			if ( empty( $collection['enabled'] ) || empty( $collection['source_url'] ) ) { continue; }
			$next_attempt = strtotime( (string) ( $collection['next_attempt_at'] ?? '' ) . ' UTC' );
			if ( $next_attempt && $next_attempt > time() ) { continue; }
			$interval = AE_Collection_Policy::clamp( absint( $collection['interval'] ?? AE_Collection_Policy::DEFAULT_INTERVAL ) );
			$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(captured_at) FROM {$p}snapshots WHERE contest_id=%d AND status='valid'", $contest->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $last && strtotime( $last . ' UTC' ) > time() - $interval ) { continue; }
			// The trailing "," or "}" stops "contest_id":1 from matching 10, 11, 100... which silently starves low-numbered contests forever.
			$id = (int) $contest->id;
			$pending = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}jobs WHERE type='collect_results' AND state IN ('queued','running','retry') AND (payload_json LIKE %s OR payload_json LIKE %s) LIMIT 1", '%"contest_id":' . $id . ',%', '%"contest_id":' . $id . '}%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $pending ) { continue; }
			// Com mais de uma UF, a pesada espera alguns segundos: uma leve que vença logo depois ainda passa à frente dela.
			$delay = $multi_uf && AE_Collection_Policy::is_heavy( (string) $contest->position_code ) ? AE_Collection_Policy::HEAVY_QUEUE_DELAY : 0;
			self::enqueue( 'collect_results', array( 'contest_id' => (int) $contest->id, 'kind' => strtoupper( sanitize_key( $collection['kind'] ?? 'EA20' ) ), 'source_url' => esc_url_raw( $collection['source_url'] ) ), array(), $delay );
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
		$started = microtime( true );
		try {
			$payload = json_decode( $job->payload_json, true );
			$cursor = $job->cursor_json ? json_decode( $job->cursor_json, true ) : array();
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) || ! is_array( $cursor ) ) { throw new RuntimeException( 'Payload ou cursor do job inválido.' ); }
			if ( 'import_candidates' === $job->type ) {
				$done = AE_TSE_Client::instance()->import_candidates_page( $payload, $cursor, $job->id );
			} elseif ( 'collect_results' === $job->type ) {
				$done = AE_TSE_Client::instance()->collect_results( $payload );
			} elseif ( 'sync_tse' === $job->type ) {
				$done = AE_TSE_Discovery::sync( $payload );
			} else {
				throw new RuntimeException( 'Unsupported job type: ' . $job->type );
			}
			if ( 'collect_results' === $job->type ) { AE_Perf::record( 'job', microtime( true ) - $started ); }
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
		AE_Logger::write( 'info', 'job_completed', array( 'job_id' => (int) $job->id, 'type' => $job->type ) );
	}

	private function retry_or_fail( object $job, Throwable $e ): void {
		global $wpdb; $t = $wpdb->prefix . 'ae_jobs'; $attempts = (int) $job->attempts;
		$state = $attempts >= 5 ? 'failed' : 'retry'; $delay = min( 900, 30 * ( 2 ** max( 0, $attempts - 1 ) ) );
		$wpdb->update( $t, array( 'state' => $state, 'run_after' => gmdate( 'Y-m-d H:i:s', time() + $delay ), 'locked_until' => null, 'lock_token' => null, 'last_error' => $e->getMessage(), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $job->id ), array( '%s','%s','%s','%s','%s','%s' ), array( '%d' ) );
		AE_Logger::write( 'error', 'job_' . $state, array( 'job_id' => (int) $job->id, 'error' => $e->getMessage() ) );
	}
}
