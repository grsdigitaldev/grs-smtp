<?php
/**
 * Controllo DNS del dominio mittente (SPF, DMARC, DKIM opzionale).
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Verifiche di base sulla consegnabilità. Non sostituisce un test completo (es. mail-tester.com).
 */
class Dns_Check {

	/**
	 * Esegue tutti i controlli.
	 *
	 * @param string $domain   Dominio mittente.
	 * @param string $selector Selettore DKIM opzionale.
	 * @return array[] Elenco di controlli: label, status (ok|warn|error|info), message, detail.
	 */
	public static function run( $domain, $selector = '' ) {
		$domain = strtolower( trim( (string) $domain ) );
		$checks = array();

		if ( '' === $domain ) {
			$checks[] = self::item( __( 'Dominio mittente', 'grs-smtp' ), 'error', __( 'Nessun indirizzo mittente configurato.', 'grs-smtp' ) );
			return $checks;
		}

		if ( ! function_exists( 'dns_get_record' ) ) {
			$checks[] = self::item( __( 'DNS', 'grs-smtp' ), 'info', __( 'Il server non permette interrogazioni DNS da PHP (dns_get_record non disponibile). Usa uno strumento esterno come MXToolbox.', 'grs-smtp' ) );
			return $checks;
		}

		$checks[] = self::check_alignment( $domain );

		if ( ! self::resolvable( $domain ) ) {
			$checks[] = self::item( __( 'DNS', 'grs-smtp' ), 'info', __( 'Impossibile interrogare il DNS del dominio da questo server (risoluzione bloccata o dominio inesistente). Verifica SPF, DKIM e DMARC con uno strumento esterno come MXToolbox.', 'grs-smtp' ) );
			return $checks;
		}

		$checks[] = self::check_spf( $domain );
		$checks[] = self::check_dmarc( $domain );
		$checks[] = self::check_dkim( $domain, $selector );

		return $checks;
	}

	/**
	 * Il dominio risponde (NS o SOA): distingue "record assente" da "DNS non interrogabile".
	 *
	 * @param string $domain Dominio.
	 * @return bool
	 */
	private static function resolvable( $domain ) {
		foreach ( array( DNS_NS, DNS_SOA ) as $type ) {
			$records = @dns_get_record( $domain, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Warning su NXDOMAIN gestito dal valore di ritorno.
			if ( ! empty( $records ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record TXT di un nome.
	 *
	 * @param string $name Nome DNS.
	 * @return string[]|false False se la query fallisce.
	 */
	private static function txt( $name ) {
		$records = @dns_get_record( $name, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record emette warning su NXDOMAIN, gestito dal valore di ritorno.
		if ( false === $records ) {
			return false;
		}
		$out = array();
		foreach ( (array) $records as $record ) {
			if ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
				$out[] = implode( '', $record['entries'] );
			} elseif ( isset( $record['txt'] ) ) {
				$out[] = (string) $record['txt'];
			}
		}
		return $out;
	}

	/**
	 * Mittente e utente SMTP sullo stesso dominio.
	 *
	 * @param string $domain Dominio mittente.
	 * @return array
	 */
	private static function check_alignment( $domain ) {
		$label    = __( 'Mittente e account SMTP', 'grs-smtp' );
		$username = strtolower( (string) Settings::get( 'username' ) );

		if ( ! is_email( $username ) ) {
			return self::item( $label, 'info', __( 'L\'utente SMTP non è un indirizzo email: verifica con il provider che possa inviare a nome del dominio mittente.', 'grs-smtp' ) );
		}

		$user_domain = substr( (string) strrchr( $username, '@' ), 1 );
		if ( $user_domain === $domain ) {
			return self::item( $label, 'ok', __( 'Il mittente usa lo stesso dominio dell\'account SMTP.', 'grs-smtp' ) );
		}

		return self::item(
			$label,
			'warn',
			sprintf(
				/* translators: 1: dominio mittente, 2: dominio account SMTP. */
				__( 'Il mittente (@%1$s) è diverso dall\'account SMTP (@%2$s). Molti provider rifiutano o segnano come spam queste email: usa un mittente dello stesso dominio o autorizzalo nel provider.', 'grs-smtp' ),
				$domain,
				$user_domain
			)
		);
	}

	/**
	 * SPF.
	 *
	 * @param string $domain Dominio.
	 * @return array
	 */
	private static function check_spf( $domain ) {
		$label   = 'SPF';
		$records = self::txt( $domain );

		if ( false === $records ) {
			return self::item( $label, 'info', __( 'Impossibile interrogare il DNS in questo momento.', 'grs-smtp' ) );
		}

		$spf = array_values(
			array_filter(
				$records,
				static function ( $txt ) {
					return 0 === stripos( trim( $txt ), 'v=spf1' );
				}
			)
		);

		if ( ! $spf ) {
			return self::item( $label, 'error', __( 'Nessun record SPF trovato. Aggiungine uno che autorizzi il server SMTP usato (chiedi il valore al provider della posta).', 'grs-smtp' ) );
		}
		if ( count( $spf ) > 1 ) {
			return self::item( $label, 'error', __( 'Ci sono più record SPF: è un errore, i provider li considerano non validi. Uniscili in un unico record.', 'grs-smtp' ), implode( "\n", $spf ) );
		}

		$record = strtolower( $spf[0] );

		if ( preg_match( '/(^|\s)\+?all(\s|$)/', $record ) ) {
			return self::item( $label, 'warn', __( 'Il record SPF termina con "+all": autorizza chiunque a inviare a nome del dominio. Usa "~all" o "-all".', 'grs-smtp' ), $spf[0] );
		}

		$lookups = preg_match_all( '/\b(include:|a\b|a:|mx\b|mx:|ptr|exists:|redirect=)/', $record );
		if ( $lookups > 10 ) {
			return self::item( $label, 'error', __( 'Il record SPF supera il limite di 10 interrogazioni DNS: viene considerato non valido.', 'grs-smtp' ), $spf[0] );
		}

		$providers = Providers::all();
		$provider  = (string) Settings::get( 'provider' );
		$include   = isset( $providers[ $provider ]['spf'] ) ? (string) $providers[ $provider ]['spf'] : '';

		if ( '' !== $include ) {
			if ( false !== strpos( $record, 'include:' . strtolower( $include ) ) ) {
				/* translators: %s: meccanismo SPF del provider. */
				return self::item( $label, 'ok', sprintf( __( 'Record SPF presente e include il provider selezionato (%s).', 'grs-smtp' ), $include ), $spf[0] );
			}
			/* translators: %s: meccanismo SPF del provider. */
			return self::item( $label, 'warn', sprintf( __( 'Record SPF presente ma non contiene "include:%s" richiesto dal provider selezionato.', 'grs-smtp' ), $include ), $spf[0] );
		}

		return self::item( $label, 'ok', __( 'Record SPF presente e valido nella forma. Verifica con il provider che autorizzi il suo server di invio.', 'grs-smtp' ), $spf[0] );
	}

	/**
	 * DMARC.
	 *
	 * @param string $domain Dominio.
	 * @return array
	 */
	private static function check_dmarc( $domain ) {
		$label   = 'DMARC';
		$records = self::txt( '_dmarc.' . $domain );

		$dmarc = array();
		if ( is_array( $records ) ) {
			foreach ( $records as $txt ) {
				if ( 0 === stripos( trim( $txt ), 'v=DMARC1' ) ) {
					$dmarc[] = $txt;
				}
			}
		}

		if ( ! $dmarc ) {
			return self::item( $label, 'warn', __( 'Nessun record DMARC. Gmail, Yahoo e Microsoft lo richiedono sempre più spesso: aggiungi almeno "v=DMARC1; p=none;" su _dmarc del dominio.', 'grs-smtp' ) );
		}

		$policy = 'none';
		if ( preg_match( '/\bp\s*=\s*(none|quarantine|reject)/i', $dmarc[0], $match ) ) {
			$policy = strtolower( $match[1] );
		}

		/* translators: %s: policy DMARC. */
		return self::item( $label, 'ok', sprintf( __( 'Record DMARC presente (policy: %s).', 'grs-smtp' ), $policy ), $dmarc[0] );
	}

	/**
	 * DKIM: verificabile solo conoscendo il selettore.
	 *
	 * @param string $domain   Dominio.
	 * @param string $selector Selettore.
	 * @return array
	 */
	private static function check_dkim( $domain, $selector ) {
		$label    = 'DKIM';
		$selector = strtolower( trim( (string) $selector ) );

		if ( '' === $selector ) {
			return self::item( $label, 'info', __( 'Per verificare la firma DKIM inserisci il selettore indicato dal provider (es. "default", "google", "selector1").', 'grs-smtp' ) );
		}
		if ( ! preg_match( '/^[a-z0-9._-]+$/', $selector ) ) {
			return self::item( $label, 'error', __( 'Selettore non valido.', 'grs-smtp' ) );
		}

		$name    = $selector . '._domainkey.' . $domain;
		$records = self::txt( $name );
		if ( is_array( $records ) ) {
			foreach ( $records as $txt ) {
				if ( false !== stripos( $txt, 'p=' ) ) {
					/* translators: %s: nome DNS. */
					return self::item( $label, 'ok', sprintf( __( 'Chiave DKIM trovata su %s.', 'grs-smtp' ), $name ), $txt );
				}
			}
		}

		/* translators: %s: nome DNS. */
		return self::item( $label, 'warn', sprintf( __( 'Nessuna chiave DKIM su %s. Controlla il selettore o attiva DKIM nel pannello del provider.', 'grs-smtp' ), $name ) );
	}

	/**
	 * Costruisce una voce di risultato.
	 *
	 * @param string $label   Etichetta.
	 * @param string $status  ok|warn|error|info.
	 * @param string $message Messaggio.
	 * @param string $detail  Dettaglio (record).
	 * @return array
	 */
	private static function item( $label, $status, $message, $detail = '' ) {
		return array(
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
			'detail'  => $detail,
		);
	}
}
