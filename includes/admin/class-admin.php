<?php
/**
 * Pagina di amministrazione: impostazioni, test, log, diagnostica.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp\Admin;

use GRSDigital\Smtp\Conflicts;
use GRSDigital\Smtp\Crypto;
use GRSDigital\Smtp\Dns_Check;
use GRSDigital\Smtp\Logger;
use GRSDigital\Smtp\Mailer;
use GRSDigital\Smtp\Notifier;
use GRSDigital\Smtp\Providers;
use GRSDigital\Smtp\Settings;
use GRSDigital\Smtp\Tester;
use GRSDigital\Smtp\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Interfaccia admin del plugin (GRS DEVs → SMTP).
 */
class Admin {

	const SLUG       = 'grs-smtp';
	const SCREEN_ID  = 'grs-devs_page_grs-smtp';
	const CAPABILITY = 'manage_options';
	const GROUP      = 'grs_smtp';

	/**
	 * Hook suffix della pagina.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'redirect_legacy_url' ), 1 );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_filter( 'grs_devs_plugins', array( $this, 'register_in_grs_devs' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'wp_ajax_grs_smtp_send_test', array( $this, 'ajax_test' ) );
		add_action( 'admin_post_grs_smtp_log', array( $this, 'handle_log_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GRS_SMTP_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * URL della pagina.
	 *
	 * @param array $args Parametri.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Fino alla 1.0.x la pagina era in Impostazioni: i vecchi link (segnalibri, email di avviso) portano alla nuova.
	 *
	 * Gira su admin_menu, prima del controllo dei permessi di WordPress che altrimenti
	 * risponderebbe "pagina non consentita" per l'indirizzo non più registrato.
	 *
	 * @return void
	 */
	public function redirect_legacy_url() {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo reindirizzamento, nessuna azione.
		if ( 'options-general.php' !== $pagenow || ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		$args = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parametri di navigazione ricopiati e sanitizzati.
		foreach ( (array) wp_unslash( $_GET ) as $key => $value ) {
			if ( 'page' !== $key && is_scalar( $value ) ) {
				$args[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Registrazione nella panoramica "GRS DEVs".
	 *
	 * @param array $plugins Plugin GRS registrati.
	 * @return array
	 */
	public function register_in_grs_devs( $plugins ) {
		$plugins = is_array( $plugins ) ? $plugins : array();

		$plugins['grs-smtp'] = array(
			'name'        => 'GRS SMTP',
			'version'     => GRS_SMTP_VERSION,
			'description' => __( 'Invio delle email tramite il server SMTP del dominio, con test, log e avvisi di errore.', 'grs-smtp' ),
			'url'         => self::url(),
		);
		return $plugins;
	}

	/**
	 * Voce di menu.
	 *
	 * @return void
	 */
	public function menu() {
		// Voce del menu condiviso "GRS DEVs" (componente in includes/grs-devs/, menu padre registrato a priorità 9).
		$hook = add_submenu_page( 'grs-devs', 'GRS SMTP', 'SMTP', self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
		if ( $hook ) {
			$this->hook = $hook;
			add_action( 'load-' . $hook, array( $this, 'on_load' ) );
		}
	}

	/**
	 * Link "Impostazioni" nella lista plugin.
	 *
	 * @param array $links Link.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Impostazioni', 'grs-smtp' ) . '</a>' );
		return $links;
	}

	/**
	 * CSS e JS solo sulla pagina del plugin.
	 *
	 * @param string $hook_suffix Pagina corrente.
	 * @return void
	 */
	public function assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'grs-smtp-admin', GRS_SMTP_URL . 'assets/css/admin.css', array(), GRS_SMTP_VERSION );
		wp_enqueue_script( 'grs-smtp-admin', GRS_SMTP_URL . 'assets/js/admin.js', array(), GRS_SMTP_VERSION, true );

		$providers = array();
		foreach ( Providers::all() as $key => $provider ) {
			$providers[ $key ] = array(
				'host' => (string) $provider['host'],
				'port' => (int) $provider['port'],
				'enc'  => (string) $provider['enc'],
				'note' => (string) $provider['note'],
			);
		}

		wp_localize_script(
			'grs-smtp-admin',
			'grsSmtp',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'grs_smtp_test' ),
				'providers' => $providers,
				'i18n'      => array(
					'sending'       => __( 'Invio in corso…', 'grs-smtp' ),
					'error'         => __( 'Richiesta non riuscita. Ricarica la pagina e riprova.', 'grs-smtp' ),
					'debug'         => __( 'Dettaglio tecnico della connessione', 'grs-smtp' ),
					'hint'          => __( 'Cosa controllare', 'grs-smtp' ),
					'confirm'       => __( 'Eliminare definitivamente tutte le righe del log?', 'grs-smtp' ),
					'confirmDelete' => __( 'Eliminare questa email dal log?', 'grs-smtp' ),
				),
			)
		);
	}

	/**
	 * Caricamento pagina: azioni di gruppo del log (prima dell'output, per poter reindirizzare).
	 *
	 * @return void
	 */
	public function on_load() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Il nonce è verificato sotto prima di agire.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'log' !== $tab ) {
			return;
		}

		$action = '';
		if ( isset( $_GET['action'] ) && '-1' !== $_GET['action'] ) {
			$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		} elseif ( isset( $_GET['action2'] ) && '-1' !== $_GET['action2'] ) {
			$action = sanitize_key( wp_unslash( $_GET['action2'] ) );
		}

		if ( 'bulk_delete' !== $action || empty( $_GET['ids'] ) ) {
			return;
		}
		// phpcs:enable

		check_admin_referer( 'bulk-grs_smtp_logs' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'grs-smtp' ), 403 );
		}

		$ids = array_map( 'absint', (array) wp_unslash( $_GET['ids'] ) );
		Logger::delete( $ids );

		wp_safe_redirect(
			self::url(
				array(
					'tab'     => 'log',
					'grs_msg' => 'deleted',
				)
			)
		);
		exit;
	}

	/**
	 * Avvisi generali del plugin.
	 *
	 * @return void
	 */
	public function notices() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$is_ours    = ( $screen->id === $this->hook );
		$is_general = in_array( $screen->id, array( 'dashboard', 'plugins' ), true );

		if ( ! $is_ours && ! $is_general ) {
			return;
		}

		if ( Mailer::delivery_disabled() ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'GRS SMTP: invio email disattivato (modalità staging).', 'grs-smtp' ) . '</strong> ' . esc_html__( 'Le email non vengono consegnate, solo registrate nel log. Ricordati di riattivarle sul sito in produzione.', 'grs-smtp' ) . '</p></div>';
		}

		if ( Settings::password_unreadable() ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'GRS SMTP: la password salvata non è più leggibile.', 'grs-smtp' ) . '</strong> ' . esc_html__( 'Succede quando cambiano le chiavi di sicurezza di wp-config.php (es. dopo una migrazione). Inserisci di nuovo la password SMTP.', 'grs-smtp' ) . '</p></div>';
		}

		$conflicts = Conflicts::active_plugins();
		if ( $conflicts && ( $is_ours || 'plugins' === $screen->id ) ) {
			/* translators: %s: elenco plugin. */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'GRS SMTP: è attivo anche %s. Due plugin SMTP insieme possono sovrascriversi a vicenda: disattivane uno.', 'grs-smtp' ), implode( ', ', $conflicts ) ) ) . '</p></div>';
		}

		if ( $is_ours && ! Settings::is_configured() ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'GRS SMTP non è ancora configurato: le email partono con il metodo predefinito del server.', 'grs-smtp' ) . '</p></div>';
		}

		if ( $is_ours ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo lettura del codice messaggio.
			$code     = isset( $_GET['grs_msg'] ) ? sanitize_key( wp_unslash( $_GET['grs_msg'] ) ) : '';
			$messages = array(
				'resent'        => array( 'success', __( 'Email reinviata.', 'grs-smtp' ) ),
				'resend_failed' => array( 'error', __( 'Reinvio non riuscito: controlla il log per il dettaglio.', 'grs-smtp' ) ),
				'no_content'    => array( 'error', __( 'Il contenuto di questa email non è stato salvato: impossibile reinviarla.', 'grs-smtp' ) ),
				'deleted'       => array( 'success', __( 'Righe eliminate.', 'grs-smtp' ) ),
				'cleared'       => array( 'success', __( 'Log svuotato.', 'grs-smtp' ) ),
			);
			if ( isset( $messages[ $code ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $messages[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ][1] ) . '</p></div>';
			}
		}
	}

	/**
	 * Pagina.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tabs = array(
			'settings'    => __( 'Impostazioni', 'grs-smtp' ),
			'test'        => __( 'Email di test', 'grs-smtp' ),
			'log'         => __( 'Log', 'grs-smtp' ),
			'diagnostics' => __( 'Diagnostica', 'grs-smtp' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigazione.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}

		echo '<div class="wrap grs-smtp">';
		// Fuori da Impostazioni WordPress non mostra da solo l'esito del salvataggio.
		if ( 'settings' === $tab ) {
			settings_errors();
		}
		echo '<h1 class="grs-smtp__title">GRS SMTP <span class="grs-smtp__version">v' . esc_html( GRS_SMTP_VERSION ) . '</span>';
		echo $this->status_badge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML costruito con escape nel metodo.
		echo '</h1>';

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$class = 'nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'test':
				$this->render_test();
				break;
			case 'log':
				$this->render_log();
				break;
			case 'diagnostics':
				$this->render_diagnostics();
				break;
			default:
				$this->render_settings();
		}

		echo '<p class="grs-smtp__footer">' . esc_html__( 'Sviluppato da', 'grs-smtp' ) . ' <a href="https://grsdigital.it" target="_blank" rel="noopener">GRS Digital</a> · <a href="mailto:info@grsdigital.it">info@grsdigital.it</a></p>';
		echo '</div>';
	}

	/**
	 * Badge di stato accanto al titolo.
	 *
	 * @return string
	 */
	private function status_badge() {
		if ( Mailer::delivery_disabled() ) {
			return '<span class="grs-smtp__badge grs-smtp__badge--warn">' . esc_html__( 'Invii bloccati', 'grs-smtp' ) . '</span>';
		}
		if ( ! Settings::is_configured() ) {
			return '<span class="grs-smtp__badge grs-smtp__badge--off">' . esc_html__( 'Non configurato', 'grs-smtp' ) . '</span>';
		}
		$state = Notifier::state();
		if ( (int) $state['count'] > 0 ) {
			return '<span class="grs-smtp__badge grs-smtp__badge--error">' . esc_html__( 'Ultimo invio fallito', 'grs-smtp' ) . '</span>';
		}
		return '<span class="grs-smtp__badge grs-smtp__badge--ok">' . esc_html__( 'Attivo', 'grs-smtp' ) . '</span>';
	}

	/**
	 * Attributo "disabled" e nota per i campi definiti in wp-config.php.
	 *
	 * @param string $key Chiave.
	 * @return array{0: string, 1: string}
	 */
	private function locked( $key ) {
		if ( Settings::is_constant( $key ) ) {
			return array( ' disabled', '<p class="description grs-smtp__locked">' . esc_html__( 'Definito in wp-config.php.', 'grs-smtp' ) . '</p>' );
		}
		return array( '', '' );
	}

	/**
	 * Checkbox.
	 *
	 * @param string $key   Chiave.
	 * @param string $label Etichetta.
	 * @param string $desc  Descrizione.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		$name                    = Settings::OPTION . '[' . $key . ']';
		list( $disabled, $note ) = $this->locked( $key );
		echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" id="grs-smtp-' . esc_attr( $key ) . '" value="1"' . checked( (bool) Settings::get( $key ), true, false ) . esc_attr( $disabled ) . '> ' . esc_html( $label ) . '</label>';
		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo wp_kses_post( $note );
	}

	/**
	 * Campo di testo.
	 *
	 * @param string $key   Chiave.
	 * @param string $type  Tipo input.
	 * @param string $desc  Descrizione.
	 * @param string $extra Attributi extra (già sicuri).
	 * @param string $css   Classe CSS.
	 * @return void
	 */
	private function text( $key, $type = 'text', $desc = '', $extra = '', $css = 'regular-text' ) {
		$name                    = Settings::OPTION . '[' . $key . ']';
		list( $disabled, $note ) = $this->locked( $key );
		$value                   = Settings::get( $key );
		echo '<input type="' . esc_attr( $type ) . '" class="' . esc_attr( $css ) . '" name="' . esc_attr( $name ) . '" id="grs-smtp-' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '"' . esc_attr( $disabled ) . ' ' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $extra è una stringa statica interna.
		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo wp_kses_post( $note );
	}

	/**
	 * Scheda Impostazioni.
	 *
	 * @return void
	 */
	private function render_settings() {
		$settings  = Settings::all();
		$providers = Providers::all();
		$option    = Settings::OPTION;

		echo '<form method="post" action="options.php" class="grs-smtp__form" autocomplete="off">';
		settings_fields( self::GROUP );

		// Server SMTP.
		echo '<h2>' . esc_html__( 'Server SMTP', 'grs-smtp' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="grs-smtp-provider">' . esc_html__( 'Provider', 'grs-smtp' ) . '</label></th><td>';
		echo '<select name="' . esc_attr( $option ) . '[provider]" id="grs-smtp-provider">';
		foreach ( $providers as $key => $provider ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $settings['provider'], $key, false ) . '>' . esc_html( $provider['label'] ) . '</option>';
		}
		echo '</select>';
		$note = isset( $providers[ $settings['provider'] ]['note'] ) ? $providers[ $settings['provider'] ]['note'] : '';
		echo '<p class="description" id="grs-smtp-provider-note">' . esc_html( $note ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="grs-smtp-host">' . esc_html__( 'Server (host)', 'grs-smtp' ) . '</label></th><td>';
		$this->text( 'host', 'text', __( 'Es. smtps.aruba.it', 'grs-smtp' ), 'spellcheck="false"' );
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Cifratura', 'grs-smtp' ) . '</th><td><fieldset>';
		list( $enc_disabled, $enc_note ) = $this->locked( 'encryption' );
		$encryptions                     = array(
			'ssl'  => __( 'SSL (porta 465)', 'grs-smtp' ),
			'tls'  => __( 'TLS / STARTTLS (porta 587)', 'grs-smtp' ),
			'none' => __( 'Nessuna', 'grs-smtp' ),
		);
		foreach ( $encryptions as $value => $label ) {
			echo '<label class="grs-smtp__radio"><input type="radio" name="' . esc_attr( $option ) . '[encryption]" value="' . esc_attr( $value ) . '"' . checked( $settings['encryption'], $value, false ) . esc_attr( $enc_disabled ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo wp_kses_post( $enc_note );
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="grs-smtp-port">' . esc_html__( 'Porta', 'grs-smtp' ) . '</label></th><td>';
		$this->text( 'port', 'number', '', 'min="1" max="65535"', 'small-text' );
		echo '</td></tr>';

		echo '<tr class="grs-smtp__row-autotls"><th scope="row">' . esc_html__( 'TLS automatico', 'grs-smtp' ) . '</th><td>';
		$this->checkbox( 'auto_tls', __( 'Usa TLS se il server lo offre', 'grs-smtp' ), __( 'Lascia attivo. Disattiva solo se il server dichiara TLS ma il certificato non è valido.', 'grs-smtp' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Autenticazione', 'grs-smtp' ) . '</th><td>';
		$this->checkbox( 'auth', __( 'Il server richiede utente e password', 'grs-smtp' ) );
		echo '</td></tr>';

		echo '<tr class="grs-smtp__row-auth"><th scope="row"><label for="grs-smtp-username">' . esc_html__( 'Utente', 'grs-smtp' ) . '</label></th><td>';
		$this->text( 'username', 'text', __( 'Di solito l\'indirizzo email completo.', 'grs-smtp' ), 'spellcheck="false" autocomplete="off"' );
		echo '</td></tr>';

		echo '<tr class="grs-smtp__row-auth"><th scope="row"><label for="grs-smtp-password">' . esc_html__( 'Password', 'grs-smtp' ) . '</label></th><td>';
		if ( Settings::is_constant( 'password' ) ) {
			echo '<input type="password" class="regular-text" id="grs-smtp-password" value="" placeholder="••••••••" disabled>';
			echo '<p class="description grs-smtp__locked">' . esc_html__( 'Definita in wp-config.php.', 'grs-smtp' ) . '</p>';
		} else {
			$placeholder = Settings::has_password() ? __( '•••••••• (salvata, lascia vuoto per non cambiarla)', 'grs-smtp' ) : '';
			echo '<input type="password" class="regular-text" name="' . esc_attr( $option ) . '[password]" id="grs-smtp-password" value="" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="new-password">';
			if ( Settings::has_password() ) {
				echo '<p><label><input type="checkbox" name="' . esc_attr( $option ) . '[password_clear]" value="1"> ' . esc_html__( 'Rimuovi la password salvata', 'grs-smtp' ) . '</label></p>';
			}
			$desc = Crypto::available() ? __( 'Salvata cifrata nel database. In alternativa definiscila in wp-config.php con la costante GRS_SMTP_PASS.', 'grs-smtp' ) : __( 'OpenSSL non è disponibile su questo server: la password viene salvata solo offuscata. Meglio definirla in wp-config.php con la costante GRS_SMTP_PASS.', 'grs-smtp' );
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
		echo '</table>';

		// Mittente.
		echo '<h2>' . esc_html__( 'Mittente', 'grs-smtp' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="grs-smtp-from_email">' . esc_html__( 'Email mittente', 'grs-smtp' ) . '</label></th><td>';
		$this->text( 'from_email', 'email', __( 'Usa un indirizzo del dominio del cliente, meglio se uguale all\'utente SMTP. Se vuoto viene usato l\'utente SMTP.', 'grs-smtp' ) );
		echo '<div class="grs-smtp__sub">';
		$this->checkbox( 'force_from_email', __( 'Forza questo indirizzo per tutte le email', 'grs-smtp' ), __( 'Consigliato: evita che plugin di terze parti usino mittenti che il server SMTP rifiuterebbe. Se disattivato viene sostituito solo l\'indirizzo predefinito wordpress@.', 'grs-smtp' ) );
		echo '</div></td></tr>';

		echo '<tr><th scope="row"><label for="grs-smtp-from_name">' . esc_html__( 'Nome mittente', 'grs-smtp' ) . '</label></th><td>';
		/* translators: %s: nome del sito. */
		$this->text( 'from_name', 'text', sprintf( __( 'Es. il nome dell\'azienda. Se vuoto viene usato il nome del sito (%s), mai "WordPress".', 'grs-smtp' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ) );
		echo '<div class="grs-smtp__sub">';
		$this->checkbox( 'force_from_name', __( 'Forza questo nome per tutte le email', 'grs-smtp' ), __( 'Se disattivato i plugin che impostano un loro nome (es. WooCommerce) lo mantengono; viene sostituito solo "WordPress".', 'grs-smtp' ) );
		echo '</div></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Return-Path', 'grs-smtp' ) . '</th><td>';
		$this->checkbox( 'return_path', __( 'Allinea il Return-Path al mittente', 'grs-smtp' ), __( 'Le notifiche di mancata consegna tornano all\'indirizzo mittente.', 'grs-smtp' ) );
		echo '</td></tr>';
		echo '</table>';

		// Log.
		echo '<h2>' . esc_html__( 'Log', 'grs-smtp' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Registro invii', 'grs-smtp' ) . '</th><td>';
		$this->checkbox( 'log_enabled', __( 'Registra le email inviate', 'grs-smtp' ) );
		echo '<div class="grs-smtp__sub">';
		$this->checkbox( 'log_content', __( 'Salva anche il contenuto', 'grs-smtp' ), __( 'Necessario per vedere il testo e reinviare. Le email possono contenere dati personali e link di reset password: tieni la conservazione breve.', 'grs-smtp' ) );
		echo '</div></td></tr>';

		echo '<tr><th scope="row"><label for="grs-smtp-log_retention">' . esc_html__( 'Conservazione', 'grs-smtp' ) . '</label></th><td>';
		echo '<select name="' . esc_attr( $option ) . '[log_retention]" id="grs-smtp-log_retention">';
		foreach ( Settings::RETENTION_CHOICES as $days ) {
			/* translators: %d: numero di giorni. */
			$label = 0 === $days ? __( 'Non eliminare mai', 'grs-smtp' ) : sprintf( _n( '%d giorno', '%d giorni', $days, 'grs-smtp' ), $days );
			echo '<option value="' . esc_attr( (string) $days ) . '"' . selected( (int) $settings['log_retention'], $days, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '</table>';

		// Avvisi.
		echo '<h2>' . esc_html__( 'Avvisi di errore', 'grs-smtp' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Avviso via email', 'grs-smtp' ) . '</th><td>';
		$this->checkbox( 'alert_enabled', __( 'Avvisami quando un invio fallisce', 'grs-smtp' ), __( 'Massimo un avviso all\'ora, inviato senza SMTP. In bacheca compare comunque un avviso agli amministratori.', 'grs-smtp' ) );
		echo '<div class="grs-smtp__sub">';
		$this->text( 'alert_email', 'email', __( 'Indirizzo che riceve gli avvisi (meglio di un altro dominio, es. il tuo di assistenza).', 'grs-smtp' ) );
		echo '</div></td></tr>';
		echo '</table>';

		// Avanzate.
		echo '<h2>' . esc_html__( 'Avanzate', 'grs-smtp' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Modalità staging', 'grs-smtp' ) . '</th><td>';
		$this->checkbox( 'disable_delivery', __( 'Blocca tutti gli invii', 'grs-smtp' ), __( 'Per copie di sviluppo del sito: nessuna email viene consegnata, restano solo nel log. Ai plugin l\'invio risulta riuscito.', 'grs-smtp' ) );
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Disinstallazione', 'grs-smtp' ) . '</th><td>';
		$this->checkbox( 'keep_data', __( 'Conserva impostazioni e log se il plugin viene eliminato', 'grs-smtp' ) );
		echo '</td></tr>';
		echo '</table>';

		submit_button( __( 'Salva impostazioni', 'grs-smtp' ) );
		echo '</form>';
	}

	/**
	 * Scheda Email di test.
	 *
	 * @return void
	 */
	private function render_test() {
		$user = wp_get_current_user();

		echo '<div class="grs-smtp__card">';
		echo '<h2>' . esc_html__( 'Invia un\'email di test', 'grs-smtp' ) . '</h2>';
		echo '<p>' . esc_html__( 'Verifica che le impostazioni funzionino. In caso di errore ti mostro cosa controllare e il dettaglio della connessione (credenziali e contenuto sono nascosti).', 'grs-smtp' ) . '</p>';
		echo '<form id="grs-smtp-test-form" class="grs-smtp__test-form">';
		echo '<label for="grs-smtp-test-to" class="screen-reader-text">' . esc_html__( 'Destinatario', 'grs-smtp' ) . '</label>';
		echo '<input type="email" id="grs-smtp-test-to" class="regular-text" required value="' . esc_attr( $user->user_email ) . '"> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Invia test', 'grs-smtp' ) . '</button>';
		echo '</form>';
		echo '<div id="grs-smtp-test-result" class="grs-smtp__result" aria-live="polite"></div>';
		echo '<p class="description">' . esc_html__( 'Suggerimento: per controllare anche SPF, DKIM e punteggio spam invia il test all\'indirizzo che ti fornisce mail-tester.com.', 'grs-smtp' ) . '</p>';
		echo '</div>';
	}

	/**
	 * AJAX: invio test.
	 *
	 * @return void
	 */
	public function ajax_test() {
		check_ajax_referer( 'grs_smtp_test', 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'grs-smtp' ) ), 403 );
		}

		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Indirizzo email non valido.', 'grs-smtp' ) ) );
		}

		$result = ( new Tester() )->send( $to );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	/**
	 * Scheda Log.
	 *
	 * @return void
	 */
	private function render_log() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Visualizzazione.
		$view_id = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
		if ( $view_id ) {
			$this->render_log_detail( $view_id );
			return;
		}

		if ( ! Settings::get( 'log_enabled' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Il log è disattivato nelle impostazioni: le nuove email non vengono registrate.', 'grs-smtp' ) . '</p></div>';
		}

		$table = new Log_Table();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="log">';
		$table->views();
		$table->search_box( __( 'Cerca', 'grs-smtp' ), 'grs-smtp-search' );
		$table->display();
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="grs-smtp__clear" data-grs-confirm="all">';
		echo '<input type="hidden" name="action" value="grs_smtp_log">';
		echo '<input type="hidden" name="do" value="clear">';
		wp_nonce_field( 'grs_smtp_log_clear' );
		submit_button( __( 'Svuota tutto il log', 'grs-smtp' ), 'delete', 'submit', false );
		$days = (int) Settings::get( 'log_retention' );
		if ( $days > 0 ) {
			/* translators: %d: giorni. */
			echo ' <span class="description">' . esc_html( sprintf( __( 'Le righe più vecchie di %d giorni vengono eliminate automaticamente.', 'grs-smtp' ), $days ) ) . '</span>';
		}
		echo '</form>';
	}

	/**
	 * Dettaglio di un'email.
	 *
	 * @param int $id ID.
	 * @return void
	 */
	private function render_log_detail( $id ) {
		$row = Logger::get( $id );
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'log' ) ) ) . '">&larr; ' . esc_html__( 'Torna al log', 'grs-smtp' ) . '</a></p>';

		if ( ! $row ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Email non trovata nel log.', 'grs-smtp' ) . '</p></div>';
			return;
		}

		$files       = Logger::files_of( $row );
		$attachments = array_merge( array_values( $files['files'] ), array_values( $files['embeds'] ) );

		echo '<div class="grs-smtp__card">';
		echo '<table class="widefat striped grs-smtp__detail"><tbody>';
		echo '<tr><th>' . esc_html__( 'Data', 'grs-smtp' ) . '</th><td>' . esc_html( get_date_from_gmt( $row->created_at, 'd/m/Y H:i:s' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Stato', 'grs-smtp' ) . '</th><td>' . Log_Table::status_html( $row->status ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escape nel metodo.
		if ( '' !== (string) $row->error ) {
			$hint = Tester::hint( $row->error );
			echo '<tr><th>' . esc_html__( 'Errore', 'grs-smtp' ) . '</th><td><code>' . esc_html( $row->error ) . '</code>' . ( '' !== $hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : '' ) . '</td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Destinatario', 'grs-smtp' ) . '</th><td>' . esc_html( $row->to_email ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Oggetto', 'grs-smtp' ) . '</th><td>' . esc_html( $row->subject ) . '</td></tr>';
		if ( '' !== (string) $row->headers ) {
			echo '<tr><th>' . esc_html__( 'Intestazioni', 'grs-smtp' ) . '</th><td><pre class="grs-smtp__pre">' . esc_html( $row->headers ) . '</pre></td></tr>';
		}
		if ( $attachments ) {
			echo '<tr><th>' . esc_html__( 'Allegati', 'grs-smtp' ) . '</th><td>';
			foreach ( $attachments as $file ) {
				echo esc_html( wp_basename( (string) $file ) ) . ( is_readable( (string) $file ) ? '' : ' <em>(' . esc_html__( 'file non più presente', 'grs-smtp' ) . ')</em>' ) . '<br>';
			}
			echo '</td></tr>';
		}
		if ( (int) $row->resent_from > 0 ) {
			echo '<tr><th>' . esc_html__( 'Reinvio di', 'grs-smtp' ) . '</th><td><a href="' . esc_url(
				self::url(
					array(
						'tab'  => 'log',
						'view' => (int) $row->resent_from,
					)
				)
			) . '">#' . esc_html( (string) $row->resent_from ) . '</a></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Contenuto', 'grs-smtp' ) . '</h3>';
		$message = (string) $row->message;
		if ( '' === $message ) {
			echo '<p class="description">' . esc_html__( 'Contenuto non salvato (opzione disattivata).', 'grs-smtp' ) . '</p>';
		} elseif ( self::is_html( $message, (string) $row->headers ) ) {
			// Iframe senza script né form: l'HTML dell'email non può agire sulla bacheca.
			echo '<iframe class="grs-smtp__preview" sandbox="" title="' . esc_attr__( 'Anteprima email', 'grs-smtp' ) . '" srcdoc="' . esc_attr( $message ) . '"></iframe>';
		} else {
			echo '<pre class="grs-smtp__pre grs-smtp__pre--body">' . esc_html( $message ) . '</pre>';
		}

		echo '<p class="grs-smtp__actions">';
		if ( '' !== $message ) {
			echo '<a class="button button-primary" href="' . esc_url( self::log_action_url( 'resend', (int) $row->id ) ) . '">' . esc_html__( 'Reinvia', 'grs-smtp' ) . '</a> ';
		}
		echo '<a class="button grs-smtp__delete" data-grs-confirm="1" href="' . esc_url( self::log_action_url( 'delete', (int) $row->id ) ) . '">' . esc_html__( 'Elimina', 'grs-smtp' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Il contenuto è HTML.
	 *
	 * @param string $message Corpo.
	 * @param string $headers Intestazioni.
	 * @return bool
	 */
	private static function is_html( $message, $headers ) {
		if ( false !== stripos( $headers, 'text/html' ) ) {
			return true;
		}
		return (bool) preg_match( '/<(html|body|p|div|table|br)[\s>\/]/i', $message );
	}

	/**
	 * URL firmato per un'azione sul log.
	 *
	 * @param string $task Azione (resend|delete).
	 * @param int    $id   ID.
	 * @return string
	 */
	public static function log_action_url( $task, $id ) {
		$url = add_query_arg(
			array(
				'action' => 'grs_smtp_log',
				'do'     => $task,
				'id'     => (int) $id,
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'grs_smtp_log_' . $task . '_' . (int) $id );
	}

	/**
	 * Azioni sul log (admin-post).
	 *
	 * @return void
	 */
	public function handle_log_action() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'grs-smtp' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verificato per ogni azione qui sotto.
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		$id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
		// phpcs:enable

		$args = array( 'tab' => 'log' );

		switch ( $do ) {
			case 'resend':
				check_admin_referer( 'grs_smtp_log_resend_' . $id );
				$result          = Logger::resend( $id );
				$args['grs_msg'] = true === $result ? 'resent' : ( 'grs_smtp_no_content' === $result->get_error_code() ? 'no_content' : 'resend_failed' );
				break;

			case 'delete':
				check_admin_referer( 'grs_smtp_log_delete_' . $id );
				Logger::delete( array( $id ) );
				$args['grs_msg'] = 'deleted';
				break;

			case 'clear':
				check_admin_referer( 'grs_smtp_log_clear' );
				Logger::clear();
				$args['grs_msg'] = 'cleared';
				break;

			default:
				wp_die( esc_html__( 'Azione non valida.', 'grs-smtp' ), 400 );
		}

		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Scheda Diagnostica.
	 *
	 * @return void
	 */
	private function render_diagnostics() {
		// DNS.
		$from_email = Mailer::from_email();
		$domain     = '' !== $from_email ? substr( (string) strrchr( $from_email, '@' ), 1 ) : '';

		echo '<div class="grs-smtp__card">';
		echo '<h2>' . esc_html__( 'Controllo DNS del dominio mittente', 'grs-smtp' ) . '</h2>';

		if ( '' === $domain ) {
			echo '<p>' . esc_html__( 'Configura prima l\'email mittente o l\'utente SMTP.', 'grs-smtp' ) . '</p>';
		} else {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verificato sotto con wp_verify_nonce.
			$run      = isset( $_GET['dns'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'grs_smtp_dns' );
			$selector = isset( $_GET['selector'] ) ? sanitize_text_field( wp_unslash( $_GET['selector'] ) ) : '';
			// phpcs:enable

			echo '<form method="get" class="grs-smtp__dns-form">';
			echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
			echo '<input type="hidden" name="tab" value="diagnostics">';
			echo '<input type="hidden" name="dns" value="1">';
			wp_nonce_field( 'grs_smtp_dns', '_wpnonce', false );
			/* translators: %s: dominio. */
			echo '<p>' . esc_html( sprintf( __( 'Dominio controllato: %s', 'grs-smtp' ), $domain ) ) . '</p>';
			echo '<label for="grs-smtp-selector">' . esc_html__( 'Selettore DKIM (facoltativo)', 'grs-smtp' ) . '</label> ';
			echo '<input type="text" id="grs-smtp-selector" name="selector" value="' . esc_attr( $selector ) . '" class="regular-text" spellcheck="false"> ';
			submit_button( __( 'Esegui controllo', 'grs-smtp' ), 'secondary', '', false );
			echo '</form>';

			if ( $run ) {
				$results = Dns_Check::run( $domain, $selector );
				echo '<table class="widefat striped grs-smtp__checks"><tbody>';
				foreach ( $results as $check ) {
					echo '<tr><td class="grs-smtp__check-status">' . self::status_icon( $check['status'] ) . '</td><th scope="row">' . esc_html( $check['label'] ) . '</th><td>' . esc_html( $check['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icona con escape nel metodo.
					if ( '' !== $check['detail'] ) {
						echo '<pre class="grs-smtp__pre">' . esc_html( $check['detail'] ) . '</pre>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
				echo '<p class="description">' . esc_html__( 'Controllo di base. Per una verifica completa invia un test a mail-tester.com.', 'grs-smtp' ) . '</p>';
			}
		}
		echo '</div>';

		// Ambiente.
		$state     = Notifier::state();
		$conflicts = Conflicts::active_plugins();
		$override  = Conflicts::wp_mail_override();
		$next_cron = wp_next_scheduled( Logger::CRON_HOOK );
		$repo      = Updater::repository();

		$rows = array(
			array( Settings::is_configured() ? 'ok' : 'warn', __( 'SMTP configurato', 'grs-smtp' ), Settings::is_configured() ? Settings::get( 'host' ) . ':' . Settings::get( 'port' ) : __( 'No', 'grs-smtp' ) ),
			array( Mailer::delivery_disabled() ? 'warn' : 'ok', __( 'Invio email', 'grs-smtp' ), Mailer::delivery_disabled() ? __( 'Bloccato (modalità staging)', 'grs-smtp' ) : __( 'Attivo', 'grs-smtp' ) ),
			array( Crypto::available() ? 'ok' : 'warn', __( 'Cifratura password', 'grs-smtp' ), Crypto::available() ? 'OpenSSL AES-256' : __( 'OpenSSL non disponibile', 'grs-smtp' ) ),
			array( Settings::password_unreadable() ? 'error' : 'ok', __( 'Password salvata', 'grs-smtp' ), Settings::password_unreadable() ? __( 'Non leggibile: inseriscila di nuovo', 'grs-smtp' ) : ( Settings::has_password() ? __( 'Presente', 'grs-smtp' ) : __( 'Assente', 'grs-smtp' ) ) ),
			array( $conflicts ? 'warn' : 'ok', __( 'Altri plugin SMTP', 'grs-smtp' ), $conflicts ? implode( ', ', $conflicts ) : __( 'Nessuno', 'grs-smtp' ) ),
			array( '' !== $override ? 'error' : 'ok', __( 'Funzione wp_mail()', 'grs-smtp' ), '' !== $override ? sprintf( /* translators: %s: file. */ __( 'Sostituita da %s: GRS SMTP potrebbe non essere usato.', 'grs-smtp' ), $override ) : __( 'Originale di WordPress', 'grs-smtp' ) ),
			array( (int) $state['count'] > 0 ? 'error' : 'ok', __( 'Errori consecutivi', 'grs-smtp' ), (string) (int) $state['count'] ),
			array( $next_cron ? 'ok' : 'warn', __( 'Pulizia automatica log', 'grs-smtp' ), $next_cron ? wp_date( 'd/m/Y H:i', $next_cron ) : __( 'Non pianificata', 'grs-smtp' ) ),
			array( '' !== $repo && Updater::library_present() ? 'ok' : 'info', __( 'Aggiornamenti automatici', 'grs-smtp' ), '' !== $repo && Updater::library_present() ? $repo : __( 'Non configurati', 'grs-smtp' ) ),
			array( 'info', __( 'Versioni', 'grs-smtp' ), 'WordPress ' . get_bloginfo( 'version' ) . ' · PHP ' . PHP_VERSION . ' · GRS SMTP ' . GRS_SMTP_VERSION ),
		);

		echo '<div class="grs-smtp__card">';
		echo '<h2>' . esc_html__( 'Stato', 'grs-smtp' ) . '</h2>';
		echo '<table class="widefat striped grs-smtp__checks"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td class="grs-smtp__check-status">' . self::status_icon( $row[0] ) . '</td><th scope="row">' . esc_html( $row[1] ) . '</th><td>' . esc_html( (string) $row[2] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icona con escape nel metodo.
		}
		echo '</tbody></table>';
		echo '</div>';

		// Costanti.
		echo '<div class="grs-smtp__card">';
		echo '<h2>' . esc_html__( 'Configurazione da wp-config.php (facoltativa)', 'grs-smtp' ) . '</h2>';
		echo '<p>' . esc_html__( 'Utile per non salvare la password nel database o per bloccare le impostazioni. Le costanti definite hanno la precedenza sui campi.', 'grs-smtp' ) . '</p>';
		$snippet = "define( 'GRS_SMTP_HOST', 'smtps.aruba.it' );\ndefine( 'GRS_SMTP_PORT', 465 );\ndefine( 'GRS_SMTP_ENCRYPTION', 'ssl' ); // ssl, tls, none\ndefine( 'GRS_SMTP_AUTH', true );\ndefine( 'GRS_SMTP_USER', 'info@dominio.it' );\ndefine( 'GRS_SMTP_PASS', 'password' );\ndefine( 'GRS_SMTP_FROM_EMAIL', 'info@dominio.it' );\ndefine( 'GRS_SMTP_FROM_NAME', 'Nome Azienda' );\ndefine( 'GRS_SMTP_DISABLE_DELIVERY', true ); // solo su staging";
		echo '<pre class="grs-smtp__pre">' . esc_html( $snippet ) . '</pre>';
		echo '</div>';
	}

	/**
	 * Icona di stato.
	 *
	 * @param string $status ok|warn|error|info.
	 * @return string
	 */
	private static function status_icon( $status ) {
		$map  = array(
			'ok'    => array( 'yes-alt', __( 'OK', 'grs-smtp' ) ),
			'warn'  => array( 'warning', __( 'Attenzione', 'grs-smtp' ) ),
			'error' => array( 'dismiss', __( 'Errore', 'grs-smtp' ) ),
			'info'  => array( 'info', __( 'Info', 'grs-smtp' ) ),
		);
		$item = isset( $map[ $status ] ) ? $map[ $status ] : $map['info'];
		return '<span class="dashicons dashicons-' . esc_attr( $item[0] ) . ' grs-smtp__icon grs-smtp__icon--' . esc_attr( $status ) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $item[1] ) . '</span>';
	}
}
