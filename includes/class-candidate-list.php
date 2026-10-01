<?php
defined( 'ABSPATH' ) || exit;

/**
 * Lista enxuta de candidatos para vitrines (home, faixas, carrosséis): só markup, sem CSS nem JS do plugin.
 * O tema cuida do título, do botão e do carrossel; o plugin entrega <ul><li> com classes estáveis.
 *
 * [apuracao_candidatos_lista cargo="presidente" uf="" limite="10" foto="sim" ids=""]
 */
final class AE_Candidate_List {
	private const DEFAULT_LIMIT = 10;
	private const MAX_LIMIT     = 100;

	public static function render( array $atts = array() ): string {
		global $wpdb;
		$a = shortcode_atts( array( 'cargo' => '', 'uf' => '', 'limite' => self::DEFAULT_LIMIT, 'foto' => 'sim', 'ids' => '' ), $atts, 'apuracao_candidatos_lista' );
		$p = $wpdb->prefix . 'ae_';

		$limit = max( 1, min( self::MAX_LIMIT, absint( $a['limite'] ) ?: self::DEFAULT_LIMIT ) );
		$photo = strtolower( sanitize_key( $a['foto'] ) );
		$photo = in_array( $photo, array( 'nao', 'somente' ), true ) ? $photo : 'sim';
		$ids   = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', explode( ',', (string) $a['ids'] ) ), 'strlen' ) ) );
		$ids   = array_map( 'trim', $ids );

		// Só o ano mais recente com candidatos importados (presidente e estado são eleições distintas do mesmo ano): a vitrine não deve misturar anos.
		$year = (int) $wpdb->get_var( "SELECT MAX(e.year) FROM {$p}elections e WHERE EXISTS (SELECT 1 FROM {$p}candidates c WHERE c.election_id=e.id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $year ) { return self::empty_notice( 'Nenhum candidato importado.' ); }

		$where = array( 'e.year=%d', 'c.removed_at IS NULL' ); $args = array( $year );
		$code  = self::position_code( (string) $a['cargo'] );
		if ( null === $code ) { return self::empty_notice( 'Cargo não reconhecido.' ); }
		if ( '' !== $code ) { $where[] = 'ct.position_code=%s'; $args[] = $code; }
		$scope = strtoupper( sanitize_key( $a['uf'] ) );
		if ( '' !== $scope ) { $where[] = 'ct.scope_code=%s'; $args[] = $scope; }
		if ( 'somente' === $photo ) { $where[] = "c.photo_url <> ''"; }
		$order = 'c.ballot_name ASC, c.id ASC';
		if ( $ids ) {
			$where[] = 'c.external_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ')';
			$args    = array_merge( $args, $ids );
			$order   = 'FIELD(c.external_id,' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . '), ' . $order;
		}
		$sql = "SELECT c.external_id,c.ballot_name,c.full_name,c.ballot_number,c.party,c.photo_url,ct.position_code,ct.scope_code FROM {$p}candidates c INNER JOIN {$p}elections e ON e.id=c.election_id LEFT JOIN {$p}contests ct ON ct.id=c.contest_id WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order} LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$args = array_merge( $args, $ids ? $ids : array(), array( $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows ) { return self::empty_notice( 'Nenhum candidato encontrado para esse filtro.' ); }

		$base = self::profile_base();
		ob_start(); ?>
<ul class="ae-candidate-list">
<?php foreach ( $rows as $row ) :
	$name  = AE_Candidate_Catalog::clean_value( $row['ballot_name'] ) ?: AE_Candidate_Catalog::clean_value( $row['full_name'] );
	$party = AE_Candidate_Catalog::clean_value( $row['party'] );
	$url   = add_query_arg( 'ae_candidato', rawurlencode( $row['external_id'] ), $base );
	?>
	<li class="ae-candidate-item" data-numero="<?php echo esc_attr( $row['ballot_number'] ); ?>" data-partido="<?php echo esc_attr( $party ); ?>" data-cargo="<?php echo esc_attr( (string) $row['position_code'] ); ?>" data-uf="<?php echo esc_attr( (string) $row['scope_code'] ); ?>"><a class="ae-candidate-link" href="<?php echo esc_url( $url ); ?>"><?php if ( 'nao' !== $photo && $row['photo_url'] ) : ?><img class="ae-candidate-photo" src="<?php echo esc_url( $row['photo_url'] ); ?>" alt="" loading="lazy"><?php endif; ?><span class="ae-candidate-name"><?php echo esc_html( $name ); ?></span></a></li>
<?php endforeach; ?>
</ul>
<?php
		return (string) ob_get_clean();
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
