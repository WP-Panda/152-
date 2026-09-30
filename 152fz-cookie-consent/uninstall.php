<?php
/** Remove plugin-owned data only when the plugin is explicitly deleted from WordPress. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'rcc_consents' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
delete_option( 'rcc_options' );
wp_clear_scheduled_hook( 'rcc_cleanup' );
