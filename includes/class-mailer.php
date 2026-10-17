<?php
/**
 * Configura PHPMailer per l'invio tramite SMTP e gestisce il mittente.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Aggancio a phpmailer_init: wp_mail() resta quello di WordPress, massima compatibilità.
 */
class Mailer {

	/**
	 * Se true il prossimo invio usa il metodo predefinito del server (usato per l'avviso di errore).
	 *
	 * @var bool
	 */
	private static $bypass = false;

	/**
	 * Righe di debug SMTP raccolte durante l'email di test; null se la raccolta è spenta.
	 *
	 * @var array|null
	 */
	private static $debug = null;

	/**
	 * Stato interno del filtro di debug (credenziali o contenuto in corso).
	 *
	 * @var bool
	 */
	private static $debug_hiding = false;

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'phpmailer_init', array( $this, 'configure' ), 999 );
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), 999 );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), 999 );
		add_filter( 'pre_wp_mail', array( $this, 'maybe_block' ), 999 );
	}

	/**
	 * Attiva o disattiva l'invio senza SMTP.
	 *
	 * @param bool $bypass Stato.
	 * @return void
	 */
	public static function set_bypass( $bypass ) {
		self::$bypass = (bool) $bypass;
	}

	/**
	 * Invio senza SMTP attivo.
	 *
	 * @return bool
	 */
	public static function is_bypassed() {
		return self::$bypass;
	}

	/**
	 * Avvia la raccolta del debug SMTP.
	 *
	 * @return void
	 */
	public static function start_debug() {
		self::$debug        = array();
		self::$debug_hiding = false;
	}

	/**
	 * Termina la raccolta e restituisce le righe.
	 *
	 * @return string[]
	 */
	public static function stop_debug() {
		$lines       = is_array( self::$debug ) ? self::$debug : array();
		self::$debug = null;
		return $lines;
	}

	/**
	 * Invii bloccati (modalità staging).
	 *
	 * @return bool
	 */
	public static function delivery_disabled() {
		return (bool) Settings::get( 'disable_delivery' );
	}

	/**
	 * Indirizzo mittente effettivo.
	 *
	 * @return string
	 */
	public static function from_email() {
		$email = (string) Settings::get( 'from_email' );
		if ( '' === $email ) {
			$username = (string) Settings::get( 'username' );
			if ( is_email( $username ) ) {
				$email = $username;
			}
		}
		return $email;
	}

	/**
	 * Nome mittente effettivo.
	 *
	 * @return string
	 */
	public static function from_name() {
		$name = (string) Settings::get( 'from_name' );
		if ( '' === $name ) {
			$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
		return $name;
	}

	/**
	 * Configura PHPMailer.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Istanza.
	 * @return void
	 */
	public function configure( $phpmailer ) {
		// L'istanza di PHPMailer è condivisa tra gli invii: azzera sempre il debug.
		$phpmailer->SMTPDebug = 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Proprietà di PHPMailer.

		if ( self::$bypass || ! Settings::is_configured() ) {
			return;
		}

		$settings = Settings::all();

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Proprietà di PHPMailer.
		$phpmailer->isSMTP();
		$phpmailer->Host        = $settings['host'];
		$phpmailer->Port        = (int) $settings['port'];
		$phpmailer->SMTPSecure  = 'none' === $settings['encryption'] ? '' : $settings['encryption'];
		$phpmailer->SMTPAutoTLS = 'none' === $settings['encryption'] ? (bool) $settings['auto_tls'] : true;
		$phpmailer->Timeout     = (int) apply_filters( 'grs_smtp_timeout', 20 );

		if ( $settings['auth'] ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = (string) $settings['username'];
			$phpmailer->Password = Settings::get_password();
		} else {
			$phpmailer->SMTPAuth = false;
		}

		if ( $settings['return_path'] && is_email( $phpmailer->From ) ) {
			$phpmailer->Sender = $phpmailer->From;
		}

		if ( is_array( self::$debug ) ) {
			$phpmailer->SMTPDebug   = 3;
			$phpmailer->Debugoutput = array( __CLASS__, 'collect_debug' );
		}
		// phpcs:enable

		/**
		 * Consente ritocchi finali a PHPMailer dopo la configurazione GRS SMTP.
		 *
		 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Istanza.
		 */
		do_action( 'grs_smtp_phpmailer_configured', $phpmailer );
	}

	/**
	 * Raccoglie il debug SMTP nascondendo credenziali e contenuto del messaggio.
	 *
	 * @param string $line  Riga di debug.
	 * @param int    $level Livello.
	 * @return void
	 */
	public static function collect_debug( $line, $level = 0 ) {
		unset( $level );
		if ( ! is_array( self::$debug ) ) {
			return;
		}
		$line = trim( (string) $line );
		if ( '' === $line ) {
			return;
		}

		$client_prefix = 'CLIENT -> SERVER:';
		if ( 0 === strpos( $line, $client_prefix ) ) {
			$command = trim( substr( $line, strlen( $client_prefix ) ) );
			$verb    = strtoupper( (string) strtok( $command, ' ' ) );
			$safe    = array( 'EHLO', 'HELO', 'STARTTLS', 'MAIL', 'RCPT', 'DATA', 'QUIT', 'RSET', 'NOOP' );

			if ( 'AUTH' === $verb ) {
				$parts              = explode( ' ', $command );
				$mechanism          = isset( $parts[1] ) ? $parts[1] : '';
				self::$debug[]      = $client_prefix . ' AUTH ' . $mechanism . ' ' . __( '[credenziali nascoste]', 'grs-smtp' );
				self::$debug_hiding = true;
				return;
			}
			if ( in_array( $verb, $safe, true ) ) {
				self::$debug[]      = $client_prefix . ' ' . $command;
				self::$debug_hiding = false;
				return;
			}
			// Credenziali (base64) o righe del messaggio: mai mostrate.
			if ( ! self::$debug_hiding ) {
				self::$debug[]      = $client_prefix . ' ' . __( '[contenuto nascosto]', 'grs-smtp' );
				self::$debug_hiding = true;
			}
			return;
		}

		// Qualsiasi riga del server o di connessione chiude un blocco nascosto.
		self::$debug_hiding = false;
		self::$debug[]      = $line;
	}

	/**
	 * Indirizzo mittente.
	 *
	 * @param string $from Indirizzo proposto da WordPress o da un altro plugin.
	 * @return string
	 */
	public function filter_from_email( $from ) {
		$ours = self::from_email();
		if ( '' === $ours ) {
			return $from;
		}
		$from = (string) $from;
		if ( Settings::get( 'force_from_email' ) || '' === $from || 0 === strpos( strtolower( $from ), 'wordpress@' ) ) {
			return $ours;
		}
		return $from;
	}

	/**
	 * Nome mittente.
	 *
	 * @param string $name Nome proposto.
	 * @return string
	 */
	public function filter_from_name( $name ) {
		$name = (string) $name;
		if ( Settings::get( 'force_from_name' ) || '' === $name || 'WordPress' === $name ) {
			return self::from_name();
		}
		return $name;
	}

	/**
	 * Modalità staging: blocca l'invio senza segnalare errore al plugin chiamante.
	 *
	 * @param null|bool $result Risultato di altri filtri.
	 * @return null|bool
	 */
	public function maybe_block( $result ) {
		if ( null !== $result ) {
			return $result;
		}
		if ( self::delivery_disabled() ) {
			return true;
		}
		return $result;
	}
}
