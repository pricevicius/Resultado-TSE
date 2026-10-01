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

	/**
	 * Disputas da UF do site que estão desligadas. Foi assim que o Deputado Federal ficou parado sem
	 * nenhum erro visível: desligada na Seleção de disputas, a coleta simplesmente não acontece.
	 * Cargos nacionais (BR) não entram: só a UF do site importa aqui.
	 *
	 * @param array<int,object> $contests Linhas com position_name, scope_code, round_no e config_json.
	 * @return array<int,object> As disputas da UF que não estão ligadas.
	 */
	public static function disabled_in_site_uf( array $contests, string $site_uf ): array {
		$site_uf = strtoupper( trim( $site_uf ) );
		if ( '' === $site_uf ) { return array(); }
		$off = array();
		foreach ( $contests as $contest ) {
			if ( strtoupper( (string) $contest->scope_code ) !== $site_uf ) { continue; }
			$config = json_decode( (string) $contest->config_json, true );
			if ( isset( $config['collection']['enabled'] ) && ! $config['collection']['enabled'] ) { $off[] = $contest; }
		}
		return $off;
	}

	/** Cargos (CD_CARGO sem zero à esquerda) → rótulo curto, para a tela de importação. */
	public const CARGO_LABELS = array( '1' => 'Presidente', '3' => 'Governador', '5' => 'Senador', '6' => 'Dep. Federal', '7' => 'Dep. Estadual', '8' => 'Dep. Distrital' );

	/**
	 * UFs e cargos a importar, derivados das disputas ligadas na Seleção de disputas (a mesma
	 * seleção da coleta). Disputa sem a chave enabled conta como ligada.
	 *
	 * @param array<int,object> $contests Linhas com position_code, scope_code e config_json.
	 * @return array{ufs:string[],cargos:string[],cargo_labels:string[]}
	 */
	public static function import_scope( array $contests ): array {
		$ufs = array(); $cargos = array(); $labels = array();
		foreach ( $contests as $c ) {
			$config = json_decode( (string) $c->config_json, true );
			$enabled = ! isset( $config['collection']['enabled'] ) || $config['collection']['enabled'];
			if ( ! $enabled ) { continue; }
			if ( 'BR' !== $c->scope_code ) { $ufs[ $c->scope_code ] = true; }
			$cargo = (string) absint( $c->position_code );
			$cargos[ $cargo ] = true;
			$labels[ self::CARGO_LABELS[ $cargo ] ?? $cargo ] = true;
		}
		return array( 'ufs' => array_keys( $ufs ), 'cargos' => array_keys( $cargos ), 'cargo_labels' => array_keys( $labels ) );
	}

	/**
	 * Payload do job import_candidates de uma eleição, com o escopo da seleção atual.
	 * Devolve null se a eleição não existe. Sem nenhuma disputa ligada, o payload não traz
	 * ufs/cargos e a importação cobriria o Brasil inteiro (quem agenda deve recusar isso).
	 */
	public static function import_payload( int $election_id ): ?array {
		global $wpdb;
		$p = $wpdb->prefix . 'ae_';
		$year = absint( $wpdb->get_var( $wpdb->prepare( "SELECT year FROM {$p}elections WHERE id=%d", $election_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $election_id || ! $year ) { return null; }
		$contests = $wpdb->get_results( $wpdb->prepare( "SELECT position_code,scope_code,config_json FROM {$p}contests WHERE election_id=%d AND active=1", $election_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$scope = self::import_scope( (array) $contests );
		$payload = array( 'election_id' => $election_id, 'source_url' => AE_TSE_Discovery::candidates_url( $year ), 'format' => 'zip' );
		if ( $scope['ufs'] ) { $payload['ufs'] = $scope['ufs']; $payload['cargos'] = $scope['cargos']; }
		return $payload;
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
