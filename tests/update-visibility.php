<?php
/**
 * Isolated regression tests for public update visibility and protected downloads.
 * Run with: php tests/update-visibility.php
 * WordPress is intentionally stubbed; full REST/database integration tests are still recommended.
 */
define('ABSPATH', __DIR__ . '/');
define('WP_PANDA_VAULT_SECRET', str_repeat('test-secret-', 4));

class WP_Error {
    private $code;
    public function __construct($code, $message = '', $data = array()) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function __($text) { return $text; }
function nocache_headers() {}
function rest_ensure_response($value) { return $value; }
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function esc_url_raw($value) { return trim((string) $value); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function rest_url($path = '') { return 'https://vault.example/wp-json/' . ltrim($path, '/'); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function current_time($type, $gmt = false) { return '2026-09-30 12:00:00'; }

class WP_Panda_Vault {
    public static function schema_ready() { return true; }
    public static function find_product($slug, $type) {
        if ($slug !== 'panda-demo' || $type !== 'plugin') return null;
        return (object) array('id' => 3, 'slug' => $slug, 'type' => $type, 'name' => 'Panda Demo');
    }
    public static function latest_release($product_id) {
        return (object) array('id' => 9, 'product_id' => $product_id, 'version' => '2.0.0', 'changelog' => 'New release', 'tested_wp' => '6.8', 'requires_wp' => '6.0', 'requires_php' => '7.4', 'created_at' => '2026-09-30 12:00:00');
    }
    public static function normalize_license_key($key) { return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $key)); }
    public static function licenses_table() { return 'wp_panda_vault_licenses'; }
    public static function activations_table() { return 'wp_panda_vault_activations'; }
    public static function releases_table() { return 'wp_panda_vault_releases'; }
}

class TestWpdb {
    public $prefix = 'wp_';
    public $last_args = array();
    public $license_hash;
    public $license_active = true;
    public $site_active = true;
    public function prepare($query, ...$args) { $this->last_args = $args; return $query; }
    public function get_row($query) {
        if (strpos($query, 'panda_vault_releases') !== false) return (object) array('id' => 9, 'product_id' => 3);
        if (strpos($query, 'panda_vault_licenses') !== false) {
            if (strpos($query, 'license_key_hash') !== false) {
                $hash = isset($this->last_args[1]) ? $this->last_args[1] : '';
                if (!$this->license_active || !hash_equals($this->license_hash, (string) $hash)) return null;
                return (object) array('id' => 22, 'product_id' => 3, 'status' => 'active', 'max_activations' => 3);
            }
            return $this->license_active ? (object) array('id' => 22, 'product_id' => 3, 'status' => 'active', 'max_activations' => 3) : null;
        }
        if (strpos($query, 'panda_vault_activations') !== false) return $this->site_active ? (object) array('id' => 44) : null;
        return null;
    }
    public function get_var($query) { return $this->site_active ? '44' : null; }
    public function update() { return 1; }
}

class TestRequest {
    private $params;
    private $headers;
    public function __construct($params = array(), $headers = array()) { $this->params = $params; $this->headers = $headers; }
    public function get_param($key) { return isset($this->params[$key]) ? $this->params[$key] : null; }
    public function get_header($key) { $key = strtolower($key); return isset($this->headers[$key]) ? $this->headers[$key] : ''; }
}

$license_key = 'WPPV-1234-5678-90AB-CDEF-1234567890AB-12345678';
$GLOBALS['wpdb'] = new TestWpdb();
$GLOBALS['wpdb']->license_hash = hash('sha256', WP_Panda_Vault::normalize_license_key($license_key));
require_once dirname(__DIR__) . '/server/wp-panda-vault/includes/class-wp-panda-vault-api.php';

register_shutdown_function(static function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        $message = str_replace(array("\r", "\n", ':', '%'), ' ', $error['message']);
        echo '::error file=tests/update-visibility.php,line=' . (int) $error['line'] . '::' . $message . "\n";
    }
});

function expect($condition, $message) {
    if (!$condition) {
        echo '::error file=tests/update-visibility.php::' . str_replace(array("\r", "\n", ':', '%'), ' ', $message) . "\n";
        throw new RuntimeException($message);
    }
}
function update_request($key = '') {
    $headers = $key ? array('authorization' => 'Bearer ' . $key) : array();
    return new TestRequest(array('slug' => 'panda-demo', 'type' => 'plugin', 'version' => '1.0.0', 'site_url' => 'https://shop.example/'), $headers);
}

$public_response = WP_Panda_Vault_API::check_update(update_request());
expect(!is_wp_error($public_response) && !empty($public_response['update']), 'A new release must be visible without a license key.');
expect(parse_url($public_response['package'], PHP_URL_QUERY) === null, 'Public update metadata must not contain a download capability.');
$denied = WP_Panda_Vault_API::authorize_download(new TestRequest(array('id' => 9)));
expect(is_wp_error($denied) && $denied->get_error_code() === 'license_required', 'The public package URL must deny downloads.');

$GLOBALS['wpdb']->license_active = false;
$invalid_key_response = WP_Panda_Vault_API::check_update(update_request('WPPV-invalid-key-value-12345678901234567890'));
expect(!is_wp_error($invalid_key_response) && !empty($invalid_key_response['update']), 'A bad key must not hide the available update.');
expect(parse_url($invalid_key_response['package'], PHP_URL_QUERY) === null, 'An invalid key must not receive a signed package URL.');

$GLOBALS['wpdb']->license_active = true;
$GLOBALS['wpdb']->site_active = true;
$licensed_response = WP_Panda_Vault_API::check_update(update_request($license_key));
$query = array();
parse_str((string) parse_url($licensed_response['package'], PHP_URL_QUERY), $query);
expect(!empty($query['signature']) && (int) $query['license_id'] === 22 && !empty($query['site_hash']), 'An entitled site must receive a site-bound signed URL.');
$authorized = WP_Panda_Vault_API::authorize_download(new TestRequest(array_merge(array('id' => 9), $query)));
expect($authorized === true, 'An active license and activation must authorize the package.');

$GLOBALS['wpdb']->license_active = false;
$revoked = WP_Panda_Vault_API::authorize_download(new TestRequest(array_merge(array('id' => 9), $query)));
expect(is_wp_error($revoked) && $revoked->get_error_code() === 'license_required', 'Revoking a license must invalidate an already-issued URL.');

$GLOBALS['wpdb']->license_active = true;
$GLOBALS['wpdb']->site_active = false;
$deactivated = WP_Panda_Vault_API::authorize_download(new TestRequest(array_merge(array('id' => 9), $query)));
expect(is_wp_error($deactivated) && $deactivated->get_error_code() === 'license_required', 'Removing an activation must invalidate an already-issued URL.');

echo "Update visibility and guarded download regression tests passed.\n";
