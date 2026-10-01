<?php
defined( 'ABSPATH' ) || exit;

require_once AE_DIR . 'includes/class-schema.php';
require_once AE_DIR . 'includes/class-logger.php';
require_once AE_DIR . 'includes/class-perf.php';
require_once AE_DIR . 'includes/class-collection-policy.php';
require_once AE_DIR . 'includes/class-job-runner.php';
require_once AE_DIR . 'includes/class-tse-client.php';
require_once AE_DIR . 'includes/class-tse-discovery.php';
require_once AE_DIR . 'includes/class-results.php';
require_once AE_DIR . 'includes/class-candidate-catalog.php';
require_once AE_DIR . 'includes/class-candidate-list.php';
require_once AE_DIR . 'includes/class-navigation.php';
require_once AE_DIR . 'includes/class-rest.php';
require_once AE_DIR . 'includes/class-resumo.php';
require_once AE_DIR . 'includes/class-shortcodes.php';
require_once AE_DIR . 'includes/class-admin-guide.php';
require_once AE_DIR . 'includes/class-admin.php';

final class AE_Plugin {
	private static ?AE_Plugin $instance = null;

	public static function instance(): AE_Plugin {
		return self::$instance ??= new self();
	}

	/**
	 * Requisitos de ambiente sem os quais o plugin não deve ser ativado. Hoje: a extensão PHP zip
	 * (classe ZipArchive), que a importação de candidatos usa para abrir o pacote dos Dados Abertos.
	 * Quem desenvolve ou testa sem a extensão pode definir TSE_APURACAO_ALLOW_NO_ZIP como true no
	 * wp-config.php, ou usar o filtro ae_missing_requirements; em produção, não faça isso.
	 *
	 * @return string[] Descrição de cada requisito que falta (vazio = tudo certo).
	 */
	public static function missing_requirements(): array {
		$missing = array();
		$allow = defined( 'TSE_APURACAO_ALLOW_NO_ZIP' ) && TSE_APURACAO_ALLOW_NO_ZIP;
		if ( ! class_exists( 'ZipArchive' ) && ! $allow ) {
			$missing[] = 'a extensão PHP zip (classe ZipArchive), usada para importar os candidatos do pacote ZIP dos Dados Abertos do TSE. Instale o pacote php-zip no servidor (por exemplo, apt install php-zip, ou o equivalente da sua versão do PHP) e reinicie o PHP-FPM ou o Apache';
		}
		return array_values( (array) apply_filters( 'ae_missing_requirements', $missing ) );
	}

	public static function activate(): void {
		add_filter( 'cron_schedules', array( self::instance(), 'minute_schedule' ) );
		AE_Schema::install();
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
		// WordPress antigo (antes do 5.5) não tem blocos por pasta/block.json; nele o shortcode cobre o mesmo uso.
		if ( ! function_exists( 'register_block_type_from_metadata' ) ) { return; }
		register_block_type( AE_DIR . 'blocks/apuracao' );
	}

	/**
	 * Ano de eleição sugerido nos formulários: o ano corrente se for par, senão o seguinte
	 * (eleições gerais e municipais caem em anos pares). Filtrável por ae_default_election_year.
	 */
	public static function default_election_year(): int {
		$year = (int) gmdate( 'Y' );
		return (int) apply_filters( 'ae_default_election_year', 0 === $year % 2 ? $year : $year + 1 );
	}
}
