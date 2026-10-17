<?php
/**
 * Preset dei provider SMTP più usati.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Elenco preset: compilano host, porta e cifratura. Valori verificati a ottobre 2026.
 */
class Providers {

	/**
	 * Tutti i preset.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$providers = array(
			'custom'    => array(
				'label' => __( 'Personalizzato', 'grs-smtp' ),
				'host'  => '',
				'port'  => 465,
				'enc'   => 'ssl',
				'spf'   => '',
				'note'  => __( 'Inserisci i dati forniti dal provider della casella email.', 'grs-smtp' ),
			),
			'aruba'     => array(
				'label' => 'Aruba',
				'host'  => 'smtps.aruba.it',
				'port'  => 465,
				'enc'   => 'ssl',
				'spf'   => '',
				'note'  => __( 'Caselle su dominio. Per caselle @aruba.it usa smtp.aruba.it. Utente: indirizzo email completo.', 'grs-smtp' ),
			),
			'register'  => array(
				'label' => 'Register.it',
				'host'  => 'authsmtp.securemail.pro',
				'port'  => 465,
				'enc'   => 'ssl',
				'spf'   => '',
				'note'  => __( 'Utente: indirizzo email completo.', 'grs-smtp' ),
			),
			'google'    => array(
				'label' => 'Gmail / Google Workspace',
				'host'  => 'smtp.gmail.com',
				'port'  => 587,
				'enc'   => 'tls',
				'spf'   => '_spf.google.com',
				'note'  => __( 'Serve una "password per le app" (verifica in due passaggi attiva), non la password dell\'account.', 'grs-smtp' ),
			),
			'microsoft' => array(
				'label' => 'Microsoft 365 / Outlook',
				'host'  => 'smtp.office365.com',
				'port'  => 587,
				'enc'   => 'tls',
				'spf'   => 'spf.protection.outlook.com',
				'note'  => __( 'Microsoft sta dismettendo l\'accesso SMTP con password: verifica che "SMTP autenticato" sia abilitato sulla casella e sul tenant.', 'grs-smtp' ),
			),
			'brevo'     => array(
				'label' => 'Brevo',
				'host'  => 'smtp-relay.brevo.com',
				'port'  => 587,
				'enc'   => 'tls',
				'spf'   => 'spf.brevo.com',
				'note'  => __( 'Utente: login SMTP indicato da Brevo. Password: chiave SMTP (non la chiave API). Configura DKIM del dominio in Brevo.', 'grs-smtp' ),
			),
			'hosting'   => array(
				'label' => __( 'Hosting cPanel / Plesk (mail.dominio)', 'grs-smtp' ),
				'host'  => 'mail.' . self::site_domain(),
				'port'  => 465,
				'enc'   => 'ssl',
				'spf'   => '',
				'note'  => __( 'Server di posta dell\'hosting del sito. Verifica il nome esatto nel pannello dell\'hosting.', 'grs-smtp' ),
			),
		);

		/**
		 * Filtra i preset dei provider.
		 *
		 * @param array $providers Preset.
		 */
		return (array) apply_filters( 'grs_smtp_providers', $providers );
	}

	/**
	 * Dominio del sito senza www.
	 *
	 * @return string
	 */
	public static function site_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';
		return (string) preg_replace( '/^www\./', '', $host );
	}
}
