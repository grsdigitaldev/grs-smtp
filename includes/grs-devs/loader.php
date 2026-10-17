<?php
/**
 * GRS DEVs - loader del menu condiviso dei plugin GRS Digital.
 *
 * Copiare questa cartella (grs-devs/) invariata in includes/ di ogni plugin GRS
 * e includere questo file dal file principale, a livello di file (non dentro un hook):
 *     require_once GRS_NOME_PATH . 'includes/grs-devs/loader.php';
 *
 * Se più plugin GRS sono attivi, viene caricata una sola copia del componente:
 * quella con la versione più alta. Non modificare il componente dentro un singolo
 * plugin: la fonte è dev/grs-devs-menu.md nel Progetto.
 *
 * @package GRSDigital\Devs
 * @version 1.0.1
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'grs_devs_register_candidate' ) ) {

	/**
	 * Registra una copia disponibile del componente.
	 *
	 * @param string $version Versione del componente.
	 * @param string $file    Percorso del file della classe.
	 * @return void
	 */
	function grs_devs_register_candidate( $version, $file ) {
		if ( ! isset( $GLOBALS['grs_devs_candidates'] ) || ! is_array( $GLOBALS['grs_devs_candidates'] ) ) {
			$GLOBALS['grs_devs_candidates'] = array();
		}
		$GLOBALS['grs_devs_candidates'][ (string) $file ] = (string) $version;
	}

	/**
	 * Carica la copia più recente del componente (solo in bacheca).
	 *
	 * @return void
	 */
	function grs_devs_load_latest() {
		if ( ! is_admin() || class_exists( 'GRS_Devs_Menu', false ) || empty( $GLOBALS['grs_devs_candidates'] ) ) {
			return;
		}
		$best_file    = '';
		$best_version = '0';
		foreach ( $GLOBALS['grs_devs_candidates'] as $file => $version ) {
			if ( version_compare( $version, $best_version, '>' ) && is_readable( $file ) ) {
				$best_file    = $file;
				$best_version = $version;
			}
		}
		if ( '' !== $best_file ) {
			require_once $best_file;
			GRS_Devs_Menu::init( $best_version );
		}
	}

	add_action( 'plugins_loaded', 'grs_devs_load_latest', 1 );
}

grs_devs_register_candidate( '1.0.1', __DIR__ . '/class-grs-devs-menu.php' );
