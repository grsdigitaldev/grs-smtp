<?php
/**
 * Integrazione con gli strumenti privacy di WordPress (GDPR).
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Exporter, eraser e testo suggerito per la privacy policy.
 */
class Privacy {

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_content' ) );
	}

	/**
	 * Registra l'exporter.
	 *
	 * @param array $exporters Exporter.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['grs-smtp'] = array(
			'exporter_friendly_name' => __( 'Log email (GRS SMTP)', 'grs-smtp' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registra l'eraser.
	 *
	 * @param array $erasers Eraser.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['grs-smtp'] = array(
			'eraser_friendly_name' => __( 'Log email (GRS SMTP)', 'grs-smtp' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Esporta le email registrate per un indirizzo.
	 *
	 * @param string $email Indirizzo.
	 * @param int    $page  Pagina.
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$limit = 100;
		$rows  = Logger::find_by_email( $email, $page, $limit );
		$data  = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'grs-smtp-log',
				'group_label' => __( 'Email inviate dal sito', 'grs-smtp' ),
				'item_id'     => 'grs-smtp-log-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Data (UTC)', 'grs-smtp' ),
						'value' => $row->created_at,
					),
					array(
						'name'  => __( 'Destinatario', 'grs-smtp' ),
						'value' => $row->to_email,
					),
					array(
						'name'  => __( 'Oggetto', 'grs-smtp' ),
						'value' => $row->subject,
					),
					array(
						'name'  => __( 'Stato', 'grs-smtp' ),
						'value' => $row->status,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < $limit,
		);
	}

	/**
	 * Cancella le email registrate per un indirizzo.
	 *
	 * @param string $email Indirizzo.
	 * @param int    $page  Pagina (sempre 1: le righe eliminate escono dalla query).
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		unset( $page );
		$limit = 100;
		$rows  = Logger::find_by_email( $email, 1, $limit );
		$ids   = wp_list_pluck( $rows, 'id' );

		$removed = $ids ? Logger::delete( $ids ) : 0;

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $rows ) < $limit,
		);
	}

	/**
	 * Testo suggerito per l'informativa privacy.
	 *
	 * @return void
	 */
	public function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( 'Le email inviate dal sito (ad esempio conferme dei moduli, notifiche e recupero password) vengono trasmesse tramite il server di posta del dominio. Per finalità tecniche e di assistenza il sito conserva un registro degli invii (destinatario, oggetto, contenuto, data ed esito) per un periodo limitato, al termine del quale i dati vengono eliminati automaticamente.', 'grs-smtp' ) . '</p>';
		wp_add_privacy_policy_content( 'GRS SMTP', wp_kses_post( wpautop( $content, false ) ) );
	}
}
