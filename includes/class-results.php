<?php
defined( 'ABSPATH' ) || exit;

final class AE_Results {
	private static ?AE_Results $instance = null;
	public static function instance(): AE_Results { return self::$instance ??= new self(); }

	public function latest( string $election_slug, int $round, string $position, string $scope ): ?array {
		global $wpdb; $p = $wpdb->prefix . 'ae_';
		$contest = $wpdb->get_row( $wpdb->prepare( "SELECT c.* FROM {$p}contests c INNER JOIN {$p}elections e ON e.id=c.election_id WHERE e.slug=%s AND c.round_no=%d AND c.position_code=%s AND c.scope_code=%s AND c.active=1 LIMIT 1", $election_slug, $round, $position, strtoupper( $scope ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $contest ) { return null; }
		$snapshot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}snapshots WHERE contest_id=%d AND status='valid' ORDER BY captured_at DESC, id DESC LIMIT 1", $contest->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $snapshot ) { return array( 'contest' => $this->contest_meta( $contest ), 'snapshot' => null, 'candidates' => array() ); }
		$key = 'snapshot:' . $snapshot->id;
		$data = wp_cache_get( $key, 'apuracao-eleitoral' );
		if ( false === $data ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.rank_no,r.external_candidate_id,r.votes,r.percentage,r.elected,r.situation,c.ballot_name,c.full_name,c.ballot_number,c.party,c.photo_url FROM {$p}result_rows r LEFT JOIN {$p}candidates c ON c.id=r.candidate_id WHERE r.snapshot_id=%d ORDER BY r.rank_no ASC, r.votes DESC", $snapshot->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$data = array( 'contest' => $this->contest_meta( $contest ), 'snapshot' => array( 'id' => (int) $snapshot->id, 'captured_at' => gmdate( DATE_RFC3339, strtotime( $snapshot->captured_at . ' UTC' ) ), 'generated_at' => $snapshot->generated_at ? gmdate( DATE_RFC3339, strtotime( $snapshot->generated_at . ' UTC' ) ) : null, 'totals' => json_decode( $snapshot->totals_json, true ) ), 'candidates' => $rows );
			wp_cache_set( $key, $data, 'apuracao-eleitoral', HOUR_IN_SECONDS );
		}
		return $data;
	}

	public function invalidate( int $snapshot_id ): void { wp_cache_delete( 'snapshot:' . $snapshot_id, 'apuracao-eleitoral' ); }
	private function contest_meta( object $c ): array { return array( 'id' => (int) $c->id, 'round' => (int) $c->round_no, 'position' => $c->position_name, 'scope' => array( 'type' => $c->scope_type, 'code' => $c->scope_code, 'name' => $c->scope_name ), 'seats' => (int) $c->seats ); }
}
