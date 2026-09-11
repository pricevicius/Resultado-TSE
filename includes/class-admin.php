<?php
defined( 'ABSPATH' ) || exit;

final class AE_Admin {
	private static ?AE_Admin $instance = null;
	public static function instance(): AE_Admin { return self::$instance ??= new self(); }
	public function register(): void { add_action( 'admin_menu', array( $this, 'menu' ) ); }
	public function menu(): void { add_menu_page( 'Apuracao Eleitoral', 'Apuracao', 'manage_options', 'apuracao-eleitoral', array( $this, 'page' ), 'dashicons-chart-bar', 58 ); }
	public function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$health = AE_REST::instance()->health()->get_data();
		echo '<div class="wrap"><h1>Apuracao Eleitoral</h1><p>Estado operacional do coletor e dos snapshots.</p><table class="widefat"><tbody>';
		foreach ( array( 'schema' => 'Schema', 'cron_next' => 'Proximo job', 'latest_snapshot' => 'Ultimo snapshot valido' ) as $key => $label ) { echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) ( $health[ $key ] ?? '—' ) ) . '</td></tr>'; }
		echo '<tr><th>Jobs em espera/falhos</th><td>' . esc_html( $health['jobs']['queued'] . ' / ' . $health['jobs']['failed'] ) . '</td></tr></tbody></table><p>Use a API administrativa para enfileirar importacoes e coletas; o painel completo sera a proxima fase.</p></div>';
	}
}
