<?php
defined( 'ABSPATH' ) || exit;

/** A missing source is local to one contest and must not pause all collections. */
final class AE_TSE_Source_Not_Found extends RuntimeException {}

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
			$batch_data = $this->read_open_data_batch( $url, $format, $cursor, $job_id );
			$batch = $batch_data['rows']; $complete = $batch_data['complete'];
		} else {
			$response = $this->get_json( $url ); $items = $response['candidatos'] ?? $response['items'] ?? $response;
			if ( ! is_array( $items ) ) { throw new RuntimeException( 'Formato de candidatos nao reconhecido.' ); }
			$batch = array_slice( $items, $offset, 250 ); $complete = $offset + count( $batch ) >= count( $items );
		}
		global $wpdb; $table = $wpdb->prefix . 'ae_candidates'; $now = current_time( 'mysql', true );
		foreach ( $batch as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$external = sanitize_text_field( (string) ( $row['id'] ?? $row['SQ_CANDIDATO'] ?? $row['sq_CANDIDATO'] ?? '' ) );
			if ( '' === $external ) { continue; }
			$data = array( 'election_id' => $election_id, 'external_id' => $external, 'contest_id' => absint( $row['contest_id'] ?? 0 ) ?: null, 'ballot_name' => sanitize_text_field( $row['NM_URNA_CANDIDATO'] ?? $row['nm_URNA_CANDIDATO'] ?? $row['nomeUrna'] ?? '' ), 'full_name' => sanitize_text_field( $row['NM_CANDIDATO'] ?? $row['nm_CANDIDATO'] ?? $row['nomeCompleto'] ?? '' ), 'ballot_number' => sanitize_text_field( (string) ( $row['NR_CANDIDATO'] ?? $row['nr_CANDIDATO'] ?? $row['numero'] ?? '' ) ), 'party' => sanitize_text_field( $row['SG_PARTIDO'] ?? $row['sg_PARTIDO'] ?? $row['partido'] ?? '' ), 'situation' => sanitize_text_field( $row['DS_SITUACAO_CANDIDATURA'] ?? $row['ds_SITUACAO_CANDIDATURA'] ?? $row['situacao'] ?? '' ), 'photo_url' => esc_url_raw( $row['urlFoto'] ?? '' ), 'data_json' => wp_json_encode( $row ), 'updated_at' => $now );
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE election_id=%d AND external_id=%s", $election_id, $external ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $existing ) { unset( $data['election_id'], $data['external_id'] ); $wpdb->update( $table, $data, array( 'id' => (int) $existing ) ); }
			else { $wpdb->insert( $table, $data ); }
		}
		AE_Logger::write( 'info', 'candidate_import_page', array( 'job_id' => $job_id, 'offset' => $offset, 'count' => count( $batch ) ) );
		$next_cursor = $batch_data['cursor'] ?? array( 'offset' => $offset + count( $batch ) );
		if ( $complete ) { $this->cleanup_import_file( $job_id ); }
		return array( 'complete' => $complete, 'cursor' => $next_cursor );
	}

	public function collect_results( array $payload ): array {
		$contest_id = absint( $payload['contest_id'] ?? 0 ); $url = esc_url_raw( $payload['source_url'] ?? '' ); $kind = strtoupper( sanitize_key( $payload['kind'] ?? 'EA20' ) );
		if ( ! $contest_id || ! in_array( $kind, array( 'EA14', 'EA15', 'EA20' ), true ) || ! $this->allowed_url( $url ) ) { throw new RuntimeException( 'Coleta TSE invalida.' ); }
		if ( ! $this->result_source_is_current( $contest_id, $url ) ) {
			AE_Logger::write( 'info', 'stale_result_job_ignored', array( 'contest_id' => $contest_id, 'source_url' => $url ) );
			return array( 'complete' => true, 'source_ignored' => true );
		}
		try {
			$raw = $this->fetch_json( $url, true );
		} catch ( AE_TSE_Source_Not_Found $e ) {
			$this->disable_missing_result_source( $contest_id, $url );
			return array( 'complete' => true, 'source_disabled' => true );
		}
		if ( null === $raw ) { return array( 'complete' => true, 'unchanged' => true ); }
		$normalized = $this->normalize_result( $raw, $kind );
		if ( ! $this->is_valid_result( $raw, $normalized, $kind ) ) { throw new RuntimeException( 'Snapshot rejeitado: estrutura essencial do TSE ausente.' ); }
		global $wpdb; $p = $wpdb->prefix . 'ae_'; $now = current_time( 'mysql', true );
		$sha = hash( 'sha256', wp_json_encode( $raw ) );
		$previous = $wpdb->get_var( $wpdb->prepare( "SELECT source_sha256 FROM {$p}snapshots WHERE contest_id=%d AND status='valid' ORDER BY id DESC LIMIT 1", $contest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( hash_equals( (string) $previous, $sha ) ) { return array( 'complete' => true, 'unchanged' => true ); }
		$sequence = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sequence_no),0)+1 FROM {$p}snapshots WHERE contest_id=%d", $contest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert( $p . 'snapshots', array( 'contest_id' => $contest_id, 'source' => $kind, 'source_url' => $url, 'source_sha256' => $sha, 'captured_at' => $now, 'generated_at' => $normalized['generated_at'], 'sequence_no' => $sequence, 'status' => 'valid', 'totals_json' => wp_json_encode( $normalized['totals'] ), 'raw_json' => wp_json_encode( $raw ), 'valid_until' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( '%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s' ) );
		$snapshot_id = (int) $wpdb->insert_id;
		foreach ( $normalized['candidates'] as $candidate ) {
			$candidate_id = $this->upsert_result_candidate( $contest_id, $candidate, $url );
			$wpdb->insert( $p . 'result_rows', array( 'snapshot_id' => $snapshot_id, 'candidate_id' => $candidate_id ? (int) $candidate_id : null, 'external_candidate_id' => $candidate['external_id'], 'rank_no' => $candidate['rank'], 'votes' => $candidate['votes'], 'percentage' => $candidate['percentage'], 'elected' => $candidate['elected'], 'situation' => $candidate['situation'] ), array( '%d','%d','%s','%d','%d','%f','%d','%s' ) );
		}
		AE_Results::instance()->invalidate( $snapshot_id );
		AE_Logger::write( 'info', 'snapshot_valid', array( 'contest_id' => $contest_id, 'snapshot_id' => $snapshot_id, 'sha256' => $sha ) );
		return array( 'complete' => true );
	}

	private function normalize_result( array $raw, string $kind ): array {
		$source = $this->candidate_rows( $raw );
		$candidates = array();
		foreach ( $source as $index => $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$id = (string) ( $item['sqcand'] ?? $item['id'] ?? $item['sequencial'] ?? '' );
			if ( '' === $id ) { continue; }
			$status = sanitize_text_field( $item['st'] ?? $item['situacao'] ?? '' );
			// O TSE usa cand.e=s tambem para quem apenas avanca ao 2º turno; so ha eleito de fato apos a totalizacao do turno.
			$runoff = false !== mb_stripos( $status, 'turno' );
			$elected = $runoff ? 0 : ( array_key_exists( 'e', $item ) ? ( 's' === strtolower( (string) $item['e'] ) ? 1 : 0 ) : ( str_starts_with( strtoupper( $status ), 'ELEITO' ) ? 1 : 0 ) );
			$candidates[] = array( 'external_id' => $id, 'rank' => absint( $item['seq'] ?? $item['posicao'] ?? ( $index + 1 ) ), 'votes' => $this->integer( $item['vap'] ?? $item['votos'] ?? 0 ), 'percentage' => $this->decimal( $item['pvap'] ?? $item['percentual'] ?? 0 ), 'elected' => $elected, 'situation' => $status, 'ballot_name' => sanitize_text_field( $item['nmu'] ?? $item['nomeUrna'] ?? '' ), 'full_name' => sanitize_text_field( $item['nm'] ?? $item['nomeCompleto'] ?? '' ), 'ballot_number' => sanitize_text_field( (string) ( $item['n'] ?? $item['numero'] ?? '' ) ), 'party' => sanitize_text_field( $item['_party'] ?? $item['partido'] ?? '' ) );
		}
		$sections = is_array( $raw['s'] ?? null ) ? $raw['s'] : array();
		$votes = is_array( $raw['v'] ?? null ) ? $raw['v'] : ( is_array( $raw['tot'] ?? null ) ? $raw['tot'] : array() );
		$progress = strtolower( (string) ( $raw['and'] ?? '' ) );
		return array( 'generated_at' => $this->date_or_null( trim( (string) ( $raw['dg'] ?? $raw['dt'] ?? $raw['data'] ?? '' ) . ' ' . (string) ( $raw['hg'] ?? '' ) ) ), 'totals' => array( 'total_votes' => $this->integer( $raw['total'] ?? $votes['tv'] ?? $raw['totalVotos'] ?? 0 ), 'reported_sections' => $this->integer( $sections['st'] ?? $raw['secoesTotalizadas'] ?? 0 ), 'total_sections' => $this->integer( $sections['ts'] ?? $raw['totalSecoes'] ?? 0 ), 'reported_percentage' => $this->decimal( $sections['pst'] ?? $raw['pst'] ?? 0 ),
			// TSE distingue votos validos, brancos, nulos e anulados (van); sem isto o total nunca fecha com a soma dos candidatos.
			'valid_votes' => $this->integer( $votes['vv'] ?? 0 ), 'blank_votes' => $this->integer( $votes['vb'] ?? 0 ), 'blank_percentage' => $this->decimal( $votes['pvb'] ?? 0 ), 'null_votes' => $this->integer( $votes['vn'] ?? 0 ), 'null_percentage' => $this->decimal( $votes['pvn'] ?? 0 ), 'annulled_votes' => $this->integer( $votes['van'] ?? 0 ), 'annulled_percentage' => $this->decimal( $votes['pvan'] ?? 0 ),
			'progress' => array( 'n' => 'not_started', 'p' => 'partial', 'f' => 'final' )[ $progress ] ?? 'unknown', 'final' => 'f' === $progress || 's' === strtolower( (string) ( $raw['tf'] ?? '' ) ), 'mathematically_defined' => sanitize_key( $raw['md'] ?? 'n' ), 'source_format' => $kind, 'tse_generation_id' => sanitize_text_field( (string) ( $raw['idg'] ?? '' ) ) ), 'candidates' => $candidates );
	}

	/** @return array<string,mixed>|null Null means HTTP 304/not modified. */
	public function fetch_json( string $url, bool $conditional = true ): ?array {
		if ( ! $this->allowed_url( $url ) ) { throw new RuntimeException( 'URL fora dos domínios oficiais do TSE.' ); }
		$blocked_until = absint( get_option( 'ae_tse_blocked_until', 0 ) );
		if ( $blocked_until > time() ) { throw new RuntimeException( 'Coleta pausada preventivamente até ' . gmdate( 'H:i:s', $blocked_until ) . ' UTC.' ); }
		$this->throttle();
		$key = 'ae_tse_http_' . md5( $url );
		$state = $conditional ? get_option( $key, array() ) : array();
		$headers = array( 'Accept' => 'application/json', 'User-Agent' => 'WordPress Apuracao Eleitoral/' . AE_VERSION );
		if ( ! empty( $state['etag'] ) ) { $headers['If-None-Match'] = $state['etag']; }
		if ( ! empty( $state['last_modified'] ) ) { $headers['If-Modified-Since'] = $state['last_modified']; }
		$response = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 2, 'headers' => $headers ) );
		if ( is_wp_error( $response ) ) { throw new RuntimeException( $response->get_error_message() ); }
		$code = wp_remote_retrieve_response_code( $response );
		if ( 304 === $code ) { return null; }
		if ( 404 === $code ) { throw new AE_TSE_Source_Not_Found( 'O TSE não publicou este arquivo de resultado.' ); }
		if ( in_array( $code, array( 403, 429 ), true ) ) { update_option( 'ae_tse_blocked_until', time() + 10 * MINUTE_IN_SECONDS, false ); }
		if ( 200 !== $code ) { throw new RuntimeException( 'TSE respondeu HTTP ' . $code . '; novas tentativas foram desaceleradas.' ); }
		update_option( $key, array( 'etag' => wp_remote_retrieve_header( $response, 'etag' ), 'last_modified' => wp_remote_retrieve_header( $response, 'last-modified' ) ), false );
		try { $data = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR ); return is_array( $data ) ? $data : throw new RuntimeException( 'JSON TSE sem objeto raiz.' ); } catch ( JsonException $e ) { throw new RuntimeException( 'JSON TSE inválido.' ); }
	}

	private function get_json( string $url ): array { return $this->fetch_json( $url, false ) ?? array(); }

	/** Ignore queued work after a source is disabled or replaced by a newer EA11 sync. */
	private function result_source_is_current( int $contest_id, string $url ): bool {
		global $wpdb;
		$config_json = $wpdb->get_var( $wpdb->prepare( "SELECT config_json FROM {$wpdb->prefix}ae_contests WHERE id=%d AND active=1", $contest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $config_json ) { return false; }
		$config = json_decode( (string) $config_json, true );
		$collection = is_array( $config ) && is_array( $config['collection'] ?? null ) ? $config['collection'] : array();
		if ( ! $collection ) { return true; }
		return ! empty( $collection['enabled'] ) && esc_url_raw( (string) ( $collection['source_url'] ?? '' ) ) === $url;
	}

	/** Prevent one bad generated URL from re-entering the queue every minute. */
	private function disable_missing_result_source( int $contest_id, string $url ): void {
		global $wpdb;
		$p = $wpdb->prefix . 'ae_';
		$config_json = $wpdb->get_var( $wpdb->prepare( "SELECT config_json FROM {$p}contests WHERE id=%d", $contest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$config = json_decode( (string) $config_json, true );
		if ( ! is_array( $config ) ) { $config = array(); }
		$config['collection'] = is_array( $config['collection'] ?? null ) ? $config['collection'] : array();
		if ( esc_url_raw( (string) ( $config['collection']['source_url'] ?? '' ) ) !== $url ) {
			AE_Logger::write( 'info', 'stale_404_ignored', array( 'contest_id' => $contest_id, 'source_url' => $url ) );
			return;
		}
		$config['collection']['enabled'] = false;
		$config['collection']['disabled_reason'] = 'O TSE não publicou este arquivo (HTTP 404).';
		$wpdb->update( $p . 'contests', array( 'config_json' => wp_json_encode( $config ) ), array( 'id' => $contest_id ) );
		AE_Logger::write( 'warning', 'result_source_disabled', array( 'contest_id' => $contest_id, 'source_url' => $url, 'reason' => 'HTTP 404' ) );
	}

	private function candidate_rows( array $raw ): array {
		if ( isset( $raw['cand'] ) && is_array( $raw['cand'] ) ) { return $raw['cand']; }
		if ( isset( $raw['candidatos'] ) && is_array( $raw['candidatos'] ) ) { return $raw['candidatos']; }
		if ( isset( $raw['candidates'] ) && is_array( $raw['candidates'] ) ) { return $raw['candidates']; }
		$rows = array();
		foreach ( (array) ( $raw['carg'] ?? array() ) as $position ) {
			foreach ( (array) ( $position['agr'] ?? array() ) as $group ) {
				foreach ( (array) ( $group['par'] ?? array() ) as $party ) {
					foreach ( (array) ( $party['cand'] ?? array() ) as $candidate ) {
						if ( ! is_array( $candidate ) ) { continue; }
						$candidate['_party'] = sanitize_text_field( $party['sg'] ?? '' );
						$rows[] = $candidate;
					}
				}
			}
		}
		return $rows;
	}

	private function is_valid_result( array $raw, array $normalized, string $kind ): bool {
		if ( 'EA20' !== $kind ) { return isset( $raw['abr'] ) || isset( $raw['s'] ); }
		$official_shape = isset( $raw['s'], $raw['v'] ) && ( isset( $raw['carg'] ) || isset( $raw['perg'] ) );
		$legacy_shape = isset( $raw['cand'] ) && isset( $normalized['totals']['total_votes'] );
		return $official_shape || $legacy_shape;
	}

	private function upsert_result_candidate( int $contest_id, array $candidate, string $source_url ): int {
		global $wpdb;
		$p = $wpdb->prefix . 'ae_';
		$election_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT election_id FROM {$p}contests WHERE id=%d", $contest_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $election_id ) { return 0; }
		$table = $p . 'candidates';
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE election_id=%d AND external_id=%s", $election_id, $candidate['external_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data = array( 'contest_id' => $contest_id, 'ballot_name' => $candidate['ballot_name'], 'full_name' => $candidate['full_name'], 'ballot_number' => $candidate['ballot_number'], 'party' => $candidate['party'], 'situation' => $candidate['situation'], 'photo_url' => $this->photo_url( $source_url, $candidate['external_id'] ), 'updated_at' => current_time( 'mysql', true ) );
		if ( $id ) { $wpdb->update( $table, array_filter( $data, static fn( $value ) => null !== $value && '' !== $value ), array( 'id' => $id ) ); return $id; }
		$data['election_id'] = $election_id; $data['external_id'] = $candidate['external_id']; $data['data_json'] = wp_json_encode( array( 'source' => 'EA20' ) );
		$wpdb->insert( $table, $data ); return (int) $wpdb->insert_id;
	}

	private function photo_url( string $source_url, string $candidate_id ): string {
		$path = (string) wp_parse_url( $source_url, PHP_URL_PATH );
		$host = (string) wp_parse_url( $source_url, PHP_URL_HOST );
		if ( ! preg_match( '#^(.+?)/dados/([^/]+)/[^/]+$#', $path, $match ) ) { return ''; }
		return esc_url_raw( 'https://' . $host . $match[1] . '/fotos/' . $match[2] . '/' . rawurlencode( $candidate_id ) . '.jpeg' );
	}

	private function throttle(): void {
		$last = (float) get_option( 'ae_tse_last_request_at', 0 );
		$remaining = 0.05 - ( microtime( true ) - $last ); // Hard ceiling: 20 requests/s per WordPress origin.
		if ( $remaining > 0 ) { usleep( (int) ceil( $remaining * 1000000 ) ); }
		update_option( 'ae_tse_last_request_at', microtime( true ), false );
	}

	private function integer( mixed $value ): int {
		$digits = preg_replace( '/[^0-9-]/', '', (string) $value );
		return is_string( $digits ) && '' !== $digits ? (int) $digits : 0;
	}

	private function decimal( mixed $value ): float { return (float) str_replace( ',', '.', (string) $value ); }

	/** Reads only the requested page from a staged CSV/ZIP, so a cron retry resumes by line. */
	private function read_open_data_batch( string $url, string $format, array $cursor, int $job_id ): array {
		$offset = absint( $cursor['offset'] ?? 0 );
		$path = get_transient( 'ae_import_file_' . $job_id );
		if ( ! $path || ! is_readable( $path ) ) {
			$path = wp_tempnam( $url );
			if ( ! $path ) { throw new RuntimeException( 'Não foi possível preparar o arquivo de candidatos.' ); }
			$response = wp_remote_get( $url, array( 'timeout' => 300, 'redirection' => 2, 'stream' => true, 'filename' => $path, 'headers' => array( 'Accept' => 'application/zip,application/octet-stream', 'User-Agent' => 'WordPress Apuracao Eleitoral/' . AE_VERSION ) ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				wp_delete_file( $path );
				throw new RuntimeException( is_wp_error( $response ) ? $response->get_error_message() : 'Dados Abertos do TSE respondeu HTTP ' . wp_remote_retrieve_response_code( $response ) . '.' );
			}
			set_transient( 'ae_import_file_' . $job_id, $path, DAY_IN_SECONDS );
		}
		$stream = null; $zip = null; $entry = absint( $cursor['entry'] ?? 0 ); $csv_names = array();
		if ( 'zip' === $format ) {
			if ( ! class_exists( 'ZipArchive' ) ) { throw new RuntimeException( 'Extensao ZipArchive indisponivel.' ); }
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path ) ) { throw new RuntimeException( 'Arquivo ZIP TSE invalido.' ); }
			for ( $i = 0; $i < $zip->numFiles; $i++ ) { $name = $zip->getNameIndex( $i ); if ( str_ends_with( strtolower( $name ), '.csv' ) ) { $csv_names[] = $name; } }
			if ( isset( $csv_names[ $entry ] ) ) { $stream = $zip->getStream( $csv_names[ $entry ] ); }
			if ( ! is_resource( $stream ) ) { $zip->close(); throw new RuntimeException( 'ZIP sem CSV de candidatos.' ); }
		} else { $stream = fopen( $path, 'rb' ); }
		if ( ! is_resource( $stream ) ) { throw new RuntimeException( 'Nao foi possivel abrir dados abertos.' ); }
		$header = fgetcsv( $stream, 0, ';' );
		if ( ! is_array( $header ) || ! $header ) { fclose( $stream ); if ( $zip ) { $zip->close(); } throw new RuntimeException( 'CSV sem cabecalho.' ); }
		$header = array_map( array( $this, 'utf8' ), $header );
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		for ( $skip = 0; $skip < $offset && false !== fgetcsv( $stream, 0, ';' ); $skip++ ) { }
		$rows = array();
		while ( count( $rows ) < 251 && false !== ( $line = fgetcsv( $stream, 0, ';' ) ) ) {
			if ( count( $line ) === count( $header ) ) { $rows[] = array_combine( $header, array_map( array( $this, 'utf8' ), $line ) ); }
		}
		$has_more_rows = count( $rows ) > 250;
		if ( $has_more_rows ) { array_pop( $rows ); }
		fclose( $stream ); if ( $zip ) { $zip->close(); }
		if ( 'zip' === $format ) {
			$next_cursor = $has_more_rows ? array( 'entry' => $entry, 'offset' => $offset + count( $rows ) ) : array( 'entry' => $entry + 1, 'offset' => 0 );
			$complete = ! $has_more_rows && $entry + 1 >= count( $csv_names );
		} else { $next_cursor = array( 'offset' => $offset + count( $rows ) ); $complete = ! $has_more_rows; }
		return array( 'rows' => $rows, 'complete' => $complete, 'cursor' => $next_cursor );
	}

	private function cleanup_import_file( int $job_id ): void {
		$key = 'ae_import_file_' . $job_id; $path = get_transient( $key );
		if ( $path && is_file( $path ) ) { wp_delete_file( $path ); }
		delete_transient( $key );
	}

	private function utf8( mixed $value ): string {
		$value = (string) $value;
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) ) { return mb_convert_encoding( $value, 'UTF-8', 'Windows-1252,ISO-8859-1' ); }
		return $value;
	}

	private function allowed_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && ( 'tse.jus.br' === $host || str_ends_with( $host, '.tse.jus.br' ) );
	}
	private function date_or_null( mixed $value ): ?string {
		$value = trim( (string) $value );
		foreach ( array( 'd/m/Y H:i:s', 'd/m/Y', DATE_RFC3339, 'Y-m-d H:i:s' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $value, new DateTimeZone( 'America/Sao_Paulo' ) );
			if ( $date instanceof DateTimeImmutable ) { return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); }
		}
		$time = strtotime( $value ); return $time ? gmdate( 'Y-m-d H:i:s', $time ) : null;
	}
}
