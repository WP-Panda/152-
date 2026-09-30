<?php
namespace RCC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rcc_consents';
	}

	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			consent_id char(36) NOT NULL,
			policy_version char(16) NOT NULL,
			created_at datetime NOT NULL,
			ip_hash char(64) NOT NULL,
			user_agent varchar(255) NOT NULL,
			categories longtext NOT NULL,
			choice varchar(12) NOT NULL,
			url text NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY choice (choice)
		) {$charset};" );
		if ( ! wp_next_scheduled( 'rcc_cleanup' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'rcc_cleanup' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'rcc_cleanup' );
	}

	public function __construct() {
		add_action( 'rcc_cleanup', array( $this, 'cleanup' ) );
	}

	public static function insert( string $id, array $categories, string $choice ): void {
		global $wpdb;
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		$url = isset( $_POST['url'] ) && is_string( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$url = substr( $url, 0, 2048 );
		$wpdb->insert( self::table(), array(
			'consent_id' => $id, 'policy_version' => Plugin::version( Plugin::options() ), 'created_at' => current_time( 'mysql', true ),
			'ip_hash' => hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ),
			'user_agent' => $agent, 'categories' => wp_json_encode( $categories ),
			'choice' => $choice, 'url' => $url, 'user_id' => get_current_user_id(),
		), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ) );
	}

	public function cleanup(): void {
		global $wpdb;
		$months = (int) Plugin::options()['retention'];
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
