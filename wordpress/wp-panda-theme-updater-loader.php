<?php
/**
 * Optional MU-plugin loader for updates to active AND inactive themes.
 * Install this file and wp-panda-updater.php directly in wp-content/mu-plugins/.
 * Configure WP_PANDA_VAULT_API_URL and per-theme key constants in wp-config.php.
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
    $constant = 'WP_PANDA_' . strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $stylesheet)) . '_KEY';
    if (!defined($constant)) {
        continue;
    }
    new WP_Panda_Updater(array(
        'type' => 'theme',
        'slug' => $stylesheet,
        'version' => $theme->get('Version'),
        'api_key_constant' => $constant,
        'api_url' => WP_PANDA_VAULT_API_URL,
        'theme' => $theme,
    ));
}
