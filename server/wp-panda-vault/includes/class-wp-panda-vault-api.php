<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WP_Panda_Vault_API {
    const SIGNATURE_TTL = 600;

    public static function register_routes() {
        register_rest_route('wp-panda/v1', '/check', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'check_update'),
            'permission_callback' => '__return_true',
            'args' => array(
                'slug' => array('required' => true, 'sanitize_callback' => 'sanitize_key'),
                'type' => array('required' => true, 'sanitize_callback' => 'sanitize_key'),
                'version' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'site_url' => array('required' => true, 'sanitize_callback' => 'esc_url_raw'),
            ),
        ));
        register_rest_route('wp-panda/v1', '/license/activate', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'activate_license'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('wp-panda/v1', '/license/deactivate', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'deactivate_license'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('wp-panda/v1', '/download/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'download_package'),
            'permission_callback' => array(__CLASS__, 'authorize_download'),
            'args' => array(
                'id' => array('required' => true, 'sanitize_callback' => 'absint'),
                'expires' => array('required' => true, 'sanitize_callback' => 'absint'),
                'signature' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));
    }

    private static function site_identity($url) {
        $url = esc_url_raw(trim((string) $url));
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
            return new WP_Error('invalid_site_url', __('A valid HTTP/HTTPS site URL is required.', 'wp-panda-vault'), array('status' => 400));
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) $port = 0;
        $path = isset($parts['path']) ? '/' . trim(preg_replace('#/+#', '/', $parts['path']), '/') : '';
        if ($path === '/') $path = '';
        $identity = $host . ($port ? ':' . $port : '') . $path;
        if (strlen($identity) > 255) return new WP_Error('invalid_site_url', __('Site URL is too long.', 'wp-panda-vault'), array('status' => 400));
        return array('hash' => hash('sha256', $identity), 'url' => ($scheme === 'http' ? 'http://' : 'https://') . $identity);
    }

    private static function get_product_and_license($slug, $type, $key, $require_active = true) {
        global $wpdb;
        $slug = sanitize_key($slug);
        $type = sanitize_key($type);
        if (!in_array($type, array('plugin', 'theme'), true) || !$slug) {
            return new WP_Error('invalid_product', __('Invalid product or type.', 'wp-panda-vault'), array('status' => 400));
        }
        $product = WP_Panda_Vault::find_product($slug, $type);
        $normalized = WP_Panda_Vault::normalize_license_key($key);
        if (!$product || strlen($normalized) < 32) {
            return new WP_Error('invalid_license', __('Invalid product or license key.', 'wp-panda-vault'), array('status' => 403));
        }
        $sql = 'SELECT * FROM ' . WP_Panda_Vault::licenses_table() . ' WHERE product_id = %d AND license_key_hash = %s';
        if ($require_active) $sql .= ' AND status = \'active\'';
        $license = $wpdb->get_row($wpdb->prepare($sql, $product->id, hash('sha256', $normalized)));
        if (!$license) {
            return new WP_Error('invalid_license', __('Invalid, revoked, or inactive license key.', 'wp-panda-vault'), array('status' => 403));
        }
        return array($product, $license);
    }

    private static function request_payload($request) {
        $payload = $request->get_json_params();
        return is_array($payload) ? $payload : array();
    }

    public static function activate_license($request) {
        nocache_headers();
        $payload = self::request_payload($request);
        $credentials = self::get_product_and_license($payload['slug'] ?? '', $payload['type'] ?? '', $payload['license_key'] ?? '', true);
        if (is_wp_error($credentials)) return $credentials;
        list($product, $license) = $credentials;
        $site = self::site_identity($payload['site_url'] ?? '');
        if (is_wp_error($site)) return $site;

        global $wpdb;
        $licenses_table = WP_Panda_Vault::licenses_table();
        $activations_table = WP_Panda_Vault::activations_table();
        // Lock the license row to prevent simultaneous activation requests oversubscribing the slot limit.
        $wpdb->query('START TRANSACTION');
        $locked = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$licenses_table} WHERE id = %d FOR UPDATE", $license->id));
        if (!$locked || $locked->status !== 'active') {
            $wpdb->query('ROLLBACK');
            return new WP_Error('invalid_license', __('License is not active.', 'wp-panda-vault'), array('status' => 403));
        }
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$activations_table} WHERE license_id = %d AND site_hash = %s", $license->id, $site['hash']));
        if ($existing) {
            $wpdb->update($activations_table, array('site_url' => $site['url'], 'last_seen_at' => current_time('mysql', true)), array('id' => $existing->id), array('%s', '%s'), array('%d'));
        } else {
            $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$activations_table} WHERE license_id = %d", $license->id));
            if ($count >= (int) $license->max_activations) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('activation_limit_reached', sprintf(__('This license is already active on %1$d of %2$d allowed sites. Deactivate an old site or contact the license owner.', 'wp-panda-vault'), $count, (int) $license->max_activations), array('status' => 409, 'activations' => $count, 'limit' => (int) $license->max_activations));
            }
            $inserted = $wpdb->insert($activations_table, array(
                'license_id' => $license->id,
                'site_hash' => $site['hash'],
                'site_url' => $site['url'],
                'activated_at' => current_time('mysql', true),
                'last_seen_at' => current_time('mysql', true),
            ), array('%d', '%s', '%s', '%s', '%s'));
            if (!$inserted) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('activation_failed', __('Could not register this site activation.', 'wp-panda-vault'), array('status' => 500));
            }
        }
        $wpdb->query('COMMIT');
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$activations_table} WHERE license_id = %d", $license->id));
        return rest_ensure_response(array('activated' => true, 'product' => $product->slug, 'activations' => $count, 'limit' => (int) $license->max_activations));
    }

    public static function deactivate_license($request) {
        nocache_headers();
        $payload = self::request_payload($request);
        // A revoked license may still remove its own activation record and release a seat.
        $credentials = self::get_product_and_license($payload['slug'] ?? '', $payload['type'] ?? '', $payload['license_key'] ?? '', false);
        if (is_wp_error($credentials)) return $credentials;
        list($product, $license) = $credentials;
        $site = self::site_identity($payload['site_url'] ?? '');
        if (is_wp_error($site)) return $site;
        global $wpdb;
        $wpdb->delete(WP_Panda_Vault::activations_table(), array('license_id' => $license->id, 'site_hash' => $site['hash']), array('%d', '%s'));
        return rest_ensure_response(array('deactivated' => true, 'product' => $product->slug));
    }

    private static function bearer_token($request) {
        $header = $request->get_header('authorization');
        if (!$header && isset($_SERVER['HTTP_AUTHORIZATION'])) $header = wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
        if (!$header && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $header = wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        return preg_match('/^Bearer\s+(.+)$/i', (string) $header, $matches) ? trim($matches[1]) : '';
    }

    public static function check_update($request) {
        nocache_headers();
        $slug = sanitize_key($request->get_param('slug'));
        $type = sanitize_key($request->get_param('type'));
        $installed = sanitize_text_field($request->get_param('version'));
        $credentials = self::get_product_and_license($slug, $type, self::bearer_token($request), true);
        if (is_wp_error($credentials)) return $credentials;
        list($product, $license) = $credentials;
        $site = self::site_identity($request->get_param('site_url'));
        if (is_wp_error($site)) return $site;
        global $wpdb;
        $activation = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Panda_Vault::activations_table() . ' WHERE license_id = %d AND site_hash = %s', $license->id, $site['hash']));
        if (!$activation) {
            return new WP_Error('site_not_activated', __('This site is not activated for this license. Activate it in WordPress Settings → Panda Updates.', 'wp-panda-vault'), array('status' => 403));
        }
        $wpdb->update(WP_Panda_Vault::activations_table(), array('site_url' => $site['url'], 'last_seen_at' => current_time('mysql', true)), array('id' => $activation->id), array('%s', '%s'), array('%d'));
        $release = WP_Panda_Vault::latest_release($product->id);
        if (!$release || version_compare($release->version, $installed, '<=')) return rest_ensure_response(array('update' => false));
        $expires = time() + self::SIGNATURE_TTL;
        $signature = self::sign_download($release->id, $expires);
        $package = add_query_arg(array('expires' => $expires, 'signature' => $signature), rest_url('wp-panda/v1/download/' . absint($release->id)));
        return rest_ensure_response(array(
            'update' => true,
            'slug' => $product->slug,
            'type' => $product->type,
            'name' => $product->name,
            'version' => $release->version,
            'changelog' => $release->changelog,
            'tested' => $release->tested_wp,
            'requires' => $release->requires_wp,
            'requires_php' => $release->requires_php,
            'package' => esc_url_raw($package),
            'updated_at' => mysql_to_rfc3339($release->created_at),
        ));
    }

    private static function signing_secret() {
        if (defined('WP_PANDA_VAULT_SECRET') && strlen((string) WP_PANDA_VAULT_SECRET) >= 32) return (string) WP_PANDA_VAULT_SECRET;
        return wp_salt('auth');
    }

    private static function sign_download($release_id, $expires) {
        return hash_hmac('sha256', absint($release_id) . ':' . absint($expires), self::signing_secret());
    }

    public static function authorize_download($request) {
        $release_id = absint($request->get_param('id'));
        $expires = absint($request->get_param('expires'));
        $signature = sanitize_text_field($request->get_param('signature'));
        if (!$release_id || $expires < time() || $expires > time() + self::SIGNATURE_TTL + 30 || !$signature) {
            return new WP_Error('invalid_download_link', __('Download link is invalid or expired.', 'wp-panda-vault'), array('status' => 403));
        }
        if (!hash_equals(self::sign_download($release_id, $expires), $signature)) {
            return new WP_Error('invalid_download_link', __('Download link is invalid or expired.', 'wp-panda-vault'), array('status' => 403));
        }
        return true;
    }

    public static function download_package($request) {
        global $wpdb;
        $release = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Panda_Vault::releases_table() . ' WHERE id = %d', absint($request->get_param('id'))));
        if (!$release || !WP_Panda_Vault::is_path_in_storage($release->package_path) || !is_readable($release->package_path)) {
            return new WP_Error('package_not_found', __('Package is unavailable.', 'wp-panda-vault'), array('status' => 404));
        }
        $actual_hash = hash_file('sha256', $release->package_path);
        if (!$actual_hash || !hash_equals($release->package_sha256, $actual_hash)) {
            return new WP_Error('package_integrity_error', __('Package integrity verification failed.', 'wp-panda-vault'), array('status' => 500));
        }
        $wpdb->query($wpdb->prepare('UPDATE ' . WP_Panda_Vault::releases_table() . ' SET downloads = downloads + 1 WHERE id = %d', absint($release->id)));
        nocache_headers();
        @ini_set('zlib.output_compression', 'Off');
        header('X-Accel-Buffering: no');
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($release->package_path));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($release->version . '-' . $release->id . '.zip') . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        while (ob_get_level()) ob_end_clean();
        $handle = fopen($release->package_path, 'rb');
        if (!$handle) { status_header(500); exit; }
        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);
            if ($chunk === false) break;
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary ZIP stream.
            flush();
        }
        fclose($handle);
        exit;
    }
}
