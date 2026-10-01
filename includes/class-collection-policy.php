<?php
defined( 'ABSPATH' ) || exit;

/**
 * Intervalo de coleta por tipo de disputa.
 *
 * O worker é sequencial e cada coleta de Deputado Federal/Estadual tem centenas de candidatos
 * por UF (pode levar minutos), então elas atrasam todas as outras quando há várias UFs ligadas.
 * A regra, aplicada só com MAIS DE UMA UF ligada:
 *  - leves (Presidente, Governador, Senador, Prefeito): 60 s e vão primeiro na fila;
 *  - pesadas (Deputado Federal, Estadual e Distrital): intervalo maior (padrão 120 s).
 * Com uma UF só, tudo fica em 60 s.
 *
 * O intervalo vive em config_json.collection.interval. config_json.collection.interval_mode
 * diz quem decidiu: 'auto' (esta regra, recalculada quando o conjunto de UFs muda) ou
 * 'manual' (o admin ajustou na Seleção de disputas e a regra nunca sobrescreve).
 */
final class AE_Collection_Policy {
	public const DEFAULT_INTERVAL = 60;
	public const HEAVY_INTERVAL = 120;
	public const MIN_INTERVAL = 30;
	public const MAX_INTERVAL = 900;
	/** Atraso na fila das pesadas, para uma leve que vença depois ainda passar à frente. */
	public const HEAVY_QUEUE_DELAY = 5;
	/** Deputado Federal, Estadual e Distrital. */
	private const HEAVY_POSITIONS = array( '0006', '0007', '0008' );

	public static function is_heavy( string $position_code ): bool {
		return in_array( str_pad( $position_code, 4, '0', STR_PAD_LEFT ), self::HEAVY_POSITIONS, true );
	}

	/** Intervalo automático das pesadas; ajustável pelo filtro ae_heavy_interval. */
	public static function heavy_interval(): int {
		return self::clamp( (int) apply_filters( 'ae_heavy_interval', self::HEAVY_INTERVAL ) );
	}

	public static function clamp( int $seconds ): int {
		return max( self::MIN_INTERVAL, min( self::MAX_INTERVAL, $seconds ) );
	}

	/** Intervalo que a regra define para a disputa, dado se há mais de uma UF ligada. */
	public static function automatic_interval( string $position_code, bool $multi_uf ): int {
		return $multi_uf && self::is_heavy( $position_code ) ? self::heavy_interval() : self::DEFAULT_INTERVAL;
	}

	/**
	 * Quantas UFs distintas têm coleta ligada. O escopo nacional (Presidente) não conta.
	 *
	 * @param array<int,object> $contests Linhas com scope_code e config_json.
	 */
	public static function count_enabled_ufs( array $contests ): int {
		$ufs = array();
		foreach ( $contests as $contest ) {
			$scope = strtoupper( (string) $contest->scope_code );
			if ( '' === $scope || 'BR' === $scope ) { continue; }
			$config = json_decode( (string) $contest->config_json, true );
			if ( is_array( $config ) && ! empty( $config['collection']['enabled'] ) ) { $ufs[ $scope ] = true; }
		}
		return count( $ufs );
	}

	/** A regra só vale com mais de uma UF ligada. */
	public static function is_multi_uf(): bool {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT scope_code,config_json FROM {$wpdb->prefix}ae_contests WHERE active=1 AND config_json IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::count_enabled_ufs( (array) $rows ) > 1;
	}

	/**
	 * Quem decidiu o intervalo desta disputa. Disputas sincronizadas antes desta regra não têm
	 * interval_mode: valem como automáticas se o valor é um dos padrões (60 s ou o das pesadas)
	 * e como manuais em qualquer outro caso, para nunca apagar um ajuste que alguém já fez.
	 */
	public static function is_manual( array $collection ): bool {
		if ( isset( $collection['interval_mode'] ) ) { return 'manual' === $collection['interval_mode']; }
		$interval = absint( $collection['interval'] ?? self::DEFAULT_INTERVAL );
		return ! in_array( $interval, array( self::DEFAULT_INTERVAL, self::HEAVY_INTERVAL, self::heavy_interval() ), true );
	}

	/** Tempo sem checagem, em segundos, a partir do qual o front mostra "Dados atrasados". */
	public static function stale_after( int $interval ): int {
		return max( 3 * MINUTE_IN_SECONDS, 3 * $interval );
	}

	/**
	 * Recalcula o intervalo automático de todas as disputas gerenciadas (as manuais ficam como estão).
	 * Chamada depois de sincronizar o EA11 e depois de salvar a Seleção de disputas, que são os
	 * momentos em que o conjunto de UFs ligadas muda.
	 *
	 * @return int Quantas disputas mudaram de intervalo.
	 */
	public static function apply_all(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'ae_contests';
		$rows = (array) $wpdb->get_results( "SELECT id,position_code,scope_code,config_json FROM {$table} WHERE active=1 AND config_json IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$multi_uf = self::count_enabled_ufs( $rows ) > 1;
		$changed = 0;
		foreach ( $rows as $row ) {
			$config = json_decode( (string) $row->config_json, true );
			if ( ! is_array( $config ) || ! is_array( $config['collection'] ?? null ) || empty( $config['collection']['managed'] ) ) { continue; }
			if ( self::is_manual( $config['collection'] ) ) { continue; }
			$interval = self::automatic_interval( (string) $row->position_code, $multi_uf );
			if ( (int) ( $config['collection']['interval'] ?? 0 ) === $interval && 'auto' === ( $config['collection']['interval_mode'] ?? '' ) ) { continue; }
			$config['collection']['interval'] = $interval;
			$config['collection']['interval_mode'] = 'auto';
			$wpdb->update( $table, array( 'config_json' => wp_json_encode( $config ) ), array( 'id' => (int) $row->id ) );
			$changed++;
		}
		return $changed;
	}
}
