<?php
defined( 'ABSPATH' ) || exit;

/** Discovers official TSE files. Editors never need to assemble or paste a URL. */
final class AE_TSE_Discovery {
	private const CANDIDATES_URL = 'https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_%d.zip';

	public static function environment( string $environment ): array {
		if ( 'simulado' === sanitize_key( $environment ) ) {
			return array( 'name' => 'simulado', 'base' => 'https://resultados-sim.tse.jus.br' );
		}
		return array( 'name' => 'oficial', 'base' => 'https://resultados.tse.jus.br' );
	}

	public static function config_url( string $environment ): string {
		$environment = self::environment( $environment );
		return $environment['base'] . '/' . $environment['name'] . '/comum/config/ele-c.json';
	}

	public static function candidates_url( int $year ): string {
		return sprintf( self::CANDIDATES_URL, max( 2000, $year ) );
	}

	public static function sync( array $payload ): array {
		$environment = sanitize_key( $payload['environment'] ?? 'oficial' );
		$year = max( 2022, absint( $payload['year'] ?? 2026 ) );
		$url = self::config_url( $environment );
		$catalog = AE_TSE_Client::instance()->fetch_json( $url, false );
		if ( empty( $catalog['pl'] ) || ! is_array( $catalog['pl'] ) ) {
			throw new RuntimeException( 'O EA11 do TSE não contém eleições disponíveis.' );
		}

		$cycle = sanitize_text_field( (string) ( $catalog['c'] ?? '' ) );
		$files = is_array( $catalog['arq'] ?? null ) ? $catalog['arq'] : array();
		$matched = 0;
		foreach ( $catalog['pl'] as $pleito ) {
			if ( ! is_array( $pleito ) || ! self::is_year( $pleito, $year ) ) { continue; }
			foreach ( (array) ( $pleito['e'] ?? array() ) as $election ) {
				if ( ! is_array( $election ) || ! self::is_year( $election, $year ) ) { continue; }
				self::save_election( $environment, $cycle, $files, $pleito, $election, $year );
				$matched++;
			}
		}
		if ( 0 === $matched ) {
			throw new RuntimeException( sprintf( 'O TSE respondeu, mas ainda não publicou a eleição de %d no EA11 (%s).', $year, $cycle ?: 'ciclo não informado' ) );
		}
		update_option( 'ae_tse_environment', $environment, false );
		update_option( 'ae_tse_catalog_checked_at', current_time( 'mysql', true ), false );
		AE_Logger::write( 'info', 'tse_catalog_synced', array( 'environment' => $environment, 'year' => $year, 'elections' => $matched, 'url' => $url ) );
		return array( 'complete' => true );
	}

	private static function save_election( string $environment, string $cycle, array $files, array $pleito, array $election, int $year ): void {
		global $wpdb;
		$p = $wpdb->prefix . 'ae_';
		$tse_code = absint( $election['cd'] ?? 0 );
		if ( ! $tse_code ) { return; }
		$round = max( 1, absint( $election['t'] ?? 1 ) );
		$slug = 'tse-' . $tse_code;
		$name = wp_strip_all_tags( html_entity_decode( (string) ( $election['nm'] ?? 'Eleição ' . $year ), ENT_QUOTES, 'UTF-8' ) );
		$now = current_time( 'mysql', true );
		$config = array( 'tse' => array( 'environment' => $environment, 'cycle' => $cycle, 'pleito_code' => (string) ( $pleito['cd'] ?? '' ), 'election_code' => $tse_code, 'round' => $round, 'files' => $files ) );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}elections WHERE slug=%s", $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data = array( 'name' => $name, 'year' => $year, 'timezone' => 'America/Sao_Paulo', 'status' => 'active', 'config_json' => wp_json_encode( $config ), 'updated_at' => $now );
		if ( $id ) { $wpdb->update( $p . 'elections', $data, array( 'id' => $id ) ); }
		else { $data['slug'] = $slug; $data['created_at'] = $now; $wpdb->insert( $p . 'elections', $data ); $id = (int) $wpdb->insert_id; }

		foreach ( (array) ( $election['abr'] ?? array() ) as $scope ) {
			$scope_code = strtolower( sanitize_key( $scope['cd'] ?? '' ) );
			foreach ( (array) ( $scope['cp'] ?? array() ) as $position ) {
				$position_code = str_pad( (string) absint( $position['cd'] ?? 0 ), 4, '0', STR_PAD_LEFT );
				if ( '0000' === $position_code || ! $scope_code ) { continue; }
				$source_url = self::result_url( $environment, $cycle, $tse_code, $scope_code, $position_code, $files );
				$contest_config = array( 'collection' => array( 'source_url' => $source_url, 'kind' => 'EA20', 'interval' => 60, 'enabled' => true, 'managed' => true ) );
				$seats = '0005' === $position_code ? 2 : 1;
				$external = $tse_code . '-r' . $round . '-' . $position_code . '-' . strtoupper( $scope_code );
				$sql = $wpdb->prepare( "INSERT INTO {$p}contests (election_id,external_id,round_no,position_code,position_name,scope_type,scope_code,scope_name,seats,active,config_json) VALUES (%d,%s,%d,%s,%s,%s,%s,%s,%d,1,%s) ON DUPLICATE KEY UPDATE position_name=VALUES(position_name),seats=VALUES(seats),active=1,config_json=VALUES(config_json)", $id, $external, $round, $position_code, sanitize_text_field( $position['ds'] ?? $position_code ), 'br' === $scope_code ? 'BR' : 'UF', strtoupper( $scope_code ), 'br' === $scope_code ? 'Brasil' : strtoupper( $scope_code ), $seats, wp_json_encode( $contest_config ) );
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}

	private static function result_url( string $environment, string $cycle, int $election, string $scope, string $position, array $files ): string {
		$env = self::environment( $environment );
		$template = '';
		foreach ( $files as $file ) { if ( is_array( $file ) && 'u' === ( $file['tp'] ?? '' ) ) { $template = (string) ( $file['dir'] ?? '' ); break; } }
		if ( '' === $template ) { throw new RuntimeException( 'O EA11 não informou o diretório do arquivo unificado EA20.' ); }
		$directory = strtr( $template, array( '<base>' => $env['base'], '<ambiente>' => $env['name'], '<ciclo>' => $cycle, '<cd_eleicao>' => (string) $election, '<uf>' => $scope ) );
		if ( str_contains( $directory, '<' ) || ! str_starts_with( $directory, $env['base'] . '/' ) ) { throw new RuntimeException( 'O diretório EA20 recebido do EA11 contém tokens não suportados.' ); }
		$election_file = str_pad( (string) $election, 6, '0', STR_PAD_LEFT );
		return trailingslashit( $directory ) . sprintf( '%s-c%s-e%s-u.json', $scope, $position, $election_file );
	}

	private static function is_year( array $item, int $year ): bool {
		return str_contains( (string) ( $item['dt'] ?? '' ), (string) $year ) || str_contains( wp_strip_all_tags( html_entity_decode( (string) ( $item['nm'] ?? '' ) ) ), (string) $year );
	}
}
