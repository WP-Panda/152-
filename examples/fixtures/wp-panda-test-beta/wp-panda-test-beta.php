<?php
/**
 * Plugin Name: WP Panda Test Beta
 * Description: Минимальный плагин для проверки интеграции лицензии в собственную страницу настроек.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: WP Panda
 * Text Domain: wp-panda-test-beta
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/wp-panda-updater.php';
$metadata = get_file_data(__FILE__, array('Version' => 'Version'), 'plugin');
new WP_Panda_Updater(array(
    'type' => 'plugin',
    'slug' => 'wp-panda-test-beta',
    'name' => 'WP Panda Test Beta',
    'version' => isset($metadata['Version']) ? $metadata['Version'] : '1.0.0',
    'api_url' => defined('WP_PANDA_VAULT_API_URL') ? WP_PANDA_VAULT_API_URL : 'https://updates.example.com/wp-json/wp-panda/v1',
    'plugin_file' => __FILE__,
    'settings_page' => 'wp-panda-test-beta-settings',
));

add_action('admin_menu', static function () {
    add_options_page(
        __('WP Panda Test Beta', 'wp-panda-test-beta'),
        __('Panda Test Beta', 'wp-panda-test-beta'),
        'manage_options',
        'wp-panda-test-beta-settings',
        'wp_panda_test_beta_render_settings'
    );
});

function wp_panda_test_beta_render_settings() {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>' . esc_html__('WP Panda Test Beta', 'wp-panda-test-beta') . '</h1>';
    echo '<p>' . esc_html__('Пример интеграции блока лицензии в собственную страницу настроек.', 'wp-panda-test-beta') . '</p>';
    wp_panda_updater_license_field('wp-panda-test-beta', 'plugin');
    echo '</div>';
}
