<?php
namespace RCC\Integrations;

use RCC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ninja_Forms {
	public function __construct() {
		if ( ! function_exists( 'Ninja_Forms' ) ) {
			return;
		}
		add_filter( 'ninja_forms_submit_data', array( $this, 'validate' ) );
	}

	public function validate( array $data ): array {
		if ( ! Integrations::submitted() ) {
			$data['errors']['form']['rcc_personal_data_consent'] = Integrations::message();
		}
		return $data;
	}
}
