<?php
defined( 'ABSPATH' ) || exit;

class TSE_Shortcode {

    public static function init(): void {
        add_shortcode( 'tse_apuracao', [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_route' ] );
    }

    public static function enqueue_assets(): void {
        global $post;
        // Só carrega nos posts/páginas que usam o shortcode
        if ( ! is_singular() || ! is_a( $post, 'WP_Post' ) ) {
            return;
        }
        if ( ! has_shortcode( $post->post_content, 'tse_apuracao' ) ) {
            return;
        }

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

        wp_localize_script( 'tse-live', 'TSEConfig', [
            'restUrl'     => rest_url( 'tse/v1/resultado' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'corPrimaria' => $opts['cor_primaria'] ?? '#003366',
            'corEleito'   => $opts['cor_eleito']   ?? '#007A33',
        ] );
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
            ],
        ] );
    }

    public static function rest_resultado( WP_REST_Request $request ): WP_REST_Response {
        $cargo  = $request->get_param( 'cargo' );
        $uf     = $request->get_param( 'uf' );
        $limite = $request->get_param( 'limite' );

        $opts = get_option( 'tse_apuracao_settings', TSE_Settings::defaults() );
        $ttl  = (int) ( $opts['ttl_ao_vivo'] ?? 30 );

        if ( TSE_API::is_mock_mode() ) {
            $data = TSE_API::get_mock_resultado();
            if ( isset( $data['erro'] ) ) {
                return new WP_REST_Response( $data, 502 );
            }
            if ( $limite > 0 && ! empty( $data['candidatos'] ) ) {
                $data['candidatos'] = array_slice( $data['candidatos'], 0, $limite );
            }
            return new WP_REST_Response( $data, 200 );
        }

        $ids = TSE_API::resolver_ids( $cargo, $uf );
        if ( empty( $ids['eleicao'] ) ) {
            return new WP_REST_Response( [ 'erro' => 'Eleição não configurada. Acesse Configurações > TSE Apuração.' ], 503 );
        }

        $data = TSE_API::get_resultado( $cargo, $uf, $ids['eleicao'], $ids['pleito'], $ttl );

        if ( isset( $data['erro'] ) ) {
            return new WP_REST_Response( $data, 502 );
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
        $atts = shortcode_atts( [
            'cargo'     => 'presidente',
            'uf'        => 'br',
            'limite'    => 10,
            'atualizar' => 60,
            'titulo'    => '',
            'turno'     => 1,
        ], $atts, 'tse_apuracao' );

        $cargo    = sanitize_text_field( $atts['cargo'] );
        $uf       = strtolower( sanitize_text_field( $atts['uf'] ) );
        $limite   = max( 1, (int) $atts['limite'] );
        $atualizar= max( 0, (int) $atts['atualizar'] );
        $titulo   = sanitize_text_field( $atts['titulo'] );

        $opts  = get_option( 'tse_apuracao_settings', TSE_Settings::defaults() );
        $ttl   = (int) ( $opts['ttl_ao_vivo'] ?? 30 );
        // Passa cargo e UF para encontrar a eleicao correta no ele-c.json
        $ids   = TSE_API::resolver_ids( $cargo, $uf );

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
        } elseif ( empty( $ids['eleicao'] ) ) {
            $erro = 'Eleição não configurada. Acesse <a href="' . admin_url( 'options-general.php?page=tse-apuracao' ) . '">Configurações > TSE Apuração</a>.';
        } else {
            $dados = TSE_API::get_resultado( $cargo, $uf, $ids['eleicao'], $ids['pleito'], $ttl );
            if ( isset( $dados['erro'] ) ) {
                $erro = 'Dados temporariamente indisponíveis: ' . esc_html( $dados['erro'] );
                $dados = [];
            } elseif ( $limite > 0 && ! empty( $dados['candidatos'] ) ) {
                $dados['candidatos'] = array_slice( $dados['candidatos'], 0, $limite );
            }
        }

        ob_start();
        include TSE_APURACAO_DIR . 'templates/resultado.php';
        return ob_get_clean();
    }
}
