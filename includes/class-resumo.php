<?php
defined( 'ABSPATH' ) || exit;

/**
 * [tse_apuracao_resumo]: widget simples para a home. Um único bloco com uma linha por disputa
 * (líder, partido, percentual e % apurado), em vez de um card por disputa. Lê só os snapshots
 * locais, como os demais shortcodes; a atualização no navegador fica em assets/js/tse-resumo.js.
 */
final class AE_Resumo {
	/** Quantas linhas, no máximo: o widget é um resumo, não a página de apuração. */
	private const MAX_ROWS = 8;
	/** Quantos candidatos por disputa, no máximo. */
	private const MAX_LIMIT = 10;

	public static function render( $atts ): string {
		$site_uf = strtolower( (string) get_option( 'ae_site_uf', '' ) );
		$default = 'presidente:br' . ( '' !== $site_uf ? ",governador:{$site_uf},senador:{$site_uf}" : '' );
		$a = shortcode_atts( array( 'disputas' => $default, 'titulo' => 'Apuração', 'link' => '', 'link_texto' => 'Ver apuração completa', 'limite' => 3, 'atualizar' => 60, 'classe' => '' ), is_array( $atts ) ? $atts : array(), 'tse_apuracao_resumo' );
		TSE_Shortcode::enqueue_widget_assets();
		$limite = max( 1, min( self::MAX_LIMIT, (int) $a['limite'] ) );
		$rows = array();
		foreach ( self::parse_disputas( (string) $a['disputas'] ) as $row ) {
			$row['dados'] = TSE_API::is_mock_mode() ? TSE_API::get_mock_resultado() : TSE_Shortcode::snapshot_resultado( $row['cargo'], $row['uf'], $row['turno'] );
			$row['candidatos'] = isset( $row['dados']['erro'] ) ? array() : array_slice( (array) ( $row['dados']['candidatos'] ?? array() ), 0, $limite );
			$row['lider'] = $row['candidatos'][0] ?? null;
			$row['rotulo'] = ucfirst( str_replace( '-', ' ', $row['cargo'] ) ) . ' · ' . strtoupper( $row['uf'] );
			$rows[] = $row;
		}
		$titulo = sanitize_text_field( $a['titulo'] );
		$link = esc_url( (string) $a['link'] );
		$link_texto = sanitize_text_field( $a['link_texto'] );
		$atualizar = max( 0, (int) $a['atualizar'] );
		$classe = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( (string) $a['classe'] ) ) ) ) );
		$estado = self::estado( $rows );
		$widget_id = 'tse-resumo-' . uniqid();
		ob_start();
		include TSE_APURACAO_DIR . 'templates/resumo.php';
		return (string) ob_get_clean();
	}

	/**
	 * "governador:sp,senador:sp:2" → lista de cargo/uf/turno. Cargo desconhecido é ignorado, o turno
	 * é 1 ou 2 e o total é limitado a MAX_ROWS.
	 *
	 * @return array<int,array{cargo:string,uf:string,turno:int}>
	 */
	public static function parse_disputas( string $disputas ): array {
		$rows = array();
		foreach ( explode( ',', $disputas ) as $item ) {
			$parts = array_map( 'trim', explode( ':', $item ) );
			$cargo = sanitize_title( $parts[0] ?? '' );
			if ( '' === $cargo || ! isset( TSE_API::CARGOS[ $cargo ] ) ) { continue; }
			$uf = strtolower( preg_replace( '/[^A-Za-z]/', '', (string) ( $parts[1] ?? 'br' ) ) ) ?: 'br';
			$rows[] = array( 'cargo' => $cargo, 'uf' => substr( $uf, 0, 2 ), 'turno' => max( 1, min( 2, (int) ( $parts[2] ?? 1 ) ) ) );
			if ( count( $rows ) >= self::MAX_ROWS ) { break; }
		}
		return $rows;
	}

	/** Estado do bloco: atrasado se qualquer disputa está atrasada; concluída só se todas totalizaram. */
	private static function estado( array $rows ): string {
		$with_data = array_filter( $rows, static fn( array $r ): bool => null !== $r['lider'] );
		if ( ! $with_data ) { return 'Aguardando apuração'; }
		foreach ( $with_data as $r ) { if ( ! empty( $r['dados']['atrasado'] ) ) { return 'Dados atrasados'; } }
		foreach ( $with_data as $r ) { if ( 'Totalizado' !== ( $r['dados']['status'] ?? '' ) ) { return 'Ao vivo'; } }
		return 'Apuração concluída';
	}
}
