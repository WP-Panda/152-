<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WP_Panda_Vault_Admin {
    private static $instance;
    private $page_hook;

    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_post_wp_panda_vault_create', array($this, 'create_product'));
        add_action('admin_post_wp_panda_vault_upload', array($this, 'upload_release'));
        add_action('admin_post_wp_panda_vault_create_license', array($this, 'create_license'));
        add_action('admin_post_wp_panda_vault_revoke_license', array($this, 'revoke_license'));
        add_action('admin_post_wp_panda_vault_remove_activation', array($this, 'remove_activation'));
        add_action('admin_post_wp_panda_vault_delete', array($this, 'delete_product'));
        add_action('admin_notices', array($this, 'storage_notice'));
        add_action('admin_enqueue_scripts', array($this, 'admin_styles'));
    }

    public function add_menu() {
        $this->page_hook = add_menu_page(
            __('WP Panda Vault', 'wp-panda-vault'),
            __('Panda Vault', 'wp-panda-vault'),
            'manage_options',
            'wp-panda-vault',
            array($this, 'render_page'),
            'dashicons-lock',
            58
        );
    }

    public function admin_styles($hook) {
        if ($hook !== $this->page_hook) return;
        wp_register_style('wp-panda-vault-admin', false, array(), WP_PANDA_VAULT_VERSION);
        wp_enqueue_style('wp-panda-vault-admin');
        wp_add_inline_style('wp-panda-vault-admin', '.wppv-wrap{max-width:1180px}.wppv-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(280px,.65fr);gap:20px;margin-top:20px}.wppv-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:18px 0}.wppv-card h2{margin-top:0}.wppv-key{padding:12px;background:#f0f6fc;border-left:4px solid #2271b1;font:13px monospace;word-break:break-all}.wppv-code{background:#1d2327;color:#d7f0df;padding:14px;overflow:auto;white-space:pre-wrap}.wppv-muted{color:#646970}.wppv-badge{display:inline-block;padding:3px 8px;background:#edf5f0;border-radius:12px}.wppv-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.wppv-inline{display:inline-block;margin-right:8px}.wppv-table td{vertical-align:middle}.wppv-danger{color:#b32d2e}@media(max-width:850px){.wppv-grid{grid-template-columns:1fr}}');
    }

    public function storage_notice() {
        if (!current_user_can('manage_options')) return;
        $storage = WP_Panda_Vault::prepare_storage();
        if (is_wp_error($storage)) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('WP Panda Vault storage error:', 'wp-panda-vault') . '</strong> ' . esc_html($storage->get_error_message()) . '</p></div>';
        }
    }

    public function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to access this page.', 'wp-panda-vault'));
        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        echo '<div class="wrap wppv-wrap"><h1>WP Panda Vault</h1>';
        $this->render_notice();
        $license_notice = get_transient('wppv_license_' . get_current_user_id());
        if (is_array($license_notice) && !empty($license_notice['key']) && absint($license_notice['product_id'] ?? 0) === $product_id) {
            delete_transient('wppv_license_' . get_current_user_id());
            echo '<div class="notice notice-success"><p><strong>' . esc_html__('Скопируйте индивидуальный лицензионный ключ сейчас — повторно он не показывается.', 'wp-panda-vault') . '</strong></p><p class="wppv-key">' . esc_html($license_notice['key']) . '</p></div>';
        }
        if ($product_id) {
            $product = WP_Panda_Vault::get_product($product_id);
            if (!$product) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Продукт не найден.', 'wp-panda-vault') . '</p></div>';
            } else {
                $this->render_product($product);
            }
        } else {
            $this->render_catalog();
        }
        echo '</div>';
    }

    private function render_notice() {
        $messages = array(
            'created' => __('Продукт создан.', 'wp-panda-vault'),
            'release' => __('Релиз опубликован.', 'wp-panda-vault'),
            'license_created' => __('Индивидуальная лицензия создана.', 'wp-panda-vault'),
            'license_revoked' => __('Лицензия отозвана.', 'wp-panda-vault'),
            'activation_removed' => __('Активация удалена, слот освобождён.', 'wp-panda-vault'),
            'deleted' => __('Продукт, лицензии и релизы удалены.', 'wp-panda-vault'),
        );
        $key = isset($_GET['notice']) ? sanitize_key($_GET['notice']) : '';
        if (isset($messages[$key])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$key]) . '</p></div>';
        if (isset($_GET['error'])) echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['error']))) . '</p></div>';
    }

    private function render_catalog() {
        global $wpdb;
        $products = (array) $wpdb->get_results('SELECT * FROM ' . WP_Panda_Vault::products_table() . ' ORDER BY created_at DESC, id DESC');
        $release_table = WP_Panda_Vault::releases_table();
        $release_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$release_table}");
        $download_count = (int) $wpdb->get_var("SELECT COALESCE(SUM(downloads),0) FROM {$release_table}");
        $product_count = count($products);
        echo '<p>' . esc_html__('Приватные обновления плагинов и тем через штатный механизм WordPress.', 'wp-panda-vault') . '</p>';
        echo '<div class="wppv-grid"><section class="wppv-card"><h2>' . esc_html__('Продукты', 'wp-panda-vault') . '</h2>';
        echo '<p class="wppv-muted">' . esc_html(sprintf(__('Продуктов: %1$d · релизов: %2$d · загрузок: %3$d', 'wp-panda-vault'), $product_count, $release_count, $download_count)) . '</p>';
        if (!$products) {
            echo '<p>' . esc_html__('Пока нет продуктов. Создайте плагин или тему в форме справа.', 'wp-panda-vault') . '</p>';
        } else {
            echo '<table class="widefat striped wppv-table"><thead><tr><th>' . esc_html__('Название', 'wp-panda-vault') . '</th><th>Slug</th><th>' . esc_html__('Тип', 'wp-panda-vault') . '</th><th>' . esc_html__('Последняя версия', 'wp-panda-vault') . '</th><th>' . esc_html__('Скачивания', 'wp-panda-vault') . '</th></tr></thead><tbody>';
            foreach ($products as $product) {
                $latest = WP_Panda_Vault::latest_release($product->id);
                $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(downloads),0) FROM ' . $release_table . ' WHERE product_id = %d', $product->id));
                $url = add_query_arg(array('page' => 'wp-panda-vault', 'product_id' => $product->id), admin_url('admin.php'));
                echo '<tr><td><a href="' . esc_url($url) . '"><strong>' . esc_html($product->name) . '</strong></a></td><td><code>' . esc_html($product->slug) . '</code></td><td>' . esc_html($product->type === 'theme' ? __('Тема', 'wp-panda-vault') : __('Плагин', 'wp-panda-vault')) . '</td><td>' . ($latest ? '<span class="wppv-badge">v' . esc_html($latest->version) . '</span>' : esc_html__('Нет релизов', 'wp-panda-vault')) . '</td><td>' . esc_html(number_format_i18n($count)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section><section class="wppv-card"><h2>' . esc_html__('Добавить продукт', 'wp-panda-vault') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wppv_create_product');
        echo '<input type="hidden" name="action" value="wp_panda_vault_create">';
        echo '<p><label for="wppv-name"><strong>' . esc_html__('Название', 'wp-panda-vault') . '</strong></label><br><input class="regular-text" id="wppv-name" name="name" required maxlength="191"></p>';
        echo '<p><label for="wppv-slug"><strong>Slug</strong></label><br><input class="regular-text" id="wppv-slug" name="slug" required pattern="[a-z0-9][a-z0-9_-]{1,59}" maxlength="60"><br><span class="description">' . esc_html__('Slug должен совпадать с именем каталога продукта в ZIP.', 'wp-panda-vault') . '</span></p>';
        echo '<p><label for="wppv-type"><strong>' . esc_html__('Тип продукта', 'wp-panda-vault') . '</strong></label><br><select id="wppv-type" name="type"><option value="plugin">' . esc_html__('Плагин', 'wp-panda-vault') . '</option><option value="theme">' . esc_html__('Тема', 'wp-panda-vault') . '</option></select></p>';
        submit_button(__('Создать продукт', 'wp-panda-vault'));
        echo '</form></section></div>';
        echo '<section class="wppv-card"><h2>' . esc_html__('Защита файлов', 'wp-panda-vault') . '</h2><p>' . esc_html(sprintf(__('Хранилище: %s', 'wp-panda-vault'), WP_Panda_Vault::storage_path())) . '</p><p class="wppv-muted">' . esc_html__('Каталог расположен вне web-root и ABSPATH. Хеши индивидуальных лицензий хранятся в базе; исходные ключи показываются только один раз при выпуске.', 'wp-panda-vault') . '</p></section>';
    }

    private function render_product($product) {
        $back = admin_url('admin.php?page=wp-panda-vault');
        $releases = WP_Panda_Vault::get_releases($product->id);
        $latest = WP_Panda_Vault::latest_release($product->id);
        echo '<p><a href="' . esc_url($back) . '">← ' . esc_html__('К списку продуктов', 'wp-panda-vault') . '</a></p>';
        echo '<h2>' . esc_html($product->name) . ' <code>' . esc_html($product->slug) . '</code> <span class="wppv-badge">' . esc_html($product->type) . '</span></h2>';
        echo '<div class="wppv-actions"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wppv_delete_' . $product->id);
        echo '<input type="hidden" name="action" value="wp_panda_vault_delete"><input type="hidden" name="product_id" value="' . esc_attr($product->id) . '">';
        submit_button(__('Удалить продукт', 'wp-panda-vault'), 'delete', 'submit', false, array('onclick' => "return confirm('Удалить продукт, лицензии и все ZIP-релизы без возможности восстановления?')"));
        echo '</form></div>';
        echo '<section class="wppv-card"><h2>' . esc_html__('Интеграция обновлений', 'wp-panda-vault') . '</h2><p>' . esc_html__('Добавьте SDK wp-panda-updater.php в пакет. На каждом сайте пользователь активирует индивидуальный ключ через Настройки → Panda Updates.', 'wp-panda-vault') . '</p>';
        $snippet = "require_once __DIR__ . '/wp-panda-updater.php';\nnew WP_Panda_Updater(array(\n    'type' => " . var_export($product->type, true) . ",\n    'slug' => " . var_export($product->slug, true) . ",\n    'name' => " . var_export($product->name, true) . ",\n    'version' => '1.0.0', // синхронизировать с заголовком продукта\n    'api_url' => " . var_export(rest_url('wp-panda/v1'), true) . ",\n));";
        echo '<pre class="wppv-code">' . esc_html($snippet) . '</pre>';
        echo '<p class="description">' . esc_html__('Не встраивайте клиентский лицензионный ключ в публичный код. Первая сборка с SDK устанавливается обычным способом; пользователь вводит ключ в админке каждого сайта.', 'wp-panda-vault') . '</p></section>';
        $this->render_licenses($product);
        echo '<div class="wppv-grid"><section class="wppv-card"><h2>' . esc_html__('Релизы', 'wp-panda-vault') . '</h2>';
        if (!$releases) echo '<p>' . esc_html__('Опубликованных релизов пока нет.', 'wp-panda-vault') . '</p>';
        else {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Версия', 'wp-panda-vault') . '</th><th>' . esc_html__('Дата', 'wp-panda-vault') . '</th><th>' . esc_html__('Размер', 'wp-panda-vault') . '</th><th>' . esc_html__('Скачивания', 'wp-panda-vault') . '</th><th>' . esc_html__('SHA-256', 'wp-panda-vault') . '</th></tr></thead><tbody>';
            foreach ($releases as $release) {
                $is_latest = $latest && (int) $latest->id === (int) $release->id;
                echo '<tr><td><strong>v' . esc_html($release->version) . '</strong> ' . ($is_latest ? '<span class="wppv-badge">' . esc_html__('Последняя', 'wp-panda-vault') . '</span>' : '') . '<br><span class="description">' . nl2br(esc_html($release->changelog)) . '</span></td><td>' . esc_html(get_date_from_gmt($release->created_at, 'Y-m-d H:i')) . '</td><td>' . esc_html(size_format((int) $release->package_size)) . '</td><td>' . esc_html(number_format_i18n((int) $release->downloads)) . '</td><td><code>' . esc_html(substr($release->package_sha256, 0, 16)) . '…</code></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section><section class="wppv-card"><h2>' . esc_html__('Опубликовать релиз', 'wp-panda-vault') . '</h2>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wppv_upload_' . $product->id);
        echo '<input type="hidden" name="action" value="wp_panda_vault_upload"><input type="hidden" name="product_id" value="' . esc_attr($product->id) . '">';
        echo '<p><label><strong>' . esc_html__('Версия', 'wp-panda-vault') . '</strong><br><input name="version" required pattern="[0-9]+([.][0-9]+){0,3}" placeholder="1.2.0"></label></p>';
        echo '<p><label><strong>' . esc_html__('ZIP-архив', 'wp-panda-vault') . '</strong><br><input type="file" name="package" accept=".zip,application/zip" required></label><br><span class="description">' . esc_html(sprintf(__('До %s. На сервере должен быть включён PHP ZipArchive.', 'wp-panda-vault'), size_format(WP_Panda_Vault::max_package_size()))) . '</span></p>';
        echo '<p><label><strong>' . esc_html__('Описание изменений', 'wp-panda-vault') . '</strong><br><textarea class="large-text" name="changelog" rows="5"></textarea></label></p>';
        echo '<details><summary>' . esc_html__('Требования WordPress/PHP (необязательно)', 'wp-panda-vault') . '</summary><p><label>' . esc_html__('Требует WordPress', 'wp-panda-vault') . ' <input name="requires_wp" value="6.0"></label> &nbsp; <label>' . esc_html__('Требует PHP', 'wp-panda-vault') . ' <input name="requires_php" value="7.4"></label> &nbsp; <label>' . esc_html__('Проверено до WordPress', 'wp-panda-vault') . ' <input name="tested_wp" value=""></label></p></details>';
        submit_button(__('Загрузить и опубликовать', 'wp-panda-vault'));
        echo '</form></section></div>';
    }

    public function create_product() {
        $this->authorize_admin();
        check_admin_referer('wppv_create_product');
        global $wpdb;
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $slug = strtolower(trim(sanitize_text_field(wp_unslash($_POST['slug'] ?? ''))));
        $type = sanitize_key(wp_unslash($_POST['type'] ?? ''));
        if (strlen($name) < 2 || !preg_match('/^[a-z0-9][a-z0-9_-]{1,59}$/', $slug) || !in_array($type, array('plugin', 'theme'), true)) {
            $this->redirect_error(__('Проверьте название, slug и тип продукта.', 'wp-panda-vault'));
        }
        $result = $wpdb->insert(WP_Panda_Vault::products_table(), array(
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'api_key_hash' => '', // Retained for backward-compatible schema upgrades; licenses authorize clients.
            'status' => 'active',
            'created_at' => current_time('mysql', true),
        ), array('%s', '%s', '%s', '%s', '%s', '%s'));
        if (!$result) $this->redirect_error(__('Не удалось создать продукт. Такой slug/type уже может существовать.', 'wp-panda-vault'));
        $id = (int) $wpdb->insert_id;
        wp_safe_redirect(add_query_arg(array('page' => 'wp-panda-vault', 'product_id' => $id, 'notice' => 'created'), admin_url('admin.php')));
        exit;
    }

    public function upload_release() {
        $this->authorize_admin();
        $product_id = absint($_POST['product_id'] ?? 0);
        check_admin_referer('wppv_upload_' . $product_id);
        $product = WP_Panda_Vault::get_product($product_id);
        if (!$product) $this->redirect_error(__('Продукт не найден.', 'wp-panda-vault'));
        if (!class_exists('ZipArchive')) $this->redirect_error(__('На PHP-сервере требуется расширение ZipArchive для проверки ZIP.', 'wp-panda-vault'), $product_id);
        $version = sanitize_text_field(wp_unslash($_POST['version'] ?? ''));
        if (!preg_match('/^\d+(\.\d+){0,3}$/', $version)) $this->redirect_error(__('Укажите числовую версию, например 1.2.0.', 'wp-panda-vault'), $product_id);
        if (empty($_FILES['package']) || !isset($_FILES['package']['error']) || UPLOAD_ERR_OK !== (int) $_FILES['package']['error']) {
            $this->redirect_error(__('Не удалось загрузить ZIP. Проверьте лимиты upload_max_filesize и post_max_size в PHP.', 'wp-panda-vault'), $product_id);
        }
        $upload = $_FILES['package'];
        $max_size = WP_Panda_Vault::max_package_size();
        if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) $this->redirect_error(__('Временный загруженный файл недействителен.', 'wp-panda-vault'), $product_id);
        $actual_size = filesize($upload['tmp_name']);
        if ($actual_size === false || (int) $actual_size < 4 || (int) $actual_size > $max_size) {
            $this->redirect_error(sprintf(__('Размер файла должен быть от 4 байт до %s.', 'wp-panda-vault'), size_format($max_size)), $product_id);
        }
        $filename = sanitize_file_name($upload['name']);
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') $this->redirect_error(__('Разрешены только ZIP-архивы.', 'wp-panda-vault'), $product_id);
        $magic = file_get_contents($upload['tmp_name'], false, null, 0, 4);
        if (!is_string($magic) || substr($magic, 0, 2) !== "PK") $this->redirect_error(__('Файл не распознан как ZIP-архив.', 'wp-panda-vault'), $product_id);
        $zip_error = $this->validate_zip($upload['tmp_name'], $product->slug, $product->type, $version);
        if (is_wp_error($zip_error)) $this->redirect_error($zip_error->get_error_message(), $product_id);
        $storage = WP_Panda_Vault::prepare_storage();
        if (is_wp_error($storage)) $this->redirect_error($storage->get_error_message(), $product_id);
        try {
            $random_name = bin2hex(random_bytes(24)) . '.zip';
        } catch (Exception $e) {
            $this->redirect_error(__('Не удалось создать безопасное имя файла.', 'wp-panda-vault'), $product_id);
        }
        $destination = trailingslashit($storage) . $random_name;
        if (!move_uploaded_file($upload['tmp_name'], $destination)) $this->redirect_error(__('Не удалось перенести архив в закрытое хранилище.', 'wp-panda-vault'), $product_id);
        @chmod($destination, 0600);
        $hash = hash_file('sha256', $destination);
        $changelog = sanitize_textarea_field(wp_unslash($_POST['changelog'] ?? ''));
        $requires_wp = $this->clean_version(wp_unslash($_POST['requires_wp'] ?? '6.0'), '6.0');
        $requires_php = $this->clean_version(wp_unslash($_POST['requires_php'] ?? '7.4'), '7.4');
        $tested_wp = $this->clean_version(wp_unslash($_POST['tested_wp'] ?? ''), '');
        global $wpdb;
        $table = WP_Panda_Vault::releases_table();
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE product_id = %d AND version = %s", $product_id, $version));
        $data = array(
            'product_id' => $product_id,
            'version' => $version,
            'changelog' => $changelog,
            'package_path' => $destination,
            'package_size' => filesize($destination),
            'package_sha256' => $hash,
            'requires_wp' => $requires_wp,
            'requires_php' => $requires_php,
            'tested_wp' => $tested_wp,
            'created_at' => current_time('mysql', true),
        );
        if ($existing) {
            $saved = $wpdb->update($table, $data, array('id' => $existing->id));
            if ($saved === false) { @unlink($destination); $this->redirect_error(__('Не удалось обновить релиз в базе данных.', 'wp-panda-vault'), $product_id); }
            if (WP_Panda_Vault::is_path_in_storage($existing->package_path)) @unlink($existing->package_path);
        } else {
            $saved = $wpdb->insert($table, $data);
            if (!$saved) { @unlink($destination); $this->redirect_error(__('Не удалось сохранить релиз в базе данных.', 'wp-panda-vault'), $product_id); }
        }
        wp_safe_redirect(add_query_arg(array('page' => 'wp-panda-vault', 'product_id' => $product_id, 'notice' => 'release'), admin_url('admin.php')));
        exit;
    }

    private function validate_zip($file, $slug, $type, $version) {
        $zip = new ZipArchive();
        $opened = $zip->open($file, ZipArchive::CHECKCONS);
        if ($opened !== true) return new WP_Error('invalid_zip', __('ZIP повреждён или не может быть открыт.', 'wp-panda-vault'));
        $has_entry = false;
        $has_main = false;
        $version_matches = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (!is_string($entry) || strpos($entry, "\0") !== false) continue;
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                $zip->close();
                return new WP_Error('zip_symlink', __('ZIP entries cannot be symbolic links.', 'wp-panda-vault'));
            }
            $entry = str_replace('\\', '/', $entry);
            $parts = explode('/', trim($entry, '/'));
            $invalid_segment = (bool) array_intersect($parts, array('..', '.', ''));
            if ($invalid_segment || strpos($entry, '/') === 0 || strpos($entry, ':') !== false || !$parts || $parts[0] !== $slug) {
                $zip->close();
                return new WP_Error('invalid_zip_structure', sprintf(__('Внутри ZIP все файлы должны находиться в каталоге %s/; архивы с путями traversal запрещены.', 'wp-panda-vault'), $slug));
            }
            $has_entry = true;
            $is_theme_style = count($parts) === 2 && $type === 'theme' && strtolower($parts[1]) === 'style.css';
            $is_plugin_php = count($parts) === 2 && $type === 'plugin' && strtolower(pathinfo($parts[1], PATHINFO_EXTENSION)) === 'php';
            if ($is_theme_style || $is_plugin_php) {
                $header = $zip->getFromIndex($i, 8192);
                if (!is_string($header)) continue;
                $has_product_header = $type === 'theme'
                    ? (bool) preg_match('/^[ \t\/*#@]*Theme Name:/mi', $header)
                    : (bool) preg_match('/^[ \t\/*#@]*Plugin Name:/mi', $header);
                if ($has_product_header) {
                    $has_main = true;
                    if (preg_match('/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $header, $matches) && trim($matches[1]) === $version) {
                        $version_matches = true;
                    }
                }
            }
        }
        $zip->close();
        if (!$has_entry || !$has_main) {
            $expected = $type === 'theme' ? $slug . '/style.css' : $slug . '/main-plugin.php';
            return new WP_Error('missing_main_file', sprintf(__('В ZIP должен быть каталог %1$s/ и основной файл (%2$s).', 'wp-panda-vault'), $slug, $expected));
        }
        if (!$version_matches) {
            return new WP_Error('version_mismatch', __('Версия в ZIP-заголовке основного PHP-файла/теме должна точно совпадать с версией релиза.', 'wp-panda-vault'));
        }
        return true;
    }

    private function clean_version($value, $fallback) {
        $value = sanitize_text_field($value);
        return preg_match('/^\d+(\.\d+){0,3}$/', $value) ? $value : $fallback;
    }

    private function render_licenses($product) {
        global $wpdb;
        $licenses = WP_Panda_Vault::get_licenses($product->id);
        $activations_table = WP_Panda_Vault::activations_table();
        echo '<section class="wppv-card"><h2>' . esc_html__('Лицензии и активации сайтов', 'wp-panda-vault') . '</h2>';
        echo '<p>' . esc_html__('Создайте индивидуальный ключ и установите лимит на 1, 3 или 5 сайтов. Сайт занимает слот после активации ключа в своей админке.', 'wp-panda-vault') . '</p>';
        echo '<form class="wppv-actions" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wppv_create_license_' . $product->id);
        echo '<input type="hidden" name="action" value="wp_panda_vault_create_license"><input type="hidden" name="product_id" value="' . esc_attr($product->id) . '">';
        echo '<label>' . esc_html__('Метка/клиент', 'wp-panda-vault') . ' <input name="label" class="regular-text" maxlength="191" placeholder="Клиент или заказ"></label> ';
        echo '<label>' . esc_html__('Лимит сайтов', 'wp-panda-vault') . ' <select name="max_activations"><option value="1">1</option><option value="3">3</option><option value="5">5</option></select></label> ';
        submit_button(__('Выпустить лицензию', 'wp-panda-vault'), 'primary', 'submit', false);
        echo '</form>';
        if (!$licenses) {
            echo '<p>' . esc_html__('Лицензий пока нет.', 'wp-panda-vault') . '</p></section>';
            return;
        }
        echo '<table class="widefat striped" style="margin-top:18px"><thead><tr><th>' . esc_html__('Метка', 'wp-panda-vault') . '</th><th>' . esc_html__('Активации', 'wp-panda-vault') . '</th><th>' . esc_html__('Статус', 'wp-panda-vault') . '</th><th>' . esc_html__('Создана', 'wp-panda-vault') . '</th><th>' . esc_html__('Действие', 'wp-panda-vault') . '</th></tr></thead><tbody>';
        foreach ($licenses as $license) {
            $sites = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$activations_table} WHERE license_id = %d ORDER BY activated_at ASC", $license->id));
            echo '<tr><td><strong>' . esc_html($license->label ?: __('Без метки', 'wp-panda-vault')) . '</strong></td><td>' . esc_html(count($sites) . ' / ' . (int) $license->max_activations) . '</td><td>' . esc_html($license->status === 'active' ? __('Активна', 'wp-panda-vault') : __('Отозвана', 'wp-panda-vault')) . '</td><td>' . esc_html(get_date_from_gmt($license->created_at, 'Y-m-d')) . '</td><td>';
            if ($license->status === 'active') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('wppv_revoke_license_' . $license->id);
                echo '<input type="hidden" name="action" value="wp_panda_vault_revoke_license"><input type="hidden" name="product_id" value="' . esc_attr($product->id) . '"><input type="hidden" name="license_id" value="' . esc_attr($license->id) . '">';
                submit_button(__('Отозвать', 'wp-panda-vault'), 'delete', 'submit', false, array('onclick' => "return confirm('Лицензия перестанет работать на всех сайтах. Продолжить?')"));
                echo '</form>';
            }
            echo '</td></tr>';
            foreach ($sites as $site) {
                echo '<tr><td colspan="2">↳ <code>' . esc_html($site->site_url) . '</code></td><td colspan="2"><span class="description">' . esc_html(sprintf(__('Активирован: %1$s · последняя проверка: %2$s UTC', 'wp-panda-vault'), $site->activated_at, $site->last_seen_at)) . '</span></td><td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('wppv_remove_activation_' . $site->id);
                echo '<input type="hidden" name="action" value="wp_panda_vault_remove_activation"><input type="hidden" name="product_id" value="' . esc_attr($product->id) . '"><input type="hidden" name="license_id" value="' . esc_attr($license->id) . '"><input type="hidden" name="activation_id" value="' . esc_attr($site->id) . '">';
                submit_button(__('Освободить слот', 'wp-panda-vault'), 'secondary', 'submit', false, array('onclick' => "return confirm('Деактивировать этот сайт и освободить место?')"));
                echo '</form></td></tr>';
            }
        }
        echo '</tbody></table><p class="description">' . esc_html__('Если клиент переехал или потерял доступ к сайту, удалите его активацию здесь. Отозванные лицензии не принимают новые активации.', 'wp-panda-vault') . '</p></section>';
    }

    public function create_license() {
        $this->authorize_admin();
        $product_id = absint($_POST['product_id'] ?? 0);
        check_admin_referer('wppv_create_license_' . $product_id);
        $product = WP_Panda_Vault::get_product($product_id);
        if (!$product) $this->redirect_error(__('Продукт не найден.', 'wp-panda-vault'));
        $limit = absint($_POST['max_activations'] ?? 1);
        if (!in_array($limit, array(1, 3, 5), true)) $limit = 1;
        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        $key = WP_Panda_Vault::create_license_key();
        if (is_wp_error($key)) $this->redirect_error($key->get_error_message(), $product_id);
        global $wpdb;
        $saved = $wpdb->insert(WP_Panda_Vault::licenses_table(), array(
            'product_id' => $product_id,
            'license_key_hash' => hash('sha256', WP_Panda_Vault::normalize_license_key($key)),
            'label' => $label,
            'max_activations' => $limit,
            'status' => 'active',
            'created_at' => current_time('mysql', true),
        ), array('%d', '%s', '%s', '%d', '%s', '%s'));
        if (!$saved) $this->redirect_error(__('Не удалось сохранить лицензию в базе данных.', 'wp-panda-vault'), $product_id);
        set_transient('wppv_license_' . get_current_user_id(), array('key' => $key, 'product_id' => $product_id), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array('page' => 'wp-panda-vault', 'product_id' => $product_id, 'notice' => 'license_created'), admin_url('admin.php')));
        exit;
    }

    public function revoke_license() {
        $this->authorize_admin();
        $product_id = absint($_POST['product_id'] ?? 0);
        $license_id = absint($_POST['license_id'] ?? 0);
        check_admin_referer('wppv_revoke_license_' . $license_id);
        global $wpdb;
        $updated = $wpdb->update(WP_Panda_Vault::licenses_table(), array('status' => 'revoked'), array('id' => $license_id, 'product_id' => $product_id), array('%s'), array('%d', '%d'));
        if ($updated === false) $this->redirect_error(__('Не удалось отозвать лицензию.', 'wp-panda-vault'), $product_id);
        wp_safe_redirect(add_query_arg(array('page' => 'wp-panda-vault', 'product_id' => $product_id, 'notice' => 'license_revoked'), admin_url('admin.php')));
        exit;
    }

    public function remove_activation() {
        $this->authorize_admin();
        $product_id = absint($_POST['product_id'] ?? 0);
        $license_id = absint($_POST['license_id'] ?? 0);
        $activation_id = absint($_POST['activation_id'] ?? 0);
        check_admin_referer('wppv_remove_activation_' . $activation_id);
        global $wpdb;
        $wpdb->delete(WP_Panda_Vault::activations_table(), array('id' => $activation_id, 'license_id' => $license_id), array('%d', '%d'));
        wp_safe_redirect(add_query_arg(array('page' => 'wp-panda-vault', 'product_id' => $product_id, 'notice' => 'activation_removed'), admin_url('admin.php')));
        exit;
    }

    public function delete_product() {
        $this->authorize_admin();
        $id = absint($_POST['product_id'] ?? 0);
        check_admin_referer('wppv_delete_' . $id);
        global $wpdb;
        $product = WP_Panda_Vault::get_product($id);
        if (!$product) $this->redirect_error(__('Продукт не найден.', 'wp-panda-vault'));
        $releases = WP_Panda_Vault::get_releases($id);
        $licenses = WP_Panda_Vault::get_licenses($id);
        foreach ($licenses as $license) {
            $wpdb->delete(WP_Panda_Vault::activations_table(), array('license_id' => $license->id), array('%d'));
        }
        $wpdb->delete(WP_Panda_Vault::licenses_table(), array('product_id' => $id), array('%d'));
        $deleted_releases = $wpdb->delete(WP_Panda_Vault::releases_table(), array('product_id' => $id), array('%d'));
        $deleted_product = $wpdb->delete(WP_Panda_Vault::products_table(), array('id' => $id), array('%d'));
        if ($deleted_releases === false || $deleted_product === false) $this->redirect_error(__('Database error while deleting product; package files were retained.', 'wp-panda-vault'));
        foreach ($releases as $release) {
            if (WP_Panda_Vault::is_path_in_storage($release->package_path)) @unlink($release->package_path);
        }
        wp_safe_redirect(add_query_arg(array('page' => 'wp-panda-vault', 'notice' => 'deleted'), admin_url('admin.php')));
        exit;
    }

    private function authorize_admin() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to perform this action.', 'wp-panda-vault'));
    }

    private function redirect_error($message, $product_id = 0) {
        $args = array('page' => 'wp-panda-vault', 'error' => wp_strip_all_tags($message));
        if ($product_id) $args['product_id'] = absint($product_id);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
