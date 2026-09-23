<?php
/**
 * Plugin Name:  TSE Apuração
 * Description:  Publica snapshots auditáveis de resultados eleitorais do TSE. Use o shortcode [tse_apuracao] ou o bloco Apuração eleitoral.
 * Version:      2.3.5
 * Requires PHP: 8.1
 * License:      GPL-2.0-or-later
 * Text Domain:  tse-apuracao
 */

defined( 'ABSPATH' ) || exit;

define( 'TSE_APURACAO_VERSION', '2.3.5' );
define( 'TSE_APURACAO_DIR',     plugin_dir_path( __FILE__ ) );
define( 'TSE_APURACAO_URL',     plugin_dir_url( __FILE__ ) );

define( 'AE_VERSION', '2.3.5' );
define( 'AE_FILE', __FILE__ );
define( 'AE_DIR', TSE_APURACAO_DIR );
define( 'AE_URL', TSE_APURACAO_URL );

require_once TSE_APURACAO_DIR . 'includes/class-tse-api.php';
require_once TSE_APURACAO_DIR . 'includes/class-tse-settings.php';
require_once TSE_APURACAO_DIR . 'includes/class-tse-shortcode.php';
require_once TSE_APURACAO_DIR . 'includes/class-plugin.php';

add_action( 'plugins_loaded', function () {
    TSE_Settings::init();
    TSE_Shortcode::init();
    AE_Plugin::instance()->boot();
} );

register_activation_hook( __FILE__, function () {
    add_option( 'tse_apuracao_settings', TSE_Settings::defaults() );
    AE_Plugin::activate();
} );

register_deactivation_hook( __FILE__, array( 'AE_Plugin', 'deactivate' ) );
