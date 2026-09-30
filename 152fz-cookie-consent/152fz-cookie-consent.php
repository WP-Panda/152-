<?php
/**
 * Plugin Name: 152FZ Cookie & Consent Guard
 * Description: Consent banner, tracker blocking, form consent and an auditable consent log.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Text Domain: 152fz-cookie-consent
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RCC_VERSION', '1.0.0' );
define( 'RCC_FILE', __FILE__ );
define( 'RCC_PATH', plugin_dir_path( __FILE__ ) );
define( 'RCC_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( ! str_starts_with( $class, 'RCC\\' ) ) {
			return;
		}
		$name = substr( $class, 4 );
		if ( str_starts_with( $name, 'Integrations\\' ) ) {
			$file = RCC_PATH . 'includes/integrations/class-' . strtolower( str_replace( '_', '-', substr( $name, 13 ) ) ) . '.php';
		} else {
			$file = RCC_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		}
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'RCC\\Logger', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RCC\\Logger', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'RCC\\Plugin', 'boot' ) );

/** Enqueue a consent-gated script through WordPress's script registry. */
function rcc_enqueue_script( string $handle, string $src, string $category = 'analytics' ): void {
	$category = sanitize_key( $category );
	if ( ! isset( \RCC\Plugin::options()['categories'][ $category ] ) ) {
		$category = 'analytics';
	}
	wp_enqueue_script( $handle, esc_url_raw( $src ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_script_add_data( $handle, 'rcc_category', $category );
}
