<?php
/**
 * Importação de candidatos pelo caminho do ZIP de verdade (Dados Abertos do TSE), sem rede: o download
 * é interceptado e entrega um ZIP montado aqui, com um CSV por UF em ISO-8859-1 (como o TSE publica),
 * cargos de vice, uma UF fora da seleção e mais de 250 linhas numa UF (paginação por cursor entre
 * arquivos do ZIP). Precisa da extensão zip; sem ela o teste é ignorado.
 *
 *   wp eval-file wp-content/plugins/tse-apuracao/tests/wp-import-zip.php
 *
 * Cria eleição e disputas temporárias (UFs fictícias ZW, ZV, ZU) e remove tudo no final.
 */
if ( ! class_exists( 'ZipArchive' ) ) { echo "ignorado: este PHP não tem a extensão zip (rode com php-zip, ver PENDENCIAS.md)\n"; exit( 0 ); }
$failures = array();
$check = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' — ' . $detail ) . "\n";
	if ( ! $ok ) { $failures[] = $name; }
};
global $wpdb;
$p = $wpdb->prefix . 'ae_';
$now = current_time( 'mysql', true );
$saved = array( 'ae_last_import' => get_option( 'ae_last_import', null ) );
$wpdb->insert( $p . 'elections', array( 'slug' => 'ae-test-zip', 'name' => 'Teste ZIP', 'year' => 2026, 'timezone' => 'America/Sao_Paulo', 'status' => 'draft', 'config_json' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
$eid = (int) $wpdb->insert_id;
$contest_ids = array();
foreach ( array( array( 'ZW', '0006' ), array( 'ZV', '0006' ), array( 'BR', '0001' ), array( 'ZU', '0006' ) ) as $c ) {
	$wpdb->insert( $p . 'contests', array( 'election_id' => $eid, 'external_id' => 'ae-test-zip-' . $c[1] . $c[0], 'round_no' => 1, 'position_code' => $c[1], 'position_name' => 'Teste', 'scope_type' => $c[0] === 'BR' ? 'BR' : 'UF', 'scope_code' => $c[0], 'scope_name' => 'Teste', 'seats' => 1, 'active' => 1, 'config_json' => '{}' ) );
	$contest_ids[ $c[0] ] = (int) $wpdb->insert_id;
}
$header = array( 'DT_GERACAO', 'HH_GERACAO', 'NR_TURNO', 'SG_UF', 'CD_CARGO', 'SQ_CANDIDATO', 'NR_CANDIDATO', 'NM_CANDIDATO', 'NM_URNA_CANDIDATO', 'SG_PARTIDO', 'DS_SITUACAO_CANDIDATURA' );
$csv_latin1 = static function ( array $rows ) use ( $header ): string {
	$out = '"' . implode( '";"', $header ) . "\"\r\n";
	foreach ( $rows as $r ) { $out .= '"' . implode( '";"', $r ) . "\"\r\n"; }
	return mb_convert_encoding( $out, 'ISO-8859-1', 'UTF-8' );
};
$row = static function ( string $uf, string $cargo, string $sq, string $name, string $urn = '' ) { return array( '28/09/2026', '09:41:32', '1', $uf, $cargo, $sq, '1' . substr( $sq, -3 ), $name, '' === $urn ? $name : $urn, 'ABC', 'Deferido' ); };
$zw = array(); for ( $i = 1; $i <= 300; $i++ ) { $zw[] = $row( 'ZW', '6', (string) ( 100000 + $i ), 'CANDIDATO ' . $i . ' DA SILVA' ); }
$zw[] = $row( 'ZW', '6', '100301', 'JOSÉ AÇAÍ NÓBREGA', 'ZÉ DO AÇAÍ' );
$zw[] = $row( 'ZW', '9', '100302', 'SUPLENTE' ); // suplente
$zv = array( $row( 'ZV', '6', '200001', 'ANA PAULA' ), $row( 'ZV', '6', '200002', 'BRUNO' ), $row( 'ZV', '2', '200003', 'VICE' ) );
$zu = array( $row( 'ZU', '6', '300001', 'FORA DA SELEÇÃO' ) );
$br = array( $row( 'BR', '1', '400001', 'PRESIDENTE UM' ), $row( 'BR', '2', '400002', 'VICE-PRESIDENTE' ) );
$build_zip = static function ( array $files ): string {
	$path = wp_tempnam( 'ae-zip' );
	$zip = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $files as $name => $content ) { $zip->addFromString( $name, $content ); }
	$zip->close();
	$bytes = (string) file_get_contents( $path );
	unlink( $path );
	return $bytes;
};
$zip_bytes = $build_zip( array(
	'consulta_cand_2026_BRASIL.csv' => $csv_latin1( $br ),
	'consulta_cand_2026_ZU.csv' => $csv_latin1( $zu ),
	'consulta_cand_2026_ZV.csv' => $csv_latin1( $zv ),
	'consulta_cand_2026_ZW.csv' => $csv_latin1( $zw ),
	'leiame.pdf' => 'não é CSV',
) );
$server = array( 'mode' => 'ok', 'bytes' => $zip_bytes, 'hits' => 0 );
$import_url = 'https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_2026.zip';
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$server, $import_url ) {
	if ( $url !== $import_url ) { return new WP_Error( 'blocked', 'Teste bloqueou rede real: ' . $url ); }
	$server['hits']++;
	if ( '500' === $server['mode'] ) { return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 500, 'message' => '' ), 'cookies' => array(), 'filename' => null ); }
	$bytes = 'garbage' === $server['mode'] ? 'isto não é um zip' : $server['bytes'];
	if ( ! empty( $args['filename'] ) ) { file_put_contents( $args['filename'], $bytes ); }
	return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
}, 10, 3 );
$import = static function ( int $job_id ) use ( $eid, $import_url ): array {
	$payload = array( 'election_id' => $eid, 'source_url' => $import_url, 'format' => 'zip', 'ufs' => array( 'ZW', 'ZV' ), 'cargos' => array( '1', '6' ) );
	$cursor = array(); $steps = 0;
	for ( $i = 0; $i < 30; $i++ ) {
		$out = AE_TSE_Client::instance()->import_candidates_page( $payload, $cursor, $job_id );
		$steps++;
		if ( ! empty( $out['complete'] ) ) { return array( $out, $steps ); }
		$cursor = $out['cursor'];
	}
	throw new RuntimeException( 'Importação não terminou.' );
};
$count = static function ( string $where = '1=1' ) use ( $wpdb, $p, $eid ): int { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}candidates WHERE election_id={$eid} AND {$where}" ); }; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

try {
	// 1) Importação completa pelo ZIP.
	list( $out, $steps ) = $import( 910001 );
	$check( 'zip: importou 300+1 de ZW, 2 de ZV e 1 Presidente (vices, suplente e UF fora da seleção ficam de fora)', 304 === $count(), (string) $count() );
	$check( 'zip: a UF fora da seleção (ZU) não entrou', 0 === $count( "external_id='300001'" ) );
	$check( 'zip: vice e suplente não entram', 0 === $count( "external_id IN ('200003','400002','100302')" ) );
	$check( 'zip: ZW com mais de 250 linhas pagina por cursor (mais de um passo)', $steps >= 4, (string) $steps );
	$name = (string) $wpdb->get_var( "SELECT CONCAT(full_name,'|',ballot_name) FROM {$p}candidates WHERE election_id={$eid} AND external_id='100301'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'zip: acentos do ISO-8859-1 chegam em UTF-8', 'JOSÉ AÇAÍ NÓBREGA|ZÉ DO AÇAÍ' === $name, $name );
	$check( 'zip: candidato vinculado à disputa da UF (cargo+UF+turno)', 301 === $count( 'contest_id=' . $contest_ids['ZW'] ) && 2 === $count( 'contest_id=' . $contest_ids['ZV'] ) && 1 === $count( 'contest_id=' . $contest_ids['BR'] ) );
	$links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}candidate_contests l JOIN {$p}candidates c ON c.id=l.candidate_id WHERE c.election_id={$eid}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'zip: o vínculo candidato x disputa foi gravado para todos', 304 === $links, (string) $links );
	$last = get_option( 'ae_last_import' );
	$check( 'zip: ae_last_import registra as 304 linhas e a geração do CSV', is_array( $last ) && 304 === (int) $last['rows'] && '2026-09-28 09:41:32' === $last['csv_generated_at'], wp_json_encode( $last ) );
	$check( 'zip: o arquivo temporário do download foi apagado ao concluir', false === get_transient( 'ae_import_file_910001' ) );

	// 2) Reimportação: um candidato de ZV some do ZIP e é marcado como removido.
	sleep( 2 );
	$server['bytes'] = $build_zip( array(
		'consulta_cand_2026_BRASIL.csv' => $csv_latin1( $br ),
		'consulta_cand_2026_ZV.csv' => $csv_latin1( array( $zv[0], $zv[2] ) ),
		'consulta_cand_2026_ZW.csv' => $csv_latin1( $zw ),
	) );
	$import( 910002 );
	$removed = array_map( 'strval', $wpdb->get_col( "SELECT external_id FROM {$p}candidates WHERE election_id={$eid} AND removed_at IS NOT NULL" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'zip: reimportação marca só quem saiu do ZIP (BRUNO, de ZV)', array( '200002' ) === $removed, wp_json_encode( $removed ) );

	// 3) Falhas: HTTP 500 e ZIP inválido levantam erro (retry) e não deixam arquivo temporário nem candidatos novos.
	$before = $count();
	$server['mode'] = '500'; $err = '';
	try { $import( 910003 ); } catch ( Throwable $e ) { $err = $e->getMessage(); }
	$check( 'zip: HTTP 500 do TSE levanta erro para o retry', false !== strpos( $err, '500' ), $err );
	$server['mode'] = 'garbage'; $err = '';
	try { $import( 910004 ); } catch ( Throwable $e ) { $err = $e->getMessage(); }
	$check( 'zip: arquivo que não é ZIP levanta erro claro', false !== strpos( $err, 'ZIP' ), $err );
	$check( 'zip: as falhas não alteraram o cadastro', $before === $count() );
} finally {
	remove_all_filters( 'pre_http_request' );
	$wpdb->query( "DELETE l FROM {$p}candidate_contests l JOIN {$p}candidates c ON c.id=l.candidate_id WHERE c.election_id={$eid}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->delete( $p . 'candidates', array( 'election_id' => $eid ) );
	$wpdb->delete( $p . 'contests', array( 'election_id' => $eid ) );
	$wpdb->delete( $p . 'elections', array( 'id' => $eid ) );
	foreach ( array( 910001, 910002, 910003, 910004 ) as $job ) { $t = get_transient( 'ae_import_file_' . $job ); if ( $t && is_file( $t ) ) { unlink( $t ); } delete_transient( 'ae_import_file_' . $job ); }
	foreach ( $saved as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value, false ); }
}
echo $failures ? "\n" . count( $failures ) . " falha(s)\n" : "\nTudo certo.\n";
exit( $failures ? 1 : 0 );
