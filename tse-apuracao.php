<?php
/**
 * Plugin Name:  TSE Apuração
 * Plugin URI:   https://tribunaonline.com.br
 * Description:  Exibe resultados eleitorais em tempo real via API pública do TSE. Use o shortcode [tse_apuracao] em qualquer página.
 * Version:      1.0.0
 * Requires PHP: 7.4
 * Author:       Tribuna Online
 * License:      GPL-2.0-or-later
 * Text Domain:  tse-apuracao
 */

defined( 'ABSPATH' ) || exit;

define( 'TSE_APURACAO_VERSION', '1.0.0' );
define( 'TSE_APURACAO_DIR',     plugin_dir_path( __FILE__ ) );
define( 'TSE_APURACAO_URL',     plugin_dir_url( __FILE__ ) );

require_once TSE_APURACAO_DIR . 'includes/class-tse-api.php';
require_once TSE_APURACAO_DIR . 'includes/class-tse-settings.php';
require_once TSE_APURACAO_DIR . 'includes/class-tse-shortcode.php';

add_action( 'plugins_loaded', function () {
    TSE_Settings::init();
    TSE_Shortcode::init();
} );

register_activation_hook( __FILE__, function () {
    add_option( 'tse_apuracao_settings', TSE_Settings::defaults() );
} );
