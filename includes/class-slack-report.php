<?php
defined( 'ABSPATH' ) || exit;

/**
 * Relatório de saúde da apuração pro Slack (#tribunaonline), reaproveitando
 * o mesmo Incoming Webhook do okn-elite-importer (option
 * okn_elite_slack_webhook_url). Não registra cron próprio: piggyback no
 * 'ae_run_jobs' que já dispara a cada minuto (AE_Job_Runner::tick()), e
 * decide internamente quando falar.
 *
 * Dois caminhos de envio:
 * - snapshot periódico (a cada 15 min, só quando há alguma eleição
 *   sincronizada — evita ruído em dias sem nada rodando);
 * - alerta imediato na transição de saudável -> problema (bloqueio do TSE
 *   ativo, ou fila com atraso generalizado), sem esperar o próximo slot de
 *   15 min. Um "cooldown" evita reenviar o mesmo alerta a cada minuto
 *   enquanto o problema persiste.
 */
final class AE_Slack_Report {
	private const SNAPSHOT_INTERVAL = 15 * MINUTE_IN_SECONDS;
	private const ALERT_COOLDOWN = 20 * MINUTE_IN_SECONDS;
	/** Mesmo limiar usado no incidente de 15/09 para considerar uma disputa "atrasada". */
	private const STALE_THRESHOLD = 3 * MINUTE_IN_SECONDS;

	public static function init(): void {
		add_action( 'ae_run_jobs', array( __CLASS__, 'maybe_report' ), 20 );
	}

	public static function maybe_report(): void {
		$webhook = trim( (string) get_option( 'okn_elite_slack_webhook_url', '' ) );
		if ( '' === $webhook ) { return; }

		$health = self::collect_health();
		if ( 0 === $health['elections'] ) { return; } // Nada sincronizado ainda: nada a reportar.
		if ( 0 === $health['contests'] ) { return; } // Sem disputa ativa não há coleta a monitorar: só ruído.

		self::maybe_send_alert( $webhook, $health );
		self::maybe_send_snapshot( $webhook, $health );
	}

	private static function maybe_send_snapshot( string $webhook, array $health ): void {
		$last = (int) get_option( 'ae_slack_report_last_sent', 0 );
		if ( time() - $last < self::SNAPSHOT_INTERVAL ) { return; }
		self::send( $webhook, self::format_snapshot( $health ) );
		update_option( 'ae_slack_report_last_sent', time(), false );
	}

	private static function maybe_send_alert( string $webhook, array $health ): void {
		$problem = $health['blocked_until'] > time() || $health['stale_contests'] >= 5 || $health['tick']['stale'] || $health['tick']['cli_stopped'];
		$was_problem = (bool) get_option( 'ae_slack_report_problem_state', false );

		if ( ! $problem ) {
			if ( $was_problem ) { update_option( 'ae_slack_report_problem_state', false, false ); }
			return;
		}

		$last_alert = (int) get_option( 'ae_slack_report_last_alert', 0 );
		if ( $was_problem && time() - $last_alert < self::ALERT_COOLDOWN ) { return; }

		self::send( $webhook, self::format_alert( $health ) );
		update_option( 'ae_slack_report_problem_state', true, false );
		update_option( 'ae_slack_report_last_alert', time(), false );
	}

	private static function collect_health(): array {
		global $wpdb;
		$p = $wpdb->prefix . 'ae_';

		$counts = array(
			'elections'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}elections" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'contests'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}contests WHERE active=1" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'candidates' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}candidates" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'snapshots'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}snapshots WHERE status='valid'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$jobs = $wpdb->get_row( "SELECT
			SUM(state IN ('queued','retry')) AS pending,
			SUM(state='failed') AS failed
			FROM {$p}jobs", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$stale = self::count_stale_contests();
		$last_snapshot = $wpdb->get_var( "SELECT MAX(captured_at) FROM {$p}snapshots WHERE status='valid'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_merge( $counts, array(
			'jobs_pending'   => (int) ( $jobs['pending'] ?? 0 ),
			'jobs_failed'    => (int) ( $jobs['failed'] ?? 0 ),
			'stale_contests' => $stale,
			'blocked_until'  => absint( get_option( 'ae_tse_blocked_until', 0 ) ),
			'environment'    => (string) get_option( 'ae_tse_environment', 'oficial' ),
			'last_snapshot'  => $last_snapshot ? (string) $last_snapshot : null,
			'tick'           => AE_Job_Runner::tick_status(),
		) );
	}

	/** Disputas gerenciadas, habilitadas, sem checagem recente há mais que o limiar do incidente de 15/09. */
	private static function count_stale_contests(): int {
		global $wpdb;
		$p = $wpdb->prefix . 'ae_';
		$rows = $wpdb->get_results( "SELECT id, config_json FROM {$p}contests WHERE active=1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stale = 0;
		$cutoff = time() - self::STALE_THRESHOLD;
		foreach ( $rows as $row ) {
			$config = json_decode( (string) $row->config_json, true );
			$collection = is_array( $config ) && is_array( $config['collection'] ?? null ) ? $config['collection'] : array();
			if ( empty( $collection['enabled'] ) || empty( $collection['managed'] ) ) { continue; }
			$checked = get_option( 'ae_result_checked_' . $row->id, '' );
			if ( '' === $checked || strtotime( $checked . ' UTC' ) < $cutoff ) { $stale++; }
		}
		return $stale;
	}

	private static function format_snapshot( array $h ): string {
		$lines = array();
		$lines[] = '*Apuração TSE — ' . current_time( 'd/m/Y H:i' ) . ' (' . esc_html( $h['environment'] ) . ')*';
		$lines[] = "• Eleições: *{$h['elections']}* · Disputas ativas: *{$h['contests']}* · Candidatos: *{$h['candidates']}*";
		$lines[] = "• Snapshots válidos: *{$h['snapshots']}*" . ( $h['last_snapshot'] ? " (último: {$h['last_snapshot']} UTC)" : '' );
		$fila_icon = $h['jobs_failed'] > 0 ? ' ⚠️' : '';
		$lines[] = "• Fila: *{$h['jobs_pending']}* pendente(s), *{$h['jobs_failed']}* falhada(s){$fila_icon}";
		$stale_icon = $h['stale_contests'] > 0 ? ' ⚠️' : ' ✅';
		$lines[] = "• Disputas atrasadas (>3min sem checagem): *{$h['stale_contests']}*{$stale_icon}";
		$tick = $h['tick'];
		if ( $tick['stale'] || $tick['cli_stopped'] ) {
			$lines[] = '• 🔴 Disparo da coleta parado (ver alerta)';
		} elseif ( null !== $tick['age'] ) {
			$lines[] = '• Disparo da coleta: último tick há *' . max( 0, (int) $tick['age'] ) . 's* ✅' . ( $tick['cli_never'] ? ' (só WP-Cron, sem cron de sistema)' : '' );
		}
		if ( $h['blocked_until'] > time() ) {
			$lines[] = '• 🔴 Coleta pausada pelo TSE até ' . gmdate( 'H:i:s', $h['blocked_until'] ) . ' UTC';
		}
		return implode( "\n", $lines );
	}

	private static function format_alert( array $h ): string {
		$lines = array( '*🔴 Alerta — Apuração TSE (' . current_time( 'd/m/Y H:i' ) . ')*' );
		if ( $h['blocked_until'] > time() ) {
			$lines[] = 'Coleta pausada pelo TSE (403/429) até ' . gmdate( 'H:i:s', $h['blocked_until'] ) . ' UTC. Nenhuma nova requisição está sendo feita até lá — repetir manualmente antes disso reinicia a pausa.';
		}
		if ( $h['stale_contests'] >= 5 ) {
			$lines[] = "*{$h['stale_contests']}* disputas sem checagem há mais de 3 minutos — mesmo padrão do incidente de 15/09 (fila represada). Ver Apuração → Fila e progresso.";
		}
		$tick = $h['tick'];
		if ( $tick['cli_stopped'] ) {
			$lines[] = 'O cron de sistema (bin/tse-tick-loop.sh) não dispara há *' . (int) $tick['cli_age'] . 's*. A coleta segue só pelo WP-Cron por tráfego, que é irregular. Causa comum: variável TSE_APURACAO_CONTAINER fora do crontab (o crontab não herda variáveis do shell) ou container renomeado. Confira se o log do tick está crescendo.';
		}
		if ( $tick['stale'] ) {
			$lines[] = 'Nenhum disparo do worker há *' . (int) $tick['age'] . 's* com disputas ligadas: os resultados publicados estão congelados.';
		}
		return implode( "\n", $lines );
	}

	private static function send( string $webhook, string $text ): void {
		$response = wp_remote_post( $webhook, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'text' => $text ) ),
			'timeout' => 10,
		) );
		if ( is_wp_error( $response ) ) {
			AE_Logger::write( 'error', 'slack_report_failed', array( 'error' => $response->get_error_message() ) );
		} else {
			AE_Logger::write( 'info', 'slack_report_sent', array( 'code' => wp_remote_retrieve_response_code( $response ) ) );
		}
	}
}
