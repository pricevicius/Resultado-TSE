<?php
defined( 'ABSPATH' ) || exit;

require_once AE_DIR . 'includes/class-schema.php';
require_once AE_DIR . 'includes/class-logger.php';
require_once AE_DIR . 'includes/class-job-runner.php';
require_once AE_DIR . 'includes/class-tse-client.php';
require_once AE_DIR . 'includes/class-tse-discovery.php';
require_once AE_DIR . 'includes/class-results.php';
require_once AE_DIR . 'includes/class-candidate-catalog.php';
require_once AE_DIR . 'includes/class-rest.php';
require_once AE_DIR . 'includes/class-shortcodes.php';
require_once AE_DIR . 'includes/class-admin.php';

final class AE_Plugin {
	private static ?AE_Plugin $instance = null;

	public static function instance(): AE_Plugin {
		return self::$instance ??= new self();
	}

	public static function activate(): void {
		add_filter( 'cron_schedules', array( self::instance(), 'minute_schedule' ) );
		AE_Schema::install();
		if ( AE_Schema::VERSION === get_option( 'ae_schema_version' ) ) { self::seed_2026(); }
		if ( ! wp_next_scheduled( 'ae_run_jobs' ) ) {
			wp_schedule_event( time() + 60, 'ae_minute', 'ae_run_jobs' );
		}
		set_transient( 'ae_activation_redirect', 1, MINUTE_IN_SECONDS );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'ae_run_jobs' );
	}

	public function boot(): void {
		add_filter( 'cron_schedules', array( $this, 'minute_schedule' ) );
		// The option alone is not reliable after a partial restore or failed activation.
		if ( ! AE_Schema::is_ready() ) {
			AE_Schema::install();
			if ( AE_Schema::is_ready() ) { self::seed_2026(); }
		}
		if ( ! wp_next_scheduled( 'ae_run_jobs' ) ) {
			wp_schedule_event( time() + 60, 'ae_minute', 'ae_run_jobs' );
		}
		add_action( 'ae_run_jobs', array( AE_Job_Runner::instance(), 'tick' ) );
		add_action( 'rest_api_init', array( AE_REST::instance(), 'register_routes' ) );
		add_action( 'init', array( AE_Shortcodes::instance(), 'register' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
		if ( is_admin() ) {
			AE_Admin::instance()->register();
			add_action( 'admin_init', array( $this, 'redirect_after_activation' ) );
		}
	}

	/** Sends a newly activated plugin directly to its operational checklist. */
	public function redirect_after_activation(): void {
		if ( ! get_transient( 'ae_activation_redirect' ) || wp_doing_ajax() || ! current_user_can( 'manage_options' ) || isset( $_GET['activate-multi'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		delete_transient( 'ae_activation_redirect' );
		wp_safe_redirect( admin_url( 'admin.php?page=apuracao-eleitoral' ) );
		exit;
	}

	public function minute_schedule( array $schedules ): array {
		$schedules['ae_minute'] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => __( 'Every minute (Apuracao)', 'apuracao-eleitoral' ) );
		return $schedules;
	}

	public function register_blocks(): void {
		register_block_type( AE_DIR . 'blocks/apuracao' );
	}

	private static function seed_2026(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ae_elections';
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", 'eleicoes-2026' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $exists ) {
			return;
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert( $table, array(
			'slug' => 'eleicoes-2026', 'name' => 'Eleicoes Gerais 2026', 'year' => 2026,
			'timezone' => 'America/Sao_Paulo', 'status' => 'draft',
			'config_json' => wp_json_encode( array(
				'simulations' => array( '2026-09-15/2026-09-17', '2026-09-22/2026-09-24' ),
				'positions' => array(
					array( 'code' => '0001', 'name' => 'Presidente', 'scope_type' => 'BR', 'seats' => 1 ),
					array( 'code' => '0003', 'name' => 'Governador', 'scope_type' => 'UF', 'seats' => 1 ),
					array( 'code' => '0005', 'name' => 'Senador', 'scope_type' => 'UF', 'seats' => 1 ),
				),
			) ), 'created_at' => $now, 'updated_at' => $now,
		), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
	}
}
