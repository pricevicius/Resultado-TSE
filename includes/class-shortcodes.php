<?php
defined( 'ABSPATH' ) || exit;

final class AE_Shortcodes {
	private static ?AE_Shortcodes $instance = null;
	public static function instance(): AE_Shortcodes { return self::$instance ??= new self(); }
	public function register(): void {
		add_shortcode( 'apuracao', array( $this, 'render' ) );
		add_shortcode( 'apuracao_candidato', array( $this, 'candidate' ) );
	}
	public function render( array $atts ): string {
		$a = shortcode_atts( array( 'eleicao' => 'eleicoes-2026', 'turno' => 1, 'cargo' => '', 'abrangencia' => 'BR', 'titulo' => '' ), $atts, 'apuracao' );
		if ( ! $a['cargo'] ) { return ''; }
		$data = AE_Results::instance()->latest( sanitize_title( $a['eleicao'] ), absint( $a['turno'] ), sanitize_key( $a['cargo'] ), sanitize_key( $a['abrangencia'] ) );
		if ( ! $data ) { return current_user_can( 'edit_posts' ) ? '<p class="ae-empty">Disputa ainda nao configurada.</p>' : ''; }
		ob_start();
		?><section class="ae-results" data-ae-snapshot="<?php echo esc_attr( $data['snapshot']['id'] ?? '' ); ?>">
			<?php if ( $a['titulo'] ) : ?><h2><?php echo esc_html( $a['titulo'] ); ?></h2><?php endif; ?>
			<?php if ( $data['snapshot'] ) : ?><p class="ae-updated">Atualizado em <?php echo esc_html( $data['snapshot']['captured_at'] ); ?></p><ol><?php foreach ( $data['candidates'] as $candidate ) : ?><li><strong><?php echo esc_html( $candidate['ballot_name'] ?: $candidate['full_name'] ); ?></strong> — <?php echo esc_html( number_format_i18n( (int) $candidate['votes'] ) ); ?> votos (<?php echo esc_html( number_format_i18n( (float) $candidate['percentage'], 2 ) ); ?>%)</li><?php endforeach; ?></ol><?php else : ?><p>Apuracao ainda nao iniciada.</p><?php endif; ?>
		</section><?php
		return (string) ob_get_clean();
	}
	public function candidate( array $atts ): string { return '<div class="ae-candidate" data-candidate="' . esc_attr( $atts['id'] ?? '' ) . '"></div>'; }
}
