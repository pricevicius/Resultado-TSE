<?php
// Set WP_TESTS_DIR to a checkout of the WordPress test library before PHPUnit.
$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP_TESTS_DIR is required for integration tests.\n" );
	exit( 1 );
}
require_once $wp_tests_dir . '/includes/functions.php';
tests_add_filter( 'muplugins_loaded', static function () { require dirname( __DIR__ ) . '/tse-apuracao.php'; } );
require $wp_tests_dir . '/includes/bootstrap.php';
