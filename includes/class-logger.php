<?php
/**
 * Log degli invii email (tabella custom).
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tabella custom del plugin: nessuna API WordPress disponibile, dati non cacheabili.

/**
 * Registra ogni chiamata a wp_mail() e il suo esito.
 */
class Logger {

	const DB_VERSION        = '1';
	const OPTION_DB_VERSION = 'grs_smtp_db_version';
	const CRON_HOOK         = 'grs_smtp_purge_logs';

	const STATUS_PENDING     = 'pending';
	const STATUS_SENT        = 'sent';
	const STATUS_FAILED      = 'failed';
	const STATUS_BLOCKED     = 'blocked';
	const STATUS_INTERCEPTED = 'intercepted';

	/**
	 * Pila degli ID in corso (wp_mail può essere richiamato dentro un altro invio).
	 *
	 * @var int[]
	 */
	private $stack = array();

	/**
	 * ID dell'email che si sta reinviando.
	 *
	 * @var int
	 */
	private static $resend_of = 0;

	/**
	 * Nome completo della tabella.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'grs_smtp_log';
	}

	/**
	 * Crea o aggiorna la tabella.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			to_email text NOT NULL,
			subject text NOT NULL,
			message longtext NOT NULL,
			headers text NOT NULL,
			attachments text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			error text NOT NULL,
			resent_from bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_mail', array( $this, 'on_wp_mail' ), PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( $this, 'on_pre_wp_mail' ), PHP_INT_MAX );
		add_action( 'wp_mail_succeeded', array( $this, 'on_succeeded' ) );
		add_action( 'wp_mail_failed', array( $this, 'on_failed' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'purge' ) );
	}

	/**
	 * Crea la riga "in corso".
	 *
	 * @param array $atts Argomenti di wp_mail().
	 * @return array
	 */
	public function on_wp_mail( $atts ) {
		$id = 0;
		if ( Settings::get( 'log_enabled' ) && is_array( $atts ) ) {
			$id = $this->insert( $atts );
		}
		$this->stack[] = $id;
		return $atts;
	}

	/**
	 * Invio fermato prima di PHPMailer (modalità staging o altro plugin).
	 *
	 * @param null|bool $result Risultato.
	 * @return null|bool
	 */
	public function on_pre_wp_mail( $result ) {
		if ( null !== $result ) {
			$id     = $this->pop();
			$status = Mailer::delivery_disabled() ? self::STATUS_BLOCKED : self::STATUS_INTERCEPTED;
			$error  = self::STATUS_INTERCEPTED === $status ? __( 'Delivery handled by another plugin (pre_wp_mail filter).', 'grs-smtp' ) : '';
			$this->update( $id, $status, $error );
		}
		return $result;
	}

	/**
	 * Invio riuscito.
	 *
	 * @return void
	 */
	public function on_succeeded() {
		$this->update( $this->pop(), self::STATUS_SENT, '' );
	}

	/**
	 * Invio fallito.
	 *
	 * @param \WP_Error $error Errore.
	 * @return void
	 */
	public function on_failed( $error ) {
		$message = is_wp_error( $error ) ? $error->get_error_message() : __( 'Unknown error.', 'grs-smtp' );
		$note    = Mailer::is_bypassed() ? '' : Settings::failure_note();
		if ( '' !== $note ) {
			$message .= ' ' . $note;
		}
		$this->update( $this->pop(), self::STATUS_FAILED, $message );
	}

	/**
	 * Estrae l'ultimo ID in corso.
	 *
	 * @return int
	 */
	private function pop() {
		$id = array_pop( $this->stack );
		return null === $id ? 0 : (int) $id;
	}

	/**
	 * Inserisce una riga.
	 *
	 * @param array $atts Argomenti di wp_mail().
	 * @return int
	 */
	private function insert( array $atts ) {
		global $wpdb;

		$to = isset( $atts['to'] ) ? $atts['to'] : '';
		if ( is_array( $to ) ) {
			$to = implode( ', ', array_map( 'strval', $to ) );
		}

		$headers = isset( $atts['headers'] ) ? $atts['headers'] : '';
		if ( is_array( $headers ) ) {
			$headers = implode( "\n", array_map( 'strval', $headers ) );
		}

		$files = array(
			'files'  => self::file_list( isset( $atts['attachments'] ) ? $atts['attachments'] : array() ),
			'embeds' => self::file_list( isset( $atts['embeds'] ) ? $atts['embeds'] : array() ),
		);

		$message = '';
		if ( Settings::get( 'log_content' ) && isset( $atts['message'] ) ) {
			$message = (string) $atts['message'];
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql', true ),
				'to_email'    => (string) $to,
				'subject'     => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
				'message'     => $message,
				'headers'     => (string) $headers,
				'attachments' => (string) wp_json_encode( $files ),
				'status'      => self::STATUS_PENDING,
				'error'       => '',
				'resent_from' => self::$resend_of,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Aggiorna stato ed errore.
	 *
	 * @param int    $id     ID riga.
	 * @param string $status Stato.
	 * @param string $error  Messaggio di errore.
	 * @return void
	 */
	private function update( $id, $status, $error ) {
		global $wpdb;
		if ( $id <= 0 ) {
			return;
		}
		$wpdb->update(
			self::table(),
			array(
				'status' => $status,
				'error'  => (string) $error,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Singola riga.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nome tabella interno.
	}

	/**
	 * Righe per la tabella di amministrazione.
	 *
	 * @param array $args status, search, per_page, page, orderby, order.
	 * @return array{items: array, total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table = self::table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(to_email LIKE %s OR subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$orderby  = ( isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'created_at', 'to_email', 'subject', 'status' ), true ) ) ? $args['orderby'] : 'created_at';
		$order    = ( isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ) ? 'ASC' : 'DESC';
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		// Nome tabella, clausole e ordinamento provengono da whitelist interne; i valori passano da prepare().
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$list_sql  = "SELECT id, created_at, to_email, subject, status, error, resent_from FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";

		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Vedi sopra.
		$items = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Vedi sopra.

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Conteggio per stato.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nessun input esterno.
		$out   = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ $row->status ] = (int) $row->total;
			}
		}
		return $out;
	}

	/**
	 * Elimina righe.
	 *
	 * @param int[] $ids ID.
	 * @return int Righe eliminate.
	 */
	public static function delete( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Segnaposto generati.
	}

	/**
	 * Svuota il log.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Nome tabella interno.
	}

	/**
	 * Elimina le righe oltre il periodo di conservazione (WP-Cron giornaliero).
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;
		$days = (int) Settings::get( 'log_retention' );
		if ( $days <= 0 ) {
			return;
		}
		$table = self::table();
		$limit = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nome tabella interno.
	}

	/**
	 * Normalizza un elenco di file (array o stringa separata da a capo).
	 *
	 * @param mixed $value Valore.
	 * @return string[]
	 */
	private static function file_list( $value ) {
		if ( ! is_array( $value ) ) {
			$value = '' === (string) $value ? array() : explode( "\n", str_replace( "\r\n", "\n", (string) $value ) );
		}
		$out = array();
		foreach ( $value as $key => $file ) {
			if ( is_scalar( $file ) && '' !== (string) $file ) {
				$out[ is_string( $key ) ? $key : count( $out ) ] = (string) $file;
			}
		}
		return $out;
	}

	/**
	 * Allegati e immagini incorporate di una riga.
	 *
	 * @param object $row Riga del log.
	 * @return array{files: string[], embeds: string[]}
	 */
	public static function files_of( $row ) {
		$data = json_decode( (string) $row->attachments, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( ! isset( $data['files'] ) && ! isset( $data['embeds'] ) ) {
			$data = array( 'files' => $data );
		}
		return array(
			'files'  => isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array(),
			'embeds' => isset( $data['embeds'] ) && is_array( $data['embeds'] ) ? $data['embeds'] : array(),
		);
	}

	/**
	 * Reinvia un'email registrata.
	 *
	 * @param int $id ID riga.
	 * @return true|\WP_Error
	 */
	public static function resend( $id ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return new \WP_Error( 'grs_smtp_not_found', __( 'Email not found in the log.', 'grs-smtp' ) );
		}
		if ( '' === (string) $row->message ) {
			return new \WP_Error( 'grs_smtp_no_content', __( 'The content of this email was not saved: it cannot be resent.', 'grs-smtp' ) );
		}

		$to          = array_filter( array_map( 'trim', explode( ',', (string) $row->to_email ) ) );
		$headers     = '' === (string) $row->headers ? array() : explode( "\n", (string) $row->headers );
		$files       = self::files_of( $row );
		$attachments = array_values( array_filter( $files['files'], 'is_readable' ) );
		$embeds      = array_filter( $files['embeds'], 'is_readable' );

		self::$resend_of = (int) $row->id;
		// Il sesto parametro ($embeds, WP 6.9+) viene ignorato dalle versioni precedenti.
		$sent            = $embeds ? wp_mail( $to, (string) $row->subject, (string) $row->message, $headers, $attachments, $embeds ) : wp_mail( $to, (string) $row->subject, (string) $row->message, $headers, $attachments );
		self::$resend_of = 0;

		if ( ! $sent ) {
			return new \WP_Error( 'grs_smtp_resend_failed', __( 'Resend failed: check the log for details.', 'grs-smtp' ) );
		}
		return true;
	}

	/**
	 * Righe che contengono un indirizzo (privacy).
	 *
	 * @param string $email Indirizzo.
	 * @param int    $page  Pagina.
	 * @param int    $limit Righe per pagina.
	 * @return array
	 */
	public static function find_by_email( $email, $page = 1, $limit = 100 ) {
		global $wpdb;
		$table  = self::table();
		$like   = '%' . $wpdb->esc_like( (string) $email ) . '%';
		$offset = ( max( 1, (int) $page ) - 1 ) * $limit;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, to_email, subject, status FROM {$table} WHERE to_email LIKE %s OR headers LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d", $like, $like, $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nome tabella interno.
		return is_array( $rows ) ? $rows : array();
	}
}
