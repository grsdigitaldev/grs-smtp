<?php
/**
 * Plugin Name:       GRS SMTP
 * Plugin URI:        https://grsdigital.it
 * Description:       Invia le email di WordPress tramite il server SMTP del dominio, con email di test, log degli invii, avvisi di errore e controllo DNS.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            GRS Digital
 * Author URI:        https://grsdigital.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       grs-smtp
 * Domain Path:       /languages
 * Update URI:        https://github.com/grsdigitaldev/grs-smtp
 *
 * @package GRSDigital\Smtp
 */

defined( 'ABSPATH' ) || exit;

define( 'GRS_SMTP_VERSION', '1.1.1' );
define( 'GRS_SMTP_FILE', __FILE__ );
define( 'GRS_SMTP_PATH', plugin_dir_path( __FILE__ ) );
define( 'GRS_SMTP_URL', plugin_dir_url( __FILE__ ) );

// Guard versione PHP: questo file deve restare sintatticamente valido anche su PHP vecchi.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'GRS SMTP richiede PHP 7.4 o superiore. Il plugin non è attivo.', 'grs-smtp' ) . '</p></div>';
		}
	);
	return;
}

require_once GRS_SMTP_PATH . 'includes/autoload.php';
// Menu condiviso "GRS DEVs": incluso a livello di file, viene caricata la copia più recente tra i plugin GRS attivi.
require_once GRS_SMTP_PATH . 'includes/grs-devs/loader.php';

register_activation_hook( __FILE__, array( 'GRSDigital\\Smtp\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GRSDigital\\Smtp\\Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'GRSDigital\\Smtp\\Plugin', 'instance' ) );
