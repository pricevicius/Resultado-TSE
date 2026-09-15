<?php
defined( 'ABSPATH' ) || exit;

class TSE_Shortcode {
	private static bool $assets_localized = false;

    public static function init(): void {
        add_shortcode( 'tse_apuracao', [ __CLASS__, 'render' ] );
        add_shortcode( 'tse_apuracao_card', [ __CLASS__, 'render_card' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_route' ] );
    }

    public static function enqueue_assets(): void {
        // O shortcode pode aparecer em qualquer lugar (home, widget, page builder, block
        // theme) sem estar em $post->post_content, e enqueue tardio (dentro do próprio
        // render do shortcode) já passou do wp_head() — o <link> do CSS nunca sai. Por
        // isso carregamos sempre aqui; os arquivos são pequenos (CSS ~10KB, JS poucos KB).
        self::enqueue_widget_assets();
    }

	public static function enqueue_widget_assets(): void {

        $opts = get_option( 'tse_apuracao_settings', TSE_Settings::defaults() );

        wp_enqueue_style(
            'tse-apuracao',
            TSE_APURACAO_URL . 'assets/css/tse-apuracao.css',
            [],
            TSE_APURACAO_VERSION
        );

        wp_enqueue_script(
            'tse-live',
            TSE_APURACAO_URL . 'assets/js/tse-live.js',
            [],
            TSE_APURACAO_VERSION,
            true
        );

		if ( ! self::$assets_localized ) {
			wp_localize_script( 'tse-live', 'TSEConfig', [
				'restUrl'     => rest_url( 'tse/v1/resultado' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'corPrimaria' => $opts['cor_primaria'] ?? '#003366',
				'corEleito'   => $opts['cor_eleito']   ?? '#007A33',
			] );
			self::$assets_localized = true;
		}
    }

    public static function register_rest_route(): void {
        register_rest_route( 'tse/v1', '/resultado', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_resultado' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'cargo'   => [ 'sanitize_callback' => 'sanitize_text_field', 'default' => 'presidente' ],
                'uf'      => [ 'sanitize_callback' => 'sanitize_text_field', 'default' => 'br' ],
                'limite'  => [ 'sanitize_callback' => 'absint',              'default' => 10 ],
				'turno'   => [ 'sanitize_callback' => 'absint',              'default' => 1 ],
            ],
        ] );
    }

    public static function rest_resultado( WP_REST_Request $request ): WP_REST_Response {
        $cargo  = $request->get_param( 'cargo' );
        $uf     = $request->get_param( 'uf' );
        $limite = $request->get_param( 'limite' );

        if ( TSE_API::is_mock_mode() ) {
            $data = TSE_API::get_mock_resultado();
            if ( isset( $data['erro'] ) ) {
                return new WP_REST_Response( $data, 502 );
            }
        } else {
            $data = self::snapshot_resultado( $cargo, $uf, (int) $request->get_param( 'turno' ) ?: 1 );
            if ( isset( $data['erro'] ) ) {
                return new WP_REST_Response( $data, 503 );
            }
        }

        if ( $limite > 0 && ! empty( $data['candidatos'] ) ) {
            $data['candidatos'] = array_slice( $data['candidatos'], 0, $limite );
        }

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * Renderiza o shortcode [tse_apuracao].
     *
     * Atributos:
     *   cargo    = presidente|governador|senador|deputado-federal|deputado-estadual|prefeito|vereador
     *   uf       = br|sp|rj|mg|... (sigla em minúsculas)
     *   limite   = 10 (máx candidatos exibidos)
     *   atualizar= 60 (segundos; 0 = desligar auto-refresh)
     *   titulo   = "Resultado Presidente" (opcional)
     *   turno    = 1|2
     */
    public static function render( $atts ): string {
		self::enqueue_widget_assets();
        // Aceita 'title' como sinônimo de 'titulo' — engano comum de quem está acostumado a atributos em inglês.
        $titulo_informado = is_array( $atts ) ? ( $atts['titulo'] ?? $atts['title'] ?? '' ) : '';
        $atts = shortcode_atts( [
            'cargo'     => 'presidente',
            'uf'        => 'br',
            'limite'    => 10,
            'atualizar' => 60,
            'titulo'    => $titulo_informado,
            'turno'     => 1,
        ], $atts, 'tse_apuracao' );

        $cargo    = sanitize_text_field( $atts['cargo'] );
        $uf       = strtolower( sanitize_text_field( $atts['uf'] ) );
        $limite   = max( 1, (int) $atts['limite'] );
        $atualizar= max( 0, (int) $atts['atualizar'] );
        $titulo   = sanitize_text_field( $atts['titulo'] );
		$turno    = max( 1, min( 2, (int) $atts['turno'] ) );

        // Monta o ID único do widget para que o JS saiba o que atualizar
        $widget_id = 'tse-' . $cargo . '-' . $uf . '-' . uniqid();

        $dados = [];
        $erro  = null;

        if ( TSE_API::is_mock_mode() ) {
            $dados = TSE_API::get_mock_resultado();
            if ( isset( $dados['erro'] ) ) {
                $erro  = 'Erro no mock: ' . esc_html( $dados['erro'] );
                $dados = [];
            }
        } else {
			$dados = self::snapshot_resultado( $cargo, $uf, $turno );
            if ( isset( $dados['erro'] ) ) {
                $erro = 'Ainda não há snapshot válido para esta disputa.';
                $dados = [];
            } elseif ( $limite > 0 && ! empty( $dados['candidatos'] ) ) {
                $dados['candidatos'] = array_slice( $dados['candidatos'], 0, $limite );
            }
        }

        ob_start();
        include TSE_APURACAO_DIR . 'templates/resultado.php';
        return ob_get_clean();
    }

    /**
     * Renderiza o shortcode [tse_apuracao_card] — card compacto com só o líder da disputa.
     * Pensado para grades na home: pode ser repetido quantas vezes quiser, um por disputa.
     *
     * Atributos:
     *   cargo    = presidente|governador|senador|deputado-federal|deputado-estadual|prefeito|vereador
     *   uf       = br|sp|rj|mg|... (sigla em minúsculas)
     *   turno    = 1|2
     *   limite   = 1 (quantos colocados mostrar; 1 = card grande só do líder, >1 = mini-lista)
     *   atualizar= 60 (segundos; 0 = desligar auto-refresh)
     *   titulo   = "Governador — Espírito Santo" (opcional; default é montado a partir de cargo/uf)
     *   classe   = classe(s) CSS extra no elemento raiz, para o front estilizar sem !important
     */
    public static function render_card( $atts ): string {
        self::enqueue_widget_assets();
        // Aceita 'title' como sinônimo de 'titulo' — engano comum de quem está acostumado a atributos em inglês.
        $titulo_informado = is_array( $atts ) ? ( $atts['titulo'] ?? $atts['title'] ?? '' ) : '';
        $atts = shortcode_atts( [
            'cargo'     => 'presidente',
            'uf'        => 'br',
            'turno'     => 1,
            'limite'    => 1,
            'atualizar' => 60,
            'titulo'    => $titulo_informado,
            'classe'    => '',
        ], $atts, 'tse_apuracao_card' );

        $cargo       = sanitize_text_field( $atts['cargo'] );
        $uf          = strtolower( sanitize_text_field( $atts['uf'] ) );
        $turno       = max( 1, min( 2, (int) $atts['turno'] ) );
        $limite      = max( 1, (int) $atts['limite'] );
        $atualizar   = max( 0, (int) $atts['atualizar'] );
        $titulo      = sanitize_text_field( $atts['titulo'] );
        $classe_extra = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( (string) $atts['classe'] ) ) ) ) );
        $widget_id   = 'tse-card-' . $cargo . '-' . $uf . '-' . uniqid();

        $dados      = TSE_API::is_mock_mode() ? TSE_API::get_mock_resultado() : self::snapshot_resultado( $cargo, $uf, $turno );
        $erro       = isset( $dados['erro'] ) ? 'Ainda não há snapshot válido para esta disputa.' : null;
        $candidatos = ! $erro ? array_slice( $dados['candidatos'] ?? [], 0, $limite ) : [];
        $lider      = $candidatos[0] ?? null;

        ob_start();
        include TSE_APURACAO_DIR . 'templates/card.php';
        return ob_get_clean();
    }

    /** Compatibility adapter: the historical widget only reads materialized snapshots. */
    private static function snapshot_resultado( string $cargo, string $uf, int $turno ): array {
        $code = TSE_API::CARGOS[ $cargo ] ?? $cargo;
        $data = AE_Results::instance()->latest(
            'eleicoes-2026',
            max( 1, $turno ),
            str_pad( (string) $code, 4, '0', STR_PAD_LEFT ),
            strtoupper( $uf )
        );
		if ( ! $data || empty( $data['snapshot'] ) ) {
			global $wpdb;
			$p = $wpdb->prefix . 'ae_';
			$slug = $wpdb->get_var( $wpdb->prepare( "SELECT e.slug FROM {$p}elections e INNER JOIN {$p}contests c ON c.election_id=e.id INNER JOIN {$p}snapshots s ON s.contest_id=c.id WHERE e.year=%d AND c.round_no=%d AND c.position_code=%s AND c.scope_code=%s AND s.status='valid' ORDER BY s.id DESC LIMIT 1", 2026, max( 1, $turno ), str_pad( (string) $code, 4, '0', STR_PAD_LEFT ), strtoupper( $uf ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $slug ) { $data = AE_Results::instance()->latest( $slug, max( 1, $turno ), str_pad( (string) $code, 4, '0', STR_PAD_LEFT ), strtoupper( $uf ) ); }
		}
        if ( ! $data || empty( $data['snapshot'] ) ) {
            return array( 'erro' => 'Snapshot indisponível.' );
        }
        $totals = $data['snapshot']['totals'] ?? array();
        $reported = (int) ( $totals['reported_sections'] ?? 0 );
        $total = (int) ( $totals['total_sections'] ?? 0 );
		$pct_number = isset( $totals['reported_percentage'] ) ? (float) $totals['reported_percentage'] : ( $total > 0 ? ( $reported / $total ) * 100 : 0 );
		$pct = number_format_i18n( $pct_number, 2 ) . '%';
		$captured_at = (string) ( $data['snapshot']['captured_at'] ?? '' );
		$captured_timestamp = $captured_at ? strtotime( $captured_at ) : false;
        $candidates = array_map( static function ( array $candidate ): array {
            $situacao = (string) ( $candidate['situation'] ?? '' );
            return array(
                'numero' => (string) ( $candidate['ballot_number'] ?? '' ),
                'nome' => (string) ( $candidate['ballot_name'] ?: $candidate['full_name'] ?: $candidate['external_candidate_id'] ),
                'partido' => (string) ( $candidate['party'] ?? '' ),
                'votos' => (int) $candidate['votes'],
                'percentual' => number_format_i18n( (float) $candidate['percentage'], 2 ) . '%',
                'eleito' => (bool) $candidate['elected'],
                'situacao' => $situacao,
                // Sinalizador explicito para o front nao precisar adivinhar pelo texto livre de 'situacao'.
                'segundo_turno' => false !== mb_stripos( $situacao, 'turno' ),
                'foto_url' => (string) ( $candidate['photo_url'] ?? '' ),
                'sequencia' => (int) ( $candidate['rank_no'] ?? 0 ),
            );
        }, $data['candidates'] );
        return array(
            'atualizado_em' => $data['snapshot']['captured_at'],
            'horario' => $data['snapshot']['generated_at'] ?? '',
			'status' => ( $totals['progress'] ?? '' ) === 'final' ? 'Totalizado' : ( ( $totals['progress'] ?? '' ) === 'not_started' ? 'Aguardando apuração' : 'Parcial' ),
            'pct_apurado' => $pct,
			'pct_apurado_numero' => round( $pct_number, 2 ),
			// Disputa "final" nao recebe mais atualizacoes do TSE; snapshot antigo ali e normal, nao atraso.
			'atrasado' => 'final' !== ( $totals['progress'] ?? '' ) && ( ! $captured_timestamp || $captured_timestamp < time() - 3 * MINUTE_IN_SECONDS ),
			'votos_brancos' => (int) ( $totals['blank_votes'] ?? 0 ),
			'votos_nulos' => (int) ( $totals['null_votes'] ?? 0 ),
			'votos_anulados' => (int) ( $totals['annulled_votes'] ?? 0 ),
			'pct_votos_anulados' => round( (float) ( $totals['annulled_percentage'] ?? 0 ), 2 ),
            'turno' => (string) $turno,
            'candidatos' => $candidates,
        );
    }
}
