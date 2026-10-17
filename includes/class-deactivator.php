<?php
/**
 * Disattivazione.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Rimuove gli eventi cron. Dati e impostazioni restano fino alla disinstallazione.
 */
class Deactivator {

	/**
	 * Hook di disattivazione.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Logger::CRON_HOOK );
	}
}
