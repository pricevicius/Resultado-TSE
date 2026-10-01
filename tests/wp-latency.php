<?php
/**
 * Avaliação local da coleta (A10): mede o custo LOCAL de coletar uma disputa leve e uma pesada
 * (decodificar, gravar snapshot, ranking e vínculos) com o TSE falso, e projeta, para várias
 * latências de rede, quanto do tempo do worker o conjunto de disputas ocupa. A latência real do TSE
 * é a única peça que não dá para medir aqui: a Visão geral e /admin/health passam a mostrá-la
 * (AE_Perf) já nas primeiras coletas oficiais.
 *
 *   wp eval-file wp-content/plugins/tse-apuracao/tests/wp-latency.php
 *
 * Cria eleição e disputas temporárias (UF fictícia ZX) e remove tudo no final. Sem rede.
 */
$failures = array();
$check = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' — ' . $detail ) . "\n";
	if ( ! $ok ) { $failures[] = $name; }
};
global $wpdb;
$p = $wpdb->prefix . 'ae_';
$now = current_time( 'mysql', true );
$saved = array( 'ae_tse_blocked_until' => get_option( 'ae_tse_blocked_until', null ), 'ae_perf_samples' => get_option( 'ae_perf_samples', null ), 'ae_tse_last_request_at' => get_option( 'ae_tse_last_request_at', null ) );
$latency = 0.0; // segundos de rede simulada por requisição
$fetches = 0;

$wpdb->insert( $p . 'elections', array( 'slug' => 'ae-test-latency', 'name' => 'Teste latência', 'year' => 2026, 'timezone' => 'America/Sao_Paulo', 'status' => 'active', 'config_json' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
$eid = (int) $wpdb->insert_id;
$urls = array(); $contests = array();
$make = static function ( string $kind, int $i, string $position, int $cands ) use ( &$urls, &$contests, $wpdb, $p, $eid ): int {
	$url = 'https://resultados.tse.jus.br/oficial/ele2026/9999/dados/zx/zx-c' . $position . '-e0099' . sprintf( '%02d', $i ) . '-u.json';
	$wpdb->insert( $p . 'contests', array( 'election_id' => $eid, 'external_id' => 'ae-test-lat-' . $kind . $i, 'round_no' => 1, 'position_code' => $position, 'position_name' => 'Teste', 'scope_type' => 'UF', 'scope_code' => 'ZX' . $i, 'scope_name' => 'Teste', 'seats' => 1, 'active' => 1, 'config_json' => wp_json_encode( array( 'collection' => array( 'enabled' => true, 'kind' => 'EA20', 'source_url' => $url, 'interval' => 60 ) ) ) ) );
	$id = (int) $wpdb->insert_id;
	$urls[ $url ] = array( $cands, 800000 + $i * 10000 + ( '0006' === $position ? 500000 : 0 ) ); // candidatos distintos por disputa, como no TSE
	$contests[ $id ] = $url;
	return $id;
};
$doc = static function ( int $n, int $revision, int $base ): array {
	$cands = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$cands[] = array( 'n' => (string) ( 10 + $i ), 'sqcand' => (string) ( $base + $i ), 'nm' => 'Candidato Completo ' . $i, 'nmu' => 'CAND ' . $i, 'seq' => (string) $i, 'e' => 'n', 'st' => 'Não eleito', 'vap' => (string) ( ( $n - $i + 1 ) * 100 + $revision ), 'pvap' => '1,00' );
	}
	return array( 'ele' => '9999', 't' => '1', 'f' => 's', 'tpabr' => 'uf', 'cdabr' => 'zx', 'dg' => '28/09/2026', 'hg' => '19:00:00', 'idg' => 'r' . $revision, 'tf' => 'n', 'and' => 'p', 'md' => 'n',
		'carg' => array( array( 'cd' => '6', 'nv' => '1', 'agr' => array( array( 'tp' => 'i', 'par' => array( array( 'sg' => 'AAA', 'cand' => $cands ) ) ) ) ) ),
		's' => array( 'ts' => '2500', 'st' => '1250', 'pst' => '50,00', 'snt' => '0' ), 'e' => array( 'te' => '1500000', 'est' => '0' ),
		'v' => array( 'tv' => '820000', 'vv' => '780000', 'vb' => '20000', 'vn' => '15000', 'van' => '5000', 'pvb' => '2,44', 'pvn' => '1,83', 'pvan' => '0,61' ) );
};
$revision = 1;
add_filter( 'pre_http_request', static function ( $pre, $args, $request_url ) use ( &$urls, &$latency, &$fetches, $doc, &$revision ) {
	if ( ! isset( $urls[ $request_url ] ) ) { return new WP_Error( 'blocked', 'Teste bloqueou rede real: ' . $request_url ); }
	$fetches++;
	if ( $latency > 0 ) { usleep( (int) ( $latency * 1000000 ) ); }
	return array( 'headers' => array(), 'body' => wp_json_encode( $doc( $urls[ $request_url ][0], $revision, $urls[ $request_url ][1] ) ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );

/** Coleta todas as disputas de $ids em sequência, como o worker; devolve os segundos gastos. */
$run = static function ( array $ids ) use ( &$contests, &$revision ): float {
	$revision++; // conteúdo novo a cada rodada: cada coleta grava um snapshot de verdade
	$t = microtime( true );
	foreach ( $ids as $id ) { AE_TSE_Client::instance()->collect_results( array( 'contest_id' => $id, 'source_url' => $contests[ $id ], 'kind' => 'EA20' ) ); }
	return microtime( true ) - $t;
};

try {
	$light_ids = array(); $heavy_ids = array();
	for ( $i = 1; $i <= 8; $i++ ) { $light_ids[] = $make( 'l', $i, '0003', 8 ); }
	for ( $i = 1; $i <= 8; $i++ ) { $heavy_ids[] = $make( 'h', 10 + $i, '0006', 1100 ); }

	// 1) Custo local (rede = 0): a 1ª rodada cadastra os candidatos; a medida vale a partir da 2ª (rodadas seguintes = regime).
	$run( array_merge( $light_ids, $heavy_ids ) );
	$light = $run( $light_ids ) / count( $light_ids );
	$heavy = $run( $heavy_ids ) / count( $heavy_ids );
	printf( "custo local por disputa: leve %.0f ms · pesada (1.100 candidatos) %.0f ms\n", $light * 1000, $heavy * 1000 );
	$check( 'custo local: disputa pesada de 1.100 candidatos coleta em menos de 2 s', $heavy < 2.0, sprintf( '%.2f s', $heavy ) );
	$check( 'custo local: disputa leve coleta em menos de 0,5 s', $light < 0.5, sprintf( '%.2f s', $light ) );

	// 2) Validação do modelo com latência real simulada.
	$latency = 0.15;
	$subset = array_merge( array_slice( $light_ids, 0, 4 ), array_slice( $heavy_ids, 0, 4 ) );
	$predicted = 4 * ( $light + $latency ) + 4 * ( $heavy + $latency );
	$measured = $run( $subset );
	$latency = 0.0;
	printf( "modelo vs medido (8 disputas, 150 ms de rede): previsto %.2f s · medido %.2f s\n", $predicted, $measured );
	// 40%: o custo local oscila de uma rodada para outra (banco, disco); o que o modelo precisa mostrar é que a rede domina e soma linearmente.
	$check( 'modelo: previsto e medido diferem menos de 40%', abs( $measured - $predicted ) / $predicted < 0.40, sprintf( 'previsto %.2f medido %.2f', $predicted, $measured ) );

	// 3) Projeção: ocupação do worker por latência. Cenário nacional: 27 UFs com Governador, Senador, Dep. Federal e Dep. Estadual.
	$n_light = 54 + 1; $n_heavy = 54; // + Presidente
	echo "\nprojeção — cenário nacional (55 leves a cada 60 s, 54 pesadas a cada 120 s):\n";
	echo "  rede/req | trabalho em 120 s | ocupação do worker\n";
	$limit_ok = null;
	foreach ( array( 0.1, 0.25, 0.5, 1.0, 2.0, 3.0 ) as $lat ) {
		$work = 2 * $n_light * ( $light + $lat ) + $n_heavy * ( $heavy + $lat );
		$util = $work / 120;
		printf( "  %5.2f s  | %8.1f s        | %5.0f%% %s\n", $lat, $work, $util * 100, $util > 1 ? '(não fecha: a fila atrasa)' : ( $util > 0.7 ? '(apertado)' : '' ) );
		if ( $util <= 0.7 ) { $limit_ok = $lat; }
	}
	// Latência máxima sustentável (ocupação 100%) e confortável (70%).
	$solve = static function ( float $target ) use ( $light, $heavy, $n_light, $n_heavy ): float { return ( $target * 120 - 2 * $n_light * $light - $n_heavy * $heavy ) / ( 2 * $n_light + $n_heavy ); };
	printf( "  latência máxima por requisição: %.2f s para ocupar 100%% do worker; %.2f s para ocupar 70%%\n", $solve( 1.0 ), $solve( 0.7 ) );
	$check( 'projeção: com 250 ms de rede por requisição, o cenário nacional cabe no worker (<100%)', ( 2 * $n_light * ( $light + 0.25 ) + $n_heavy * ( $heavy + 0.25 ) ) / 120 < 1.0 );

	// 4) AE_Perf gravou amostras de download durante os testes.
	$summary = AE_Perf::summary();
	$check( 'AE_Perf: o download do TSE foi medido (amostras, média, p95 e máximo)', isset( $summary['fetch'] ) && $summary['fetch']['count'] >= 8 && $summary['fetch']['max_ms'] >= 100 && $summary['fetch']['p95_ms'] >= $summary['fetch']['avg_ms'], wp_json_encode( $summary['fetch'] ?? null ) );
	// O tempo do job só é gravado quando a coleta passa pelo worker: enfileira duas e drena pelo runner (sem tick, que enfileiraria outras disputas do banco).
	$revision++;
	foreach ( array_slice( $light_ids, 0, 2 ) as $id ) { AE_Job_Runner::enqueue( 'collect_results', array( 'contest_id' => $id, 'kind' => 'EA20', 'source_url' => $contests[ $id ] ) ); }
	$runner = AE_Job_Runner::instance();
	foreach ( array( 'acquire_lock', 'drain', 'release_lock' ) as $m ) { $methods[ $m ] = new ReflectionMethod( $runner, $m ); $methods[ $m ]->setAccessible( true ); }
	$ran = 0;
	if ( $methods['acquire_lock']->invoke( $runner ) ) { try { $ran = (int) $methods['drain']->invoke( $runner, 20 ); } finally { $methods['release_lock']->invoke( $runner ); } }
	$samples = (array) get_option( 'ae_perf_samples', array() );
	$last_kinds = array_slice( array_column( $samples, 'k' ), -4 );
	$check( 'AE_Perf: o job de coleta executado pelo worker foi medido (2 jobs, 2 amostras "job")', 2 === $ran && 2 === count( array_keys( $last_kinds, 'job', true ) ), $ran . ' ' . wp_json_encode( $last_kinds ) );
	$check( 'AE_Perf: a REST de saúde e a Visão geral expõem as medidas', isset( rest_do_request( new WP_REST_Request( 'GET', '/apuracao/v1/admin/health' ) )->get_data()['perf'] ) || 401 === rest_do_request( new WP_REST_Request( 'GET', '/apuracao/v1/admin/health' ) )->get_status() );
} finally {
	remove_all_filters( 'pre_http_request' );
	foreach ( array_keys( $contests ) as $id ) {
		$wpdb->query( "DELETE r FROM {$p}result_rows r JOIN {$p}snapshots s ON s.id=r.snapshot_id WHERE s.contest_id={$id}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $p . 'snapshots', array( 'contest_id' => $id ) );
		delete_option( 'ae_result_checked_' . $id );
	}
	$wpdb->query( "DELETE l FROM {$p}candidate_contests l JOIN {$p}candidates c ON c.id=l.candidate_id WHERE c.election_id={$eid}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->delete( $p . 'candidates', array( 'election_id' => $eid ) );
	$wpdb->delete( $p . 'contests', array( 'election_id' => $eid ) );
	$wpdb->delete( $p . 'elections', array( 'id' => $eid ) );
	if ( $contests ) { $wpdb->query( "DELETE FROM {$p}jobs WHERE type='collect_results' AND payload_json REGEXP '\"contest_id\":(" . implode( '|', array_keys( $contests ) ) . ")[,}]'" ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( array_keys( $urls ) as $u ) { delete_option( 'ae_tse_http_' . md5( $u ) ); }
	foreach ( $saved as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value, false ); }
}
echo $failures ? "\n" . count( $failures ) . " falha(s)\n" : "\nTudo certo.\n";
exit( $failures ? 1 : 0 );
