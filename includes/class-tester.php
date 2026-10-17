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
				'message' => __( 'Gli invii sono bloccati (modalità staging): disattivala per eseguire il test.', 'grs-smtp' ),
				'hint'    => '',
				'debug'   => array(),
			);
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: nome del sito. */
		$subject = sprintf( __( 'Email di test da %s', 'grs-smtp' ), $site );
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
				'message' => sprintf( __( 'Email di test inviata a %s. Controlla la casella (anche lo spam).', 'grs-smtp' ), $to ),
				'hint'    => Settings::is_configured() ? '' : __( 'Attenzione: SMTP non è configurato, l\'email è partita con il metodo predefinito del server.', 'grs-smtp' ),
				'debug'   => $debug,
			);
		}

		$error = '' !== $this->error ? $this->error : __( 'Invio non riuscito.', 'grs-smtp' );

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
			__( 'Sito', 'grs-smtp' )      => home_url(),
			__( 'Server', 'grs-smtp' )    => $settings['host'] . ':' . $settings['port'],
			__( 'Cifratura', 'grs-smtp' ) => strtoupper( $settings['encryption'] ),
			__( 'Mittente', 'grs-smtp' )  => Mailer::from_name() . ' <' . Mailer::from_email() . '>',
			__( 'Data', 'grs-smtp' )      => wp_date( 'd/m/Y H:i:s' ),
		);

		$html  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1d2327;max-width:560px">';
		$html .= '<h2 style="margin:0 0 12px">' . esc_html__( 'Funziona!', 'grs-smtp' ) . '</h2>';
		/* translators: %s: nome del sito. */
		$html .= '<p>' . esc_html( sprintf( __( 'Questa è un\'email di test inviata da %s tramite GRS SMTP.', 'grs-smtp' ), $site ) ) . '</p>';
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
			'could not authenticate'     => __( 'Credenziali rifiutate: controlla utente (di solito l\'indirizzo email completo) e password. Per Gmail serve una password per le app; per Microsoft 365 l\'SMTP autenticato deve essere abilitato.', 'grs-smtp' ),
			'535'                        => __( 'Credenziali rifiutate dal server: controlla utente e password.', 'grs-smtp' ),
			'5.7.30'                     => __( 'Microsoft ha disattivato l\'accesso SMTP con password per questa casella o tenant: va riabilitato dall\'amministratore Microsoft 365 o va usato un altro servizio di invio.', 'grs-smtp' ),
			'5.7.139'                    => __( 'Microsoft 365: SMTP autenticato disabilitato per questa casella o tenant.', 'grs-smtp' ),
			'certificate'                => __( 'Problema con il certificato SSL del server: verifica che l\'host corrisponda esattamente al nome del certificato (es. smtps.aruba.it, non mail.dominio.it).', 'grs-smtp' ),
			'ssl operation failed'       => __( 'Errore SSL: probabilmente cifratura e porta non corrispondono. Usa SSL con porta 465 oppure TLS con porta 587.', 'grs-smtp' ),
			'connection refused'         => __( 'Connessione rifiutata: porta errata o bloccata dall\'hosting. Prova 587 con TLS o 465 con SSL, oppure chiedi all\'hosting se le porte SMTP in uscita sono aperte.', 'grs-smtp' ),
			'connection timed out'       => __( 'Il server non risponde: l\'hosting potrebbe bloccare le connessioni SMTP in uscita o l\'host è errato.', 'grs-smtp' ),
			'getaddrinfo'                => __( 'Host non trovato: controlla il nome del server SMTP.', 'grs-smtp' ),
			'smtp connect() failed'      => __( 'Connessione al server non riuscita: verifica host, porta e cifratura, e che l\'hosting non blocchi le porte SMTP in uscita.', 'grs-smtp' ),
			'could not connect'          => __( 'Connessione al server non riuscita: verifica host, porta e cifratura, e che l\'hosting non blocchi le porte SMTP in uscita.', 'grs-smtp' ),
			'sender address rejected'    => __( 'Il server rifiuta il mittente: usa come mittente lo stesso indirizzo dell\'account SMTP o un alias autorizzato.', 'grs-smtp' ),
			'not owned by user'          => __( 'Il mittente non appartiene all\'account SMTP: usa lo stesso indirizzo dell\'account o un alias autorizzato.', 'grs-smtp' ),
			'sendasdenied'               => __( 'Microsoft 365: l\'account non ha il permesso di inviare a nome del mittente indicato.', 'grs-smtp' ),
			'recipients failed'          => __( 'Il server ha rifiutato uno o più destinatari: l\'indirizzo non esiste o non è accettato. Controlla che sia scritto correttamente.', 'grs-smtp' ),
			'invalid address'            => __( 'Indirizzo email non valido (mittente o destinatario).', 'grs-smtp' ),
			'could not instantiate mail' => __( 'SMTP non è attivo e il server non permette l\'invio con la funzione mail() di PHP: configura i dati SMTP.', 'grs-smtp' ),
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
