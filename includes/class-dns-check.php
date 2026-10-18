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
			$checks[] = self::item( __( 'Sender domain', 'grs-smtp' ), 'error', __( 'No sender address configured.', 'grs-smtp' ) );
			return $checks;
		}

		if ( ! function_exists( 'dns_get_record' ) ) {
			$checks[] = self::item( __( 'DNS', 'grs-smtp' ), 'info', __( 'The server does not allow DNS queries from PHP (dns_get_record not available). Use an external tool such as MXToolbox.', 'grs-smtp' ) );
			return $checks;
		}

		$checks[] = self::check_alignment( $domain );

		if ( ! self::resolvable( $domain ) ) {
			$checks[] = self::item( __( 'DNS', 'grs-smtp' ), 'info', __( 'Unable to query the domain\'s DNS from this server (resolution blocked or domain does not exist). Check SPF, DKIM and DMARC with an external tool such as MXToolbox.', 'grs-smtp' ) );
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
		$label    = __( 'Sender and SMTP account', 'grs-smtp' );
		$username = strtolower( (string) Settings::get( 'username' ) );

		if ( ! is_email( $username ) ) {
			return self::item( $label, 'info', __( 'The SMTP username is not an email address: check with the provider that it can send on behalf of the sender domain.', 'grs-smtp' ) );
		}

		$user_domain = substr( (string) strrchr( $username, '@' ), 1 );
		if ( $user_domain === $domain ) {
			return self::item( $label, 'ok', __( 'The sender uses the same domain as the SMTP account.', 'grs-smtp' ) );
		}

		return self::item(
			$label,
			'warn',
			sprintf(
				/* translators: 1: dominio mittente, 2: dominio account SMTP. */
				__( 'The sender (@%1$s) differs from the SMTP account (@%2$s). Many providers reject these emails or mark them as spam: use a sender on the same domain or authorize it with the provider.', 'grs-smtp' ),
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
			return self::item( $label, 'info', __( 'Unable to query DNS right now.', 'grs-smtp' ) );
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
			return self::item( $label, 'error', __( 'No SPF record found. Add one that authorizes the SMTP server in use (ask your email provider for the value).', 'grs-smtp' ) );
		}
		if ( count( $spf ) > 1 ) {
			return self::item( $label, 'error', __( 'There are multiple SPF records: this is an error and providers treat them as invalid. Merge them into a single record.', 'grs-smtp' ), implode( "\n", $spf ) );
		}

		$record = strtolower( $spf[0] );

		if ( preg_match( '/(^|\s)\+?all(\s|$)/', $record ) ) {
			return self::item( $label, 'warn', __( 'The SPF record ends with "+all": it authorizes anyone to send on behalf of the domain. Use "~all" or "-all".', 'grs-smtp' ), $spf[0] );
		}

		$lookups = preg_match_all( '/\b(include:|a\b|a:|mx\b|mx:|ptr|exists:|redirect=)/', $record );
		if ( $lookups > 10 ) {
			return self::item( $label, 'error', __( 'The SPF record exceeds the limit of 10 DNS lookups: it is treated as invalid.', 'grs-smtp' ), $spf[0] );
		}

		$providers = Providers::all();
		$provider  = (string) Settings::get( 'provider' );
		$include   = isset( $providers[ $provider ]['spf'] ) ? (string) $providers[ $provider ]['spf'] : '';

		if ( '' !== $include ) {
			if ( false !== strpos( $record, 'include:' . strtolower( $include ) ) ) {
				/* translators: %s: meccanismo SPF del provider. */
				return self::item( $label, 'ok', sprintf( __( 'SPF record present and includes the selected provider (%s).', 'grs-smtp' ), $include ), $spf[0] );
			}
			/* translators: %s: meccanismo SPF del provider. */
			return self::item( $label, 'warn', sprintf( __( 'SPF record present but it does not contain "include:%s" required by the selected provider.', 'grs-smtp' ), $include ), $spf[0] );
		}

		return self::item( $label, 'ok', __( 'SPF record present and well formed. Check with the provider that it authorizes its sending server.', 'grs-smtp' ), $spf[0] );
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
			return self::item( $label, 'warn', __( 'No DMARC record. Gmail, Yahoo and Microsoft increasingly require it: add at least "v=DMARC1; p=none;" on the domain\'s _dmarc.', 'grs-smtp' ) );
		}

		$policy = 'none';
		if ( preg_match( '/\bp\s*=\s*(none|quarantine|reject)/i', $dmarc[0], $match ) ) {
			$policy = strtolower( $match[1] );
		}

		/* translators: %s: policy DMARC. */
		return self::item( $label, 'ok', sprintf( __( 'DMARC record present (policy: %s).', 'grs-smtp' ), $policy ), $dmarc[0] );
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
			return self::item( $label, 'info', __( 'To verify the DKIM signature enter the selector given by the provider (e.g. "default", "google", "selector1").', 'grs-smtp' ) );
		}
		if ( ! preg_match( '/^[a-z0-9._-]+$/', $selector ) ) {
			return self::item( $label, 'error', __( 'Invalid selector.', 'grs-smtp' ) );
		}

		$name    = $selector . '._domainkey.' . $domain;
		$records = self::txt( $name );
		if ( is_array( $records ) ) {
			foreach ( $records as $txt ) {
				if ( false !== stripos( $txt, 'p=' ) ) {
					/* translators: %s: nome DNS. */
					return self::item( $label, 'ok', sprintf( __( 'DKIM key found on %s.', 'grs-smtp' ), $name ), $txt );
				}
			}
		}

		/* translators: %s: nome DNS. */
		return self::item( $label, 'warn', sprintf( __( 'No DKIM key on %s. Check the selector or enable DKIM in the provider\'s panel.', 'grs-smtp' ), $name ) );
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
