<?php
/**
 * Disparo direto do worker de coleta, independente do WP-Cron por tráfego.
 * Uso: php bin/tse-tick.php (chamado por um cron real do sistema).
 */
define( 'WP_USE_THEMES', false );
require_once dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! class_exists( 'TSE_Job_Runner' ) ) {
	fwrite( STDERR, "TSE_Job_Runner indisponivel; plugin inativo?\n" );
	exit( 1 );
}

TSE_Job_Runner::instance()->tick();
