<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/wp-panda-updater.php';
$theme = wp_get_theme();
new WP_Panda_Updater(array(
    'type' => 'theme',
    'slug' => 'wp-panda-test-theme',
    'name' => 'WP Panda Test Theme',
    'version' => $theme->get('Version'),
    'api_url' => defined('WP_PANDA_VAULT_API_URL') ? WP_PANDA_VAULT_API_URL : 'https://updates.example.com/wp-json/wp-panda/v1',
));
