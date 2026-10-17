<?php
/**
 * Attivazione.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Crea tabella, opzioni e cron. In multisite gli altri siti vengono preparati al primo caricamento (Plugin::maybe_upgrade).
 */
class Activator {

	/**
	 * Hook di attivazione.
	 *
	 * @return void
	 */
	public static function activate() {
		self::setup_site();
	}

	/**
	 * Prepara il sito corrente.
	 *
	 * @return void
	 */
	public static function setup_site() {
		Logger::install();

		if ( false === get_option( Settings::OPTION, false ) ) {
			$defaults                = Settings::defaults();
			$defaults['alert_email'] = (string) get_option( 'admin_email', '' );
			add_option( Settings::OPTION, $defaults );
		}

		if ( ! wp_next_scheduled( Logger::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Logger::CRON_HOOK );
		}

		update_option( Plugin::OPTION_VERSION, GRS_SMTP_VERSION );
	}
}
