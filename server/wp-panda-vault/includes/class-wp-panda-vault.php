<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WP_Panda_Vault {
    const DB_VERSION = '1.2.0';
    private static $instance;
    private static $schema_error;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (version_compare((string) get_option('wp_panda_vault_db_version', '0'), self::DB_VERSION, '<')) {
            $schema_result = self::install_schema();
            if (is_wp_error($schema_result)) self::$schema_error = $schema_result;
        }
        if (is_admin()) add_action('admin_notices', array(__CLASS__, 'schema_notice'));
        add_action('rest_api_init', array('WP_Panda_Vault_API', 'register_routes'));
    }

    public static function schema_ready() {
        return !is_wp_error(self::$schema_error);
    }

    public static function schema_notice() {
        if (!self::$schema_error || !current_user_can('manage_options')) return;
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('WP Panda Vault database migration failed:', 'wp-panda-vault') . '</strong> ' . esc_html(self::$schema_error->get_error_message()) . '</p></div>';
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
        ) {$charset} ENGINE=InnoDB;");
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
        ) {$charset} ENGINE=InnoDB;");
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
        ) {$charset} ENGINE=InnoDB;");
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
        ) {$charset} ENGINE=InnoDB;");
        // Revoke credentials from the earlier shared-product-key implementation.
        if ($wpdb->query("UPDATE {$products} SET api_key_hash = '' WHERE api_key_hash <> ''") === false) {
            return new WP_Error('schema_migration_failed', __('Could not revoke credentials from the previous schema.', 'wp-panda-vault'));
        }
        foreach (array($products, $releases, $licenses, $activations) as $table) {
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table));
            if (!$status) return new WP_Error('schema_table_missing', sprintf(__('Required database table is missing: %s', 'wp-panda-vault'), $table));
            $engine = isset($status->Engine) ? strtolower((string) $status->Engine) : '';
            if ($engine !== 'innodb' && $wpdb->query("ALTER TABLE {$table} ENGINE=InnoDB") === false) {
                return new WP_Error('schema_engine_required', sprintf(__('Could not convert %s to InnoDB; transactional activation limits require InnoDB.', 'wp-panda-vault'), $table));
            }
        }
        update_option('wp_panda_vault_db_version', self::DB_VERSION, false);
        return true;
    }

    public static function activate() {
        $schema_result = self::install_schema();
        if (is_wp_error($schema_result)) {
            deactivate_plugins(plugin_basename(WP_PANDA_VAULT_FILE));
            wp_die(esc_html($schema_result->get_error_message()), esc_html__('WP Panda Vault: database error', 'wp-panda-vault'), array('back_link' => true));
        }
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
        $created = false;
        if (!is_dir($path)) {
            if (!wp_mkdir_p($path)) {
                return new WP_Error('storage_create_failed', sprintf(__('Unable to create private storage directory: %s', 'wp-panda-vault'), $path));
            }
            $created = true;
        }
        $real = realpath($path);
        if (!$real) {
            return new WP_Error('storage_not_private', __('Could not resolve the private storage directory. Set WP_PANDA_VAULT_STORAGE_DIR in wp-config.php.', 'wp-panda-vault'));
        }
        $normalize_path = static function ($value) {
            $value = wp_normalize_path($value);
            return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
        };
        $real_normalized = $normalize_path($real);
        $filesystem_root = $normalize_path(dirname($real));
        $forbidden_system_roots = array('/', '/tmp', '/var', '/home', '/usr', '/etc', '/opt', '/root', '/srv', '/mnt', '/media', '/proc', '/sys', '/dev');
        if ($filesystem_root === $real_normalized || in_array(untrailingslashit($real_normalized), $forbidden_system_roots, true)) {
            return new WP_Error('storage_not_private', __('Private storage must be a dedicated directory, not a filesystem or shared system directory.', 'wp-panda-vault'));
        }

        $protected_roots = array(realpath(ABSPATH));
        if (!empty($_SERVER['DOCUMENT_ROOT'])) $protected_roots[] = realpath(wp_unslash($_SERVER['DOCUMENT_ROOT']));
        foreach (array_filter($protected_roots) as $protected_root) {
            $protected_normalized = untrailingslashit($normalize_path($protected_root));
            $real_prefix = trailingslashit($real_normalized);
            $protected_prefix = trailingslashit($protected_normalized);
            // Reject both a storage path inside the public tree and a storage path
            // that is an ancestor of the public tree (for example, /var).
            if ($real_normalized === $protected_normalized || strpos($real_prefix, $protected_prefix) === 0 || strpos($protected_prefix, $real_prefix) === 0) {
                return new WP_Error('storage_not_private', __('Private storage must be a dedicated directory outside both ABSPATH and the web server document root.', 'wp-panda-vault'));
            }
        }
        if (!is_writable($real)) {
            return new WP_Error('storage_not_writable', sprintf(__('Private storage is not writable by PHP: %s', 'wp-panda-vault'), $real));
        }
        if ($created) @chmod($real, 0700);
        if (DIRECTORY_SEPARATOR === '/') {
            $permissions = @fileperms($real);
            if ($permissions !== false && ($permissions & 0077) !== 0) {
                return new WP_Error('storage_permissions', __('Private storage must have restrictive permissions (0700). Vault does not change permissions on existing directories; set permissions manually.', 'wp-panda-vault'));
            }
        }
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
        if (!$root || !$candidate) return false;
        $root = wp_normalize_path($root);
        $candidate = wp_normalize_path($candidate);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $candidate = strtolower($candidate);
        }
        $root = untrailingslashit($root);
        if ($root === '') return false; // Never treat the filesystem root as a package store.
        return $candidate !== $root && strpos($candidate, trailingslashit($root)) === 0;
    }
}
