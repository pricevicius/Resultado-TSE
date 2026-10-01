<?php
defined( 'ABSPATH' ) || exit;

/**
 * Turno em andamento de uma disputa (cargo + abrangência). O turno é decidido por disputa, não pela eleição: em outubro
 * Presidente e alguns governadores podem ter 2º turno e Senador e deputados não.
 *
 * Regra: o 1º turno vale até o 2º ter um snapshot válido **com apuração iniciada** (progress diferente de "not_started").
 * O EA11 divulga a disputa do 2º turno dias antes e a coleta pode gravar um snapshot zerado; trocar a vitrine por ele
 * mostraria zeros por semanas. Depois que o 2º turno entra, ele não volta atrás.
 */
final class AE_Rounds {
	/** @var array<string,array> */
	private static array $memo = array();

	/**
	 * @param string $position Código do cargo com 4 dígitos ("0001").
	 * @param string $scope    Abrangência ("BR", "ES").
	 * @return array{round:int,rounds:int[],slug:?string} round = turno em andamento; rounds = turnos que já têm apuração iniciada
	 *                                                    (o que o seletor pode oferecer); slug = eleição do turno em andamento.
	 */
	public static function resolve( string $position, string $scope, ?int $year = null ): array {
		$year  = $year ?: (int) ( get_option( 'tse_apuracao_settings', array() )['ano'] ?? AE_Plugin::default_election_year() );
		$scope = strtoupper( $scope );
		$key   = $year . '|' . $position . '|' . $scope;
		if ( isset( self::$memo[ $key ] ) ) { return self::$memo[ $key ]; }

		global $wpdb; $p = $wpdb->prefix . 'ae_';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.round_no,e.slug,(SELECT s.totals_json FROM {$p}snapshots s WHERE s.contest_id=c.id AND s.status='valid' ORDER BY s.captured_at DESC, s.id DESC LIMIT 1) AS totals_json FROM {$p}contests c INNER JOIN {$p}elections e ON e.id=c.election_id WHERE e.year=%d AND c.position_code=%s AND c.scope_code=%s AND c.active=1 ORDER BY c.round_no ASC", $year, $position, $scope ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rounds = array(); $slugs = array();
		foreach ( (array) $rows as $row ) {
			$round = (int) $row['round_no'];
			if ( null === $row['totals_json'] ) { continue; }
			$totals = json_decode( (string) $row['totals_json'], true );
			// O 1º turno entra mesmo zerado (é o padrão); os demais só depois de a apuração começar.
			if ( 1 !== $round && 'not_started' === ( is_array( $totals ) ? ( $totals['progress'] ?? '' ) : '' ) ) { continue; }
			$rounds[]        = $round;
			$slugs[ $round ] = (string) $row['slug'];
		}
		$round = $rounds ? max( $rounds ) : 1;
		if ( ! $slugs ) {
			foreach ( (array) $rows as $row ) { if ( 1 === (int) $row['round_no'] ) { $slugs[1] = (string) $row['slug']; } }
		}
		return self::$memo[ $key ] = array( 'round' => $round, 'rounds' => $rounds, 'slug' => $slugs[ $round ] ?? null );
	}

	/** Só para os testes: o resultado é guardado por requisição. */
	public static function flush(): void { self::$memo = array(); }
}
