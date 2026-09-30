<?php
namespace RCC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {
	private const PAGE = 'rcc-guard';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_rcc_scan', array( $this, 'scan' ) );
		add_action( 'admin_post_rcc_export', array( $this, 'export' ) );
		add_action( 'admin_post_rcc_import', array( $this, 'import' ) );
		add_action( 'admin_post_rcc_csv', array( $this, 'csv' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	public function menu(): void {
		add_options_page( __( '152ФЗ: согласие', '152fz-cookie-consent' ), __( '152ФЗ Cookies', '152fz-cookie-consent' ), 'manage_options', self::PAGE, array( $this, 'render' ) );
	}

	public function assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'rcc-admin', RCC_URL . 'assets/css/admin.css', array(), RCC_VERSION );
		wp_enqueue_script( 'rcc-admin', RCC_URL . 'assets/js/admin.js', array(), RCC_VERSION, true );
	}

	public function notices(): void {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['page'] ) || self::PAGE !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		if ( ! is_ssl() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Включите HTTPS для защиты данных и cookie согласия.', '152fz-cookie-consent' ) . '</p></div>';
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Проверьте местоположение хранения персональных данных и серверов вашего сайта: плагин не определяет юрисдикцию хостинга.', '152fz-cookie-consent' ) . '</p></div>';
	}

	public function register(): void {
		register_setting( 'rcc_group', 'rcc_options', array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize' ), 'default' => Plugin::defaults() ) );
	}

	public function sanitize( $input ): array {
		if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) ) {
			return Plugin::options();
		}
		$old = Plugin::options();
		foreach ( $input as $key => $value ) {
			if ( ! in_array( $key, array( 'integrations', 'categories' ), true ) && ! is_scalar( $value ) ) {
				unset( $input[ $key ] );
			}
		}
		$result = $old;
		foreach ( array( 'position' => array( 'bottom', 'top', 'center', 'corner', 'slide' ), 'animation' => array( 'fade', 'none' ) ) as $key => $allowed ) {
			$value = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : '';
			$result[ $key ] = in_array( $value, $allowed, true ) ? $value : $old[ $key ];
		}
		foreach ( array( 'background', 'text_color', 'button_color', 'button_text' ) as $key ) {
			$result[ $key ] = sanitize_hex_color( $input[ $key ] ?? '' ) ?: $old[ $key ];
		}
		foreach ( array( 'radius' => 40, 'width' => 1600, 'delay' => 60, 'duration' => 730 ) as $key => $max ) {
			$result[ $key ] = min( $max, max( 'duration' === $key ? 1 : 0, absint( $input[ $key ] ?? $old[ $key ] ) ) );
		}
		$result['retention'] = in_array( (int) ( $input['retention'] ?? 12 ), array( 6, 12, 24 ), true ) ? (int) $input['retention'] : 12;
		foreach ( array( 'title', 'accept', 'reject', 'settings', 'exclude_ids' ) as $key ) {
			$result[ $key ] = sanitize_text_field( $input[ $key ] ?? $old[ $key ] );
		}
		$result['description'] = wp_kses_post( $input['description'] ?? $old['description'] );
		$result['logo'] = esc_url_raw( $input['logo'] ?? '' );
		foreach ( array( 'hide_admin', 'overlay', 'floating', 'form_auto', 'form_cookie', 'google_mode', 'debug' ) as $key ) {
			$result[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}
		$result['rules'] = substr( sanitize_textarea_field( $input['rules'] ?? '' ), 0, 10000 );
		$result['cookies'] = substr( sanitize_textarea_field( $input['cookies'] ?? '' ), 0, 20000 );
		// CSS is for trusted administrators only. Reject CSS constructs that can initiate network requests.
		$css = (string) ( $input['custom_css'] ?? '' );
		$result['custom_css'] = substr( preg_replace( '~@import|url\s*\(|</|expression\s*\(|[<>]~i', '', $css ), 0, 10000 );
		$categories = array( 'necessary' => $old['categories']['necessary'] );
		$lines = explode( "\n", (string) ( $input['category_lines'] ?? '' ) );
		foreach ( array_slice( $lines, 0, 20 ) as $line ) {
			$parts = explode( '|', $line, 3 );
			$key = sanitize_key( $parts[0] ?? '' );
			if ( $key && 'necessary' !== $key && ! isset( $categories[ $key ] ) && count( $parts ) >= 2 ) {
				$categories[ $key ] = array( 'name' => sanitize_text_field( $parts[1] ), 'description' => sanitize_text_field( $parts[2] ?? '' ) );
			}
		}
		$result['categories'] = count( $categories ) > 1 ? $categories : $old['categories'];
		$result['integrations'] = array();
		foreach ( Integrations::names() as $slug => $name ) {
			$result['integrations'][ $slug ] = is_array( $input['integrations'] ?? null ) && ! empty( $input['integrations'][ $slug ] ) ? 1 : 0;
		}
		return apply_filters( 'rcc_sanitized_options', $result );
	}

	private function field( string $key, string $label, string $type = 'text' ): void {
		$options = Plugin::options();
		$value = $options[ $key ] ?? '';
		echo '<label class="rcc-field"><span>' . esc_html( $label ) . '</span>';
		if ( 'checkbox' === $type ) {
			echo '<input type="checkbox" name="rcc_options[' . esc_attr( $key ) . ']" value="1" ' . checked( (bool) $value, true, false ) . '>';
		} elseif ( 'textarea' === $type ) {
			echo '<textarea rows="5" name="rcc_options[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" name="rcc_options[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		}
		echo '</label>';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = array( 'dashboard' => __( 'Обзор', '152fz-cookie-consent' ), 'appearance' => __( 'Внешний вид', '152fz-cookie-consent' ), 'categories' => __( 'Категории', '152fz-cookie-consent' ), 'scanner' => __( 'Сканер и cookies', '152fz-cookie-consent' ), 'integrations' => __( 'Интеграции', '152fz-cookie-consent' ), 'texts' => __( 'Тексты', '152fz-cookie-consent' ), 'logs' => __( 'Журнал', '152fz-cookie-consent' ), 'advanced' => __( 'Дополнительно', '152fz-cookie-consent' ) );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'dashboard';
		}
		echo '<div class="wrap rcc-admin"><h1>' . esc_html__( '152FZ Cookie & Consent Guard', '152fz-cookie-consent' ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $name ) {
			echo '<a class="nav-tab ' . ( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=' . $key ) ) . '">' . esc_html( $name ) . '</a>';
		}
		echo '</nav>';
		if ( 'dashboard' === $tab ) {
			$this->dashboard();
		} elseif ( 'logs' === $tab ) {
			$this->logs();
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
			settings_fields( 'rcc_group' );
			// Preserve fields on other tabs across Settings API saves.
			foreach ( Plugin::options() as $key => $value ) {
				if ( in_array( $key, array( 'categories', 'integrations' ), true ) ) {
					continue;
				}
				if ( is_scalar( $value ) ) {
					echo '<input type="hidden" class="rcc-preserve" data-key="' . esc_attr( $key ) . '" name="rcc_options[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
				}
			}
			foreach ( Integrations::names() as $slug => $name ) {
				echo '<input type="hidden" class="rcc-preserve" data-key="integrations-' . esc_attr( $slug ) . '" name="rcc_options[integrations][' . esc_attr( $slug ) . ']" value="' . esc_attr( Plugin::options()['integrations'][ $slug ] ?? 1 ) . '">';
			}
			echo '<input type="hidden" name="rcc_options[category_lines]" value="' . esc_attr( $this->category_lines() ) . '">';
			$this->tab( $tab );
			submit_button( __( 'Сохранить', '152fz-cookie-consent' ) );
			echo '</form>';
			if ( 'scanner' === $tab ) {
				$this->scanner_form();
			}
			if ( 'advanced' === $tab ) {
				$this->import_form();
			}
		}
		echo '</div>';
	}

	private function category_lines(): string {
		$lines = array();
		foreach ( Plugin::options()['categories'] as $key => $data ) {
			if ( 'necessary' !== $key ) {
				$lines[] = $key . '|' . $data['name'] . '|' . $data['description'];
			}
		}
		return implode( "\n", $lines );
	}

	private function tab( string $tab ): void {
		$options = Plugin::options();
		switch ( $tab ) {
			case 'appearance':
				echo '<h2>' . esc_html__( 'Баннер', '152fz-cookie-consent' ) . '</h2><label class="rcc-field"><span>' . esc_html__( 'Положение', '152fz-cookie-consent' ) . '</span><select name="rcc_options[position]">';
				foreach ( array( 'bottom', 'top', 'center', 'corner', 'slide' ) as $value ) {
					echo '<option value="' . esc_attr( $value ) . '" ' . selected( $options['position'], $value, false ) . '>' . esc_html( $value ) . '</option>';
				}
				echo '</select></label>';
				foreach ( array( 'background' => __( 'Цвет фона', '152fz-cookie-consent' ), 'text_color' => __( 'Цвет текста', '152fz-cookie-consent' ), 'button_color' => __( 'Цвет кнопки', '152fz-cookie-consent' ), 'button_text' => __( 'Цвет текста кнопки', '152fz-cookie-consent' ) ) as $key => $label ) {
					$this->field( $key, $label, 'color' );
				}
				foreach ( array( 'width' => __( 'Ширина, px', '152fz-cookie-consent' ), 'radius' => __( 'Скругление, px', '152fz-cookie-consent' ), 'delay' => __( 'Задержка, сек.', '152fz-cookie-consent' ) ) as $key => $label ) {
					$this->field( $key, $label, 'number' );
				}
				$this->field( 'logo', __( 'URL логотипа', '152fz-cookie-consent' ), 'url' );
				echo '<button class="button" type="button" id="rcc-select-logo">' . esc_html__( 'Выбрать логотип', '152fz-cookie-consent' ) . '</button>';
				if ( $options['logo'] ) {
					echo '<p><img id="rcc-logo-preview" src="' . esc_url( $options['logo'] ) . '" alt="" style="max-width:150px;max-height:90px"></p>';
				}
				$this->field( 'overlay', __( 'Затемнение фона', '152fz-cookie-consent' ), 'checkbox' );
				$this->field( 'floating', __( 'Плавающая кнопка', '152fz-cookie-consent' ), 'checkbox' );
				echo '<label class="rcc-field"><span>' . esc_html__( 'Анимация', '152fz-cookie-consent' ) . '</span><select name="rcc_options[animation]">';
				foreach ( array( 'fade', 'none' ) as $animation ) {
					echo '<option value="' . esc_attr( $animation ) . '" ' . selected( $options['animation'], $animation, false ) . '>' . esc_html( $animation ) . '</option>';
				}
				echo '</select></label>';
				break;
			case 'categories':
				echo '<h2>' . esc_html__( 'Категории: slug|название|описание (по одной в строке)', '152fz-cookie-consent' ) . '</h2><p>' . esc_html__( 'Необходимые всегда включены. Смена категорий обновит версию политики.', '152fz-cookie-consent' ) . '</p>';
				echo '<textarea rows="10" class="large-text" name="rcc_options[category_lines]">' . esc_textarea( $this->category_lines() ) . '</textarea>';
				break;
			case 'scanner':
				echo '<h2>' . esc_html__( 'Сканирование HTML и заголовков Set-Cookie', '152fz-cookie-consent' ) . '</h2><p>' . esc_html__( 'Сканирование не запускает браузерный JavaScript. Проверьте результаты вручную в браузере без согласия.', '152fz-cookie-consent' ) . '</p>';
				$this->field( 'cookies', __( 'Реестр cookies: категория|имя|поставщик|срок|цель (одна строка на cookie)', '152fz-cookie-consent' ), 'textarea' );
				echo '<p>' . esc_html__( 'Сначала сохраните реестр, затем запустите сканер ниже.', '152fz-cookie-consent' ) . '</p>';
				break;
			case 'integrations':
				$this->field( 'form_auto', __( 'Автоматически добавлять чекбокс в обычные HTML-формы', '152fz-cookie-consent' ), 'checkbox' );
				$this->field( 'form_cookie', __( 'Требовать выбор cookies до отправки формы', '152fz-cookie-consent' ), 'checkbox' );
				foreach ( Integrations::names() as $slug => $name ) {
					echo '<label class="rcc-field"><span>' . esc_html( $name ) . '</span><input type="checkbox" name="rcc_options[integrations][' . esc_attr( $slug ) . ']" value="1" ' . checked( Plugin::options()['integrations'][ $slug ] ?? 1, 1, false ) . '></label>';
				}
				break;
			case 'texts':
				foreach ( array( 'title' => __( 'Заголовок', '152fz-cookie-consent' ), 'accept' => __( 'Принять все', '152fz-cookie-consent' ), 'reject' => __( 'Отклонить', '152fz-cookie-consent' ), 'settings' => __( 'Настройки', '152fz-cookie-consent' ) ) as $key => $label ) {
					$this->field( $key, $label );
				}
				$this->field( 'description', __( 'Описание ({privacy_policy_link})', '152fz-cookie-consent' ), 'textarea' );
				break;
			case 'advanced':
				$this->field( 'duration', __( 'Срок действия согласия, дни', '152fz-cookie-consent' ), 'number' );
				echo '<label class="rcc-field"><span>' . esc_html__( 'Хранить журнал, месяцев', '152fz-cookie-consent' ) . '</span><select name="rcc_options[retention]">';
				foreach ( array( 6, 12, 24 ) as $months ) {
					echo '<option value="' . esc_attr( $months ) . '" ' . selected( $options['retention'], $months, false ) . '>' . esc_html( $months ) . '</option>';
				}
				echo '</select></label>';
				$this->field( 'exclude_ids', __( 'Исключить ID страниц (через запятую)', '152fz-cookie-consent' ) );
				$this->field( 'hide_admin', __( 'Не показывать администраторам', '152fz-cookie-consent' ), 'checkbox' );
				$this->field( 'google_mode', __( 'Google Consent Mode v2', '152fz-cookie-consent' ), 'checkbox' );
				$this->field( 'debug', __( 'Режим отладки', '152fz-cookie-consent' ), 'checkbox' );
				$this->field( 'rules', __( 'Правила: категория|подстрока URL/id/class (по одному на строку)', '152fz-cookie-consent' ), 'textarea' );
				$this->field( 'custom_css', __( 'Дополнительный CSS', '152fz-cookie-consent' ), 'textarea' );
				break;
		}
		if ( 'advanced' === $tab ) {
			echo '<p>' . esc_html__( 'Импорт и экспорт настроек JSON', '152fz-cookie-consent' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rcc_export' ), 'rcc_export' ) ) . '">' . esc_html__( 'Экспорт', '152fz-cookie-consent' ) . '</a></p>';
		}
	}

	private function import_form(): void {
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rcc_import">';
		wp_nonce_field( 'rcc_import' );
		echo '<label>' . esc_html__( 'Импорт настроек JSON', '152fz-cookie-consent' ) . ' <input type="file" name="settings" accept=".json,application/json" required></label> <button class="button" type="submit">' . esc_html__( 'Импортировать', '152fz-cookie-consent' ) . '</button></form>';
	}

	private function scanner_form(): void {
		echo '<hr><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rcc_scan">';
		wp_nonce_field( 'rcc_scan' );
		echo '<label>' . esc_html__( 'ID опубликованных страниц (через запятую)', '152fz-cookie-consent' ) . ' <input name="ids" type="text"></label> <button class="button" type="submit">' . esc_html__( 'Сканировать сайт', '152fz-cookie-consent' ) . '</button></form>';
		$results = get_transient( 'rcc_scan_' . get_current_user_id() );
		if ( is_array( $results ) ) {
			echo '<h3>' . esc_html__( 'Найдено', '152fz-cookie-consent' ) . ': ' . esc_html( count( $results ) ) . '</h3><table class="widefat striped"><thead><tr><th>URL</th><th>' . esc_html__( 'Категория', '152fz-cookie-consent' ) . '</th><th>' . esc_html__( 'Фрагмент', '152fz-cookie-consent' ) . '</th></tr></thead><tbody>';
			foreach ( $results as $row ) {
				echo '<tr><td>' . esc_html( $row['url'] ) . '</td><td>' . esc_html( $row['category'] ) . '</td><td><code>' . esc_html( $row['snippet'] ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	private function dashboard(): void {
		global $wpdb;
		$table = Logger::table();
		$rows = $wpdb->get_results( "SELECT choice, COUNT(*) AS total FROM {$table} GROUP BY choice" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<h2>' . esc_html__( 'Выборы посетителей', '152fz-cookie-consent' ) . '</h2><div class="rcc-stats">';
		foreach ( (array) $rows as $row ) {
			echo '<div><strong>' . esc_html( $row->total ) . '</strong><span>' . esc_html( $row->choice ) . '</span></div>';
		}
		echo '</div><p>' . esc_html__( 'Перед публикацией настройте политику конфиденциальности WordPress, категории, тексты и проверьте страницы магазина и формы.', '152fz-cookie-consent' ) . '</p>';
		$since = gmdate( 'Y-m-d 00:00:00', time() - 6 * DAY_IN_SECONDS );
		$days = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS day, COUNT(*) AS total FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day ASC", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $days ) {
			$max = max( array_map( static fn( $day ) => (int) $day->total, $days ) );
			echo '<h2>' . esc_html__( 'Выборы по дням (UTC)', '152fz-cookie-consent' ) . '</h2><div class="rcc-chart">';
			foreach ( $days as $day ) {
				$height = max( 5, (int) round( (int) $day->total / $max * 130 ) );
				echo '<div title="' . esc_attr( $day->day . ': ' . $day->total ) . '"><strong>' . esc_html( $day->total ) . '</strong><span style="height:' . esc_attr( $height ) . 'px"></span><small>' . esc_html( $day->day ) . '</small></div>';
			}
			echo '</div>';
		}
	}

	private function logs(): void {
		global $wpdb;
		$table = Logger::table();
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$filter = isset( $_GET['choice'] ) && is_string( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '';
		$where = in_array( $filter, array( 'accept', 'reject', 'save', 'revoke' ), true ) ? $wpdb->prepare( ' WHERE choice = %s', $filter ) : '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT 50 OFFSET %d", ( $page - 1 ) * 50 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<h2>' . esc_html__( 'Журнал согласий', '152fz-cookie-consent' ) . '</h2><form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="logs"><select name="choice"><option value="">Все</option>';
		foreach ( array( 'accept', 'reject', 'save', 'revoke' ) as $choice ) {
			echo '<option value="' . esc_attr( $choice ) . '" ' . selected( $filter, $choice, false ) . '>' . esc_html( $choice ) . '</option>';
		}
		echo '</select> <button class="button">' . esc_html__( 'Фильтр', '152fz-cookie-consent' ) . '</button></form>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rcc_csv&choice=' . $filter ), 'rcc_csv' ) ) . '">' . esc_html__( 'Экспорт CSV', '152fz-cookie-consent' ) . '</a></p><table class="widefat striped"><thead><tr><th>UTC</th><th>UUID</th><th>Policy</th><th>IP hash</th><th>UA</th><th>Выбор</th><th>Категории</th><th>URL</th><th>User ID</th></tr></thead><tbody>';
		foreach ( (array) $rows as $row ) {
			echo '<tr>';
			foreach ( array( 'created_at', 'consent_id', 'policy_version', 'ip_hash', 'user_agent', 'choice', 'categories', 'url', 'user_id' ) as $key ) {
				echo '<td>' . esc_html( $row->$key ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table><p><a class="button" href="' . esc_url( add_query_arg( 'paged', $page + 1 ) ) . '">' . esc_html__( 'Следующая страница', '152fz-cookie-consent' ) . '</a></p>';
	}

	private function authorized( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', '152fz-cookie-consent' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	public function scan(): void {
		$this->authorized( 'rcc_scan' );
		$ids = isset( $_POST['ids'] ) && is_string( $_POST['ids'] ) ? array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) : array();
		set_transient( 'rcc_scan_' . get_current_user_id(), Scanner::scan( $ids ), HOUR_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=scanner' ) );
		exit;
	}

	public function export(): void {
		$this->authorized( 'rcc_export' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rcc-settings.json"' );
		echo wp_json_encode( Plugin::options(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public function import(): void {
		$this->authorized( 'rcc_import' );
		if ( empty( $_FILES['settings']['tmp_name'] ) || ! is_uploaded_file( $_FILES['settings']['tmp_name'] ) || (int) $_FILES['settings']['size'] > 200000 ) {
			wp_die( esc_html__( 'Файл отсутствует или слишком большой.', '152fz-cookie-consent' ) );
		}
		$data = json_decode( (string) file_get_contents( $_FILES['settings']['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			wp_die( esc_html__( 'Недопустимый JSON.', '152fz-cookie-consent' ) );
		}
		$data['category_lines'] = '';
		if ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) {
			foreach ( $data['categories'] as $key => $item ) {
				if ( is_array( $item ) && 'necessary' !== $key ) {
					$data['category_lines'] .= sanitize_key( $key ) . '|' . ( $item['name'] ?? '' ) . '|' . ( $item['description'] ?? '' ) . "\n";
				}
			}
		}
		update_option( 'rcc_options', $this->sanitize( $data ) );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=advanced&imported=1' ) );
		exit;
	}

	public function csv(): void {
		$this->authorized( 'rcc_csv' );
		global $wpdb;
		$choice = isset( $_GET['choice'] ) && is_string( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '';
		$where = in_array( $choice, array( 'accept', 'reject', 'save', 'revoke' ), true ) ? $wpdb->prepare( ' WHERE choice = %s', $choice ) : '';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rcc-consents.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'UTC', 'UUID', 'Policy version', 'IP hash', 'User Agent', 'Choice', 'Categories', 'URL', 'User ID' ) );
		$table = Logger::table();
		$offset = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT created_at, consent_id, policy_version, ip_hash, user_agent, choice, categories, url, user_id FROM {$table} {$where} ORDER BY id DESC LIMIT 500 OFFSET %d", $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				fputcsv( $out, array_map( static fn( $value ) => preg_match( '/^[=+@\-\t\r]/', (string) $value ) ? "'" . $value : $value, array_values( $row ) ) );
			}
			$offset += count( $rows );
		} while ( 500 === count( $rows ) );
		fclose( $out );
		exit;
	}
}
