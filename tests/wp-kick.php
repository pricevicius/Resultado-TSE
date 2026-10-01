<?php
/**
 * kick() sem WP-Cron: com a fila vazia ele precisa criar os jobs de coleta devidos (antes só drenava
 * jobs que já existiam, e sem o tick do WP-Cron a coleta nunca nascia). Sem rede: o HTTP do TSE é interceptado.
 *
 *   wp eval-file wp-content/plugins/tse-apuracao/tests/wp-kick.php
 *
 * Cria uma eleição e uma disputa temporárias (UF fictícia ZY). No final remove tudo e restaura a configuração das
 * disputas e as opções de kick (o kick também enfileira as disputas reais do ambiente; esses jobs são apagados).
 */
$failures = array();
$check = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' — ' . $detail ) . "\n";
	if ( ! $ok ) { $failures[] = $name; }
};
global $wpdb;
$p = $wpdb->prefix . 'ae_';
$now = current_time( 'mysql', true );
$year = (int) ( get_option( 'tse_apuracao_settings', array() )['ano'] ?? AE_Plugin::default_election_year() );
$url = 'https://resultados.tse.jus.br/oficial/ele' . $year . '/9999/dados/zy/zy-c0003-e009999-u.json';
$saved_options = array();
foreach ( array( 'ae_last_kick_at', 'ae_last_kick_enqueue_at', 'ae_tse_blocked_until' ) as $name ) { $saved_options[ $name ] = get_option( $name, null ); }
$saved_configs = $wpdb->get_results( "SELECT id, config_json FROM {$p}contests", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$max_job = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$p}jobs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$wpdb->insert( $p . 'elections', array( 'slug' => 'ae-test-kick', 'name' => 'Teste kick', 'year' => $year, 'timezone' => 'America/Sao_Paulo', 'status' => 'active', 'config_json' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
$eid = (int) $wpdb->insert_id;
$config = array( 'collection' => array( 'enabled' => true, 'managed' => true, 'kind' => 'EA20', 'source_url' => $url, 'interval' => 60, 'interval_mode' => 'auto' ) );
$wpdb->insert( $p . 'contests', array( 'election_id' => $eid, 'external_id' => 'ae-test-kick', 'round_no' => 1, 'position_code' => '0003', 'position_name' => 'Governador', 'scope_type' => 'UF', 'scope_code' => 'ZY', 'scope_name' => 'Teste', 'seats' => 1, 'active' => 1, 'config_json' => wp_json_encode( $config ) ) );
$cid = (int) $wpdb->insert_id;

// TSE falso: 404 sem corpo (a coleta trata com backoff). Nenhuma requisição real sai.
$requests = 0;
$fake = static function ( $pre, $args, $request_url ) use ( &$requests ) {
	if ( false === strpos( (string) $request_url, 'tse.jus.br' ) ) { return $pre; }
	$requests++;
	return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $fake, 10, 3 );
$jobs_for = static function () use ( $wpdb, $p, $cid ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}jobs WHERE type='collect_results' AND (payload_json LIKE %s OR payload_json LIKE %s)", '%"contest_id":' . $cid . ',%', '%"contest_id":' . $cid . '}%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};
$reset = static function (): void { delete_option( 'ae_last_kick_at' ); delete_option( 'ae_last_kick_enqueue_at' ); delete_option( 'ae_tse_blocked_until' ); };

// 1) Fila sem nenhum job pendente + disputa ligada e devida => o kick cria o job (e o executa).
$wpdb->query( "DELETE FROM {$p}jobs WHERE state IN ('queued','retry')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$reset();
$check( 'K1: antes do kick não há job da disputa', 0 === $jobs_for() );
AE_Job_Runner::instance()->kick();
$check( 'K2: kick com fila vazia enfileira a coleta devida', 1 === $jobs_for(), 'jobs=' . $jobs_for() );
$check( 'K3: o job foi processado pelo mesmo kick (TSE falso acionado)', $requests >= 1, 'requests=' . $requests );

// 2) Duas chamadas seguidas: a segunda é barrada pelo intervalo mínimo e não duplica o job.
$before = $jobs_for();
AE_Job_Runner::instance()->kick();
$check( 'K4: kick repetido dentro do intervalo não duplica job', $before === $jobs_for() );

// 3) Verificação de disputas devidas é limitada no tempo com a fila vazia (não consulta ae_contests a cada visita).
$wpdb->query( "DELETE FROM {$p}jobs WHERE state IN ('queued','retry')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
delete_option( 'ae_last_kick_at' );
update_option( 'ae_last_kick_enqueue_at', time(), false );
$wpdb->update( $p . 'contests', array( 'config_json' => wp_json_encode( $config ) ), array( 'id' => $cid ) );
AE_Job_Runner::instance()->kick();
$check( 'K5: com a checagem recente e a fila vazia, o kick não enfileira de novo', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}jobs WHERE state IN ('queued','retry')" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// 4) Circuit breaker do TSE aberto: o kick não cria nem executa nada.
$reset();
update_option( 'ae_tse_blocked_until', time() + 600, false );
$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}jobs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
AE_Job_Runner::instance()->kick();
$check( 'K6: com o breaker do TSE aberto o kick não enfileira', $count_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}jobs" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// 5) run_now() (ação do admin) não lança exceção e registra batimento do tick.
$reset();
delete_option( 'ae_last_tick_at' );
AE_Job_Runner::instance()->run_now( 5 );
$check( 'K7: run_now registra o tick', (int) get_option( 'ae_last_tick_at', 0 ) > 0 );

// 6) Diagnóstico de loopback devolve a estrutura esperada.
delete_transient( 'ae_loopback_status' );
$loop = AE_Job_Runner::loopback_status();
$check( 'K8: loopback_status devolve ok/error/url', array_key_exists( 'ok', $loop ) && array_key_exists( 'error', $loop ) && '' !== $loop['url'] );
delete_transient( 'ae_loopback_status' );

// Limpeza.
remove_filter( 'pre_http_request', $fake, 10 );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}jobs WHERE id > %d", $max_job ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}logs WHERE event IN ('job_failed','job_retry') AND created_at >= %s", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->delete( $p . 'contests', array( 'id' => $cid ) );
$wpdb->delete( $p . 'elections', array( 'id' => $eid ) );
foreach ( $saved_configs as $row ) { $wpdb->update( $p . 'contests', array( 'config_json' => $row->config_json ), array( 'id' => (int) $row->id ) ); }
foreach ( $saved_options as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value, false ); }

echo "\n" . ( $failures ? count( $failures ) . ' falha(s): ' . implode( '; ', $failures ) : 'Todos os testes passaram.' ) . "\n";
exit( $failures ? 1 : 0 );
