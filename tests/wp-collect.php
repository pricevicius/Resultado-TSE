<?php
/**
 * Coleta de ponta a ponta em WordPress real, sem rede: o HTTP do TSE é interceptado
 * (pre_http_request) e servido com JSONs EA20 montados no teste, a partir do formato das
 * fixtures. Cobre busca, HTTP condicional, validação, normalização, snapshot, REST pública,
 * shortcodes, 404 com backoff, 403/429 com pausa preventiva e JSON inválido.
 *
 *   wp eval-file wp-content/plugins/tse-apuracao/tests/wp-collect.php
 *
 * Cria uma eleição e uma disputa temporárias (UF fictícia ZY) e remove tudo no final.
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
$url2 = 'https://resultados.tse.jus.br/oficial/ele' . $year . '/9998/dados/zy/zy-c0003-e009998-u.json';
$saved = array( 'ae_tse_blocked_until' => get_option( 'ae_tse_blocked_until', null ) );

$wpdb->insert( $p . 'elections', array( 'slug' => 'ae-test-collect', 'name' => 'Teste coleta', 'year' => $year, 'timezone' => 'America/Sao_Paulo', 'status' => 'active', 'config_json' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
$eid = (int) $wpdb->insert_id;
$config = array( 'collection' => array( 'enabled' => true, 'managed' => true, 'kind' => 'EA20', 'source_url' => $url, 'interval' => 60, 'interval_mode' => 'auto' ) );
$wpdb->insert( $p . 'contests', array( 'election_id' => $eid, 'external_id' => 'ae-test-collect', 'round_no' => 1, 'position_code' => '0003', 'position_name' => 'Governador', 'scope_type' => 'UF', 'scope_code' => 'ZY', 'scope_name' => 'Teste', 'seats' => 1, 'active' => 1, 'config_json' => wp_json_encode( $config ) ) );
$cid = (int) $wpdb->insert_id;

/** Documento EA20 no formato oficial (carg > agr > par > cand). $stage: zero | partial | final | runoff. */
$doc = static function ( string $stage, int $n = 40 ): array {
	$cands = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$votes = 'zero' === $stage ? 0 : ( $n - $i + 1 ) * 1000;
		$elected = 'final' === $stage && 1 === $i ? 's' : 'n';
		$status = 'Não eleito';
		if ( 'final' === $stage ) { $status = 1 === $i ? 'Eleito' : 'Não eleito'; }
		if ( 'runoff' === $stage && $i <= 2 ) { $elected = 's'; $status = 'Segue para o 2º turno'; } // quirk: e=s também para quem só vai ao 2º turno
		$cands[] = array( 'n' => (string) ( 10 + $i ), 'sqcand' => (string) ( 5000 + $i ), 'nm' => 'Candidato Completo ' . $i, 'nmu' => 'CAND ' . $i . ' "Aspas" & <b>', 'seq' => (string) $i, 'e' => $elected, 'st' => $status, 'vap' => (string) $votes, 'pvap' => 'zero' === $stage ? '0,00' : number_format( $votes / 8200, 2, ',', '' ) );
	}
	$progress = array( 'zero' => 'n', 'partial' => 'p', 'final' => 'f', 'runoff' => 'f' )[ $stage ];
	return array( 'ele' => '9999', 't' => '1', 'f' => 's', 'tpabr' => 'uf', 'cdabr' => 'zy', 'dg' => '28/09/2026', 'hg' => '1' . ( 'zero' === $stage ? '0' : ( 'partial' === $stage ? '5' : '9' ) ) . ':00:00', 'idg' => $stage,
		'tf' => 'final' === $stage ? 's' : 'n', 'and' => $progress, 'md' => 'final' === $stage ? 'e' : 'n',
		'carg' => array( array( 'cd' => '3', 'nv' => '1', 'agr' => array( array( 'tp' => 'i', 'par' => array( array( 'sg' => 'AAA', 'cand' => $cands ) ) ) ) ) ),
		's' => array( 'ts' => '2500', 'st' => 'zero' === $stage ? '0' : ( 'partial' === $stage ? '1250' : '2500' ), 'pst' => 'zero' === $stage ? '0,00' : ( 'partial' === $stage ? '50,00' : '100,00' ), 'snt' => '0' ),
		'e' => array( 'te' => '1500000', 'est' => '0' ),
		'v' => array( 'tv' => '820000', 'vv' => '780000', 'vb' => '20000', 'vn' => '15000', 'van' => '5000', 'pvb' => '2,44', 'pvn' => '1,83', 'pvan' => '0,61' ) );
};

// Servidor TSE falso: $server['mode'] decide a resposta; registra as requisições.
$server = array( 'mode' => 'ok', 'body' => $doc( 'zero' ), 'etag' => '"v1"', 'hits' => 0, 'conditional_hits' => 0 );
add_filter( 'pre_http_request', static function ( $pre, $args, $request_url ) use ( &$server, $url, $url2 ) {
	if ( $request_url !== $url && $request_url !== $url2 ) { return new WP_Error( 'blocked', 'Teste bloqueou rede real: ' . $request_url ); }
	$server['hits']++;
	$sent = $args['headers']['If-None-Match'] ?? '';
	if ( '' !== $sent ) { $server['conditional_hits']++; }
	$res = static fn( int $code, string $body = '', array $headers = array() ) => array( 'headers' => $headers, 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	switch ( $server['mode'] ) {
		case '404': return $res( 404 );
		case '429': return $res( 429 );
		case '500': return $res( 500 );
		case 'garbage': return $res( 200, '{nao e json', array( 'etag' => '"bad"' ) );
		case 'empty-shape': return $res( 200, wp_json_encode( array( 'foo' => 'bar' ) ), array( 'etag' => '"shape"' ) );
	}
	if ( $sent === $server['etag'] ) { return $res( 304 ); }
	return $res( 200, wp_json_encode( $server['body'] ), array( 'etag' => $server['etag'], 'last-modified' => 'Mon, 28 Sep 2026 12:00:00 GMT' ) );
}, 10, 3 );

$collect = static function () use ( $cid, $url ): array {
	return AE_TSE_Client::instance()->collect_results( array( 'contest_id' => $cid, 'source_url' => $url, 'kind' => 'EA20' ) );
};
$snapshots = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}snapshots WHERE contest_id={$cid} AND status='valid'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$rest = static function ( array $headers = array() ) use ( $year ): WP_REST_Response {
	$request = new WP_REST_Request( 'GET', '/apuracao/v1/results/ae-test-collect/1/0003/ZY' );
	foreach ( $headers as $k => $v ) { $request->set_header( $k, $v ); }
	return rest_do_request( $request );
};
$http_key = 'ae_tse_http_' . md5( $url );
$last_snapshot = static fn(): ?array => ( $r = $wpdb->get_row( "SELECT * FROM {$p}snapshots WHERE contest_id={$cid} AND status='valid' ORDER BY id DESC LIMIT 1", ARRAY_A ) ) ? array_merge( $r, array( 'totals' => json_decode( (string) $r['totals_json'], true ) ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

try {
	// 1) Antes da apuração começar: 0 votos, aguardando.
	$out = $collect();
	$snap = $last_snapshot();
	$check( 'zero: coleta conclui e grava o 1º snapshot', ! empty( $out['complete'] ) && 1 === $snapshots() );
	$check( 'zero: progresso not_started, 0 votos, 40 candidatos no ranking', 'not_started' === $snap['totals']['progress'] && 0 === (int) $wpdb->get_var( "SELECT SUM(votes) FROM {$p}result_rows WHERE snapshot_id={$snap['id']}" ) && 40 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}result_rows WHERE snapshot_id={$snap['id']}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'zero: totais de votos brancos, nulos e anulados presentes', 20000 === (int) $snap['totals']['blank_votes'] && 15000 === (int) $snap['totals']['null_votes'] && 5000 === (int) $snap['totals']['annulled_votes'] );
	$check( 'zero: guarda ETag para a próxima requisição condicional', '"v1"' === ( get_option( $http_key )['etag'] ?? '' ) );

	// 2) Mesmo conteúdo: o servidor responde 304, nenhum snapshot novo, mas a checagem é registrada.
	delete_option( 'ae_result_checked_' . $cid );
	$out = $collect();
	$check( 'HTTP condicional: 304 não cria snapshot', ! empty( $out['unchanged'] ) && 1 === $snapshots() && $server['conditional_hits'] >= 1 );
	$check( 'HTTP condicional: 304 conta como checagem (não é dado atrasado)', '' !== (string) get_option( 'ae_result_checked_' . $cid, '' ) );

	// 3) REST pública antes de qualquer mudança.
	$response = $rest();
	$data = $response->get_data();
	$check( 'REST: 200 com 40 candidatos e Cache-Control público', 200 === $response->get_status() && 40 === count( $data['candidates'] ) && false !== strpos( (string) $response->get_headers()['Cache-Control'], 'public' ) );
	$etag = $response->get_headers()['ETag'] ?? '';
	$check( 'REST: If-None-Match devolve 304', 304 === $rest( array( 'if-none-match' => $etag ) )->get_status() );

	// 4) Parcial.
	$server['body'] = $doc( 'partial' ); $server['etag'] = '"v2"';
	$out = $collect();
	$snap = $last_snapshot();
	$check( 'parcial: novo snapshot, progresso partial, 50% das seções', 2 === $snapshots() && 'partial' === $snap['totals']['progress'] && 50.0 === (float) $snap['totals']['reported_percentage'], wp_json_encode( $snap['totals'] ) );
	$top = $rest()->get_data()['candidates'][0];
	$check( 'parcial: líder tem votos e posição 1; ninguém eleito ainda', 1 === (int) $top['rank_no'] && 40000 === (int) $top['votes'] && 0 === (int) $top['elected'] );

	// 5) Divergência and/tf do TSE: and=p mas tf=s. A interface decide por `and`.
	$mixed = $doc( 'partial' ); $mixed['tf'] = 's'; $mixed['idg'] = 'mixed';
	$server['body'] = $mixed; $server['etag'] = '"v2b"';
	$collect();
	$snap = $last_snapshot();
	$check( 'quirk and/tf: progress segue o `and` (partial) mesmo com tf=s', 'partial' === $snap['totals']['progress'], wp_json_encode( $snap['totals'] ) );

	// 6) Quirk do 2º turno: e=s não vira "eleito".
	$server['body'] = $doc( 'runoff' ); $server['etag'] = '"v3"';
	$collect();
	$data = $rest()->get_data();
	$flags = array_map( static fn( $c ) => array( (int) $c['elected'], (bool) $c['segundo_turno'] ), array_slice( $data['candidates'], 0, 3 ) );
	$check( 'quirk 2º turno: quem segue para o 2º turno não é marcado eleito', array( array( 0, true ), array( 0, true ), array( 0, false ) ) === $flags, wp_json_encode( $flags ) );

	// 7) Final com um eleito.
	$server['body'] = $doc( 'final' ); $server['etag'] = '"v4"';
	$collect();
	$snap = $last_snapshot();
	$data = $rest()->get_data();
	$check( 'final: progress final e eleito só o 1º', 'final' === $snap['totals']['progress'] && true === $snap['totals']['final'] && 1 === (int) $data['candidates'][0]['elected'] && 0 === (int) $data['candidates'][1]['elected'] );
	$check( 'final: cada coleta com conteúdo novo gerou um snapshot (5 no total)', 5 === $snapshots(), (string) $snapshots() );

	// 8) Camada pública: shortcode e card mostram o resultado, escapam o nome e o estado "concluída".
	$html = do_shortcode( '[tse_apuracao cargo="governador" uf="zy"]' );
	$check( 'shortcode: mostra candidato, escapa HTML e marca apuração concluída', false !== strpos( $html, 'CAND 1' ) && false === strpos( $html, '<b>' ) && false !== strpos( $html, 'Apuração concluída' ) );
	$card = do_shortcode( '[tse_apuracao_card cargo="governador" uf="zy" limite="3"]' );
	$check( 'card: mostra o líder', false !== strpos( $card, 'CAND 1' ) );
	$resumo = do_shortcode( '[tse_apuracao_resumo disputas="governador:zy,senador:zy" titulo="Home" link="/apuracao/"]' );
	$check( 'resumo: mostra o líder, "Eleito", % apurado, link e o estado concluída', false !== strpos( $resumo, 'CAND 1' ) && false !== strpos( $resumo, 'Eleito' ) && false !== strpos( $resumo, number_format_i18n( 100, 2 ) . '% apurado' ) && false !== strpos( $resumo, 'href="/apuracao/"' ) && false !== strpos( $resumo, 'Apuração concluída' ) );
	$check( 'resumo: escapa o nome do candidato e a disputa sem dado vira "Aguardando"', false === strpos( $resumo, '<b>' ) && false !== strpos( $resumo, 'tse-resumo-aguardando' ) );
	// Disputa não finalizada e sem checagem recente: "Dados atrasados".
	$server['body'] = $doc( 'partial' ); $server['etag'] = '"v5"';
	$collect();
	update_option( 'ae_result_checked_' . $cid, gmdate( 'Y-m-d H:i:s', time() - 3600 ), false );
	$check( 'front: sem checagem há 1 h numa disputa parcial mostra "Dados atrasados"', false !== strpos( do_shortcode( '[tse_apuracao cargo="governador" uf="zy"]' ), 'Dados atrasados' ) );
	$check( 'resumo: disputa parcial sem checagem recente mostra "Dados atrasados"', false !== strpos( do_shortcode( '[tse_apuracao_resumo disputas="governador:zy"]' ), 'Dados atrasados' ) );
	update_option( 'ae_result_checked_' . $cid, current_time( 'mysql', true ), false );
	$check( 'resumo: checagem recente mostra "Ao vivo"', false !== strpos( do_shortcode( '[tse_apuracao_resumo disputas="governador:zy"]' ), 'Ao vivo' ) );
	$check( 'front: checagem recente mostra "Ao vivo"', false !== strpos( do_shortcode( '[tse_apuracao cargo="governador" uf="zy"]' ), 'Ao vivo' ) );

	// 9) Falhas do TSE: o último snapshot válido continua sendo servido.
	$before = $snapshots();
	$server['mode'] = 'garbage';
	$err = '';
	try { $collect(); } catch ( Throwable $e ) { $err = $e->getMessage(); }
	$check( 'JSON inválido: levanta erro e não cria snapshot', '' !== $err && $before === $snapshots(), $err );
	$server['mode'] = 'empty-shape';
	$err = '';
	try { $collect(); } catch ( Throwable $e ) { $err = $e->getMessage(); }
	$check( 'JSON sem estrutura essencial: rejeitado', false !== strpos( $err, 'rejeitado' ) && $before === $snapshots(), $err );
	$server['mode'] = '500';
	$err = '';
	try { $collect(); } catch ( Throwable $e ) { $err = $e->getMessage(); }
	$check( 'HTTP 500: erro de retry, sem bloquear a coleta', false !== strpos( $err, '500' ) && absint( get_option( 'ae_tse_blocked_until', 0 ) ) <= time() );
	$check( 'durante as falhas, a REST segue servindo o último snapshot válido', 200 === $rest()->get_status() && 40 === count( $rest()->get_data()['candidates'] ) );

	// 10) 404: adia a fonte com backoff, sem desligar a disputa.
	$server['mode'] = '404';
	$out = $collect();
	$cfg = json_decode( (string) $wpdb->get_var( "SELECT config_json FROM {$p}contests WHERE id={$cid}" ), true ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( '404: fonte adiada com tentativa futura, disputa continua ligada', ! empty( $out['source_deferred'] ) && ! empty( $cfg['collection']['enabled'] ) && 1 === (int) $cfg['collection']['missing_attempts'] && strtotime( $cfg['collection']['next_attempt_at'] . ' UTC' ) > time() );
	$collect();
	$cfg = json_decode( (string) $wpdb->get_var( "SELECT config_json FROM {$p}contests WHERE id={$cid}" ), true ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( '404 repetido: o backoff cresce', 2 === (int) $cfg['collection']['missing_attempts'] );
	$server['mode'] = 'ok'; $server['etag'] = '"v6"'; $server['body'] = $doc( 'final' );
	$collect();
	$cfg = json_decode( (string) $wpdb->get_var( "SELECT config_json FROM {$p}contests WHERE id={$cid}" ), true ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'fonte volta a publicar: o adiamento é limpo sozinho', empty( $cfg['collection']['missing_attempts'] ) && empty( $cfg['collection']['next_attempt_at'] ) );

	// 10b) A9: o mesmo candidato no 1º e no 2º turno (disputas diferentes, mesmo cargo e escopo).
	$server['mode'] = 'ok'; $server['etag'] = '"v7"'; $server['body'] = $doc( 'final' );
	$collect();
	$cid2 = 0;
	$wpdb->insert( $p . 'contests', array( 'election_id' => $eid, 'external_id' => 'ae-test-collect-2t', 'round_no' => 2, 'position_code' => '0003', 'position_name' => 'Governador', 'scope_type' => 'UF', 'scope_code' => 'ZY', 'scope_name' => 'Teste', 'seats' => 1, 'active' => 1, 'config_json' => wp_json_encode( array( 'collection' => array( 'enabled' => true, 'kind' => 'EA20', 'source_url' => $url2, 'interval' => 60 ) ) ) ) );
	$cid2 = (int) $wpdb->insert_id;
	$ref = static fn(): int => (int) $wpdb->get_var( "SELECT contest_id FROM {$p}candidates WHERE election_id={$eid} AND external_id='5001'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$links = static fn(): array => array_map( 'intval', $wpdb->get_col( "SELECT l.contest_id FROM {$p}candidate_contests l JOIN {$p}candidates c ON c.id=l.candidate_id WHERE c.election_id={$eid} AND c.external_id='5001' ORDER BY l.contest_id" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'A9: após o 1º turno o candidato está vinculado só à disputa do 1º turno', $cid === $ref() && array( $cid ) === $links(), $ref() . ' ' . wp_json_encode( $links() ) );
	$server['body'] = $doc( 'runoff' ); $server['etag'] = '"v8"';
	AE_TSE_Client::instance()->collect_results( array( 'contest_id' => $cid2, 'source_url' => $url2, 'kind' => 'EA20' ) );
	$check( 'A9: coletar o 2º turno NÃO move a disputa de referência do candidato', $cid === $ref(), (string) $ref() );
	$both = array( $cid, $cid2 ); sort( $both );
	$check( 'A9: o candidato fica vinculado às duas disputas (1º e 2º turno)', $both === $links(), wp_json_encode( $links() ) );
	AE_TSE_Client::instance()->collect_results( array( 'contest_id' => $cid2, 'source_url' => $url2, 'kind' => 'EA20' ) );
	$check( 'A9: recoletar não duplica o vínculo', $both === $links() );
	// Candidato que só existe no 2º turno (cadastro novo pelo EA20 do 2º turno) fica na disputa do 2º turno.
	$extra = $doc( 'runoff', 41 ); $server['body'] = $extra; $server['etag'] = '"v9"';
	AE_TSE_Client::instance()->collect_results( array( 'contest_id' => $cid2, 'source_url' => $url2, 'kind' => 'EA20' ) );
	$check( 'A9: candidato novo do 2º turno nasce vinculado à disputa do 2º turno', $cid2 === (int) $wpdb->get_var( "SELECT contest_id FROM {$p}candidates WHERE election_id={$eid} AND external_id='5041'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$_GET['ae_candidato'] = '5001';
	$profile = do_shortcode( '[apuracao_candidatos]' );
	unset( $_GET['ae_candidato'] );
	$check( 'A9: o perfil do candidato mostra "1º e 2º turno"', false !== strpos( $profile, 'Turnos disputados' ) && false !== strpos( $profile, '1º e 2º turno' ) );
	// A tabela de vínculos existe e a migração é idempotente.
	AE_Schema::install();
	$check( 'A9: reinstalar o esquema mantém os vínculos (idempotente)', $both === $links() );
	$server['body'] = $doc( 'final' ); $server['etag'] = '"v7"';

	// 11) 429: pausa preventiva de 10 min; nada mais é buscado até acabar.
	$server['mode'] = '429';
	$err = '';
	try { $collect(); } catch ( Throwable $e ) { $err = $e->getMessage(); }
	$blocked = absint( get_option( 'ae_tse_blocked_until', 0 ) );
	$check( '429: abre a pausa preventiva de ~10 min', $blocked > time() + 9 * 60 && $blocked <= time() + 10 * 60 + 5, (string) ( $blocked - time() ) );
	$hits = $server['hits'];
	$err = '';
	try { $collect(); } catch ( Throwable $e ) { $err = $e->getMessage(); }
	$check( 'pausa ativa: nenhuma requisição ao TSE', $hits === $server['hits'] && false !== strpos( $err, 'pausada' ), $err );
	$check( 'durante a pausa a REST continua servindo dados', 200 === $rest()->get_status() );
} finally {
	remove_all_filters( 'pre_http_request' );
	$wpdb->query( "DELETE r FROM {$p}result_rows r JOIN {$p}snapshots s ON s.id=r.snapshot_id WHERE s.contest_id={$cid}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->delete( $p . 'snapshots', array( 'contest_id' => $cid ) );
	$wpdb->query( "DELETE l FROM {$p}candidate_contests l JOIN {$p}candidates c ON c.id=l.candidate_id WHERE c.election_id={$eid}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! empty( $cid2 ) ) { $wpdb->query( "DELETE r FROM {$p}result_rows r JOIN {$p}snapshots s ON s.id=r.snapshot_id WHERE s.contest_id={$cid2}" ); $wpdb->delete( $p . 'snapshots', array( 'contest_id' => $cid2 ) ); $wpdb->delete( $p . 'contests', array( 'id' => $cid2 ) ); delete_option( 'ae_result_checked_' . $cid2 ); delete_option( 'ae_tse_http_' . md5( $url2 ) ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->delete( $p . 'candidates', array( 'election_id' => $eid ) );
	$wpdb->delete( $p . 'contests', array( 'id' => $cid ) );
	$wpdb->delete( $p . 'elections', array( 'id' => $eid ) );
	delete_option( $http_key );
	delete_option( 'ae_result_checked_' . $cid );
	foreach ( $saved as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value, false ); }
}
echo $failures ? "\n" . count( $failures ) . " falha(s)\n" : "\nTudo certo.\n";
exit( $failures ? 1 : 0 );
