<?php
defined( 'ABSPATH' ) || exit;

final class AE_REST {
	private static ?AE_REST $instance = null;
	public static function instance(): AE_REST { return self::$instance ??= new self(); }

	public function register_routes(): void {
		register_rest_route( 'apuracao/v1', '/results/(?P<election>[a-z0-9-]+)/(?P<round>\d+)/(?P<position>[A-Za-z0-9_-]+)/(?P<scope>[A-Za-z0-9_-]+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'results' ), 'permission_callback' => '__return_true', 'args' => array( 'round' => array( 'validate_callback' => static fn( $v ) => absint( $v ) > 0 ) ) ) );
		register_rest_route( 'apuracao/v1', '/candidates/(?P<election>[a-z0-9-]+)/(?P<candidate>[A-Za-z0-9_-]+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'candidate' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'apuracao/v1', '/admin/jobs', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'enqueue' ), 'permission_callback' => static fn() => current_user_can( 'manage_options' ) ) );
		register_rest_route( 'apuracao/v1', '/admin/elections', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save_election' ), 'permission_callback' => static fn() => current_user_can( 'manage_options' ) ) );
		register_rest_route( 'apuracao/v1', '/admin/contests', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save_contest' ), 'permission_callback' => static fn() => current_user_can( 'manage_options' ) ) );
		register_rest_route( 'apuracao/v1', '/admin/health', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'health' ), 'permission_callback' => static fn() => current_user_can( 'manage_options' ) ) );
	}

	public function results( WP_REST_Request $request ): WP_REST_Response {
		$data = AE_Results::instance()->latest( sanitize_title( $request['election'] ), absint( $request['round'] ), sanitize_key( $request['position'] ), sanitize_key( $request['scope'] ) );
		if ( null === $data ) { return new WP_REST_Response( array( 'code' => 'ae_contest_not_found', 'message' => 'Disputa nao encontrada.' ), 404 ); }
		// Sinalizador explicito para consumidores externos nao precisarem interpretar o texto livre de 'situation'.
		$data['candidates'] = array_map( static function ( array $candidate ): array {
			$candidate['segundo_turno'] = false !== mb_stripos( (string) ( $candidate['situation'] ?? '' ), 'turno' );
			return $candidate;
		}, $data['candidates'] );
		return $this->cached_response( $request, $data, $data['snapshot']['captured_at'] ?? null );
	}

	public function candidate( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb; $p = $wpdb->prefix . 'ae_';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT c.id,c.external_id,c.ballot_name,c.full_name,c.ballot_number,c.party,c.situation,c.photo_url FROM {$p}candidates c INNER JOIN {$p}elections e ON e.id=c.election_id WHERE e.slug=%s AND c.external_id=%s", sanitize_title( $request['election'] ), sanitize_text_field( $request['candidate'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) { return new WP_REST_Response( array( 'code' => 'ae_candidate_not_found', 'message' => 'Candidato nao encontrado.' ), 404 ); }
		return $this->cached_response( $request, $row, null );
	}

	public function enqueue( WP_REST_Request $request ): WP_REST_Response {
		$type = sanitize_key( $request->get_param( 'type' ) );
		if ( ! in_array( $type, array( 'import_candidates', 'collect_results' ), true ) ) { return new WP_REST_Response( array( 'message' => 'Tipo de job invalido.' ), 400 ); }
		$id = AE_Job_Runner::enqueue( $type, (array) $request->get_param( 'payload' ) );
		return new WP_REST_Response( array( 'job_id' => $id, 'state' => 'queued' ), 202 );
	}

	/** Creates or updates an election; configuration remains data-driven, never postmeta. */
	public function save_election( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb; $table = $wpdb->prefix . 'ae_elections'; $id = absint( $request->get_param( 'id' ) );
		$data = array( 'slug' => sanitize_title( (string) $request->get_param( 'slug' ) ), 'name' => sanitize_text_field( (string) $request->get_param( 'name' ) ), 'year' => absint( $request->get_param( 'year' ) ), 'timezone' => sanitize_text_field( (string) ( $request->get_param( 'timezone' ) ?: 'America/Sao_Paulo' ) ), 'status' => in_array( $request->get_param( 'status' ), array( 'draft', 'active', 'archived' ), true ) ? $request->get_param( 'status' ) : 'draft', 'config_json' => wp_json_encode( (array) $request->get_param( 'config' ) ), 'updated_at' => current_time( 'mysql', true ) );
		if ( ! $data['slug'] || ! $data['name'] || ! $data['year'] ) { return new WP_REST_Response( array( 'message' => 'slug, name e year sao obrigatorios.' ), 400 ); }
		if ( $id ) { $ok = $wpdb->update( $table, $data, array( 'id' => $id ) ); } else { $data['created_at'] = $data['updated_at']; $ok = $wpdb->insert( $table, $data ); $id = (int) $wpdb->insert_id; }
		return false === $ok ? new WP_REST_Response( array( 'message' => 'Nao foi possivel salvar a eleicao.' ), 500 ) : new WP_REST_Response( array( 'id' => $id ), 201 );
	}

	/** Creates or updates a turn/cargo/abrangencia configuration. */
	public function save_contest( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb; $table = $wpdb->prefix . 'ae_contests'; $id = absint( $request->get_param( 'id' ) );
		$active = $request->get_param( 'active' );
		$data = array( 'election_id' => absint( $request->get_param( 'election_id' ) ), 'external_id' => sanitize_text_field( (string) $request->get_param( 'external_id' ) ), 'round_no' => max( 1, absint( $request->get_param( 'round_no' ) ) ), 'position_code' => sanitize_key( (string) $request->get_param( 'position_code' ) ), 'position_name' => sanitize_text_field( (string) $request->get_param( 'position_name' ) ), 'scope_type' => sanitize_key( (string) $request->get_param( 'scope_type' ) ), 'scope_code' => strtoupper( sanitize_key( (string) $request->get_param( 'scope_code' ) ) ), 'scope_name' => sanitize_text_field( (string) $request->get_param( 'scope_name' ) ), 'seats' => max( 1, absint( $request->get_param( 'seats' ) ) ), 'active' => null === $active || rest_sanitize_boolean( $active ) ? 1 : 0, 'config_json' => wp_json_encode( (array) $request->get_param( 'config' ) ) );
		foreach ( array( 'election_id', 'external_id', 'position_code', 'position_name', 'scope_type', 'scope_code', 'scope_name' ) as $required ) { if ( empty( $data[ $required ] ) ) { return new WP_REST_Response( array( 'message' => $required . ' e obrigatorio.' ), 400 ); } }
		if ( $id ) { $ok = $wpdb->update( $table, $data, array( 'id' => $id ) ); } else { $ok = $wpdb->insert( $table, $data ); $id = (int) $wpdb->insert_id; }
		return false === $ok ? new WP_REST_Response( array( 'message' => 'Nao foi possivel salvar a disputa.' ), 500 ) : new WP_REST_Response( array( 'id' => $id ), 201 );
	}

	public function health(): WP_REST_Response {
		global $wpdb; $p = $wpdb->prefix . 'ae_';
		return new WP_REST_Response( array( 'schema' => get_option( 'ae_schema_version' ), 'cron_next' => wp_next_scheduled( 'ae_run_jobs' ), 'jobs' => array( 'queued' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}jobs WHERE state IN ('queued','retry')" ), 'failed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}jobs WHERE state='failed'" ) ), 'latest_snapshot' => $wpdb->get_var( "SELECT MAX(captured_at) FROM {$p}snapshots WHERE status='valid'" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function cached_response( WP_REST_Request $request, array $data, ?string $modified ): WP_REST_Response {
		$etag = '"' . hash( 'sha256', wp_json_encode( $data ) ) . '"';
		if ( trim( (string) $request->get_header( 'if-none-match' ) ) === $etag ) { return new WP_REST_Response( null, 304, array( 'ETag' => $etag ) ); }
		$headers = array( 'ETag' => $etag, 'Cache-Control' => 'public, max-age=30, s-maxage=60, stale-while-revalidate=300, stale-if-error=3600', 'Vary' => 'Accept-Encoding' );
		if ( $modified ) { $headers['Last-Modified'] = gmdate( 'D, d M Y H:i:s', strtotime( $modified ) ) . ' GMT'; }
		return new WP_REST_Response( $data, 200, $headers );
	}
}
