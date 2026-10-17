<?php
/**
 * Cifratura della password SMTP salvata nel database.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * AES-256-CTR con chiave derivata dalle chiavi di wp-config.php.
 * Protegge la password da esportazioni o letture del solo database: chi ha accesso
 * anche ai file del sito può comunque decifrarla (limite di ogni cifratura lato WordPress).
 */
class Crypto {

	const PREFIX = 'grs1:';
	const METHOD = 'aes-256-ctr';

	/**
	 * Disponibilità di OpenSSL.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'openssl_encrypt' ) && in_array( self::METHOD, openssl_get_cipher_methods(), true );
	}

	/**
	 * La chiave dipende dalle chiavi di wp-config.php (e cambia se vengono rigenerate).
	 *
	 * @return bool
	 */
	public static function uses_salts() {
		return ! ( defined( 'GRS_SMTP_ENCRYPTION_KEY' ) && '' !== GRS_SMTP_ENCRYPTION_KEY );
	}

	/**
	 * Chiave di cifratura.
	 *
	 * @param array|null $salts Valori nuovi di LOGGED_IN_KEY e LOGGED_IN_SALT (rigenerazione in corso); null = costanti attuali.
	 * @return string
	 */
	private static function key( $salts = null ) {
		if ( ! self::uses_salts() ) {
			$material = (string) GRS_SMTP_ENCRYPTION_KEY;
		} elseif ( is_array( $salts ) && ! empty( $salts['LOGGED_IN_KEY'] ) ) {
			$material = (string) $salts['LOGGED_IN_KEY'] . ( isset( $salts['LOGGED_IN_SALT'] ) ? (string) $salts['LOGGED_IN_SALT'] : '' );
		} elseif ( defined( 'LOGGED_IN_KEY' ) && '' !== LOGGED_IN_KEY ) {
			$material = LOGGED_IN_KEY . ( defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : '' );
		} else {
			// Ultima risorsa: installazioni senza chiavi in wp-config.php.
			$material = 'grs-smtp-' . wp_salt( 'logged_in' );
		}
		return hash( 'sha256', $material, true );
	}

	/**
	 * Cifra un valore.
	 *
	 * @param string     $value Testo in chiaro.
	 * @param array|null $salts Chiavi nuove da usare al posto di quelle attuali (vedi key()).
	 * @return string
	 */
	public static function encrypt( $value, $salts = null ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( ! self::available() ) {
			// Senza OpenSSL salviamo offuscato (non cifrato) e lo segnaliamo in Diagnostica.
			return 'b64:' . base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fallback dichiarato in Diagnostica.
		}

		$iv_length = openssl_cipher_iv_length( self::METHOD );
		$iv        = random_bytes( $iv_length );
		$key       = self::key( $salts );
		$cipher    = openssl_encrypt( $value, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return '';
		}
		$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );

		return self::PREFIX . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Codifica del dato cifrato.
	}

	/**
	 * Decifra un valore.
	 *
	 * @param string $value Valore salvato.
	 * @return string|false False se non decifrabile (chiavi cambiate o dato alterato).
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( 0 === strpos( $value, 'b64:' ) ) {
			$decoded = base64_decode( substr( $value, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Fallback senza OpenSSL.
			return false === $decoded ? false : $decoded;
		}
		if ( 0 !== strpos( $value, self::PREFIX ) || ! self::available() ) {
			return false;
		}

		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodifica del dato cifrato.
		if ( false === $raw ) {
			return false;
		}

		$iv_length = openssl_cipher_iv_length( self::METHOD );
		if ( strlen( $raw ) <= $iv_length + 32 ) {
			return false;
		}

		$iv     = substr( $raw, 0, $iv_length );
		$mac    = substr( $raw, $iv_length, 32 );
		$cipher = substr( $raw, $iv_length + 32 );
		$key    = self::key();

		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $key, true ), $mac ) ) {
			return false;
		}

		$plain = openssl_decrypt( $cipher, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? false : $plain;
	}
}
