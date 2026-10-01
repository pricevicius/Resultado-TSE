<?php
/**
 * Teste de integração em WordPress real (sem PHPUnit). Rode com:
 *   wp eval-file wp-content/plugins/tse-apuracao/tests/wp-integration.php
 * ou, com Docker, pelo tests/run-in-docker.sh.
 *
 * Só faz alteração temporária (liga/desliga uma disputa) e sempre restaura no final.
 */
$failures = array();
$check = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' — ' . $detail ) . "\n";
	if ( ! $ok ) { $failures[] = $name; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admins ) { fwrite( STDERR, "Administrator user required.\n" ); exit( 1 ); }
wp_set_current_user( $admins[0]->ID );
global $wpdb;
$p = $wpdb->prefix . 'ae_';

$render = static function ( string $tab ): string {
	global $wpdb;
	$_GET['tab'] = $tab;
	$wpdb->last_error = '';
	ob_start();
	AE_Admin::instance()->page();
	return (string) ob_get_clean();
};

// Versão única.
$check( 'AE_VERSION igual a TSE_APURACAO_VERSION', AE_VERSION === TSE_APURACAO_VERSION );

// Todas as abas renderizam sem erro de banco.
foreach ( array( 'overview', 'setup', 'selecao', 'import', 'jobs', 'logs', 'shortcodes' ) as $tab ) {
	$html = $render( $tab );
	$check( "aba {$tab} renderiza", '' !== $html && '' === $wpdb->last_error, $wpdb->last_error );
}

// Requisito php-zip: ativação recusada e aviso permanente quando falta.
$check( 'requisitos: sem pendência quando há ZipArchive ou a dispensa por constante', ( class_exists( 'ZipArchive' ) || ( defined( 'TSE_APURACAO_ALLOW_NO_ZIP' ) && TSE_APURACAO_ALLOW_NO_ZIP ) ) === ( array() === AE_Plugin::missing_requirements() ) );
$force = static function ( array $m ) { return array( 'a extensão PHP zip (teste)' ); };
add_filter( 'ae_missing_requirements', $force );
$check( 'requisitos: o filtro consegue declarar requisito ausente', array( 'a extensão PHP zip (teste)' ) === AE_Plugin::missing_requirements() );
foreach ( array( 'overview', 'selecao', 'import' ) as $tab ) {
	$check( "requisitos: aviso permanente aparece na aba {$tab}", false !== strpos( $render( $tab ), 'Requisito do servidor ausente' ) );
}
$health_missing = rest_do_request( new WP_REST_Request( 'GET', '/apuracao/v1/admin/health' ) )->get_data();
$check( 'requisitos: REST de saúde lista o requisito ausente', array( 'a extensão PHP zip (teste)' ) === ( $health_missing['missing_requirements'] ?? null ) );
remove_filter( 'ae_missing_requirements', $force );
$check( 'requisitos: sem o filtro o aviso some (se o ambiente tem a extensão)', ! class_exists( 'ZipArchive' ) || false === strpos( $render( 'overview' ), 'Requisito do servidor ausente' ) );

// A7: tabelas InnoDB.
$check( 'non_innodb_tables vazio quando tudo é InnoDB', array() === AE_Schema::non_innodb_tables(), implode( ',', AE_Schema::non_innodb_tables() ) );
$check( 'aviso de InnoDB ausente na Visão geral', false === strpos( $render( 'overview' ), 'Tabelas fora do InnoDB' ) );
$wpdb->query( "CREATE TABLE {$p}tmp_myisam (id int) ENGINE=MyISAM" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
try {
	$check( 'non_innodb_tables acusa tabela MyISAM', array( $p . 'tmp_myisam' ) === AE_Schema::non_innodb_tables() );
	$check( 'aviso de InnoDB aparece na Visão geral', false !== strpos( $render( 'overview' ), $p . 'tmp_myisam' ) );
} finally {
	$wpdb->query( "DROP TABLE IF EXISTS {$p}tmp_myisam" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
$health = rest_do_request( new WP_REST_Request( 'GET', '/apuracao/v1/admin/health' ) )->get_data();
$check( 'REST de saúde traz non_innodb_tables', is_array( $health['non_innodb_tables'] ?? null ) );

// A5: aviso de disputas da UF do site desligadas.
$site_uf = strtoupper( (string) get_option( 'ae_site_uf', '' ) );
if ( '' === $site_uf ) {
	echo "skip A5 (ae_site_uf vazio)\n";
} else {
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,config_json FROM {$p}contests WHERE active=1 AND scope_code=%s AND position_code='0006' LIMIT 1", $site_uf ) );
	if ( ! $row ) {
		echo "skip A5 (sem Deputado Federal em {$site_uf})\n";
	} else {
		$original = $row->config_json;
		try {
			$had_warning = false !== strpos( $render( 'overview' ), 'Disputas de ' . $site_uf . ' desligadas' );
			$config = json_decode( (string) $original, true ) ?: array();
			$config['collection']['enabled'] = false;
			$wpdb->update( $p . 'contests', array( 'config_json' => wp_json_encode( $config ) ), array( 'id' => (int) $row->id ) );
			foreach ( array( 'overview', 'selecao' ) as $tab ) {
				$html = $render( $tab );
				$check( "aviso de UF desligada em {$tab}", false !== strpos( $html, 'Disputas de ' . $site_uf . ' desligadas' ) && false !== strpos( $html, 'Deputado Federal' ) );
			}
			$config['collection']['enabled'] = true;
			$wpdb->update( $p . 'contests', array( 'config_json' => wp_json_encode( $config ) ), array( 'id' => (int) $row->id ) );
			$check( 'aviso volta ao estado anterior ao religar', $had_warning === ( false !== strpos( $render( 'overview' ), 'Disputas de ' . $site_uf . ' desligadas' ) ) );
		} finally {
			$wpdb->update( $p . 'contests', array( 'config_json' => $original ), array( 'id' => (int) $row->id ) );
		}
	}
}

// A5: lógica pura, sem tocar no banco.
$mk = static fn( string $uf, ?bool $enabled ) => (object) array( 'position_name' => 'X', 'scope_code' => $uf, 'round_no' => 1, 'config_json' => null === $enabled ? '{}' : wp_json_encode( array( 'collection' => array( 'enabled' => $enabled ) ) ) );
$check( 'disabled_in_site_uf: só a UF do site e só as desligadas', 1 === count( AE_Collection_Policy::disabled_in_site_uf( array( $mk( 'SP', false ), $mk( 'SP', true ), $mk( 'RJ', false ), $mk( 'BR', false ), $mk( 'SP', null ) ), 'sp' ) ) );
$check( 'disabled_in_site_uf: sem UF não avisa', array() === AE_Collection_Policy::disabled_in_site_uf( array( $mk( 'SP', false ) ), '' ) );


// Manual do admin (aba "Como usar"): não pode divergir do código.
$guide = $render( 'shortcodes' );
$registered = array( 'tse_apuracao', 'tse_apuracao_card', 'tse_apuracao_resumo', 'apuracao', 'apuracao_candidato', 'apuracao_candidatos', 'apuracao_candidatos_lista', 'apuracao_navegacao' );
$missing = array_filter( $registered, static fn( string $tag ): bool => ! shortcode_exists( $tag ) || false === strpos( $guide, '[' . $tag ) );
$check( 'manual: todo shortcode registrado está documentado', ! $missing, implode( ',', $missing ) );
$slugs_missing = array_filter( array_keys( TSE_API::CARGOS ), static fn( string $slug ): bool => false === strpos( $guide, '<code>' . $slug . '</code>' ) );
$check( 'manual: tabela de cargos traz todos os cargos de TSE_API::CARGOS', ! $slugs_missing, implode( ',', $slugs_missing ) );
preg_match_all( '#<div class="ae-code"><code>(.*?)</code>#s', $guide, $found );
$examples = array_map( 'html_entity_decode', $found[1] );
$check( 'manual: há exemplos copiáveis', count( $examples ) >= 12, (string) count( $examples ) );
$broken = array();
foreach ( $examples as $example ) {
	$out = do_shortcode( $example );
	if ( $out === $example || false !== strpos( $out, 'Fatal' ) || false !== strpos( $out, 'Warning' ) ) { $broken[] = $example; }
}
$check( 'manual: todo exemplo copiável executa como shortcode', ! $broken, implode( ' | ', $broken ) );
$parsed = AE_Resumo::parse_disputas( 'governador:SP, senador:sp:2,xyz:rj,presidente,vereador:rj:9,deputado-federal:sp:0' );
$check( 'resumo: lê cargo:uf:turno, ignora cargo desconhecido, usa br e limita o turno', array( array( 'governador', 'sp', 1 ), array( 'senador', 'sp', 2 ), array( 'presidente', 'br', 1 ), array( 'vereador', 'rj', 2 ), array( 'deputado-federal', 'sp', 1 ) ) === array_map( static fn( $r ) => array( $r['cargo'], $r['uf'], $r['turno'] ), $parsed ), wp_json_encode( $parsed ) );
$check( 'resumo: no máximo 8 disputas', 8 === count( AE_Resumo::parse_disputas( implode( ',', array_fill( 0, 20, 'governador:sp' ) ) ) ) );
$html = do_shortcode( '[tse_apuracao_resumo disputas="governador:zz" titulo="<b>X</b>" link="javascript:alert(1)"]' );
$check( 'resumo: escapa título, recusa link perigoso e mostra "Aguardando apuração"', false === strpos( $html, '<b>X' ) && false === strpos( $html, 'javascript:' ) && false !== strpos( $html, 'Aguardando apuração' ) );
$check( 'resumo: sem disputas válidas mostra aviso', false !== strpos( do_shortcode( '[tse_apuracao_resumo disputas="xyz"]' ), 'Nenhuma disputa configurada' ) );
$legacy_args = array();
foreach ( array( 'render', 'candidate', 'catalog', 'navigation' ) as $method ) {
	try { AE_Shortcodes::instance()->$method( '' ); } catch ( Throwable $e ) { $legacy_args[] = $method . ': ' . $e->getMessage(); }
}
$check( 'shortcodes aceitam "" como atributos (WordPress antigo entrega string vazia)', ! $legacy_args, implode( ' | ', $legacy_args ) );
$check( 'manual: aba aparece no menu do admin', false !== strpos( $render( 'overview' ), 'tab=shortcodes' ) );

// A6: nada fixo de uma eleição ou de um projeto nas telas.
$y = AE_Plugin::default_election_year();
$check( 'A6: ano padrão é par e não fica no passado', 0 === $y % 2 && $y >= (int) gmdate( 'Y' ) );
$setup = $render( 'setup' );
$check( 'A6: formulário de sincronização usa o ano padrão e UF neutra', false !== strpos( $setup, 'value="' . $y . '"' ) && false === strpos( $setup, 'Simulado 2026' ) && false === strpos( $setup, 'placeholder="ES"' ) );
$check( 'A6: sem seed de eleição fixa criado pelo plugin', ! method_exists( 'AE_Plugin', 'seed_2026' ) );
$catalog_html = AE_Candidate_Catalog::render();
$check( 'A6: cabeçalho do catálogo não é fixo', false !== strpos( $catalog_html, 'ae-catalog-kicker' ) && 1 === preg_match( '/ae-catalog-kicker">Eleições( \d{4})?</', $catalog_html ) );

// A3 + A4: importação de candidatos com um CSV sintético (UF fictícia ZZ, sem rede).
$now = current_time( 'mysql', true );
$wpdb->insert( $p . 'elections', array( 'slug' => 'ae-test-import', 'name' => 'Teste importação', 'year' => 2026, 'timezone' => 'America/Sao_Paulo', 'status' => 'draft', 'config_json' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
$test_election = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'contests', array( 'election_id' => $test_election, 'external_id' => 'ae-test-0006-ZZ', 'round_no' => 1, 'position_code' => '0006', 'position_name' => 'Deputado Federal', 'scope_type' => 'UF', 'scope_code' => 'ZZ', 'scope_name' => 'Teste', 'seats' => 1, 'active' => 1, 'config_json' => wp_json_encode( array( 'collection' => array( 'enabled' => true ) ) ) ) );
$test_contest = (int) $wpdb->insert_id;
$saved_options = array( 'ae_last_import' => get_option( 'ae_last_import', null ), 'ae_auto_import_election' => get_option( 'ae_auto_import_election', null ), 'ae_auto_import_last_at' => get_option( 'ae_auto_import_last_at', null ) );
$import_url = 'https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_2026.zip';
$run_import = static function ( array $rows, int $job_id ) use ( $test_election, $import_url ): array {
	$header = array( 'SQ_CANDIDATO', 'NM_URNA_CANDIDATO', 'NM_CANDIDATO', 'NR_CANDIDATO', 'SG_PARTIDO', 'CD_CARGO', 'SG_UF', 'NR_TURNO', 'DS_SITUACAO_CANDIDATURA', 'DT_GERACAO', 'HH_GERACAO' );
	$csv = implode( ';', $header ) . "\n";
	foreach ( $rows as $r ) { $csv .= implode( ';', $r ) . "\n"; }
	// CSV puro: o container local pode não ter php-zip, e o caminho de leitura e de marcação é o mesmo.
	$csv_path = wp_tempnam( 'ae-test' );
	file_put_contents( $csv_path, $csv );
	set_transient( 'ae_import_file_' . $job_id, $csv_path, HOUR_IN_SECONDS );
	$payload = array( 'election_id' => $test_election, 'source_url' => $import_url, 'format' => 'csv', 'ufs' => array( 'ZZ' ), 'cargos' => array( '6' ) );
	$cursor = array();
	for ( $i = 0; $i < 20; $i++ ) {
		$out = AE_TSE_Client::instance()->import_candidates_page( $payload, $cursor, $job_id );
		if ( ! empty( $out['complete'] ) ) { return $out; }
		$cursor = $out['cursor'];
	}
	throw new RuntimeException( 'Importação não terminou.' );
};
$cand = static fn( string $sq, string $name, string $cargo = '6' ) => array( $sq, $name, $name . ' da Silva', '1' . $sq, 'ABC', $cargo, 'ZZ', '1', 'Deferido', '28/09/2026', '09:41:32' );
$removed_ids = static function () use ( $wpdb, $p, $test_election ): array {
	return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( "SELECT external_id FROM {$p}candidates WHERE election_id=%d AND removed_at IS NOT NULL ORDER BY external_id", $test_election ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};
try {
	$run_import( array( $cand( '9001', 'Ana' ), $cand( '9002', 'Bruno' ), $cand( '9003', 'Carla' ), $cand( '9004', 'Vice', '2' ) ), 900001 );
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}candidates WHERE election_id=%d", $test_election ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'importação: 3 titulares entram, o vice (cargo 2) não', 3 === $count, (string) $count );
	$linked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}candidates WHERE election_id=%d AND contest_id=%d", $test_election, $test_contest ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'importação: candidatos vinculados à disputa por cargo+UF+turno', 3 === $linked, (string) $linked );
	$links_n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}candidate_contests l INNER JOIN {$p}candidates c ON c.id=l.candidate_id WHERE c.election_id=%d AND l.contest_id=%d", $test_election, $test_contest ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'A9: a importação registra o vínculo candidato x disputa', 3 === $links_n, (string) $links_n );
	$last = get_option( 'ae_last_import' );
	$check( 'A4: ae_last_import guarda linhas e geração do CSV', is_array( $last ) && 3 === (int) $last['rows'] && '2026-09-28 09:41:32' === $last['csv_generated_at'] && 0 === (int) $last['removed'], wp_json_encode( $last ) );
	$check( 'A3: primeira importação não marca ninguém', array() === $removed_ids() );

	// Segunda importação: Bruno saiu do CSV.
	sleep( 2 ); // updated_at tem resolução de 1 s; a 2ª importação precisa começar depois da 1ª.
	$run_import( array( $cand( '9001', 'Ana' ), $cand( '9003', 'Carla' ) ), 900002 );
	$check( 'A3: quem saiu do CSV é marcado, os demais não', array( '9002' ) === $removed_ids(), implode( ',', $removed_ids() ) );
	$check( 'A3: candidato removido continua no cadastro', 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}candidates WHERE election_id=%d", $test_election ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$_GET = array( 'ae_uf' => 'ZZ' );
	$catalog = AE_Candidate_Catalog::render();
	$check( 'A3: catálogo mostra o aviso só para o removido', 1 === substr_count( $catalog, 'ae-candidate-removed' ) );
	$html = $render( 'import' );
	$check( 'A4: aba Importar mostra a última importação e a contagem de removidos', false !== strpos( $html, 'Última importação de candidatos' ) && false !== strpos( $html, '28/09/2026 09:41' ) );
	$_GET = array();

	// Terceira: ele volta, e uma importação vazia não marca ninguém.
	sleep( 2 );
	$run_import( array( $cand( '9001', 'Ana' ), $cand( '9002', 'Bruno' ), $cand( '9003', 'Carla' ) ), 900003 );
	$check( 'A3: candidato que reaparece volta ao normal', array() === $removed_ids() );
	sleep( 2 );
	$run_import( array(), 900004 );
	$check( 'A3: importação sem nenhuma linha não marca ninguém', array() === $removed_ids() );

	// A4: agendamento. Desligado não enfileira; ligado enfileira uma vez e respeita o intervalo.
	$jobs = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}jobs WHERE type='import_candidates' AND payload_json LIKE '%\"election_id\":" . $test_election . ",%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$schedule = new ReflectionMethod( AE_Job_Runner::class, 'maybe_enqueue_scheduled_import' );
	$schedule->setAccessible( true );
	$runner = AE_Job_Runner::instance();
	delete_option( 'ae_auto_import_last_at' );
	update_option( 'ae_auto_import_election', 0, false );
	$schedule->invoke( $runner );
	$check( 'A4: agendamento desligado não enfileira', 0 === $jobs() );
	update_option( 'ae_auto_import_election', $test_election, false );
	$schedule->invoke( $runner );
	$check( 'A4: agendamento ligado enfileira a importação com o escopo da seleção', 1 === $jobs() );
	$schedule->invoke( $runner );
	$check( 'A4: não enfileira de novo dentro do intervalo', 1 === $jobs() );
	// Sem disputa ligada na UF, nunca agenda o Brasil inteiro.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}jobs WHERE type='import_candidates' AND payload_json LIKE %s", '%"election_id":' . $test_election . ',%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	delete_option( 'ae_auto_import_last_at' );
	$wpdb->update( $p . 'contests', array( 'config_json' => wp_json_encode( array( 'collection' => array( 'enabled' => false ) ) ) ), array( 'id' => $test_contest ) );
	$schedule->invoke( $runner );
	$check( 'A4: sem disputa ligada não agenda importação nacional', 0 === $jobs() );
} finally {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}jobs WHERE payload_json LIKE %s", '%"election_id":' . $test_election . ',%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE l FROM {$p}candidate_contests l INNER JOIN {$p}candidates c ON c.id=l.candidate_id WHERE c.election_id=%d", $test_election ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->delete( $p . 'candidates', array( 'election_id' => $test_election ) );
	$wpdb->delete( $p . 'contests', array( 'election_id' => $test_election ) );
	$wpdb->delete( $p . 'elections', array( 'id' => $test_election ) );
	foreach ( $saved_options as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value, false ); }
	$_GET = array();
}

// A1: gravação do snapshot em lote (mesma saída, bem menos queries).
$now = current_time( 'mysql', true );
$wpdb->insert( $p . 'elections', array( 'slug' => 'ae-test-persist', 'name' => 'Teste persist', 'year' => 2026, 'timezone' => 'America/Sao_Paulo', 'status' => 'draft', 'config_json' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
$pe = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'contests', array( 'election_id' => $pe, 'external_id' => 'ae-test-persist', 'round_no' => 1, 'position_code' => '0006', 'position_name' => 'Deputado Federal', 'scope_type' => 'UF', 'scope_code' => 'ZY', 'scope_name' => 'Teste', 'seats' => 1, 'active' => 1, 'config_json' => '{}' ) );
$pc = (int) $wpdb->insert_id;
$persist = new ReflectionMethod( AE_TSE_Client::class, 'persist_result' );
$persist->setAccessible( true );
$make = static function ( int $n, int $shift ): array {
	$list = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$list[] = array( 'external_id' => (string) ( 770000 + $i ), 'rank' => $i, 'votes' => ( $n - $i ) * 10 + $shift, 'percentage' => round( ( $n - $i ) / $n * 5, 4 ), 'elected' => $i <= 5 ? 1 : 0, 'situation' => $i <= 5 ? 'Eleito' : 'Não eleito', 'ballot_name' => 'Cand ' . $i, 'full_name' => 'Cand Completo ' . $i, 'ballot_number' => (string) ( 1000 + $i ), 'party' => 'P' . ( $i % 9 ) );
	}
	return array( 'generated_at' => null, 'totals' => array( 'total_votes' => $n ), 'candidates' => $list );
};
$url = 'https://resultados.tse.jus.br/oficial/ele2026/1/dados/zy/zy-c0006-e000001-u.json';
try {
	$out1 = $persist->invoke( AE_TSE_Client::instance(), $pc, 'EA20', $url, array( 'v' => 1 ), $make( 450, 0 ) ); // 450 > 200: força mais de um INSERT em lote
	$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}result_rows r JOIN {$p}snapshots s ON s.id=r.snapshot_id WHERE s.contest_id={$pc}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'A1: snapshot grava as 450 linhas de ranking (vários lotes)', 450 === $rows, (string) $rows );
	$linked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}result_rows r JOIN {$p}snapshots s ON s.id=r.snapshot_id WHERE s.contest_id={$pc} AND r.candidate_id IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'A1: toda linha do ranking aponta para o candidato cadastrado', 450 === $linked, (string) $linked );
	$sample = $wpdb->get_row( "SELECT r.rank_no,r.votes,r.elected,r.situation,r.ballot_name,r.party,c.ballot_number FROM {$p}result_rows r JOIN {$p}snapshots s ON s.id=r.snapshot_id JOIN {$p}candidates c ON c.id=r.candidate_id WHERE s.contest_id={$pc} AND r.external_candidate_id='770003'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'A1: campos da linha conferem', is_array( $sample ) && 3 === (int) $sample['rank_no'] && 4470 === (int) $sample['votes'] && 1 === (int) $sample['elected'] && 'Eleito' === $sample['situation'] && 'Cand 3' === $sample['ballot_name'] && '1003' === $sample['ballot_number'], wp_json_encode( $sample ) );
	$same = $persist->invoke( AE_TSE_Client::instance(), $pc, 'EA20', $url, array( 'v' => 1 ), $make( 450, 0 ) );
	$check( 'A1: mesmo JSON não cria snapshot novo', ! empty( $same['unchanged'] ) );
	$q0 = $wpdb->num_queries;
	$persist->invoke( AE_TSE_Client::instance(), $pc, 'EA20', $url, array( 'v' => 2 ), $make( 450, 3 ) );
	$used = $wpdb->num_queries - $q0;
	$check( 'A1: atualização de 450 candidatos usa poucas queries', $used < 60, (string) $used . ' queries' );
	$check( 'A1: segundo snapshot gravado', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}snapshots WHERE contest_id={$pc}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// Campos vazios vindos do EA20 não apagam o cadastro.
	$blank = $make( 1, 0 );
	$blank['candidates'][0] = array_merge( $blank['candidates'][0], array( 'external_id' => '770001', 'ballot_name' => '', 'full_name' => '', 'ballot_number' => '', 'party' => '', 'situation' => '' ) );
	$persist->invoke( AE_TSE_Client::instance(), $pc, 'EA20', $url, array( 'v' => 3 ), $blank );
	$check( 'A1: campo vazio do EA20 não apaga o cadastro', 'Cand 1' === $wpdb->get_var( "SELECT ballot_name FROM {$p}candidates WHERE election_id={$pe} AND external_id='770001'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// Transação: falha no meio (candidato duplicado viola a chave do ranking) não deixa snapshot parcial.
	$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}snapshots WHERE contest_id={$pc}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$broken = $make( 10, 50 );
	$broken['candidates'][9]['external_id'] = $broken['candidates'][0]['external_id'];
	$wpdb->suppress_errors( true );
	$threw = false;
	try { $persist->invoke( AE_TSE_Client::instance(), $pc, 'EA20', $url, array( 'v' => 4 ), $broken ); } catch ( Throwable $e ) { $threw = true; }
	$wpdb->suppress_errors( false );
	$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}snapshots WHERE contest_id={$pc}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}result_rows r LEFT JOIN {$p}snapshots s ON s.id=r.snapshot_id WHERE s.id IS NULL AND r.external_candidate_id BETWEEN '770001' AND '770010'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'transação: falha no ranking levanta erro e desfaz o snapshot', $threw && $before === $after && 0 === $orphans, "threw={$threw} before={$before} after={$after} orphans={$orphans}" );
} finally {
	$wpdb->query( "DELETE r FROM {$p}result_rows r JOIN {$p}snapshots s ON s.id=r.snapshot_id WHERE s.contest_id={$pc}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->delete( $p . 'snapshots', array( 'contest_id' => $pc ) );
	$wpdb->query( "DELETE l FROM {$p}candidate_contests l JOIN {$p}candidates c ON c.id=l.candidate_id WHERE c.election_id={$pe}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->delete( $p . 'candidates', array( 'election_id' => $pe ) );
	$wpdb->delete( $p . 'contests', array( 'id' => $pc ) );
	$wpdb->delete( $p . 'elections', array( 'id' => $pe ) );
}

echo $failures ? "\n" . count( $failures ) . " falha(s)\n" : "\nTudo certo.\n";
exit( $failures ? 1 : 0 );
