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
            ),
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

    private static function bearer_token($request) {
        $header = $request->get_header('authorization');
        if (!$header && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
        }
        if (!$header && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }
        return preg_match('/^Bearer\s+(.+)$/i', (string) $header, $matches) ? trim($matches[1]) : '';
    }

    public static function check_update($request) {
        nocache_headers();
        $slug = sanitize_key($request->get_param('slug'));
        $type = sanitize_key($request->get_param('type'));
        $installed = sanitize_text_field($request->get_param('version'));
        if (!in_array($type, array('plugin', 'theme'), true) || !$slug || !$installed) {
            return new WP_Error('invalid_request', __('Invalid product, type or installed version.', 'wp-panda-vault'), array('status' => 400));
        }
        $product = WP_Panda_Vault::find_product($slug, $type);
        $token = self::bearer_token($request);
        if (!$product || !$token || !hash_equals($product->api_key_hash, hash('sha256', $token))) {
            return new WP_Error('invalid_credentials', __('Invalid product credentials.', 'wp-panda-vault'), array('status' => 401));
        }
        $release = WP_Panda_Vault::latest_release($product->id);
        if (!$release || version_compare($release->version, $installed, '<=')) {
            return rest_ensure_response(array('update' => false));
        }
        $expires = time() + self::SIGNATURE_TTL;
        $signature = self::sign_download($release->id, $expires);
        $package = add_query_arg(array(
            'expires' => $expires,
            'signature' => $signature,
        ), rest_url('wp-panda/v1/download/' . absint($release->id)));
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
        if (defined('WP_PANDA_VAULT_SECRET') && strlen((string) WP_PANDA_VAULT_SECRET) >= 32) {
            return (string) WP_PANDA_VAULT_SECRET;
        }
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
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . WP_Panda_Vault::releases_table() . ' SET downloads = downloads + 1 WHERE id = %d', absint($release->id)));
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        @ini_set('zlib.output_compression', 'Off');
        header('X-Accel-Buffering: no');
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($release->package_path));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($release->version . '-' . $release->id . '.zip') . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        while (ob_get_level()) {
            ob_end_clean();
        }
        $handle = fopen($release->package_path, 'rb');
        if (!$handle) {
            status_header(500);
            exit;
        }
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
