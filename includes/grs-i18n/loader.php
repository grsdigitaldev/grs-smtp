<?php
/**
 * GRS i18n - loader del ponte traduzioni condiviso dei plugin GRS Digital.
 *
 * Copiare questa cartella (grs-i18n/) invariata in includes/ di ogni plugin GRS
 * e includere questo file dal file principale, a livello di file (non dentro un hook):
 *     require_once GRS_NOME_PATH . 'includes/grs-i18n/loader.php';
 *
 * Se più plugin GRS sono attivi viene caricata una sola copia: quella con la
 * versione più alta. Non modificare il componente dentro un singolo plugin:
 * la fonte è dev/grs-i18n.md nel Progetto.
 *
 * @package GRSDigital\I18n
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'grs_i18n_register_candidate' ) ) {

	/**
	 * Registra una copia disponibile del componente.
	 *
	 * @param string $version Versione del componente.
	 * @param string $file    Percorso del file della classe.
	 * @return void
	 */
	function grs_i18n_register_candidate( $version, $file ) {
		if ( ! isset( $GLOBALS['grs_i18n_candidates'] ) || ! is_array( $GLOBALS['grs_i18n_candidates'] ) ) {
			$GLOBALS['grs_i18n_candidates'] = array();
		}
		$GLOBALS['grs_i18n_candidates'][ (string) $file ] = (string) $version;
	}

	/**
	 * Carica la copia più recente del componente (in ogni contesto: serve anche sul sito).
	 *
	 * @return void
	 */
	function grs_i18n_load_latest() {
		if ( class_exists( 'GRS_I18n', false ) || empty( $GLOBALS['grs_i18n_candidates'] ) ) {
			return;
		}
		$best_file    = '';
		$best_version = '0';
		foreach ( $GLOBALS['grs_i18n_candidates'] as $file => $version ) {
			if ( version_compare( $version, $best_version, '>' ) && is_readable( $file ) ) {
				$best_file    = $file;
				$best_version = $version;
			}
		}
		if ( '' !== $best_file ) {
			require_once $best_file;
			GRS_I18n::init( $best_version );
		}
	}

	add_action( 'plugins_loaded', 'grs_i18n_load_latest', 1 );
}

grs_i18n_register_candidate( '1.0.0', __DIR__ . '/class-grs-i18n.php' );
