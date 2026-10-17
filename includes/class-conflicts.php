<?php
/**
 * Rilevamento di altri plugin che gestiscono l'invio email.
 *
 * @package GRSDigital\Smtp
 */

namespace GRSDigital\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Segnala plugin SMTP concorrenti o wp_mail() sostituito.
 */
class Conflicts {

	/**
	 * Plugin noti (file principale => nome).
	 *
	 * @return array<string,string>
	 */
	public static function known() {
		return (array) apply_filters(
			'grs_smtp_conflicting_plugins',
			array(
				'wp-mail-smtp/wp_mail_smtp.php'     => 'WP Mail SMTP',
				'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP Pro',
				'fluent-smtp/fluent-smtp.php'       => 'FluentSMTP',
				'post-smtp/postman-smtp.php'        => 'Post SMTP',
				'gosmtp/gosmtp.php'                 => 'GoSMTP',
				'gosmtp-pro/gosmtp-pro.php'         => 'GoSMTP Pro',
				'easy-wp-smtp/easy-wp-smtp.php'     => 'Easy WP SMTP',
				'smtp-mailer/main.php'              => 'SMTP Mailer',
				'wp-smtp/wp-smtp.php'               => 'WP SMTP',
				'mailin/sendinblue.php'             => 'Brevo (Sendinblue)',
				'suremails/suremails.php'           => 'SureMail',
				'site-mailer/site-mailer.php'       => 'Site Mailer',
				'wp-ses/wp-ses.php'                 => 'WP Offload SES',
				'mailgun/mailgun.php'               => 'Mailgun',
				'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
			)
		);
	}

	/**
	 * Plugin concorrenti attivi.
	 *
	 * @return string[] Nomi.
	 */
	public static function active_plugins() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$found = array();
		foreach ( self::known() as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	/**
	 * File che definisce wp_mail() se diverso da quello di WordPress.
	 *
	 * @return string Percorso relativo, vuoto se wp_mail() è quello originale o non ancora definito.
	 */
	public static function wp_mail_override() {
		if ( ! function_exists( 'wp_mail' ) ) {
			return '';
		}
		try {
			$reflection = new \ReflectionFunction( 'wp_mail' );
			$file       = (string) $reflection->getFileName();
		} catch ( \ReflectionException $e ) {
			return '';
		}
		$core = wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' );
		$file = wp_normalize_path( $file );
		if ( '' === $file || $file === $core ) {
			return '';
		}
		return str_replace( wp_normalize_path( ABSPATH ), '', $file );
	}
}
