<?php
namespace RCC\Integrations;

use RCC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fluent_Forms {
	public function __construct() {
		if ( ! defined( 'FLUENTFORM_VERSION' ) ) {
			return;
		}
		add_filter( 'fluentform/validation_errors', array( $this, 'validate' ), 10, 3 );
	}

	public function validate( array $errors, $form_data, $form ): array {
		if ( ! Integrations::submitted() ) {
			$errors['rcc_personal_data_consent'] = array( Integrations::message() );
		}
		return $errors;
	}
}
