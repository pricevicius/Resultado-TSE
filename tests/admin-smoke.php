<?php
// Run with: wp eval-file wp-content/plugins/tse-apuracao/tests/admin-smoke.php
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admins ) {
	throw new RuntimeException( 'Administrator user required for the admin smoke test.' );
}
wp_set_current_user( $admins[0]->ID );
global $wpdb;
$tabs = array( 'overview' => 'Comece em três passos', 'setup' => 'Conectar ao TSE', 'import' => 'Importar candidatos', 'jobs' => 'Fila de processamento', 'logs' => 'Últimos eventos' );
foreach ( $tabs as $tab => $expected ) {
	$_GET['tab'] = $tab;
	$wpdb->last_error = '';
	ob_start();
	AE_Admin::instance()->page();
	$html = ob_get_clean();
	if ( $wpdb->last_error ) {
		throw new RuntimeException( 'Database error on ' . $tab . ': ' . $wpdb->last_error );
	}
	if ( false === strpos( $html, $expected ) ) {
		throw new RuntimeException( 'Admin tab ' . $tab . ' missing: ' . $expected );
	}
}
echo "Admin smoke test: OK\n";
