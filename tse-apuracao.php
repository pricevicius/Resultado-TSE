<?php
/**
 * Plugin Name:  TSE Apuração
 * Description:  Publica snapshots auditáveis de resultados eleitorais do TSE. Use o shortcode [tse_apuracao] ou o bloco Apuração eleitoral.
 * Version:      2.7.0
 * Requires PHP: 8.1
 * License:      GPL-2.0-or-later
 * Text Domain:  tse-apuracao
 */

defined( 'ABSPATH' ) || exit;

define( 'TSE_APURACAO_VERSION', '2.7.0' );
define( 'TSE_APURACAO_DIR',     plugin_dir_path( __FILE__ ) );
define( 'TSE_APURACAO_URL',     plugin_dir_url( __FILE__ ) );

define( 'AE_VERSION', TSE_APURACAO_VERSION );
define( 'AE_FILE', __FILE__ );
define( 'AE_DIR', TSE_APURACAO_DIR );
define( 'AE_URL', TSE_APURACAO_URL );

require_once TSE_APURACAO_DIR . 'includes/compat.php';
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
    // Recusa a ativação se faltar um requisito de ambiente (hoje, a extensão PHP zip), com a explicação na tela.
    $missing = AE_Plugin::missing_requirements();
    if ( $missing ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            '<p><strong>O plugin TSE Apuração não foi ativado.</strong> Falta no servidor:</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $missing ) ) . '</li></ul>',
            'TSE Apuração: requisito ausente',
            array( 'back_link' => true )
        );
    }
    add_option( 'tse_apuracao_settings', TSE_Settings::defaults() );
    AE_Plugin::activate();
} );

register_deactivation_hook( __FILE__, array( 'AE_Plugin', 'deactivate' ) );
