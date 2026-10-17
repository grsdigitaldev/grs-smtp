<?php
/**
 * Orchestratore del plugin.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Avvia i componenti e gestisce gli aggiornamenti di versione.
 */
final class Plugin {

	const OPTION_VERSION = 'grs_smtp_version';

	/**
	 * Istanza unica.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Istanza unica.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Costruttore privato.
	 */
	private function __construct() {}

	/**
	 * Avvio.
	 *
	 * @return void
	 */
	private function boot() {
		$this->maybe_upgrade();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_setting' ) );

		// GRS Security 1.0.1+: mantiene leggibile la password quando rigenera le chiavi SALT.
		add_action( 'grs_security_salts_regenerated', array( Settings::class, 'rekey_password' ) );

		( new Mailer() )->register();
		( new Logger() )->register();
		( new Notifier() )->register();
		( new Privacy() )->register();
		( new Updater() )->register();

		if ( is_admin() ) {
			( new Admin\Admin() )->register();
		}

		add_action( 'admin_bar_menu', array( $this, 'admin_bar_staging' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_bar_style' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'admin_bar_style' ) );
	}

	/**
	 * Colore dell'indicatore nella barra admin.
	 *
	 * @return void
	 */
	public function admin_bar_style() {
		if ( Mailer::delivery_disabled() && is_admin_bar_showing() && current_user_can( 'manage_options' ) ) {
			wp_add_inline_style( 'admin-bar', '#wpadminbar #wp-admin-bar-grs-smtp-staging > .ab-item{background:#d63638;color:#fff}' );
		}
	}

	/**
	 * Migrazioni tra versioni e preparazione dei siti di un network.
	 *
	 * @return void
	 */
	private function maybe_upgrade() {
		if ( GRS_SMTP_VERSION === get_option( self::OPTION_VERSION ) && Logger::DB_VERSION === get_option( Logger::OPTION_DB_VERSION ) ) {
			return;
		}
		Activator::setup_site();
	}

	/**
	 * Registra l'opzione con la sua sanitizzazione in ogni contesto (bacheca, WP-CLI, codice).
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			'grs_smtp',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Traduzioni (stringhe sorgente in italiano).
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'grs-smtp', false, dirname( plugin_basename( GRS_SMTP_FILE ) ) . '/languages' );
	}

	/**
	 * Indicatore nella barra admin quando gli invii sono bloccati.
	 *
	 * @param \WP_Admin_Bar $bar Barra admin.
	 * @return void
	 */
	public function admin_bar_staging( $bar ) {
		if ( ! Mailer::delivery_disabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'grs-smtp-staging',
				'title' => esc_html__( 'Email bloccate', 'grs-smtp' ),
				'href'  => admin_url( 'admin.php?page=grs-smtp' ),
				'meta'  => array(
					'title' => esc_attr__( 'GRS SMTP: invio email disattivato (modalità staging)', 'grs-smtp' ),
				),
			)
		);
	}
}
