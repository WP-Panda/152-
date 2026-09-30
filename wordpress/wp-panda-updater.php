<?php
/**
 * WP Panda Updater — drop-in client for a private WP Panda Vault.
 * Copy this file into the plugin/theme package and initialize WP_Panda_Updater.
 * Requires WordPress 5.8+ and PHP 7.4+.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_Panda_Updater', false)) {
    final class WP_Panda_Updater {
        private $type;
        private $slug;
        private $version;
        private $api_key;
        private $api_url;
        private $plugin_file;
        private $cache_key;

        public function __construct($args) {
            $this->type = isset($args['type']) ? sanitize_key($args['type']) : 'plugin';
            $this->slug = isset($args['slug']) ? sanitize_key($args['slug']) : '';
            $this->version = isset($args['version']) ? (string) $args['version'] : '0.0.0';
            $this->api_url = isset($args['api_url']) ? esc_url_raw(trim((string) $args['api_url'])) : '';
            $this->api_key = isset($args['api_key']) ? trim((string) $args['api_key']) : '';
            if (!$this->api_key) {
                $constant = isset($args['api_key_constant']) ? (string) $args['api_key_constant'] : 'WP_PANDA_API_KEY';
                if (preg_match('/^[A-Z][A-Z0-9_]*$/', $constant) && defined($constant)) {
                    $this->api_key = trim((string) constant($constant));
                }
            }
            $this->plugin_file = isset($args['plugin_file']) ? plugin_basename($args['plugin_file']) : $this->detect_plugin_file();
            $this->cache_key = 'wp_panda_vault_' . md5($this->type . ':' . $this->slug . ':' . $this->version);

            if (!in_array($this->type, array('plugin', 'theme'), true) || !$this->slug || !$this->api_key || !$this->api_url) return;
            if (wp_parse_url($this->api_url, PHP_URL_SCHEME) !== 'https' && !(defined('WP_PANDA_ALLOW_INSECURE_API') && WP_PANDA_ALLOW_INSECURE_API)) return;
            if ($this->type === 'plugin' && !$this->plugin_file) return;
            if ($this->type === 'plugin') {
                add_filter('pre_set_site_transient_update_plugins', array($this, 'filter_plugin_updates'));
                add_filter('plugins_api', array($this, 'plugin_information'), 20, 3);
            } else {
                add_filter('pre_set_site_transient_update_themes', array($this, 'filter_theme_updates'));
            }
        }

        private function detect_plugin_file() {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            foreach ($trace as $frame) {
                if (!empty($frame['file']) && $frame['file'] !== __FILE__ && strpos(wp_normalize_path($frame['file']), wp_normalize_path(WP_PLUGIN_DIR) . '/') === 0) {
                    return plugin_basename($frame['file']);
                }
            }
            return '';
        }

        private function check_for_update() {
            $cached = get_transient($this->cache_key);
            if (is_array($cached)) return $cached;
            $endpoint = add_query_arg(array(
                'slug' => $this->slug,
                'type' => $this->type,
                'version' => $this->version,
            ), $this->api_url);
            $response = wp_remote_get($endpoint, array(
                'timeout' => 12,
                'redirection' => 2,
                'sslverify' => true,
                'headers' => array(
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->api_key,
                ),
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
            ));
            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
                set_transient($this->cache_key, array('update' => false), 5 * MINUTE_IN_SECONDS);
                return array('update' => false);
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data) || empty($data['update']) || empty($data['version']) || empty($data['package'])) {
                $data = array('update' => false);
            }
            // Signed package URLs last 10 minutes; keep the response cache shorter.
            set_transient($this->cache_key, $data, 5 * MINUTE_IN_SECONDS);
            return $data;
        }

        public function filter_plugin_updates($transient) {
            if (!is_object($transient) || empty($transient->checked) || !isset($transient->checked[$this->plugin_file])) return $transient;
            $data = $this->check_for_update();
            if (empty($data['update']) || version_compare($data['version'], $this->version, '<=')) return $transient;
            $update = (object) array(
                'id' => $this->api_url,
                'slug' => $this->slug,
                'plugin' => $this->plugin_file,
                'new_version' => $data['version'],
                'url' => $this->api_url,
                'package' => esc_url_raw($data['package']),
            );
            foreach (array('tested', 'requires', 'requires_php') as $field) {
                if (!empty($data[$field])) $update->{$field} = sanitize_text_field($data[$field]);
            }
            $transient->response[$this->plugin_file] = $update;
            return $transient;
        }

        public function filter_theme_updates($transient) {
            if (!is_object($transient) || !isset($transient->checked) || !is_array($transient->checked)) return $transient;
            $data = $this->check_for_update();
            if (empty($data['update']) || version_compare($data['version'], $this->version, '<=')) return $transient;
            if (!isset($transient->response) || !is_array($transient->response)) $transient->response = array();
            $transient->response[$this->slug] = array(
                'theme' => $this->slug,
                'new_version' => $data['version'],
                'url' => $this->api_url,
                'package' => esc_url_raw($data['package']),
                'requires' => isset($data['requires']) ? sanitize_text_field($data['requires']) : '6.0',
                'requires_php' => isset($data['requires_php']) ? sanitize_text_field($data['requires_php']) : '7.4',
            );
            return $transient;
        }

        public function plugin_information($result, $action, $args) {
            if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug) return $result;
            $data = $this->check_for_update();
            if (empty($data['update'])) return $result;
            return (object) array(
                'name' => isset($data['name']) ? sanitize_text_field($data['name']) : $this->slug,
                'slug' => $this->slug,
                'version' => $data['version'],
                'author' => 'WP Panda Vault',
                'homepage' => $this->api_url,
                'requires' => isset($data['requires']) ? $data['requires'] : '6.0',
                'requires_php' => isset($data['requires_php']) ? $data['requires_php'] : '7.4',
                'tested' => isset($data['tested']) ? $data['tested'] : '',
                'download_link' => esc_url_raw($data['package']),
                'sections' => array(
                    'description' => '',
                    'changelog' => wp_kses_post(nl2br(isset($data['changelog']) ? $data['changelog'] : '')),
                ),
            );
        }
    }
}
