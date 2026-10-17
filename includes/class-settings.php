<?php
/**
 * Lettura, scrittura e sanitizzazione delle impostazioni.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Gestisce l'opzione unica grs_smtp_settings e la password cifrata (opzione separata, non autoload).
 */
class Settings {

	const OPTION          = 'grs_smtp_settings';
	const OPTION_PASSWORD = 'grs_smtp_password';

	/**
	 * Mappa impostazione => costante di wp-config.php che la sovrascrive.
	 *
	 * @var array<string,string>
	 */
	const CONSTANTS = array(
		'host'             => 'GRS_SMTP_HOST',
		'port'             => 'GRS_SMTP_PORT',
		'encryption'       => 'GRS_SMTP_ENCRYPTION',
		'auth'             => 'GRS_SMTP_AUTH',
		'username'         => 'GRS_SMTP_USER',
		'from_email'       => 'GRS_SMTP_FROM_EMAIL',
		'from_name'        => 'GRS_SMTP_FROM_NAME',
		'disable_delivery' => 'GRS_SMTP_DISABLE_DELIVERY',
	);

	const PASSWORD_CONSTANT = 'GRS_SMTP_PASS';

	/**
	 * Giorni di conservazione del log ammessi (0 = mai).
	 *
	 * @var int[]
	 */
	const RETENTION_CHOICES = array( 7, 14, 30, 60, 90, 180, 365, 0 );

	/**
	 * Cache delle impostazioni per la richiesta corrente.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Valori predefiniti.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'provider'         => 'custom',
			'host'             => '',
			'port'             => 465,
			'encryption'       => 'ssl',
			'auto_tls'         => true,
			'auth'             => true,
			'username'         => '',
			'from_email'       => '',
			'from_name'        => '',
			'force_from_email' => true,
			'force_from_name'  => false,
			'return_path'      => true,
			'disable_delivery' => false,
			'log_enabled'      => true,
			'log_content'      => true,
			'log_retention'    => 30,
			'alert_enabled'    => false,
			'alert_email'      => '',
			'keep_data'        => false,
		);
	}

	/**
	 * Tutte le impostazioni effettive (opzione + costanti).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			$settings = array_merge( self::defaults(), $saved );

			foreach ( self::CONSTANTS as $key => $constant ) {
				if ( defined( $constant ) ) {
					$settings[ $key ] = constant( $constant );
				}
			}

			$settings['port']             = (int) $settings['port'];
			$settings['auth']             = (bool) $settings['auth'];
			$settings['disable_delivery'] = (bool) $settings['disable_delivery'];
			$settings['encryption']       = in_array( $settings['encryption'], array( 'none', 'ssl', 'tls' ), true ) ? $settings['encryption'] : 'none';

			self::$cache = $settings;
		}

		return self::$cache;
	}

	/**
	 * Singola impostazione.
	 *
	 * @param string $key Chiave.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Svuota la cache (dopo un salvataggio).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Indica se un'impostazione è definita in wp-config.php.
	 *
	 * @param string $key Chiave (oppure "password").
	 * @return bool
	 */
	public static function is_constant( $key ) {
		if ( 'password' === $key ) {
			return defined( self::PASSWORD_CONSTANT );
		}
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	/**
	 * Il plugin ha i dati minimi per inviare via SMTP.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( (string) self::get( 'host' ) );
	}

	/**
	 * Password SMTP in chiaro (solo per l'invio, mai da stampare).
	 *
	 * @return string
	 */
	public static function get_password() {
		if ( defined( self::PASSWORD_CONSTANT ) ) {
			return (string) constant( self::PASSWORD_CONSTANT );
		}
		$stored = get_option( self::OPTION_PASSWORD, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}
		$plain = Crypto::decrypt( $stored );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Esiste una password salvata.
	 *
	 * @return bool
	 */
	public static function has_password() {
		if ( defined( self::PASSWORD_CONSTANT ) ) {
			return true;
		}
		$stored = get_option( self::OPTION_PASSWORD, '' );
		return is_string( $stored ) && '' !== $stored;
	}

	/**
	 * La password salvata non è più decifrabile (es. chiavi di wp-config cambiate dopo una migrazione).
	 *
	 * @return bool
	 */
	public static function password_unreadable() {
		if ( defined( self::PASSWORD_CONSTANT ) ) {
			return false;
		}
		$stored = get_option( self::OPTION_PASSWORD, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}
		return false === Crypto::decrypt( $stored );
	}

	/**
	 * Spiegazione da aggiungere agli errori quando la password non è più decifrabile.
	 *
	 * @return string Vuoto se la password è leggibile o l'autenticazione è spenta.
	 */
	public static function failure_note() {
		if ( self::get( 'auth' ) && self::password_unreadable() ) {
			return __( 'La password SMTP salvata non è più leggibile perché le chiavi di sicurezza (SALT) di wp-config.php sono cambiate: inseriscila di nuovo in GRS DEVs → SMTP.', 'grs-smtp' );
		}
		return '';
	}

	/**
	 * Cifra di nuovo la password con le chiavi appena rigenerate da GRS Security.
	 *
	 * Hook: grs_security_salts_regenerated (GRS Security 1.0.1+), eseguito nella stessa richiesta
	 * in cui wp-config.php viene riscritto: le costanti in memoria sono ancora quelle vecchie,
	 * quindi la password si decifra con le vecchie chiavi e si cifra con le nuove.
	 *
	 * @param mixed $new_keys Nuovi valori, es. array( 'LOGGED_IN_KEY' => '...', 'LOGGED_IN_SALT' => '...' ).
	 * @return void
	 */
	public static function rekey_password( $new_keys ) {
		if ( ! is_array( $new_keys ) || empty( $new_keys['LOGGED_IN_KEY'] ) || ! Crypto::uses_salts() || defined( self::PASSWORD_CONSTANT ) ) {
			return;
		}
		$stored = get_option( self::OPTION_PASSWORD, '' );
		if ( ! is_string( $stored ) || 0 !== strpos( $stored, Crypto::PREFIX ) ) {
			return;
		}
		$plain = Crypto::decrypt( $stored );
		if ( false === $plain || '' === $plain ) {
			return;
		}
		$encrypted = Crypto::encrypt( $plain, $new_keys );
		if ( '' !== $encrypted ) {
			update_option( self::OPTION_PASSWORD, $encrypted, false );
		}
	}

	/**
	 * Salva la password cifrata (opzione non autoload).
	 *
	 * @param string $plain Password in chiaro.
	 * @return void
	 */
	public static function set_password( $plain ) {
		$encrypted = Crypto::encrypt( (string) $plain );
		if ( false === get_option( self::OPTION_PASSWORD, false ) ) {
			add_option( self::OPTION_PASSWORD, $encrypted, '', false );
		} else {
			update_option( self::OPTION_PASSWORD, $encrypted, false );
		}
	}

	/**
	 * Callback di sanitizzazione per register_setting().
	 *
	 * @param mixed $input Valori inviati.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$current  = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$current = array_merge( $defaults, $current );

		if ( ! is_array( $input ) ) {
			return $current;
		}

		// Password: gestita a parte, mai salvata nell'opzione principale.
		if ( ! empty( $input['password_clear'] ) ) {
			delete_option( self::OPTION_PASSWORD );
		} elseif ( isset( $input['password'] ) && '' !== (string) $input['password'] ) {
			self::set_password( (string) $input['password'] );
		}
		unset( $input['password'], $input['password_clear'] );

		$out = array();

		$providers       = Providers::all();
		$out['provider'] = isset( $input['provider'] ) && isset( $providers[ $input['provider'] ] ) ? (string) $input['provider'] : 'custom';

		$host        = isset( $input['host'] ) ? strtolower( trim( sanitize_text_field( (string) $input['host'] ) ) ) : '';
		$host        = (string) preg_replace( '#^[a-z]+://#', '', $host );
		$out['host'] = (string) preg_replace( '/[^a-z0-9.\-]/', '', $host );

		$port        = isset( $input['port'] ) ? absint( $input['port'] ) : 0;
		$out['port'] = ( $port > 0 && $port <= 65535 ) ? $port : $defaults['port'];

		$encryption        = isset( $input['encryption'] ) ? sanitize_key( (string) $input['encryption'] ) : 'none';
		$out['encryption'] = in_array( $encryption, array( 'none', 'ssl', 'tls' ), true ) ? $encryption : 'none';

		$out['username'] = isset( $input['username'] ) ? trim( sanitize_text_field( (string) $input['username'] ) ) : '';

		$out['from_email'] = isset( $input['from_email'] ) ? sanitize_email( (string) $input['from_email'] ) : '';
		$out['from_name']  = isset( $input['from_name'] ) ? sanitize_text_field( (string) $input['from_name'] ) : '';

		foreach ( array( 'auto_tls', 'auth', 'force_from_email', 'force_from_name', 'return_path', 'disable_delivery', 'log_enabled', 'log_content', 'alert_enabled', 'keep_data' ) as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}

		$retention            = isset( $input['log_retention'] ) ? (int) $input['log_retention'] : $defaults['log_retention'];
		$out['log_retention'] = in_array( $retention, self::RETENTION_CHOICES, true ) ? $retention : $defaults['log_retention'];

		$out['alert_email'] = isset( $input['alert_email'] ) ? sanitize_email( (string) $input['alert_email'] ) : '';
		if ( $out['alert_enabled'] && '' === $out['alert_email'] ) {
			$out['alert_enabled'] = false;
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, 'grs_smtp_alert_email', __( 'Avviso via email non attivato: inserisci un indirizzo valido.', 'grs-smtp' ) );
			}
		}

		if ( '' !== $out['from_email'] && ! is_email( $out['from_email'] ) ) {
			$out['from_email'] = '';
		}

		self::flush();

		return $out;
	}
}
