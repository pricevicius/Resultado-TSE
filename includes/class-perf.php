<?php
defined( 'ABSPATH' ) || exit;

/**
 * Amostras de tempo da coleta (download do TSE, job inteiro e tick), numa janela deslizante.
 * Serve para decidir o intervalo das disputas pesadas com dado real (ver A10 em PENDENCIAS.md):
 * aparece na Visão geral e em /wp-json/apuracao/v1/admin/health. Só o worker grava (um por vez,
 * pelo lock do job runner), então a opção não sofre corrida.
 */
final class AE_Perf {
	private const OPTION = 'ae_perf_samples';
	private const MAX_SAMPLES = 300;

	/** @param string $kind fetch | job | tick. @param int $code HTTP do download (0 nos demais). @param int $items Jobs executados no tick. */
	public static function record( string $kind, float $seconds, int $code = 0, int $items = 0 ): void {
		$samples = get_option( self::OPTION, array() );
		$samples = is_array( $samples ) ? $samples : array();
		$samples[] = array( 't' => time(), 'k' => $kind, 'ms' => (int) round( $seconds * 1000 ), 'c' => $code, 'n' => $items );
		if ( count( $samples ) > self::MAX_SAMPLES ) { $samples = array_slice( $samples, -self::MAX_SAMPLES ); }
		update_option( self::OPTION, $samples, false );
	}

	/** @return array<string,array{count:int,avg_ms:int,p95_ms:int,max_ms:int,last_at:int}> Por tipo; vazio se não há amostra. */
	public static function summary(): array {
		$samples = get_option( self::OPTION, array() );
		$by = array();
		foreach ( is_array( $samples ) ? $samples : array() as $s ) {
			if ( is_array( $s ) && isset( $s['k'], $s['ms'] ) ) { $by[ (string) $s['k'] ][] = $s; }
		}
		$out = array();
		foreach ( $by as $kind => $rows ) {
			$ms = array_map( static function ( $s ) { return (int) $s['ms']; }, $rows );
			sort( $ms );
			$count = count( $ms );
			$out[ $kind ] = array(
				'count'   => $count,
				'avg_ms'  => (int) round( array_sum( $ms ) / $count ),
				'p95_ms'  => $ms[ (int) min( $count - 1, floor( 0.95 * $count ) ) ],
				'max_ms'  => $ms[ $count - 1 ],
				'last_at' => (int) max( array_column( $rows, 't' ) ),
			);
		}
		return $out;
	}

	/** Texto curto para a tela de saúde: "média 420 ms · p95 900 ms · máx 1,2 s (38 amostras)". */
	public static function label( array $row ): string {
		$fmt = static function ( int $ms ): string { return $ms >= 1000 ? number_format_i18n( $ms / 1000, 1 ) . ' s' : $ms . ' ms'; };
		return 'média ' . $fmt( $row['avg_ms'] ) . ' · p95 ' . $fmt( $row['p95_ms'] ) . ' · máx ' . $fmt( $row['max_ms'] ) . ' (' . $row['count'] . ' amostras)';
	}
}
