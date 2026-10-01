<?php
/**
 * Concorrência do worker: vários processos disputando a mesma fila ao mesmo tempo (o cenário do cron de
 * sistema + WP-Cron + visitas). Use pelo tests/run-concurrency.sh, que chama este arquivo em quatro modos
 * (TSE_CONC_MODE = setup | work | check | teardown). O TSE é falso (sem rede) e tem latência simulada.
 * Confere: cada job roda uma vez só, cada disputa ganha um único snapshot, o lock do worker nunca é
 * segurado por dois processos ao mesmo tempo e a fila termina vazia.
 *
 * Cria eleição e disputas temporárias (UFs fictícias ZC1..ZC24) e remove tudo no teardown.
 */
global $wpdb;
$p = $wpdb->prefix . 'ae_';
$mode = (string) getenv( 'TSE_CONC_MODE' );
$n_contests = 24;
$state_file = sys_get_temp_dir() . '/ae-conc-state.json';
$url_for = static function ( int $i ): string { return 'https://resultados.tse.jus.br/oficial/ele2026/9999/dados/zc/zc-c0003-e0099' . sprintf( '%02d', $i ) . '-u.json'; };

if ( 'setup' === $mode ) {
	$now = current_time( 'mysql', true );
	$wpdb->insert( $p . 'elections', array( 'slug' => 'ae-test-conc', 'name' => 'Teste concorrência', 'year' => 2026, 'timezone' => 'America/Sao_Paulo', 'status' => 'active', 'config_json' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
	$eid = (int) $wpdb->insert_id;
	$ids = array();
	for ( $i = 1; $i <= $n_contests; $i++ ) {
		$wpdb->insert( $p . 'contests', array( 'election_id' => $eid, 'external_id' => 'ae-test-conc-' . $i, 'round_no' => 1, 'position_code' => '0003', 'position_name' => 'Teste', 'scope_type' => 'UF', 'scope_code' => 'ZC' . $i, 'scope_name' => 'Teste', 'seats' => 1, 'active' => 1, 'config_json' => wp_json_encode( array( 'collection' => array( 'enabled' => true, 'kind' => 'EA20', 'source_url' => $url_for( $i ), 'interval' => 60 ) ) ) ) );
		$cid = (int) $wpdb->insert_id;
		$ids[] = $cid;
		AE_Job_Runner::enqueue( 'collect_results', array( 'contest_id' => $cid, 'kind' => 'EA20', 'source_url' => $url_for( $i ) ) );
	}
	file_put_contents( $state_file, wp_json_encode( array( 'eid' => $eid, 'ids' => $ids ) ) );
	array_map( 'unlink', glob( sys_get_temp_dir() . '/ae-conc-worker-*.json' ) ?: array() );
	echo "setup: $n_contests disputas e $n_contests jobs\n";
	return;
}

$state = json_decode( (string) @file_get_contents( $state_file ), true );
if ( ! is_array( $state ) ) { echo "FAIL sem estado: rode o setup antes\n"; exit( 1 ); }
$eid = (int) $state['eid']; $ids = array_map( 'intval', $state['ids'] );
$id_list = implode( ',', $ids );

if ( 'work' === $mode ) {
	$by_url = array(); foreach ( $ids as $k => $id ) { $by_url[ $url_for( $k + 1 ) ] = $k + 1; }
	add_filter( 'pre_http_request', static function ( $pre, $args, $request_url ) use ( $by_url ) {
		if ( ! isset( $by_url[ $request_url ] ) ) { return new WP_Error( 'blocked', 'Teste bloqueou rede real: ' . $request_url ); }
		usleep( 100000 ); // 100 ms de rede por requisição
		$base = 900000 + $by_url[ $request_url ] * 100;
		$cands = array(); for ( $i = 1; $i <= 12; $i++ ) { $cands[] = array( 'n' => (string) ( 10 + $i ), 'sqcand' => (string) ( $base + $i ), 'nm' => 'Candidato ' . $i, 'nmu' => 'CAND ' . $i, 'seq' => (string) $i, 'e' => 'n', 'st' => 'Não eleito', 'vap' => (string) ( 1000 - $i ), 'pvap' => '1,00' ); }
		$doc = array( 'ele' => '9999', 't' => '1', 'f' => 's', 'tpabr' => 'uf', 'cdabr' => 'zc', 'dg' => '28/09/2026', 'hg' => '19:00:00', 'idg' => 'c', 'tf' => 'n', 'and' => 'p', 'md' => 'n',
			'carg' => array( array( 'cd' => '3', 'nv' => '1', 'agr' => array( array( 'tp' => 'i', 'par' => array( array( 'sg' => 'AAA', 'cand' => $cands ) ) ) ) ) ),
			's' => array( 'ts' => '2500', 'st' => '1250', 'pst' => '50,00', 'snt' => '0' ), 'e' => array( 'te' => '1500000', 'est' => '0' ),
			'v' => array( 'tv' => '820000', 'vv' => '780000', 'vb' => '20000', 'vn' => '15000', 'van' => '5000', 'pvb' => '2,44', 'pvn' => '1,83', 'pvan' => '0,61' ) );
		return array( 'headers' => array(), 'body' => wp_json_encode( $doc ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	}, 10, 3 );
	$runner = AE_Job_Runner::instance();
	$acquire = new ReflectionMethod( $runner, 'acquire_lock' ); $acquire->setAccessible( true );
	$release = new ReflectionMethod( $runner, 'release_lock' ); $release->setAccessible( true );
	$drain = new ReflectionMethod( $runner, 'drain' ); $drain->setAccessible( true );
	$holds = array(); $ran = 0;
	for ( $round = 0; $round < 60; $round++ ) {
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}jobs WHERE type='collect_results' AND state IN ('queued','retry','running') AND payload_json REGEXP '\"contest_id\":(" . implode( '|', $ids ) . ")[,}]'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === $left ) { break; }
		if ( ! $acquire->invoke( $runner ) ) { usleep( 50000 + mt_rand( 0, 100000 ) ); continue; }
		$start = microtime( true );
		try { $ran += (int) $drain->invoke( $runner, 2 ); } finally { $release->invoke( $runner ); }
		$holds[] = array( $start, microtime( true ) );
		usleep( mt_rand( 0, 50000 ) );
	}
	file_put_contents( sys_get_temp_dir() . '/ae-conc-worker-' . getmypid() . '.json', wp_json_encode( array( 'ran' => $ran, 'holds' => $holds ) ) );
	echo "worker " . getmypid() . ": $ran jobs em " . count( $holds ) . " turnos de lock\n";
	return;
}

$failures = array();
$check = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' — ' . $detail ) . "\n";
	if ( ! $ok ) { $failures[] = $name; }
};
if ( 'check' === $mode ) {
	$n = count( $ids );
	$workers = array_filter( array_map( static function ( $f ) { return json_decode( (string) file_get_contents( $f ), true ); }, glob( sys_get_temp_dir() . '/ae-conc-worker-*.json' ) ?: array() ) );
	$total_ran = array_sum( array_column( $workers, 'ran' ) );
	$check( 'quatro workers rodaram', count( $workers ) >= 4, (string) count( $workers ) );
	$check( "cada um dos $n jobs foi executado uma única vez (soma dos workers)", $n === $total_ran, (string) $total_ran );
	$snap = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}snapshots WHERE contest_id IN ({$id_list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( "$n disputas = $n snapshots, nenhum duplicado", $n === $snap, (string) $snap );
	$bad_seq = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT contest_id FROM {$p}snapshots WHERE contest_id IN ({$id_list}) GROUP BY contest_id HAVING COUNT(*) <> 1 OR MAX(sequence_no) <> 1) t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'cada disputa tem um snapshot com sequence_no 1', 0 === $bad_seq, (string) $bad_seq );
	$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}result_rows r JOIN {$p}snapshots s ON s.id=r.snapshot_id WHERE s.contest_id IN ({$id_list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'ranking completo: 12 linhas por snapshot', 12 * $n === $rows, (string) $rows );
	$jobs = $wpdb->get_results( "SELECT state,attempts FROM {$p}jobs WHERE type='collect_results' AND payload_json REGEXP '\"contest_id\":(" . implode( '|', $ids ) . ")[,}]'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'todos os jobs terminaram "completed"', $n === count( $jobs ) && ! array_filter( $jobs, static function ( $j ) { return 'completed' !== $j->state; }) );
	$check( 'nenhum job foi tentado mais de uma vez', ! array_filter( $jobs, static function ( $j ) { return (int) $j->attempts !== 1; }) );
	$intervals = array(); foreach ( $workers as $w ) { foreach ( $w['holds'] as $h ) { $intervals[] = $h; } }
	usort( $intervals, static function ( $a, $b ) { return $a[0] <=> $b[0]; });
	$overlap = 0; for ( $i = 1; $i < count( $intervals ); $i++ ) { if ( $intervals[ $i ][0] < $intervals[ $i - 1 ][1] - 0.005 ) { $overlap++; } }
	$check( 'o lock nunca foi segurado por dois processos ao mesmo tempo', 0 === $overlap, (string) $overlap . ' sobreposições em ' . count( $intervals ) . ' turnos' );
	$check( 'mais de um worker de fato trabalhou (a disputa pela fila aconteceu)', count( array_filter( array_column( $workers, 'ran' ) ) ) >= 2, wp_json_encode( array_column( $workers, 'ran' ) ) );
	echo $failures ? "\n" . count( $failures ) . " falha(s)\n" : "\nTudo certo.\n";
	exit( $failures ? 1 : 0 );
}

if ( 'teardown' === $mode ) {
	$wpdb->query( "DELETE r FROM {$p}result_rows r JOIN {$p}snapshots s ON s.id=r.snapshot_id WHERE s.contest_id IN ({$id_list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM {$p}snapshots WHERE contest_id IN ({$id_list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM {$p}jobs WHERE type='collect_results' AND payload_json REGEXP '\"contest_id\":(" . implode( '|', $ids ) . ")[,}]'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE l FROM {$p}candidate_contests l JOIN {$p}candidates c ON c.id=l.candidate_id WHERE c.election_id={$eid}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->delete( $p . 'candidates', array( 'election_id' => $eid ) );
	$wpdb->delete( $p . 'contests', array( 'election_id' => $eid ) );
	$wpdb->delete( $p . 'elections', array( 'id' => $eid ) );
	foreach ( $ids as $k => $id ) { delete_option( 'ae_result_checked_' . $id ); delete_option( 'ae_tse_http_' . md5( $url_for( $k + 1 ) ) ); }
	array_map( 'unlink', glob( sys_get_temp_dir() . '/ae-conc-*.json' ) ?: array() );
	echo "teardown ok\n";
}
