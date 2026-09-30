<?php
namespace RCC\Integrations;

use RCC\Frontend;
use RCC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Wpforms {
	public function __construct() {
		if ( ! function_exists( 'wpforms' ) ) {
			return;
		}
		add_action( 'wpforms_display_submit_before', array( $this, 'field' ) );
		add_action( 'wpforms_process', array( $this, 'validate' ), 10, 3 );
	}

	public function field(): void {
		echo Frontend::checkbox_static(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function validate( $fields, $entry, $form_data ): void {
		if ( ! Integrations::submitted() ) {
			wpforms()->process->errors[ $form_data['id'] ]['header'] = Integrations::message();
		}
	}
}
