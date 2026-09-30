<?php
/**
 * Optional MU-plugin loader for active and inactive WP Panda themes.
 * Copy this file and wp-panda-updater.php to wp-content/mu-plugins/.
 * Add "Panda Vault Slug: your-theme-slug" to the theme style.css and define
 * WP_PANDA_VAULT_API_URL in wp-config.php.
 */
if (!defined('ABSPATH')) {
    exit;
}

$client_sdk = WPMU_PLUGIN_DIR . '/wp-panda-updater.php';
if (!is_readable($client_sdk) || !defined('WP_PANDA_VAULT_API_URL')) {
    return;
}
require_once $client_sdk;

foreach (wp_get_themes() as $stylesheet => $theme) {
    $style_file = $theme->get_stylesheet_directory() . '/style.css';
    if (!is_readable($style_file)) continue;
    $header = file_get_contents($style_file, false, null, 0, 8192);
    if (!is_string($header) || !preg_match('/^[ \t\/*#@]*Panda Vault Slug:\s*([a-z0-9_-]+)\s*$/mi', $header, $matches)) continue;
    $slug = sanitize_key($matches[1]);
    if ($slug !== $stylesheet) continue;
    new WP_Panda_Updater(array(
        'type' => 'theme',
        'slug' => $slug,
        'name' => $theme->get('Name'),
        'version' => $theme->get('Version'),
        'api_url' => WP_PANDA_VAULT_API_URL,
    ));
}
