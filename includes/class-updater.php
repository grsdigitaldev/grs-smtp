<?php
/**
 * Aggiornamenti automatici da GitHub tramite Plugin Update Checker (YahnisElsts, MIT).
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Collega il plugin alle release del repository GitHub.
 *
 * Repository: costante GRS_SMTP_UPDATE_REPO o filtro grs_smtp_update_repository.
 * Repository privato: token in sola lettura nella costante GRS_SMTP_GITHUB_TOKEN (wp-config.php).
 */
class Updater {

	/**
	 * Repository pubblico delle release (dev/distribuzione-github.md). Vuoto = aggiornamenti disattivati.
	 */
	const DEFAULT_REPO = 'https://github.com/grsdigitaldev/grs-smtp';

	/**
	 * Registra il controllo aggiornamenti.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$repo = self::repository();
		if ( '' === $repo ) {
			return;
		}

		$loader = GRS_SMTP_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return;
		}
		require_once $loader;

		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		$checker = $factory::buildUpdateChecker( trailingslashit( $repo ), GRS_SMTP_FILE, 'grs-smtp' );

		// Solo lo zip allegato alla release (grs-smtp-x.y.z.zip, cartella grs-smtp/ alla radice).
		// "Richiesto": senza allegato nessun aggiornamento, mai il sorgente automatico di GitHub
		// (il repository può contenere solo README e LICENSE: installarlo romperebbe il plugin).
		if ( method_exists( $checker, 'getVcsApi' ) ) {
			$api = $checker->getVcsApi();
			if ( is_object( $api ) && method_exists( $api, 'enableReleaseAssets' ) ) {
				$require = '\\YahnisElsts\\PluginUpdateChecker\\v5p7\\Vcs\\Api::REQUIRE_RELEASE_ASSETS';
				$api->enableReleaseAssets( '/^grs-smtp-[0-9]+\.[0-9]+\.[0-9]+\.zip$/i', defined( $require ) ? constant( $require ) : 2 );
			}
		}

		// Solo release pubblicate: PUC altrimenti ripiega su tag e branch "main" (sorgente senza zip),
		// che offrirebbe come aggiornamento codice caricato nel repository ma non ancora rilasciato.
		if ( method_exists( $checker, 'getUniqueName' ) ) {
			add_filter(
				$checker->getUniqueName( 'vcs_update_detection_strategies' ),
				static function ( $strategies ) {
					return ( is_array( $strategies ) && isset( $strategies['latest_release'] ) ) ? array( 'latest_release' => $strategies['latest_release'] ) : array();
				}
			);
		}

		if ( defined( 'GRS_SMTP_GITHUB_TOKEN' ) && '' !== GRS_SMTP_GITHUB_TOKEN && method_exists( $checker, 'setAuthentication' ) ) {
			$checker->setAuthentication( (string) GRS_SMTP_GITHUB_TOKEN );
		}
	}

	/**
	 * URL del repository.
	 *
	 * @return string
	 */
	public static function repository() {
		$repo = defined( 'GRS_SMTP_UPDATE_REPO' ) ? (string) GRS_SMTP_UPDATE_REPO : self::DEFAULT_REPO;

		/**
		 * Filtra l'URL del repository GitHub usato per gli aggiornamenti.
		 *
		 * @param string $repo URL, es. https://github.com/account/grs-smtp/.
		 */
		return (string) apply_filters( 'grs_smtp_update_repository', $repo );
	}

	/**
	 * Libreria presente nel pacchetto.
	 *
	 * @return bool
	 */
	public static function library_present() {
		return is_readable( GRS_SMTP_PATH . 'lib/plugin-update-checker/plugin-update-checker.php' );
	}
}
