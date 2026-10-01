<?php
/**
 * Massa de teste do turno automático no navegador (tests/e2e/turno-browser.js). Uso, no container do site:
 *   wp eval-file .../tests/e2e/turno-fixture.php setup     disputa ZY-E2E: 1º turno parcial e 2º turno só com snapshot zerado, mais a página ae-e2e-turno
 *   wp eval-file .../tests/e2e/turno-fixture.php advance   o 2º turno passa a ter apuração iniciada (os 2 finalistas com votos)
 *   wp eval-file .../tests/e2e/turno-fixture.php cleanup   remove tudo (eleição, disputas, candidatos, snapshots e a página)
 * Só mexe em registros "ae-e2e-turno"; nenhuma requisição ao TSE.
 */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
global $wpdb;
$p     = $wpdb->prefix . 'ae_';
$mode  = $args[0] ?? '';
$year  = (int) ( get_option( 'tse_apuracao_settings', array() )['ano'] ?? AE_Plugin::default_election_year() );
$slug  = 'ae-e2e-turno';
$eid   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}elections WHERE slug=%s", $slug ) );

$cleanup = static function () use ( $wpdb, $p, $slug, $eid ): void {
	if ( $eid ) {
		$wpdb->query( "DELETE r FROM {$p}result_rows r JOIN {$p}snapshots s ON s.id=r.snapshot_id JOIN {$p}contests c ON c.id=s.contest_id WHERE c.election_id={$eid}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE s FROM {$p}snapshots s JOIN {$p}contests c ON c.id=s.contest_id WHERE c.election_id={$eid}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE l FROM {$p}candidate_contests l JOIN {$p}candidates c ON c.id=l.candidate_id WHERE c.election_id={$eid}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $p . 'candidates', array( 'election_id' => $eid ) );
		$wpdb->delete( $p . 'contests', array( 'election_id' => $eid ) );
		$wpdb->delete( $p . 'elections', array( 'id' => $eid ) );
	}
	foreach ( get_posts( array( 'post_type' => 'page', 'name' => $slug, 'post_status' => 'any', 'numberposts' => 5 ) ) as $page ) { wp_delete_post( $page->ID, true ); }
};

if ( 'cleanup' === $mode ) { $cleanup(); echo "limpo\n"; return; }

$snapshot = static function ( int $contest_id, string $progress, array $rows ) use ( $wpdb, $p ): void {
	$now = current_time( 'mysql', true );
	$wpdb->insert( $p . 'snapshots', array( 'contest_id' => $contest_id, 'source' => 'e2e', 'source_url' => 'https://example.invalid/e2e', 'source_sha256' => hash( 'sha256', $contest_id . $progress . microtime() ), 'captured_at' => $now, 'generated_at' => $now, 'sequence_no' => time(), 'status' => 'valid', 'totals_json' => wp_json_encode( array( 'progress' => $progress, 'reported_sections' => 'not_started' === $progress ? 0 : 50, 'total_sections' => 100, 'reported_percentage' => 'not_started' === $progress ? 0 : 50 ) ) ) );
	$sid = (int) $wpdb->insert_id;
	foreach ( $rows as $i => $r ) {
		$cand = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}candidates WHERE external_id=%s", 'e2e-' . $r[0] ) );
		$wpdb->insert( $p . 'result_rows', array( 'snapshot_id' => $sid, 'candidate_id' => $cand, 'external_candidate_id' => 'e2e-' . $r[0], 'rank_no' => $i + 1, 'votes' => $r[1], 'percentage' => $r[2], 'elected' => 0, 'situation' => '', 'ballot_name' => 'CAND ' . $r[0], 'ballot_number' => (string) ( 10 + $r[0] ), 'party' => 'TST' ) );
	}
};

if ( 'setup' === $mode ) {
	$cleanup();
	$now = current_time( 'mysql', true );
	$wpdb->insert( $p . 'elections', array( 'slug' => $slug, 'name' => 'E2E turno', 'year' => $year, 'timezone' => 'America/Sao_Paulo', 'status' => 'active', 'config_json' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
	$eid = (int) $wpdb->insert_id;
	$contest = static function ( int $round ) use ( $wpdb, $p, $eid ): int {
		$wpdb->insert( $p . 'contests', array( 'election_id' => $eid, 'external_id' => 'e2e-' . $round, 'round_no' => $round, 'position_code' => '0003', 'position_name' => 'Governador', 'scope_type' => 'UF', 'scope_code' => 'EY', 'scope_name' => 'E2E', 'seats' => 1, 'active' => 1, 'config_json' => wp_json_encode( array( 'collection' => array( 'enabled' => false ) ) ) ) );
		return (int) $wpdb->insert_id;
	};
	$c1 = $contest( 1 ); $c2 = $contest( 2 );
	foreach ( range( 1, 5 ) as $n ) {
		$wpdb->insert( $p . 'candidates', array( 'election_id' => $eid, 'external_id' => 'e2e-' . $n, 'contest_id' => $c1, 'ballot_name' => 'CAND ' . $n, 'full_name' => 'Candidato ' . $n, 'ballot_number' => (string) ( 10 + $n ), 'party' => 'TST', 'photo_url' => '', 'updated_at' => $now ) );
		$wpdb->insert( $p . 'candidate_contests', array( 'candidate_id' => $wpdb->insert_id, 'contest_id' => $c1 ) );
		if ( $n <= 2 ) { $wpdb->insert( $p . 'candidate_contests', array( 'candidate_id' => (int) $wpdb->get_var( "SELECT id FROM {$p}candidates WHERE external_id='e2e-{$n}'" ), 'contest_id' => $c2 ) ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$snapshot( $c1, 'partial', array( array( 1, 400, 30.0 ), array( 2, 350, 26.0 ), array( 3, 300, 22.0 ), array( 4, 200, 15.0 ), array( 5, 100, 7.0 ) ) );
	$snapshot( $c2, 'not_started', array( array( 1, 0, 0 ), array( 2, 0, 0 ) ) );
	$block = 'cargo="governador" uf="ey" atualizar="5"';
	$content = "[tse_apuracao {$block}]\n[tse_apuracao {$block}]\n[tse_apuracao_card {$block}]\n[tse_apuracao_card cargo=\"governador\" uf=\"ey\" limite=\"3\" atualizar=\"5\"]\n[tse_apuracao_resumo disputas=\"governador:ey,senador:ey\" atualizar=\"5\"]\n[apuracao_candidatos_lista cargo=\"governador\" uf=\"ey\" mostrar=\"percentual\" atualizar=\"15\"]";
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => 'E2E turno', 'post_content' => $content ) );
	echo "pronto: /{$slug}/\n";
	return;
}

if ( 'advance' === $mode && $eid ) {
	$c2 = (int) $wpdb->get_var( "SELECT id FROM {$p}contests WHERE election_id={$eid} AND round_no=2" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$snapshot( $c2, 'partial', array( array( 2, 520, 52.0 ), array( 1, 480, 48.0 ) ) );
	echo "2º turno em andamento\n";
	return;
}
fwrite( STDERR, "uso: setup | advance | cleanup\n" ); exit( 1 );
