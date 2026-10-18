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
	 * Contesto del ponte traduzioni GRS i18n (non cambiarlo: è la chiave delle traduzioni).
	 */
	const I18N_CONTEXT = 'GRS SMTP';

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

		// Ponte traduzioni GRS i18n: il nome mittente arriva ai destinatari, quindi è traducibile.
		add_action( 'admin_init', array( $this, 'register_translatable_texts' ) );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'register_saved_texts' ), 10, 2 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'register_saved_texts' ), 10, 2 );

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
	 * Registra presso il sistema di traduzione del sito i testi delle impostazioni
	 * che arrivano ai destinatari (oggi solo il nome mittente).
	 *
	 * @param string|null $from_name Valore appena salvato; null = valore corrente.
	 * @return void
	 */
	public function register_translatable_texts( $from_name = null ) {
		if ( ! class_exists( 'GRS_I18n' ) ) {
			return;
		}
		if ( ! is_string( $from_name ) ) {
			$from_name = (string) Settings::get( 'from_name' );
		}
		\GRS_I18n::register( self::I18N_CONTEXT, 'from_name', $from_name );
	}

	/**
	 * Registrazione dopo il salvataggio delle impostazioni.
	 *
	 * @param mixed $old_or_name Valore precedente (update_option) o nome dell'opzione (add_option).
	 * @param mixed $value       Nuovo valore.
	 * @return void
	 */
	public function register_saved_texts( $old_or_name, $value ) {
		unset( $old_or_name );
		if ( Settings::is_constant( 'from_name' ) ) {
			$this->register_translatable_texts();
			return;
		}
		$name = ( is_array( $value ) && isset( $value['from_name'] ) && is_scalar( $value['from_name'] ) ) ? (string) $value['from_name'] : '';
		$this->register_translatable_texts( $name );
	}

	/**
	 * Formato data e ora del sito (Impostazioni → Generali).
	 *
	 * @return string
	 */
	public static function datetime_format() {
		$date = (string) get_option( 'date_format' );
		$time = (string) get_option( 'time_format' );
		return trim( ( '' !== $date ? $date : 'Y-m-d' ) . ' ' . ( '' !== $time ? $time : 'H:i' ) );
	}

	/**
	 * Traduzioni (testi del codice in inglese, italiano incluso in languages/).
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
				'title' => esc_html__( 'Emails blocked', 'grs-smtp' ),
				'href'  => admin_url( 'admin.php?page=grs-smtp' ),
				'meta'  => array(
					'title' => esc_attr__( 'GRS SMTP: email delivery disabled (staging mode)', 'grs-smtp' ),
				),
			)
		);
	}
}
