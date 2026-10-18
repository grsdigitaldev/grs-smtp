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
				'label' => __( 'Custom', 'grs-smtp' ),
				'host'  => '',
				'port'  => 465,
				'enc'   => 'ssl',
				'spf'   => '',
				'note'  => __( 'Enter the details provided by your mailbox provider.', 'grs-smtp' ),
			),
			'aruba'     => array(
				'label' => 'Aruba',
				'host'  => 'smtps.aruba.it',
				'port'  => 465,
				'enc'   => 'ssl',
				'spf'   => '',
				'note'  => __( 'Mailboxes on your own domain. For @aruba.it mailboxes use smtp.aruba.it. Username: full email address.', 'grs-smtp' ),
			),
			'register'  => array(
				'label' => 'Register.it',
				'host'  => 'authsmtp.securemail.pro',
				'port'  => 465,
				'enc'   => 'ssl',
				'spf'   => '',
				'note'  => __( 'Username: full email address.', 'grs-smtp' ),
			),
			'google'    => array(
				'label' => 'Gmail / Google Workspace',
				'host'  => 'smtp.gmail.com',
				'port'  => 587,
				'enc'   => 'tls',
				'spf'   => '_spf.google.com',
				'note'  => __( 'An "app password" is required (2-step verification enabled), not the account password.', 'grs-smtp' ),
			),
			'microsoft' => array(
				'label' => 'Microsoft 365 / Outlook',
				'host'  => 'smtp.office365.com',
				'port'  => 587,
				'enc'   => 'tls',
				'spf'   => 'spf.protection.outlook.com',
				'note'  => __( 'Microsoft is retiring SMTP access with password: check that "Authenticated SMTP" is enabled on the mailbox and on the tenant.', 'grs-smtp' ),
			),
			'brevo'     => array(
				'label' => 'Brevo',
				'host'  => 'smtp-relay.brevo.com',
				'port'  => 587,
				'enc'   => 'tls',
				'spf'   => 'spf.brevo.com',
				'note'  => __( 'Username: SMTP login given by Brevo. Password: SMTP key (not the API key). Set up the domain\'s DKIM in Brevo.', 'grs-smtp' ),
			),
			'hosting'   => array(
				'label' => __( 'cPanel / Plesk hosting (mail.domain)', 'grs-smtp' ),
				'host'  => 'mail.' . self::site_domain(),
				'port'  => 465,
				'enc'   => 'ssl',
				'spf'   => '',
				'note'  => __( 'Mail server of the site\'s hosting. Check the exact name in the hosting panel.', 'grs-smtp' ),
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
