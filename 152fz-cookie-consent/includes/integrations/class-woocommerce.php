<?php
namespace RCC\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woocommerce {
	public function __construct() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		// Core cart, session, checkout, blocks and payment gateway assets are never gated.
		add_filter( 'rcc_tracker_rules', array( $this, 'rules' ) );
	}

	public function rules( array $rules ): array {
		// This filter is available for store-specific pixel rules; WooCommerce cookies stay necessary.
		return $rules;
	}
}
