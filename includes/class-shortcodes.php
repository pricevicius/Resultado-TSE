<?php
defined( 'ABSPATH' ) || exit;

final class TSE_Shortcodes {
	private static ?TSE_Shortcodes $instance = null;
	public static function instance(): TSE_Shortcodes { return self::$instance ??= new self(); }
	public function register(): void {
		add_shortcode( 'apuracao', array( $this, 'render' ) );
		add_shortcode( 'apuracao_candidato', array( $this, 'candidate' ) );
		add_shortcode( 'apuracao_candidatos', array( $this, 'catalog' ) );
	}
	public function render( array $atts ): string {
		$a = shortcode_atts( array( 'eleicao' => 'eleicoes-2026', 'turno' => 1, 'cargo' => '', 'abrangencia' => 'BR', 'titulo' => '' ), $atts, 'apuracao' );
		if ( ! $a['cargo'] ) { return ''; }
		$by_code = array_flip( TSE_API::CARGOS );
		$code = (string) absint( $a['cargo'] );
		if ( ! isset( $by_code[ $code ] ) ) { return current_user_can( 'edit_posts' ) ? '<p class="ae-empty">Cargo não reconhecido.</p>' : ''; }
		return TSE_Shortcode::render( array( 'cargo' => $by_code[ $code ], 'uf' => strtolower( sanitize_key( $a['abrangencia'] ) ), 'turno' => absint( $a['turno'] ), 'titulo' => sanitize_text_field( $a['titulo'] ) ) );
	}
	public function candidate( array $atts ): string { return TSE_Candidate_Catalog::render( array() ); }
	public function catalog( array $atts ): string { return TSE_Candidate_Catalog::render( $atts ); }
}
