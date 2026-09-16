<?php
/**
 * Plugin Name:  TSE Apuração
 * Description:  Publica snapshots auditáveis de resultados eleitorais do TSE. Use o shortcode [tse_apuracao] ou o bloco Apuração eleitoral.
 * Version:      2.2.2
 * Requires PHP: 8.1
 * Author:       Pricevicius
 * License:      GPL-2.0-or-later
 * Text Domain:  tse-apuracao
 */

defined( 'ABSPATH' ) || exit;

define( 'TSE_APURACAO_VERSION', '2.2.2' );
define( 'TSE_APURACAO_FILE',    __FILE__ );
define( 'TSE_APURACAO_DIR',     plugin_dir_path( __FILE__ ) );
define( 'TSE_APURACAO_URL',     plugin_dir_url( __FILE__ ) );

require_once TSE_APURACAO_DIR . 'includes/class-tse-api.php';
require_once TSE_APURACAO_DIR . 'includes/class-tse-settings.php';
require_once TSE_APURACAO_DIR . 'includes/class-tse-shortcode.php';
require_once TSE_APURACAO_DIR . 'includes/class-plugin.php';

add_action( 'plugins_loaded', function () {
    TSE_Settings::init();
    TSE_Shortcode::init();
    TSE_Plugin::instance()->boot();
} );

register_activation_hook( __FILE__, function () {
    add_option( 'tse_apuracao_settings', TSE_Settings::defaults() );
    TSE_Plugin::activate();
} );

register_deactivation_hook( __FILE__, array( 'TSE_Plugin', 'deactivate' ) );
