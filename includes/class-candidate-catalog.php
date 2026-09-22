<?php
defined( 'ABSPATH' ) || exit;

/** Public, local-only candidate directory fed by TSE Dados Abertos and EA20 snapshots. */
final class AE_Candidate_Catalog {
	public static function render( array $atts = array() ): string {
		global $wpdb;
		wp_enqueue_style( 'tse-apuracao', AE_URL . 'assets/css/tse-apuracao.css', array(), AE_VERSION );
		$p = $wpdb->prefix . 'ae_';
		$q = sanitize_text_field( wp_unslash( $_GET['ae_busca'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$position = sanitize_text_field( wp_unslash( $_GET['ae_cargo'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope = strtoupper( sanitize_key( wp_unslash( $_GET['ae_uf'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$party = sanitize_text_field( wp_unslash( $_GET['ae_partido'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = sanitize_text_field( wp_unslash( $_GET['ae_candidato'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base = remove_query_arg( array( 'ae_busca', 'ae_cargo', 'ae_uf', 'ae_partido', 'ae_candidato', 'paged' ) );
		if ( $id ) { return self::profile( $id, $base ); }
		$where = array( '1=1' ); $args = array();
		if ( $q ) { $where[] = '(c.ballot_name LIKE %s OR c.full_name LIKE %s OR c.ballot_number LIKE %s)'; $like = '%' . $wpdb->esc_like( $q ) . '%'; array_push( $args, $like, $like, $like ); }
		if ( $position ) { $where[] = 'ct.position_code=%s'; $args[] = str_pad( (string) absint( $position ), 4, '0', STR_PAD_LEFT ); }
		if ( $scope ) { $where[] = 'ct.scope_code=%s'; $args[] = $scope; }
		if ( $party ) { $where[] = 'c.party=%s'; $args[] = $party; }
		$sql = "SELECT DISTINCT c.*,ct.position_name,ct.position_code,ct.scope_code FROM {$p}candidates c LEFT JOIN {$p}contests ct ON ct.id=c.contest_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY c.ballot_name ASC LIMIT 60'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$parties = $wpdb->get_col( "SELECT DISTINCT party FROM {$p}candidates WHERE party <> '' ORDER BY party" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ob_start(); ?>
		<section class="ae-catalog" aria-label="Consulta de candidatos">
			<header><p class="ae-catalog-kicker">Eleições 2026</p><h1>Conheça os candidatos</h1><p>Consulte candidatura, partido e resultados quando a apuração estiver disponível.</p></header>
			<form class="ae-catalog-filters" method="get" action="<?php echo esc_url( $base ); ?>"><input name="ae_busca" value="<?php echo esc_attr( $q ); ?>" placeholder="Nome ou número"><select name="ae_cargo"><option value="">Todos os cargos</option><?php foreach ( array( '0001'=>'Presidente','0003'=>'Governador','0005'=>'Senador','0006'=>'Deputado Federal','0007'=>'Deputado Estadual','0008'=>'Deputado Distrital' ) as $code=>$label ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $position, $code ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><input name="ae_uf" maxlength="2" value="<?php echo esc_attr( $scope ); ?>" placeholder="UF"><select name="ae_partido"><option value="">Todos os partidos</option><?php foreach ( $parties as $item ) : ?><option value="<?php echo esc_attr( $item ); ?>" <?php selected( $party, $item ); ?>><?php echo esc_html( $item ); ?></option><?php endforeach; ?></select><button>Buscar</button></form>
			<p class="ae-catalog-count"><?php echo esc_html( count( $rows ) ); ?> candidatos encontrados</p><div class="ae-catalog-grid"><?php foreach ( $rows as $candidate ) : $url = add_query_arg( 'ae_candidato', rawurlencode( $candidate['external_id'] ), $base ); ?><article class="ae-candidate-card"><?php if ( $candidate['photo_url'] ) : ?><img src="<?php echo esc_url( $candidate['photo_url'] ); ?>" alt=""><?php else : ?><div class="ae-candidate-avatar" aria-hidden="true">👤</div><?php endif; ?><div><span class="ae-candidate-number"><?php echo esc_html( $candidate['ballot_number'] ); ?></span><h2><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $candidate['ballot_name'] ?: $candidate['full_name'] ); ?></a></h2><p><?php echo esc_html( trim( $candidate['party'] . ' · ' . ( $candidate['position_name'] ?? '' ) ) ); ?></p><small><?php echo esc_html( $candidate['scope_code'] ?? '' ); ?></small></div></article><?php endforeach; ?></div><?php if ( ! $rows ) : ?><p class="ae-catalog-empty">Nenhum candidato encontrado. Importe os dados abertos do TSE ou altere os filtros.</p><?php endif; ?></section><?php return (string) ob_get_clean();
	}
	private static function profile( string $external, string $back ): string {
		global $wpdb; $p = $wpdb->prefix . 'ae_'; $row = $wpdb->get_row( $wpdb->prepare( "SELECT c.*,ct.position_name,ct.scope_code FROM {$p}candidates c LEFT JOIN {$p}contests ct ON ct.id=c.contest_id WHERE c.external_id=%s ORDER BY c.updated_at DESC LIMIT 1", $external ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) { return '<p class="ae-catalog-empty">Candidato não encontrado.</p>'; }
		$data = json_decode( (string) $row['data_json'], true ); $data = is_array( $data ) ? $data : array();
		$personal = array_filter( array( 'Ocupação' => $data['DS_OCUPACAO'] ?? '', 'Data de nascimento' => $data['DT_NASCIMENTO'] ?? '', 'Escolaridade' => $data['DS_GRAU_INSTRUCAO'] ?? '', 'Estado civil' => $data['DS_ESTADO_CIVIL'] ?? '', 'Naturalidade' => trim( ( $data['NM_MUNICIPIO_NASCIMENTO'] ?? '' ) . ' ' . ( $data['SG_UF_NASCIMENTO'] ?? '' ) ) ) );
		$application = array_filter( array( 'Nome completo' => $row['full_name'], 'Situação' => $row['situation'] ?: 'Não informada', 'Coligação' => $data['NM_COLIGACAO'] ?? '', 'Composição' => $data['DS_COMPOSICAO_COLIGACAO'] ?? '' ) );
		ob_start(); ?><article class="ae-candidate-profile"><a href="<?php echo esc_url( $back ); ?>">← Voltar aos candidatos</a><header><?php if ( $row['photo_url'] ) : ?><img src="<?php echo esc_url( $row['photo_url'] ); ?>" alt=""><?php endif; ?><div><span><?php echo esc_html( $row['ballot_number'] ); ?></span><h1><?php echo esc_html( $row['ballot_name'] ?: $row['full_name'] ); ?></h1><p><?php echo esc_html( $row['party'] ); ?> · <?php echo esc_html( $row['position_name'] ); ?> · <?php echo esc_html( $row['scope_code'] ); ?></p></div></header><h2>Dados da candidatura</h2><dl><?php foreach ( $application as $label=>$value ) : ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd><?php endforeach; ?></dl><?php if ( $personal ) : ?><h2>Dados pessoais</h2><dl><?php foreach ( $personal as $label=>$value ) : ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd><?php endforeach; ?></dl><?php endif; ?></article><?php return (string) ob_get_clean();
	}
}
