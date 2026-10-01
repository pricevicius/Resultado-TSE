<?php
/**
 * Widget simples — [tse_apuracao_resumo]. Variáveis vindas de AE_Resumo::render():
 * $widget_id, $titulo, $rows, $estado, $link, $link_texto, $atualizar, $classe.
 */
defined( 'ABSPATH' ) || exit;
?>
<section id="<?php echo esc_attr( $widget_id ); ?>" class="tse-resumo <?php echo esc_attr( $classe ); ?>" data-atualizar="<?php echo esc_attr( (string) $atualizar ); ?>" aria-label="<?php echo esc_attr( $titulo ); ?>">
	<header class="tse-resumo-header">
		<h3 class="tse-resumo-titulo"><?php echo esc_html( $titulo ); ?></h3>
		<span class="tse-resumo-estado<?php echo 'Dados atrasados' === $estado ? ' tse-dados-atrasados' : ''; ?>"><?php echo esc_html( $estado ); ?></span>
	</header>
	<?php if ( ! $rows ) : ?>
	<p class="tse-resumo-vazio">Nenhuma disputa configurada.</p>
	<?php else : ?>
	<ul class="tse-resumo-lista">
		<?php foreach ( $rows as $row ) : $lider = $row['lider']; $dados = $row['dados']; ?>
		<li class="tse-resumo-item<?php echo $lider && ! empty( $lider['eleito'] ) ? ' tse-eleito' : ''; ?>" data-cargo="<?php echo esc_attr( $row['cargo'] ); ?>" data-uf="<?php echo esc_attr( $row['uf'] ); ?>" data-turno="<?php echo esc_attr( (string) $row['turno'] ); ?>">
			<span class="tse-resumo-disputa"><?php echo esc_html( $row['rotulo'] ); ?></span>
			<?php if ( $lider ) : ?>
			<span class="tse-resumo-lider"><strong class="tse-resumo-nome"><?php echo esc_html( $lider['nome'] ); ?></strong> <span class="tse-resumo-partido"><?php echo esc_html( $lider['partido'] ); ?></span><?php if ( ! empty( $lider['eleito'] ) ) : ?> <span class="tse-badge-eleito">Eleito</span><?php endif; ?></span>
			<span class="tse-resumo-pct"><?php echo esc_html( $lider['percentual'] ); ?></span>
			<span class="tse-resumo-apurado"><?php echo esc_html( ( $dados['pct_apurado'] ?? '0%' ) . ' apurado' ); ?></span>
			<?php else : ?>
			<span class="tse-resumo-lider tse-resumo-aguardando">Aguardando apuração</span>
			<?php endif; ?>
		</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
	<?php if ( $link ) : ?>
	<footer class="tse-resumo-rodape"><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $link_texto ); ?> →</a></footer>
	<?php endif; ?>
</section>
