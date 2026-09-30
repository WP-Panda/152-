<?php
namespace RCC\Integrations;

use RCC\Frontend;
use RCC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Contact_Form_7 {
	public function __construct() {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			return;
		}
		add_filter( 'wpcf7_form_elements', array( $this, 'append' ) );
		add_filter( 'wpcf7_validate', array( $this, 'validate' ), 20, 2 );
		add_action( 'wpcf7_before_send_mail', array( $this, 'before_send' ), 10, 2 );
	}

	public function append( string $html ): string {
		if ( str_contains( $html, 'rcc_personal_data_consent' ) ) {
			return $html;
		}
		return $html . Frontend::checkbox_static();
	}

	public function before_send( $form, &$abort ): void {
		if ( ! Integrations::submitted() ) {
			$abort = true;
		}
	}

	public function validate( $result, $tags ) {
		if ( ! Integrations::submitted() && is_array( $tags ) && ! empty( $tags[0] ) ) {
			$result->invalidate( $tags[0], Integrations::message() );
		}
		return $result;
	}
}
