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
        private $settings_page;
        private $settings_parent;

        public function __construct($args) {
            $this->type = isset($args['type']) ? sanitize_key($args['type']) : 'plugin';
            $this->slug = isset($args['slug']) ? sanitize_key($args['slug']) : '';
            $this->name = isset($args['name']) ? sanitize_text_field($args['name']) : $this->slug;
            $this->version = isset($args['version']) ? (string) $args['version'] : '0.0.0';
            $this->api_url = isset($args['api_url']) ? untrailingslashit(esc_url_raw(trim((string) $args['api_url']))) : '';
            $this->plugin_file = isset($args['plugin_file']) ? plugin_basename($args['plugin_file']) : $this->detect_plugin_file();
            $this->settings_page = !empty($args['settings_page']) ? (is_string($args['settings_page']) ? sanitize_key($args['settings_page']) : true) : false;
            $this->settings_parent = isset($args['settings_parent']) ? sanitize_key($args['settings_parent']) : '';
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
                add_action('wp_ajax_wp_panda_updater_license', array(__CLASS__, 'handle_license_ajax'));
                add_action('admin_footer', array(__CLASS__, 'print_license_script'));
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
            $has_default_page = false;
            $parent = '';
            foreach (self::$registry as $instance) {
                if (!$instance->settings_page) {
                    $has_default_page = true;
                    if (!$parent && $instance->settings_parent) $parent = $instance->settings_parent;
                }
            }
            if (!$has_default_page) return;
            $callback = array(__CLASS__, 'render_settings_page');
            if ($parent) {
                add_submenu_page($parent, __('Panda Updates', 'wp-panda-updater'), __('Лицензия обновлений', 'wp-panda-updater'), 'manage_options', 'wp-panda-updates', $callback);
            } else {
                add_options_page(__('Panda Updates', 'wp-panda-updater'), __('Panda Updates', 'wp-panda-updater'), 'manage_options', 'wp-panda-updates', $callback);
            }
        }

        public static function render_settings_page() {
            if (!current_user_can('manage_options')) wp_die(esc_html__('Недостаточно прав.', 'wp-panda-updater'));
            echo '<div class="wrap"><h1>' . esc_html__('Panda Updates — лицензии', 'wp-panda-updater') . '</h1>';
            echo '<p>' . esc_html__('Управление лицензиями продуктов, подключённых к частному серверу обновлений.', 'wp-panda-updater') . '</p>';
            foreach (self::$registry as $instance) {
                if ($instance->settings_page) continue;
                self::render_license_field($instance->slug, $instance->type);
            }
            echo '</div>';
        }

        /**
         * Render a license control inside a product's own settings page.
         * It deliberately outputs no <form>, so it can be used inside an existing settings form.
         */
        public static function render_license_field($slug, $type = 'plugin') {
            $key = sanitize_key($type) . ':' . sanitize_key($slug);
            if (!isset(self::$registry[$key])) return false;
            if (!current_user_can('manage_options')) return false;
            $instance = self::$registry[$key];
            $stored = (bool) $instance->license_key();
            $id = 'wp-panda-license-' . md5($key);
            echo '<section class="wp-panda-license-control" id="' . esc_attr($id) . '" data-type="' . esc_attr($instance->type) . '" data-slug="' . esc_attr($instance->slug) . '" data-nonce="' . esc_attr(wp_create_nonce('wp_panda_client_license_' . $instance->type . '_' . $instance->slug)) . '">';
            echo '<h3>' . esc_html(sprintf(__('Лицензия обновлений: %s', 'wp-panda-updater'), $instance->name)) . '</h3>';
            echo '<p class="description"><strong>' . esc_html__('Лицензия не влияет на работоспособность продукта, а только на получение обновлений.', 'wp-panda-updater') . '</strong></p>';
            if ($stored) {
                echo '<p><span style="color:#16834a">●</span> ' . esc_html__('Ключ сохранён. Доступ к обновлениям проверяется сервером.', 'wp-panda-updater') . '</p>';
            }
            echo '<label for="' . esc_attr($id . '-key') . '"><strong>' . esc_html($stored ? __('Новый лицензионный ключ (для замены)', 'wp-panda-updater') : __('Лицензионный ключ', 'wp-panda-updater')) . '</strong></label><br>';
            echo '<input class="regular-text wp-panda-license-key" type="password" autocomplete="new-password" id="' . esc_attr($id . '-key') . '" placeholder="WPPV-…"> ';
            echo '<button type="button" class="button button-primary wp-panda-license-action" data-license-action="activate">' . esc_html($stored ? __('Заменить ключ', 'wp-panda-updater') : __('Активировать лицензию', 'wp-panda-updater')) . '</button> ';
            if ($stored) {
                echo '<button type="button" class="button wp-panda-license-action" data-license-action="deactivate">' . esc_html__('Деактивировать сайт', 'wp-panda-updater') . '</button> ';
                echo '<button type="button" class="button button-link-delete wp-panda-license-action" data-license-action="forget">' . esc_html__('Удалить ключ локально', 'wp-panda-updater') . '</button>';
            }
            echo '<div class="wp-panda-license-message" role="status" aria-live="polite" style="margin-top:8px"></div>';
            echo '</section>';
            return true;
        }

        public static function print_license_script() {
            if (!current_user_can('manage_options') || !self::$registry) return;
            echo '<script>(function(){document.addEventListener("click",function(event){var button=event.target.closest(".wp-panda-license-action");if(!button)return;var box=button.closest(".wp-panda-license-control");if(!box)return;var action=button.getAttribute("data-license-action");if(action==="deactivate"&&!window.confirm("Деактивировать сайт и освободить слот лицензии?"))return;if(action==="forget"&&!window.confirm("Ключ будет удалён только на этом сайте. Если сервер недоступен, слот нужно освободить в Vault. Продолжить?"))return;var input=box.querySelector(".wp-panda-license-key");if(action==="activate"&&(!input||!input.value.trim())){box.querySelector(".wp-panda-license-message").textContent="Введите лицензионный ключ.";return;}button.disabled=true;var payload=new URLSearchParams({action:"wp_panda_updater_license",nonce:box.dataset.nonce,type:box.dataset.type,slug:box.dataset.slug,license_action:action,license_key:input?input.value:""});fetch(ajaxurl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:payload.toString()}).then(function(response){return response.json().then(function(data){return {ok:response.ok,data:data};});}).then(function(result){var message=box.querySelector(".wp-panda-license-message");message.textContent=result.data&&result.data.data&&result.data.data.message?result.data.data.message:(result.data&&result.data.success?"Готово.":"Не удалось выполнить запрос лицензии.");message.className="wp-panda-license-message notice inline "+(result.ok&&result.data.success?"notice-success":"notice-error");if(result.ok&&result.data.success)setTimeout(function(){window.location.reload();},900);else button.disabled=false;}).catch(function(){var message=box.querySelector(".wp-panda-license-message");message.textContent="Не удалось связаться с сервером лицензий.";message.className="wp-panda-license-message notice inline notice-error";button.disabled=false;});});})();</script>';
        }

        public static function handle_license_ajax() {
            if (!current_user_can('manage_options')) wp_send_json_error(array('message' => __('Недостаточно прав.', 'wp-panda-updater')), 403);
            $type = sanitize_key(wp_unslash($_POST['type'] ?? ''));
            $slug = sanitize_key(wp_unslash($_POST['slug'] ?? ''));
            $registry_key = $type . ':' . $slug;
            if (!isset(self::$registry[$registry_key])) wp_send_json_error(array('message' => __('Неизвестный продукт.', 'wp-panda-updater')), 404);
            $instance = self::$registry[$registry_key];
            $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
            if (!wp_verify_nonce($nonce, 'wp_panda_client_license_' . $type . '_' . $slug)) wp_send_json_error(array('message' => __('Сессия истекла. Обновите страницу и повторите попытку.', 'wp-panda-updater')), 403);
            $action = sanitize_key(wp_unslash($_POST['license_action'] ?? ''));
            $old_key = $instance->license_key();
            $new_key = trim(sanitize_text_field(wp_unslash($_POST['license_key'] ?? '')));

            if ($action === 'forget') {
                delete_option($instance->option_name);
                delete_transient($instance->cache_key);
                wp_send_json_success(array('message' => __('Ключ удалён локально. Если сервер не получил деактивацию, освободите слот в панели Vault.', 'wp-panda-updater')));
            }
            if ($action === 'activate') {
                if (!$new_key) wp_send_json_error(array('message' => __('Введите лицензионный ключ.', 'wp-panda-updater')), 400);
                $response = $instance->license_request('activate', $new_key);
                if (is_wp_error($response)) wp_send_json_error(array('message' => $response->get_error_message()), 502);
                if ((int) wp_remote_retrieve_response_code($response) !== 200) {
                    $code = (int) wp_remote_retrieve_response_code($response);
                    wp_send_json_error(array('message' => self::response_error($response)), $code >= 400 && $code < 600 ? $code : 502);
                }
                $old_normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $old_key));
                $new_normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $new_key));
                if ($old_key && !hash_equals($old_normalized, $new_normalized)) $instance->license_request('deactivate', $old_key);
                update_option($instance->option_name, $new_key, false);
                delete_transient($instance->cache_key);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $message = sprintf(__('Лицензия активирована: %1$d из %2$d сайтов.', 'wp-panda-updater'), absint($body['activations'] ?? 1), absint($body['limit'] ?? 1));
                wp_send_json_success(array('message' => $message));
            }
            if ($action === 'deactivate') {
                if (!$old_key) wp_send_json_error(array('message' => __('Для этого продукта нет сохранённого ключа.', 'wp-panda-updater')), 400);
                $response = $instance->license_request('deactivate', $old_key);
                if (is_wp_error($response)) wp_send_json_error(array('message' => $response->get_error_message()), 502);
                if ((int) wp_remote_retrieve_response_code($response) !== 200) {
                    $code = (int) wp_remote_retrieve_response_code($response);
                    wp_send_json_error(array('message' => self::response_error($response)), $code >= 400 && $code < 600 ? $code : 502);
                }
                delete_option($instance->option_name);
                delete_transient($instance->cache_key);
                wp_send_json_success(array('message' => __('Сайт деактивирован, слот освобождён.', 'wp-panda-updater')));
            }
            wp_send_json_error(array('message' => __('Неизвестное действие.', 'wp-panda-updater')), 400);
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

    }
}

if (!function_exists('wp_panda_updater_license_field')) {
    /** Render this inside an existing product settings callback. */
    function wp_panda_updater_license_field($slug, $type = 'plugin') {
        return WP_Panda_Updater::render_license_field($slug, $type);
    }
}
