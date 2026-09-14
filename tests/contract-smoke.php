<?php
// Run with: wp eval-file wp-content/plugins/tse-apuracao/tests/contract-smoke.php
$method = new ReflectionMethod( AE_TSE_Client::class, 'normalize_result' );
$method->setAccessible( true );
$load = static function ( string $name ) use ( $method ): array {
	$raw = json_decode( file_get_contents( AE_DIR . 'tests/fixtures/' . $name ), true, 512, JSON_THROW_ON_ERROR );
	return $method->invoke( AE_TSE_Client::instance(), $raw, 'EA20' );
};
$zero = $load( 'ea20-zero.json' );
$final = $load( 'ea20-final.json' );
if ( 'not_started' !== $zero['totals']['progress'] || 0 !== $zero['totals']['total_votes'] || 2 !== count( $zero['candidates'] ) ) {
	throw new RuntimeException( 'EA20 zero contract failed.' );
}
if ( 'final' !== $final['totals']['progress'] || ! $final['totals']['final'] || 2 !== array_sum( array_column( $final['candidates'], 'elected' ) ) || 'Não eleito' !== $final['candidates'][2]['situation'] ) {
	throw new RuntimeException( 'EA20 final contract failed.' );
}
if ( 'https://resultados.tse.jus.br/oficial/comum/config/ele-c.json' !== AE_TSE_Discovery::config_url( 'oficial' ) ) {
	throw new RuntimeException( 'Official EA11 URL contract failed.' );
}
try {
	AE_TSE_Discovery::sync( array( 'environment' => 'simulado', 'year' => 2026 ) );
	throw new RuntimeException( 'Simulation guard was not triggered.' );
} catch ( RuntimeException $error ) {
	if ( ! str_contains( $error->getMessage(), 'ainda não publicou' ) ) { throw $error; }
}
if ( ! str_ends_with( AE_TSE_Discovery::candidates_url( 2026 ), '/consulta_cand_2026.zip' ) ) {
	throw new RuntimeException( 'Candidate package URL contract failed.' );
}
$url_method = new ReflectionMethod( AE_TSE_Discovery::class, 'result_url' );
$url_method->setAccessible( true );
$result_url = $url_method->invoke( null, 'oficial', 'ele2026', 999, 'es', '0003', array( array( 'tp' => 'u', 'dir' => '<base>/<ambiente>/<ciclo>/<cd_eleicao>/dados/<uf>' ) ) );
if ( 'https://resultados.tse.jus.br/oficial/ele2026/999/dados/es/es-c0003-e000999-u.json' !== $result_url ) {
	throw new RuntimeException( 'EA20 URL template contract failed.' );
}
$widget = do_shortcode( '[apuracao cargo="0003" abrangencia="ES" turno="1"]' );
if ( ! str_contains( $widget, 'tse-apuracao-widget' ) || ! wp_script_is( 'tse-live', 'enqueued' ) ) {
	throw new RuntimeException( 'Client-side block/shortcode adapter failed.' );
}
echo "EA20 zero/final and discovery contracts: OK\n";
