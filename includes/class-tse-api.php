<?php
defined( 'ABSPATH' ) || exit;

/**
 * Busca e normaliza os dados da API pública do TSE.
 *
 * URL real confirmada (2022):
 *   https://resultados.tse.jus.br/oficial/ele2022/544/dados-simplificados/{uf}/{uf}-c{cargo4d}-e{eleicao6d}-r.json
 *
 * Cargo é zero-padded para 4 dígitos: presidente=0001, governador=0003...
 * Eleição é zero-padded para 6 dígitos: 544 → 000544
 *
 * Ponto de entrada: https://resultados.tse.jus.br/oficial/comum/config/ele-c.json
 * Campos reais dos candidatos: cand[], n, nm, cc (partido), vap (votos), pvap (%), e (eleito S/N)
 */
class TSE_API {

    // Códigos internos do TSE para cada cargo (confirmados em 2022)
    const CARGOS = [
        'presidente'         => '1',
        'governador'         => '3',
        'senador'            => '5',
        'deputado-federal'   => '6',
        'deputado-estadual'  => '7',
        'deputado-distrital' => '8',
        'prefeito'           => '11',
        'vereador'           => '13',
    ];

    /** Retorna true se o modo mock estiver ativo nas configurações. */
    public static function is_mock_mode(): bool {
        $opts = get_option( 'tse_apuracao_settings', [] );
        return ! empty( $opts['mock_mode'] );
    }

    /** Em modo mock, lê o arquivo local de dados de teste. */
    public static function get_mock_resultado(): array {
        $file = TSE_APURACAO_DIR . 'mock/resultado-mock.json';
        if ( ! file_exists( $file ) ) {
            return [ 'erro' => 'Arquivo mock não encontrado: ' . $file ];
        }
        $raw = json_decode( file_get_contents( $file ), true );
        if ( ! is_array( $raw ) ) {
            return [ 'erro' => 'Mock JSON inválido' ];
        }
        return self::normalizar( $raw );
    }

    /** Retorna a configuração da eleição (ele-c.json), com cache de 5 minutos. */
    public static function get_config(): array {
        $opts      = get_option( 'tse_apuracao_settings', [] );
        $base_url  = trailingslashit( $opts['base_url'] ?? 'https://resultados.tse.jus.br/oficial/' );
        $cache_key = 'tse_ele_config_' . md5( $base_url );

        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $url      = $base_url . 'comum/config/ele-c.json';
        $response = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => true ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            return [];
        }

        set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
        return $data;
    }

    /**
     * Monta a URL do arquivo de resultado conforme o padrão real do TSE.
     *
     * Padrão confirmado 2022:
     *   /ele{ano}/{pleito}/dados-simplificados/{uf}/{uf}-c{cargo4d}-e{eleicao6d}-r.json
     *
     * Exemplo:
     *   /ele2022/544/dados-simplificados/br/br-c0001-e000544-r.json   ← presidente BR
     *   /ele2022/544/dados-simplificados/sp/sp-c0003-e000544-r.json   ← governador SP
     */
    private static function montar_url( string $base_url, string $ano, int $pleito, string $uf, string $cargo_num, string $eleicao ): string {
        $cargo_pad  = str_pad( $cargo_num, 4, '0', STR_PAD_LEFT );
        $eleicao_pad = str_pad( $eleicao, 6, '0', STR_PAD_LEFT );
        $path = "ele{$ano}/{$pleito}/dados-simplificados/{$uf}/{$uf}-c{$cargo_pad}-e{$eleicao_pad}-r.json";
        return trailingslashit( $base_url ) . $path;
    }

    /**
     * Busca resultados para um cargo/UF específico.
     *
     * @param string $cargo    Slug legível (ex: 'presidente', 'senador')
     * @param string $uf       Sigla em minúsculas (ex: 'br', 'sp')
     * @param string $eleicao  ID da eleição (numérico, do ele-c.json)
     * @param int    $pleito   ID do pleito (numérico, do ele-c.json)
     * @param int    $ttl      Cache em segundos
     */
    public static function get_resultado( string $cargo, string $uf, string $eleicao, int $pleito, int $ttl = 30 ): array {
        $opts      = get_option( 'tse_apuracao_settings', [] );
        $base_url  = $opts['base_url'] ?? 'https://resultados.tse.jus.br/oficial/';
        $ano       = $opts['ano'] ?? '2026';
        $uf        = strtolower( sanitize_text_field( $uf ) );
        $cargo_num = self::CARGOS[ $cargo ] ?? $cargo;

        $cache_key = "tse_resultado_{$uf}_{$cargo}_{$eleicao}_{$pleito}";
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $url = self::montar_url( $base_url, $ano, $pleito, $uf, $cargo_num, $eleicao );

        $response = wp_remote_get( $url, [
            'timeout'   => 15,
            'sslverify' => true,
            'headers'   => [ 'Accept' => 'application/json' ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'erro' => $response->get_error_message(), 'url' => $url ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return [ 'erro' => "HTTP {$code}", 'url' => $url ];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            return [ 'erro' => 'JSON inválido', 'url' => $url ];
        }

        $normalizado = self::normalizar( $data );
        set_transient( $cache_key, $normalizado, $ttl );
        return $normalizado;
    }

    /**
     * Normaliza o JSON real do TSE para o formato interno.
     *
     * Campos confirmados no JSON real (2022):
     *   cand[]  → array de candidatos (NÃO "cands")
     *   n       → número do candidato
     *   nm      → nome
     *   cc      → sigla do partido/coligação (NÃO "sg")
     *   vap     → votos apurados (NÃO "tv")
     *   pvap    → percentual dos votos apurados
     *   e       → eleito: "s" = eleito, vazio = não
     *   st      → situação: "2º turno", "Não eleito"
     *   dg, hg  → data e hora de geração
     *   pst     → % seções totalizadas (pode vir como número ou string)
     *   s       → status textual ("Parcial", "Totalizado")
     *
     * Mantém fallbacks para variações entre eleições.
     */
    private static function normalizar( array $raw ): array {
        $cands = [];

        // "cand" é o campo real do TSE; fallbacks para formato mock/legado
        $lista = $raw['cand'] ?? $raw['cands'] ?? $raw['c'] ?? [];

        foreach ( $lista as $c ) {
            // Partido: "cc" é o campo real (coligação/partido); "sg" era suposição anterior
            $partido = $c['cc'] ?? $c['sg'] ?? $c['sgp'] ?? '';

            // Votos: "vap" é o campo real; "tv" era suposição anterior
            $votos = (int) ( $c['vap'] ?? $c['tv'] ?? 0 );

            // Eleito: "st" = "Eleito" é a fonte correta.
            // "e" = "s" significa apenas que o candidato se qualificou (pode ser 2º turno).
            // Exemplos reais: st="2º turno" = classificado, st="Eleito" = ganhou, st="Não eleito" = perdeu.
            $situacao   = $c['st'] ?? '';
            $eleito     = stripos( $situacao, 'eleito' ) !== false && stripos( $situacao, 'não' ) === false && stripos( $situacao, 'nao' ) === false && stripos( $situacao, '2º' ) === false;

            // Nome: TSE às vezes retorna entidades HTML (ex: D&apos;Avila → D'Avila)
            $nome = html_entity_decode( $c['nm'] ?? $c['nmc'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            $cands[] = [
                'numero'     => $c['n']    ?? $c['nu']  ?? '',
                'nome'       => $nome,
                'partido'    => $partido,
                'votos'      => $votos,
                'percentual' => $c['pvap'] ?? $c['pv']  ?? '0%',
                'eleito'     => $eleito,
                'situacao'   => $situacao,
                'foto_url'   => $c['f']    ?? '',
                'sequencia'  => (int) ( $c['seq'] ?? 0 ),
            ];
        }

        usort( $cands, fn( $a, $b ) => $b['votos'] <=> $a['votos'] );

        // pst = % seções apuradas; vem como número (100.00) ou string ("87,42%")
        $pst_raw = $raw['pst'] ?? $raw['pa'] ?? '';
        if ( is_numeric( $pst_raw ) ) {
            $pst = number_format( (float) $pst_raw, 2, ',', '.' ) . '%';
        } elseif ( is_string( $pst_raw ) && $pst_raw !== '' && strpos( $pst_raw, '%' ) === false ) {
            $pst = $pst_raw . '%';
        } else {
            $pst = $pst_raw;
        }

        // Status: deriva do % apurado (pst) — campo "s" em 2022 contém nº de seções (numérico)
        $pct_num    = (float) str_replace( ',', '.', $pst_raw );
        $status_raw = $pct_num >= 100 ? 'Totalizado' : 'Parcial';

        // Data: "dg"+"hg" são geração; "dt"+"ht" são transmissão
        $data_hora = trim( ( $raw['dg'] ?? $raw['dt'] ?? '' ) . ' ' . ( $raw['hg'] ?? $raw['ht'] ?? '' ) );

        return [
            'atualizado_em' => $data_hora,
            'horario'       => $raw['hg'] ?? $raw['ht'] ?? '',
            'status'        => $status_raw,
            'pct_apurado'   => $pst,
            'turno'         => $raw['t']  ?? '1',
            'candidatos'    => $cands,
        ];
    }

    /**
     * Resolve IDs de eleição e pleito para um cargo e UF específicos.
     *
     * Estrutura do ele-c.json:
     *   pl[].cd           → código do pleito (segmento de URL: /ele2026/{cd}/)
     *   pl[].e[].cd       → código da eleição (no filename: -e{cd}-r.json)
     *   pl[].e[].abr[].cd → sigla da UF que essa eleição abrange
     *   pl[].e[].abr[].cp → array de códigos de cargo nessa UF
     *
     * Cada cargo+UF tem sua própria eleição — presidente tem eleicao diferente de governador.
     * Se os IDs forem configurados manualmente no admin, usa esses (override para testes).
     *
     * @param string $cargo_slug Slug legível (ex: 'governador')
     * @param string $uf         Sigla em maiúsculas (ex: 'SP') ou minúsculas
     */
    public static function resolver_ids( string $cargo_slug = '', string $uf = '' ): array {
        $opts = get_option( 'tse_apuracao_settings', [] );

        // Override manual — usa diretamente se configurado no admin
        if ( ! empty( $opts['eleicao_id'] ) && ! empty( $opts['pleito_id'] ) ) {
            return [
                'eleicao' => (string) $opts['eleicao_id'],
                'pleito'  => (int) $opts['pleito_id'],
            ];
        }

        $config = self::get_config();
        if ( empty( $config['pl'] ) ) {
            return [ 'eleicao' => '', 'pleito' => 0 ];
        }

        $cargo_num = self::CARGOS[ $cargo_slug ] ?? '';
        $uf_upper  = strtoupper( $uf );
        // "BR" no TSE é abrangência nacional (presidente, senador agregado, etc.)
        $uf_busca  = ( $uf_upper === 'BR' || $uf_upper === '' ) ? 'BR' : $uf_upper;

        // Percorre todos os pleitos e eleições buscando cargo+UF
        foreach ( $config['pl'] as $pleito_obj ) {
            $pleito_cd = (int) ( $pleito_obj['cd'] ?? 0 );
            foreach ( $pleito_obj['e'] ?? [] as $eleicao_obj ) {
                $eleicao_cd = (string) ( $eleicao_obj['cd'] ?? '' );
                foreach ( $eleicao_obj['abr'] ?? [] as $abr ) {
                    $abr_uf = strtoupper( $abr['cd'] ?? '' );
                    // Aceita match exato de UF ou BR (nacional) como fallback
                    if ( $abr_uf !== $uf_busca && $abr_uf !== 'BR' ) {
                        continue;
                    }
                    $cargos_abr = array_map( 'strval', $abr['cp'] ?? [] );
                    if ( $cargo_num !== '' && ! in_array( $cargo_num, $cargos_abr, true ) ) {
                        continue;
                    }
                    // Match encontrado — retorna o primeiro que atender cargo+UF
                    if ( $abr_uf === $uf_busca || $cargo_num === '' ) {
                        return [ 'eleicao' => $eleicao_cd, 'pleito' => $pleito_cd ];
                    }
                }
            }
        }

        // Fallback: primeiro pleito/eleição disponível (para eleições simples com 1 pleito)
        $pleito_obj = reset( $config['pl'] );
        $pleito_cd  = (int) ( $pleito_obj['cd'] ?? 0 );
        $eleicoes   = $pleito_obj['e'] ?? [];
        $eleicao_cd = '';
        if ( ! empty( $eleicoes ) ) {
            $primeira   = reset( $eleicoes );
            $eleicao_cd = (string) ( $primeira['cd'] ?? '' );
        }

        // Se não tiver eleição separada, usa o mesmo código do pleito
        if ( empty( $eleicao_cd ) ) {
            $eleicao_cd = (string) $pleito_cd;
        }

        return [
            'eleicao' => $eleicao_cd,
            'pleito'  => $pleito_cd,
        ];
    }
}
