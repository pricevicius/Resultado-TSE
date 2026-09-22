<?php
/**
 * Disparo direto do worker de coleta, independente do WP-Cron por tráfego.
 * Uso: php bin/tse-tick.php (chamado por um cron real do sistema).
 */
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( "Somente CLI.\n" );
}
define( 'WP_USE_THEMES', false );
require_once dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! class_exists( 'AE_Job_Runner' ) ) {
	fwrite( STDERR, "AE_Job_Runner indisponivel; plugin inativo?\n" );
	exit( 1 );
}

AE_Job_Runner::instance()->tick();
