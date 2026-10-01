<?php
defined( 'ABSPATH' ) || exit;

/**
 * Vitrine de candidatos para a home: faixa com título, link para a apuração e carrossel (layout="carrossel", o padrão),
 * ou só o <ul><li> (layout="lista") para o tema montar o próprio visual. O visual do carrossel é mínimo e se ajusta
 * por variáveis CSS (--ae-strip-*); CSS e JS só são carregados quando o carrossel aparece na página.
 *
 * [apuracao_candidatos_lista cargo="presidente" uf="" limite="10" foto="sim" ids="" ordem="ranking" mostrar="" atualizar="60" layout="carrossel" titulo="" link="" link_texto="" kicker=""]
 */
final class AE_Candidate_List {
	private const DEFAULT_LIMIT = 10;
	private const MAX_LIMIT     = 100;

	public static function render( array $atts = array() ): string {
		global $wpdb;
		$a = shortcode_atts( array( 'cargo' => '', 'uf' => '', 'limite' => self::DEFAULT_LIMIT, 'foto' => 'sim', 'ids' => '', 'ordem' => 'ranking', 'mostrar' => '', 'atualizar' => 60, 'layout' => 'carrossel', 'kicker' => null, 'titulo' => 'Acompanhe por candidato', 'link' => null, 'link_texto' => 'Ver apuração' ), $atts, 'apuracao_candidatos_lista' );
		$p = $wpdb->prefix . 'ae_';

		$limit = max( 1, min( self::MAX_LIMIT, absint( $a['limite'] ) ?: self::DEFAULT_LIMIT ) );
		$photo = strtolower( sanitize_key( $a['foto'] ) );
		$photo = in_array( $photo, array( 'nao', 'somente' ), true ) ? $photo : 'sim';
		$show   = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', strtolower( (string) $a['mostrar'] ) ) ) );
		// "votos" e "percentual" mostram o percentual (o número de manchete da apuração); os dois juntos acrescentam os votos absolutos; "absoluto" mostra só os votos.
		$has_votes = in_array( 'votos', $show, true );
		$has_pct   = in_array( 'percentual', $show, true );
		$pct       = $has_votes || $has_pct;
		$votes     = ( $has_votes && $has_pct ) || in_array( 'absoluto', $show, true );
		$strip = 'lista' !== strtolower( sanitize_key( $a['layout'] ) );
		$ids   = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', explode( ',', (string) $a['ids'] ) ), 'strlen' ) ) );
		$ids   = array_map( 'trim', $ids );

		// Só o ano mais recente com candidatos importados (presidente e estado são eleições distintas do mesmo ano): a vitrine não deve misturar anos.
		$year = (int) $wpdb->get_var( "SELECT MAX(e.year) FROM {$p}elections e WHERE EXISTS (SELECT 1 FROM {$p}candidates c WHERE c.election_id=e.id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $year ) { return self::empty_notice( 'Nenhum candidato importado.' ); }

		$where = array( 'e.year=%d', 'c.removed_at IS NULL' ); $args = array( $year );
		$code  = self::position_code( (string) $a['cargo'] );
		if ( null === $code ) { return self::empty_notice( 'Cargo não reconhecido.' ); }
		$scope = strtoupper( sanitize_key( $a['uf'] ) );
		// Faixa de UMA disputa: segue o turno em andamento (AE_Rounds). Com 2º turno em curso, só entram os candidatos que o disputam.
		$single = '' !== $code && ( '' !== $scope || '0001' === $code );
		$round  = 1;
		if ( $single ) {
			$resolved = AE_Rounds::resolve( $code, '' !== $scope ? $scope : 'BR', $year );
			$round    = $resolved['round'];
		}
		if ( '' !== $code ) { $where[] = 'ct.position_code=%s'; $args[] = $code; }
		if ( '' !== $scope ) { $where[] = 'ct.scope_code=%s'; $args[] = $scope; }
		if ( 'somente' === $photo ) { $where[] = "c.photo_url <> ''"; }
		// Ordem do ranking da apuração (rank_no do último snapshot válido da disputa); sem snapshot, cai para o nome.
		$ranking = 'nome' !== strtolower( sanitize_key( $a['ordem'] ) );
		$order   = ( $ranking ? 'r.rank_no IS NULL, r.rank_no ASC, r.votes DESC, ' : '' ) . 'c.ballot_name ASC, c.id ASC';
		if ( $ids ) {
			$where[] = 'c.external_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ')';
			$args    = array_merge( $args, $ids );
			$order   = 'FIELD(c.external_id,' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . '), ' . $order;
		}
		// Turno 1 (e faixas de várias disputas): a disputa de referência do candidato. Turno seguinte: a disputa daquele turno, via ae_candidate_contests.
		$join = "LEFT JOIN {$p}contests ct ON ct.id=c.contest_id"; $cid = 'c.contest_id';
		if ( $round > 1 ) { $join = "INNER JOIN {$p}candidate_contests cc ON cc.candidate_id=c.id INNER JOIN {$p}contests ct ON ct.id=cc.contest_id AND ct.round_no={$round}"; $cid = 'ct.id'; }
		$sql = "SELECT c.external_id,c.ballot_name,c.full_name,c.ballot_number,c.party,c.photo_url,ct.position_code,ct.scope_code,ct.round_no,e.slug AS election_slug,r.votes,r.percentage,r.elected FROM {$p}candidates c INNER JOIN {$p}elections e ON e.id=c.election_id {$join} LEFT JOIN {$p}result_rows r ON r.external_candidate_id=c.external_id AND r.snapshot_id=(SELECT s.id FROM {$p}snapshots s WHERE s.contest_id={$cid} AND s.status='valid' ORDER BY s.captured_at DESC, s.id DESC LIMIT 1) WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order} LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$args = array_merge( $args, $ids ? $ids : array(), array( $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows ) { return self::empty_notice( 'Nenhum candidato encontrado para esse filtro.' ); }

		$base = self::profile_base();
		ob_start();
		if ( $strip ) {
			self::enqueue();
			$kicker = null === $a['kicker'] ? 'Eleições ' . $year : (string) $a['kicker'];
			$live   = $single ? self::live_attrs( $rows[0], $code, $scope, absint( $a['atualizar'] ), $ranking && ! $ids, $round ) : '';
			$link   = null === $a['link'] ? self::results_url() : (string) $a['link'];
			?>
<section class="ae-strip" aria-label="<?php echo esc_attr( $a['titulo'] ?: 'Candidatos' ); ?>"<?php echo $live; ?>>
	<header class="ae-strip-head">
		<?php if ( '' !== $kicker ) : ?><p class="ae-strip-kicker"><?php echo esc_html( $kicker ); ?></p><?php endif; ?>
		<?php if ( '' !== (string) $a['titulo'] ) : ?><h2 class="ae-strip-title"><?php echo esc_html( $a['titulo'] ); ?></h2><?php endif; ?>
		<?php if ( $single ) : ?><span class="ae-strip-turno"<?php echo $round > 1 ? '' : ' hidden'; ?>><?php echo esc_html( $round > 1 ? $round . 'º turno' : '' ); ?></span><?php endif; ?>
		<?php if ( '' !== $link && '' !== (string) $a['link_texto'] ) : ?><a class="ae-strip-link" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $a['link_texto'] ); ?></a><?php endif; ?>
	</header>
	<div class="ae-strip-body">
<?php
		}
		?>
<ul class="ae-candidate-list">
<?php foreach ( $rows as $row ) :
	$name  = AE_Candidate_Catalog::clean_value( $row['ballot_name'] ) ?: AE_Candidate_Catalog::clean_value( $row['full_name'] );
	$party = AE_Candidate_Catalog::clean_value( $row['party'] );
	$url   = add_query_arg( 'ae_candidato', rawurlencode( $row['external_id'] ), $base );
	?>
	<li class="ae-candidate-item" data-id="<?php echo esc_attr( $row['external_id'] ); ?>" data-numero="<?php echo esc_attr( $row['ballot_number'] ); ?>" data-partido="<?php echo esc_attr( $party ); ?>" data-cargo="<?php echo esc_attr( (string) $row['position_code'] ); ?>" data-uf="<?php echo esc_attr( (string) $row['scope_code'] ); ?>"><a class="ae-candidate-link" href="<?php echo esc_url( $url ); ?>"><?php if ( 'nao' !== $photo && $row['photo_url'] ) : ?><img class="ae-candidate-photo" src="<?php echo esc_url( $row['photo_url'] ); ?>" alt="" loading="lazy"><?php endif; ?><span class="ae-candidate-text"><span class="ae-candidate-role"><?php echo esc_html( self::position_label( (string) $row['position_code'] ) ); ?></span><span class="ae-candidate-name"><?php echo esc_html( $name ); ?></span><?php if ( $votes || $pct ) : ?><span class="ae-candidate-votes"<?php echo $votes ? ' data-votos="1"' : ''; echo $pct ? ' data-pct="1"' : ''; ?>><?php echo esc_html( self::votes_text( $row, $votes, $pct ) ); ?></span><?php endif; ?><?php if ( '' !== $party ) : ?><span class="ae-candidate-party"><?php echo esc_html( $party ); ?></span><?php endif; ?></span></a></li>
<?php endforeach; ?>
</ul>
<?php
		if ( $strip ) {
			?>
	</div>
	<div class="ae-strip-nav" hidden>
		<button type="button" class="ae-strip-prev" aria-label="Anteriores">&#8249;</button>
		<button type="button" class="ae-strip-next" aria-label="Próximos">&#8250;</button>
	</div>
</section>
<?php
		}
		return (string) ob_get_clean();
	}

	/** "48,30% · 1.234 votos"; sem resultado ainda, "0,00%" / "0 votos" (o número aparece mesmo zerado). */
	private static function votes_text( array $row, bool $votes, bool $pct ): string {
		$n     = (int) ( $row['votes'] ?? 0 );
		$parts = array();
		if ( $pct ) { $parts[] = number_format_i18n( (float) ( $row['percentage'] ?? 0 ), 2 ) . '%'; }
		if ( $votes ) { $parts[] = number_format_i18n( $n ) . ( 1 === $n ? ' voto' : ' votos' ); }
		$text = implode( ' · ', $parts );
		return ! empty( $row['elected'] ) ? $text . ' · Eleito' : $text;
	}

	/**
	 * Atributos data-* para a atualização ao vivo pela REST local (nunca o TSE). Só quando a faixa é de UMA disputa:
	 * cargo definido e (uf informada ou presidente). A URL usa o turno "auto": quando o 2º turno começar, a REST passa a
	 * devolver só os finalistas e o JS esconde os demais sem recarregar a página.
	 */
	private static function live_attrs( array $first, string $code, string $scope, int $interval, bool $reorder, int $round ): string {
		if ( $interval < 1 || '' === $code || empty( $first['election_slug'] ) ) { return ''; }
		$uf = '' !== $scope ? $scope : ( '0001' === $code ? 'BR' : '' );
		if ( '' === $uf ) { return ''; }
		$url = rest_url( 'apuracao/v1/results/' . rawurlencode( $first['election_slug'] ) . '/auto/' . $code . '/' . rawurlencode( $uf ) );
		return ' data-live="' . esc_url( $url ) . '" data-intervalo="' . max( 15, $interval ) . '" data-turno="' . $round . '"' . ( $reorder ? ' data-reordenar="1"' : '' );
	}

	/** CSS e JS do carrossel: só quando a faixa aparece (o JS vai ao rodapé). Sem JS, a faixa ainda rola com o dedo/mouse. */
	private static function enqueue(): void {
		wp_enqueue_style( 'ae-candidate-strip', AE_URL . 'assets/css/ae-candidate-strip.css', array(), AE_VERSION );
		wp_enqueue_script( 'ae-candidate-strip', AE_URL . 'assets/js/ae-candidate-strip.js', array(), AE_VERSION, true );
	}

	/** Link padrão da faixa: a página de Apuração escolhida em Configuração (a mesma do [apuracao_navegacao]); '' sem ela. */
	private static function results_url(): string {
		$page_id = (int) get_option( AE_Navigation::OPTION_RESULTS );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return $url ? $url : '';
	}

	/** "0003" → "Governador", "0006" → "Deputado Federal" (a partir de TSE_API::CARGOS). */
	private static function position_label( string $code ): string {
		$slug = array_search( (string) absint( $code ), array_map( 'strval', TSE_API::CARGOS ), true );
		return false === $slug ? 'Candidato' : ucwords( str_replace( '-', ' ', (string) $slug ) );
	}

	/** '' = todos os cargos; null = valor informado que não é um cargo. Aceita slug (presidente) ou código (1, 0001). */
	private static function position_code( string $cargo ): ?string {
		$cargo = strtolower( sanitize_title( $cargo ) );
		if ( '' === $cargo ) { return ''; }
		$codes = array_map( 'strval', TSE_API::CARGOS );
		if ( isset( $codes[ $cargo ] ) ) { return str_pad( $codes[ $cargo ], 4, '0', STR_PAD_LEFT ); }
		if ( ctype_digit( $cargo ) && in_array( (string) absint( $cargo ), $codes, true ) ) { return str_pad( (string) absint( $cargo ), 4, '0', STR_PAD_LEFT ); }
		return null;
	}

	/** Página de Candidatos definida em Apuração → Configuração; sem ela, a página atual (o catálogo lê ?ae_candidato). */
	private static function profile_base(): string {
		$page_id = (int) get_option( AE_Navigation::OPTION_CANDIDATES );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return $url ? $url : home_url( '/' );
	}

	private static function empty_notice( string $message ): string {
		return current_user_can( 'edit_posts' ) ? '<p class="ae-empty">' . esc_html( $message ) . '</p>' : '';
	}
}
