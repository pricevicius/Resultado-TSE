<?php
defined( 'ABSPATH' ) || exit;

/**
 * Compatibilidade para o plugin não depender da versão do PHP nem do WordPress: funções que o PHP 8.0
 * e o WordPress mais novo trazem, definidas aqui só quando ainda não existem.
 */
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $haystack, $needle ) { return '' === $needle || false !== strpos( $haystack, $needle ); }
}
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $haystack, $needle ) { return 0 === strncmp( $haystack, $needle, strlen( $needle ) ); }
}
if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $haystack, $needle ) { return '' === $needle || ( '' !== $haystack && 0 === substr_compare( $haystack, $needle, -strlen( $needle ) ) ); }
}

/** wp_date() chegou no WordPress 5.3: no mais antigo, formata no fuso do site com date_i18n(). */
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		$timestamp = null === $timestamp ? time() : (int) $timestamp;
		$zone = $timezone;
		if ( ! $zone instanceof DateTimeZone ) {
			$name = (string) get_option( 'timezone_string' );
			$zone = '' !== $name ? new DateTimeZone( $name ) : null;
		}
		$offset = $zone ? $zone->getOffset( new DateTime( '@' . $timestamp ) ) : (int) round( (float) get_option( 'gmt_offset' ) * 3600 );
		return date_i18n( $format, $timestamp + $offset );
	}
}
