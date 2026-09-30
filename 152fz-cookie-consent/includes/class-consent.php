<?php
namespace RCC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Consent {
	public function __construct() {
		add_action( 'wp_ajax_rcc_save', array( $this, 'save' ) );
		add_action( 'wp_ajax_nopriv_rcc_save', array( $this, 'save' ) );
		add_action( 'wp_ajax_rcc_nonce', array( $this, 'nonce' ) );
		add_action( 'wp_ajax_nopriv_rcc_nonce', array( $this, 'nonce' ) );
	}

	public static function categories( $input ): array {
		$options = Plugin::options();
		$allowed = array_keys( $options['categories'] );
		$result = array( 'necessary' => true );
		foreach ( $allowed as $key ) {
			if ( 'necessary' !== $key ) {
				$result[ $key ] = is_array( $input ) && ! empty( $input[ $key ] );
			}
		}
		return $result;
	}

	public function nonce(): void {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'rcc_public' ) ) );
	}

	public function save(): void {
		check_ajax_referer( 'rcc_public', 'nonce' );
		$action = isset( $_POST['choice'] ) && is_string( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';
		if ( ! in_array( $action, array( 'accept', 'reject', 'save', 'revoke' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid choice' ), 400 );
		}
		$options = Plugin::options();
		$raw = isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ? wp_unslash( $_POST['categories'] ) : array();
		$categories = self::categories( $raw );
		if ( 'accept' === $action ) {
			$categories = array_fill_keys( array_keys( $options['categories'] ), true );
		} elseif ( 'reject' === $action || 'revoke' === $action ) {
			$categories = self::categories( array() );
		}
		$id = wp_generate_uuid4();
		$version = Plugin::version( $options );
		$record = array( 'id' => $id, 'version' => $version, 'categories' => $categories, 'time' => time() );
		$seconds = max( 1, (int) $options['duration'] ) * DAY_IN_SECONDS;
		// Cookie is a UI preference, not evidence of consent: the server log is the audit record.
		setcookie( 'rcc_consent', wp_json_encode( $record ), array(
			'expires' => time() + $seconds, 'path' => COOKIEPATH ?: '/',
			'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax',
		) );
		Logger::insert( $id, $categories, $action );
		do_action( 'rcc_consent_updated', $categories, $action, $id );
		wp_send_json_success( $record );
	}
}
