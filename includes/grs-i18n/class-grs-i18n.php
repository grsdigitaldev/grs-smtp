<?php
/**
 * GRS i18n - ponte tra i plugin GRS e i sistemi di traduzione
 * (GRS Loco o altri tramite filtri, WPML String Translation, Polylang).
 *
 * Serve SOLO per i testi inseriti dall'utente nelle impostazioni e mostrati
 * ai visitatori o inviati per email. I testi dell'interfaccia del plugin
 * usano gettext (__(), esc_html__()...) con il text domain del plugin.
 *
 * @package GRSDigital\I18n
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GRS_I18n', false ) ) {

	/**
	 * API statica del ponte traduzioni.
	 *
	 * Uso tipico in un plugin:
	 *   - registrazione (bacheca, dopo il salvataggio delle impostazioni e su admin_init):
	 *       GRS_I18n::register( 'GRS SMTP', 'from_name', $settings['from_name'] );
	 *   - traduzione (al momento dell'uso):
	 *       $name = GRS_I18n::translate( $settings['from_name'], 'GRS SMTP', 'from_name' );
	 *
	 * Il contesto è il nome leggibile del plugin; il nome identifica il campo
	 * (es. la chiave dell'opzione) e non deve cambiare tra le versioni.
	 */
	final class GRS_I18n {

		/**
		 * Versione caricata del componente.
		 *
		 * @var string
		 */
		private static $version = '';

		/**
		 * Avvio.
		 *
		 * @param string $version Versione scelta dal loader.
		 * @return void
		 */
		public static function init( $version ) {
			self::$version = (string) $version;
		}

		/**
		 * Versione caricata.
		 *
		 * @return string
		 */
		public static function version() {
			return self::$version;
		}

		/**
		 * Registra un testo traducibile presso il sistema di traduzione attivo.
		 *
		 * Da chiamare in bacheca (es. su admin_init e dopo il salvataggio):
		 * Polylang mostra solo i testi registrati nella richiesta corrente.
		 *
		 * @param string $context   Contesto leggibile (nome del plugin, es. "GRS SMTP").
		 * @param string $name      Nome stabile del campo (es. "from_name").
		 * @param string $value     Testo nella lingua predefinita del sito.
		 * @param bool   $multiline Testo su più righe (per l'interfaccia di traduzione).
		 * @return void
		 */
		public static function register( $context, $name, $value, $multiline = false ) {
			$context = (string) $context;
			$name    = (string) $name;
			$value   = (string) $value;
			if ( '' === trim( $value ) || '' === $context || '' === $name ) {
				return;
			}

			/**
			 * Permette a un sistema di traduzione (es. GRS Loco) di registrare il testo.
			 *
			 * @param string $context   Contesto.
			 * @param string $name      Nome del campo.
			 * @param string $value     Testo originale.
			 * @param bool   $multiline Su più righe.
			 */
			do_action( 'grs_i18n_register', $context, $name, $value, (bool) $multiline );

			if ( self::has_wpml_st() ) {
				do_action( 'wpml_register_single_string', $context, $name, $value );
			} elseif ( function_exists( 'pll_register_string' ) ) {
				pll_register_string( $context . ' - ' . $name, $value, $context, (bool) $multiline );
			}
		}

		/**
		 * Registra più testi dello stesso contesto.
		 *
		 * @param string               $context   Contesto.
		 * @param array<string,string> $strings   Nome => testo.
		 * @param string[]             $multiline Nomi dei testi su più righe.
		 * @return void
		 */
		public static function register_many( $context, array $strings, array $multiline = array() ) {
			foreach ( $strings as $name => $value ) {
				if ( is_scalar( $value ) ) {
					self::register( $context, (string) $name, (string) $value, in_array( $name, $multiline, true ) );
				}
			}
		}

		/**
		 * Restituisce il testo tradotto nella lingua richiesta (o in quella corrente).
		 *
		 * Senza sistemi di traduzione attivi restituisce il testo originale:
		 * il plugin funziona sempre, anche su siti monolingua.
		 *
		 * @param string      $value   Testo originale (valore salvato nelle impostazioni).
		 * @param string      $context Contesto usato nella registrazione.
		 * @param string      $name    Nome usato nella registrazione.
		 * @param string|null $lang    Codice lingua (es. "en"); null = lingua corrente.
		 * @return string
		 */
		public static function translate( $value, $context, $name, $lang = null ) {
			$value = (string) $value;
			if ( '' === trim( $value ) ) {
				return $value;
			}
			$context = (string) $context;
			$name    = (string) $name;
			$lang    = ( null === $lang || '' === $lang ) ? null : (string) $lang;

			/**
			 * Permette a un sistema di traduzione (es. GRS Loco) di fornire la traduzione.
			 * Restituire una stringa per usarla, null per lasciar decidere ai sistemi successivi.
			 *
			 * @param string|null $translated Traduzione o null.
			 * @param string      $value      Testo originale.
			 * @param string      $context    Contesto.
			 * @param string      $name       Nome del campo.
			 * @param string|null $lang       Lingua richiesta.
			 */
			$translated = apply_filters( 'grs_i18n_translate', null, $value, $context, $name, $lang );
			if ( is_string( $translated ) ) {
				return $translated;
			}

			if ( self::has_wpml_st() ) {
				$result = apply_filters( 'wpml_translate_single_string', $value, $context, $name, $lang );
				return is_string( $result ) ? $result : $value;
			}

			if ( function_exists( 'pll__' ) ) {
				if ( null !== $lang && function_exists( 'pll_translate_string' ) ) {
					$result = pll_translate_string( $value, $lang );
				} else {
					$result = pll__( $value );
				}
				return is_string( $result ) ? $result : $value;
			}

			return $value;
		}

		/**
		 * Codice della lingua corrente (es. "it", "en").
		 *
		 * @return string
		 */
		public static function current_language() {
			/**
			 * Permette a un sistema di traduzione (es. GRS Loco) di indicare la lingua corrente.
			 *
			 * @param string|null $lang Codice lingua o null.
			 */
			$lang = apply_filters( 'grs_i18n_current_language', null );
			if ( is_string( $lang ) && '' !== $lang ) {
				return $lang;
			}
			if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
				$lang = apply_filters( 'wpml_current_language', null );
				if ( is_string( $lang ) && '' !== $lang && 'all' !== $lang ) {
					return $lang;
				}
			}
			if ( function_exists( 'pll_current_language' ) ) {
				$lang = pll_current_language();
				if ( is_string( $lang ) && '' !== $lang ) {
					return $lang;
				}
			}
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
			return strtolower( substr( (string) $locale, 0, 2 ) );
		}

		/**
		 * Il sito è multilingua (GRS Loco, WPML o Polylang attivi)?
		 *
		 * @return bool
		 */
		public static function is_multilingual() {
			return (bool) apply_filters(
				'grs_i18n_is_multilingual',
				defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_current_language' )
			);
		}

		/**
		 * WPML con String Translation attivo.
		 *
		 * @return bool
		 */
		private static function has_wpml_st() {
			return defined( 'ICL_SITEPRESS_VERSION' ) && defined( 'WPML_ST_VERSION' );
		}
	}
}
