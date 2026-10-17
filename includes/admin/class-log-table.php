<?php
/**
 * Tabella del log email.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp\Admin;

use GRSDigital\Smtp\Logger;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Elenco delle email registrate con filtri, ricerca e azioni.
 */
class Log_Table extends \WP_List_Table {

	/**
	 * Costruttore.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'grs_smtp_log',
				'plural'   => 'grs_smtp_logs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Etichette degli stati.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			Logger::STATUS_SENT        => __( 'Inviata', 'grs-smtp' ),
			Logger::STATUS_FAILED      => __( 'Fallita', 'grs-smtp' ),
			Logger::STATUS_BLOCKED     => __( 'Bloccata (staging)', 'grs-smtp' ),
			Logger::STATUS_INTERCEPTED => __( 'Gestita da altro plugin', 'grs-smtp' ),
			Logger::STATUS_PENDING     => __( 'Esito sconosciuto', 'grs-smtp' ),
		);
	}

	/**
	 * Etichetta colorata dello stato.
	 *
	 * @param string $status Stato.
	 * @return string
	 */
	public static function status_html( $status ) {
		$labels = self::statuses();
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		return '<span class="grs-smtp__status grs-smtp__status--' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Colonne.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox">',
			'subject'    => __( 'Oggetto', 'grs-smtp' ),
			'to_email'   => __( 'Destinatario', 'grs-smtp' ),
			'status'     => __( 'Stato', 'grs-smtp' ),
			'created_at' => __( 'Data', 'grs-smtp' ),
		);
	}

	/**
	 * Colonne ordinabili.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'subject'    => array( 'subject', false ),
			'to_email'   => array( 'to_email', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Azioni di gruppo.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'bulk_delete' => __( 'Elimina', 'grs-smtp' ) );
	}

	/**
	 * Filtri per stato.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = Logger::counts();
		$current = $this->current_status();
		$total   = array_sum( $counts );
		$base    = Admin::url( array( 'tab' => 'log' ) );

		$views        = array();
		$views['all'] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
			esc_url( $base ),
			'' === $current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Tutte', 'grs-smtp' ),
			(int) $total
		);

		foreach ( self::statuses() as $status => $label ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}
			$views[ $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				(int) $counts[ $status ]
			);
		}

		return $views;
	}

	/**
	 * Stato filtrato.
	 *
	 * @return string
	 */
	private function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filtro di visualizzazione.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		return isset( self::statuses()[ $status ] ) ? $status : '';
	}

	/**
	 * Carica le righe.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Parametri di visualizzazione.
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		$result = Logger::query(
			array(
				'status'   => $this->current_status(),
				'search'   => $search,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'subject' );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	/**
	 * Messaggio senza righe.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Nessuna email registrata.', 'grs-smtp' );
	}

	/**
	 * Checkbox.
	 *
	 * @param object $item Riga.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . esc_attr( (string) $item->id ) . '">';
	}

	/**
	 * Oggetto con azioni.
	 *
	 * @param object $item Riga.
	 * @return string
	 */
	protected function column_subject( $item ) {
		$view    = Admin::url(
			array(
				'tab'  => 'log',
				'view' => (int) $item->id,
			)
		);
		$subject = '' !== (string) $item->subject ? (string) $item->subject : __( '(senza oggetto)', 'grs-smtp' );

		$actions = array(
			'view'   => '<a href="' . esc_url( $view ) . '">' . esc_html__( 'Dettagli', 'grs-smtp' ) . '</a>',
			'resend' => '<a href="' . esc_url( Admin::log_action_url( 'resend', (int) $item->id ) ) . '">' . esc_html__( 'Reinvia', 'grs-smtp' ) . '</a>',
			'delete' => '<a class="grs-smtp__delete" data-grs-confirm="1" href="' . esc_url( Admin::log_action_url( 'delete', (int) $item->id ) ) . '">' . esc_html__( 'Elimina', 'grs-smtp' ) . '</a>',
		);

		$out = '<strong><a class="row-title" href="' . esc_url( $view ) . '">' . esc_html( $subject ) . '</a></strong>';
		if ( (int) $item->resent_from > 0 ) {
			$out .= ' <span class="grs-smtp__tag">' . esc_html__( 'reinvio', 'grs-smtp' ) . '</span>';
		}
		return $out . $this->row_actions( $actions );
	}

	/**
	 * Destinatario.
	 *
	 * @param object $item Riga.
	 * @return string
	 */
	protected function column_to_email( $item ) {
		return esc_html( (string) $item->to_email );
	}

	/**
	 * Stato con eventuale errore.
	 *
	 * @param object $item Riga.
	 * @return string
	 */
	protected function column_status( $item ) {
		$out = self::status_html( (string) $item->status );
		if ( '' !== (string) $item->error ) {
			$out .= '<br><small class="grs-smtp__error" title="' . esc_attr( (string) $item->error ) . '">' . esc_html( wp_html_excerpt( (string) $item->error, 80, '…' ) ) . '</small>';
		}
		return $out;
	}

	/**
	 * Data locale.
	 *
	 * @param object $item Riga.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		return esc_html( get_date_from_gmt( (string) $item->created_at, 'd/m/Y H:i:s' ) );
	}
}
