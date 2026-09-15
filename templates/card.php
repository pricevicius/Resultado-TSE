<?php
/**
 * Card compacto — [tse_apuracao_card]. Marcação enxuta, pensada para ser
 * restilizada livremente pelo front (grade na home, carrossel, etc.).
 * Variáveis vindas de TSE_Shortcode::render_card(): $widget_id, $cargo, $uf,
 * $turno, $limite, $atualizar, $titulo, $classe_extra, $dados, $erro,
 * $candidatos.
 *
 * limite=1 (padrão): um único card, igual ao de sempre.
 * limite>1: repete o MESMO card, um por colocado, dentro de uma grade.
 */
defined( 'ABSPATH' ) || exit;

$rotulo_cargo = $titulo ?: ( ucfirst( str_replace( '-', ' ', $cargo ) ) . ' — ' . strtoupper( $uf ) );
$status       = $dados['status'] ?? '';
$atrasado     = ! empty( $dados['atrasado'] );

/** 'percentual' vem formatado ("12,85%") para exibicao; largura de barra precisa de numero puro (ponto). */
$barra_pct = static fn( array $c ): float => (float) str_replace( ',', '.', rtrim( (string) $c['percentual'], '% ' ) );

$badge = static function ( array $c ) use ( $status ): string {
	if ( $c['eleito'] ) { return '<span class="tse-badge-eleito">Eleito</span>'; }
	if ( 'Totalizado' === $status && ! empty( $c['situacao'] ) ) {
		$turno2 = ! empty( $c['segundo_turno'] ) ? ' tse-badge-turno2' : '';
		return '<span class="tse-badge-status' . $turno2 . '">' . esc_html( $c['situacao'] ) . '</span>';
	}
	return '';
};

$renderiza_card = function ( int $posicao, ?array $c, string $classe_card = '', bool $mostrar_header = true ) use ( $widget_id, $cargo, $uf, $turno, $limite, $atualizar, $rotulo_cargo, $erro, $dados, $badge, $barra_pct, $atrasado, $status ): void {
	?>
	<div id="<?php echo esc_attr( $widget_id . '-' . $posicao ); ?>"
		class="tse-card <?php echo $classe_card ? esc_attr( $classe_card ) : ''; ?>"
		data-cargo="<?php echo esc_attr( $cargo ); ?>"
		data-uf="<?php echo esc_attr( $uf ); ?>"
		data-turno="<?php echo esc_attr( $turno ); ?>"
		data-posicao="<?php echo esc_attr( $posicao ); ?>"
		data-limite="<?php echo esc_attr( max( $limite, $posicao + 1 ) ); ?>"
		data-atualizar="<?php echo esc_attr( $atualizar ); ?>">

		<?php if ( $mostrar_header ) : ?>
		<div class="tse-card-header">
			<span class="tse-card-cargo"><?php echo esc_html( $rotulo_cargo ); ?></span>
			<?php if ( ! $erro ) : ?>
			<span class="tse-card-pct"><?php echo esc_html( $dados['pct_apurado'] ?? '0%' ); ?> apurado</span>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if ( $erro ) : ?>
		<div class="tse-card-vazio"><?php echo esc_html( $erro ); ?></div>
		<?php elseif ( ! $c ) : ?>
		<div class="tse-card-vazio">Aguardando início da apuração…</div>
		<?php else : ?>

		<div class="tse-card-lider <?php echo $c['eleito'] ? 'tse-eleito' : ''; ?> <?php echo ! empty( $c['segundo_turno'] ) ? 'tse-segundo-turno' : ''; ?>">
			<?php if ( ! empty( $c['foto_url'] ) ) : ?>
			<img class="tse-card-foto" src="<?php echo esc_url( $c['foto_url'] ); ?>" alt="" loading="lazy">
			<?php endif; ?>
			<div class="tse-card-info">
				<div class="tse-card-nome-linha">
					<span class="tse-card-numero"><?php echo esc_html( $c['numero'] ); ?></span>
					<span class="tse-card-nome"><?php echo esc_html( $c['nome'] ); ?></span>
				</div>
				<div class="tse-card-partido"><?php echo esc_html( $c['partido'] ); ?></div>
			</div>
			<div class="tse-card-percentual"><?php echo esc_html( $c['percentual'] ); ?></div>
		</div>

		<div class="tse-card-barra-wrap" role="presentation">
			<div class="tse-card-barra" style="width:<?php echo esc_attr( $barra_pct( $c ) ); ?>%"></div>
		</div>

		<div class="tse-card-rodape">
			<?php echo wp_kses_post( $badge( $c ) ); ?>
			<span class="tse-card-atualizado <?php echo $atrasado ? 'tse-dados-atrasados' : ''; ?>">
				<?php echo $atrasado ? 'Dados atrasados' : ( 'Totalizado' === $status ? 'Apuração concluída' : 'Ao vivo' ); ?>
			</span>
		</div>
		<?php endif; ?>
	</div>
	<?php
};

if ( count( $candidatos ) <= 1 ) {
	// Sem grade: a classe extra vai direto no único card, que já é o elemento de fora.
	$renderiza_card( 0, $candidatos[0] ?? null, $classe_extra );
} else {
	?>
	<div id="<?php echo esc_attr( $widget_id ); ?>"
		class="tse-card-secao <?php echo $classe_extra ? esc_attr( $classe_extra ) : ''; ?>"
		data-cargo="<?php echo esc_attr( $cargo ); ?>"
		data-uf="<?php echo esc_attr( $uf ); ?>"
		data-turno="<?php echo esc_attr( $turno ); ?>"
		data-limite="<?php echo esc_attr( $limite ); ?>"
		data-atualizar="<?php echo esc_attr( $atualizar ); ?>">

		<header class="apuracao__header">
			<h2><?php echo esc_html( $rotulo_cargo ); ?></h2>
			<?php if ( ! $erro ) : ?>
			<span class="tse-card-secao-pct"><?php echo esc_html( $dados['pct_apurado'] ?? '0%' ); ?> apurado</span>
			<?php endif; ?>
		</header>

		<div class="tse-card-grid">
			<?php foreach ( $candidatos as $i => $c ) { $renderiza_card( $i, $c, '', false ); } ?>
		</div>
	</div>
	<?php
}
