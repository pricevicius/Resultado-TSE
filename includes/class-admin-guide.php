<?php
defined( 'ABSPATH' ) || exit;

/**
 * Aba "Como usar" do admin: manual dos shortcodes e do bloco, com exemplos prontos para copiar.
 * Os cargos vêm de TSE_API::CARGOS e os exemplos usam a UF configurada neste site, então o texto
 * acompanha o código em vez de virar uma cópia desatualizada.
 */
final class AE_Admin_Guide {
	/** Nome de exibição de cada slug de cargo. */
	private const CARGO_NAMES = array(
		'presidente'         => 'Presidente',
		'governador'         => 'Governador',
		'senador'            => 'Senador',
		'deputado-federal'   => 'Deputado Federal',
		'deputado-estadual'  => 'Deputado Estadual',
		'deputado-distrital' => 'Deputado Distrital',
		'prefeito'           => 'Prefeito',
		'vereador'           => 'Vereador',
	);

	public static function render( string $selection_url, string $setup_url, string $import_url ): void {
		$uf = strtolower( (string) get_option( 'ae_site_uf', '' ) );
		$uf = '' !== $uf ? $uf : 'sp';
		?>
		<section class="ae-panel">
			<h2>Como publicar a apuração no site</h2>
			<p>O plugin tem dois tipos de conteúdo para colocar numa página ou matéria: o <strong>placar da apuração</strong> (resultado de uma disputa, atualizado sozinho) e o <strong>catálogo de candidatos</strong> (busca e perfil). Cada um é um <em>shortcode</em>: um texto entre colchetes que você cola no conteúdo.</p>
			<ol class="ae-steps">
				<li><span>1</span><div><strong>Garanta que a disputa está sendo coletada</strong><p>O placar só mostra o que já foi coletado do TSE. Confira em <a href="<?php echo esc_url( $selection_url ); ?>">Seleção de disputas</a> se a disputa está marcada; desmarcada, a página fica parada ou sem dados.</p></div></li>
				<li><span>2</span><div><strong>Copie o shortcode</strong><p>Use os exemplos abaixo (botão <em>Copiar</em>) e troque o cargo e a UF pelo que você quer mostrar.</p></div></li>
				<li><span>3</span><div><strong>Cole na página</strong><p>No editor de blocos, adicione o bloco <em>Shortcode</em> e cole o texto nele. No editor clássico, cole direto no conteúdo. Também existe o bloco <em>Apuração eleitoral</em> (veja no fim desta página).</p></div></li>
			</ol>
		</section>

		<section class="ae-panel">
			<h2>Resumo: qual usar</h2>
			<table class="widefat striped"><thead><tr><th>Quero…</th><th>Use</th></tr></thead><tbody>
				<tr><td>O resultado completo de uma disputa (lista de candidatos, % apurado, status)</td><td><?php self::inline( '[tse_apuracao cargo="governador" uf="' . $uf . '"]' ); ?></td></tr>
				<tr><td>Um bloco simples para a home, com várias disputas numa só caixa</td><td><?php self::inline( '[tse_apuracao_resumo disputas="presidente:br,governador:' . $uf . '"]' ); ?></td></tr>
				<tr><td>Um card compacto com o líder (para a home ou grades)</td><td><?php self::inline( '[tse_apuracao_card cargo="governador" uf="' . $uf . '"]' ); ?></td></tr>
				<tr><td>Uma página de consulta de candidatos, com busca e paginação</td><td><?php self::inline( '[apuracao_candidatos]' ); ?></td></tr>
				<tr><td>Uma lista de candidatos (<code>&lt;ul&gt;&lt;li&gt;</code>) para o tema montar vitrine ou carrossel</td><td><?php self::inline( '[apuracao_candidatos_lista cargo="presidente" limite="10"]' ); ?></td></tr>
				<tr><td>Um menu entre a página de Apuração e a de Candidatos</td><td><?php self::inline( '[apuracao_navegacao]' ); ?></td></tr>
			</tbody></table>
		</section>

		<section class="ae-panel">
			<h2><code>[tse_apuracao]</code> — placar de uma disputa</h2>
			<p>Mostra o resultado: candidatos por votos, percentual, % de seções apuradas, brancos, nulos e a situação (<em>Ao vivo</em>, <em>Apuração concluída</em> ou <em>Dados atrasados</em>). A página se atualiza sozinha, sem recarregar, consultando só este WordPress (nunca o TSE).</p>
			<table class="widefat striped"><thead><tr><th>Atributo</th><th>O que faz</th><th>Padrão</th><th>Valores</th></tr></thead><tbody>
				<tr><td><code>cargo</code></td><td>Qual cargo mostrar</td><td><code>presidente</code></td><td>veja a tabela de cargos abaixo</td></tr>
				<tr><td><code>uf</code></td><td>Abrangência da disputa</td><td><code>br</code></td><td><code>br</code> (nacional, Presidente) ou a sigla do estado em minúsculas: <code>sp</code>, <code>rj</code>, <code>es</code>…</td></tr>
				<tr><td><code>turno</code></td><td>Turno</td><td><code>1</code></td><td><code>1</code> ou <code>2</code></td></tr>
				<tr><td><code>limite</code></td><td>Quantos candidatos listar</td><td><code>10</code></td><td>número a partir de 1 (os primeiros colocados)</td></tr>
				<tr><td><code>atualizar</code></td><td>De quantos em quantos segundos a página busca dado novo</td><td><code>60</code></td><td>segundos; <code>0</code> desliga a atualização automática</td></tr>
				<tr><td><code>titulo</code></td><td>Título acima do placar</td><td>montado pelo cargo e UF</td><td>texto livre (também aceita <code>title</code>)</td></tr>
			</tbody></table>
			<h3>Exemplos</h3>
			<?php
			self::example( 'Governador do estado, com os 5 primeiros', '[tse_apuracao cargo="governador" uf="' . $uf . '" limite="5"]' );
			self::example( 'Presidente (nacional)', '[tse_apuracao cargo="presidente" uf="br"]' );
			self::example( 'Senado, com título próprio', '[tse_apuracao cargo="senador" uf="' . $uf . '" titulo="Quem lidera para o Senado"]' );
			self::example( 'Deputado Federal, todos os 20 primeiros e atualização a cada 2 minutos', '[tse_apuracao cargo="deputado-federal" uf="' . $uf . '" limite="20" atualizar="120"]' );
			self::example( 'Segundo turno de Governador', '[tse_apuracao cargo="governador" uf="' . $uf . '" turno="2"]' );
			?>
		</section>

		<section class="ae-panel">
			<h2><code>[tse_apuracao_resumo]</code> — widget simples para a home</h2>
			<p>Uma caixa única com <strong>uma linha por disputa</strong>: cargo e UF, o líder com partido, o percentual e quanto já foi apurado, mais um selo geral (<em>Ao vivo</em>, <em>Apuração concluída</em> ou <em>Dados atrasados</em>) e um link opcional para a página completa. Atualiza sozinho. É o mais indicado para a home, a barra lateral e matérias; para destacar uma disputa só, use o card abaixo.</p>
			<table class="widefat striped"><thead><tr><th>Atributo</th><th>O que faz</th><th>Padrão</th><th>Valores</th></tr></thead><tbody>
				<tr><td><code>disputas</code></td><td>Quais disputas listar, separadas por vírgula, cada uma no formato <code>cargo:uf</code> (ou <code>cargo:uf:turno</code>)</td><td>Presidente, e Governador e Senador da UF deste site</td><td>até 8 disputas; cargos da tabela abaixo; <code>uf</code> em minúsculas (<code>br</code> para Presidente); turno <code>1</code> ou <code>2</code></td></tr>
				<tr><td><code>titulo</code></td><td>Título da caixa</td><td><code>Apuração</code></td><td>texto livre</td></tr>
				<tr><td><code>link</code></td><td>Endereço da página de apuração completa (mostra o link no rodapé)</td><td>sem link</td><td>URL, por exemplo <code>/apuracao/</code></td></tr>
				<tr><td><code>link_texto</code></td><td>Texto do link</td><td><code>Ver apuração completa</code></td><td>texto livre</td></tr>
				<tr><td><code>atualizar</code></td><td>Segundos entre atualizações</td><td><code>60</code></td><td><code>0</code> desliga</td></tr>
				<tr><td><code>classe</code></td><td>Classe CSS extra, para o tema estilizar</td><td>vazio</td><td>uma ou mais classes separadas por espaço</td></tr>
			</tbody></table>
			<h3>Exemplos</h3>
			<?php
			self::example( 'Home: Presidente e as disputas do estado, com link para a apuração', '[tse_apuracao_resumo disputas="presidente:br,governador:' . $uf . ',senador:' . $uf . '" link="/apuracao/"]' );
			self::example( 'Só o Governador, com título próprio', '[tse_apuracao_resumo disputas="governador:' . $uf . '" titulo="Quem lidera para governador"]' );
			self::example( 'Segundo turno', '[tse_apuracao_resumo disputas="presidente:br:2,governador:' . $uf . ':2" titulo="Segundo turno"]' );
			?>
			<p class="description">Disputas sem resultado ainda aparecem como "Aguardando apuração". O widget usa as cores do tema por variáveis CSS (<code>.tse-resumo { --tse-primary: #sua-cor; }</code>).</p>
		</section>

		<section class="ae-panel">
			<h2><code>[tse_apuracao_card]</code> — card compacto</h2>
			<p>Versão enxuta para a home e para grades: por padrão mostra só o líder da disputa. Pode ser repetido quantas vezes quiser, um por disputa.</p>
			<table class="widefat striped"><thead><tr><th>Atributo</th><th>O que faz</th><th>Padrão</th><th>Valores</th></tr></thead><tbody>
				<tr><td><code>cargo</code>, <code>uf</code>, <code>turno</code></td><td>Mesmos do <code>[tse_apuracao]</code></td><td><code>presidente</code>, <code>br</code>, <code>1</code></td><td>iguais aos acima</td></tr>
				<tr><td><code>limite</code></td><td>Quantos colocados mostrar</td><td><code>1</code></td><td><code>1</code> = card grande só com o líder; mais que 1 = mini-lista</td></tr>
				<tr><td><code>atualizar</code></td><td>Segundos entre atualizações</td><td><code>60</code></td><td><code>0</code> desliga</td></tr>
				<tr><td><code>titulo</code></td><td>Título do card</td><td>montado pelo cargo e UF</td><td>texto livre (também aceita <code>title</code>)</td></tr>
				<tr><td><code>classe</code></td><td>Classe CSS extra no card, para o front estilizar sem <code>!important</code></td><td>vazio</td><td>uma ou mais classes separadas por espaço</td></tr>
			</tbody></table>
			<h3>Exemplos</h3>
			<?php
			self::example( 'Líder da disputa de Governador', '[tse_apuracao_card cargo="governador" uf="' . $uf . '"]' );
			self::example( 'Mini-lista com os 3 primeiros do Senado', '[tse_apuracao_card cargo="senador" uf="' . $uf . '" limite="3"]' );
			self::example( 'Card da Presidência com classe própria', '[tse_apuracao_card cargo="presidente" uf="br" classe="home-destaque"]' );
			?>
		</section>

		<section class="ae-panel">
			<h2><code>[apuracao_candidatos]</code> — catálogo de candidatos</h2>
			<p>Página de consulta com busca por nome ou número, filtros de cargo, UF e partido, e paginação. Cada candidato leva ao perfil na mesma página. Os candidatos vêm da importação em <a href="<?php echo esc_url( $import_url ); ?>">Importar e coletar</a>.</p>
			<table class="widefat striped"><thead><tr><th>Atributo</th><th>O que faz</th><th>Padrão</th><th>Valores</th></tr></thead><tbody>
				<tr><td><code>por_pagina</code></td><td>Candidatos por página</td><td><code>24</code></td><td>de 1 a 200 (24 fecha a grade de 3 colunas)</td></tr>
			</tbody></table>
			<h3>Exemplos</h3>
			<?php
			self::example( 'Catálogo padrão', '[apuracao_candidatos]' );
			self::example( 'Catálogo com 48 por página', '[apuracao_candidatos por_pagina="48"]' );
			?>
			<h3>Links que abrem o catálogo já filtrado</h3>
			<p>O visitante filtra pelo formulário da própria página, mas você também pode montar links (em matérias, por exemplo) acrescentando estes parâmetros ao endereço da página do catálogo:</p>
			<table class="widefat striped"><thead><tr><th>Parâmetro</th><th>Filtra por</th><th>Exemplo</th></tr></thead><tbody>
				<tr><td><code>ae_busca</code></td><td>nome ou número</td><td><code>?ae_busca=maria</code></td></tr>
				<tr><td><code>ae_cargo</code></td><td>cargo, pelo código de 4 dígitos</td><td><code>?ae_cargo=0006</code> (Deputado Federal)</td></tr>
				<tr><td><code>ae_uf</code></td><td>estado</td><td><code>?ae_uf=<?php echo esc_html( strtoupper( $uf ) ); ?></code></td></tr>
				<tr><td><code>ae_partido</code></td><td>sigla do partido</td><td><code>?ae_partido=PT</code></td></tr>
				<tr><td><code>ae_pagina</code></td><td>número da página</td><td><code>?ae_pagina=2</code></td></tr>
				<tr><td><code>ae_candidato</code></td><td>abre o perfil de um candidato (identificador do TSE)</td><td><code>?ae_candidato=250001234567</code></td></tr>
			</tbody></table>
			<p class="description">Combine com <code>&amp;</code>, por exemplo <code>?ae_cargo=0006&amp;ae_uf=<?php echo esc_html( strtoupper( $uf ) ); ?>&amp;ae_pagina=2</code>. Códigos de cargo: 0001 Presidente, 0003 Governador, 0005 Senador, 0006 Deputado Federal, 0007 Deputado Estadual, 0008 Deputado Distrital.</p>
			<p class="description"><code>[apuracao_candidato]</code> mostra o perfil de um candidato quando o endereço traz <code>ae_candidato</code>. Você normalmente não precisa dele: o catálogo já abre o perfil sozinho.</p>
		</section>

		<section class="ae-panel">
			<h2><code>[apuracao_candidatos_lista]</code> — lista de candidatos para vitrine</h2>
			<p>Devolve só o HTML de uma lista (<code>&lt;ul class="ae-candidate-list"&gt;</code> com um <code>&lt;li class="ae-candidate-item"&gt;</code> por candidato), sem título, sem CSS e sem JavaScript do plugin: título, botão e carrossel ficam por conta do tema. Usa a eleição mais recente importada e ignora candidatos que saíram da lista do TSE.</p>
			<table class="widefat striped"><thead><tr><th>Atributo</th><th>O que faz</th><th>Padrão</th><th>Valores</th></tr></thead><tbody>
				<tr><td><code>cargo</code></td><td>Filtra pelo cargo</td><td>todos</td><td>os mesmos cargos da tabela abaixo (<code>presidente</code>, <code>governador</code>…)</td></tr>
				<tr><td><code>uf</code></td><td>Filtra pelo estado</td><td>todos</td><td>sigla, como <code>SP</code> (<code>BR</code> para presidente)</td></tr>
				<tr><td><code>limite</code></td><td>Quantos candidatos</td><td><code>10</code></td><td>de 1 a 100</td></tr>
				<tr><td><code>foto</code></td><td>Foto do candidato</td><td><code>sim</code></td><td><code>sim</code> mostra a foto quando existe; <code>nao</code> omite a foto; <code>somente</code> lista só quem tem foto</td></tr>
				<tr><td><code>ids</code></td><td>Escolhe os candidatos e a ordem</td><td>ordem alfabética</td><td>identificadores do TSE separados por vírgula (os mesmos de <code>?ae_candidato=</code>)</td></tr>
			</tbody></table>
			<?php
			self::example( 'Presidenciáveis com foto', '[apuracao_candidatos_lista cargo="presidente" limite="10"]' );
			self::example( 'Governadores de um estado, sem foto', '[apuracao_candidatos_lista cargo="governador" uf="' . $uf . '" foto="nao"]' );
			?>
			<p class="description">Cada item traz <code>data-numero</code>, <code>data-partido</code>, <code>data-cargo</code> e <code>data-uf</code>, e leva ao perfil na página de Candidatos escolhida em Configuração.</p>
		</section>

		<section class="ae-panel">
			<h2><code>[apuracao_navegacao]</code> — menu entre Apuração e Candidatos</h2>
			<p>Mostra dois links, <em>Apuração</em> e <em>Candidatos</em>, marcando em qual página o visitante está. Não tem atributos: ele usa as duas páginas escolhidas em <a href="<?php echo esc_url( $setup_url ); ?>">Configuração → Navegação entre Apuração e Candidatos</a> e continua certo se você mudar o endereço (slug) das páginas.</p>
			<?php self::example( 'Cole nas duas páginas', '[apuracao_navegacao]' ); ?>
			<p class="description">Sem as duas páginas escolhidas, o menu não aparece para os visitantes (e o administrador vê um aviso).</p>
		</section>

		<section class="ae-panel">
			<h2>Cargos aceitos em <code>cargo=""</code></h2>
			<table class="widefat striped"><thead><tr><th>Valor</th><th>Cargo</th><th>Código do TSE</th></tr></thead><tbody>
				<?php foreach ( TSE_API::CARGOS as $slug => $code ) : ?>
				<tr><td><code><?php echo esc_html( $slug ); ?></code></td><td><?php echo esc_html( self::CARGO_NAMES[ $slug ] ?? $slug ); ?></td><td><?php echo esc_html( str_pad( $code, 4, '0', STR_PAD_LEFT ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
			<p class="description">A disputa só aparece se existir e estiver ligada em <a href="<?php echo esc_url( $selection_url ); ?>">Seleção de disputas</a>. Presidente usa <code>uf="br"</code>; os demais cargos usam a sigla do estado.</p>
		</section>

		<section class="ae-panel">
			<h2>Bloco e shortcode antigo</h2>
			<p>O editor de blocos tem o bloco <strong>Apuração eleitoral</strong>, com os campos Cargo, Abrangência, Turno e Título. Ele e o shortcode antigo <code>[apuracao]</code> usam o <strong>código numérico</strong> do cargo (1, 3, 5, 6, 7, 8) e a sigla em <code>abrangencia</code>, e o atributo <code>eleicao</code> é ignorado. Para conteúdo novo, prefira <code>[tse_apuracao]</code>, que é mais claro.</p>
			<?php self::example( '[apuracao] antigo, equivalente ao Governador do estado', '[apuracao cargo="3" abrangencia="' . strtoupper( $uf ) . '" turno="1"]' ); ?>
		</section>

		<section class="ae-panel">
			<h2>Se algo não aparece</h2>
			<table class="widefat striped"><thead><tr><th>O que você vê</th><th>O que significa e o que fazer</th></tr></thead><tbody>
				<tr><td>"Ainda não há snapshot válido para esta disputa."</td><td>Ainda não chegou nenhum resultado dessa disputa. Confira se ela está ligada em <a href="<?php echo esc_url( $selection_url ); ?>">Seleção de disputas</a>, se o cargo e a UF do shortcode estão certos e se a fila está rodando (aba <em>Fila e progresso</em>). Antes do TSE publicar, é normal.</td></tr>
				<tr><td>Selo <strong>Dados atrasados</strong></td><td>O servidor não consegue consultar o TSE há alguns minutos (o limite acompanha o intervalo da disputa). Veja a <em>Visão geral</em> e os <em>Logs</em>. Os números exibidos são os últimos válidos.</td></tr>
				<tr><td>Selo <strong>Apuração concluída</strong></td><td>A disputa foi totalizada; o resultado não muda mais.</td></tr>
				<tr><td>Catálogo vazio</td><td>Importe os candidatos em <a href="<?php echo esc_url( $import_url ); ?>">Importar e coletar</a>.</td></tr>
				<tr><td>O shortcode aparece escrito na página</td><td>O texto foi colado fora de um bloco <em>Shortcode</em> ou com aspas curvas (“ ”). Use aspas retas (<code>"</code>), como nos exemplos.</td></tr>
				<tr><td>A página mostra um número antigo logo que abre</td><td>Pode ser cache de página ou CDN. O placar se corrige sozinho em segundos; se não, veja a documentação do plugin sobre cache.</td></tr>
			</tbody></table>
		</section>
		<script>
		document.querySelectorAll('.ae-copy').forEach(function (button) {
			button.addEventListener('click', function () {
				var text = button.parentNode.querySelector('code').textContent;
				var done = function () { var old = button.textContent; button.textContent = 'Copiado!'; setTimeout(function () { button.textContent = old; }, 1500); };
				if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done); return; }
				var area = document.createElement('textarea'); area.value = text; document.body.appendChild(area); area.select(); document.execCommand('copy'); document.body.removeChild(area); done();
			});
		});
		</script>
		<?php
	}

	/** Shortcode numa linha da tabela de resumo, sem botão. */
	private static function inline( string $shortcode ): void {
		echo '<code>' . esc_html( $shortcode ) . '</code>';
	}

	/** Exemplo com legenda, o shortcode em bloco e o botão de copiar. */
	private static function example( string $caption, string $shortcode ): void {
		echo '<div class="ae-example"><p>' . esc_html( $caption ) . '</p><div class="ae-code"><code>' . esc_html( $shortcode ) . '</code><button type="button" class="button button-small ae-copy">Copiar</button></div></div>';
	}
}
