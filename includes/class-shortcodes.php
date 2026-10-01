<?php
defined( 'ABSPATH' ) || exit;

final class AE_Shortcodes {
	private static $instance = null;
	public static function instance(): AE_Shortcodes { if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }
	public function register(): void {
		add_shortcode( 'apuracao', array( $this, 'render' ) );
		add_shortcode( 'apuracao_candidato', array( $this, 'candidate' ) );
		add_shortcode( 'apuracao_candidatos', array( $this, 'catalog' ) );
		add_shortcode( 'apuracao_navegacao', array( $this, 'navigation' ) );
		add_shortcode( 'tse_apuracao_resumo', array( 'AE_Resumo', 'render' ) );
	}
	/** O WordPress entrega '' (string) quando o shortcode não tem atributos em versões antigas; por isso $atts não é tipado como array. */
	public function render( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$a = shortcode_atts( array( 'eleicao' => 'eleicoes-2026', 'turno' => 1, 'cargo' => '', 'abrangencia' => 'BR', 'titulo' => '' ), $atts, 'apuracao' );
		if ( ! $a['cargo'] ) { return ''; }
		$by_code = array_flip( TSE_API::CARGOS );
		$code = (string) absint( $a['cargo'] );
		if ( ! isset( $by_code[ $code ] ) ) { return current_user_can( 'edit_posts' ) ? '<p class="ae-empty">Cargo não reconhecido.</p>' : ''; }
		return TSE_Shortcode::render( array( 'cargo' => $by_code[ $code ], 'uf' => strtolower( sanitize_key( $a['abrangencia'] ) ), 'turno' => absint( $a['turno'] ), 'titulo' => sanitize_text_field( $a['titulo'] ) ) );
	}
	public function candidate( $atts ): string { return AE_Candidate_Catalog::render( array() ); }
	public function catalog( $atts ): string { return AE_Candidate_Catalog::render( is_array( $atts ) ? $atts : array() ); }
	public function navigation( $atts ): string { return AE_Navigation::shortcode(); }
}
