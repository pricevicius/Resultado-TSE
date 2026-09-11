<?php
defined( 'ABSPATH' ) || exit;

/** Transport and normalization boundary. No visitor request invokes this class. */
final class AE_TSE_Client {
	private static ?AE_TSE_Client $instance = null;
	public static function instance(): AE_TSE_Client { return self::$instance ??= new self(); }

	public function import_candidates_page( array $payload, array $cursor, int $job_id ): array {
		$url = esc_url_raw( $payload['source_url'] ?? '' ); $election_id = absint( $payload['election_id'] ?? 0 );
		if ( ! $election_id || ! $this->allowed_url( $url ) ) { throw new RuntimeException( 'Fonte de candidatos invalida.' ); }
		$offset = absint( $cursor['offset'] ?? 0 );
		$format = strtolower( sanitize_key( $payload['format'] ?? pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ) );
		if ( in_array( $format, array( 'csv', 'zip' ), true ) ) {
			$batch_data = $this->read_open_data_batch( $url, $format, $offset, $job_id );
			$batch = $batch_data['rows']; $complete = $batch_data['complete'];
		} else {
			$response = $this->get_json( $url ); $items = $response['candidatos'] ?? $response['items'] ?? $response;
			if ( ! is_array( $items ) ) { throw new RuntimeException( 'Formato de candidatos nao reconhecido.' ); }
			$batch = array_slice( $items, $offset, 250 ); $complete = $offset + count( $batch ) >= count( $items );
		}
		global $wpdb; $table = $wpdb->prefix . 'ae_candidates'; $now = current_time( 'mysql', true );
		foreach ( $batch as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$external = sanitize_text_field( (string) ( $row['id'] ?? $row['sq_CANDIDATO'] ?? '' ) );
			if ( '' === $external ) { continue; }
			$data = array( 'election_id' => $election_id, 'external_id' => $external, 'contest_id' => absint( $row['contest_id'] ?? 0 ) ?: null, 'ballot_name' => sanitize_text_field( $row['nm_URNA_CANDIDATO'] ?? $row['nomeUrna'] ?? '' ), 'full_name' => sanitize_text_field( $row['nm_CANDIDATO'] ?? $row['nomeCompleto'] ?? '' ), 'ballot_number' => sanitize_text_field( (string) ( $row['nr_CANDIDATO'] ?? $row['numero'] ?? '' ) ), 'party' => sanitize_text_field( $row['sg_PARTIDO'] ?? $row['partido'] ?? '' ), 'situation' => sanitize_text_field( $row['ds_SITUACAO_CANDIDATURA'] ?? $row['situacao'] ?? '' ), 'photo_url' => esc_url_raw( $row['urlFoto'] ?? '' ), 'data_json' => wp_json_encode( $row ), 'updated_at' => $now );
			$wpdb->replace( $table, $data, array( '%d','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s' ) );
		}
		AE_Logger::write( 'info', 'candidate_import_page', array( 'job_id' => $job_id, 'offset' => $offset, 'count' => count( $batch ) ) );
		$next = $offset + count( $batch );
		if ( $complete ) { $this->cleanup_import_file( $job_id ); }
		return array( 'complete' => $complete, 'cursor' => array( 'offset' => $next ) );
	}

	public function collect_results( array $payload ): array {
		$contest_id = absint( $payload['contest_id'] ?? 0 ); $url = esc_url_raw( $payload['source_url'] ?? '' ); $kind = strtoupper( sanitize_key( $payload['kind'] ?? 'EA20' ) );
		if ( ! $contest_id || ! in_array( $kind, array( 'EA14', 'EA15', 'EA20' ), true ) || ! $this->allowed_url( $url ) ) { throw new RuntimeException( 'Coleta TSE invalida.' ); }
		$raw = $this->get_json( $url ); $normalized = $this->normalize_result( $raw, $kind );
		if ( empty( $normalized['candidates'] ) || ! isset( $normalized['totals']['total_votes'] ) ) { throw new RuntimeException( 'Snapshot rejeitado: dados essenciais ausentes.' ); }
		global $wpdb; $p = $wpdb->prefix . 'ae_'; $now = current_time( 'mysql', true );
		$sha = hash( 'sha256', wp_json_encode( $raw ) );
		$sequence = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sequence_no),0)+1 FROM {$p}snapshots WHERE contest_id=%d", $contest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert( $p . 'snapshots', array( 'contest_id' => $contest_id, 'source' => $kind, 'source_url' => $url, 'source_sha256' => $sha, 'captured_at' => $now, 'generated_at' => $normalized['generated_at'], 'sequence_no' => $sequence, 'status' => 'valid', 'totals_json' => wp_json_encode( $normalized['totals'] ), 'raw_json' => wp_json_encode( $raw ), 'valid_until' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( '%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s' ) );
		$snapshot_id = (int) $wpdb->insert_id;
		foreach ( $normalized['candidates'] as $candidate ) {
			$candidate_id = $wpdb->get_var( $wpdb->prepare( "SELECT ca.id FROM {$p}candidates ca INNER JOIN {$p}contests co ON co.election_id=ca.election_id WHERE co.id=%d AND ca.external_id=%s LIMIT 1", $contest_id, $candidate['external_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->insert( $p . 'result_rows', array( 'snapshot_id' => $snapshot_id, 'candidate_id' => $candidate_id ? (int) $candidate_id : null, 'external_candidate_id' => $candidate['external_id'], 'rank_no' => $candidate['rank'], 'votes' => $candidate['votes'], 'percentage' => $candidate['percentage'], 'elected' => $candidate['elected'], 'situation' => $candidate['situation'] ), array( '%d','%d','%s','%d','%d','%f','%d','%s' ) );
		}
		AE_Results::instance()->invalidate( $snapshot_id );
		AE_Logger::write( 'info', 'snapshot_valid', array( 'contest_id' => $contest_id, 'snapshot_id' => $snapshot_id, 'sha256' => $sha ) );
		return array( 'complete' => true );
	}

	private function normalize_result( array $raw, string $kind ): array {
		// EA14/EA15/EA20 vary by election. Keep field aliases here, covered by fixtures before each simulacao.
		$source = $raw['cand'] ?? $raw['candidatos'] ?? $raw['candidates'] ?? array();
		$candidates = array();
		foreach ( $source as $index => $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$id = (string) ( $item['sqcand'] ?? $item['id'] ?? $item['sequencial'] ?? '' );
			if ( '' === $id ) { continue; }
			$candidates[] = array( 'external_id' => $id, 'rank' => absint( $item['seq'] ?? $item['posicao'] ?? ( $index + 1 ) ), 'votes' => (int) preg_replace( '/\D/', '', (string) ( $item['vap'] ?? $item['votos'] ?? 0 ) ), 'percentage' => (float) str_replace( ',', '.', (string) ( $item['pvap'] ?? $item['percentual'] ?? 0 ) ), 'elected' => in_array( strtoupper( (string) ( $item['st'] ?? $item['situacao'] ?? '' ) ), array( 'ELEITO', 'ELEITO POR QP', 'ELEITO POR MEDIA' ), true ) ? 1 : 0, 'situation' => sanitize_text_field( $item['st'] ?? $item['situacao'] ?? '' ) );
		}
		$total = (int) preg_replace( '/\D/', '', (string) ( $raw['total'] ?? $raw['tot']['tv'] ?? $raw['totalVotos'] ?? 0 ) );
		return array( 'generated_at' => $this->date_or_null( $raw['dt'] ?? $raw['data'] ?? null ), 'totals' => array( 'total_votes' => $total, 'reported_sections' => absint( $raw['pst'] ?? $raw['secoesTotalizadas'] ?? 0 ), 'total_sections' => absint( $raw['tse'] ?? $raw['totalSecoes'] ?? 0 ), 'source_format' => $kind ), 'candidates' => $candidates );
	}

	private function get_json( string $url ): array {
		$response = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 2, 'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'WordPress Apuracao Eleitoral/' . AE_VERSION ) ) );
		if ( is_wp_error( $response ) ) { throw new RuntimeException( $response->get_error_message() ); }
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) { throw new RuntimeException( 'TSE respondeu HTTP ' . wp_remote_retrieve_response_code( $response ) ); }
		try { return json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR ); } catch ( JsonException $e ) { throw new RuntimeException( 'JSON TSE invalido.' ); }
	}

	/** Reads only the requested page from a staged CSV/ZIP, so a cron retry resumes by line. */
	private function read_open_data_batch( string $url, string $format, int $offset, int $job_id ): array {
		$path = get_transient( 'ae_import_file_' . $job_id );
		if ( ! $path || ! is_readable( $path ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$path = download_url( $url, 60 );
			if ( is_wp_error( $path ) ) { throw new RuntimeException( $path->get_error_message() ); }
			set_transient( 'ae_import_file_' . $job_id, $path, DAY_IN_SECONDS );
		}
		$stream = null; $zip = null;
		if ( 'zip' === $format ) {
			if ( ! class_exists( 'ZipArchive' ) ) { throw new RuntimeException( 'Extensao ZipArchive indisponivel.' ); }
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path ) ) { throw new RuntimeException( 'Arquivo ZIP TSE invalido.' ); }
			for ( $i = 0; $i < $zip->numFiles; $i++ ) { $name = $zip->getNameIndex( $i ); if ( str_ends_with( strtolower( $name ), '.csv' ) ) { $stream = $zip->getStream( $name ); break; } }
			if ( ! is_resource( $stream ) ) { $zip->close(); throw new RuntimeException( 'ZIP sem CSV de candidatos.' ); }
		} else { $stream = fopen( $path, 'rb' ); }
		if ( ! is_resource( $stream ) ) { throw new RuntimeException( 'Nao foi possivel abrir dados abertos.' ); }
		$header = fgetcsv( $stream, 0, ';' );
		if ( ! is_array( $header ) || ! $header ) { fclose( $stream ); if ( $zip ) { $zip->close(); } throw new RuntimeException( 'CSV sem cabecalho.' ); }
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		for ( $skip = 0; $skip < $offset && false !== fgetcsv( $stream, 0, ';' ); $skip++ ) { }
		$rows = array();
		while ( count( $rows ) < 251 && false !== ( $line = fgetcsv( $stream, 0, ';' ) ) ) {
			if ( count( $line ) === count( $header ) ) { $rows[] = array_combine( $header, $line ); }
		}
		$complete = count( $rows ) <= 250;
		if ( ! $complete ) { array_pop( $rows ); }
		fclose( $stream ); if ( $zip ) { $zip->close(); }
		return array( 'rows' => $rows, 'complete' => $complete );
	}

	private function cleanup_import_file( int $job_id ): void {
		$key = 'ae_import_file_' . $job_id; $path = get_transient( $key );
		if ( $path && is_file( $path ) ) { wp_delete_file( $path ); }
		delete_transient( $key );
	}

	private function allowed_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && ( 'tse.jus.br' === $host || str_ends_with( $host, '.tse.jus.br' ) );
	}
	private function date_or_null( mixed $value ): ?string { $time = strtotime( (string) $value ); return $time ? gmdate( 'Y-m-d H:i:s', $time ) : null; }
}
