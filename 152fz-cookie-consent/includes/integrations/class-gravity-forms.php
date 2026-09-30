<?php
namespace RCC\Integrations;

use RCC\Frontend;
use RCC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gravity_Forms {
	public function __construct() {
		if ( ! class_exists( 'GFForms' ) ) {
			return;
		}
		add_filter( 'gform_submit_button', array( $this, 'field' ), 10, 2 );
		add_filter( 'gform_validation', array( $this, 'validate' ) );
	}

	public function field( string $button, array $form ): string {
		return Frontend::checkbox_static() . $button;
	}

	public function validate( array $result ): array {
		if ( ! Integrations::submitted() ) {
			$result['is_valid'] = false;
			$result['form']['failed_validation'] = true;
			$result['form']['validation_message'] = Integrations::message();
		}
		return $result;
	}
}
