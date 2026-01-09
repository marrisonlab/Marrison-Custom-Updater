<?php
/**
 * Plugin Name: Marrison Custom Updater
 * Plugin URI:  https://marrisonlab.com
 * Description: This plugin is used to add a personal repository for updating plugins.
 * Version: 3.5
 * Author: Angelo Marra
 * Author URI:  https://marrisonlab.com
 */

class Marrison_Custom_Updater {

    private $updates_url = 'https://marrisonlab.com/wp-repo/';
    private $cache_duration = 6 * HOUR_IN_SECONDS;

    public function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_marrison_update_plugin', [$this, 'update_plugin']);
        add_action('admin_post_marrison_bulk_update', [$this, 'bulk_update']);
        add_action('admin_post_marrison_clear_cache', [$this, 'clear_cache']);
        add_action('admin_post_marrison_save_repo_url', [$this, 'save_repo_url']);
        
        // Hook per AJAX
        add_action('wp_ajax_marrison_update_plugin_ajax', [$this, 'update_plugin_ajax']);
        add_action('wp_ajax_marrison_bulk_update_ajax', [$this, 'bulk_update_ajax']);
        add_action('wp_ajax_marrison_auto_update_ajax', [$this, 'auto_update_ajax']);
        
        // Aggiungi script e stili per la pagina admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Hook per aggiungere link al plugin Marrison Updater nella pagina dei plugin
        add_filter('plugin_action_links', [$this, 'add_marrison_action_links'], 10, 2);
        
        // Hook per aggiungere notifiche al menu
        add_action('admin_menu', [$this, 'add_menu_notification_badge'], 999);
        add_action('admin_head', [$this, 'add_menu_badge_styles']);
        add_action('admin_init', [$this, 'check_for_available_updates']);
    }

    /* ===================== AUTO UPDATE AJAX HANDLER ===================== */

    public function auto_update_ajax() {
        // Verifica il nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        if (!wp_verify_nonce($nonce, 'marrison_auto_update')) {
            wp_die('Security check failed');
        }

        // Verifica i permessi
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Ottieni tutti i plugin con aggiornamenti automatici attivati
        $auto_update_plugins = (array) get_site_option('auto_update_plugins', []);
        
        if (empty($auto_update_plugins)) {
            wp_send_json_error('Nessun plugin ha gli aggiornamenti automatici attivati');
        }

        // Ottieni tutti gli aggiornamenti disponibili
        $updates = $this->get_available_updates();
        $plugins = get_plugins();
        $plugins_to_update = [];
        
        // Trova i plugin che hanno sia aggiornamenti disponibili che auto-update attivato
        foreach ($updates as $u) {
            foreach ($plugins as $file => $data) {
                $slug = dirname($file);
                if ($slug === '.' || $slug === '') $slug = basename($file, '.php');
                
                if ($slug === $u['slug'] && version_compare($data['Version'], $u['version'], '<')) {
                    // Controlla se questo plugin ha l'auto-update attivato
                    if (in_array($file, $auto_update_plugins)) {
                        $plugins_to_update[] = $slug;
                    }
                    break;
                }
            }
        }
        
        if (empty($plugins_to_update)) {
            wp_send_json_error('Nessun plugin con aggiornamenti automatici attivati ha aggiornamenti disponibili');
        }

        $results = [];
        $success_count = 0;
        
        foreach ($plugins_to_update as $slug) {
            $result = false;
            
            // Controlla se è il plugin stesso (Marrison Custom Updater)
            if ($slug === 'marrison-custom-updater') {
                $transient = get_site_transient('update_plugins');
                if (isset($transient->response[plugin_basename(__FILE__)])) {
                    $update = $transient->response[plugin_basename(__FILE__)];
                    $result = $this->perform_self_update($update->package);
                }
            } else {
                $result = $this->perform_update($slug);
            }
            
            $results[$slug] = $result;
            if ($result) {
                $success_count++;
            }
        }

        if ($success_count > 0) {
            wp_send_json_success([
                'message' => sprintf('%d plugin con auto-update aggiornati con successo', $success_count),
                'results' => $results,
                'success_count' => $success_count,
                'total_count' => count($plugins_to_update)
            ]);
            
            // Aggiorna il conteggio delle notifiche
            $this->check_for_available_updates();
        } else {
            wp_send_json_error('Nessun plugin è stato aggiornato');
        }
    }

    /* ===================== MENU NOTIFICATION BADGE ===================== */

    public function check_for_available_updates() {
        // Salva il numero di aggiornamenti disponibili in un'opzione per accesso rapido
        $updates = $this->get_available_updates();
        $plugins = get_plugins();
        $update_count = 0;
        
        foreach ($updates as $u) {
            foreach ($plugins as $file => $data) {
                $slug = dirname($file);
                if ($slug === '.' || $slug === '') $slug = basename($file, '.php');
                
                if ($slug === $u['slug'] && version_compare($data['Version'], $u['version'], '<')) {
                    $update_count++;
                    break;
                }
            }
        }
        
        update_option('marrison_available_updates_count', $update_count);
    }

    public function add_menu_notification_badge() {
        $update_count = get_option('marrison_available_updates_count', 0);
        
        if ($update_count > 0) {
            global $menu;
            
            // Trova il menu Marrison Updater e aggiungi il conteggio
            foreach ($menu as $key => $item) {
                if (isset($item[2]) && $item[2] === 'marrison-updater') {
                    $menu[$key][0] .= ' <span class="marrison-update-badge awaiting-mod count-' . $update_count . '">' . $update_count . '</span>';
                    break;
                }
            }
        }
    }

    public function add_menu_badge_styles() {
        ?>
        <style>
        .marrison-update-badge {
            display: inline-block;
            background-color: #d63638;
            color: #fff;
            font-size: 9px;
            line-height: 17px;
            font-weight: 600;
            margin: 1px 0 0 2px;
            vertical-align: top;
            -webkit-border-radius: 10px;
            border-radius: 10px;
            z-index: 26;
            min-width: 7px;
            padding: 0 6px;
            text-align: center;
        }
        
        #adminmenu .marrison-update-badge {
            position: relative;
            top: -1px;
            left: 2px;
        }
        
        #adminmenu .wp-submenu a[href="admin.php?page=marrison-updater"] {
            position: relative;
        }
        
        #adminmenu .wp-submenu a[href="admin.php?page=marrison-updater"]:after {
            content: "";
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 8px;
            height: 8px;
            background-color: #d63638;
            border-radius: 50%;
            display: <?php echo get_option('marrison_available_updates_count', 0) > 0 ? 'block' : 'none'; ?>;
        }
        </style>
        <?php
    }

    /* ===================== UPDATE SOURCE ===================== */

    private function get_available_updates() {
        $custom_repo_url = get_option('marrison_repo_url');
        $repo_url = !empty($custom_repo_url) ? trailingslashit($custom_repo_url) : $this->updates_url;

        $cached = get_transient('marrison_available_updates');
        if ($cached !== false) return $cached;

        $response = wp_remote_get($repo_url . 'index.php', ['timeout' => 15]);
        if (is_wp_error($response)) return [];

        $updates = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($updates)) return [];

        set_transient('marrison_available_updates', $updates, $this->cache_duration);
        return $updates;
    }

    /* ===================== WP UPDATE HOOK ===================== */

    public function check_for_updates($transient) {
        if (!is_object($transient)) $transient = new stdClass();
        $plugins = get_plugins();

        foreach ($this->get_available_updates() as $update) {
            $file = $this->find_plugin_file($update['slug']);
            if (!$file || !isset($plugins[$file])) continue;

            $installed = $plugins[$file]['Version'];
            $remote    = $update['version'];

            if (version_compare($installed, $remote, '<')) {
                $transient->response[$file] = (object)[
                    'slug'        => $update['slug'],
                    'new_version' => $remote,
                    'package'     => $update['download_url'],
                ];
            }

            $transient->checked[$file] = $installed;
        }

        // Controlla anche il plugin stesso da GitHub
        $this->check_self_update($transient);

        return $transient;
    }

    private function check_self_update($transient) {
        $plugin_file = plugin_basename(__FILE__);
        $plugins = get_plugins();

        if (!isset($plugins[$plugin_file])) return;

        $installed = $plugins[$plugin_file]['Version'];
        $remote = $this->get_github_version();

        if ($remote && version_compare($installed, $remote, '<')) {
            $transient->response[$plugin_file] = (object)[
                'slug'        => 'marrison-custom-updater',
                'new_version' => $remote,
                'package'     => 'https://github.com/marrisonlab/Marrison-Custom-Updater/archive/refs/tags/v' . $remote . '.zip',
                'url'         => 'https://github.com/marrisonlab/Marrison-Custom-Updater',
                'plugin'      => $plugin_file,
                'tested'      => '6.4',
                'requires_php' => '7.4',
            ];
        }

        $transient->checked[$plugin_file] = $installed;
    }

    private function get_github_version() {
        $cached = get_transient('marrison_github_version');
        if ($cached !== false) return $cached;

        $response = wp_remote_get('https://api.github.com/repos/marrisonlab/marrison-custom-updater/releases/latest', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github.v3+json']
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['tag_name'])) return false;

        $version = str_replace('v', '', $body['tag_name']);
        set_transient('marrison_github_version', $version, 6 * HOUR_IN_SECONDS);

        return $version;
    }

    private function find_plugin_file($slug) {
        foreach (get_plugins() as $file => $data) {
            $dir = dirname($file);
            if ($dir === '.' || $dir === '') $dir = basename($file, '.php');
            if ($dir === $slug) return $file;
        }
        return null;
    }

    public function plugin_info($false, $action, $args) {
        if ($action !== 'plugin_information') return $false;
        
        // Controlla se è il nostro plugin
        if ($args->slug !== 'marrison-custom-updater') return $false;

        // Leggi le informazioni dal readme.txt su GitHub
        $response = wp_remote_get('https://raw.githubusercontent.com/marrisonlab/marrison-custom-updater/stable/readme.txt', [
            'timeout' => 10
        ]);

        if (is_wp_error($response)) return $false;

        $readme = wp_remote_retrieve_body($response);
        if (empty($readme)) return $false;

        // Parsa il readme.txt
        return $this->parse_readme($readme);
    }

    private function parse_readme($readme) {
        $info = new stdClass();
        
        // Estrai la descrizione
        if (preg_match('/== Description ==\s*(.*?)\s*== /s', $readme, $match)) {
            $info->description = trim($match[1]);
        } else {
            $info->description = '';
        }

        // Estrai il changelog
        if (preg_match('/== Changelog ==\s*(.*?)$/s', $readme, $match)) {
            $info->changelog = trim($match[1]);
        } else {
            $info->changelog = '';
        }

        // Dati base
        $github_version = $this->get_github_version();
        $info->name = 'Marrison Custom Updater';
        $info->slug = 'marrison-custom-updater';
        $info->version = $github_version ? $github_version : '1.0.0';
        $info->author = 'Angelo Marra';
        $info->author_profile = 'https://marrisonlab.com';
        $info->plugin_url = 'https://github.com/marrisonlab/marrison-custom-updater';
        $info->download_url = $info->version ? 'https://github.com/marrisonlab/marrison-custom-updater/archive/refs/tags/v' . $info->version . '.zip' : '';
        $info->requires_php = '7.4';
        $info->requires = '5.0';
        $info->tested = '6.4';
        $info->last_updated = current_time('mysql');
        $info->homepage = 'https://marrisonlab.com';
        $info->active_installs = 0;
        $info->rating = 100;
        $info->ratings = array(5 => 100);
        $info->num_ratings = 0;
        $info->support_url = 'https://github.com/marrisonlab/marrison-custom-updater/issues';
        $info->sections = array(
            'description' => $info->description ? $info->description : 'Plugin per aggiornamenti personalizzati',
            'changelog' => $info->changelog ? $info->changelog : 'Consultare il repository GitHub'
        );

        return $info;
    }

    /* ===================== PLUGIN ACTION LINKS ===================== */

    public function add_marrison_action_links($actions, $plugin_file) {
        // Controlla se è il file del plugin Marrison Custom Updater
        if (strpos($plugin_file, 'custom_updater.php') === false && strpos($plugin_file, 'marrison') === false) {
            return $actions;
        }

        // Link alla pagina del Marrison Updater
        $actions['marrison_settings'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=marrison-updater')),
            esc_html__('Setting', 'marrison-custom-updater')
        );

        return $actions;
    }

    /* ===================== REAL UPDATE ENGINE ===================== */

    private function perform_update($slug) {

        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        if (!$wp_filesystem) return false;

        foreach ($this->get_available_updates() as $update) {
            if ($update['slug'] !== $slug) continue;

            $zip = download_url($update['download_url']);
            if (is_wp_error($zip)) return false;

            $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-' . $slug;
            wp_mkdir_p($upgrade_dir);

            unzip_file($zip, $upgrade_dir);
            unlink($zip);

            $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
            if (empty($dirs)) return false;

            $source = trailingslashit($dirs[0]);
            $dest   = trailingslashit(WP_PLUGIN_DIR . '/' . $slug);

            if ($wp_filesystem->is_dir($dest)) {
                $wp_filesystem->delete($dest, true);
            }

            copy_dir($source, $dest);
            $wp_filesystem->delete($upgrade_dir, true);

            delete_site_transient('update_plugins');
            wp_clean_plugins_cache(true);

            return true;
        }

        return false;
    }

    private function perform_self_update($download_url) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        if (!$wp_filesystem) return false;

        $zip = download_url($download_url);
        if (is_wp_error($zip)) return false;

        $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-custom-updater-temp';
        wp_mkdir_p($upgrade_dir);

        unzip_file($zip, $upgrade_dir);
        unlink($zip);

        // Trova la cartella estratta (potrebbe avere nome diverso come marrison-custom-updater-v1.5)
        $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) return false;

        $source = trailingslashit($dirs[0]);
        $dest   = trailingslashit(WP_PLUGIN_DIR . '/marrison-custom-updater');

        if ($wp_filesystem->is_dir($dest)) {
            $wp_filesystem->delete($dest, true);
        }

        copy_dir($source, $dest);
        $wp_filesystem->delete($upgrade_dir, true);

        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);

        return true;
    }

    /* ===================== ACTIONS ===================== */

    public function update_plugin() {
        $slug = sanitize_text_field($_GET['slug'] ?? '');
        check_admin_referer('marrison_update_' . $slug);

        $this->perform_update($slug);

        wp_redirect(admin_url('admin.php?page=marrison-updater&updated=' . $slug));
        exit;
    }

    public function bulk_update() {
        check_admin_referer('marrison_bulk_update');

        $updated = [];
        foreach ($_POST['plugins'] ?? [] as $slug) {
            if ($this->perform_update(sanitize_text_field($slug))) {
                $updated[] = $slug;
            }
        }

        $query = http_build_query(['bulk_updated' => $updated]);
        wp_redirect(admin_url('admin.php?page=marrison-updater&' . $query));
        exit;
    }

    public function clear_cache() {
        check_admin_referer('marrison_clear_cache');
        delete_transient('marrison_available_updates');
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);

        wp_redirect(admin_url('admin.php?page=marrison-updater&cache_cleared=1'));
        exit;
    }

    public function save_repo_url() {
        check_admin_referer('marrison_save_repo_url');

        if (isset($_POST['marrison_remove_repo_url'])) {
            delete_option('marrison_repo_url');
            $redirect_url = admin_url('admin.php?page=marrison-updater&settings-updated=removed');
        } else {
            $url = sanitize_url($_POST['marrison_repo_url']);
            update_option('marrison_repo_url', $url);
            $redirect_url = admin_url('admin.php?page=marrison-updater&settings-updated=saved');
        }

        // Pulisce la cache dopo aver modificato l'URL
        delete_transient('marrison_available_updates');
        delete_site_transient('update_plugins');

        wp_redirect($redirect_url);
        exit;
    }

    /* ===================== AJAX HANDLER ===================== */

    public function update_plugin_ajax() {
        // Verifica il nonce - accetta sia nonce specifico che generico
        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        // Controlla nonce specifico per il plugin o nonce bulk generico
        $nonce_valid = wp_verify_nonce($nonce, 'marrison_update_' . $slug) || 
                       wp_verify_nonce($nonce, 'marrison_bulk_update') ||
                       wp_verify_nonce($nonce, 'marrison_update_marrison-custom-updater');
        
        if (!$nonce_valid) {
            wp_send_json_error('Security check failed');
        }

        // Verifica i permessi
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Esegui l'aggiornamento
        $result = false;
        
        // Controlla se è il plugin stesso (Marrison Custom Updater)
        if ($slug === 'marrison-custom-updater') {
            $transient = get_site_transient('update_plugins');
            if (isset($transient->response[plugin_basename(__FILE__)])) {
                $update = $transient->response[plugin_basename(__FILE__)];
                $result = $this->perform_self_update($update->package);
            }
        } else {
            $result = $this->perform_update($slug);
        }

        if ($result) {
            wp_send_json_success('Plugin aggiornato con successo');
            
            // Aggiorna il conteggio delle notifiche
            $this->check_for_available_updates();
        } else {
            wp_send_json_error('Errore durante l\'aggiornamento del plugin');
        }
    }

    /* ===================== BULK UPDATE AJAX HANDLER ===================== */

    public function bulk_update_ajax() {
        // Verifica il nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        $plugins = isset($_POST['plugins']) ? array_map('sanitize_text_field', $_POST['plugins']) : [];
        
        if (!wp_verify_nonce($nonce, 'marrison_bulk_update')) {
            wp_die('Security check failed');
        }

        // Verifica i permessi
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        if (empty($plugins)) {
            wp_send_json_error('Nessun plugin selezionato');
        }

        $results = [];
        $success_count = 0;
        
        foreach ($plugins as $slug) {
            $result = false;
            
            // Controlla se è il plugin stesso (Marrison Custom Updater)
            if ($slug === 'marrison-custom-updater') {
                $transient = get_site_transient('update_plugins');
                if (isset($transient->response[plugin_basename(__FILE__)])) {
                    $update = $transient->response[plugin_basename(__FILE__)];
                    $result = $this->perform_self_update($update->package);
                }
            } else {
                $result = $this->perform_update($slug);
            }
            
            $results[$slug] = $result;
            if ($result) {
                $success_count++;
            }
        }

        if ($success_count > 0) {
            wp_send_json_success([
                'message' => sprintf('%d plugin aggiornati con successo', $success_count),
                'results' => $results,
                'success_count' => $success_count,
                'total_count' => count($plugins)
            ]);
            
            // Aggiorna il conteggio delle notifiche
            $this->check_for_available_updates();
        } else {
            wp_send_json_error('Nessun plugin è stato aggiornato');
        }
    }

    /* ===================== ADMIN UI ===================== */

    public function enqueue_admin_scripts($hook) {
        // Carica gli script solo sulla nostra pagina
        if ($hook !== 'toplevel_page_marrison-updater') {
            return;
        }
        
        // Assicurati che jQuery sia caricato
        wp_enqueue_script('jquery');
        
        // Aggiungi lo script per la barra di caricamento
        wp_add_inline_script('jquery', '
            var marrisonUpdater = {
                ajaxurl: "' . admin_url('admin-ajax.php') . '"
            };
        ');
    }

    public function add_admin_menu() {
        // Aggiungi menu principale con icona carina
        add_menu_page(
            'Marrison Updater',
            'MCU',
            'manage_options',
            'marrison-updater',
            [$this,'admin_page'],
            'dashicons-update', // Icona carina per aggiornamenti
            30 // Posizione nel menu (dopo Dashboard e Media)
        );
    }

    public function admin_page() {

        $updates     = $this->get_available_updates();
        $plugins     = get_plugins();
        $updated     = $_GET['updated'] ?? '';
        $bulkUpdated = $_GET['bulk_updated'] ?? [];
        if (!is_array($bulkUpdated)) $bulkUpdated = [$bulkUpdated];
        $settingsUpdated = $_GET['settings-updated'] ?? '';

        ?>
        <div class="wrap">
            <h1>Marrison Updater</h1>

            <!-- Barra di caricamento -->
            <div id="marrison-update-progress" class="notice notice-info" style="display:none; padding: 15px; margin: 20px 0;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="spinner is-active" style="float:none; width:20px; height:20px; margin:0;"></div>
                    <div style="flex: 1;">
                        <div id="marrison-update-status" style="font-weight: 600; margin-bottom: 8px;">Aggiornamento in corso...</div>
                        <div style="background: #f0f0f1; border-radius: 4px; height: 8px; overflow: hidden;">
                            <div id="marrison-update-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                        </div>
                        <div id="marrison-update-info" style="font-size: 12px; color: #646970; margin-top: 4px;"></div>
                    </div>
                </div>
            </div>

            <?php if ($settingsUpdated === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Impostazioni salvate correttamente.</p></div>
            <?php elseif ($settingsUpdated === 'removed'): ?>
                <div class="notice notice-success is-dismissible"><p>URL del repository ripristinato ai valori predefiniti.</p></div>
            <?php endif; ?>

            <?php if ($bulkUpdated): ?>
                <div class="notice notice-success"><p>Bulk update completato ✓</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="notice notice-info"><p>Cache pulita ✓</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_bulk_update'); ?>
                <input type="hidden" name="action" value="marrison_bulk_update">

                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column"><label class="screen-reader-text" for="cb-select-all-1">Seleziona tutto</label><input id="cb-select-all-1" type="checkbox"></td>
                            <th>Plugin</th>
                            <th>Versione</th>
                            <th>Azione</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php foreach ($updates as $u):
                        foreach ($plugins as $file => $data) {
                            $slug = dirname($file);
                            if ($slug === '.' || $slug === '') $slug = basename($file, '.php');

                            if ($slug === $u['slug'] && version_compare($data['Version'], $u['version'], '<')):
                    ?>
                        <tr>
                            <td><input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>"></td>
                            <td><?php echo esc_html($u['name']); ?></td>
                            <td><?php echo esc_html($data['Version'] . ' → ' . $u['version']); ?></td>
                            <td>
                                <?php if ($updated === $slug || in_array($slug, $bulkUpdated, true)): ?>
                                    <strong style="color:green;">✓ Aggiornato</strong>
                                <?php else: ?>
                                    <?php 
                                    $is_self_update = ($slug === 'marrison-custom-updater');
                                    $nonce = $is_self_update ? wp_create_nonce('marrison_update_marrison-custom-updater') : wp_create_nonce('marrison_update_' . $slug);
                                    ?>
                                    <button class="button button-primary marrison-update-btn" 
                                            data-slug="<?php echo esc_attr($slug); ?>"
                                            data-nonce="<?php echo esc_attr($nonce); ?>">
                                        Aggiorna
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php
                            endif;
                        }
                    endforeach; ?>

                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="manage-column column-cb check-column"><label class="screen-reader-text" for="cb-select-all-2">Seleziona tutto</label><input id="cb-select-all-2" type="checkbox"></td>
                            <th>Plugin</th>
                            <th>Versione</th>
                            <th>Azione</th>
                        </tr>
                    </tfoot>
                </table>

                <p>
                    <button class="button button-secondary">Aggiorna selezionati</button>
                    <?php 
                    // Controlla se ci sono plugin con auto-update attivati che hanno aggiornamenti
                    $auto_update_plugins = (array) get_site_option('auto_update_plugins', []);
                    $updates = $this->get_available_updates();
                    $plugins = get_plugins();
                    $auto_update_available = false;
                    
                    foreach ($updates as $u) {
                        foreach ($plugins as $file => $data) {
                            $slug = dirname($file);
                            if ($slug === '.' || $slug === '') $slug = basename($file, '.php');
                            
                            if ($slug === $u['slug'] && version_compare($data['Version'], $u['version'], '<')) {
                                if (in_array($file, $auto_update_plugins)) {
                                    $auto_update_available = true;
                                    break 2;
                                }
                                break;
                            }
                        }
                    }
                    
                    if ($auto_update_available): 
                        $auto_update_nonce = wp_create_nonce('marrison_auto_update');
                    ?>
                        <button type="button" class="button button-primary marrison-auto-update-btn" 
                                data-nonce="<?php echo esc_attr($auto_update_nonce); ?>"
                                style="margin-left: 10px;">
                            <span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>
                            Aggiorna tutti i plugin con auto-update
                        </button>
                    <?php endif; ?>
                </p>
            </form>

            <hr>

            <h2>Impostazioni Repository</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_save_repo_url'); ?>
                <input type="hidden" name="action" value="marrison_save_repo_url">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="marrison_repo_url">Indirizzo Repository</label></th>
                        <td>
                            <input type="url" id="marrison_repo_url" name="marrison_repo_url" value="<?php echo esc_attr(get_option('marrison_repo_url', $this->updates_url)); ?>" class="regular-text">
                            <p class="description">Inserisci l'URL del repository personalizzato.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button class="button button-primary" type="submit">Salva</button>
                    <button class="button" type="submit" name="marrison_remove_repo_url" value="1">Rimuovi e ripristina default</button>
                </p>
            </form>

            <hr>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_clear_cache'); ?>
                <input type="hidden" name="action" value="marrison_clear_cache">
                <button class="button">Pulisci cache</button>
            </form>
        </div>
        <script>
            jQuery(document).ready(function($) {
                
                function updateProgressBar(percent, status, info) {
                    $('#marrison-update-bar').css('width', percent + '%');
                    $('#marrison-update-status').text(status);
                    if (info) {
                        $('#marrison-update-info').text(info);
                    }
                }

                function showProgressBar() {
                    $('#marrison-update-progress').show();
                    updateProgressBar(0, 'Preparazione aggiornamento...', '');
                }

                function hideProgressBar() {
                    setTimeout(function() {
                        $('#marrison-update-progress').fadeOut();
                    }, 2000);
                }

                // Gestione click sui pulsanti di aggiornamento singoli
                $('.marrison-update-btn').on('click', function(e) {
                    e.preventDefault();
                    
                    var $btn = $(this);
                    var slug = $btn.data('slug');
                    var nonce = $btn.data('nonce');
                    
                    // Disabilita il pulsante
                    $btn.prop('disabled', true).text('Aggiornamento...');
                    
                    // Mostra la barra di caricamento
                    showProgressBar();
                    
                    // Simula progresso
                    var progress = 0;
                    var progressInterval = setInterval(function() {
                        progress += Math.random() * 15;
                        if (progress > 90) progress = 90;
                        
                        if (progress < 30) {
                            updateProgressBar(progress, 'Download del plugin...', 'Scaricamento in corso');
                        } else if (progress < 60) {
                            updateProgressBar(progress, 'Estrazione file...', 'Decompressione archivio');
                        } else if (progress < 90) {
                            updateProgressBar(progress, 'Installazione aggiornamento...', 'Copia file');
                        }
                    }, 300);
                    
                    // Esegui l'aggiornamento via AJAX
                    $.ajax({
                        url: marrisonUpdater.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'marrison_update_plugin_ajax',
                            slug: slug,
                            nonce: nonce
                        },
                        success: function(response) {
                            clearInterval(progressInterval);
                            
                            if (response.success) {
                                updateProgressBar(100, 'Aggiornamento completato!', 'Plugin aggiornato con successo');
                                $btn.replaceWith('<strong style="color:green;">✓ Aggiornato</strong>');
                                
                                // Ricarica la pagina dopo 2 secondi per mostrare lo stato aggiornato
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                updateProgressBar(0, 'Errore durante l\'aggiornamento', response.data || 'Si è verificato un errore');
                                $btn.prop('disabled', false).text('Aggiorna');
                            }
                            
                            hideProgressBar();
                        },
                        error: function() {
                            clearInterval(progressInterval);
                            updateProgressBar(0, 'Errore di connessione', 'Impossibile contattare il server');
                            $btn.prop('disabled', false).text('Aggiorna');
                            hideProgressBar();
                        }
                    });
                });
                
                // Gestione aggiornamento multiplo via AJAX - AGGIORNA UNO PER UNO
                $('form input[name="action"][value="marrison_bulk_update"]').closest('form').on('submit', function(e) {
                    e.preventDefault();
                    console.log('Form submit intercettato');
                    
                    var $checked = $('input[name="plugins[]"]:checked');
                    if ($checked.length === 0) {
                        alert('Seleziona almeno un plugin da aggiornare');
                        return;
                    }
                    
                    var plugins = [];
                    $checked.each(function() {
                        plugins.push($(this).val());
                    });
                    
                    console.log('Plugin da aggiornare:', plugins);
                    
                    // Mostra la barra di caricamento
                    showProgressBar();
                    updateProgressBar(0, 'Preparazione aggiornamento...', 'Plugin selezionati: ' + plugins.length);
                    
                    // Nonce bulk generico (accettato da update_plugin_ajax)
                    var bulkNonce = '<?php echo wp_create_nonce("marrison_bulk_update"); ?>';
                    
                    // Aggiorna i plugin uno per uno
                    var currentIndex = 0;
                    var successCount = 0;
                    var failedPlugins = [];
                    
                    function updateNextPlugin() {
                        if (currentIndex >= plugins.length) {
                            // Tutti gli aggiornamenti completati
                            console.log('Aggiornamenti completati. Successi: ' + successCount);
                            
                            if (successCount > 0) {
                                updateProgressBar(100, 'Aggiornamento completato!', 'Aggiornati ' + successCount + ' di ' + plugins.length + ' plugin');
                                
                                // Aggiorna lo stato dei pulsanti
                                plugins.forEach(function(slug) {
                                    $('button[data-slug="' + slug + '"]').replaceWith('<strong style="color:green;">✓ Aggiornato</strong>');
                                });
                                
                                // Ricarica dopo 2 secondi
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                updateProgressBar(0, 'Errore durante l\'aggiornamento', 'Nessun plugin è stato aggiornato');
                                hideProgressBar();
                            }
                            return;
                        }
                        
                        var slug = plugins[currentIndex];
                        var progressPercent = Math.round((currentIndex / plugins.length) * 100);
                        
                        // Aggiorna lo stato della barra
                        updateProgressBar(progressPercent, 'Aggiornamento in corso...', 'Aggiornamento ' + (currentIndex + 1) + ' di ' + plugins.length + ': ' + slug);
                        
                        console.log('Aggiornamento plugin: ' + slug);
                        
                        // Esegui l'aggiornamento via AJAX
                        $.ajax({
                            url: marrisonUpdater.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'marrison_update_plugin_ajax',
                                slug: slug,
                                nonce: bulkNonce
                            },
                            success: function(response) {
                                console.log('Risposta AJAX per ' + slug + ':', response);
                                if (response.success) {
                                    successCount++;
                                } else {
                                    console.log('Errore per ' + slug + ':', response.data);
                                    failedPlugins.push(slug);
                                }
                                currentIndex++;
                                updateNextPlugin();
                            },
                            error: function(error) {
                                console.log('Errore AJAX per ' + slug + ':', error);
                                failedPlugins.push(slug);
                                currentIndex++;
                                updateNextPlugin();
                            }
                        });
                    }
                    
                    // Avvia l'aggiornamento dei plugin
                    updateNextPlugin();
                });
                
                // Gestione checkbox "Seleziona tutto" (mantenuta dalla versione originale)
                const selectAll1 = document.getElementById('cb-select-all-1');
                const selectAll2 = document.getElementById('cb-select-all-2');
                const checkboxes = document.querySelectorAll('input[name="plugins[]"]');

                function toggleCheckboxes(source) {
                    checkboxes.forEach(function(checkbox) {
                        checkbox.checked = source.checked;
                    });
                    if(source === selectAll1 && selectAll2) selectAll2.checked = source.checked;
                    if(source === selectAll2 && selectAll1) selectAll1.checked = source.checked;
                }

                if (selectAll1) {
                    selectAll1.addEventListener('change', function() {
                        toggleCheckboxes(this);
                    });
                }
                if (selectAll2) {
                    selectAll2.addEventListener('change', function() {
                        toggleCheckboxes(this);
                    });
                }
                
                // Gestione aggiornamento automatico plugin
                $('.marrison-auto-update-btn').on('click', function(e) {
                    e.preventDefault();
                    
                    var $btn = $(this);
                    var nonce = $btn.data('nonce');
                    
                    // Conferma prima di procedere
                    if (!confirm('Sei sicuro di voler aggiornare tutti i plugin con aggiornamenti automatici attivati?')) {
                        return;
                    }
                    
                    // Disabilita il pulsante
                    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Aggiornamento in corso...');
                    
                    // Mostra la barra di caricamento
                    showProgressBar();
                    
                    // Simula progresso durante la preparazione
                    var progress = 0;
                    var progressInterval = setInterval(function() {
                        progress += Math.random() * 8;
                        if (progress > 85) progress = 85;
                        updateProgressBar(progress, 'Ricerca plugin con auto-update...', 'Analizzando i plugin');
                    }, 300);
                    
                    // Esegui l'aggiornamento via AJAX
                    $.ajax({
                        url: marrisonUpdater.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'marrison_auto_update_ajax',
                            nonce: nonce
                        },
                        success: function(response) {
                            clearInterval(progressInterval);
                            
                            if (response.success) {
                                updateProgressBar(100, 'Aggiornamento completato!', response.data.message);
                                
                                // Aggiorna i pulsanti per i plugin aggiornati
                                var updatedSlugs = response.data.results || {};
                                Object.keys(updatedSlugs).forEach(function(slug) {
                                    if (updatedSlugs[slug]) {
                                        $('button[data-slug="' + slug + '"]').replaceWith('<strong style="color:green;">✓ Aggiornato</strong>');
                                    }
                                });
                                
                                // Nascondi il pulsante auto-update se non ci sono più plugin con auto-update disponibili
                                if (response.data.success_count > 0) {
                                    $btn.fadeOut();
                                }
                                
                                // Ricarica la pagina dopo 2 secondi per mostrare lo stato aggiornato
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                updateProgressBar(0, 'Errore durante l\'aggiornamento', response.data || 'Si è verificato un errore');
                                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Aggiorna tutti i plugin con auto-update');
                            }
                            
                            hideProgressBar();
                        },
                        error: function() {
                            clearInterval(progressInterval);
                            updateProgressBar(0, 'Errore di connessione', 'Impossibile contattare il server');
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Aggiorna tutti i plugin con auto-update');
                            hideProgressBar();
                        }
                    });
                });
            });
        </script>
        <?php
    }
}

new Marrison_Custom_Updater;

/**
 * Fix definitivo GitHub updater:
 * - rinomina la cartella del plugin con suffisso versione (es. -1.9)
 * - forza il refresh della cache plugin per mostrare la versione corretta in WP
 */
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) {

    // Agisce solo sui plugin
    if ( empty( $hook_extra['type'] ) || $hook_extra['type'] !== 'plugin' ) {
        return;
    }

    $plugins_dir = WP_PLUGIN_DIR;
    $expected    = $plugins_dir . '/marrison-custom-updater';

    // Cerca directory tipo: marrison-custom-updater-*
    foreach ( glob( $plugins_dir . '/marrison-custom-updater-*', GLOB_ONLYDIR ) as $dir ) {

        // Se la directory corretta esiste già, salta
        if ( is_dir( $expected ) ) {
            continue;
        }

        // Rinomina e pulisce la cache plugin
        if ( rename( $dir, $expected ) ) {
            wp_clean_plugins_cache( true );
        }

        break;
    }
}, 10, 2 );