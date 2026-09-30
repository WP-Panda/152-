<?php
namespace RCC\Integrations;

use RCC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Formidable {
	public function __construct() {
		if ( ! defined( 'FRM_VERSION' ) ) {
			return;
		}
		add_filter( 'frm_validate_entry', array( $this, 'validate' ), 10, 2 );
	}

	public function validate( array $errors, $values ): array {
		if ( ! Integrations::submitted() ) {
			$errors['rcc_personal_data_consent'] = Integrations::message();
		}
		return $errors;
	}
}
