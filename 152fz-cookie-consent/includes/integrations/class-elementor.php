<?php
namespace RCC\Integrations;

use RCC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor {
	public function __construct() {
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return;
		}
		add_action( 'elementor_pro/forms/validation', array( $this, 'validate' ), 10, 2 );
	}

	public function validate( $record, $handler ): void {
		if ( ! Integrations::submitted() ) {
			$handler->add_error_message( Integrations::message() );
		}
	}
}
