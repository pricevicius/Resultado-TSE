<?php
/**
 * Template do painel de apuração.
 * Variáveis disponíveis: $widget_id, $cargo, $uf, $titulo, $atualizar, $limite, $dados, $erro
 */
defined( 'ABSPATH' ) || exit;

$opts       = get_option( 'tse_apuracao_settings', TSE_Settings::defaults() );
$cor        = esc_attr( $opts['cor_primaria'] ?? '#003366' );
$cor_eleito = esc_attr( $opts['cor_eleito']   ?? '#007A33' );

$cargo_labels = [
    'presidente'         => 'Presidente',
    'governador'         => 'Governador',
    'senador'            => 'Senador',
    'deputado-federal'   => 'Dep. Federal',
    'deputado-estadual'  => 'Dep. Estadual',
    'deputado-distrital' => 'Dep. Distrital',
    'prefeito'           => 'Prefeito',
    'vereador'           => 'Vereador',
];

$uf_labels = [
    'br' => 'Brasil',
    'ac' => 'Acre',      'al' => 'Alagoas',   'ap' => 'Amapá',     'am' => 'Amazonas',
    'ba' => 'Bahia',     'ce' => 'Ceará',      'df' => 'Distrito Federal',
    'es' => 'Espírito Santo', 'go' => 'Goiás', 'ma' => 'Maranhão', 'mt' => 'Mato Grosso',
    'ms' => 'Mato Grosso do Sul', 'mg' => 'Minas Gerais', 'pa' => 'Pará',
    'pb' => 'Paraíba',   'pr' => 'Paraná',     'pe' => 'Pernambuco', 'pi' => 'Piauí',
    'rj' => 'Rio de Janeiro', 'rn' => 'Rio Grande do Norte', 'rs' => 'Rio Grande do Sul',
    'ro' => 'Rondônia',  'rr' => 'Roraima',   'sc' => 'Santa Catarina', 'sp' => 'São Paulo',
    'se' => 'Sergipe',   'to' => 'Tocantins',
];

$titulo_final  = $titulo ?: ( ( $cargo_labels[ $cargo ] ?? ucfirst( $cargo ) ) . ' — ' . ( $uf_labels[ $uf ] ?? strtoupper( $uf ) ) );
$pct_apurado   = $dados['pct_apurado'] ?? null;
$pct_numero    = isset( $dados['pct_apurado_numero'] ) ? (float) $dados['pct_apurado_numero'] : (float) str_replace( ',', '.', (string) $pct_apurado );
$dados_atrasados = ! empty( $dados['atrasado'] );
$status        = $dados['status'] ?? '';
$atualizado_em = $dados['atualizado_em'] ?? '';
$candidatos    = $dados['candidatos'] ?? [];

// Voto máximo para escala das barras
$max_votos = ! empty( $candidatos ) ? max( array_column( $candidatos, 'votos' ) ) : 1;
$max_votos = max( $max_votos, 1 );
?>

<div
    id="<?php echo esc_attr( $widget_id ); ?>"
    class="tse-apuracao-widget"
    data-cargo="<?php echo esc_attr( $cargo ); ?>"
    data-uf="<?php echo esc_attr( $uf ); ?>"
	data-turno="<?php echo esc_attr( $turno ); ?>"
    data-limite="<?php echo esc_attr( $limite ); ?>"
    data-atualizar="<?php echo esc_attr( $atualizar ); ?>"
    style="--tse-primary:<?php echo $cor; ?>;--tse-eleito:<?php echo $cor_eleito; ?>"
    role="region"
    aria-label="<?php echo esc_attr( $titulo_final ); ?>"
>
    <!-- Cabeçalho -->
    <div class="tse-header">
        <div class="tse-header-left">
            <h2 class="tse-titulo"><?php echo esc_html( $titulo_final ); ?></h2>
            <?php if ( $pct_apurado ) : ?>
            <div class="tse-apurado">
                <div class="tse-apurado-barra" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct_numero ); ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="tse-apurado-fill" style="width:<?php echo esc_attr( number_format( max( 0, min( 100, $pct_numero ) ), 2, '.', '' ) ); ?>%"></div>
                </div>
                <span class="tse-apurado-label"><?php echo esc_html( $pct_apurado ); ?> apurado</span>
            </div>
            <?php endif; ?>
        </div>
        <div class="tse-header-right">
            <?php if ( $atualizar > 0 ) : ?>
            <span class="tse-ao-vivo <?php echo $dados_atrasados ? 'tse-dados-atrasados' : ''; ?>" title="Atualização automática a cada <?php echo esc_attr( $atualizar ); ?> segundos">
                <span class="tse-pulse" aria-hidden="true"></span>
                <span class="tse-live-label"><?php echo $dados_atrasados ? 'Dados atrasados' : ( 'Totalizado' === $status ? 'Apuração concluída' : 'Ao vivo' ); ?></span>
            </span>
            <?php endif; ?>
            <?php if ( $status ) : ?>
            <span class="tse-status"><?php echo esc_html( $status ); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Conteúdo -->
    <div class="tse-content">
        <?php if ( $erro ) : ?>
        <div class="tse-erro" role="alert">
            <span class="tse-erro-icon" aria-hidden="true">⚠️</span>
            <?php echo wp_kses_post( $erro ); ?>
        </div>
        <?php elseif ( empty( $candidatos ) ) : ?>
        <div class="tse-vazio" role="status">
            <span class="tse-loading-spinner" aria-hidden="true"></span>
            Aguardando início da apuração…
        </div>
        <?php else : ?>
        <ol class="tse-lista" aria-label="Candidatos por número de votos">
            <?php foreach ( $candidatos as $i => $cand ) :
                $pct_barra = $max_votos > 0 ? round( ( $cand['votos'] / $max_votos ) * 100, 1 ) : 0;
            ?>
            <li class="tse-candidato <?php echo $cand['eleito'] ? 'tse-eleito' : ''; ?> <?php echo ! empty( $cand['segundo_turno'] ) ? 'tse-segundo-turno' : ''; ?>"
                data-numero="<?php echo esc_attr( $cand['numero'] ); ?>"
                data-situacao="<?php echo esc_attr( $cand['situacao'] ); ?>">

                <div class="tse-cand-posicao"><?php echo esc_html( $i + 1 ); ?>º</div>

                <div class="tse-cand-info">
                    <div class="tse-cand-top">
                        <span class="tse-cand-numero"><?php echo esc_html( $cand['numero'] ); ?></span>
                        <span class="tse-cand-nome"><?php echo esc_html( $cand['nome'] ); ?></span>
                        <span class="tse-cand-partido"><?php echo esc_html( $cand['partido'] ); ?></span>
                        <?php if ( $cand['eleito'] ) : ?>
                        <span class="tse-badge-eleito">Eleito</span>
						<?php elseif ( ! empty( $cand['situacao'] ) && 'Totalizado' === $status ) : ?>
						<span class="tse-badge-status <?php echo ! empty( $cand['segundo_turno'] ) ? 'tse-badge-turno2' : ''; ?>"><?php echo esc_html( $cand['situacao'] ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="tse-cand-barra-wrap" role="presentation">
                        <div class="tse-cand-barra" style="width:<?php echo esc_attr( $pct_barra ); ?>%"></div>
                    </div>
                </div>

                <div class="tse-cand-votos">
                    <span class="tse-votos-num" aria-label="<?php echo esc_attr( number_format( $cand['votos'], 0, ',', '.' ) ); ?> votos">
                        <?php echo esc_html( number_format( $cand['votos'], 0, ',', '.' ) ); ?>
                    </span>
                    <span class="tse-votos-pct"><?php echo esc_html( $cand['percentual'] ); ?></span>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </div>

    <!-- Rodapé -->
    <div class="tse-footer">
        <span class="tse-fonte">Fonte: TSE — Tribunal Superior Eleitoral</span>
        <?php if ( $atualizado_em ) : ?>
        <span class="tse-atualizado">
            Última atualização: <time datetime="<?php echo esc_attr( $atualizado_em ); ?>" class="tse-timestamp"><?php echo esc_html( $atualizado_em ); ?></time>
        </span>
        <?php endif; ?>
    </div>
</div>
