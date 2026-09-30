<?php
namespace RCC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	public static function defaults(): array {
		return array(
			'position' => 'bottom', 'background' => '#14213d', 'text_color' => '#ffffff',
			'button_color' => '#fca311', 'button_text' => '#14213d', 'radius' => 12,
			'width' => 960, 'delay' => 0, 'duration' => 180, 'retention' => 12,
			'title' => __( 'Настройки конфиденциальности', '152fz-cookie-consent' ),
			'description' => __( 'Мы используем cookies для работы сайта и, с вашего разрешения, для аналитики и персонализации. Подробнее: {privacy_policy_link}.', '152fz-cookie-consent' ),
			'accept' => __( 'Принять все', '152fz-cookie-consent' ),
			'reject' => __( 'Только необходимые', '152fz-cookie-consent' ),
			'settings' => __( 'Настройки', '152fz-cookie-consent' ),
			'logo' => '', 'cookies' => implode( "\n", array(
				'necessary|woocommerce_cart_hash|WooCommerce|Session|Cart state',
				'necessary|woocommerce_items_in_cart|WooCommerce|Session|Cart state',
				'necessary|wp_woocommerce_session_*|WooCommerce|2 days|Customer session',
				'necessary|woocommerce_recently_viewed|WooCommerce|Session|Recently viewed products',
			) ), 'exclude_ids' => '', 'hide_admin' => 1, 'overlay' => 0,
			'floating' => 1, 'animation' => 'fade', 'form_auto' => 1, 'form_cookie' => 0,
			'google_mode' => 1, 'debug' => 0, 'custom_css' => '', 'rules' => '',
			'categories' => array(
				'necessary' => array( 'name' => __( 'Необходимые', '152fz-cookie-consent' ), 'description' => __( 'Нужны для работы сайта и не отключаются.', '152fz-cookie-consent' ) ),
				'functional' => array( 'name' => __( 'Функциональные', '152fz-cookie-consent' ), 'description' => __( 'Сохраняют ваши предпочтения.', '152fz-cookie-consent' ) ),
				'analytics' => array( 'name' => __( 'Аналитика', '152fz-cookie-consent' ), 'description' => __( 'Помогают улучшить работу сайта.', '152fz-cookie-consent' ) ),
				'marketing' => array( 'name' => __( 'Маркетинг', '152fz-cookie-consent' ), 'description' => __( 'Используются для рекламы.', '152fz-cookie-consent' ) ),
			),
		);
	}

	public static function options(): array {
		$stored = get_option( 'rcc_options', array() );
		return array_replace( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public static function version( array $options ): string {
		return substr( hash( 'sha256', (string) $options['title'] . '|' . (string) $options['description'] . '|' . wp_json_encode( $options['categories'] ) . '|' . (string) $options['cookies'] ), 0, 16 );
	}

	public static function boot(): void {
		load_plugin_textdomain( '152fz-cookie-consent', false, dirname( plugin_basename( RCC_FILE ) ) . '/languages' );
		$catalog = RCC_PATH . 'languages/' . determine_locale() . '.mo';
		if ( is_readable( $catalog ) ) {
			load_textdomain( '152fz-cookie-consent', $catalog );
		}
		new Consent();
		new Logger();
		new Integrations();
		if ( is_admin() ) {
			new Admin();
		} else {
			new Frontend();
			new Blocker();
		}
	}
}
