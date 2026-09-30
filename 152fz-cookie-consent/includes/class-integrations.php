<?php
namespace RCC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Integrations {
	public static function names(): array {
		return array(
			'contact_form_7' => 'Contact Form 7', 'wpforms' => 'WPForms',
			'gravity_forms' => 'Gravity Forms', 'ninja_forms' => 'Ninja Forms',
			'fluent_forms' => 'Fluent Forms', 'elementor' => 'Elementor Pro Forms',
			'formidable' => 'Formidable Forms', 'woocommerce' => 'WooCommerce',
		);
	}

	public function __construct() {
		$options = Plugin::options();
		foreach ( self::names() as $slug => $name ) {
			if ( ! ( $options['integrations'][ $slug ] ?? 1 ) ) {
				continue;
			}
			$class = 'RCC\\Integrations\\' . implode( '_', array_map( 'ucfirst', explode( '_', $slug ) ) );
			if ( class_exists( $class ) ) {
				new $class();
			}
		}
	}

	/** A checked checkbox must be present in the actual submitted request, not merely in the DOM. */
	public static function submitted(): bool {
		return isset( $_POST['rcc_personal_data_consent'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['rcc_personal_data_consent'] ) );
	}

	public static function message(): string {
		return __( 'Необходимо согласие на обработку персональных данных.', '152fz-cookie-consent' );
	}
}
