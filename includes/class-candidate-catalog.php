<?php
defined( 'ABSPATH' ) || exit;

/** Public, local-only candidate directory fed by TSE Dados Abertos and EA20 snapshots. */
final class AE_Candidate_Catalog {
	/**
	 * O TSE preenche campos sem informação com marcadores como "#NE" (não divulgado) e "#NULO",
	 * em vez de deixar vazio. Isso não é dado para o leitor: devolve '' para esses marcadores.
	 */
	public static function clean_value( $value ): string {
		$value = trim( (string) $value );
		return 1 === preg_match( '/^#[A-Z]{2,}$/', $value ) ? '' : $value;
	}

	public static function render( array $atts = array() ): string {
		global $wpdb;
		wp_enqueue_style( 'tse-apuracao', AE_URL . 'assets/css/tse-apuracao.css', array(), AE_VERSION );
		$p = $wpdb->prefix . 'ae_';
		$q = sanitize_text_field( wp_unslash( $_GET['ae_busca'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$position = sanitize_text_field( wp_unslash( $_GET['ae_cargo'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope = strtoupper( sanitize_key( wp_unslash( $_GET['ae_uf'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$party = sanitize_text_field( wp_unslash( $_GET['ae_partido'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = sanitize_text_field( wp_unslash( $_GET['ae_candidato'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base = remove_query_arg( array( 'ae_busca', 'ae_cargo', 'ae_uf', 'ae_partido', 'ae_candidato', 'ae_pagina', 'paged' ) );
		// Parâmetro próprio (ae_pagina), não o "paged" do WordPress: numa página estática o core trata "paged" como paginação de arquivo.
		$per_page = self::per_page( $atts );
		$page = max( 1, absint( wp_unslash( $_GET['ae_pagina'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id ) { return self::profile( $id, $base ); }
		$where = array( '1=1' ); $args = array();
		if ( $q ) { $where[] = '(c.ballot_name LIKE %s OR c.full_name LIKE %s OR c.ballot_number LIKE %s)'; $like = '%' . $wpdb->esc_like( $q ) . '%'; array_push( $args, $like, $like, $like ); }
		if ( $position ) { $where[] = 'ct.position_code=%s'; $args[] = str_pad( (string) absint( $position ), 4, '0', STR_PAD_LEFT ); }
		if ( $scope ) { $where[] = 'ct.scope_code=%s'; $args[] = $scope; }
		if ( $party ) { $where[] = 'c.party=%s'; $args[] = $party; }
		$from = "FROM {$p}candidates c LEFT JOIN {$p}contests ct ON ct.id=c.contest_id WHERE " . implode( ' AND ', $where );
		// Total real do filtro (antes havia um LIMIT 60 fixo e o contador mostrava no máximo 60).
		$count_sql = "SELECT COUNT(DISTINCT c.id) {$from}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page = min( $page, $total_pages );
		// c.id desempata nomes iguais: sem isso a ordem pode se repetir ou pular candidatos entre páginas.
		$sql = "SELECT DISTINCT c.*,ct.position_name,ct.position_code,ct.scope_code {$from} ORDER BY c.ballot_name ASC, c.id ASC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$filters = array_filter( array( 'ae_busca' => $q, 'ae_cargo' => $position, 'ae_uf' => $scope, 'ae_partido' => $party ), static function ( $value ) { return '' !== $value; } );
		$pagination = $total_pages > 1 ? paginate_links( array(
			'base'      => add_query_arg( 'ae_pagina', '%#%', add_query_arg( array_map( 'rawurlencode', $filters ), $base ) ),
			'format'    => '',
			'current'   => $page,
			'total'     => $total_pages,
			'prev_text' => '← Anterior',
			'next_text' => 'Próxima →',
			'type'      => 'list',
			'add_fragment' => '#ae-catalog-lista',
		) ) : '';
		$parties = $wpdb->get_col( "SELECT DISTINCT party FROM {$p}candidates WHERE party <> '' ORDER BY party" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ob_start(); ?>
		<section id="ae-catalog" class="ae-catalog" aria-label="Consulta de candidatos">
			<header><p class="ae-catalog-kicker">Eleições 2026</p><h1>Conheça os candidatos</h1><p>Consulte candidatura, partido e resultados quando a apuração estiver disponível.</p></header>
			<form class="ae-catalog-filters" method="get" action="<?php echo esc_url( $base . '#ae-catalog-lista' ); ?>"><input name="ae_busca" value="<?php echo esc_attr( $q ); ?>" placeholder="Nome ou número"><select name="ae_cargo"><option value="">Todos os cargos</option><?php foreach ( array( '0001'=>'Presidente','0003'=>'Governador','0005'=>'Senador','0006'=>'Deputado Federal','0007'=>'Deputado Estadual','0008'=>'Deputado Distrital' ) as $code=>$label ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $position, $code ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><input name="ae_uf" maxlength="2" value="<?php echo esc_attr( $scope ); ?>" placeholder="UF"><select name="ae_partido"><option value="">Todos os partidos</option><?php foreach ( $parties as $item ) : ?><option value="<?php echo esc_attr( $item ); ?>" <?php selected( $party, $item ); ?>><?php echo esc_html( $item ); ?></option><?php endforeach; ?></select><button>Buscar</button></form>
			<p id="ae-catalog-lista" class="ae-catalog-count"><?php echo esc_html( self::count_label( $total, $page, $per_page, count( $rows ) ) ); ?></p><div class="ae-catalog-grid"><?php foreach ( $rows as $candidate ) : $url = add_query_arg( 'ae_candidato', rawurlencode( $candidate['external_id'] ), $base ); ?><article class="ae-candidate-card"><?php if ( $candidate['photo_url'] ) : ?><img src="<?php echo esc_url( $candidate['photo_url'] ); ?>" alt=""><?php else : ?><div class="ae-candidate-avatar" aria-hidden="true">👤</div><?php endif; ?><div><span class="ae-candidate-number"><?php echo esc_html( $candidate['ballot_number'] ); ?></span><h2><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $candidate['ballot_name'] ?: $candidate['full_name'] ); ?></a></h2><p><?php echo esc_html( trim( $candidate['party'] . ' · ' . ( $candidate['position_name'] ?? '' ) ) ); ?></p><small><?php echo esc_html( $candidate['scope_code'] ?? '' ); ?></small></div></article><?php endforeach; ?></div><?php if ( ! $rows ) : ?><p class="ae-catalog-empty">Nenhum candidato encontrado. Importe os dados abertos do TSE ou altere os filtros.</p><?php endif; ?><?php if ( $pagination ) : ?><nav class="ae-catalog-pagination" aria-label="Paginação dos candidatos"><?php echo wp_kses_post( $pagination ); ?></nav><?php endif; ?></section><?php return (string) ob_get_clean();
	}
	/** Candidatos por página: atributo por_pagina do shortcode, senão 24 (múltiplo de 3, fecha a grade); filtro ae_catalog_per_page. */
	private static function per_page( array $atts ): int {
		$default = (int) apply_filters( 'ae_catalog_per_page', 24 );
		$wanted = absint( $atts['por_pagina'] ?? 0 );
		return max( 1, min( 200, $wanted ?: $default ) );
	}

	/** "1.487 candidatos encontrados · mostrando 1–24". */
	private static function count_label( int $total, int $page, int $per_page, int $shown ): string {
		if ( 0 === $total ) { return 'Nenhum candidato encontrado'; }
		$label = number_format_i18n( $total ) . ( 1 === $total ? ' candidato encontrado' : ' candidatos encontrados' );
		if ( $total <= $per_page ) { return $label; }
		$first = ( $page - 1 ) * $per_page + 1;
		return $label . ' · mostrando ' . number_format_i18n( $first ) . '–' . number_format_i18n( $first + $shown - 1 );
	}

	private static function profile( string $external, string $back ): string {
		global $wpdb; $p = $wpdb->prefix . 'ae_'; $row = $wpdb->get_row( $wpdb->prepare( "SELECT c.*,ct.position_name,ct.scope_code FROM {$p}candidates c LEFT JOIN {$p}contests ct ON ct.id=c.contest_id WHERE c.external_id=%s ORDER BY c.updated_at DESC LIMIT 1", $external ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) { return '<p class="ae-catalog-empty">Candidato não encontrado.</p>'; }
		$data = json_decode( (string) $row['data_json'], true ); $data = is_array( $data ) ? $data : array();
		$personal = array_filter( array_map( array( __CLASS__, 'clean_value' ), array( 'Ocupação' => $data['DS_OCUPACAO'] ?? '', 'Data de nascimento' => $data['DT_NASCIMENTO'] ?? '', 'Escolaridade' => $data['DS_GRAU_INSTRUCAO'] ?? '', 'Estado civil' => $data['DS_ESTADO_CIVIL'] ?? '', 'Naturalidade' => trim( self::clean_value( $data['NM_MUNICIPIO_NASCIMENTO'] ?? '' ) . ' ' . self::clean_value( $data['SG_UF_NASCIMENTO'] ?? '' ) ) ) ) );
		$application = array_filter( array_map( array( __CLASS__, 'clean_value' ), array( 'Nome completo' => $row['full_name'], 'Situação' => self::clean_value( $row['situation'] ) ?: 'Não informada', 'Coligação' => $data['NM_COLIGACAO'] ?? '', 'Composição' => $data['DS_COMPOSICAO_COLIGACAO'] ?? '' ) ) );
		ob_start(); ?><article class="ae-candidate-profile"><a href="<?php echo esc_url( $back ); ?>">← Voltar aos candidatos</a><header><?php if ( $row['photo_url'] ) : ?><img src="<?php echo esc_url( $row['photo_url'] ); ?>" alt=""><?php endif; ?><div><span><?php echo esc_html( $row['ballot_number'] ); ?></span><h1><?php echo esc_html( $row['ballot_name'] ?: $row['full_name'] ); ?></h1><p><?php echo esc_html( $row['party'] ); ?> · <?php echo esc_html( $row['position_name'] ); ?> · <?php echo esc_html( $row['scope_code'] ); ?></p></div></header><h2>Dados da candidatura</h2><dl><?php foreach ( $application as $label=>$value ) : ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd><?php endforeach; ?></dl><?php if ( $personal ) : ?><h2>Dados pessoais</h2><dl><?php foreach ( $personal as $label=>$value ) : ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd><?php endforeach; ?></dl><?php endif; ?></article><?php return (string) ob_get_clean();
	}
}
