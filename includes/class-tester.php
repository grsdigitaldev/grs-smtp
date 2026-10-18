<?php
/**
 * Email di test con diagnosi leggibile.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Invia l'email di test e traduce gli errori più comuni in indicazioni pratiche.
 */
class Tester {

	/**
	 * Ultimo errore catturato durante il test.
	 *
	 * @var string
	 */
	private $error = '';

	/**
	 * Invia l'email di test.
	 *
	 * @param string $to Destinatario.
	 * @return array{success: bool, message: string, hint: string, debug: string[]}
	 */
	public function send( $to ) {
		$this->error = '';

		if ( Mailer::delivery_disabled() ) {
			return array(
				'success' => false,
				'message' => __( 'Delivery is blocked (staging mode): disable it to run the test.', 'grs-smtp' ),
				'hint'    => '',
				'debug'   => array(),
			);
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: nome del sito. */
		$subject = sprintf( __( 'Test email from %s', 'grs-smtp' ), $site );
		$body    = $this->body( $site );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$capture = function ( $error ) {
			$this->error = is_wp_error( $error ) ? $error->get_error_message() : '';
		};
		add_action( 'wp_mail_failed', $capture );

		Mailer::start_debug();
		$sent  = wp_mail( $to, $subject, $body, $headers );
		$debug = Mailer::stop_debug();

		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			return array(
				'success' => true,
				/* translators: %s: indirizzo destinatario. */
				'message' => sprintf( __( 'Test email sent to %s. Check the mailbox (spam folder too).', 'grs-smtp' ), $to ),
				'hint'    => Settings::is_configured() ? '' : __( 'Warning: SMTP is not configured, the email was sent with the server\'s default method.', 'grs-smtp' ),
				'debug'   => $debug,
			);
		}

		$error = '' !== $this->error ? $this->error : __( 'Delivery failed.', 'grs-smtp' );

		return array(
			'success' => false,
			'message' => $error,
			'hint'    => '' !== Settings::failure_note() ? Settings::failure_note() : self::hint( $error . "\n" . implode( "\n", $debug ) ),
			'debug'   => $debug,
		);
	}

	/**
	 * Corpo HTML dell'email di test.
	 *
	 * @param string $site Nome del sito.
	 * @return string
	 */
	private function body( $site ) {
		$settings = Settings::all();
		$rows     = array(
			__( 'Site', 'grs-smtp' )       => home_url(),
			__( 'Server', 'grs-smtp' )     => $settings['host'] . ':' . $settings['port'],
			__( 'Encryption', 'grs-smtp' ) => strtoupper( $settings['encryption'] ),
			__( 'Sender', 'grs-smtp' )     => Mailer::from_name() . ' <' . Mailer::from_email() . '>',
			__( 'Date', 'grs-smtp' )       => wp_date( Plugin::datetime_format() ),
		);

		$html  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1d2327;max-width:560px">';
		$html .= '<h2 style="margin:0 0 12px">' . esc_html__( 'It works!', 'grs-smtp' ) . '</h2>';
		/* translators: %s: nome del sito. */
		$html .= '<p>' . esc_html( sprintf( __( 'This is a test email sent from %s through GRS SMTP.', 'grs-smtp' ), $site ) ) . '</p>';
		$html .= '<table cellpadding="6" style="border-collapse:collapse;font-size:14px">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><td style="color:#646970">' . esc_html( $label ) . '</td><td><strong>' . esc_html( $value ) . '</strong></td></tr>';
		}
		$html .= '</table>';
		$html .= '<p style="color:#646970;font-size:12px;margin-top:20px">GRS SMTP · GRS Digital</p></div>';

		return $html;
	}

	/**
	 * Suggerimento pratico in base all'errore.
	 *
	 * @param string $text Errore e debug.
	 * @return string
	 */
	public static function hint( $text ) {
		$text = strtolower( (string) $text );

		// I messaggi di PHPMailer arrivano tradotti da WordPress nella lingua del sito: li riconduciamo all'inglese.
		$core = array(
			'SMTP Error: Could not authenticate.'         => 'could not authenticate',
			'SMTP Error: Could not connect to SMTP host.' => 'could not connect',
			'SMTP connect() failed.'                      => 'smtp connect() failed',
			'SMTP Error: The following recipients failed: ' => 'recipients failed',
			'The following From address failed: '         => 'sender address rejected',
			'Could not instantiate mail function.'        => 'could not instantiate mail',
			'Invalid address: '                           => 'invalid address',
		);
		foreach ( $core as $english => $alias ) {
			$translated = rtrim( strtolower( translate( $english, 'default' ) ), ' :.' ); // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.TextDomainMismatch -- Stringhe del core (classe WP_PHPMailer).
			if ( '' !== $translated && false !== strpos( $text, $translated ) ) {
				$text .= ' ' . $alias;
			}
		}

		$map = array(
			'could not authenticate'     => __( 'Credentials rejected: check the username (usually the full email address) and password. Gmail requires an app password; Microsoft 365 requires authenticated SMTP to be enabled.', 'grs-smtp' ),
			'535'                        => __( 'Credentials rejected by the server: check username and password.', 'grs-smtp' ),
			'5.7.30'                     => __( 'Microsoft has disabled SMTP access with password for this mailbox or tenant: it must be re-enabled by the Microsoft 365 administrator, or another sending service must be used.', 'grs-smtp' ),
			'5.7.139'                    => __( 'Microsoft 365: authenticated SMTP disabled for this mailbox or tenant.', 'grs-smtp' ),
			'certificate'                => __( 'Problem with the server\'s SSL certificate: check that the host exactly matches the certificate name (e.g. smtps.aruba.it, not mail.domain.com).', 'grs-smtp' ),
			'ssl operation failed'       => __( 'SSL error: encryption and port probably do not match. Use SSL with port 465 or TLS with port 587.', 'grs-smtp' ),
			'connection refused'         => __( 'Connection refused: wrong port or blocked by the hosting. Try 587 with TLS or 465 with SSL, or ask the hosting whether outgoing SMTP ports are open.', 'grs-smtp' ),
			'connection timed out'       => __( 'The server does not respond: the hosting may block outgoing SMTP connections or the host is wrong.', 'grs-smtp' ),
			'getaddrinfo'                => __( 'Host not found: check the SMTP server name.', 'grs-smtp' ),
			'smtp connect() failed'      => __( 'Connection to the server failed: check host, port and encryption, and that the hosting does not block outgoing SMTP ports.', 'grs-smtp' ),
			'could not connect'          => __( 'Connection to the server failed: check host, port and encryption, and that the hosting does not block outgoing SMTP ports.', 'grs-smtp' ),
			'sender address rejected'    => __( 'The server rejects the sender: use the same address as the SMTP account or an authorized alias as sender.', 'grs-smtp' ),
			'not owned by user'          => __( 'The sender does not belong to the SMTP account: use the same address as the account or an authorized alias.', 'grs-smtp' ),
			'sendasdenied'               => __( 'Microsoft 365: the account is not allowed to send on behalf of the given sender.', 'grs-smtp' ),
			'recipients failed'          => __( 'The server rejected one or more recipients: the address does not exist or is not accepted. Check that it is spelled correctly.', 'grs-smtp' ),
			'invalid address'            => __( 'Invalid email address (sender or recipient).', 'grs-smtp' ),
			'could not instantiate mail' => __( 'SMTP is not active and the server does not allow sending with PHP\'s mail() function: configure the SMTP settings.', 'grs-smtp' ),
		);

		foreach ( $map as $needle => $hint ) {
			// Le chiavi numeriche (es. '535') diventano interi: su PHP 7.4 strpos() con ago intero è deprecato.
			if ( false !== strpos( $text, (string) $needle ) ) {
				return $hint;
			}
		}
		return '';
	}
}
