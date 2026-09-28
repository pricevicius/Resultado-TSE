<?php
defined( 'ABSPATH' ) || exit;

/** Menu de navegação entre a página de Apuração e a de Candidatos, resolvido por ID de página em vez de URL fixa. */
final class AE_Navigation {
	const OPTION_RESULTS    = 'ae_nav_results_page_id';
	const OPTION_CANDIDATES = 'ae_nav_candidates_page_id';

	public static function shortcode(): string {
		$results_id    = (int) get_option( self::OPTION_RESULTS );
		$candidates_id = (int) get_option( self::OPTION_CANDIDATES );

		if ( ! $results_id || ! $candidates_id ) {
			return current_user_can( 'manage_options' )
				? '<p class="ae-empty">Navegação não configurada — defina as páginas de Apuração e Candidatos em Apuração → Configuração.</p>'
				: '';
		}

		$results_url    = get_permalink( $results_id );
		$candidates_url = get_permalink( $candidates_id );
		if ( ! $results_url || ! $candidates_url ) {
			return '';
		}

		$current_id = get_queried_object_id();

		return '<nav class="ae-election-nav" aria-label="Eleições 2026">'
			. '<a' . ( $current_id === $results_id ? ' aria-current="page"' : '' ) . ' href="' . esc_url( $results_url ) . '">Apuração</a>'
			. '<a' . ( $current_id === $candidates_id ? ' aria-current="page"' : '' ) . ' href="' . esc_url( $candidates_url ) . '">Candidatos</a>'
			. '</nav>';
	}
}
