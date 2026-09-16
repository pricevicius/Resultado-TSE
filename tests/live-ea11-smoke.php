<?php
// Manual network smoke test. It is not part of deterministic CI.
try {
	TSE_Discovery::sync( array( 'environment' => 'oficial', 'year' => 2026 ) );
	echo "EA11 live: Eleições 2026 disponíveis e sincronizadas.\n";
} catch ( RuntimeException $error ) {
	if ( ! str_contains( $error->getMessage(), 'ainda não publicou' ) ) { throw $error; }
	echo "EA11 live: catálogo acessível; Eleições 2026 ainda não publicadas.\n";
}
