<?php
/**
 * Avvisi in caso di invii falliti.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Tiene traccia degli errori consecutivi, mostra un avviso in bacheca e (opzionale) invia un'email di allarme.
 */
class Notifier {

	const OPTION         = 'grs_smtp_failure_state';
	const THROTTLE_KEY   = 'grs_smtp_alert_sent';
	const DISMISS_ACTION = 'grs_smtp_dismiss_failure';

	/**
	 * Evita che l'email di allarme generi altri allarmi.
	 *
	 * @var bool
	 */
	private static $sending_alert = false;

	/**
	 * Registra gli hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_mail_failed', array( $this, 'on_failed' ), 20 );
		add_action( 'wp_mail_succeeded', array( $this, 'on_succeeded' ), 20 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'dismiss' ) );
	}

	/**
	 * Stato corrente.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'count'      => 0,
				'last_error' => '',
				'last_time'  => 0,
				'dismissed'  => false,
			),
			is_array( $state ) ? $state : array()
		);
	}

	/**
	 * Invio fallito.
	 *
	 * @param \WP_Error $error Errore.
	 * @return void
	 */
	public function on_failed( $error ) {
		if ( self::$sending_alert || Mailer::is_bypassed() ) {
			return;
		}

		$state               = self::state();
		$state['count']      = (int) $state['count'] + 1;
		$state['last_error'] = trim( ( is_wp_error( $error ) ? $error->get_error_message() : '' ) . ' ' . Settings::failure_note() );
		$state['last_time']  = time();
		$state['dismissed']  = false;
		update_option( self::OPTION, $state );

		$this->maybe_send_alert( $state );
	}

	/**
	 * Invio riuscito: azzera il contatore.
	 *
	 * @return void
	 */
	public function on_succeeded() {
		if ( self::$sending_alert || Mailer::is_bypassed() ) {
			return;
		}
		$state = self::state();
		if ( (int) $state['count'] > 0 ) {
			$state['count']     = 0;
			$state['dismissed'] = false;
			update_option( self::OPTION, $state );
		}
	}

	/**
	 * Email di allarme, al massimo una all'ora, inviata senza SMTP (che potrebbe essere proprio il problema).
	 *
	 * @param array $state Stato errori.
	 * @return void
	 */
	private function maybe_send_alert( array $state ) {
		$to = (string) Settings::get( 'alert_email' );
		if ( ! Settings::get( 'alert_enabled' ) || ! is_email( $to ) || get_transient( self::THROTTLE_KEY ) ) {
			return;
		}
		set_transient( self::THROTTLE_KEY, 1, HOUR_IN_SECONDS );

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: nome del sito. */
		$subject = sprintf( __( '[%s] Email delivery error (GRS SMTP)', 'grs-smtp' ), $site );

		$lines = array(
			/* translators: %s: indirizzo del sito. */
			sprintf( __( 'An email delivery failed on the site %s.', 'grs-smtp' ), home_url() ),
			'',
			/* translators: %s: data e ora. */
			sprintf( __( 'Date: %s', 'grs-smtp' ), wp_date( Plugin::datetime_format(), (int) $state['last_time'] ) ),
			/* translators: %s: messaggio di errore. */
			sprintf( __( 'Error: %s', 'grs-smtp' ), $state['last_error'] ),
			/* translators: %d: numero di errori consecutivi. */
			sprintf( __( 'Consecutive errors: %d', 'grs-smtp' ), (int) $state['count'] ),
			'',
			/* translators: %s: link al log. */
			sprintf( __( 'Check the log: %s', 'grs-smtp' ), admin_url( 'admin.php?page=grs-smtp&tab=log&status=failed' ) ),
			'',
			__( 'This alert is sent with the server\'s default method (without SMTP) and at most once per hour: check your spam folder too.', 'grs-smtp' ),
		);

		self::$sending_alert = true;
		Mailer::set_bypass( true );
		wp_mail( $to, $subject, implode( "\n", $lines ) );
		Mailer::set_bypass( false );
		self::$sending_alert = false;
	}

	/**
	 * Avviso in bacheca.
	 *
	 * @return void
	 */
	public function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', Admin\Admin::SCREEN_ID ), true ) ) {
			return;
		}

		$state = self::state();
		if ( (int) $state['count'] < 1 || $state['dismissed'] ) {
			return;
		}

		$log_url     = admin_url( 'admin.php?page=grs-smtp&tab=log&status=failed' );
		$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'GRS SMTP: email delivery failed.', 'grs-smtp' ) . '</strong> ';
		printf(
			/* translators: 1: numero di errori, 2: data. */
			esc_html__( 'Consecutive errors: %1$d, last one on %2$s.', 'grs-smtp' ),
			(int) $state['count'],
			esc_html( wp_date( Plugin::datetime_format(), (int) $state['last_time'] ) )
		);
		echo '<br><code>' . esc_html( $state['last_error'] ) . '</code></p><p>';
		echo '<a class="button button-primary" href="' . esc_url( $log_url ) . '">' . esc_html__( 'Open the log', 'grs-smtp' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'grs-smtp' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Nasconde l'avviso fino al prossimo errore.
	 *
	 * @return void
	 */
	public function dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'grs-smtp' ), 403 );
		}
		check_admin_referer( self::DISMISS_ACTION );

		$state              = self::state();
		$state['dismissed'] = true;
		update_option( self::OPTION, $state );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
