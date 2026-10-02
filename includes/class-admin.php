<?php
defined( 'ABSPATH' ) || exit;

final class AE_Admin {
	private static $instance = null;
	private const PAGE = 'apuracao-eleitoral';
	public static function instance(): AE_Admin { if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		foreach ( array( 'sync_tse', 'quick_setup', 'save_contest', 'start_import', 'retry_job', 'run_jobs', 'save_sync_selection', 'wipe_test_data', 'save_pages', 'save_auto_import' ) as $action ) { add_action( 'admin_post_ae_' . $action, array( $this, $action ) ); }
		add_action( 'wp_ajax_ae_admin_status', array( $this, 'ajax_status' ) );
		// Tela do plugin aberta = alguém acompanhando: a fila anda mesmo sem WP-Cron (admin não passa por cache de página).
		if ( 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && self::PAGE === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_action( 'shutdown', static function (): void {
				try { AE_Job_Runner::instance()->kick(); } catch ( Throwable $e ) { /* Best-effort; o tick agendado cobre. */ }
			} );
		}
	}

	public function menu(): void { add_menu_page( 'Apuração Eleitoral', 'Apuração', 'manage_options', self::PAGE, array( $this, 'page' ), 'dashicons-chart-bar', 58 ); }
	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) { return; }
		wp_enqueue_style( 'ae-admin', AE_URL . 'assets/css/ae-admin.css', array(), AE_VERSION );
		wp_enqueue_script( 'ae-admin', AE_URL . 'assets/js/ae-admin.js', array(), AE_VERSION, true );
		wp_localize_script( 'ae-admin', 'AEAdmin', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ae_admin_status' ) ) );
	}

	public function page(): void {
		$this->guard(); $tab = sanitize_key( $_GET['tab'] ?? 'overview' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'overview', 'setup', 'selecao', 'import', 'jobs', 'logs', 'shortcodes' ), true ) ) { $tab = 'overview'; }
		?><div class="wrap ae-admin"><div class="ae-title"><div><h1>Apuração Eleitoral</h1><p>Configure, importe e acompanhe a apuração sem sair do WordPress.</p></div><span class="ae-version">v<?php echo esc_html( AE_VERSION ); ?></span></div><?php $this->notice(); $this->requirements_notice(); ?><nav class="nav-tab-wrapper"><?php foreach ( array( 'overview'=>'Visão geral', 'setup'=>'Configuração', 'selecao'=>'Seleção de disputas', 'import'=>'Importar e coletar', 'jobs'=>'Fila e progresso', 'logs'=>'Logs', 'shortcodes'=>'Como usar' ) as $key=>$label ) : ?><a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $this->url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav><?php call_user_func( array( $this, 'tab_' . $tab ) ); ?></div><?php
	}

	private function tab_shortcodes(): void { AE_Admin_Guide::render( $this->url( 'selecao' ), $this->url( 'setup' ), $this->url( 'import' ) ); }

	private function tab_overview(): void {
		global $wpdb; $p = $wpdb->prefix . 'ae_'; $counts = array(
			'elections'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}elections"), 'contests'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}contests WHERE active=1"), 'enabled'=>$this->count_enabled_contests(), 'candidates'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}candidates"), 'snapshots'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}snapshots WHERE status='valid'") ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next = wp_next_scheduled( 'ae_run_jobs' );
		// "Sincronizadas" = existem no banco (o EA11 sempre traz as 27 UFs); "Habilitadas" = de fato coletando/importando — evita o rotulo antigo ("Disputas ativas") dar a entender que tudo esta sendo importado.
		$this->tick_notice(); $this->engine_notice(); $this->site_uf_notice(); $this->setup_guide(); ?><div class="ae-grid ae-stats"><?php foreach ( array( 'elections'=>'Eleições', 'contests'=>'Disputas sincronizadas', 'enabled'=>'Habilitadas p/ coleta', 'candidates'=>'Candidatos', 'snapshots'=>'Snapshots válidos' ) as $key=>$label ) : ?><div class="ae-card"><strong><?php echo esc_html( number_format_i18n( $counts[$key] ) ); ?></strong><span><?php echo esc_html( $label ); ?></span></div><?php endforeach; ?></div>
		<?php if ( $counts['contests'] > 0 && $counts['enabled'] < $counts['contests'] ) : ?><p class="description">Sincronizar sempre traz o catálogo nacional do TSE (todas as UFs) — só as <strong><?php echo esc_html( number_format_i18n( $counts['enabled'] ) ); ?></strong> disputas "habilitadas p/ coleta" são de fato coletadas e entram na importação de candidatos. Ajuste em <a href="<?php echo esc_url( $this->url( 'selecao' ) ); ?>">Seleção de disputas</a>.</p><?php endif; ?>
		<div class="ae-grid ae-two"><section class="ae-panel"><h2>Comece em três passos</h2><ol class="ae-steps"><li><span>1</span><div><strong>Sincronize com o TSE</strong><p>O plugin lê o EA11 e cria eleições, cargos, turnos e fontes oficiais.</p><a class="button" href="<?php echo esc_url($this->url('setup')); ?>">Sincronizar</a></div></li><li><span>2</span><div><strong>Importe candidatos</strong><p>O arquivo oficial de Dados Abertos é localizado automaticamente.</p><a class="button" href="<?php echo esc_url($this->url('import')); ?>">Importar</a></div></li><li><span>3</span><div><strong>Publique o componente</strong><p>Bloco e shortcode atualizam no cliente pela API do seu WordPress.</p><a class="button button-primary" href="<?php echo esc_url($this->url('import')); ?>#ae-collection">Ver coleta</a></div></li></ol></section>
		<section class="ae-panel"><h2>Saúde</h2><dl class="ae-health"><dt>Banco</dt><dd><span class="ae-dot ae-ok"></span> schema <?php echo esc_html((string)get_option('ae_schema_version','não instalado')); ?></dd><dt>Processador</dt><dd><?php echo $next ? '<span class="ae-dot ae-ok"></span> próximo ciclo em '.esc_html(human_time_diff(time(),$next)) : '<span class="ae-dot ae-bad"></span> cron não agendado'; ?></dd><dt>Último tick</dt><dd><?php echo wp_kses_post( $this->tick_health_html() ); ?></dd><?php foreach ( array( 'fetch' => 'Download do TSE', 'job' => 'Coleta de uma disputa', 'tick' => 'Lock preso por tick' ) as $perf_kind => $perf_label ) : $perf_row = AE_Perf::summary()[ $perf_kind ] ?? null; if ( $perf_row ) : ?><dt><?php echo esc_html( $perf_label ); ?></dt><dd><?php echo esc_html( AE_Perf::label( $perf_row ) ); ?></dd><?php endif; endforeach; ?><dt>Último snapshot</dt><dd><?php echo esc_html((string)($wpdb->get_var("SELECT MAX(captured_at) FROM {$p}snapshots WHERE status='valid'") ?: 'ainda não recebido')); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared ?></dd></dl><?php $this->run_button(); ?></section></div><?php
	}

	private function tab_setup(): void {
		$elections = $this->elections( true );
		$site_uf = strtoupper( (string) get_option( 'ae_site_uf', '' ) );
		$conn = $this->tse_connection( $elections );
		$env_sel = $conn ? $conn['environment'] : 'simulado';
		$year_val = $conn ? $conn['year'] : AE_Plugin::default_election_year();
		?><div class="ae-grid ae-two"><section class="ae-panel"><h2>Conectar ao TSE</h2><p>Sem copiar URLs: o plugin consulta o catálogo EA11 e monta todas as fontes conforme os diretórios oficiais.</p>
		<?php if ( '' === $site_uf ) : ?><div class="notice notice-warning inline" style="border-left:4px solid #d63638;padding:1px 12px;margin:0 0 16px;"><p><strong>Atenção:</strong> nenhuma UF configurada ainda. Sincronizando sem preencher o campo abaixo, <strong>toda disputa nova nasce ligada nas 27 UFs</strong> — a coleta automática e a importação de candidatos vão puxar o Brasil inteiro, não só o seu estado. Preencha a UF antes de sincronizar, a menos que este site cubra o país inteiro de propósito.</p></div><?php endif; ?>
		<?php if ( $conn ) : ?><p class="ae-connected"><span class="ae-dot ae-ok"></span> <strong>Conectado ao TSE</strong> — <?php echo esc_html( 'oficial' === $conn['environment'] ? 'Oficial' : 'Simulado' ); ?> · <?php echo esc_html( (string) $conn['year'] ); ?> · UF <?php echo esc_html( '' !== $site_uf ? $site_uf : 'todas' ); ?> · última sincronização <?php echo esc_html( $conn['synced'] ); ?> UTC</p><details><summary>Alterar ou ressincronizar</summary><?php endif; ?>
		<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"<?php if ( $conn ) : ?> onsubmit="return confirm('Já conectado (<?php echo esc_js( $conn['environment'] . ' ' . $conn['year'] ); ?>). Ressincronizar com os valores do formulário?');"<?php endif; ?>><?php wp_nonce_field('ae_sync_tse'); ?><input type="hidden" name="action" value="ae_sync_tse"><label>Ambiente<select name="environment"><option value="oficial"<?php selected( $env_sel, 'oficial' ); ?>>Oficial</option><option value="simulado"<?php selected( $env_sel, 'simulado' ); ?>>Simulado</option></select></label><label>Ano<input name="year" type="number" min="2022" max="2100" value="<?php echo esc_attr( (string) $year_val ); ?>" required></label><label>UF deste site<input name="uf" maxlength="2" style="text-transform:uppercase;width:4em" value="<?php echo esc_attr($site_uf); ?>" placeholder="UF"></label><button class="button <?php echo $conn ? '' : 'button-primary button-hero'; ?>"><?php echo $conn ? 'Ressincronizar com o TSE' : 'Sincronizar configuração do TSE'; ?></button><p class="description">Toda disputa nova nasce <strong>desligada</strong>, exceto as dessa UF e Presidente — evita puxar coleta e candidatos do Brasil inteiro sem querer. Disputas já existentes preservam o que você marcou em Seleção de disputas. Deixar em branco é permitido, mas volta ao comportamento antigo (tudo ligado).</p></form><?php if ( $conn ) : ?></details><?php endif; ?></section>
		<section class="ae-panel"><h2>Configuração existente</h2><?php if(!$elections): ?><p>Nenhuma eleição.</p><?php else: ?><table class="widefat striped"><thead><tr><th>Eleição</th><th>Status</th><th>Disputas</th></tr></thead><tbody><?php foreach($elections as $e): ?><tr><td><strong><?php echo esc_html($e->name); ?></strong><br><code><?php echo esc_html($e->slug); ?></code></td><td><?php echo esc_html($e->status); ?></td><td><?php echo esc_html((string)$e->contests); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section></div>
		<?php $this->navigation_panel(); ?>
		<?php $this->test_data_panel(); ?>
		<section class="ae-panel"><details><summary><strong>Estrutura local para desenvolvimento</strong></summary><p>Cria uma eleição local sem fontes externas. Use para testar tela zerada e cenários controlados antes dos simulados.</p><form class="ae-form-grid" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><?php wp_nonce_field('ae_quick_setup'); ?><input type="hidden" name="action" value="ae_quick_setup"><label>Ano<input name="year" type="number" min="2022" max="2100" value="<?php echo esc_attr( (string) AE_Plugin::default_election_year() ); ?>" required></label><label>Nome<input name="name" value="<?php echo esc_attr( 'Eleições ' . AE_Plugin::default_election_year() ); ?>" required></label><div><button class="button">Criar estrutura de teste</button></div></form></details></section>
		<section class="ae-panel"><h2>Adicionar disputa personalizada</h2><form class="ae-form-grid" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><?php wp_nonce_field('ae_save_contest'); ?><input type="hidden" name="action" value="ae_save_contest"><?php $this->election_select('election_id',$elections); ?><label>Turno<input name="round_no" type="number" min="1" max="3" value="1" required></label><label>Código do cargo<input name="position_code" value="0001" required></label><label>Nome do cargo<input name="position_name" placeholder="Presidente" required></label><label>Tipo<select name="scope_type"><option value="BR">Brasil</option><option value="UF">Estado</option><option value="MU">Município</option></select></label><label>Código<input name="scope_code" placeholder="BR ou ES" required></label><label>Abrangência<input name="scope_name" placeholder="Brasil ou Espírito Santo" required></label><label>Vagas<input name="seats" type="number" min="1" value="1" required></label><div><button class="button button-primary">Adicionar disputa</button></div></form></section><?php $this->setup_guide(); ?><?php
	}

	private function setup_guide(): void { ?>
		<section class="ae-panel"><h2>Comece por aqui: ativação e publicação</h2>
		<ol class="ae-steps">
			<li><span>1</span><div><strong>Ative o plugin</strong><p>As tabelas, o agendamento de coleta e a estrutura inicial são criados automaticamente. Se uma restauração remover tabelas, o plugin as verifica e recria no próximo carregamento do WordPress.</p></div></li>
			<li><span>2</span><div><strong>Sincronize o ambiente</strong><p>Para homologar, escolha <em>Simulado</em> e sincronize. Em produção, escolha <em>Oficial</em> somente quando o TSE publicar o catálogo daquele ambiente.</p></div></li>
			<li><span>3</span><div><strong>Importe os candidatos</strong><p>Em <a href="<?php echo esc_url( $this->url( 'import' ) ); ?>">Importar e coletar</a>, escolha a eleição e inicie a importação. A fila processa o pacote oficial em lotes; acompanhe o resultado na aba Fila e progresso.</p></div></li>
			<li><span>4</span><div><strong>Valide a apuração</strong><p>O simulado só responde nos horários divulgados pelo TSE. Verifique se surgiram snapshots válidos e se Presidente, Governador e Senado exibem dados na página de apuração.</p></div></li>
			<li><span>5</span><div><strong>Publique</strong><p>Use <code>[tse_apuracao cargo="governador" uf="es"]</code> para um placar ou <code>[apuracao_candidatos]</code> para o catálogo. Adicione <code>[apuracao_navegacao]</code> nas duas páginas para exibir o menu entre elas — configure as páginas na seção "Navegação" logo abaixo. Visitantes consultam apenas este WordPress; a coleta do TSE ocorre no servidor com limite interno e cache condicional.</p></div></li>
		</ol>
		<p class="description">Se a fila registrar 403 ou 429, aguarde a pausa de segurança de dez minutos e confira a aba Logs. Um 404 desativa somente a fonte inexistente, sem interromper os demais resultados.</p>
		</section><?php
	}

	/** Resolve o menu Apuração/Candidatos por ID de página, não por URL fixa — sobrevive a mudança de slug feita pela redação. */
	private function navigation_panel(): void {
		$results_id    = (int) get_option( AE_Navigation::OPTION_RESULTS );
		$candidates_id = (int) get_option( AE_Navigation::OPTION_CANDIDATES );
		?><section class="ae-panel"><h2>Navegação entre Apuração e Candidatos</h2>
		<p class="description">Escolha as páginas onde estão publicados <code>[apuracao ...]</code> e <code>[apuracao_candidatos]</code>. Depois, use <code>[apuracao_navegacao]</code> em ambas para exibir o menu — ele monta os links pelo ID da página, então continua funcionando se o slug mudar.</p>
		<form class="ae-form-grid" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<?php wp_nonce_field( 'ae_save_pages' ); ?>
		<input type="hidden" name="action" value="ae_save_pages">
		<label>Página de Apuração
		<?php
		wp_dropdown_pages( array(
			'name'              => 'results_page_id',
			'selected'          => $results_id,
			'show_option_none'  => 'Selecione...',
			'option_none_value' => '0',
		) );
		?>
		</label>
		<label>Página de Candidatos
		<?php
		wp_dropdown_pages( array(
			'name'              => 'candidates_page_id',
			'selected'          => $candidates_id,
			'show_option_none'  => 'Selecione...',
			'option_none_value' => '0',
		) );
		?>
		</label>
		<div><button class="button button-primary">Salvar navegação</button></div>
		</form>
		</section><?php
	}

	public function save_pages(): void {
		$this->verify( 'ae_save_pages' );
		$results_id    = absint( $_POST['results_page_id'] ?? 0 );
		$candidates_id = absint( $_POST['candidates_page_id'] ?? 0 );
		if ( $results_id && 'page' !== get_post_type( $results_id ) ) { $results_id = 0; }
		if ( $candidates_id && 'page' !== get_post_type( $candidates_id ) ) { $candidates_id = 0; }
		update_option( AE_Navigation::OPTION_RESULTS, $results_id );
		update_option( AE_Navigation::OPTION_CANDIDATES, $candidates_id );
		AE_Logger::write( 'info', 'navigation_pages_saved', array( 'results_page_id' => $results_id, 'candidates_page_id' => $candidates_id ) );
		$this->redirect( 'setup', 'Navegação salva.' );
	}

	/** Simulado nunca tem valor legal (nem se converte em Oficial); tudo sob esse ambiente é dado de teste, apagável de um clique. */
	private function test_data_panel(): void {
		global $wpdb; $p = $wpdb->prefix . 'ae_';
		$election_ids = $wpdb->get_col( "SELECT id FROM {$p}elections WHERE config_json LIKE '%\"environment\":\"simulado\"%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = array( 'elections' => count( $election_ids ), 'contests' => 0, 'snapshots' => 0, 'jobs' => 0 );
		if ( $election_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $election_ids ), '%d' ) );
			$contest_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}contests WHERE election_id IN ({$placeholders})", ...$election_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counts['contests'] = count( $contest_ids );
			if ( $contest_ids ) {
				$cplaceholders = implode( ',', array_fill( 0, count( $contest_ids ), '%d' ) );
				$counts['snapshots'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}snapshots WHERE contest_id IN ({$cplaceholders})", ...$contest_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				// Uma query com JSON_EXTRACT em vez de 1 LIKE '%...%' por disputa: com a fila de jobs em dezenas de milhares de linhas
				// (acumulada dos simulados), o loop antigo levava minutos nessa tela sozinha — medido em homolog: 83s vs 0,37s.
				$counts['jobs'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}jobs WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.contest_id')) AS UNSIGNED) IN ({$cplaceholders})", ...$contest_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		?><section class="ae-panel"><h2>Dados de teste (Simulado)</h2>
		<p class="description">Tudo que foi sincronizado com ambiente <strong>Simulado</strong> é dado de teste — o TSE nunca dá valor legal a ele nem o converte em Oficial. Use isto para limpar antes de sincronizar com o ambiente Oficial.</p>
		<p><strong><?php echo esc_html( (string) $counts['elections'] ); ?></strong> eleição(ões), <strong><?php echo esc_html( (string) $counts['contests'] ); ?></strong> disputa(s), <strong><?php echo esc_html( (string) $counts['snapshots'] ); ?></strong> snapshot(s) e <strong><?php echo esc_html( (string) $counts['jobs'] ); ?></strong> job(s) marcados como Simulado.</p>
		<?php if ( $counts['elections'] ) : ?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm('Apagar TODOS os dados de Simulado? Isso não pode ser desfeito.');">
		<?php wp_nonce_field( 'ae_wipe_test_data' ); ?>
		<input type="hidden" name="action" value="ae_wipe_test_data">
		<label><input type="checkbox" name="confirm" value="1" required> Confirmo que quero apagar todos os dados do ambiente Simulado (irreversível).</label>
		<div><button class="button" style="border-color:#d63638;color:#d63638;">Apagar dados de teste</button></div>
		</form>
		<?php else : ?><p><em>Nenhum dado de Simulado encontrado.</em></p><?php endif; ?>
		</section><?php
	}

	private function tab_selecao(): void {
		global $wpdb; $p = $wpdb->prefix . 'ae_';
		$contests = $wpdb->get_results( "SELECT c.id,c.position_code,c.scope_code,c.position_name,c.scope_name,c.round_no,c.config_json,(SELECT MAX(captured_at) FROM {$p}snapshots s WHERE s.contest_id=c.id AND s.status='valid') latest FROM {$p}contests c WHERE c.active=1 ORDER BY c.position_name,c.scope_name,c.round_no" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$groups = array();
		foreach ( $contests as $c ) { $groups[ $c->position_name ][] = $c; }
		?><section class="ae-panel">
		<div class="notice notice-warning inline" style="border-left:4px solid #d63638;padding:1px 12px;margin:0 0 16px;"><p><strong>Atenção:</strong> disputas <u>desmarcadas</u> abaixo <strong>não são sincronizadas com o TSE</strong> — ficam paradas no último dado coletado (ou nunca chegam a ter um), mesmo que apareçam publicadas em algum shortcode ou bloco do site. Marque só o que está de fato publicado.</p></div>
		<h2>Seleção de disputas para sincronização automática</h2>
		<p class="description">Agrupado por cargo, tudo fechado por padrão. Sincronizar com o TSE (aba Configuração) recria esta lista, mas preserva o que você marcar/desmarcar aqui — só volta a marcar tudo se a disputa for nova.</p>
		<?php $this->site_uf_notice( $contests ); ?>
			<?php $uf_count = AE_Collection_Policy::count_enabled_ufs( $contests ); $multi_uf = $uf_count > 1; $heavy_seconds = AE_Collection_Policy::heavy_interval(); ?>
		<div class="notice notice-info inline" style="padding:8px 12px;margin:0 0 16px;">
			<p><strong>Quanto cada disputa atualiza.</strong> Presidente, Governador e Senador (os cargos majoritários) atualizam a cada <strong><?php echo esc_html( (string) AE_Collection_Policy::DEFAULT_INTERVAL ); ?> s</strong> e vão na frente da fila. Quando <strong>mais de uma UF</strong> está ligada, Deputado Federal e Estadual/Distrital passam a atualizar a cada <strong><?php echo esc_html( (string) $heavy_seconds ); ?> s</strong>: cada coleta deles tem centenas de candidatos por UF e, no mesmo ritmo, atrasaria todas as outras. Com uma UF só, tudo fica em <?php echo esc_html( (string) AE_Collection_Policy::DEFAULT_INTERVAL ); ?> s.</p>
			<p><?php if ( $multi_uf ) : ?><strong>Agora há <?php echo esc_html( (string) $uf_count ); ?> UFs ligadas: a regra está ativa</strong> e os deputados atualizam mais devagar.<?php else : ?>Agora há <?php echo esc_html( (string) $uf_count ); ?> UF ligada: a regra está inativa e tudo atualiza em <?php echo esc_html( (string) AE_Collection_Policy::DEFAULT_INTERVAL ); ?> s.<?php endif; ?> Você pode ajustar disputa por disputa na coluna <em>Atualiza a cada</em> (30 a 900 s); apague o valor para voltar ao automático. Uma coleta pesada já em andamento ocupa a fila até terminar, e esta regra não encurta isso.</p>
		</div>
		<p><input type="search" id="ae-selecao-busca" class="regular-text" placeholder="Buscar por cargo ou abrangência (ex.: Espírito Santo)"></p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
		<?php wp_nonce_field( 'ae_save_sync_selection' ); ?>
		<input type="hidden" name="action" value="ae_save_sync_selection">
		<?php foreach ( $groups as $position_name => $rows ) :
			$enabled_count = 0;
			foreach ( $rows as $c ) { $config = json_decode( (string) $c->config_json, true ); if ( ! isset( $config['collection']['enabled'] ) || $config['collection']['enabled'] ) { $enabled_count++; } }
			?><details class="ae-selecao-grupo"><summary><strong><?php echo esc_html( $position_name ); ?></strong> — <?php echo esc_html( (string) $enabled_count ); ?> de <?php echo esc_html( (string) count( $rows ) ); ?> sincronizando</summary>
			<p><button type="button" class="button button-small ae-marcar" onclick="this.closest('details').querySelectorAll('.ae-sync-check').forEach(function(c){c.checked=true;})">Marcar todas do grupo</button> <button type="button" class="button button-small" onclick="this.closest('details').querySelectorAll('.ae-sync-check').forEach(function(c){c.checked=false;})">Desmarcar todas do grupo</button></p>
			<table class="widefat striped"><thead><tr><th>Sincronizar</th><th>Disputa</th><th>Atualiza a cada</th><th>Último snapshot (UTC)</th></tr></thead><tbody>
			<?php foreach ( $rows as $c ) :
				$config = json_decode( (string) $c->config_json, true );
				$enabled = ! isset( $config['collection']['enabled'] ) || $config['collection']['enabled'];
				$collection = is_array( $config['collection'] ?? null ) ? $config['collection'] : array();
				$row_interval = AE_Collection_Policy::clamp( absint( $collection['interval'] ?? AE_Collection_Policy::DEFAULT_INTERVAL ) );
				$row_auto = AE_Collection_Policy::automatic_interval( (string) $c->position_code, $multi_uf );
				$row_manual = AE_Collection_Policy::is_manual( $collection );
				?><tr class="ae-selecao-linha" data-busca="<?php echo esc_attr( mb_strtolower( $position_name . ' ' . $c->scope_name ) ); ?>"><td><label><input class="ae-sync-check" type="checkbox" name="enabled[<?php echo esc_attr( (string) $c->id ); ?>]" value="1" <?php checked( $enabled ); ?>><input type="hidden" name="contest_ids[]" value="<?php echo esc_attr( (string) $c->id ); ?>"></label></td><td><?php echo esc_html( $c->scope_name . ' · ' . $c->round_no . 'º turno' ); ?></td><td><input type="number" class="small-text" name="interval[<?php echo esc_attr( (string) $c->id ); ?>]" min="<?php echo esc_attr( (string) AE_Collection_Policy::MIN_INTERVAL ); ?>" max="<?php echo esc_attr( (string) AE_Collection_Policy::MAX_INTERVAL ); ?>" step="5" value="<?php echo esc_attr( (string) $row_interval ); ?>" placeholder="<?php echo esc_attr( (string) $row_auto ); ?>"> s <small><?php echo $row_manual ? 'manual' : 'automático'; ?></small></td><td><?php echo esc_html( $c->latest ?: '—' ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			</details>
		<?php endforeach; if ( ! $contests ) : ?><p>Nenhuma disputa sincronizada ainda. Sincronize com o TSE na aba Configuração primeiro.</p><?php endif; ?>
		<p><button class="button button-primary button-hero">Salvar seleção</button></p>
		</form>
		<script>
		document.getElementById('ae-selecao-busca').addEventListener('input', function () {
			var termo = this.value.trim().toLowerCase();
			document.querySelectorAll('.ae-selecao-grupo').forEach(function (grupo) {
				var temMatch = false;
				grupo.querySelectorAll('.ae-selecao-linha').forEach(function (linha) {
					var bate = !termo || linha.dataset.busca.indexOf(termo) !== -1;
					linha.style.display = bate ? '' : 'none';
					if (bate) temMatch = true;
				});
				grupo.style.display = temMatch ? '' : 'none';
				if (termo && temMatch) { grupo.open = true; }
			});
		});
		</script>
		</section><?php
	}

	private function tab_import(): void {
		global $wpdb; $p=$wpdb->prefix.'ae_'; $elections=$this->elections(); $contests=$wpdb->get_results("SELECT c.*,e.name election_name FROM {$p}contests c INNER JOIN {$p}elections e ON e.id=c.election_id WHERE c.active=1 ORDER BY e.year DESC,c.round_no,c.position_name,c.scope_code"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$scope = $this->import_scope_from_selection( $contests );
		?><div class="ae-grid ae-two"><section class="ae-panel"><h2>1. Importar candidatos</h2><p>O plugin baixa o pacote “Candidatos” do portal de Dados Abertos do TSE e processa o CSV em lotes — só das UFs e cargos das disputas marcadas em <a href="<?php echo esc_url($this->url('selecao')); ?>">Seleção de disputas</a>.</p>
		<?php if ( ! $scope['ufs'] ) : ?><p class="description">⚠️ Nenhuma disputa habilitada ainda — marque ao menos uma em <a href="<?php echo esc_url($this->url('selecao')); ?>">Seleção de disputas</a> antes de importar, senão a importação traz o Brasil inteiro.</p><?php else : ?><p class="description">Vai importar: <strong><?php echo esc_html( implode( ', ', $scope['ufs'] ) ); ?></strong> · cargos: <strong><?php echo esc_html( implode( ', ', $scope['cargo_labels'] ) ); ?></strong></p><?php endif; ?>
		<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><?php wp_nonce_field('ae_start_import'); ?><input type="hidden" name="action" value="ae_start_import"><?php $this->election_select('election_id',$elections); ?><button class="button button-primary button-hero">Buscar e importar candidatos</button><p class="description">Fonte gerenciada pelo plugin; nenhuma URL precisa ser informada.</p></form></section>
		<section id="ae-collection" class="ae-panel"><h2>2. Coleta automática protegida</h2><p>As fontes EA20 são criadas pela sincronização do EA11. O servidor consulta o TSE com teto de 20 requisições/s, cache condicional e pausa preventiva de 10 minutos somente em bloqueios (403/429). Arquivos inexistentes (404) são isolados daquela disputa.</p><p><strong>Os visitantes nunca acessam o TSE.</strong> O bloco consulta somente a API REST deste WordPress, no navegador, sem prender o cache das páginas.</p><a class="button" href="<?php echo esc_url($this->url('setup')); ?>">Sincronizar ou trocar ambiente</a></section></div>
		<?php $this->last_import_panel( $elections ); ?>
		<section class="ae-panel"><h2>Fontes configuradas</h2><table class="widefat striped"><thead><tr><th>Disputa</th><th>Formato</th><th>Intervalo</th><th>Situação</th></tr></thead><tbody><?php $configured=0; foreach($contests as $c): $config=json_decode((string)$c->config_json,true); $collection=$config['collection']??array(); if(empty($collection['source_url']))continue; $configured++; ?><tr><td><?php echo esc_html($c->position_name.' · '.$c->scope_code.' · '.$c->round_no.'º turno'); ?></td><td><?php echo esc_html($collection['kind']??''); ?></td><td><?php echo esc_html((string)($collection['interval']??60)); ?>s</td><td><span class="ae-status <?php echo !empty($collection['enabled'])?'is-completed':'is-paused'; ?>"><?php echo !empty($collection['enabled'])?'Ativa':'Pausada'; ?></span></td></tr><?php endforeach; if(!$configured): ?><tr><td colspan="4">Nenhuma fonte configurada. Sincronize com o TSE na aba Configuração.</td></tr><?php endif; ?></tbody></table></section><?php
	}

	private function tab_jobs(): void {
		$state = sanitize_key( $_GET['state'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $state, array( '', 'queued', 'running', 'retry', 'completed', 'failed' ), true ) ) { $state = ''; }
		$days = absint( $_GET['days'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?><section class="ae-panel"><div class="ae-panel-head"><div><h2>Fila de processamento</h2><p>Atualização automática a cada cinco segundos. Jobs concluídos ou falhos com mais de 30 dias são removidos automaticamente.</p></div><?php $this->run_button(); ?></div>
		<form class="ae-inline ae-jobs-filter" method="get"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>"><input type="hidden" name="tab" value="jobs">
		<label>Situação<select name="state" onchange="this.form.submit()"><?php foreach ( array( '' => 'Todas', 'queued' => 'Na fila', 'running' => 'Processando', 'retry' => 'Nova tentativa', 'completed' => 'Concluído', 'failed' => 'Falhou' ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $state, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
		<label>Período<select name="days" onchange="this.form.submit()"><?php foreach ( array( 0 => 'Todo o histórico', 1 => 'Último dia', 7 => 'Últimos 7 dias', 30 => 'Últimos 30 dias' ) as $key => $label ) : ?><option value="<?php echo esc_attr( (string) $key ); ?>" <?php selected( $days, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
		<noscript><button class="button">Filtrar</button></noscript></form>
		<div id="ae-job-summary" class="ae-mini-stats"></div><div id="ae-jobs-table" data-state="<?php echo esc_attr( $state ); ?>" data-days="<?php echo esc_attr( (string) $days ); ?>"><?php echo $this->jobs_html( $state, $days ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></section><?php
	}
	private function tab_logs(): void { global $wpdb; $table=$wpdb->prefix.'ae_logs'; $logs=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 100"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?><section class="ae-panel"><h2>Últimos eventos</h2><table class="widefat striped"><thead><tr><th>Data UTC</th><th>Nível</th><th>Evento</th><th>Contexto</th></tr></thead><tbody><?php foreach($logs as $log): ?><tr><td><?php echo esc_html($log->created_at); ?></td><td><span class="ae-status is-<?php echo esc_attr($log->level); ?>"><?php echo esc_html(strtoupper($log->level)); ?></span></td><td><code><?php echo esc_html($log->event); ?></code></td><td><details><summary>Ver detalhes</summary><pre><?php echo esc_html($log->context_json?:'{}'); ?></pre></details></td></tr><?php endforeach; if(!$logs): ?><tr><td colspan="4">Nenhum evento registrado.</td></tr><?php endif; ?></tbody></table></section><?php }

	public function save_sync_selection(): void {
		$this->verify( 'ae_save_sync_selection' );
		global $wpdb; $p = $wpdb->prefix . 'ae_';
		$ids = array_map( 'absint', (array) ( $_POST['contest_ids'] ?? array() ) );
		$enabled_ids = array_map( 'absint', array_keys( (array) ( $_POST['enabled'] ?? array() ) ) );
		foreach ( $ids as $id ) {
			$config_json = $wpdb->get_var( $wpdb->prepare( "SELECT config_json FROM {$p}contests WHERE id=%d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$config = json_decode( (string) $config_json, true );
			if ( ! is_array( $config ) ) { $config = array(); }
			$config['collection'] = is_array( $config['collection'] ?? null ) ? $config['collection'] : array();
			$config['collection']['enabled'] = in_array( $id, $enabled_ids, true );
			// Intervalo: campo vazio devolve a disputa ao automático; valor diferente do gravado vira ajuste manual (a regra nunca o sobrescreve); igual ao gravado não muda nada.
			$posted = isset( $_POST['interval'][ $id ] ) ? trim( (string) wp_unslash( $_POST['interval'][ $id ] ) ) : null;
			if ( '' === $posted ) {
				$config['collection']['interval_mode'] = 'auto';
			} elseif ( null !== $posted && ctype_digit( $posted ) ) {
				$stored = AE_Collection_Policy::clamp( absint( $config['collection']['interval'] ?? AE_Collection_Policy::DEFAULT_INTERVAL ) );
				$new_interval = AE_Collection_Policy::clamp( (int) $posted );
				if ( $new_interval !== $stored ) {
					$config['collection']['interval'] = $new_interval;
					$config['collection']['interval_mode'] = 'manual';
				}
			}
			$wpdb->update( $p . 'contests', array( 'config_json' => wp_json_encode( $config ) ), array( 'id' => $id ) );
		}
		// O conjunto de UFs ligadas pode ter mudado: recalcula o intervalo das disputas em modo automático.
		AE_Collection_Policy::apply_all();
		AE_Logger::write( 'info', 'sync_selection_saved', array( 'total' => count( $ids ), 'enabled' => count( $enabled_ids ) ) );
		// A coleta começa agora, sem depender do WP-Cron (que em vários ambientes nunca dispara).
		AE_Job_Runner::instance()->run_now();
		$this->redirect( 'selecao', 'Seleção salva: ' . count( $enabled_ids ) . ' de ' . count( $ids ) . ' disputas sincronizando automaticamente.' );
	}

	/** Simulado nao tem valor legal nem se converte em Oficial; apaga apenas o que foi sincronizado sob esse ambiente. */
	public function wipe_test_data(): void {
		$this->verify( 'ae_wipe_test_data' );
		if ( empty( $_POST['confirm'] ) ) { $this->redirect( 'setup', 'Confirme a caixa de seleção para apagar os dados de teste.', 'error' ); }
		global $wpdb; $p = $wpdb->prefix . 'ae_';
		$election_ids = $wpdb->get_col( "SELECT id FROM {$p}elections WHERE config_json LIKE '%\"environment\":\"simulado\"%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $election_ids ) { $this->redirect( 'setup', 'Nenhum dado de Simulado encontrado.' ); }
		$eplaceholders = implode( ',', array_fill( 0, count( $election_ids ), '%d' ) );
		$contest_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}contests WHERE election_id IN ({$eplaceholders})", ...$election_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $contest_ids ) {
			$cplaceholders = implode( ',', array_fill( 0, count( $contest_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}result_rows WHERE snapshot_id IN (SELECT id FROM {$p}snapshots WHERE contest_id IN ({$cplaceholders}))", ...$contest_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}snapshots WHERE contest_id IN ({$cplaceholders})", ...$contest_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// Jobs guardam contest_id dentro do payload_json, nao numa coluna: JSON_EXTRACT numa query so, em vez de 1 LIKE '%...%' por disputa (110 varreduras da tabela inteira era o gargalo medido em homolog).
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}jobs WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.contest_id')) AS UNSIGNED) IN ({$cplaceholders})", ...$contest_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}candidate_contests WHERE contest_id IN ({$cplaceholders})", ...$contest_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}contests WHERE id IN ({$cplaceholders})", ...$contest_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}candidate_contests WHERE candidate_id IN (SELECT id FROM {$p}candidates WHERE election_id IN ({$eplaceholders}))", ...$election_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}candidates WHERE election_id IN ({$eplaceholders})", ...$election_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}elections WHERE id IN ({$eplaceholders})", ...$election_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE 'ae\\_tse\\_%' OR option_name LIKE 'ae\\_result\\_checked\\_%' OR option_name IN ('ae_last_kick_at','ae_last_purge_at')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		AE_Logger::write( 'warning', 'test_data_wiped', array( 'elections' => count( $election_ids ), 'contests' => count( $contest_ids ) ) );
		$this->redirect( 'setup', 'Dados de teste (Simulado) apagados: ' . count( $election_ids ) . ' eleição(ões), ' . count( $contest_ids ) . ' disputa(s).' );
	}

	public function sync_tse(): void {
		$this->verify( 'ae_sync_tse' );
		$environment = 'simulado' === sanitize_key( $_POST['environment'] ?? '' ) ? 'simulado' : 'oficial';
		$year = max( 2022, absint( $_POST['year'] ?? AE_Plugin::default_election_year() ) );
		$uf = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $_POST['uf'] ?? '' ) ), 0, 2 ) );
		if ( '' !== $uf ) { update_option( 'ae_site_uf', $uf, false ); }
		$payload = array( 'environment' => $environment, 'year' => $year );
		if ( $uf ) { $payload['site_uf'] = $uf; }
		$id = AE_Job_Runner::enqueue( 'sync_tse', $payload );
		AE_Job_Runner::instance()->run_now();
		$this->redirect( 'jobs', 'Sincronização oficial adicionada à fila' . ( $uf ? " (UF: {$uf})" : ' (⚠️ sem UF — disputas novas nascem todas ligadas)' ) . '. Job #' . $id . '.' );
	}

	public function quick_setup(): void {
		$this->verify('ae_quick_setup'); global $wpdb; $p=$wpdb->prefix.'ae_'; $year=max(2022,absint($_POST['year']??AE_Plugin::default_election_year())); $slug='eleicoes-'.$year; $name=sanitize_text_field(wp_unslash($_POST['name']??'Eleições '.$year)); $now=current_time('mysql',true);
		$id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}elections WHERE slug=%s",$slug)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if(!$id){$wpdb->insert($p.'elections',array('slug'=>$slug,'name'=>$name,'year'=>$year,'timezone'=>'America/Sao_Paulo','status'=>'active','config_json'=>'{}','created_at'=>$now,'updated_at'=>$now));$id=(int)$wpdb->insert_id;}else{$wpdb->update($p.'elections',array('name'=>$name,'status'=>'active','updated_at'=>$now),array('id'=>$id));}
		$items=array(array(1,'0001','Presidente','BR','BR','Brasil',1),array(2,'0001','Presidente','BR','BR','Brasil',1)); foreach($this->ufs() as $code=>$label){$items[]=array(1,'0003','Governador','UF',$code,$label,1);$items[]=array(2,'0003','Governador','UF',$code,$label,1);$items[]=array(1,'0005','Senador','UF',$code,$label,1);}
		foreach($items as $item){list($round,$position_code,$position_name,$scope_type,$scope_code,$scope_name,$seats)=$item;$external=$year.'-r'.$round.'-'.$position_code.'-'.$scope_code;$sql=$wpdb->prepare("INSERT INTO {$p}contests (election_id,external_id,round_no,position_code,position_name,scope_type,scope_code,scope_name,seats,active,config_json) VALUES (%d,%s,%d,%s,%s,%s,%s,%s,%d,1,'{}') ON DUPLICATE KEY UPDATE position_name=VALUES(position_name),scope_name=VALUES(scope_name),seats=VALUES(seats),active=1",$id,$external,$round,$position_code,$position_name,$scope_type,$scope_code,$scope_name,$seats);$wpdb->query($sql);} // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		AE_Logger::write('info','quick_setup_completed',array('election_id'=>$id,'year'=>$year,'contests'=>count($items)));$this->redirect('setup','Eleição preparada com '.count($items).' disputas.');
	}

	public function save_contest(): void { $this->verify('ae_save_contest'); global $wpdb; $table=$wpdb->prefix.'ae_contests'; $data=array('election_id'=>absint($_POST['election_id']??0),'round_no'=>max(1,absint($_POST['round_no']??1)),'position_code'=>sanitize_key(wp_unslash($_POST['position_code']??'')),'position_name'=>sanitize_text_field(wp_unslash($_POST['position_name']??'')),'scope_type'=>strtoupper(sanitize_key(wp_unslash($_POST['scope_type']??''))),'scope_code'=>strtoupper(sanitize_key(wp_unslash($_POST['scope_code']??''))),'scope_name'=>sanitize_text_field(wp_unslash($_POST['scope_name']??'')),'seats'=>max(1,absint($_POST['seats']??1)),'active'=>1,'config_json'=>'{}'); $data['external_id']='manual-'.$data['round_no'].'-'.$data['position_code'].'-'.$data['scope_code']; $wpdb->replace($table,$data); AE_Logger::write('info','contest_saved',array('contest_id'=>(int)$wpdb->insert_id));$this->redirect('setup','Disputa adicionada.'); }
	public function start_import(): void {
		$this->verify('ae_start_import');
		$election_id = absint( $_POST['election_id'] ?? 0 );
		$payload = AE_Collection_Policy::import_payload( $election_id );
		if ( ! $payload ) { $this->redirect( 'import', 'Selecione uma eleição válida.', 'error' ); }
		$id = AE_Job_Runner::enqueue( 'import_candidates', $payload );
		$detalhe = ! empty( $payload['ufs'] ) ? ' (' . implode( ', ', $payload['ufs'] ) . ')' : ' (Brasil inteiro — nenhuma disputa habilitada em Seleção de disputas)';
		$this->redirect( 'jobs', 'Importação oficial de candidatos adicionada à fila' . $detalhe . '. Job #' . $id . '.' );
	}

	/** Conta quantas disputas ativas têm collection.enabled=true (ou omisso, que também conta como ligado) — usado no card "Habilitadas p/ coleta" da Visão geral. */
	/** "há 12 s", "há 3 min" — curto o bastante para caber na linha de saúde. */
	private function short_age( int $seconds ): string {
		if ( $seconds < 90 ) { return 'há ' . max( 0, $seconds ) . ' s'; }
		return 'há ' . human_time_diff( time() - $seconds, time() );
	}

	/** Linha "Último tick" da tela de saúde: mostra de onde veio o último disparo do worker. */
	private function tick_health_html(): string {
		$tick = AE_Job_Runner::tick_status();
		if ( null === $tick['age'] ) { return '<span class="ae-dot ae-bad"></span> nunca executou'; }
		$origin = 'cli' === $tick['source'] ? 'cron do sistema' : 'WP-Cron';
		$bad = $tick['stale'] || $tick['cli_stopped'];
		return '<span class="ae-dot ' . ( $bad ? 'ae-bad' : 'ae-ok' ) . '"></span> ' . esc_html( $this->short_age( (int) $tick['age'] ) . ' (' . $origin . ')' );
	}

	/** Avisos no topo da Visão geral quando o disparo da coleta parou ou depende só do tráfego. */
	private function tick_notice(): void {
		$tick = AE_Job_Runner::tick_status();
		if ( $tick['enabled_contests'] < 1 ) { return; }
		$style = 'border-left:4px solid #d63638;padding:1px 12px;margin:12px 0;';
		$loopback = AE_Job_Runner::loopback_status();
		if ( ! $loopback['ok'] ) {
			$line = '* * * * * cd ' . untrailingslashit( ABSPATH ) . ' && wp cron event run --due-now >/dev/null 2>&1';
			echo '<div class="notice notice-warning inline" style="' . esc_attr( $style ) . '"><p><strong>O servidor não consegue acessar o próprio endereço</strong> (' . esc_html( $loopback['error'] ) . '), então o WP-Cron do WordPress não dispara. O plugin compensa processando a fila quando esta tela ou o site são abertos e a cada ação de salvar/sincronizar, mas, para coletar continuamente sem ninguém olhando, agende um cron de sistema: <code>' . esc_html( $line ) . '</code></p></div>';
		}
		$how = 'Para coletar sem depender de visitas, agende o cron de sistema com bin/tse-tick-loop.sh e declare TSE_APURACAO_CONTAINER (se usar Docker), TSE_APURACAO_PLUGIN_PATH e TSE_APURACAO_LOG_FILE dentro do próprio crontab — o crontab não herda variáveis do shell.';
		if ( $tick['stale'] ) {
			echo '<div class="notice notice-error inline" style="' . esc_attr( $style ) . '"><p><strong>Coleta parada:</strong> o processador não roda ' . esc_html( $this->short_age( (int) $tick['age'] ) ) . '. Com disputas ligadas, os resultados publicados ficam congelados. ' . esc_html( $how ) . '</p></div>';
		} elseif ( $tick['cli_stopped'] ) {
			echo '<div class="notice notice-warning inline" style="' . esc_attr( $style ) . '"><p><strong>Cron de sistema parou:</strong> o último disparo dele foi ' . esc_html( $this->short_age( (int) $tick['cli_age'] ) ) . '. A coleta continua só pelo WP-Cron, que depende de tráfego e é irregular. Confira se o crontab e as variáveis ainda estão corretos e se o log de ' . esc_html( 'TSE_APURACAO_LOG_FILE' ) . ' está crescendo.</p></div>';
		} elseif ( $tick['cli_never'] ) {
			echo '<p class="description">Cron de sistema não detectado: a coleta depende do tráfego do site (WP-Cron). ' . esc_html( $how ) . '</p>';
		}
	}

	/** Aviso permanente (todas as abas) quando falta um requisito de ambiente, por exemplo a extensão php-zip removida depois da ativação. */
	private function requirements_notice(): void {
		$missing = AE_Plugin::missing_requirements();
		if ( ! $missing ) { return; }
		echo '<div class="notice notice-error inline" style="border-left:4px solid #d63638;padding:1px 12px;margin:12px 0;"><p><strong>Requisito do servidor ausente:</strong> ' . esc_html( implode( '; ', $missing ) ) . '. Enquanto isso, a importação de candidatos não funciona.</p></div>';
	}

	/** Aviso quando as tabelas não são InnoDB: a transação do snapshot não protege nada nesse caso. */
	private function engine_notice(): void {
		$bad = AE_Schema::non_innodb_tables();
		if ( ! $bad ) { return; }
		echo '<div class="notice notice-error inline" style="border-left:4px solid #d63638;padding:1px 12px;margin:12px 0;"><p><strong>Tabelas fora do InnoDB:</strong> ' . esc_html( implode( ', ', $bad ) ) . '. O snapshot e o ranking são gravados numa transação; em outro mecanismo (como MyISAM) uma interrupção pode deixar um snapshot parcial sendo servido. Converta com <code>ALTER TABLE nome ENGINE=InnoDB</code>.</p></div>';
	}

	/**
	 * Aviso quando há disputas da UF do site desligadas. Sem UF configurada não há o que comparar.
	 *
	 * @param array<int,object>|null $contests Disputas já carregadas pela tela; carrega aqui se omitido.
	 */
	private function site_uf_notice( ?array $contests = null ): void {
		global $wpdb;
		$site_uf = strtoupper( (string) get_option( 'ae_site_uf', '' ) );
		if ( '' === $site_uf ) { return; }
		if ( null === $contests ) { $contests = (array) $wpdb->get_results( "SELECT position_name,scope_code,round_no,config_json FROM {$wpdb->prefix}ae_contests WHERE active=1 AND config_json IS NOT NULL" ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$off = AE_Collection_Policy::disabled_in_site_uf( $contests, $site_uf );
		if ( ! $off ) { return; }
		$names = array();
		foreach ( $off as $c ) { $names[ $c->position_name . ( (int) $c->round_no > 1 ? ' (' . (int) $c->round_no . 'º turno)' : '' ) ] = true; }
		echo '<div class="notice notice-warning inline" style="border-left:4px solid #dba617;padding:1px 12px;margin:12px 0;"><p><strong>Disputas de ' . esc_html( $site_uf ) . ' desligadas:</strong> ' . esc_html( implode( ', ', array_keys( $names ) ) ) . '. Elas não estão sendo coletadas nem importadas, e o que estiver publicado no site fica parado. Se isso não foi de propósito, ligue em <a href="' . esc_url( $this->url( 'selecao' ) ) . '">Seleção de disputas</a>.</p></div>';
	}

	private function count_enabled_contests(): int {
		global $wpdb; $p = $wpdb->prefix . 'ae_';
		$rows = $wpdb->get_col( "SELECT config_json FROM {$p}contests WHERE active=1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$enabled = 0;
		foreach ( $rows as $config_json ) {
			$config = json_decode( (string) $config_json, true );
			if ( ! isset( $config['collection']['enabled'] ) || $config['collection']['enabled'] ) { $enabled++; }
		}
		return $enabled;
	}

	/** Quando foi a última importação, de que CSV, quantos candidatos saíram da lista, e o agendamento automático. */
	private function last_import_panel( array $elections ): void {
		global $wpdb;
		$last = get_option( 'ae_last_import', array() ); $last = is_array( $last ) ? $last : array();
		$auto = absint( get_option( 'ae_auto_import_election', 0 ) );
		$removed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ae_candidates WHERE removed_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$utc = static function ( string $mysql ): string { return '' === $mysql ? '—' : (string) get_date_from_gmt( $mysql, 'd/m/Y H:i' ); };
		?><section class="ae-panel"><h2>Última importação de candidatos</h2>
		<?php if ( ! $last ) : ?><p>Nenhuma importação concluída ainda.</p><?php else : ?>
		<dl class="ae-health"><dt>Concluída em</dt><dd><?php echo esc_html( $utc( (string) ( $last['finished_at'] ?? '' ) ) ); ?></dd><dt>CSV gerado pelo TSE em</dt><dd><?php echo esc_html( ! empty( $last['csv_generated_at'] ) ? date_i18n( 'd/m/Y H:i', strtotime( (string) $last['csv_generated_at'] . ' UTC' ) ) . ' (Brasília)' : 'não informado' ); ?></dd><dt>Linhas importadas</dt><dd><?php echo esc_html( number_format_i18n( (int) ( $last['rows'] ?? 0 ) ) ); ?></dd><dt>Escopo</dt><dd><?php echo esc_html( ! empty( $last['ufs'] ) ? implode( ', ', (array) $last['ufs'] ) : 'Brasil inteiro' ); ?></dd></dl>
		<?php endif; ?>
		<p><strong><?php echo esc_html( number_format_i18n( $removed ) ); ?></strong> candidato(s) não constam mais na lista do TSE (renúncia ou substituição). Continuam no cadastro e o catálogo mostra o aviso; voltam ao normal se reaparecerem numa importação.</p>
		<form class="ae-inline" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><?php wp_nonce_field( 'ae_save_auto_import' ); ?><input type="hidden" name="action" value="ae_save_auto_import">
		<label>Reimportar automaticamente (a cada 6 h)<select name="election_id"><option value="0">Desligado</option><?php foreach ( $elections as $e ) : ?><option value="<?php echo esc_attr( (string) $e->id ); ?>" <?php selected( $auto, (int) $e->id ); ?>><?php echo esc_html( $e->name ); ?></option><?php endforeach; ?></select></label> <button class="button">Salvar</button>
		<p class="description">O TSE regenera o CSV todos os dias. Só importa as UFs e cargos ligados em Seleção de disputas, e nunca o Brasil inteiro. O download prende a fila por alguns minutos: desligue na noite da eleição.</p></form></section><?php
	}

	public function save_auto_import(): void {
		$this->verify( 'ae_save_auto_import' );
		global $wpdb;
		$election_id = absint( $_POST['election_id'] ?? 0 );
		if ( $election_id && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ae_elections WHERE id=%d", $election_id ) ) ) { $this->redirect( 'import', 'Eleição inválida.', 'error' ); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( 'ae_auto_import_election', $election_id, false );
		AE_Logger::write( 'info', 'auto_import_saved', array( 'election_id' => $election_id ) );
		$this->redirect( 'import', $election_id ? 'Reimportação automática ligada (a cada 6 h).' : 'Reimportação automática desligada.' );
	}

	/** UFs e cargos das disputas ligadas na Seleção de disputas (a mesma seleção usada pela coleta de resultado). */
	private function import_scope_from_selection( array $contests ): array { return AE_Collection_Policy::import_scope( $contests ); }
	public function retry_job(): void { $this->verify('ae_retry_job');global $wpdb;$id=absint($_POST['job_id']??0);$wpdb->update($wpdb->prefix.'ae_jobs',array('state'=>'retry','attempts'=>0,'run_after'=>current_time('mysql',true),'locked_until'=>null,'lock_token'=>null,'last_error'=>null),array('id'=>$id));$this->redirect('jobs','Job #'.$id.' recolocado na fila.'); }
	public function run_jobs(): void { $this->verify('ae_run_jobs');AE_Job_Runner::instance()->tick();$this->redirect('jobs','Fila processada manualmente.'); }
	public function ajax_status(): void { $this->guard();check_ajax_referer('ae_admin_status','nonce');global $wpdb;$table=$wpdb->prefix.'ae_jobs';$states=$wpdb->get_results("SELECT state,COUNT(*) total FROM {$table} GROUP BY state",OBJECT_K); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$state=sanitize_key($_POST['state']??'');if(!in_array($state,array('','queued','running','retry','completed','failed'),true))$state='';
		$days=absint($_POST['days']??0);
		wp_send_json_success(array('summary'=>array_map(static function ($row) { return (int)$row->total; },$states),'html'=>$this->jobs_html($state,$days))); }

	private function jobs_html( string $state = '', int $days = 0 ): string {
		global $wpdb; $table = $wpdb->prefix . 'ae_jobs';
		$where = array(); $params = array();
		if ( $state ) { $where[] = 'state = %s'; $params[] = $state; }
		if ( $days > 0 ) { $where[] = 'updated_at >= %s'; $params[] = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ); }
		$sql = "SELECT * FROM {$table}" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY id DESC LIMIT 50';
		$jobs = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ob_start();
		?><table class="widefat striped"><thead><tr><th>Job</th><th>Tipo</th><th>Situação</th><th>Progresso</th><th>Tentativas</th><th>Atualizado</th><th></th></tr></thead><tbody><?php foreach($jobs as $job):$cursor=json_decode((string)$job->cursor_json,true);?><tr><td>#<?php echo esc_html((string)$job->id);?></td><td><code><?php echo esc_html($job->type);?></code></td><td><span class="ae-status is-<?php echo esc_attr($job->state);?>"><?php echo esc_html($this->state_label($job->state));?></span><?php if($job->last_error):?><details><summary>Erro</summary><small><?php echo esc_html($job->last_error);?></small></details><?php endif;?></td><td><?php echo 'import_candidates'===$job->type?esc_html(number_format_i18n((int)($cursor['offset']??0)).' registros'):'—';?></td><td><?php echo esc_html((string)$job->attempts);?>/5</td><td><?php echo esc_html($job->updated_at);?> UTC</td><td><?php if('failed'===$job->state):?><form action="<?php echo esc_url(admin_url('admin-post.php'));?>" method="post"><?php wp_nonce_field('ae_retry_job');?><input type="hidden" name="action" value="ae_retry_job"><input type="hidden" name="job_id" value="<?php echo esc_attr($job->id);?>"><button class="button button-small">Tentar novamente</button></form><?php endif;?></td></tr><?php endforeach;if(!$jobs):?><tr><td colspan="7">Fila vazia.</td></tr><?php endif;?></tbody></table><?php return(string)ob_get_clean(); }
	/** Conexao atual com o TSE: ambiente/ano/ultima sync da eleicao sincronizada mais recente (config_json.tse); null se nunca sincronizou. */
	private function tse_connection( array $elections ): ?array {
		$best = null;
		foreach ( $elections as $e ) {
			$cfg = json_decode( (string) $e->config_json, true );
			if ( empty( $cfg['tse']['environment'] ) ) { continue; }
			if ( ! $best || (string) $e->updated_at > $best['synced'] ) { $best = array( 'environment' => 'oficial' === $cfg['tse']['environment'] ? 'oficial' : 'simulado', 'year' => (int) $e->year, 'synced' => (string) $e->updated_at ); }
		}
		return $best;
	}
	private function elections(bool $counts=false): array { global $wpdb;$p=$wpdb->prefix.'ae_';return $counts?$wpdb->get_results("SELECT e.*,COUNT(c.id) contests FROM {$p}elections e LEFT JOIN {$p}contests c ON c.election_id=e.id GROUP BY e.id ORDER BY e.year DESC"):$wpdb->get_results("SELECT * FROM {$p}elections ORDER BY year DESC"); } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	private function election_select(string $name,array $elections): void { ?><label>Eleição<select name="<?php echo esc_attr($name);?>" required><option value="">Selecione...</option><?php foreach($elections as $e):?><option value="<?php echo esc_attr($e->id);?>"><?php echo esc_html($e->name);?></option><?php endforeach;?></select></label><?php }
	private function run_button(): void { ?><form class="ae-inline" action="<?php echo esc_url(admin_url('admin-post.php'));?>" method="post"><?php wp_nonce_field('ae_run_jobs');?><input type="hidden" name="action" value="ae_run_jobs"><button class="button">Processar fila agora</button></form><?php }
	private function notice(): void { if(empty($_GET['ae_notice']))return;$type='error'===($_GET['ae_type']??'')?'notice-error':'notice-success';echo '<div class="notice '.esc_attr($type).' is-dismissible"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['ae_notice']))).'</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	private function redirect(string $tab,string $message,string $type='success'): never { wp_safe_redirect(add_query_arg(array('page'=>self::PAGE,'tab'=>$tab,'ae_notice'=>$message,'ae_type'=>$type),admin_url('admin.php')));exit; }
	private function verify(string $action): void { $this->guard();check_admin_referer($action); }
	private function guard(): void { if(!current_user_can('manage_options'))wp_die(esc_html__('Você não tem permissão para gerenciar a apuração.','tse-apuracao')); }
	private function url(string $tab): string { return add_query_arg(array('page'=>self::PAGE,'tab'=>$tab),admin_url('admin.php')); }
	private function state_label(string $state): string { return array('queued'=>'Na fila','running'=>'Processando','retry'=>'Nova tentativa','completed'=>'Concluído','failed'=>'Falhou')[$state]??$state; }
	private function ufs(): array { return array('AC'=>'Acre','AL'=>'Alagoas','AP'=>'Amapá','AM'=>'Amazonas','BA'=>'Bahia','CE'=>'Ceará','DF'=>'Distrito Federal','ES'=>'Espírito Santo','GO'=>'Goiás','MA'=>'Maranhão','MT'=>'Mato Grosso','MS'=>'Mato Grosso do Sul','MG'=>'Minas Gerais','PA'=>'Pará','PB'=>'Paraíba','PR'=>'Paraná','PE'=>'Pernambuco','PI'=>'Piauí','RJ'=>'Rio de Janeiro','RN'=>'Rio Grande do Norte','RS'=>'Rio Grande do Sul','RO'=>'Rondônia','RR'=>'Roraima','SC'=>'Santa Catarina','SP'=>'São Paulo','SE'=>'Sergipe','TO'=>'Tocantins'); }
}
