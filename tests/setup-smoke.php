<?php
// Idempotent local smoke test for the one-click 2026 preset.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admins ) { throw new RuntimeException( 'Administrator user required.' ); }
wp_set_current_user( $admins[0]->ID );
$_POST = array( 'action' => 'ae_quick_setup', 'year' => '2026', 'name' => 'Eleições Gerais 2026' );
$_POST['_wpnonce'] = wp_create_nonce( 'ae_quick_setup' );
$_REQUEST = $_POST;
AE_Admin::instance()->quick_setup();
