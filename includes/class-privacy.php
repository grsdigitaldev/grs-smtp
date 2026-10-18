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
			'exporter_friendly_name' => __( 'Email log (GRS SMTP)', 'grs-smtp' ),
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
			'eraser_friendly_name' => __( 'Email log (GRS SMTP)', 'grs-smtp' ),
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
				'group_label' => __( 'Emails sent by the site', 'grs-smtp' ),
				'item_id'     => 'grs-smtp-log-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Date (UTC)', 'grs-smtp' ),
						'value' => $row->created_at,
					),
					array(
						'name'  => __( 'Recipient', 'grs-smtp' ),
						'value' => $row->to_email,
					),
					array(
						'name'  => __( 'Subject', 'grs-smtp' ),
						'value' => $row->subject,
					),
					array(
						'name'  => __( 'Status', 'grs-smtp' ),
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
		$content = '<p>' . esc_html__( 'Emails sent by the site (for example form confirmations, notifications and password recovery) are transmitted through the domain\'s mail server. For technical and support purposes the site keeps a log of deliveries (recipient, subject, content, date and result) for a limited period, after which the data is deleted automatically.', 'grs-smtp' ) . '</p>';
		wp_add_privacy_policy_content( 'GRS SMTP', wp_kses_post( wpautop( $content, false ) ) );
	}
}
