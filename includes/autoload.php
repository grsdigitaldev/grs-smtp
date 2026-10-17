<?php
/**
 * Autoloader delle classi del plugin.
 *
 * GRSDigital\Smtp\Log_Table       -> includes/class-log-table.php
 * GRSDigital\Smtp\Admin\Admin     -> includes/admin/class-admin.php
 *
 * @package GRSDigital\Smtp
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'GRSDigital\\Smtp\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$class    = array_pop( $parts );
		$dir      = '';

		foreach ( $parts as $part ) {
			$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}

		$file = GRS_SMTP_PATH . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
