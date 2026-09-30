<?php
/**
 * WP Panda Updater client with per-site license activation.
 * Copy into the plugin/theme package. License keys are entered by site admins
 * on Settings -> Panda Updates and are never embedded in distributable code.
 * Requires WordPress 5.8+ and PHP 7.4+.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_Panda_Updater', false)) {
    final class WP_Panda_Updater {
        private static $registry = array();
        private static $hooks_registered = false;
        private $type;
        private $slug;
        private $name;
        private $version;
        private $api_url;
        private $plugin_file;
        private $option_name;
        private $cache_key;

        public function __construct($args) {
            $this->type = isset($args['type']) ? sanitize_key($args['type']) : 'plugin';
            $this->slug = isset($args['slug']) ? sanitize_key($args['slug']) : '';
            $this->name = isset($args['name']) ? sanitize_text_field($args['name']) : $this->slug;
            $this->version = isset($args['version']) ? (string) $args['version'] : '0.0.0';
            $this->api_url = isset($args['api_url']) ? untrailingslashit(esc_url_raw(trim((string) $args['api_url']))) : '';
            $this->plugin_file = isset($args['plugin_file']) ? plugin_basename($args['plugin_file']) : $this->detect_plugin_file();
            $this->option_name = 'wp_panda_license_' . md5($this->type . ':' . $this->slug);
            $this->cache_key = 'wp_panda_vault_' . md5($this->type . ':' . $this->slug . ':' . $this->version);

            if (!in_array($this->type, array('plugin', 'theme'), true) || !$this->slug || !$this->api_url) return;
            if (wp_parse_url($this->api_url, PHP_URL_SCHEME) !== 'https' && !(defined('WP_PANDA_ALLOW_INSECURE_API') && WP_PANDA_ALLOW_INSECURE_API)) return;
            if ($this->type === 'plugin' && !$this->plugin_file) return;

            $registry_key = $this->type . ':' . $this->slug;
            // An MU-plugin loader may register the product before its own code is loaded.
            if (isset(self::$registry[$registry_key])) return;
            self::$registry[$registry_key] = $this;
            if ($this->type === 'plugin') {
                add_filter('pre_set_site_transient_update_plugins', array($this, 'filter_plugin_updates'));
                add_filter('plugins_api', array($this, 'plugin_information'), 20, 3);
            } else {
                add_filter('pre_set_site_transient_update_themes', array($this, 'filter_theme_updates'));
            }
            if (!self::$hooks_registered) {
                add_action('admin_menu', array(__CLASS__, 'register_settings_page'));
                add_action('admin_post_wp_panda_updater_license', array(__CLASS__, 'handle_license_action'));
                self::$hooks_registered = true;
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

        private function license_key() {
            return trim((string) get_option($this->option_name, ''));
        }

        private function check_for_update() {
            if (!$this->license_key()) return array('update' => false);
            $cached = get_transient($this->cache_key);
            if (is_array($cached)) return $cached;
            $endpoint = add_query_arg(array(
                'slug' => $this->slug,
                'type' => $this->type,
                'version' => $this->version,
                'site_url' => home_url('/'),
            ), trailingslashit($this->api_url) . 'check');
            $response = wp_remote_get($endpoint, array(
                'timeout' => 12,
                'redirection' => 2,
                'sslverify' => true,
                'headers' => array(
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->license_key(),
                ),
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
            ));
            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
                set_transient($this->cache_key, array('update' => false), 5 * MINUTE_IN_SECONDS);
                return array('update' => false);
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data) || empty($data['update']) || empty($data['version']) || empty($data['package'])) $data = array('update' => false);
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
                'name' => isset($data['name']) ? sanitize_text_field($data['name']) : $this->name,
                'slug' => $this->slug,
                'version' => $data['version'],
                'author' => 'WP Panda Vault',
                'homepage' => $this->api_url,
                'requires' => isset($data['requires']) ? $data['requires'] : '6.0',
                'requires_php' => isset($data['requires_php']) ? $data['requires_php'] : '7.4',
                'tested' => isset($data['tested']) ? $data['tested'] : '',
                'download_link' => esc_url_raw($data['package']),
                'sections' => array('description' => '', 'changelog' => wp_kses_post(nl2br(isset($data['changelog']) ? $data['changelog'] : ''))),
            );
        }

        public static function register_settings_page() {
            if (!self::$registry) return;
            add_options_page(__('Panda Updates', 'wp-panda-updater'), __('Panda Updates', 'wp-panda-updater'), 'manage_options', 'wp-panda-updates', array(__CLASS__, 'render_settings_page'));
        }

        public static function render_settings_page() {
            if (!current_user_can('manage_options')) wp_die(esc_html__('Недостаточно прав.', 'wp-panda-updater'));
            $notice = get_transient('wp_panda_client_notice_' . get_current_user_id());
            if ($notice) {
                delete_transient('wp_panda_client_notice_' . get_current_user_id());
                echo '<div class="notice ' . (!empty($notice['success']) ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
            }
            echo '<div class="wrap"><h1>' . esc_html__('Panda Updates — лицензии', 'wp-panda-updater') . '</h1><p>' . esc_html__('Введите индивидуальный лицензионный ключ, выданный поставщиком. Ключ активируется для URL этого сайта и занимает один слот тарифа.', 'wp-panda-updater') . '</p>';
            foreach (self::$registry as $instance) {
                $stored = $instance->license_key();
                echo '<section style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;margin:16px 0;max-width:900px"><h2>' . esc_html($instance->name) . '</h2><p><code>' . esc_html($instance->type . ' / ' . $instance->slug) . '</code></p>';
                if ($stored) echo '<p><span style="color:#16834a">●</span> ' . esc_html__('Ключ сохранён. Состояние и лимит проверяются сервером при активации и запросе обновления.', 'wp-panda-updater') . '</p>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('wp_panda_client_license_' . $instance->type . '_' . $instance->slug);
                echo '<input type="hidden" name="action" value="wp_panda_updater_license"><input type="hidden" name="type" value="' . esc_attr($instance->type) . '"><input type="hidden" name="slug" value="' . esc_attr($instance->slug) . '">';
                if (!$stored) {
                    echo '<p><label for="wp-panda-license-' . esc_attr($instance->type . '-' . $instance->slug) . '"><strong>' . esc_html__('Лицензионный ключ', 'wp-panda-updater') . '</strong></label><br><input class="regular-text" type="password" autocomplete="new-password" required id="wp-panda-license-' . esc_attr($instance->type . '-' . $instance->slug) . '" name="license_key" placeholder="WPPV-…"></p>';
                    echo '<button class="button button-primary" type="submit" name="license_action" value="activate">' . esc_html__('Активировать лицензию', 'wp-panda-updater') . '</button>';
                } else {
                    echo '<input type="password" class="regular-text" name="license_key" autocomplete="new-password" placeholder="' . esc_attr__('Введите новый ключ для замены', 'wp-panda-updater') . '"> ';
                    echo '<button class="button button-primary" type="submit" name="license_action" value="activate">' . esc_html__('Заменить ключ', 'wp-panda-updater') . '</button> ';
                    echo '<button class="button" type="submit" name="license_action" value="deactivate" onclick="return confirm(\'Освободить слот лицензии для этого сайта?\')">' . esc_html__('Деактивировать сайт', 'wp-panda-updater') . '</button> ';
                    echo '<button class="button button-link-delete" type="submit" name="license_action" value="forget" onclick="return confirm(\'Ключ будет удалён локально; если сервер недоступен, владелец лицензии должен освободить слот в Vault. Продолжить?\')">' . esc_html__('Удалить ключ локально', 'wp-panda-updater') . '</button>';
                }
                echo '</form></section>';
            }
            echo '<p class="description">' . esc_html__('При переносе сайта сначала нажмите «Деактивировать сайт» на старом домене, затем активируйте ключ на новом. Если старый сайт недоступен, владелец лицензии может освободить слот в панели Vault.', 'wp-panda-updater') . '</p></div>';
        }

        public static function handle_license_action() {
            if (!current_user_can('manage_options')) wp_die(esc_html__('Недостаточно прав.', 'wp-panda-updater'));
            $type = sanitize_key(wp_unslash($_POST['type'] ?? ''));
            $slug = sanitize_key(wp_unslash($_POST['slug'] ?? ''));
            $registry_key = $type . ':' . $slug;
            if (!isset(self::$registry[$registry_key])) wp_die(esc_html__('Неизвестный продукт.', 'wp-panda-updater'));
            $instance = self::$registry[$registry_key];
            check_admin_referer('wp_panda_client_license_' . $type . '_' . $slug);
            $action = sanitize_key(wp_unslash($_POST['license_action'] ?? ''));
            $old_key = $instance->license_key();
            $new_key = trim(sanitize_text_field(wp_unslash($_POST['license_key'] ?? '')));
            if ($action === 'forget') {
                delete_option($instance->option_name);
                delete_transient($instance->cache_key);
                self::redirect_settings(true, __('Ключ удалён локально. Если сервер не получил деактивацию, освободите слот в панели Vault.', 'wp-panda-updater'));
            }
            if ($action === 'activate') {
                if (!$new_key) self::redirect_settings(false, __('Введите лицензионный ключ.', 'wp-panda-updater'));
                $response = $instance->license_request('activate', $new_key);
                if (is_wp_error($response)) self::redirect_settings(false, $response->get_error_message());
                if ((int) wp_remote_retrieve_response_code($response) !== 200) self::redirect_settings(false, self::response_error($response));
                $old_normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $old_key));
                $new_normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $new_key));
                if ($old_key && !hash_equals($old_normalized, $new_normalized)) $instance->license_request('deactivate', $old_key);
                update_option($instance->option_name, $new_key, false);
                delete_transient($instance->cache_key);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $message = sprintf(__('Лицензия активирована: %1$d из %2$d сайтов.', 'wp-panda-updater'), absint($body['activations'] ?? 1), absint($body['limit'] ?? 1));
                self::redirect_settings(true, $message);
            }
            if ($action === 'deactivate') {
                if (!$old_key) self::redirect_settings(false, __('Для этого продукта нет сохранённого ключа.', 'wp-panda-updater'));
                $response = $instance->license_request('deactivate', $old_key);
                if (is_wp_error($response)) self::redirect_settings(false, $response->get_error_message());
                if ((int) wp_remote_retrieve_response_code($response) !== 200) self::redirect_settings(false, self::response_error($response));
                delete_option($instance->option_name);
                delete_transient($instance->cache_key);
                self::redirect_settings(true, __('Сайт деактивирован, слот освобождён.', 'wp-panda-updater'));
            }
            self::redirect_settings(false, __('Неизвестное действие.', 'wp-panda-updater'));
        }

        private function license_request($action, $license_key) {
            $endpoint = trailingslashit($this->api_url) . 'license/' . $action;
            return wp_remote_post($endpoint, array(
                'timeout' => 15,
                'redirection' => 0,
                'sslverify' => true,
                'headers' => array('Accept' => 'application/json', 'Content-Type' => 'application/json'),
                'body' => wp_json_encode(array(
                    'slug' => $this->slug,
                    'type' => $this->type,
                    'license_key' => $license_key,
                    'site_url' => home_url('/'),
                )),
            ));
        }

        private static function response_error($response) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($body) && !empty($body['message'])) return sanitize_text_field($body['message']);
            return sprintf(__('Сервер лицензий вернул HTTP %d.', 'wp-panda-updater'), (int) wp_remote_retrieve_response_code($response));
        }

        private static function redirect_settings($success, $message) {
            set_transient('wp_panda_client_notice_' . get_current_user_id(), array('success' => (bool) $success, 'message' => $message), MINUTE_IN_SECONDS);
            wp_safe_redirect(admin_url('options-general.php?page=wp-panda-updates'));
            exit;
        }
    }
}
