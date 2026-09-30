<?php
/**
 * Plugin Name: WP Panda Vault
 * Description: Private distribution and native WordPress updates for commercial plugins and themes.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: WP Panda
 * Text Domain: wp-panda-vault
 */
if (!defined('ABSPATH')) {
    exit;
}

define('WP_PANDA_VAULT_VERSION', '1.1.0');
define('WP_PANDA_VAULT_FILE', __FILE__);
define('WP_PANDA_VAULT_DIR', plugin_dir_path(__FILE__));

require_once WP_PANDA_VAULT_DIR . 'includes/class-wp-panda-vault.php';
require_once WP_PANDA_VAULT_DIR . 'includes/class-wp-panda-vault-api.php';
if (is_admin()) {
    require_once WP_PANDA_VAULT_DIR . 'includes/class-wp-panda-vault-admin.php';
}

register_activation_hook(__FILE__, array('WP_Panda_Vault', 'activate'));
add_action('plugins_loaded', static function () {
    WP_Panda_Vault::instance();
    if (is_admin()) {
        WP_Panda_Vault_Admin::instance();
    }
});
