<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WP_Panda_Vault {
    const DB_VERSION = '1.1.0';
    private static $instance;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (version_compare((string) get_option('wp_panda_vault_db_version', '0'), self::DB_VERSION, '<')) {
            self::install_schema();
        }
        add_action('rest_api_init', array('WP_Panda_Vault_API', 'register_routes'));
    }

    public static function products_table() {
        global $wpdb;
        return $wpdb->prefix . 'panda_vault_products';
    }

    public static function releases_table() {
        global $wpdb;
        return $wpdb->prefix . 'panda_vault_releases';
    }

    public static function licenses_table() {
        global $wpdb;
        return $wpdb->prefix . 'panda_vault_licenses';
    }

    public static function activations_table() {
        global $wpdb;
        return $wpdb->prefix . 'panda_vault_activations';
    }

    private static function install_schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $products = self::products_table();
        $releases = self::releases_table();
        dbDelta("CREATE TABLE {$products} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            slug varchar(60) NOT NULL,
            type varchar(12) NOT NULL,
            api_key_hash char(64) NOT NULL DEFAULT '',
            status varchar(12) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug_type (slug,type),
            KEY status (status)
        ) {$charset};");
        dbDelta("CREATE TABLE {$releases} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            version varchar(32) NOT NULL,
            changelog longtext NOT NULL,
            package_path text NOT NULL,
            package_size bigint(20) unsigned NOT NULL DEFAULT 0,
            package_sha256 char(64) NOT NULL,
            downloads bigint(20) unsigned NOT NULL DEFAULT 0,
            requires_wp varchar(32) NOT NULL DEFAULT '6.0',
            requires_php varchar(32) NOT NULL DEFAULT '7.4',
            tested_wp varchar(32) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_version (product_id,version),
            KEY product_id (product_id),
            KEY created_at (created_at)
        ) {$charset};");
        $licenses = self::licenses_table();
        dbDelta("CREATE TABLE {$licenses} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            license_key_hash char(64) NOT NULL,
            label varchar(191) NOT NULL DEFAULT '',
            max_activations tinyint(3) unsigned NOT NULL DEFAULT 1,
            status varchar(12) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_license (product_id,license_key_hash),
            KEY product_id (product_id),
            KEY status (status)
        ) {$charset};");
        $activations = self::activations_table();
        dbDelta("CREATE TABLE {$activations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            license_id bigint(20) unsigned NOT NULL,
            site_hash char(64) NOT NULL,
            site_url varchar(255) NOT NULL,
            activated_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY license_site (license_id,site_hash),
            KEY license_id (license_id),
            KEY site_hash (site_hash)
        ) {$charset};");
        // Revoke credentials from the earlier shared-product-key implementation.
        $wpdb->query("UPDATE {$products} SET api_key_hash = '' WHERE api_key_hash <> ''");
        update_option('wp_panda_vault_db_version', self::DB_VERSION, false);
    }

    public static function activate() {
        self::install_schema();
        $storage = self::prepare_storage();
        if (is_wp_error($storage)) {
            deactivate_plugins(plugin_basename(WP_PANDA_VAULT_FILE));
            wp_die(esc_html($storage->get_error_message()), esc_html__('WP Panda Vault: storage error', 'wp-panda-vault'), array('back_link' => true));
        }
    }

    /**
     * Return an absolute private directory outside ABSPATH. Define
     * WP_PANDA_VAULT_STORAGE_DIR in wp-config.php to choose a custom path.
     */
    public static function storage_path() {
        if (defined('WP_PANDA_VAULT_STORAGE_DIR') && WP_PANDA_VAULT_STORAGE_DIR) {
            return untrailingslashit(WP_PANDA_VAULT_STORAGE_DIR);
        }
        return untrailingslashit(dirname(untrailingslashit(ABSPATH)) . DIRECTORY_SEPARATOR . 'wp-panda-vault-private');
    }

    public static function prepare_storage() {
        $path = self::storage_path();
        if (!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) || strpos($path, '://') !== false) {
            return new WP_Error('storage_path_invalid', __('WP_PANDA_VAULT_STORAGE_DIR must be an absolute local filesystem path.', 'wp-panda-vault'));
        }
        if (!is_dir($path) && !wp_mkdir_p($path)) {
            return new WP_Error('storage_create_failed', sprintf(__('Unable to create private storage directory: %s', 'wp-panda-vault'), $path));
        }
        $real = realpath($path);
        $protected_roots = array(realpath(ABSPATH));
        if (!empty($_SERVER['DOCUMENT_ROOT'])) $protected_roots[] = realpath(wp_unslash($_SERVER['DOCUMENT_ROOT']));
        if (!$real) {
            return new WP_Error('storage_not_private', __('Could not resolve the private storage directory. Set WP_PANDA_VAULT_STORAGE_DIR in wp-config.php.', 'wp-panda-vault'));
        }
        foreach (array_filter($protected_roots) as $protected_root) {
            if (strpos($real . DIRECTORY_SEPARATOR, untrailingslashit($protected_root) . DIRECTORY_SEPARATOR) === 0) {
                return new WP_Error('storage_not_private', __('Private storage must resolve outside both ABSPATH and the web server document root. Set WP_PANDA_VAULT_STORAGE_DIR in wp-config.php to a protected directory outside the public web root.', 'wp-panda-vault'));
            }
        }
        if (!is_writable($real)) {
            return new WP_Error('storage_not_writable', sprintf(__('Private storage is not writable by PHP: %s', 'wp-panda-vault'), $real));
        }
        @chmod($real, 0700);
        return $real;
    }

    public static function max_package_size() {
        $default = 200 * MB_IN_BYTES;
        $limit = defined('WP_PANDA_VAULT_MAX_PACKAGE_SIZE') ? absint(WP_PANDA_VAULT_MAX_PACKAGE_SIZE) : $default;
        return (int) apply_filters('wp_panda_vault_max_package_size', max(1, $limit));
    }

    public static function get_product($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::products_table() . ' WHERE id = %d', absint($id)));
    }

    public static function find_product($slug, $type) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::products_table() . ' WHERE slug = %s AND type = %s AND status = %s', $slug, $type, 'active'));
    }

    public static function get_releases($product_id) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::releases_table() . ' WHERE product_id = %d ORDER BY created_at DESC, id DESC', absint($product_id)));
    }

    public static function latest_release($product_id) {
        $releases = self::get_releases($product_id);
        $latest = null;
        foreach ($releases as $release) {
            if (!$latest || version_compare($release->version, $latest->version, '>')) {
                $latest = $release;
            }
        }
        return $latest;
    }

    public static function get_licenses($product_id) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::licenses_table() . ' WHERE product_id = %d ORDER BY created_at DESC, id DESC', absint($product_id)));
    }

    public static function create_license_key() {
        try {
            $hex = strtoupper(bin2hex(random_bytes(20)));
            return 'WPPV-' . implode('-', str_split($hex, 4));
        } catch (Exception $e) {
            return new WP_Error('random_failed', __('Could not generate a cryptographically secure license key.', 'wp-panda-vault'));
        }
    }

    public static function normalize_license_key($key) {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $key));
    }

    public static function is_path_in_storage($file) {
        $root = realpath(self::storage_path());
        $candidate = realpath($file);
        return $root && $candidate && strpos($candidate, trailingslashit($root)) === 0;
    }
}
