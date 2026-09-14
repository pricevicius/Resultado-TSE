<?php
defined( 'ABSPATH' ) || exit;

class TSE_Settings {

    const OPTION_KEY = 'tse_apuracao_settings';

    public static function defaults(): array {
        return [
            'base_url'      => 'https://resultados.tse.jus.br/oficial/',
            'ano'           => '2026',
            'eleicao_id'    => '',   // vazio = auto via ele-c.json
            'pleito_id'     => '',   // vazio = auto via ele-c.json
            'ttl_ao_vivo'   => 30,   // segundos de cache durante eleição
            'ttl_normal'    => 300,  // segundos de cache fora do período
            'cor_primaria'  => '#003366',
            'cor_eleito'    => '#007A33',
            'mock_mode'     => false,
        ];
    }

    public static function init(): void {
		// Administration is centralized in Apuração. Keep this class only as a
		// compatibility layer for visual options and the historical shortcode.
    }

    public static function add_menu(): void {
        add_options_page(
            'TSE Apuração',
            'TSE Apuração',
            'manage_options',
            'tse-apuracao',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( 'tse_apuracao_group', self::OPTION_KEY, [
            'sanitize_callback' => [ __CLASS__, 'sanitize' ],
        ] );

        add_settings_section( 'tse_api',    'Configuração da API do TSE',   '__return_false', 'tse-apuracao' );
        add_settings_section( 'tse_teste', 'Teste e Desenvolvimento',       '__return_false', 'tse-apuracao' );
        add_settings_section( 'tse_visual', 'Aparência',                   '__return_false', 'tse-apuracao' );

        $fields_api = [
            'base_url'    => [ 'URL base do TSE',        'text',   'https://resultados.tse.jus.br/oficial/' ],
            'ano'         => [ 'Ano da eleição',         'text',   '2026' ],
            'eleicao_id'  => [ 'ID da eleição (CD)',     'text',   'Deixe em branco para detectar automaticamente via ele-c.json' ],
            'pleito_id'   => [ 'ID do pleito (PL)',      'text',   'Deixe em branco para detectar automaticamente' ],
            'ttl_ao_vivo' => [ 'Cache ao vivo (seg)',    'number', '30 segundos recomendado durante apuração' ],
            'ttl_normal'  => [ 'Cache padrão (seg)',     'number', '300 segundos fora do período eleitoral' ],
        ];

        foreach ( $fields_api as $key => $cfg ) {
            add_settings_field(
                $key, $cfg[0],
                [ __CLASS__, 'render_field' ],
                'tse-apuracao', 'tse_api',
                [ 'key' => $key, 'type' => $cfg[1], 'desc' => $cfg[2] ]
            );
        }

        add_settings_field(
            'mock_mode', 'Usar dados de teste (mock)',
            [ __CLASS__, 'render_mock_field' ],
            'tse-apuracao', 'tse_teste'
        );

        $fields_visual = [
            'cor_primaria' => [ 'Cor primária',  'color', '#003366' ],
            'cor_eleito'   => [ 'Cor "Eleito"',  'color', '#007A33' ],
        ];

        foreach ( $fields_visual as $key => $cfg ) {
            add_settings_field(
                $key, $cfg[0],
                [ __CLASS__, 'render_field' ],
                'tse-apuracao', 'tse_visual',
                [ 'key' => $key, 'type' => $cfg[1], 'desc' => $cfg[2] ]
            );
        }
    }

    public static function render_mock_field(): void {
        $opts    = get_option( self::OPTION_KEY, self::defaults() );
        $checked = ! empty( $opts['mock_mode'] ) ? 'checked' : '';
        $name    = self::OPTION_KEY . '[mock_mode]';
        $mock_file = TSE_APURACAO_DIR . 'mock/resultado-mock.json';
        echo "<label>";
        echo "<input type=\"checkbox\" name=\"{$name}\" value=\"1\" {$checked}>";
        echo " Ativar modo mock (usa dados do arquivo local em vez da API do TSE)";
        echo "</label>";
        echo "<p class=\"description\">Arquivo de dados: <code>{$mock_file}</code><br>";
        echo "Edite esse arquivo para simular diferentes cenários de apuração.</p>";
        if ( ! empty( $opts['mock_mode'] ) ) {
            echo "<p style=\"color:#b91c1c;font-weight:600;\">⚠️ MODO MOCK ATIVO — desative antes de ir para produção!</p>";
        }
    }

    public static function render_field( array $args ): void {
        $opts  = get_option( self::OPTION_KEY, self::defaults() );
        $key   = $args['key'];
        $type  = $args['type'];
        $desc  = $args['desc'] ?? '';
        $val   = esc_attr( $opts[ $key ] ?? '' );
        $name  = self::OPTION_KEY . '[' . $key . ']';

        echo "<input type=\"{$type}\" name=\"{$name}\" value=\"{$val}\" class=\"regular-text\">";
        if ( $desc ) {
            echo "<p class=\"description\">{$desc}</p>";
        }
    }

    public static function sanitize( $input ): array {
        $defaults = self::defaults();
        $clean    = [];

        $clean['base_url']      = esc_url_raw( $input['base_url'] ?? $defaults['base_url'] );
        $clean['ano']           = preg_replace( '/\D/', '', $input['ano'] ?? $defaults['ano'] );
        $clean['eleicao_id']    = sanitize_text_field( $input['eleicao_id'] ?? '' );
        $clean['pleito_id']     = sanitize_text_field( $input['pleito_id'] ?? '' );
        $clean['ttl_ao_vivo']   = max( 10, (int) ( $input['ttl_ao_vivo'] ?? $defaults['ttl_ao_vivo'] ) );
        $clean['ttl_normal']    = max( 60, (int) ( $input['ttl_normal'] ?? $defaults['ttl_normal'] ) );
        $clean['cor_primaria']  = sanitize_hex_color( $input['cor_primaria'] ?? $defaults['cor_primaria'] ) ?: $defaults['cor_primaria'];
        $clean['cor_eleito']    = sanitize_hex_color( $input['cor_eleito'] ?? $defaults['cor_eleito'] ) ?: $defaults['cor_eleito'];
        $clean['mock_mode']     = ! empty( $input['mock_mode'] );

        return $clean;
    }

    public static function maybe_show_config_notice(): void {
        $screen = get_current_screen();
        if ( $screen && $screen->id === 'settings_page_tse-apuracao' ) {
            return;
        }

        $opts = get_option( self::OPTION_KEY, [] );
        if ( empty( $opts['eleicao_id'] ) ) {
            $url = admin_url( 'options-general.php?page=tse-apuracao' );
            echo "<div class=\"notice notice-warning\"><p><strong>TSE Apuração:</strong> Configure o ID da eleição ou verifique se o ele-c.json está acessível. <a href=\"{$url}\">Configurações</a></p></div>";
        }
    }

    public static function render_page(): void {
        $ids    = TSE_API::resolver_ids();
        $config = TSE_API::get_config();
        ?>
        <div class="wrap">
            <h1>⚖️ TSE Apuração — Configurações</h1>

            <div style="background:#f0f6ff;border-left:4px solid #0073aa;padding:12px 16px;margin:16px 0;border-radius:0 4px 4px 0;">
                <strong>Shortcode disponível:</strong><br>
                <code>[tse_apuracao cargo="presidente" uf="br" limite="10" atualizar="60"]</code><br><br>
                <strong>Atributos:</strong>
                <ul style="margin:8px 0 0 16px">
                    <li><code>cargo</code>: presidente · governador · senador · deputado-federal · deputado-estadual · prefeito · vereador</li>
                    <li><code>uf</code>: br · sp · rj · mg · (qualquer sigla de estado em minúsculas)</li>
                    <li><code>limite</code>: número máximo de candidatos (padrão: 10)</li>
                    <li><code>atualizar</code>: segundos entre atualizações automáticas, 0 = desligado (padrão: 60)</li>
                    <li><code>titulo</code>: título exibido no painel (opcional)</li>
                    <li><code>turno</code>: 1 ou 2 (padrão: 1)</li>
                </ul>
            </div>

            <?php if ( ! empty( $ids['eleicao'] ) ) : ?>
            <div style="background:#f0fff4;border-left:4px solid #007A33;padding:12px 16px;margin:16px 0;border-radius:0 4px 4px 0;">
                <strong>IDs detectados automaticamente:</strong>
                Eleição <code><?php echo esc_html( $ids['eleicao'] ); ?></code> /
                Pleito <code><?php echo esc_html( $ids['pleito'] ); ?></code>
                <?php if ( ! empty( $config ) ) : ?>
                — ele-c.json carregado com sucesso ✅
                <?php endif; ?>
            </div>
            <?php else : ?>
            <div style="background:#fff8e5;border-left:4px solid #D97706;padding:12px 16px;margin:16px 0;border-radius:0 4px 4px 0;">
                ⚠️ Não foi possível detectar IDs automaticamente. Preencha manualmente abaixo ou aguarde o início do período de divulgação do TSE.
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'tse_apuracao_group' );
                do_settings_sections( 'tse-apuracao' );
                submit_button( 'Salvar configurações' );
                ?>
            </form>
        </div>
        <?php
    }
}
