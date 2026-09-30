<?php
namespace RCC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Frontend {
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 1 );
		add_action( 'wp_footer', array( $this, 'panel' ), 100 );
		add_shortcode( 'rcc_settings', fn() => '<button type="button" data-rcc-open>' . esc_html__( 'Настройки cookies', '152fz-cookie-consent' ) . '</button>' );
		add_shortcode( 'rcc_accept_button', fn() => '<button type="button" data-rcc-choice="accept">' . esc_html__( 'Принять cookies', '152fz-cookie-consent' ) . '</button>' );
		add_shortcode( 'rcc_consent_checkbox', array( $this, 'checkbox' ) );
		add_shortcode( 'rcc_cookie_policy', array( $this, 'policy' ) );
		add_shortcode( 'rcc_revoke_button', fn() => '<button type="button" data-rcc-choice="revoke">' . esc_html__( 'Отозвать согласие', '152fz-cookie-consent' ) . '</button>' );
	}

	public static function policy_link(): string {
		$url = get_privacy_policy_url();
		return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Политика конфиденциальности', '152fz-cookie-consent' ) . '</a>' : esc_html__( 'Политика конфиденциальности', '152fz-cookie-consent' );
	}

	public function policy(): string {
		$rows = self::cookies();
		$html = '<table class="rcc-cookie-table"><thead><tr><th>' . esc_html__( 'Категория', '152fz-cookie-consent' ) . '</th><th>Cookie</th><th>' . esc_html__( 'Поставщик', '152fz-cookie-consent' ) . '</th><th>' . esc_html__( 'Срок', '152fz-cookie-consent' ) . '</th><th>' . esc_html__( 'Цель', '152fz-cookie-consent' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( $row as $column ) {
				$html .= '<td>' . esc_html( $column ) . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table>';
	}

	public static function cookies(): array {
		$rows = array();
		foreach ( explode( "\n", (string) Plugin::options()['cookies'] ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 5 ) );
			if ( 5 === count( $parts ) ) {
				$rows[] = $parts;
			}
		}
		return $rows;
	}

	public function checkbox(): string {
		return self::checkbox_static();
	}

	public static function checkbox_static(): string {
		return '<label class="rcc-form-consent"><input type="checkbox" name="rcc_personal_data_consent" value="1" required> ' . sprintf(
			/* translators: %s: privacy policy link */
			wp_kses_post( __( 'Я согласен(на) на обработку персональных данных. %s', '152fz-cookie-consent' ) ),
			self::policy_link()
		) . '</label>';
	}

	public static function enabled(): bool {
		$options = Plugin::options();
		return ! ( ( $options['hide_admin'] && current_user_can( 'manage_options' ) ) || ( $options['exclude_ids'] && in_array( (string) get_queried_object_id(), array_map( 'trim', explode( ',', $options['exclude_ids'] ) ), true ) ) );
	}

	public function assets(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$options = Plugin::options();
		wp_enqueue_style( 'rcc-front', RCC_URL . 'assets/css/frontend.css', array(), RCC_VERSION );
		wp_enqueue_script( 'rcc-front', RCC_URL . 'assets/js/frontend.js', array(), RCC_VERSION, false );
		$rules = Scanner::rules();
		$rules = array_map( static fn( $list ) => array_values( array_filter( $list, 'is_string' ) ), $rules );
		$config = array(
			'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'rcc_public' ),
			'version' => Plugin::version( $options ), 'duration' => (int) $options['duration'],
			'delay' => (int) $options['delay'], 'categories' => array_keys( $options['categories'] ),
			'rules' => $rules, 'customRules' => (string) $options['rules'],
			'formAuto' => (bool) $options['form_auto'], 'formCookie' => (bool) $options['form_cookie'],
			'googleMode' => (bool) $options['google_mode'], 'debug' => (bool) $options['debug'],
			'requiredMessage' => __( 'Необходимо согласие на обработку персональных данных.', '152fz-cookie-consent' ),
			'cookieMessage' => __( 'Сначала выберите настройки cookies.', '152fz-cookie-consent' ),
			'saveError' => __( 'Не удалось сохранить выбор. Попробуйте снова.', '152fz-cookie-consent' ),
			'checkboxHtml' => $this->checkbox(),
		);
		wp_add_inline_script( 'rcc-front', 'window.rccConfig = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		if ( $options['google_mode'] ) {
			wp_add_inline_script( 'rcc-front', "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('consent','default',{ad_storage:'denied',analytics_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',functionality_storage:'granted',security_storage:'granted',wait_for_update:500});", 'before' );
		}
		$css = ':root{--rcc-bg:' . sanitize_hex_color( $options['background'] ) . ';--rcc-text:' . sanitize_hex_color( $options['text_color'] ) . ';--rcc-button:' . sanitize_hex_color( $options['button_color'] ) . ';--rcc-button-text:' . sanitize_hex_color( $options['button_text'] ) . ';--rcc-radius:' . (int) $options['radius'] . 'px;--rcc-width:' . (int) $options['width'] . 'px}';
		wp_add_inline_style( 'rcc-front', $css . (string) $options['custom_css'] );
	}

	public function panel(): void {
		$options = Plugin::options();
		if ( ! self::enabled() ) {
			return;
		}
		$description = str_replace( '{privacy_policy_link}', self::policy_link(), wp_kses_post( $options['description'] ) );
		do_action( 'rcc_before_banner' );
		?>
		<div id="rcc-root" class="rcc-root rcc-<?php echo esc_attr( $options['position'] ); ?>" data-overlay="<?php echo $options['overlay'] ? '1' : '0'; ?>" data-animation="<?php echo esc_attr( $options['animation'] ); ?>" hidden>
			<div class="rcc-backdrop" aria-hidden="true"></div>
			<section class="rcc-banner" role="region" aria-label="<?php esc_attr_e( 'Согласие на cookies', '152fz-cookie-consent' ); ?>">
				<button type="button" class="rcc-dismiss" data-rcc-dismiss aria-label="<?php esc_attr_e( 'Закрыть', '152fz-cookie-consent' ); ?>">×</button>
				<?php if ( $options['logo'] ) : ?><img class="rcc-logo" src="<?php echo esc_url( $options['logo'] ); ?>" alt=""><?php endif; ?>
				<div class="rcc-copy"><h2><?php echo esc_html( $options['title'] ); ?></h2><p><?php echo wp_kses_post( $description ); ?></p></div>
				<div class="rcc-actions"><button type="button" data-rcc-choice="reject"><?php echo esc_html( $options['reject'] ); ?></button><button type="button" data-rcc-open><?php echo esc_html( $options['settings'] ); ?></button><button type="button" class="rcc-primary" data-rcc-choice="accept"><?php echo esc_html( $options['accept'] ); ?></button></div>
			</section>
		</div>
		<div id="rcc-dialog" class="rcc-dialog-wrap" data-animation="<?php echo esc_attr( $options['animation'] ); ?>" hidden>
			<div class="rcc-backdrop" aria-hidden="true"></div>
			<section class="rcc-dialog" role="dialog" aria-modal="true" aria-labelledby="rcc-dialog-title" tabindex="-1">
				<button class="rcc-close" type="button" data-rcc-close aria-label="<?php esc_attr_e( 'Закрыть', '152fz-cookie-consent' ); ?>">×</button>
				<h2 id="rcc-dialog-title"><?php echo esc_html( $options['title'] ); ?></h2>
				<label><?php esc_html_e( 'Поиск cookies', '152fz-cookie-consent' ); ?> <input type="search" id="rcc-search"></label>
				<div class="rcc-categories">
					<?php foreach ( $options['categories'] as $key => $data ) : ?>
						<label class="rcc-category" data-rcc-search="<?php echo esc_attr( $data['name'] . ' ' . $data['description'] ); ?>">
							<span><strong><?php echo esc_html( $data['name'] ); ?></strong><small><?php echo esc_html( $data['description'] ); ?></small></span>
							<input type="checkbox" data-rcc-toggle="<?php echo esc_attr( $key ); ?>" <?php checked( 'necessary', $key ); ?> <?php disabled( 'necessary', $key ); ?> aria-label="<?php echo esc_attr( $data['name'] ); ?>">
						</label>
						<?php foreach ( self::cookies() as $cookie ) : ?>
							<?php if ( $cookie[0] === $key ) : ?><div class="rcc-cookie-row" data-rcc-search="<?php echo esc_attr( implode( ' ', $cookie ) ); ?>"><strong><?php echo esc_html( $cookie[1] ); ?></strong> — <?php echo esc_html( $cookie[2] . ' · ' . $cookie[3] . ' · ' . $cookie[4] ); ?></div><?php endif; ?>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</div>
				<button type="button" class="rcc-revoke" data-rcc-choice="revoke"><?php esc_html_e( 'Отозвать согласие', '152fz-cookie-consent' ); ?></button>
				<div class="rcc-actions"><button type="button" data-rcc-choice="reject"><?php echo esc_html( $options['reject'] ); ?></button><button type="button" data-rcc-choice="save"><?php esc_html_e( 'Сохранить настройки', '152fz-cookie-consent' ); ?></button><button type="button" class="rcc-primary" data-rcc-choice="accept"><?php echo esc_html( $options['accept'] ); ?></button></div>
			</section>
		</div>
		<?php if ( $options['floating'] ) : ?><button id="rcc-float" type="button" data-rcc-open hidden aria-label="<?php esc_attr_e( 'Настройки cookies', '152fz-cookie-consent' ); ?>">◉</button><?php endif; ?>
		<?php
	}
}
