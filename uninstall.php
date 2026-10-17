<?php
/**
 * Disinstallazione: rimuove impostazioni, password, log e cron (salvo opzione "conserva dati").
 *
 * @package GRSDigital\Smtp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Pulizia del sito corrente.
 *
 * @return void
 */
function grs_smtp_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'grs_smtp_settings', array() );
	if ( is_array( $settings ) && ! empty( $settings['keep_data'] ) ) {
		return;
	}

	wp_clear_scheduled_hook( 'grs_smtp_purge_logs' );

	foreach ( array( 'grs_smtp_settings', 'grs_smtp_password', 'grs_smtp_version', 'grs_smtp_db_version', 'grs_smtp_failure_state' ) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'grs_smtp_alert_sent' );

	$table = $wpdb->prefix . 'grs_smtp_log';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Rimozione tabella del plugin.
}

if ( is_multisite() ) {
	$grs_smtp_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $grs_smtp_sites as $grs_smtp_site_id ) {
		switch_to_blog( $grs_smtp_site_id );
		grs_smtp_uninstall_site();
		restore_current_blog();
	}
} else {
	grs_smtp_uninstall_site();
}
