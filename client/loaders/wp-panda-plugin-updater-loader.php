<?php
/**
 * Optional MU-plugin loader for inactive as well as active private plugins.
 * Copy this file and wp-panda-updater.php to wp-content/mu-plugins/.
 * Add "Panda Vault Slug: your-plugin-slug" to each private plugin main-file
 * header and define WP_PANDA_VAULT_API_URL in wp-config.php.
 */
if (!defined('ABSPATH')) {
    exit;
}

$client_sdk = WPMU_PLUGIN_DIR . '/wp-panda-updater.php';
if (!is_readable($client_sdk) || !defined('WP_PANDA_VAULT_API_URL')) {
    return;
}
require_once $client_sdk;
if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

foreach (get_plugins() as $plugin_file => $plugin_data) {
    $absolute_file = trailingslashit(WP_PLUGIN_DIR) . $plugin_file;
    if (!is_readable($absolute_file)) continue;
    $header = file_get_contents($absolute_file, false, null, 0, 8192);
    if (!is_string($header) || !preg_match('/^[ \t\/*#@]*Panda Vault Slug:\s*([a-z0-9_-]+)\s*$/mi', $header, $matches)) continue;
    $slug = sanitize_key($matches[1]);
    if (!$slug) continue;
    new WP_Panda_Updater(array(
        'type' => 'plugin',
        'slug' => $slug,
        'name' => isset($plugin_data['Name']) ? $plugin_data['Name'] : $slug,
        'version' => isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0',
        'api_url' => WP_PANDA_VAULT_API_URL,
        'plugin_file' => $absolute_file,
    ));
}
