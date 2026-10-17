<?php
/**
 * GRS DEVs - menu condiviso e pagina panoramica dei plugin GRS Digital.
 *
 * @package GRSDigital\Devs
 * @version 1.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GRS_Devs_Menu', false ) ) {

	/**
	 * Menu principale "GRS DEVs" sotto Bacheca.
	 *
	 * Ogni plugin GRS:
	 * - si registra nella panoramica con il filtro `grs_devs_plugins`;
	 * - aggiunge UNA sola voce di sottomenu con add_submenu_page( GRS_Devs_Menu::SLUG, ... )
	 *   su admin_menu con priorità 10 (le sezioni interne vanno in schede);
	 * - (dalla 1.1.0) avvolge la pagina in <div class="wrap grs-ui"> e usa render_header() e
	 *   render_footer(): lo stile condiviso grs-ui.css viene caricato da qui solo sulle pagine GRS DEVs.
	 */
	final class GRS_Devs_Menu {

		/**
		 * Slug del menu principale.
		 */
		const SLUG = 'grs-devs';

		/**
		 * Posizione nel menu: subito dopo Bacheca (2). Stringa per evitare collisioni.
		 */
		const POSITION = '2.1';

		/**
		 * Versione caricata del componente.
		 *
		 * @var string
		 */
		private static $version = '';

		/**
		 * Avvio.
		 *
		 * @param string $version Versione del componente scelta dal loader.
		 * @return void
		 */
		public static function init( $version ) {
			self::$version = (string) $version;
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 9 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_ui' ) );
		}

		/**
		 * Stile condiviso, solo sulle pagine del menu GRS DEVs.
		 *
		 * @param string $hook_suffix Pagina corrente.
		 * @return void
		 */
		public static function enqueue_ui( $hook_suffix ) {
			$hook_suffix = (string) $hook_suffix;
			if ( 'toplevel_page_' . self::SLUG !== $hook_suffix && 0 !== strpos( $hook_suffix, self::SLUG . '_page_' ) ) {
				return;
			}
			wp_enqueue_style( 'grs-devs-ui', plugins_url( 'grs-ui.css', __FILE__ ), array(), self::$version );
		}

		/**
		 * Intestazione standard delle pagine GRS: logo, titolo, sottotitolo, meta (badge, versione).
		 *
		 * Dopo l'intestazione stampa <hr class="wp-header-end">: WordPress sposta lì gli avvisi.
		 *
		 * @param string $title     Titolo (testo semplice).
		 * @param string $subtitle  Sottotitolo (testo semplice, facoltativo).
		 * @param string $meta_html HTML già sicuro per l'area a destra (badge); filtrato con wp_kses_post.
		 * @return void
		 */
		public static function render_header( $title, $subtitle = '', $meta_html = '' ) {
			echo '<div class="grs-ui-header">';
			echo '<span class="grs-ui-header__logo" aria-hidden="true">' . self::LOGO_SVG . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statico definito nel componente.
			echo '<div class="grs-ui-header__text"><h1>' . esc_html( $title ) . '</h1>';
			if ( '' !== (string) $subtitle ) {
				echo '<p class="grs-ui-header__subtitle">' . esc_html( $subtitle ) . '</p>';
			}
			echo '</div>';
			if ( '' !== (string) $meta_html ) {
				echo '<div class="grs-ui-header__meta">' . wp_kses_post( $meta_html ) . '</div>';
			}
			echo '</div><hr class="wp-header-end">';
		}

		/**
		 * Piede standard delle pagine GRS.
		 *
		 * @return void
		 */
		public static function render_footer() {
			echo '<p class="grs-ui-footer">GRS Digital &middot; <a href="https://grsdigital.it" target="_blank" rel="noopener noreferrer">grsdigital.it</a> &middot; <a href="mailto:info@grsdigital.it">info@grsdigital.it</a></p>';
		}

		/**
		 * Capability richiesta per il menu principale e la panoramica.
		 *
		 * @return string
		 */
		public static function capability() {
			return (string) apply_filters( 'grs_devs_menu_capability', 'manage_options' );
		}

		/**
		 * Registra menu principale e voce "Panoramica".
		 *
		 * @return void
		 */
		public static function register_menu() {
			$cap = self::capability();

			add_menu_page(
				'GRS DEVs',
				'GRS DEVs',
				$cap,
				self::SLUG,
				array( __CLASS__, 'render_overview' ),
				self::icon(),
				self::POSITION
			);

			add_submenu_page(
				self::SLUG,
				'GRS DEVs - Panoramica',
				'Panoramica',
				$cap,
				self::SLUG,
				array( __CLASS__, 'render_overview' )
			);
		}

		/**
		 * Marchio GRS monocromatico (anello + G): WordPress lo ricolora come le dashicons.
		 */
		const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="33 32 270 270"><path fill="black" d="M 90.10 109.06 C 88.93 107.26 88.19 106.11 87.15 104.50 C 92.67 98.73 97.87 92.90 103.49 87.50 C 114.23 77.16 125.89 68.01 139.25 61.17 C 154.64 53.30 170.80 48.52 188.21 47.72 C 219.09 46.30 246.05 56.18 269.28 76 C 287.73 91.73 298.48 112.00 301.43 136.26 C 304.01 157.49 299.80 177.51 291.26 196.84 C 279.12 224.33 259.53 245.28 234.44 261.30 C 213.60 274.60 191.01 283.53 166.52 287.26 C 138.20 291.57 111.06 288.28 86.18 273.21 C 57.66 255.93 41.21 230.32 35.79 197.64 C 30.97 168.49 37.35 141.16 50.42 115.16 C 59.39 97.31 71.23 81.48 85.53 67.55 C 93.69 59.62 102.48 52.48 112.90 47.60 C 118.81 44.83 124.96 43.42 131.48 44.02 C 133.36 44.19 135.22 44.74 137.06 45.22 C 138.53 45.60 139.94 46.38 139.69 48.11 C 139.45 49.85 138.75 51.44 136.51 51.71 C 133.82 52.03 131.16 52.55 128.50 53.08 C 117.69 55.26 108.61 60.80 100.37 67.82 C 77.31 87.46 59.64 110.98 49.67 139.79 C 44.25 155.48 41.90 171.75 43.37 188.35 C 47.43 234.24 78.17 265.91 117.64 275.53 C 128.57 278.19 139.66 279.57 150.86 278.92 C 169.34 277.86 187.05 273.37 204.03 265.94 C 224.46 257.02 242.85 245.08 258.33 228.97 C 275.06 211.55 287.04 191.39 291.46 167.40 C 296.52 139.94 291.96 114.60 273.14 92.96 C 258.25 75.83 239.23 65.62 217.41 60.35 C 190.14 53.75 164.53 58.5 140.35 72.15 C 125.27 80.67 111.56 91.10 98.46 102.40 C 95.95 104.57 93.27 106.55 90.10 109.06 Z"/><path fill="black" transform="translate(77.83 231.57)" d="M 130.31 -8.03 C 117.46 -0.84 103.09 2.75 87.18 2.75 C 71.28 2.75 56.87 -0.84 43.96 -8.03 C 31.07 -15.21 21.41 -24.47 14.98 -35.79 C 9.67 -45.17 7.03 -55.10 7.03 -65.60 C 7.03 -76.22 9.67 -86.21 14.98 -95.59 C 21.41 -106.91 31.07 -116.19 43.96 -123.43 C 56.87 -130.67 71.28 -134.29 87.18 -134.29 C 104.51 -134.29 120.93 -129.50 136.43 -119.92 L 138.42 -118.68 L 121.43 -96.96 L 118.68 -98.65 C 107.46 -105.38 96.96 -108.75 87.18 -108.75 C 73.31 -108.75 61.83 -104.36 52.76 -95.59 C 43.69 -86.82 39.15 -76.82 39.15 -65.60 C 39.15 -54.59 43.69 -44.70 52.76 -35.93 C 61.83 -27.17 73.31 -22.79 87.18 -22.79 C 98.09 -22.79 107.64 -25.67 115.85 -31.43 C 124.06 -37.19 129.70 -44.35 132.76 -52.92 L 66.84 -52.92 L 67.14 -78.62 L 167.17 -78.62 L 167.17 -65.60 C 167.17 -55.42 164.51 -45.48 159.21 -35.79 C 152.80 -24.47 143.16 -15.21 130.31 -8.03 Z"/></svg>';

		/**
		 * Logo GRS a colori per la pagina Panoramica.
		 */
		const LOGO_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 335 335" aria-hidden="true" focusable="false"><circle cx="167.5" cy="167.5" r="167" fill="#000"/><path fill="#f8e700" d="M 90.10 109.06 C 88.93 107.26 88.19 106.11 87.15 104.50 C 92.67 98.73 97.87 92.90 103.49 87.50 C 114.23 77.16 125.89 68.01 139.25 61.17 C 154.64 53.30 170.80 48.52 188.21 47.72 C 219.09 46.30 246.05 56.18 269.28 76 C 287.73 91.73 298.48 112.00 301.43 136.26 C 304.01 157.49 299.80 177.51 291.26 196.84 C 279.12 224.33 259.53 245.28 234.44 261.30 C 213.60 274.60 191.01 283.53 166.52 287.26 C 138.20 291.57 111.06 288.28 86.18 273.21 C 57.66 255.93 41.21 230.32 35.79 197.64 C 30.97 168.49 37.35 141.16 50.42 115.16 C 59.39 97.31 71.23 81.48 85.53 67.55 C 93.69 59.62 102.48 52.48 112.90 47.60 C 118.81 44.83 124.96 43.42 131.48 44.02 C 133.36 44.19 135.22 44.74 137.06 45.22 C 138.53 45.60 139.94 46.38 139.69 48.11 C 139.45 49.85 138.75 51.44 136.51 51.71 C 133.82 52.03 131.16 52.55 128.50 53.08 C 117.69 55.26 108.61 60.80 100.37 67.82 C 77.31 87.46 59.64 110.98 49.67 139.79 C 44.25 155.48 41.90 171.75 43.37 188.35 C 47.43 234.24 78.17 265.91 117.64 275.53 C 128.57 278.19 139.66 279.57 150.86 278.92 C 169.34 277.86 187.05 273.37 204.03 265.94 C 224.46 257.02 242.85 245.08 258.33 228.97 C 275.06 211.55 287.04 191.39 291.46 167.40 C 296.52 139.94 291.96 114.60 273.14 92.96 C 258.25 75.83 239.23 65.62 217.41 60.35 C 190.14 53.75 164.53 58.5 140.35 72.15 C 125.27 80.67 111.56 91.10 98.46 102.40 C 95.95 104.57 93.27 106.55 90.10 109.06 Z"/><path fill="#fff" transform="translate(77.83 231.57)" d="M 130.31 -8.03 C 117.46 -0.84 103.09 2.75 87.18 2.75 C 71.28 2.75 56.87 -0.84 43.96 -8.03 C 31.07 -15.21 21.41 -24.47 14.98 -35.79 C 9.67 -45.17 7.03 -55.10 7.03 -65.60 C 7.03 -76.22 9.67 -86.21 14.98 -95.59 C 21.41 -106.91 31.07 -116.19 43.96 -123.43 C 56.87 -130.67 71.28 -134.29 87.18 -134.29 C 104.51 -134.29 120.93 -129.50 136.43 -119.92 L 138.42 -118.68 L 121.43 -96.96 L 118.68 -98.65 C 107.46 -105.38 96.96 -108.75 87.18 -108.75 C 73.31 -108.75 61.83 -104.36 52.76 -95.59 C 43.69 -86.82 39.15 -76.82 39.15 -65.60 C 39.15 -54.59 43.69 -44.70 52.76 -35.93 C 61.83 -27.17 73.31 -22.79 87.18 -22.79 C 98.09 -22.79 107.64 -25.67 115.85 -31.43 C 124.06 -37.19 129.70 -44.35 132.76 -52.92 L 66.84 -52.92 L 67.14 -78.62 L 167.17 -78.62 L 167.17 -65.60 C 167.17 -55.42 164.51 -45.48 159.21 -35.79 C 152.80 -24.47 143.16 -15.21 130.31 -8.03 Z"/></svg>';

		/**
		 * Icona del menu (SVG in data URI).
		 *
		 * @return string
		 */
		private static function icon() {
			return (string) apply_filters( 'grs_devs_menu_icon', 'data:image/svg+xml;base64,' . base64_encode( self::ICON_SVG ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- icona SVG statica.
		}

		/**
		 * Plugin GRS registrati, ordinati per nome.
		 *
		 * @return array<string, array<string, string>>
		 */
		private static function plugins() {
			$plugins = apply_filters( 'grs_devs_plugins', array() );
			if ( ! is_array( $plugins ) ) {
				return array();
			}
			$clean = array();
			foreach ( $plugins as $slug => $data ) {
				if ( ! is_array( $data ) || empty( $data['name'] ) ) {
					continue;
				}
				$clean[ sanitize_key( $slug ) ] = array(
					'name'        => (string) $data['name'],
					'version'     => isset( $data['version'] ) ? (string) $data['version'] : '',
					'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
					'url'         => isset( $data['url'] ) ? (string) $data['url'] : '',
				);
			}
			uasort(
				$clean,
				static function ( $a, $b ) {
					return strcasecmp( $a['name'], $b['name'] );
				}
			);
			return $clean;
		}

		/**
		 * Pagina panoramica: elenco dei plugin GRS attivi.
		 *
		 * @return void
		 */
		public static function render_overview() {
			if ( ! current_user_can( self::capability() ) ) {
				wp_die( esc_html( 'Non hai i permessi per accedere a questa pagina.' ) );
			}
			$plugins = self::plugins();
			?>
			<div class="wrap grs-ui">
				<?php self::render_header( 'GRS DEVs', 'Plugin sviluppati da GRS Digital attivi su questo sito.' ); ?>
				<div class="grs-ui-card">
				<?php if ( empty( $plugins ) ) : ?>
					<p>Nessun plugin GRS registrato.</p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col">Plugin</th>
								<th scope="col">Versione</th>
								<th scope="col">Descrizione</th>
								<th scope="col"><span class="screen-reader-text">Azioni</span></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $plugins as $plugin ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $plugin['name'] ); ?></strong></td>
								<td><span class="grs-ui-version"><?php echo esc_html( $plugin['version'] ); ?></span></td>
								<td><?php echo esc_html( $plugin['description'] ); ?></td>
								<td>
									<?php if ( '' !== $plugin['url'] ) : ?>
										<a class="button button-primary" href="<?php echo esc_url( $plugin['url'] ); ?>">Apri</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				</div>
				<?php self::render_footer(); ?>
			</div>
			<?php
		}
	}
}
