<?php
/**
 * Plugin Name: WP Panda Test Alpha
 * Description: Минимальный плагин для проверки приватных обновлений WP Panda.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: WP Panda
 * Text Domain: wp-panda-test-alpha
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/wp-panda-updater.php';
$metadata = get_file_data(__FILE__, array('Version' => 'Version'), 'plugin');
new WP_Panda_Updater(array(
    'type' => 'plugin',
    'slug' => 'wp-panda-test-alpha',
    'name' => 'WP Panda Test Alpha',
    'version' => isset($metadata['Version']) ? $metadata['Version'] : '1.0.0',
    'api_url' => defined('WP_PANDA_VAULT_API_URL') ? WP_PANDA_VAULT_API_URL : 'https://updates.example.com/wp-json/wp-panda/v1',
    'plugin_file' => __FILE__,
));

add_action('admin_notices', static function () use ($metadata) {
    if (!current_user_can('manage_options')) return;
    echo '<div class="notice notice-info"><p><strong>WP Panda Test Alpha</strong> — test fixture is active; version ' . esc_html($metadata['Version'] ?? '1.0.0') . '.</p></div>';
});
