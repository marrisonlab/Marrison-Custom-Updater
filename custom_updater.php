<?php
/**
 * Plugin Name: Marrison Custom Updater
 * Plugin URI:  https://github.com/marrisonlab/marrison-custom-updater
 * Description: This plugin is used to add a personal repository for updating plugins.
 * Version: 8.0.7
 * Author: Angelo Marra
 * Author URI:  https://marrisonlab.com
 */

class Marrison_Custom_Updater {

    private $updates_url = '';
    private $cache_duration = 6 * HOUR_IN_SECONDS;

    public function __construct() {
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        // Usa site_transient_update_plugins invece di pre_set_site_transient_update_plugins
        // per iniettare gli aggiornamenti in tempo reale quando WP controlla la cache
        add_filter('site_transient_update_plugins', [$this, 'check_for_updates'], 999);
        add_filter('site_transient_update_themes', [$this, 'check_for_theme_updates'], 999);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);

        // Sincronizza la pulizia della cache
        add_action('delete_site_transient_update_plugins', [$this, 'delete_internal_cache']);
        add_action('delete_site_transient_update_themes', [$this, 'delete_internal_cache']);
        add_action('upgrader_process_complete', [$this, 'delete_internal_cache'], 10, 2);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_marrison_update_plugin', [$this, 'update_plugin']);
        add_action('admin_post_marrison_restore_plugin', [$this, 'restore_plugin']);
        add_action('admin_post_marrison_bulk_update', [$this, 'bulk_update']);
        add_action('admin_post_marrison_clear_cache', [$this, 'clear_cache']);
        add_action('admin_post_marrison_save_repo_url', [$this, 'save_repo_url']);
        add_action('admin_post_marrison_force_check_mcu', [$this, 'force_check_mcu']);
        add_action('admin_post_marrison_download_repo_file', [$this, 'download_repo_file']);
        add_action('admin_post_marrison_save_scheduling', [$this, 'save_scheduling_settings']);
        
        // Cron
        add_filter('cron_schedules', [$this, 'add_custom_cron_intervals']);
        add_action('marrison_scheduled_update_event', [$this, 'run_scheduled_updates']);
        
        // Hook per AJAX
        add_action('wp_ajax_marrison_update_plugin_ajax', [$this, 'update_plugin_ajax']);
        add_action('wp_ajax_marrison_bulk_update_ajax', [$this, 'bulk_update_ajax']);
        add_action('wp_ajax_marrison_auto_update_ajax', [$this, 'auto_update_ajax']);
        add_action('wp_ajax_marrison_get_official_updates_ajax', [$this, 'get_official_updates_ajax']);
        add_action('wp_ajax_marrison_update_official_plugin_ajax', [$this, 'update_official_plugin_ajax']);
        add_action('wp_ajax_marrison_restore_plugin_ajax', [$this, 'restore_plugin_ajax']);
        add_action('wp_ajax_marrison_update_private_theme_ajax', [$this, 'update_private_theme_ajax']);
        add_action('wp_ajax_marrison_bulk_update_private_themes_ajax', [$this, 'bulk_update_private_themes_ajax']);
        add_action('wp_ajax_marrison_update_all_themes_ajax', [$this, 'update_all_themes_ajax']);
        add_action('wp_ajax_marrison_update_translations_ajax', [$this, 'update_translations_ajax']);
        add_action('wp_ajax_marrison_get_all_updates_ajax', [$this, 'get_all_updates_ajax']);
        
        // Aggiungi script e stili per la pagina admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Hook per aggiungere link al plugin Marrison Updater nella pagina dei plugin
        add_filter('plugin_action_links', [$this, 'add_marrison_action_links'], 10, 2);
        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
        
        // Hook per aggiungere notifiche al menu
        add_action('admin_menu', [$this, 'add_menu_notification_badge'], 999);
        add_action('admin_head', [$this, 'add_menu_badge_styles']);
        add_action('admin_init', [$this, 'check_for_available_updates']);
        
        // Filtro per abilitare auto-update per questo plugin
        // add_filter('auto_update_plugin', [$this, 'auto_update_specific_plugins'], 10, 2);
        
        // Hook per pulire la cache GitHub quando si forza il controllo aggiornamenti WP
        add_action('delete_site_transient_update_plugins', [$this, 'force_clear_github_cache']);
    }

    /* ===================== SCHEDULING & CRON ===================== */

    public function add_custom_cron_intervals($schedules) {
        $schedules['monthly'] = [
            'interval' => 2592000, // 30 days
            'display'  => 'Una volta al mese'
        ];
        $schedules['biannual'] = [
            'interval' => 15552000, // 180 days (6 months)
            'display'  => 'Ogni 6 mesi'
        ];
        return $schedules;
    }

    public function save_scheduling_settings() {
        check_admin_referer('marrison_save_scheduling');

        $enabled = isset($_POST['marrison_auto_update_enabled']) ? 'yes' : 'no';
        $frequency = sanitize_text_field($_POST['marrison_auto_update_frequency']);
        $time = sanitize_text_field($_POST['marrison_auto_update_time']);
        $email = sanitize_email($_POST['marrison_auto_update_email']);

        update_option('marrison_auto_update_enabled', $enabled);
        update_option('marrison_auto_update_frequency', $frequency);
        update_option('marrison_auto_update_time', $time);
        update_option('marrison_auto_update_email', $email);

        // Clear existing schedule
        wp_clear_scheduled_hook('marrison_scheduled_update_event');

        if ($enabled === 'yes') {
            // Calculate next run time
            $tz = new DateTimeZone('Europe/Rome');
            $now = new DateTime('now', $tz);
            $target_time = DateTime::createFromFormat('H:i', $time, $tz);
            
            if (!$target_time) {
                $target_time = clone $now;
            } else {
                $target_time->setDate($now->format('Y'), $now->format('m'), $now->format('d'));
                if ($target_time <= $now) {
                    $target_time->modify('+1 day');
                }
            }

            wp_schedule_event($target_time->getTimestamp(), $frequency, 'marrison_scheduled_update_event');
        }

        wp_redirect(admin_url('admin.php?page=marrison-updater-settings&tab=scheduling&settings-updated=saved'));
        exit;
    }

    public function run_scheduled_updates() {
        // Prevent timeout
        @ignore_user_abort(true);
        @set_time_limit(0);

        // Fetch all update data using the shared logic
        $data = $this->get_all_updates_data();

        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        include_once ABSPATH . 'wp-admin/includes/theme.php';
        include_once ABSPATH . 'wp-admin/includes/file.php';
        
        // Initialize Filesystem
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        $skin = new Automatic_Upgrader_Skin();
        $updated_plugins = [];
        $updated_themes = [];
        $updated_translations = 0;

        // 1. Private Plugins
        if (!empty($data['plugins_private'])) {
            foreach ($data['plugins_private'] as $u) {
                if ($this->perform_update($u['slug'])) {
                    $updated_plugins[] = $u['name'] . ' (Privato)';
                }
            }
        }
        
        // 2. Official Plugins
        wp_update_plugins();
        $transient_plugins = get_site_transient('update_plugins');
        if (!empty($transient_plugins->response)) {
            $private_updates = $this->get_available_updates();
            $private_slugs = array_map(function($u) { return $u['slug']; }, $private_updates);
            $known_slugs = get_option('marrison_known_private_slugs', []);
            if (is_array($known_slugs)) {
                $private_slugs = array_unique(array_merge($private_slugs, $known_slugs));
            }
            $private_files = [];
            foreach ($private_updates as $u) {
                $found_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                if ($found_file) $private_files[] = $found_file;
            }
            
            $plugin_files = [];
            $plugin_names = [];
            foreach ($transient_plugins->response as $file => $data) {
                if (in_array($file, $private_files)) continue;
                
                $slug = isset($data->slug) ? $data->slug : dirname($file);
                if ($slug === '.' || $slug === '') $slug = basename($file, '.php');
                if (in_array($slug, $private_slugs)) continue;
                
                $plugin_files[] = $file;
                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file);
                $plugin_names[$file] = $plugin_data['Name'] ?? $slug;
            }
            
            if (!empty($plugin_files)) {
                $upgrader = new Plugin_Upgrader($skin);
                $results = $upgrader->bulk_upgrade($plugin_files);
                if (is_array($results)) {
                    foreach ($plugin_files as $file) {
                        $res = isset($results[$file]) ? $results[$file] : false;
                        if ($res && !is_wp_error($res)) {
                            $updated_plugins[] = ($plugin_names[$file] ?? $file) . ' (Ufficiale)';
                        }
                    }
                }
                wp_clean_plugins_cache(true);
                delete_site_transient('update_plugins');
            }
        }

        // 3. Themes
        if ($data['themes_count'] > 0) {
            // Re-fetch transient to be sure
            $current = get_site_transient('update_themes');
            if (!empty($current->response)) {
                $themes = array_keys($current->response);
                $theme_upgrader = new Theme_Upgrader($skin);
                $result = $theme_upgrader->bulk_upgrade($themes);
                
                if (is_array($result)) {
                    foreach ($result as $slug => $res) {
                        if ($res && !is_wp_error($res)) {
                            $theme = wp_get_theme($slug);
                            $updated_themes[] = $theme->get('Name');
                        }
                    }
                }
            }
        }
        
        // 4. Translations
        if ($data['translations_count'] > 0) {
            include_once ABSPATH . 'wp-admin/includes/translation-install.php';
            $translations = wp_get_translation_updates();
            if (!empty($translations)) {
                $lang_upgrader = new Language_Pack_Upgrader($skin);
                $result = $lang_upgrader->bulk_upgrade($translations);
                if ($result && !is_wp_error($result)) {
                    // Estimate count from result array
                    $count = 0;
                    foreach ($result as $r) {
                        if ($r && !is_wp_error($r)) $count++;
                    }
                    $updated_translations = $count;
                }
            }
        }
        
        // 5. Send Email Report
        $email = get_option('marrison_auto_update_email');
        if ($email && (!empty($updated_plugins) || !empty($updated_themes) || $updated_translations > 0)) {
            $subject = '[' . get_bloginfo('name') . '] Report Aggiornamento Automatico';
            $message = "Ciao,\n\nEcco il report degli aggiornamenti automatici eseguiti da Marrison Custom Updater:\n\n";
            
            if (!empty($updated_plugins)) {
                $message .= "PLUGIN AGGIORNATI:\n";
                foreach ($updated_plugins as $p) {
                    $message .= "- $p\n";
                }
                $message .= "\n";
            }
            
            if (!empty($updated_themes)) {
                $message .= "TEMI AGGIORNATI:\n";
                foreach ($updated_themes as $t) {
                    $message .= "- $t\n";
                }
                $message .= "\n";
            }
            
            if ($updated_translations > 0) {
                $message .= "TRADUZIONI AGGIORNATE: $updated_translations pacchetti.\n\n";
            }
            
            $message .= "Saluti,\n" . get_bloginfo('name');
            
            // Set content type to text/plain explicitly
            $headers = array('Content-Type: text/plain; charset=UTF-8');
            
            wp_mail($email, $subject, $message, $headers);
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain('marrison-custom-updater', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function force_clear_github_cache() {
        delete_transient('marrison_updater_github_version');
    }

    public function auto_update_specific_plugins($update, $item) {
        if (isset($item->slug) && $item->slug === 'marrison-custom-updater') {
            return true;
        }
        return $update;
    }

    /* ===================== PERMISSIONS CHECK REMOVED ===================== */

    /* ===================== AUTO UPDATE AJAX HANDLER ===================== */

    public function auto_update_ajax() {
        // Verifica il nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        if (!wp_verify_nonce($nonce, 'marrison_auto_update')) {
            wp_die(__('Security check failed', 'marrison-custom-updater'));
        }

        // Verifica i permessi
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'marrison-custom-updater'));
        }

        // Ottieni tutti i plugin con aggiornamenti automatici attivati
        $auto_update_plugins = (array) get_site_option('auto_update_plugins', []);
        
        // Forza il controllo degli aggiornamenti WordPress
        wp_update_plugins();
        $transient = get_site_transient('update_plugins');
        
        if (empty($transient->response)) {
            wp_send_json_error(__('Nessun aggiornamento disponibile', 'marrison-custom-updater'));
        }

        // Identifica i plugin del repository privato per ESCLUDERLI
        $private_updates = $this->get_available_updates();
        $private_slugs = [];
        foreach ($private_updates as $u) {
            $private_slugs[] = $u['slug'];
        }

        $plugins_to_update = [];
        $slugs_map = []; // Mappa slug => file
        
        foreach ($transient->response as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.' || $slug === '') $slug = basename($file, '.php');

            // ESCLUDI i plugin del repository privato
            if (in_array($slug, $private_slugs)) {
                continue;
            }

            // Includi TUTTI i plugin standard che hanno un aggiornamento, non solo quelli con auto-update
            $plugins_to_update[] = $file;
            $slugs_map[$slug] = $file;
        }
        
        if (empty($plugins_to_update)) {
            wp_send_json_error(__('Nessun plugin "normale" ha aggiornamenti disponibili', 'marrison-custom-updater'));
        }

        // Carica le classi necessarie per l'aggiornamento
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        
        // Usa Automatic_Upgrader_Skin per evitare output HTML
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        
        // Esegui l'aggiornamento
        $results = $upgrader->bulk_upgrade($plugins_to_update);
        
        $success_count = 0;
        $formatted_results = [];

        // Analizza i risultati
        // bulk_upgrade restituisce un array indicizzato dai file path, con valore true/false/WP_Error/array info
        foreach ($slugs_map as $slug => $file) {
            $result = isset($results[$file]) ? $results[$file] : false;
            
            // Verifica se il risultato Ã¨ positivo (non false e non WP_Error)
            // A volte restituisce un array con 'destination_name', etc.
            $is_success = $result && !is_wp_error($result);
            $formatted_results[$slug] = $is_success;
            
            if ($is_success) {
                $success_count++;
            }
        }

        if ($success_count > 0) {
            // Pulisci la cache degli aggiornamenti per evitare che vengano mostrati di nuovo
            wp_clean_plugins_cache( true );
            delete_site_transient('update_plugins');

            wp_send_json_success([
                'message' => sprintf(__('%d plugin aggiornati con successo', 'marrison-custom-updater'), $success_count),
                'results' => $formatted_results,
                'success_count' => $success_count,
                'total_count' => count($plugins_to_update)
            ]);
            
            // Aggiorna il conteggio delle notifiche
            $this->check_for_available_updates();
        } else {
            wp_send_json_error(__('Nessun plugin è stato aggiornato', 'marrison-custom-updater'));
        }
    }

    public function get_official_updates_ajax() {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'marrison_auto_update')) {
            wp_send_json_error(__('Security check failed', 'marrison-custom-updater'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'marrison-custom-updater'));
        }

        // Forza controllo aggiornamenti
        wp_update_plugins();
        $transient = get_site_transient('update_plugins');

        if (empty($transient->response)) {
            wp_send_json_success([]);
        }

        $private_updates = $this->get_available_updates();
        $private_slugs = array_map(function($u) { return $u['slug']; }, $private_updates);

        $plugins_to_update = [];
        
        foreach ($transient->response as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.' || $slug === '') $slug = basename($file, '.php');

            if (in_array($slug, $private_slugs)) continue;

            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file);
            
            $plugins_to_update[] = [
                'file' => $file,
                'slug' => $slug,
                'name' => $plugin_data['Name'] ?? $slug,
                'version' => $data->new_version,
                'package' => $data->package ?? '',
                'url' => $data->url ?? ''
            ];
        }
        
        wp_send_json_success($plugins_to_update);
    }

    public function update_official_plugin_ajax() {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        $file = sanitize_text_field($_POST['file'] ?? '');
        $package = isset($_POST['package']) ? esc_url_raw($_POST['package']) : '';
        $new_version = sanitize_text_field($_POST['new_version'] ?? '');

        if (!wp_verify_nonce($nonce, 'marrison_auto_update')) {
            wp_send_json_error(__('Security check failed', 'marrison-custom-updater'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'marrison-custom-updater'));
        }
        
        if (empty($file)) {
             wp_send_json_error(__('Missing file parameter', 'marrison-custom-updater'));
        }

        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        
        // Ensure update info is present in transient
        $transient = get_site_transient('update_plugins');
        if (!is_object($transient)) {
            $transient = new stdClass();
        }
        if (!isset($transient->response)) {
            $transient->response = [];
        }

        // Inject update info if missing and package is provided
        if (!isset($transient->response[$file]) && !empty($package)) {
            $obj = new stdClass();
            $obj->slug = dirname($file);
            if ($obj->slug == '.' || $obj->slug == '') $obj->slug = basename($file, '.php');
            $obj->plugin = $file;
            $obj->package = $package;
            $obj->new_version = $new_version; 
            $obj->url = ''; 
            
            $transient->response[$file] = $obj;
            set_site_transient('update_plugins', $transient);
        } elseif (!isset($transient->response[$file])) {
            // Fallback if no package provided: force remote check
            wp_update_plugins();
        }
        
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        
        $result = $upgrader->upgrade($file);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } elseif (!$result) {
            wp_send_json_error(__('Update failed', 'marrison-custom-updater'));
        } else {
             wp_send_json_success(__('Plugin updated', 'marrison-custom-updater'));
        }
    }

    /* ===================== MENU NOTIFICATION BADGE ===================== */

    private function update_known_private_slugs($updates) {
        if (!is_array($updates)) return;
        
        $slugs = [];
        foreach ($updates as $u) {
            if (isset($u['slug'])) {
                $slugs[] = $u['slug'];
            }
        }
        
        // Salva solo se abbiamo trovato slug, altrimenti mantieni i vecchi se il fetch fallisce
        if (!empty($slugs)) {
            update_option('marrison_known_private_slugs', $slugs, false); // autoload = false
        }
    }

    public function check_for_available_updates() {
        // Salva il numero di aggiornamenti disponibili in un'opzione per accesso rapido
        $updates = $this->get_available_updates();
        
        // Aggiorna la lista dei plugin conosciuti per il blocco futuro
        $this->update_known_private_slugs($updates);
        
        $plugins = get_plugins();
        $update_count = 0;
        
        foreach ($updates as $u) {
            $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($file && isset($plugins[$file]) && version_compare($plugins[$file]['Version'], $u['version'], '<')) {
                $update_count++;
            }
        }

        // Aggiungi conteggio temi
        $theme_updates = $this->get_available_theme_updates();
        $installed_themes = wp_get_themes(); // Cache temi installati
        
        foreach ($theme_updates as $u) {
            $slug = $u['slug'];
            $theme = wp_get_theme($slug);
            
            // Logica di fallback per trovare il tema se lo slug non corrisponde
            if (!$theme->exists()) {
                foreach ($installed_themes as $t_slug => $t_obj) {
                    if (strcasecmp($t_obj->get('Name'), $u['name']) === 0 || $t_obj->get('TextDomain') === $slug) {
                        $theme = $t_obj;
                        $slug = $t_slug;
                        break;
                    }
                }
            }

            if ($theme->exists() && version_compare($theme->get('Version'), $u['version'], '<')) {
                $update_count++;
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
        $icon_url = plugin_dir_url(__FILE__) . 'assets/icon.svg';
        ?>
        <style>
        /* Custom Icon Styles */
        #toplevel_page_marrison-updater .wp-menu-image:before {
            display: none;
        }
        #toplevel_page_marrison-updater .wp-menu-image {
            background-color: currentColor;
            -webkit-mask-image: url('<?php echo esc_url($icon_url); ?>');
            mask-image: url('<?php echo esc_url($icon_url); ?>');
            -webkit-mask-repeat: no-repeat;
            mask-repeat: no-repeat;
            -webkit-mask-position: center;
            mask-position: center;
            -webkit-mask-size: 20px;
            mask-size: 20px;
        }

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

        if (empty($repo_url)) return [];

        // Prova a recuperare la cache
        $cached = get_transient('marrison_available_updates_v2');
        
        // Se la cache esiste, controlla se è pulita
        if ($cached !== false && is_array($cached)) {
            $is_clean = true;
            foreach ($cached as $u) {
                // Se troviamo elementi corrotti nella cache, invalida tutto
                if (isset($u['name']) && (strpos($u['name'], '/i\'') !== false || strpos($u['name'], '$') !== false)) {
                    $is_clean = false;
                    break;
                }
            }
            if ($is_clean) return $cached;
        }

        $response = wp_remote_get($repo_url . 'index.php', ['timeout' => 15]);
        if (is_wp_error($response)) return [];

        $updates = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($updates)) return [];

        // Filtra e pulisci i risultati
        $cleaned_updates = [];
        foreach ($updates as $u) {
            if (!isset($u['slug'])) continue;
            
            // Pulisci i dati
            $u['slug'] = trim($u['slug']);
            if (isset($u['version'])) $u['version'] = trim($u['version']);
            if (isset($u['name'])) $u['name'] = trim($u['name']);
            
            // Rimuove elementi con variabili PHP o regex nel nome/versione (protezione)
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) continue;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) continue;
            
            $cleaned_updates[] = $u;
        }
        $updates = $cleaned_updates;

        // Re-index array (già fatto sopra)

        set_transient('marrison_available_updates_v2', $updates, $this->cache_duration);
        return $updates;
    }

    /* ===================== WP UPDATE HOOK ===================== */

    public function check_for_updates($transient) {
        if (!is_object($transient)) $transient = new stdClass();
        
        // Assicurati che le proprietà esistano
        if (!isset($transient->response)) $transient->response = [];
        if (!isset($transient->no_update)) $transient->no_update = [];
        if (!isset($transient->checked)) $transient->checked = [];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();

        // 1. Usa la lista persistente di slug privati per BLOCCARE gli aggiornamenti pubblici
        // Questo protegge anche nel caso in cui get_available_updates() fallisca (es. server down)
        $known_slugs = get_option('marrison_known_private_slugs', []);

        // Espandi known_slugs identificando i plugin installati anche tramite Nome
        // Questo è fondamentale se la cartella installata ha un nome diverso dallo slug del repo privato
        $private_updates_list = $this->get_available_updates();
        if (!empty($private_updates_list)) {
            foreach ($private_updates_list as $u) {
                $f = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                if ($f) {
                    $known_slugs[] = dirname($f);
                    $known_slugs[] = basename($f, '.php');
                }
            }
            $known_slugs = array_unique($known_slugs);
        }
        
        if (is_array($known_slugs) && !empty($known_slugs)) {
            // Pulizia aggressiva basata sullo SLUG, non solo sul file path
            // Questo gestisce casi in cui WP rileva il plugin in un path diverso (es. cartella standard vs rinominata)
            
            // Pulisci response
            if (!empty($transient->response)) {
                foreach ($transient->response as $file => $data) {
                    $check_slugs = [];
                    $check_slugs[] = dirname($file);
                    $check_slugs[] = basename($file, '.php');
                    if (isset($data->slug)) $check_slugs[] = $data->slug;
                    
                    // Rimuovi duplicati e valori vuoti/punto
                    $check_slugs = array_filter(array_unique($check_slugs), function($s) {
                        return $s !== '.' && $s !== '';
                    });

                    foreach ($check_slugs as $s) {
                        if (in_array($s, $known_slugs)) {
                            unset($transient->response[$file]);
                            break;
                        }
                    }
                }
            }

            // Pulisci no_update
            if (!empty($transient->no_update)) {
                foreach ($transient->no_update as $file => $data) {
                    $check_slugs = [];
                    $check_slugs[] = dirname($file);
                    $check_slugs[] = basename($file, '.php');
                    if (isset($data->slug)) $check_slugs[] = $data->slug;
                    
                    $check_slugs = array_filter(array_unique($check_slugs), function($s) {
                        return $s !== '.' && $s !== '';
                    });

                    foreach ($check_slugs as $s) {
                        if (in_array($s, $known_slugs)) {
                            unset($transient->no_update[$file]);
                            break;
                        }
                    }
                }
            }
        }

        // 2. Inietta i NUOVI aggiornamenti dal repository privato (se disponibili)
        foreach ($this->get_available_updates() as $update) {
            $file = $this->find_plugin_file($update['slug'], $update['name'] ?? '');
            if (!$file || !isset($plugins[$file])) continue;

            // Rimuovi di nuovo per sicurezza (ridondante ma sicuro)
            if (isset($transient->response[$file])) unset($transient->response[$file]);
            if (isset($transient->no_update[$file])) unset($transient->no_update[$file]);

            $installed = $plugins[$file]['Version'];
            $remote    = $update['version'];

            if (version_compare($installed, $remote, '<')) {
                $transient->response[$file] = (object)[
                    'slug'        => $update['slug'],
                    'new_version' => $remote,
                    'package'     => $update['download_url'],
                    'url'         => '', // Rimuovi URL per evitare link a wp.org
                ];
            } else {
                 $transient->no_update[$file] = (object)[
                    'slug'        => $update['slug'],
                    'new_version' => $remote,
                    'package'     => $update['download_url'],
                    'url'         => '',
                    'plugin'      => $file,
                ];
            }

            $transient->checked[$file] = $installed;
        }

        // Controlla anche il plugin stesso da GitHub
        $this->check_self_update($transient);

        return $transient;
    }

    /* ===================== THEME UPDATES ===================== */

    private function get_available_theme_updates() {
        $repo_url = get_option('marrison_themes_repo_url');
        
        if (empty($repo_url)) return [];
        $repo_url = trailingslashit($repo_url);

        // Prova a recuperare la cache
        $cached = get_transient('marrison_available_theme_updates');
        
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get($repo_url . 'index.php', ['timeout' => 15]);
        if (is_wp_error($response)) return [];

        $updates = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($updates)) return [];

        // Filtra e pulisci i risultati
        $cleaned_updates = [];
        foreach ($updates as $u) {
            if (!isset($u['slug'])) continue;
            
            // Pulisci i dati
            $u['slug'] = trim($u['slug']);
            if (isset($u['version'])) $u['version'] = trim($u['version']);
            if (isset($u['name'])) $u['name'] = trim($u['name']);
            
            // Rimuove elementi con variabili PHP o regex
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) continue;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) continue;
            
            $cleaned_updates[] = $u;
        }
        $updates = $cleaned_updates;

        set_transient('marrison_available_theme_updates', $updates, $this->cache_duration);
        return $updates;
    }

    public function check_for_theme_updates($transient) {
        if (!is_object($transient)) $transient = new stdClass();
        
        if (!isset($transient->response)) $transient->response = [];
        if (!isset($transient->no_update)) $transient->no_update = [];
        if (!isset($transient->checked)) $transient->checked = [];

        // Recupera tutti i temi installati per la ricerca fuzzy
        $installed_themes = wp_get_themes();

        // Inietta i NUOVI aggiornamenti dal repository privato
        foreach ($this->get_available_theme_updates() as $update) {
            $slug = $update['slug'];
            
            // Cerca il tema installato
            $theme = wp_get_theme($slug);
            
            // Se non trova corrispondenza esatta, cerca per nome tema
            if (!$theme->exists()) {
                foreach ($installed_themes as $t_slug => $t_obj) {
                    // Cerca per nome (case insensitive)
                    if (strcasecmp($t_obj->get('Name'), $update['name']) === 0) {
                        $theme = $t_obj;
                        $slug = $t_slug; // Aggiorna lo slug con quello reale della cartella
                        break;
                    }
                    
                    // Cerca per Text Domain
                    if ($t_obj->get('TextDomain') === $update['slug']) {
                        $theme = $t_obj;
                        $slug = $t_slug;
                        break;
                    }
                }
            }
            
            if (!$theme->exists()) continue;

            $installed = $theme->get('Version');
            $remote    = $update['version'];

            // Rimuovi eventuali aggiornamenti ufficiali per evitare conflitti
            if (isset($transient->response[$slug])) unset($transient->response[$slug]);
            if (isset($transient->no_update[$slug])) unset($transient->no_update[$slug]);

            if (version_compare($installed, $remote, '<')) {
                $transient->response[$slug] = [
                    'theme'       => $slug,
                    'new_version' => $remote,
                    'package'     => $update['download_url'],
                    'url'         => '', 
                ];
            } else {
                 $transient->no_update[$slug] = [
                    'theme'       => $slug,
                    'new_version' => $remote,
                    'package'     => $update['download_url'],
                    'url'         => '',
                ];
            }

            $transient->checked[$slug] = $installed;
        }

        return $transient;
    }

    private function check_self_update($transient) {
        $plugin_file = plugin_basename(__FILE__);
        $plugins = get_plugins();

        if (!isset($plugins[$plugin_file])) return;

        $installed = $plugins[$plugin_file]['Version'];
        $remote = $this->get_github_version();

        $item = (object)[
            'id'          => 'marrison-custom-updater',
            'slug'        => 'marrison-custom-updater',
            'plugin'      => $plugin_file,
            'new_version' => $remote,
            'url'         => 'https://github.com/marrisonlab/Marrison-Custom-Updater',
            'package'     => 'https://github.com/marrisonlab/Marrison-Custom-Updater/archive/refs/tags/v' . $remote . '.zip',
            'tested'      => '6.9',
            'requires_php' => '7.4',
            'icons'       => [],
            'banners'     => [],
            'banners_rtl' => [],
            'compatibility' => new stdClass(),
        ];

        if (version_compare($installed, $remote, '<')) {
            $transient->response[$plugin_file] = $item;
        } else {
            // Importante: popolare no_update permette a WP di mostrare i controlli per auto-update
            $transient->no_update[$plugin_file] = $item;
        }

        $transient->checked[$plugin_file] = $installed;
    }

    private function get_github_version() {
        $cached = get_transient('marrison_updater_github_version');
        if ($cached !== false) return $cached;

        $response = wp_remote_get('https://api.github.com/repos/marrisonlab/marrison-custom-updater/releases/latest', [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/MarrisonCustomUpdater'
            ]
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['tag_name'])) return false;

        $version = str_replace('v', '', $body['tag_name']);
        set_transient('marrison_updater_github_version', $version, 6 * HOUR_IN_SECONDS);

        return $version;
    }

    private function find_plugin_file($slug, $name = '') {
        $slug = trim($slug);
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        
        // 1. Cerca corrispondenza esatta della cartella (o nome file per plugin singoli)
        foreach ($plugins as $file => $data) {
            $dir = dirname($file);
            if ($dir === '.' || $dir === '') $dir = basename($file, '.php');
            if ($dir === $slug) return $file;
        }

        // 2. Tentativo secondario: Cerca corrispondenza esatta del file .php principale
        // Utile se la cartella ha un nome diverso ma il file del plugin corrisponde allo slug
        foreach ($plugins as $file => $data) {
             if (basename($file, '.php') === $slug) return $file;
        }

        // 3. Tentativo terziario: Cerca per Nome Plugin
        if (!empty($name)) {
            foreach ($plugins as $file => $data) {
                // Confronto case-insensitive del nome
                $plugin_name = html_entity_decode($data['Name']);
                if (strcasecmp($plugin_name, $name) === 0) return $file;
            }
        }

        return null;
    }

    public function plugin_info($false, $action, $args) {
        if ($action !== 'plugin_information') return $false;
        
        // Controlla se è il nostro plugin
        if ($args->slug !== 'marrison-custom-updater') return $false;

        // Leggi le informazioni dal file locale invece che da GitHub
        $readme_file = plugin_dir_path(__FILE__) . 'readme.txt';
        if (!file_exists($readme_file)) return $false;
        
        $readme = file_get_contents($readme_file);
        if (empty($readme)) return $false;

        // Parsa il readme.txt
        return $this->parse_readme($readme);
    }

    private function parse_readme($readme) {
        $info = new stdClass();
        
        // Estrai la descrizione
        if (preg_match('/== Description ==\s*(.*?)\s*== /s', $readme, $match)) {
            $description = trim($match[1]);
            // Converti semplice markdown in HTML
            $description = $this->markdown_to_html($description);
            $info->description = $description;
        } else {
            $info->description = '';
        }

        // Estrai il changelog
        if (preg_match('/== Changelog ==\s*(.*?)$/s', $readme, $match)) {
            $changelog = trim($match[1]);
            // Converti semplice markdown in HTML
            $changelog = $this->markdown_to_html($changelog);
            $info->changelog = $changelog;
        } else {
            $info->changelog = '';
        }

        // Estrai metadata dal readme
        $version = '1.0.0';
        if (preg_match('/Stable tag:\s*([0-9\.]+)/i', $readme, $match)) {
            $version = trim($match[1]);
        }

        $tested = '6.0';
        if (preg_match('/Tested up to:\s*([0-9\.]+)/i', $readme, $match)) {
            $tested = trim($match[1]);
        }

        $requires = '5.0';
        if (preg_match('/Requires at least:\s*([0-9\.]+)/i', $readme, $match)) {
            $requires = trim($match[1]);
        }

        $requires_php = '7.4';
        if (preg_match('/Requires PHP:\s*([0-9\.]+)/i', $readme, $match)) {
            $requires_php = trim($match[1]);
        }

        // Dati base
        $info->name = 'Marrison Custom Updater';
        $info->slug = 'marrison-custom-updater';
        $info->version = $version;
        $info->author = 'Angelo Marra';
        $info->author_profile = 'https://marrisonlab.com';
        $info->plugin_url = 'https://github.com/marrisonlab/marrison-custom-updater';
        $info->download_url = 'https://github.com/marrisonlab/marrison-custom-updater/archive/refs/tags/v' . $version . '.zip';
        $info->requires_php = $requires_php;
        $info->requires = $requires;
        $info->tested = $tested;
        $info->last_updated = current_time('mysql');
        $info->homepage = 'https://github.com/marrisonlab/marrison-custom-updater';
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

    private function markdown_to_html($text) {
        // Converti header changelog (= 1.0.0 =)
        $text = preg_replace('/^=\s*(.*?)\s*=\s*$/m', '<h4>$1</h4>', $text);
        
        // Converti grassetto (**text**)
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
        
        // Converti liste puntate (* item)
        // Aggiungi newline prima delle liste per sicurezza
        $text = preg_replace('/^\*\s+(.*?)$/m', '<li>$1</li>', $text);
        
        // Avvolgi liste (questo è un po\' grezzo ma funziona per readme standard)
        // Cerchiamo gruppi di <li> e li avvolgiamo in <ul>
        $text = preg_replace('/(<li>.*?<\/li>(\s*<li>.*?<\/li>)*)/s', '<ul>$1</ul>', $text);
        
        // Converti paragrafi (doppio newline)
        $text = wpautop($text);
        
        return $text;
    }

    /* ===================== PLUGIN ACTION LINKS ===================== */

    public function add_marrison_action_links($actions, $plugin_file) {
        // Controlla se Ã¨ il file del plugin Marrison Custom Updater
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

    public function add_plugin_row_meta($links, $file) {
        if (strpos($file, 'custom_updater.php') !== false || strpos($file, 'marrison-custom-updater') !== false) {
            $row_meta = [
                'docs' => '<a href="https://github.com/marrisonlab/marrison-custom-updater" target="_blank" aria-label="' . esc_attr__('Visita il sito del plugin', 'marrison-custom-updater') . '">' . esc_html__('Visita il sito del plugin', 'marrison-custom-updater') . '</a>',
            ];
            return array_merge($links, $row_meta);
        }
        return $links;
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

            // Trova versione corrente per il backup
            $current_version = '';
            $plugin_file = $this->find_plugin_file($slug, $update['name'] ?? '');
            if ($plugin_file) {
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $all_plugins = get_plugins();
                if (isset($all_plugins[$plugin_file])) {
                    $current_version = $all_plugins[$plugin_file]['Version'];
                }
            }

            // Crea backup prima di procedere
            $this->create_backup($slug, $current_version, 'plugin', $plugin_file);

            $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-' . $slug;
            wp_mkdir_p($upgrade_dir);

            unzip_file($zip, $upgrade_dir);
            unlink($zip);

            $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
            if (empty($dirs)) return false;

            $source = trailingslashit($dirs[0]);
            
            // Determina la cartella di destinazione corretta mantenendo quella attuale
            $dest_folder = $slug;
            if ($plugin_file) {
                $installed_dir = dirname($plugin_file);
                if ($installed_dir !== '.' && $installed_dir !== '') {
                    $dest_folder = $installed_dir;
                }
            }
            
            $dest = trailingslashit(WP_PLUGIN_DIR . '/' . $dest_folder);

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

    /* ===================== BACKUP & ROLLBACK ===================== */

    private function get_backup_dir() {
        $dir = WP_CONTENT_DIR . '/marrison-backups';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/index.php', '<?php // Silence is golden');
            file_put_contents($dir . '/.htaccess', 'deny from all');
        }
        return $dir;
    }

    private function create_backup($slug, $version = '', $type = 'plugin', $known_file = '') {
        $source = '';
        if ($type === 'plugin') {
            $plugin_file = $known_file ? $known_file : $this->find_plugin_file($slug);
            if (!$plugin_file) return false;
            
            // Per i plugin, cerchiamo di capire se è una cartella o un file singolo
            // Se find_plugin_file restituisce 'slug/file.php', la cartella è 'slug'
            // Se restituisce 'file.php', è un file singolo.
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
                // Plugin a file singolo - per ora non supportiamo il backup completo (richiederebbe zip del singolo file)
                return false; 
            }
            $source = WP_PLUGIN_DIR . '/' . $plugin_dir;
        } else {
            $theme = wp_get_theme($slug);
            if (!$theme->exists()) return false;
            $source = get_theme_root() . '/' . $slug;
        }

        if (!is_dir($source)) return false;

        $backup_dir = $this->get_backup_dir();
        
        // Pulizia vecchi backup
        // Pattern: type-slug-*-backup.zip
        $pattern = $backup_dir . '/' . $type . '-' . $slug . '-*-backup.zip';
        foreach (glob($pattern) as $f) @unlink($f);
        
        // Retrocompatibilità pulizia (solo per plugin)
        if ($type === 'plugin') {
             foreach (glob($backup_dir . '/' . $slug . '-*-backup.zip') as $f) @unlink($f);
        }
        
        $date = date('Ymd');
        $time = date('His');
        $ver_str = $version ? $version : 'na';
        
        // Formato: type-slug-v{ver}-{date}-{time}-backup.zip
        $filename = sprintf('%s-%s-v%s-%s-%s-backup.zip', $type, $slug, $ver_str, $date, $time);
        $zip_file = $backup_dir . '/' . $filename;
        
        if (file_exists($zip_file)) @unlink($zip_file);

        if (!class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }
        
        $archive = new PclZip($zip_file);
        
        // Rimuove il percorso assoluto per mantenere struttura relativa
        $remove_path = ($type === 'theme') ? get_theme_root() : WP_PLUGIN_DIR;
        $v_list = $archive->create($source, PCLZIP_OPT_REMOVE_PATH, $remove_path);
        
        return ($v_list != 0);
    }

    public function restore_plugin() {
        // Aumenta limiti esecuzione per evitare crash durante operazioni file
        @ignore_user_abort(true);
        @set_time_limit(0);

        $filename = sanitize_file_name($_GET['file'] ?? '');
        $slug_param = sanitize_text_field($_GET['slug'] ?? '');
        
        if (!empty($filename)) {
             check_admin_referer('marrison_restore_' . $filename);
        } elseif (!empty($slug_param)) {
             check_admin_referer('marrison_restore_' . $slug_param);
             // Fallback per vecchi link: cerca backup standard
             $filename = $slug_param . '-backup.zip';
             
             // Se non esiste, cerca se c'Ã¨ un backup versionato
             $backup_dir = $this->get_backup_dir();
             if (!file_exists($backup_dir . '/' . $filename)) {
                 $files = glob($backup_dir . '/' . $slug_param . '-*-backup.zip');
                 if (!empty($files)) {
                     $filename = basename($files[0]);
                 }
             }
        } else {
             wp_die('Missing parameters');
        }

        if (!current_user_can('install_plugins')) wp_die('Insufficient permissions');

        $result = $this->perform_restore($filename);

        if (is_wp_error($result)) {
            wp_die('Error restoring backup: ' . $result->get_error_message());
        }
        
        $redirect_to = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-updater-backups&restored=' . $result);
        wp_redirect($redirect_to);
        exit;
    }

    public function restore_plugin_ajax() {
        @ignore_user_abort(true);
        @set_time_limit(0);

        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $nonce = $_POST['nonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'marrison_restore_' . $filename)) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('install_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (empty($filename)) {
            wp_send_json_error('Missing filename');
        }

        $result = $this->perform_restore($filename);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['slug' => $result]);
    }

    private function perform_restore($filename) {
        try {
            $backup_dir = $this->get_backup_dir();
            $zip_file = $backup_dir . '/' . $filename;

            if (!file_exists($zip_file)) {
                return new WP_Error('not_found', 'Backup not found');
            }
            
            // Detect type and slug from filename
            $type = 'plugin';
            $slug = '';
            
            // New format: type-slug-vVersion-date-time-backup.zip
            if (strpos($filename, 'theme-') === 0) {
                $type = 'theme';
                $remaining = substr($filename, 6); // Remove 'theme-'
                if (preg_match('/^(.*?)-v.*-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                } elseif (preg_match('/^(.*?)-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                }
            } elseif (strpos($filename, 'plugin-') === 0) {
                $type = 'plugin';
                $remaining = substr($filename, 7); // Remove 'plugin-'
                if (preg_match('/^(.*?)-v.*-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                } elseif (preg_match('/^(.*?)-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                }
            } else {
                // Legacy format
                if (preg_match('/^(.*)-v(.*)-backup\.zip$/', $filename, $matches)) {
                    $slug = $matches[1];
                } else {
                    $slug = str_replace('-backup.zip', '', $filename);
                }
            }
            
            if (empty($slug) || strpos($slug, '.') !== false || strpos($slug, '/') !== false || strpos($slug, '\\') !== false) {
                 return new WP_Error('invalid_slug', 'Invalid slug derived from filename');
            }

            global $wp_filesystem;
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            
            // Tenta inizializzazione Filesystem
            if ( ! WP_Filesystem() ) {
                return new WP_Error('fs_error', 'Filesystem error - Could not initialize');
            }

            if (!$wp_filesystem) {
                return new WP_Error('fs_error', 'Filesystem error - Object is null');
            }

            // Determine destination
            $dest_root = ($type === 'theme') ? get_theme_root() : WP_PLUGIN_DIR;
            $dest = $dest_root . '/' . $slug;
            
            // Protezione extra
            if (realpath($dest) === realpath($dest_root)) {
                 return new WP_Error('invalid_dest', 'Destination invalid');
            }

            if ($wp_filesystem->is_dir($dest)) {
                // Tenta cancellazione diretta
                $deleted = $wp_filesystem->delete($dest, true);
                
                // Se fallisce (es. Windows file lock), prova strategia move-then-delete
                if (!$deleted) {
                     $trash_dir = $dest_root . '/.' . $slug . '_trash_' . time();
                     if ($wp_filesystem->move($dest, $trash_dir)) {
                         // Se spostato con successo, prova a cancellare il trash (se fallisce non importa, Ã¨ nascosto)
                         $wp_filesystem->delete($trash_dir, true);
                     } else {
                         // Se non riesco nemmeno a spostare, potrebbe fallire unzip se non sovrascrive tutto
                         // Ma proviamo comunque a continuare
                     }
                }
            }

            // Estrai backup
            $result = unzip_file($zip_file, $dest_root);

            if (is_wp_error($result)) {
                return $result;
            }
            
            // Pulisce cache
            if ($type === 'theme') {
                delete_site_transient('update_themes');
                wp_clean_themes_cache(true);
            } else {
                delete_site_transient('update_plugins');
                wp_clean_plugins_cache(true);
            }

            // Pulisce OPcache se attiva per evitare di servire file vecchi/misti
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return $slug;
        } catch (Throwable $e) {
            return new WP_Error('exception', 'Critical error during restore: ' . $e->getMessage());
        } catch (Exception $e) {
            return new WP_Error('exception', 'Exception during restore: ' . $e->getMessage());
        }
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
        
        // Update Plugins
        foreach ($_POST['plugins'] ?? [] as $slug) {
            if ($this->perform_update(sanitize_text_field($slug))) {
                $updated[] = $slug;
            }
        }

        // Update Themes
        if (!empty($_POST['themes'])) {
            $theme_updates = $this->get_available_theme_updates();
            foreach ($_POST['themes'] as $slug) {
                $slug = sanitize_text_field($slug);
                $download_url = '';
                foreach ($theme_updates as $u) {
                    if ($u['slug'] === $slug) {
                        $download_url = $u['download_url'];
                        break;
                    }
                }
                
                if ($download_url && $this->perform_theme_update($slug, $download_url)) {
                    $updated[] = $slug;
                }
            }
        }

        $query = http_build_query(['bulk_updated' => $updated]);
        wp_redirect(admin_url('admin.php?page=marrison-updater&' . $query));
        exit;
    }

    public function bulk_install() {
        check_admin_referer('marrison_bulk_install');

        $installed = [];
        foreach ($_POST['plugins'] ?? [] as $slug) {
            // perform_update gestisce anche l'installazione (scarica e copia)
            if ($this->perform_update(sanitize_text_field($slug))) {
                $installed[] = $slug;
            }
        }

        $query = http_build_query(['installed' => $installed]);
        wp_redirect(admin_url('admin.php?page=marrison-updater-installer&' . $query));
        exit;
    }

    public function delete_internal_cache() {
        delete_transient('marrison_available_updates');
        delete_transient('marrison_available_updates_v2');
        delete_transient('marrison_available_theme_updates');
    }

    public function clear_cache() {
        check_admin_referer('marrison_clear_cache');
        
        $this->delete_internal_cache();
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        wp_clean_plugins_cache(true);
        wp_clean_themes_cache(true);

        $redirect = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-updater-settings&cache_cleared=1');
        wp_redirect($redirect);
        exit;
    }

    public function force_check_mcu() {
        check_admin_referer('marrison_force_check_mcu');
        
        // Pulisce cache interna
        $this->delete_internal_cache();

        // Pulisce cache specifica GitHub
        delete_transient('marrison_updater_github_version');
        
        // Forza controllo aggiornamenti WP (Plugin)
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);
        wp_update_plugins();

        // Forza controllo aggiornamenti WP (Temi)
        delete_site_transient('update_themes');
        wp_clean_themes_cache(true);
        wp_update_themes();
        
        $redirect = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-updater&mcu_checked=1');
        wp_redirect($redirect);
        exit;
    }

    public function save_repo_url() {
        check_admin_referer('marrison_save_repo_url');

        if (isset($_POST['marrison_remove_repo_url'])) {
            delete_option('marrison_repo_url');
            delete_option('marrison_themes_repo_url'); // Rimuove anche questo per pulizia, o gestire separatamente?
            // Meglio gestire rimozioni separate se ci sono bottoni separati, ma qui sembra un form unico.
            // Se l'utente vuole rimuovere solo uno, dovrebbe svuotare il campo.
            // Il bottone "Rimuovi URL" attuale sembra inteso per resettare tutto o il principale.
            // Manteniamo il comportamento per il principale, ma aggiungiamo logica per i temi se necessario.
            // Anzi, miglioriamo: salviamo entrambi se presenti.
            
            // Se il bottone premuto è quello generico di rimozione (che era per il plugin repo)
            delete_option('marrison_repo_url');
            $redirect_url = admin_url('admin.php?page=marrison-updater-settings&settings-updated=removed');
        } else {
            // Salvataggio Plugin Repo
            if (isset($_POST['marrison_repo_url'])) {
                $url_input = $_POST['marrison_repo_url'];
                // Aggiorna solo se l'input non è oscurato (preserviamo l'esistente se l'utente non lo modifica)
                if ($url_input !== '********************') {
                    $url = sanitize_url($url_input);
                    update_option('marrison_repo_url', $url);
                }
            }

            // Salvataggio Themes Repo
            if (isset($_POST['marrison_themes_repo_url'])) {
                $theme_url_input = $_POST['marrison_themes_repo_url'];
                // Aggiorna solo se l'input non è oscurato
                if ($theme_url_input !== '********************') {
                    $theme_url = sanitize_url($theme_url_input);
                    update_option('marrison_themes_repo_url', $theme_url);
                }
            }

            $redirect_url = admin_url('admin.php?page=marrison-updater-settings&settings-updated=saved');
        }

        // Pulisce la cache dopo aver modificato l'URL
        delete_transient('marrison_available_updates');
        delete_site_transient('update_plugins');
        delete_transient('marrison_available_theme_updates');
        delete_site_transient('update_themes');

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
        
        // Controlla se Ã¨ il plugin stesso (Marrison Custom Updater)
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

    /* ===================== THEME AJAX HANDLER ===================== */

    public function update_private_theme_ajax() {
        // Verifica il nonce
        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        $nonce_valid = wp_verify_nonce($nonce, 'marrison_update_theme_' . $slug) || 
                       wp_verify_nonce($nonce, 'marrison_bulk_update');
        
        if (!$nonce_valid) {
            wp_send_json_error('Security check failed');
        }

        // Verifica i permessi
        if (!current_user_can('update_themes')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Carica classi
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/theme.php';

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        
        // Trova l'URL di download
        $download_url = '';
        $updates = $this->get_available_theme_updates();
        $theme_obj = wp_get_theme($slug);
        
        foreach ($updates as $u) {
            // Check 1: Corrispondenza slug esatta
            if ($u['slug'] === $slug) {
                $download_url = $u['download_url'];
                break;
            }
            
            // Check 2: Se il tema è installato e lo slug non corrisponde, cerca per Nome
            if ($theme_obj->exists() && strcasecmp($theme_obj->get('Name'), $u['name']) === 0) {
                $download_url = $u['download_url'];
                break;
            }
            
            // Check 3: Cerca per TextDomain
            if ($theme_obj->exists() && $theme_obj->get('TextDomain') === $u['slug']) {
                $download_url = $u['download_url'];
                break;
            }
        }
        
        if (empty($download_url)) {
            wp_send_json_error('URL download non trovato per lo slug: ' . $slug);
        }

        // Esegui l'aggiornamento
        if ($this->perform_theme_update($slug, $download_url)) {
            wp_send_json_success('Tema aggiornato con successo');
            $this->check_for_available_updates();
        } else {
            wp_send_json_error('Errore durante l\'aggiornamento del tema');
        }
    }

    public function bulk_update_private_themes_ajax() {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        $themes = isset($_POST['themes']) ? array_map('sanitize_text_field', $_POST['themes']) : [];
        
        if (!wp_verify_nonce($nonce, 'marrison_bulk_update')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('update_themes')) {
            wp_die('Insufficient permissions');
        }

        if (empty($themes)) {
            wp_send_json_error('Nessun tema selezionato');
        }

        $results = [];
        $success_count = 0;
        
        foreach ($themes as $slug) {
            $download_url = '';
            foreach ($this->get_available_theme_updates() as $u) {
                if ($u['slug'] === $slug) {
                    $download_url = $u['download_url'];
                    break;
                }
            }
            
            if ($download_url && $this->perform_theme_update($slug, $download_url)) {
                $results[$slug] = true;
                $success_count++;
            } else {
                $results[$slug] = false;
            }
        }

        if ($success_count > 0) {
            wp_send_json_success([
                'message' => sprintf('%d temi aggiornati con successo', $success_count),
                'results' => $results,
                'success_count' => $success_count,
                'total_count' => count($themes)
            ]);
            $this->check_for_available_updates();
        } else {
            wp_send_json_error('Nessun tema è stato aggiornato');
        }
    }

    private function perform_theme_update($slug, $download_url) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        if (!$wp_filesystem) return false;

        // Backup prima dell'aggiornamento
        $theme = wp_get_theme($slug);
        $current_version = $theme->exists() ? $theme->get('Version') : '';
        $this->create_backup($slug, $current_version, 'theme');

        $zip = download_url($download_url);
        if (is_wp_error($zip)) return false;

        $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-theme-' . $slug;
        wp_mkdir_p($upgrade_dir);

        unzip_file($zip, $upgrade_dir);
        unlink($zip);

        $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) return false;

        $source = trailingslashit($dirs[0]);
        $dest = trailingslashit(get_theme_root() . '/' . $slug);

        if ($wp_filesystem->is_dir($dest)) {
            $wp_filesystem->delete($dest, true);
        }

        copy_dir($source, $dest);
        $wp_filesystem->delete($upgrade_dir, true);

        delete_site_transient('update_themes');
        wp_clean_themes_cache(true);

        return true;
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
            
            // Controlla se Ã¨ il plugin stesso (Marrison Custom Updater)
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
            wp_send_json_error('Nessun plugin Ã¨ stato aggiornato');
        }
    }

    /* ===================== ADMIN UI ===================== */

    public function enqueue_admin_scripts($hook) {
        // Load on all plugin subpages
        if (strpos($hook, 'marrison-updater') === false) {
            return;
        }
        
        // Load Custom Styles and Scripts
        wp_enqueue_style('mcu-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '1.0.2');
        wp_enqueue_script('mcu-admin-script', plugin_dir_url(__FILE__) . 'assets/js/admin-script.js', ['jquery'], '1.0.2', true);
        
        wp_localize_script('mcu-admin-script', 'marrisonUpdater', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('marrison_ajax_nonce')
        ]);
    }

    public function add_admin_menu() {
        // Aggiungi menu principale con icona carina
        add_menu_page(
            'AM Updater',
            'AM Updater',
            'manage_options',
            'marrison-updater',
            [$this,'admin_page'],
            'dashicons-update', // Icona placeholder (sovrascritta via CSS)
            30 // Posizione nel menu (dopo Dashboard e Media)
        );

        // Sottomenu Aggiornamenti (default)
        add_submenu_page(
            'marrison-updater',
            'Aggiornamenti',
            'Aggiornamenti',
            'manage_options',
            'marrison-updater',
            [$this, 'admin_page']
        );

        // Sottomenu Backup
        add_submenu_page(
            'marrison-updater',
            'Backup',
            'Backup',
            'manage_options',
            'marrison-updater-backups',
            [$this, 'backup_page']
        );

        // Sottomenu Impostazioni
        add_submenu_page(
            'marrison-updater',
            'Impostazioni',
            'Impostazioni',
            'manage_options',
            'marrison-updater-settings',
            [$this, 'settings_page']
        );
    }

    public function settings_page() {
        $settingsUpdated = $_GET['settings-updated'] ?? '';
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        ?>
        <div class="mcu-wrap">
            <div class="mcu-header">
                <h1><span class="dashicons dashicons-admin-settings"></span> Impostazioni</h1>
            </div>
            
            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=marrison-updater-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">Generale</a>
                <a href="?page=marrison-updater-settings&tab=scheduling" class="nav-tab <?php echo $active_tab == 'scheduling' ? 'nav-tab-active' : ''; ?>">Programmazione</a>
                <a href="?page=marrison-updater-settings&tab=howto" class="nav-tab <?php echo $active_tab == 'howto' ? 'nav-tab-active' : ''; ?>">Guida & Download</a>
            </h2>

            <?php if ($settingsUpdated === 'saved'): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> Impostazioni salvate correttamente.</div>
            <?php elseif ($settingsUpdated === 'removed'): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> URL del repository rimosso.</div>
            <?php endif; ?>

            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> Cache pulita.</div>
            <?php endif; ?>

            <?php if (isset($_GET['mcu_checked'])): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> Controllo aggiornamenti MCU forzato con successo.</div>
            <?php endif; ?>

            <?php if ($active_tab == 'general'): ?>
                <div class="mcu-card">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-database"></span> Impostazioni Repository</h2>
                    </div>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('marrison_save_repo_url'); ?>
                        <input type="hidden" name="action" value="marrison_save_repo_url">
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="marrison_repo_url">Indirizzo Repository Plugin</label></th>
                                <td>
                                    <input type="password" id="marrison_repo_url" name="marrison_repo_url" value="<?php echo get_option('marrison_repo_url') ? '********************' : ''; ?>" class="regular-text" style="width: 100%; max-width: 500px;">
                                    <p class="description">Inserisci l'URL del repository personalizzato per i PLUGIN.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="marrison_themes_repo_url">Indirizzo Repository Temi</label></th>
                                <td>
                                    <input type="password" id="marrison_themes_repo_url" name="marrison_themes_repo_url" value="<?php echo get_option('marrison_themes_repo_url') ? '********************' : ''; ?>" class="regular-text" style="width: 100%; max-width: 500px;">
                                    <p class="description">Inserisci l'URL del repository personalizzato per i TEMI.</p>
                                </td>
                            </tr>
                        </table>
                        
                        <div style="margin-top: 20px; display: flex; gap: 10px;">
                            <button class="mcu-button mcu-button-primary" type="submit">Salva Impostazioni</button>
                            <button class="mcu-button mcu-button-secondary" type="submit" name="marrison_remove_repo_url" value="1" onclick="return confirm('Sei sicuro di voler rimuovere gli URL?');">Rimuovi URL</button>
                        </div>
                    </form>
                </div>

                <div class="mcu-card" style="margin-top: 30px;">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-admin-tools"></span> Strumenti Avanzati</h2>
                    </div>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="padding: 10px 0;">
                        <?php wp_nonce_field('marrison_force_check_mcu'); ?>
                        <input type="hidden" name="action" value="marrison_force_check_mcu">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-updater-settings&mcu_checked=1')); ?>">
                        
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <button class="mcu-button mcu-button-secondary">Forza controllo aggiornamenti MCU</button>
                            <span class="description">Usa questo pulsante se hai appena rilasciato una nuova versione su GitHub e non viene rilevata.</span>
                        </div>
                    </form>
                </div>

                <?php
                $updates = $this->get_available_updates();
                $plugins = get_plugins();
                $repo_count = count($updates);
                $installed_count = 0;
                $installed_list = [];

                if (!empty($updates)) {
                    foreach ($updates as $u) {
                        $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                        if ($file && isset($plugins[$file])) {
                            $installed_count++;
                            $installed_list[] = [
                                'name' => $u['name'],
                                'file' => $file,
                                'version' => $plugins[$file]['Version'],
                                'remote_version' => $u['version'],
                                'status' => '<span class="mcu-badge mcu-badge-success">Monitorato</span>'
                            ];
                        }
                    }
                }
                ?>

                <div class="mcu-dashboard-grid" style="margin-top: 30px;">
                    <div class="mcu-card mcu-stat-card">
                        <div class="mcu-stat-number"><?php echo !empty($updates) ? '<span class="dashicons dashicons-yes" style="color:var(--mcu-success); font-size: 36px; height: 36px; width: 36px;"></span>' : '<span class="dashicons dashicons-no" style="color:var(--mcu-danger); font-size: 36px; height: 36px; width: 36px;"></span>'; ?></div>
                        <div class="mcu-stat-label">Stato Plugin</div>
                    </div>
                    <div class="mcu-card mcu-stat-card">
                        <div class="mcu-stat-number"><?php echo $repo_count; ?></div>
                        <div class="mcu-stat-label">Plugin nel Repo</div>
                    </div>
                    <div class="mcu-card mcu-stat-card">
                        <div class="mcu-stat-number"><?php echo $installed_count; ?></div>
                        <div class="mcu-stat-label">Plugin Monitorati</div>
                    </div>
                </div>

                <?php if ($installed_count > 0): ?>
                    <div class="mcu-card">
                        <div class="mcu-card-header">
                            <h2 class="mcu-card-title">Plugin Monitorati su questo sito</h2>
                        </div>
                        <table class="mcu-table">
                            <thead>
                                <tr>
                                    <th>Plugin Installato</th>
                                    <th>File</th>
                                    <th>Versione Installata</th>
                                    <th>Versione Repository</th>
                                    <th>Stato</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($installed_list as $item): ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($item['name']); ?></strong></td>
                                        <td><code><?php echo esc_html($item['file']); ?></code></td>
                                        <td><?php echo esc_html($item['version']); ?></td>
                                        <td><?php echo esc_html($item['remote_version']); ?></td>
                                        <td><?php echo $item['status']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php
                $theme_updates = $this->get_available_theme_updates();
                $theme_repo_count = count($theme_updates);
                $theme_installed_count = 0;
                $theme_installed_list = [];

                if (!empty($theme_updates)) {
                    $installed_themes = wp_get_themes();
                    foreach ($theme_updates as $u) {
                        $slug = $u['slug'];
                        $theme = wp_get_theme($slug);
                        $is_installed = $theme->exists();
                        $detected_slug = $slug;

                        if (!$is_installed) {
                            foreach ($installed_themes as $t_slug => $t_obj) {
                                if (strcasecmp($t_obj->get('Name'), $u['name']) === 0 || $t_obj->get('TextDomain') === $slug) {
                                    $theme = $t_obj;
                                    $is_installed = true;
                                    $detected_slug = $t_slug;
                                    break;
                                }
                            }
                        }
                        
                        if ($is_installed) {
                            $theme_installed_count++;
                            $theme_installed_list[] = [
                                'name' => $u['name'],
                                'slug' => $detected_slug,
                                'version' => $is_installed ? $theme->get('Version') : '-',
                                'remote_version' => $u['version'],
                                'status' => '<span class="mcu-badge mcu-badge-success">Monitorato</span>'
                            ];
                        }
                    }
                }
                ?>

                <div class="mcu-dashboard-grid" style="margin-top: 30px;">
                    <div class="mcu-card mcu-stat-card">
                        <div class="mcu-stat-number"><?php echo !empty($theme_updates) ? '<span class="dashicons dashicons-yes" style="color:var(--mcu-success); font-size: 36px; height: 36px; width: 36px;"></span>' : '<span class="dashicons dashicons-no" style="color:var(--mcu-danger); font-size: 36px; height: 36px; width: 36px;"></span>'; ?></div>
                        <div class="mcu-stat-label">Stato Temi</div>
                    </div>
                    <div class="mcu-card mcu-stat-card">
                        <div class="mcu-stat-number"><?php echo $theme_repo_count; ?></div>
                        <div class="mcu-stat-label">Temi nel Repo</div>
                    </div>
                    <div class="mcu-card mcu-stat-card">
                        <div class="mcu-stat-number"><?php echo $theme_installed_count; ?></div>
                        <div class="mcu-stat-label">Temi Monitorati</div>
                    </div>
                </div>

                <?php if (!empty($theme_installed_list)): ?>
                    <div class="mcu-card">
                        <div class="mcu-card-header">
                            <h2 class="mcu-card-title">Temi Installati Monitorati</h2>
                        </div>
                        <table class="mcu-table">
                            <thead>
                                <tr>
                                    <th>Tema</th>
                                    <th>Slug (Cartella)</th>
                                    <th>Versione Installata</th>
                                    <th>Versione Repository</th>
                                    <th>Stato</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($theme_installed_list as $item): ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($item['name']); ?></strong></td>
                                        <td><code><?php echo esc_html($item['slug']); ?></code></td>
                                        <td><?php echo esc_html($item['version']); ?></td>
                                        <td><?php echo esc_html($item['remote_version']); ?></td>
                                        <td><?php echo $item['status']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php elseif ($active_tab == 'scheduling'): ?>
                <div class="mcu-card">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-calendar-alt"></span> Programmazione Aggiornamenti</h2>
                    </div>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('marrison_save_scheduling'); ?>
                        <input type="hidden" name="action" value="marrison_save_scheduling">
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="marrison_auto_update_enabled">Abilita Aggiornamenti Automatici</label></th>
                                <td>
                                    <input type="checkbox" id="marrison_auto_update_enabled" name="marrison_auto_update_enabled" value="yes" <?php checked('yes', get_option('marrison_auto_update_enabled')); ?>>
                                    <label for="marrison_auto_update_enabled">Attiva aggiornamento automatico periodico</label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="marrison_auto_update_frequency">Frequenza</label></th>
                                <td>
                                    <select id="marrison_auto_update_frequency" name="marrison_auto_update_frequency">
                                        <option value="daily" <?php selected('daily', get_option('marrison_auto_update_frequency')); ?>>Ogni giorno</option>
                                        <option value="monthly" <?php selected('monthly', get_option('marrison_auto_update_frequency')); ?>>Una volta al mese</option>
                                        <option value="biannual" <?php selected('biannual', get_option('marrison_auto_update_frequency')); ?>>Una volta ogni 6 mesi</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="marrison_auto_update_time">Orario (Fuso Orario Italiano)</label></th>
                                <td>
                                    <input type="time" id="marrison_auto_update_time" name="marrison_auto_update_time" value="<?php echo esc_attr(get_option('marrison_auto_update_time', '00:00')); ?>">
                                    <p class="description">Seleziona l'orario di esecuzione (Europe/Rome).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="marrison_auto_update_email">Email per Report</label></th>
                                <td>
                                    <input type="email" id="marrison_auto_update_email" name="marrison_auto_update_email" value="<?php echo esc_attr(get_option('marrison_auto_update_email', get_option('admin_email'))); ?>" class="regular-text">
                                    <p class="description">Inserisci l'indirizzo email dove inviare il report degli aggiornamenti (opzionale).</p>
                                </td>
                            </tr>
                        </table>
                        
                        <?php 
                        $next_run = wp_next_scheduled('marrison_scheduled_update_event');
                        if ($next_run): 
                            $tz = new DateTimeZone('Europe/Rome');
                            $date = new DateTime('@' . $next_run);
                            $date->setTimezone($tz);
                        ?>
                            <div class="mcu-notice mcu-notice-info" style="margin-top: 20px;">
                                <span class="dashicons dashicons-clock"></span> Prossima esecuzione programmata: <strong><?php echo $date->format('d/m/Y H:i'); ?></strong>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 20px;">
                            <button class="mcu-button mcu-button-primary" type="submit">Salva Programmazione</button>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <!-- HOW TO TAB -->
                <div class="mcu-card">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-book"></span> Guida all'uso</h2>
                    </div>
                    <div style="padding: 10px 0;">
                        <p>Per trasformare una cartella del tuo server in un Repository Privato compatibile con Marrison Custom Updater, segui questi passaggi:</p>
                        
                        <h3 style="margin-top: 20px;">1. Repository Plugin</h3>
                        <ol style="margin-left: 20px; list-style: decimal;">
                            <li>Crea una cartella pubblica sul tuo server (es. <code>https://tuosito.com/my-repo/plugins/</code>).</li>
                            <li>Scarica il file <code>index.php</code> qui sotto.</li>
                            <li>Carica il file nella cartella appena creata.</li>
                            <li>Carica i file <code>.zip</code> dei tuoi plugin nella stessa cartella.</li>
                            <li>Inserisci l'URL della cartella (es. <code>https://tuosito.com/my-repo/plugins/</code>) nelle Impostazioni di questo plugin.</li>
                        </ol>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top: 15px;">
                            <?php wp_nonce_field('marrison_download_repo_file'); ?>
                            <input type="hidden" name="action" value="marrison_download_repo_file">
                            <input type="hidden" name="file_type" value="plugin">
                            <button type="submit" class="mcu-button mcu-button-primary"><span class="dashicons dashicons-download"></span> Scarica index.php per Plugin</button>
                        </form>

                        <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

                        <h3>2. Repository Temi</h3>
                        <ol style="margin-left: 20px; list-style: decimal;">
                            <li>Crea una cartella pubblica sul tuo server (es. <code>https://tuosito.com/my-repo/themes/</code>).</li>
                            <li>Scarica il file <code>index.php</code> qui sotto (specifico per i temi).</li>
                            <li>Rinomina il file scaricato in <code>index.php</code> se necessario, oppure caricalo così com'è se supportato, ma solitamente deve chiamarsi index.php per essere servito di default. <em>Nota: il file scaricato si chiamerà index-themes.php, rinominalo in index.php sul server.</em></li>
                            <li>Carica il file nella cartella appena creata.</li>
                            <li>Carica i file <code>.zip</code> dei tuoi temi nella stessa cartella.</li>
                            <li>Inserisci l'URL della cartella (es. <code>https://tuosito.com/my-repo/themes/</code>) nelle Impostazioni di questo plugin.</li>
                        </ol>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top: 15px;">
                            <?php wp_nonce_field('marrison_download_repo_file'); ?>
                            <input type="hidden" name="action" value="marrison_download_repo_file">
                            <input type="hidden" name="file_type" value="theme">
                            <button type="submit" class="mcu-button mcu-button-primary"><span class="dashicons dashicons-download"></span> Scarica index.php per Temi</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function download_repo_file() {
        check_admin_referer('marrison_download_repo_file');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permessi insufficienti');
        }

        $type = $_POST['file_type'] ?? 'plugin';
        $source_dir = plugin_dir_path(__FILE__) . 'add_this_file_to_your_repo_folder/';
        
        if ($type === 'theme') {
            $file = $source_dir . 'index-themes.php';
            $filename = 'index-themes.php';
        } else {
            $file = $source_dir . 'index.php';
            $filename = 'index.php';
        }

        if (!file_exists($file)) {
            wp_die('File non trovato: ' . esc_html($file));
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }


    public function installer_page() {
        // Controllo permessi remoti
        $permissions = $this->check_remote_permissions();
        
        if (!$permissions['installer']) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Installer - Repository Privato', 'marrison-custom-updater'); ?></h1>
                <div class="notice notice-error"><p><?php esc_html_e('Non sei autorizzato a visualizzare questa pagina.', 'marrison-custom-updater'); ?></p></div>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('marrison_check_permissions'); ?>
                    <input type="hidden" name="action" value="marrison_check_permissions">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-updater-installer')); ?>">
                    <button class="button button-secondary"><?php esc_html_e('Verifica permessi', 'marrison-custom-updater'); ?></button>
                </form>
            </div>
            <?php
            return;
        }

        $updates = $this->get_available_updates();
        $plugins = get_plugins();
        $installed_slugs = $_GET['installed'] ?? [];
        if (!is_array($installed_slugs)) $installed_slugs = [$installed_slugs];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Installer - Repository Privato', 'marrison-custom-updater'); ?></h1>

            <?php if (!empty($installed_slugs)): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php printf(esc_html__('%d plugin installati con successo.', 'marrison-custom-updater'), count($installed_slugs)); ?></p>
                </div>
            <?php endif; ?>

            <!-- Barra di caricamento installazione -->
            <div id="marrison-install-progress" class="notice notice-info" style="display:none; padding: 15px; margin: 20px 0;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="spinner is-active" style="float:none; width:20px; height:20px; margin:0;"></div>
                    <div style="flex: 1;">
                        <div id="marrison-install-status" style="font-weight: 600; margin-bottom: 8px;">Installazione in corso...</div>
                        <div style="background: #f0f0f1; border-radius: 4px; height: 8px; overflow: hidden;">
                            <div id="marrison-install-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                        </div>
                        <div id="marrison-install-info" style="font-size: 12px; color: #646970; margin-top: 4px;">In attesa...</div>
                    </div>
                </div>
            </div>

            <form id="marrison-install-form" method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_bulk_install'); ?>
                <input type="hidden" name="action" value="marrison_bulk_install">

                <div class="tablenav top">
                    <div class="alignleft actions">
                        <label style="font-weight: 600;"><input type="checkbox" id="marrison-select-all"> Seleziona tutti</label>
                    </div>
                    <div class="alignleft actions">
                         <button type="submit" id="marrison-install-btn" class="button button-primary">Installa selezionati</button>
                    </div>
                </div>

                <div class="marrison-grid-container" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-top: 20px;">
                    <?php if (empty($updates)): ?>
                        <p>Nessun plugin disponibile nel repository.</p>
                    <?php else: ?>
                        <?php foreach ($updates as $u): 
                            if (!isset($u['slug'])) continue;
                            
                            $slug = $u['slug'];
                            $plugin_file = $this->find_plugin_file($slug, $u['name'] ?? '');
                            $is_installed = !empty($plugin_file);
                            $is_active = $is_installed && is_plugin_active($plugin_file);
                            
                            // Disabilita se installato (sia attivo che inattivo)
                            $disabled = $is_installed;
                            $card_style = $disabled ? 'opacity: 0.6; background: #f6f7f7;' : 'background: #fff;';
                            $card_style .= ' border: 1px solid #c3c4c7; padding: 12px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); position: relative;';
                        ?>
                            <div class="marrison-plugin-card" style="<?php echo $card_style; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                    <h3 style="margin: 0; font-size: 1em; line-height: 1.3;"><?php echo esc_html($u['name']); ?></h3>
                                    <?php if (!$disabled): ?>
                                        <input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>" class="marrison-plugin-cb" style="transform: scale(1.1);">
                                    <?php else: ?>
                                        <input type="checkbox" disabled checked style="transform: scale(1.1);">
                                    <?php endif; ?>
                                </div>
                                
                                <p style="margin: 4px 0; font-size: 0.9em;"><strong>v</strong> <?php echo esc_html($u['version']); ?></p>
                                
                                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #f0f0f1; font-size: 0.85em;">
                                    <?php if ($is_active): ?>
                                        <span class="dashicons dashicons-yes" style="color: #00a32a; font-size: 16px; width: 16px; height: 16px;"></span> <span style="color: #00a32a; font-weight: 600;">Attivo</span>
                                    <?php elseif ($is_installed): ?>
                                        <span class="dashicons dashicons-warning" style="color: #dba617; font-size: 16px; width: 16px; height: 16px;"></span> <span style="color: #dba617; font-weight: 600;">Installato (Inattivo)</span>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-download" style="color: #2271b1; font-size: 16px; width: 16px; height: 16px;"></span> <span style="color: #2271b1; font-weight: 600;">Disponibile</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                // Seleziona tutto
                $('#marrison-select-all').on('change', function() {
                    $('.marrison-plugin-cb').prop('checked', $(this).is(':checked'));
                });

                // Gestione installazione AJAX
                $('#marrison-install-form').on('submit', function(e) {
                    var selected = $('.marrison-plugin-cb:checked');
                    if (selected.length === 0) {
                        alert('Seleziona almeno un plugin da installare.');
                        return false;
                    }

                    // Se confermato, procedi con AJAX
                    e.preventDefault();
                    
                    var plugins = [];
                    selected.each(function() {
                        plugins.push($(this).val());
                    });

                    var total = plugins.length;
                    var processed = 0;
                    var success = 0;

                    // Mostra barra di progresso
                    $('#marrison-install-progress').slideDown();
                    $('#marrison-install-btn').prop('disabled', true).text('Installazione in corso...');
                    $('.marrison-plugin-cb').prop('disabled', true);

                    function processNext() {
                        if (processed >= total) {
                            // Finito
                            $('#marrison-install-status').text('Completato!');
                            $('#marrison-install-info').text(success + ' su ' + total + ' plugin installati correttamente. Ricaricamento...');
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                            return;
                        }

                        var slug = plugins[processed];
                        var percent = Math.round((processed / total) * 100);
                        
                        $('#marrison-install-bar').css('width', percent + '%');
                        $('#marrison-install-info').text('Installazione di ' + slug + ' (' + (processed + 1) + '/' + total + ')...');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'marrison_update_plugin_ajax', // Usiamo lo stesso handler dell'aggiornamento
                                slug: slug,
                                nonce: '<?php echo wp_create_nonce("marrison_bulk_update"); ?>' // Usa nonce bulk generico
                            },
                            success: function(response) {
                                if (response.success) {
                                    success++;
                                } else {
                                    console.error('Errore installazione ' + slug + ':', response);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Errore AJAX ' + slug + ':', error);
                            },
                            complete: function() {
                                processed++;
                                $('#marrison-install-bar').css('width', Math.round((processed / total) * 100) + '%');
                                processNext();
                            }
                        });
                    }

                    // Avvia processo
                    processNext();
                });
            });
            </script>
        </div>
        <?php
    }

    public function backup_page() {
        $restored = $_GET['restored'] ?? '';
        
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        ?>
        <div class="mcu-wrap">
            <div class="mcu-header">
                <h1><span class="dashicons dashicons-backup"></span> Backup Disponibili</h1>
            </div>
            
            <!-- Progress Bar -->
            <div class="mcu-progress-container">
                <div class="mcu-progress-header">
                    <span id="mcu-progress-title">Ripristino in corso...</span>
                    <span id="mcu-progress-percentage"></span>
                </div>
                <div class="mcu-progress-track">
                    <div class="mcu-progress-bar"></div>
                </div>
                <div class="mcu-progress-status" id="mcu-progress-status-text">Inizializzazione...</div>
            </div>

            <?php if ($restored): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> Plugin <?php echo esc_html($restored); ?> ripristinato con successo.</div>
            <?php endif; ?>

            <?php 
            $backup_dir = WP_CONTENT_DIR . '/marrison-backups';
            $backups = [];
            if (is_dir($backup_dir)) {
                $files = glob($backup_dir . '/*-backup.zip');
                usort($files, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });

                foreach ($files as $file) {
                    $filename = basename($file);
                    
                    $slug = '';
                    $backup_version = 'N/A';
                    $type_label = 'Plugin';
                    
                    $parse_name = $filename;
                    if (strpos($filename, 'theme-') === 0) {
                        $type_label = 'Tema';
                        $parse_name = substr($filename, 6);
                    } elseif (strpos($filename, 'plugin-') === 0) {
                        $type_label = 'Plugin';
                        $parse_name = substr($filename, 7);
                    }
                    
                    if (preg_match('/^(.*?)-v(.*)-backup\.zip$/', $parse_name, $matches)) {
                        $slug = $matches[1];
                        $backup_version = $matches[2];
                    } elseif (preg_match('/^(.*?)-backup\.zip$/', $parse_name, $matches)) {
                        $slug = $matches[1];
                    } else {
                        if (preg_match('/^(.*)-v(.*)-backup\.zip$/', $filename, $matches)) {
                            $slug = $matches[1];
                            $backup_version = $matches[2];
                        } else {
                            $slug = str_replace('-backup.zip', '', $filename);
                        }
                    }
                    
                    $backups[] = [
                        'file' => $file,
                        'filename' => $filename,
                        'slug' => $slug,
                        'type_label' => $type_label,
                        'backup_version' => $backup_version,
                        'date' => date('d/m/Y H:i', filemtime($file)),
                        'size' => size_format(filesize($file))
                    ];
                }
            }
            ?>

            <div class="mcu-card">
                <div class="mcu-card-header">
                    <h2 class="mcu-card-title"><span class="dashicons dashicons-list-view"></span> Lista Backup</h2>
                    <span class="mcu-badge mcu-badge-primary"><?php echo count($backups); ?> Backup</span>
                </div>

                <?php if (!empty($backups)): ?>
                    <table class="mcu-table">
                        <thead>
                            <tr>
                                <th>Elemento</th>
                                <th>Versione Backup</th>
                                <th>Versione Attuale</th>
                                <th>Data Backup</th>
                                <th>Dimensione</th>
                                <th style="text-align:right;">Azione</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $info): 
                                 $plugin_name = $info['slug'];
                                 $current_version = 'Non installato';
                                 $version_class = '';
                                 
                                 if (isset($info['type_label']) && $info['type_label'] === 'Tema') {
                                     $theme = wp_get_theme($info['slug']);
                                     if ($theme->exists()) {
                                         $plugin_name = $theme->get('Name');
                                         $current_version = $theme->get('Version');
                                     }
                                 } else {
                                     $found_file = $this->find_plugin_file($info['slug']);
                                     if ($found_file && isset($plugins[$found_file])) {
                                         $plugin_name = $plugins[$found_file]['Name'];
                                         $current_version = $plugins[$found_file]['Version'];
                                     }
                                 }

                                 if ($info['backup_version'] !== 'N/A' && $info['backup_version'] !== $current_version) {
                                     $version_class = 'color: var(--mcu-danger); font-weight: bold;';
                                 }
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($plugin_name); ?></strong>
                                        <span class="mcu-badge" style="background:#e0e0e0; margin-left:5px;"><?php echo esc_html($info['type_label'] ?? 'Plugin'); ?></span>
                                        <div style="font-size:11px; color:#888;"><?php echo esc_html($info['slug']); ?></div>
                                    </td>
                                    <td>
                                        <span class="mcu-badge mcu-badge-primary">
                                            <?php echo esc_html($info['backup_version']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="<?php echo $version_class; ?>">
                                            <?php echo esc_html($current_version); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($info['date']); ?></td>
                                    <td><?php echo esc_html($info['size']); ?></td>
                                    <td style="text-align:right;">
                                        <?php 
                                        $restore_nonce = wp_create_nonce('marrison_restore_' . $info['filename']); 
                                        ?>
                                        <button type="button" 
                                                class="mcu-button mcu-button-secondary mcu-button-sm mcu-action-restore" 
                                                data-filename="<?php echo esc_attr($info['filename']); ?>"
                                                data-nonce="<?php echo esc_attr($restore_nonce); ?>">
                                            <span class="dashicons dashicons-undo"></span> Ripristina
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="mcu-empty-state">
                        <span class="dashicons dashicons-backup"></span>
                        <p>Nessun backup disponibile.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }


    public function admin_page() {

        $updates     = $this->get_available_updates();
        $theme_updates = $this->get_available_theme_updates();
        $plugins     = get_plugins();
        $themes      = wp_get_themes();
        $updated     = $_GET['updated'] ?? '';
        $restored    = $_GET['restored'] ?? '';
        $bulkUpdated = $_GET['bulk_updated'] ?? [];
        if (!is_array($bulkUpdated)) $bulkUpdated = [$bulkUpdated];
        $settingsUpdated = $_GET['settings-updated'] ?? '';

        // Calcola conteggi per la dashboard
        $repo_updates_count = 0;
        foreach($updates as $u) {
            $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($file && isset($plugins[$file]) && version_compare(trim($plugins[$file]['Version']), trim($u['version']), '<')) {
                $repo_updates_count++;
            }
        }
        
        $theme_updates_count = 0;
        $installed_themes = wp_get_themes();
        foreach($theme_updates as $u) {
            $slug = $u['slug'];
            $theme = wp_get_theme($slug);
             if (!$theme->exists()) {
                foreach ($installed_themes as $t_slug => $t_obj) {
                    if (strcasecmp($t_obj->get('Name'), $u['name']) === 0 || $t_obj->get('TextDomain') === $slug) {
                        $theme = $t_obj;
                        break;
                    }
                }
            }
            if ($theme->exists() && version_compare($theme->get('Version'), $u['version'], '<')) {
                $theme_updates_count++;
            }
        }
        
        $total_updates = $repo_updates_count + $theme_updates_count;

        // Check if repo URL is configured
        $repo_url_config = get_option('marrison_repo_url');
        $theme_repo_url_config = get_option('marrison_themes_repo_url');

        // Calcola aggiornamenti pubblici Plugin
        $transient_plugins = get_site_transient('update_plugins');
        $public_plugin_updates_count = 0;
        
        // Logica per escludere i privati (copiata da sotto per avere il conteggio in alto)
        $private_updates_check = $this->get_available_updates();
        $private_slugs_check = [];
        $private_files_check = [];
        foreach ($private_updates_check as $u) {
            $private_slugs_check[] = $u['slug'];
            $found_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($found_file) $private_files_check[] = $found_file;
        }
        $known_slugs_check = get_option('marrison_known_private_slugs', []);
        if (is_array($known_slugs_check)) {
            $private_slugs_check = array_unique(array_merge($private_slugs_check, $known_slugs_check));
        }

        if (!empty($transient_plugins->response)) {
            foreach ($transient_plugins->response as $file => $data) {
                // ESCLUDI i plugin del repository privato
                if (in_array($file, $private_files_check)) continue;
                
                $check_slugs = [dirname($file), basename($file, '.php')];
                if (isset($data->slug)) $check_slugs[] = $data->slug;
                
                $found_private = false;
                foreach ($check_slugs as $s) {
                    if ($s !== '.' && $s !== '' && in_array($s, $private_slugs_check)) {
                        $found_private = true;
                        break;
                    }
                }
                if ($found_private) continue;
                
                $public_plugin_updates_count++;
            }
        }

        // Calcola aggiornamenti pubblici Temi
        $transient_themes = get_site_transient('update_themes');
        $public_theme_updates_count = 0;
        
        // Raccogli slug temi privati
        $private_theme_slugs = [];
        foreach ($theme_updates as $u) {
            $private_theme_slugs[] = $u['slug'];
        }

        if (!empty($transient_themes->response)) {
             foreach ($transient_themes->response as $slug => $data) {
                 // Escludi se Ã¨ nel repo privato
                 if (in_array($slug, $private_theme_slugs)) continue;
                 $public_theme_updates_count++;
             }
        }

        // Calcola aggiornamenti traduzioni
        include_once ABSPATH . 'wp-admin/includes/translation-install.php';
        $translation_updates = wp_get_translation_updates();
        $translation_updates_count = count($translation_updates);

        ?>
        <div class="mcu-wrap">
            <div class="mcu-header">
                <h1><span class="dashicons dashicons-cloud-upload"></span> Marrison Updater</h1>
                <div class="mcu-header-actions">
                    <button type="button" class="mcu-button mcu-button-primary mcu-action-update-all" style="margin-right: 10px;" 
                            data-nonce-auto="<?php echo wp_create_nonce('marrison_auto_update'); ?>"
                            data-nonce-bulk="<?php echo wp_create_nonce('marrison_bulk_update'); ?>"
                            data-nonce-all="<?php echo wp_create_nonce('marrison_update_all'); ?>">
                        <span class="dashicons dashicons-update-alt"></span> Aggiorna tutto
                    </button>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                        <?php wp_nonce_field('marrison_clear_cache'); ?>
                        <input type="hidden" name="action" value="marrison_clear_cache">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-updater&cache_cleared=1')); ?>">
                        <button class="mcu-button mcu-button-secondary mcu-action-clear-cache">
                            <span class="dashicons dashicons-update"></span> Pulisci Cache
                        </button>
                    </form>
                </div>
            </div>

            <!-- Notifications -->
            <?php if ($settingsUpdated === 'saved'): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> Impostazioni salvate correttamente.</div>
            <?php elseif ($settingsUpdated === 'removed'): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> URL del repository ripristinato ai valori predefiniti.</div>
            <?php endif; ?>

            <?php if ($bulkUpdated): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> Bulk update completato.</div>
            <?php endif; ?>
            
            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> Cache pulita con successo.</div>
            <?php endif; ?>

            <!-- Progress Bar -->
            <div class="mcu-progress-container">
                <div class="mcu-progress-header">
                    <span id="mcu-progress-title">Aggiornamento in corso...</span>
                    <span id="mcu-progress-percentage"></span>
                </div>
                <div class="mcu-progress-track">
                    <div class="mcu-progress-bar"></div>
                </div>
                <div class="mcu-progress-status" id="mcu-progress-status-text">Inizializzazione...</div>
            </div>

            <!-- Dashboard Stats -->
            <!-- Dashboard Stats -->
            <div class="mcu-dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                <div class="mcu-card mcu-stat-card">
                    <?php if (empty($repo_url_config)): ?>
                        <div class="mcu-stat-number" style="color: var(--mcu-warning);">
                            <span class="dashicons dashicons-warning" style="font-size: 36px; height: 36px; width: 36px;"></span>
                        </div>
                    <?php else: ?>
                        <div class="mcu-stat-number" style="color: <?php echo $repo_updates_count > 0 ? 'var(--mcu-danger)' : 'var(--mcu-success)'; ?>;">
                            <?php echo $repo_updates_count > 0 ? $repo_updates_count : '<span class="dashicons dashicons-yes" style="font-size: 36px; height: 36px; width: 36px;"></span>'; ?>
                        </div>
                    <?php endif; ?>
                    <div class="mcu-stat-label">Plugin Privati</div>
                </div>
                <div class="mcu-card mcu-stat-card">
                    <div class="mcu-stat-number" style="color: <?php echo $public_plugin_updates_count > 0 ? 'var(--mcu-danger)' : 'var(--mcu-success)'; ?>;">
                        <?php echo $public_plugin_updates_count > 0 ? $public_plugin_updates_count : '<span class="dashicons dashicons-yes" style="font-size: 36px; height: 36px; width: 36px;"></span>'; ?>
                    </div>
                    <div class="mcu-stat-label">Plugin Pubblici</div>
                </div>
                <div class="mcu-card mcu-stat-card">
                    <?php if (empty($theme_repo_url_config)): ?>
                        <div class="mcu-stat-number" style="color: var(--mcu-warning);">
                            <span class="dashicons dashicons-warning" style="font-size: 36px; height: 36px; width: 36px;"></span>
                        </div>
                    <?php else: ?>
                        <div class="mcu-stat-number" style="color: <?php echo $theme_updates_count > 0 ? 'var(--mcu-danger)' : 'var(--mcu-success)'; ?>;">
                            <?php echo $theme_updates_count > 0 ? $theme_updates_count : '<span class="dashicons dashicons-yes" style="font-size: 36px; height: 36px; width: 36px;"></span>'; ?>
                        </div>
                    <?php endif; ?>
                    <div class="mcu-stat-label">Temi Privati</div>
                </div>
                 <div class="mcu-card mcu-stat-card">
                    <div class="mcu-stat-number" style="color: <?php echo $public_theme_updates_count > 0 ? 'var(--mcu-danger)' : 'var(--mcu-success)'; ?>;">
                        <?php echo $public_theme_updates_count > 0 ? $public_theme_updates_count : '<span class="dashicons dashicons-yes" style="font-size: 36px; height: 36px; width: 36px;"></span>'; ?>
                    </div>
                    <div class="mcu-stat-label">Temi Pubblici</div>
                </div>
                <div class="mcu-card mcu-stat-card">
                    <div class="mcu-stat-number" style="color: <?php echo $translation_updates_count > 0 ? 'var(--mcu-danger)' : 'var(--mcu-success)'; ?>;">
                        <?php echo $translation_updates_count > 0 ? $translation_updates_count : '<span class="dashicons dashicons-yes" style="font-size: 36px; height: 36px; width: 36px;"></span>'; ?>
                    </div>
                    <div class="mcu-stat-label">Traduzioni</div>
                </div>
            </div>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_bulk_update'); ?>
                <input type="hidden" name="action" value="marrison_bulk_update">

                <!-- Private Plugins -->
                <div class="mcu-card" style="margin-bottom: 30px;">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-admin-plugins"></span> Plugin Repository Privato</h2>
                        <?php if ($repo_updates_count > 0): ?>
                            <button type="button" class="mcu-button mcu-button-primary mcu-button-sm mcu-action-bulk-update-private" data-type="plugin">Aggiorna Selezionati</button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($updates)): ?>
                         <div class="mcu-empty-state">
                            <?php if (empty($repo_url_config)): ?>
                                <span class="dashicons dashicons-warning" style="color: var(--mcu-warning);"></span>
                                <p>Repository non configurato.</p>
                                <a href="<?php echo admin_url('admin.php?page=marrison-updater-settings'); ?>" class="mcu-button mcu-button-secondary">Configura ora</a>
                            <?php else: ?>
                                <span class="dashicons dashicons-saved"></span>
                                <p>Tutti i plugin privati sono aggiornati.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <table class="mcu-table">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"><input type="checkbox" id="cb-select-all-1"></th>
                                    <th>Plugin</th>
                                    <th>Versione</th>
                                    <th style="text-align:right;">Azione</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
                            $has_repo_updates = false;
                            foreach ($updates as $u):
                                $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                                if ($file && isset($plugins[$file])) {
                                    $data = $plugins[$file];
                                    $slug = $u['slug']; 
                                    $has_update = version_compare(trim($data['Version']), trim($u['version']), '<');
                                    if ($has_update) $has_repo_updates = true;
                                    
                                    $row_style = $has_update ? '' : 'opacity: 0.6; background: #f9f9f9;';
                            ?>
                                <tr style="<?php echo $row_style; ?>">
                                    <td>
                                        <?php if($has_update): ?>
                                            <?php 
                                            $nonce = ($slug === 'marrison-custom-updater') ? wp_create_nonce('marrison_update_marrison-custom-updater') : wp_create_nonce('marrison_update_' . $slug);
                                            ?>
                                            <input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
                                        <?php else: ?>
                                            <span class="dashicons dashicons-yes" style="color:var(--mcu-success);"></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($u['name']); ?></strong>
                                        <div style="font-size:11px; color:#888;"><?php echo esc_html($slug); ?></div>
                                    </td>
                                    <td>
                                        <span class="mcu-badge mcu-badge-<?php echo $has_update ? 'warning' : 'success'; ?>">
                                            <?php echo esc_html($data['Version']); ?>
                                        </span>
                                        <?php if($has_update): ?>
                                            <span class="dashicons dashicons-arrow-right-alt2" style="font-size:12px;vertical-align:middle;margin:0 5px;"></span>
                                            <span class="mcu-badge mcu-badge-success"><?php echo esc_html($u['version']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php if ($has_update): ?>
                                            <?php 
                                            $is_self_update = ($slug === 'marrison-custom-updater');
                                            $nonce = $is_self_update ? wp_create_nonce('marrison_update_marrison-custom-updater') : wp_create_nonce('marrison_update_' . $slug);
                                            ?>
                                            <button class="mcu-button mcu-button-primary mcu-button-sm mcu-action-update" 
                                                    data-slug="<?php echo esc_attr($slug); ?>"
                                                    data-version="<?php echo esc_attr($u['version']); ?>"
                                                    data-nonce="<?php echo esc_attr($nonce); ?>">
                                                Aggiorna
                                            </button>
                                        <?php else: ?>
                                            <span style="color:var(--mcu-success); font-size:12px; font-weight:500;">Aggiornato</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php
                                }
                            endforeach; 
                            ?>
                            <?php if (!$has_repo_updates && !empty($updates)): ?>
                                <tr><td colspan="4" style="text-align:center; padding: 20px;">Tutti i plugin monitorati sono aggiornati.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Private Themes -->
                <div class="mcu-card" style="margin-bottom: 30px;">
                    <div class="mcu-card-header">
                        <h2 class="mcu-card-title"><span class="dashicons dashicons-art"></span> Temi Repository Privato</h2>
                        <?php if ($theme_updates_count > 0): ?>
                            <button type="button" class="mcu-button mcu-button-primary mcu-button-sm mcu-action-bulk-update-private" data-type="theme">Aggiorna Selezionati</button>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($theme_repo_url_config)): ?>
                         <div class="mcu-empty-state">
                            <span class="dashicons dashicons-warning" style="color: var(--mcu-warning);"></span>
                            <p>Repository Temi non configurato.</p>
                            <a href="<?php echo admin_url('admin.php?page=marrison-updater-settings'); ?>" class="mcu-button mcu-button-secondary">Configura ora</a>
                        </div>
                    <?php else: ?>
                    
                    <table class="mcu-table">
                        <thead>
                            <tr>
                                <th style="width: 30px;"><input type="checkbox" id="cb-select-all-themes"></th>
                                <th>Tema</th>
                                <th>Versione</th>
                                <th style="text-align:right;">Azione</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        $has_theme_updates = false;
                        foreach ($theme_updates as $u):
                            $slug = $u['slug'];
                            $theme = wp_get_theme($slug);
                            if (!$theme->exists()) {
                                foreach ($installed_themes as $t_slug => $t_obj) {
                                    if (strcasecmp($t_obj->get('Name'), $u['name']) === 0 || $t_obj->get('TextDomain') === $slug) {
                                        $theme = $t_obj;
                                        $slug = $t_slug;
                                        break;
                                    }
                                }
                            }
                            
                            if ($theme->exists()) {
                                $has_update = version_compare($theme->get('Version'), $u['version'], '<');
                                if ($has_update) $has_theme_updates = true;
                                $row_style = $has_update ? '' : 'opacity: 0.6; background: #f9f9f9;';
                        ?>
                            <tr style="<?php echo $row_style; ?>">
                                <td>
                                    <?php if($has_update): ?>
                                        <?php $nonce = wp_create_nonce('marrison_update_theme_' . $slug); ?>
                                        <input type="checkbox" name="themes[]" value="<?php echo esc_attr($slug); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
                                    <?php else: ?>
                                        <span class="dashicons dashicons-yes" style="color:var(--mcu-success);"></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($u['name']); ?></td>
                                <td>
                                    <span class="mcu-badge mcu-badge-<?php echo $has_update ? 'warning' : 'success'; ?>">
                                        <?php echo esc_html($theme->get('Version')); ?>
                                    </span>
                                    <?php if($has_update): ?>
                                        <span class="dashicons dashicons-arrow-right-alt2" style="font-size:12px;vertical-align:middle;margin:0 5px;"></span>
                                        <span class="mcu-badge mcu-badge-success"><?php echo esc_html($u['version']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ($has_update): ?>
                                        <?php $nonce = wp_create_nonce('marrison_update_theme_' . $slug); ?>
                                        <button type="button" class="mcu-button mcu-button-primary mcu-button-sm mcu-action-update" 
                                                data-slug="<?php echo esc_attr($slug); ?>"
                                                data-version="<?php echo esc_attr($u['version']); ?>"
                                                data-nonce="<?php echo esc_attr($nonce); ?>"
                                                data-type="theme">
                                            Aggiorna
                                        </button>
                                    <?php else: ?>
                                        <span style="color:var(--mcu-success); font-size:12px; font-weight:500;">Aggiornato</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php
                            }
                        endforeach; 
                        
                        if (!$has_theme_updates): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 20px;">Nessun aggiornamento temi disponibile.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

            </form>

            <!-- Official Plugins Section -->
             <?php 
                // Logic for official plugins
                $auto_update_plugins = (array) get_site_option('auto_update_plugins', []);
                $transient = get_site_transient('update_plugins');
                $private_updates = $this->get_available_updates();
                $private_slugs = [];
                $private_files = [];
                foreach ($private_updates as $u) {
                    $private_slugs[] = $u['slug'];
                    $found_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                    if ($found_file) $private_files[] = $found_file;
                }
                
                $known_slugs = get_option('marrison_known_private_slugs', []);
                if (is_array($known_slugs)) {
                    $private_slugs = array_unique(array_merge($private_slugs, $known_slugs));
                }

                $plugins_with_auto_update = [];
                if (!empty($transient->response)) {
                    foreach ($transient->response as $file => $data) {
                        $slug = isset($data->slug) ? $data->slug : dirname($file);
                        if ($slug === '.') $slug = basename($file, '.php');
                        
                        // ESCLUDI i plugin del repository privato (check prioritario su file path)
                        if (in_array($file, $private_files)) continue;

                        $check_slugs = [];
                        $check_slugs[] = dirname($file);
                        $check_slugs[] = basename($file, '.php');
                        if (isset($data->slug)) $check_slugs[] = $data->slug;
                        
                        $found_private = false;
                        foreach ($check_slugs as $s) {
                            if ($s !== '.' && $s !== '' && in_array($s, $private_slugs)) {
                                $found_private = true;
                                break;
                            }
                        }
                        if ($found_private) continue;
                        
                        $plugins_with_auto_update[$slug] = [
                            'name' => isset($plugins[$file]['Name']) ? $plugins[$file]['Name'] : $slug,
                            'current_version' => isset($plugins[$file]['Version']) ? $plugins[$file]['Version'] : '?',
                            'new_version' => $data->new_version ?? '?'
                        ];
                    }
                }
            ?>

            <div class="mcu-card">
                <div class="mcu-card-header">
                    <h2 class="mcu-card-title"><span class="dashicons dashicons-wordpress"></span> Repository Ufficiale WordPress</h2>
                    <?php if (!empty($plugins_with_auto_update)): ?>
                         <?php $auto_update_nonce = wp_create_nonce('marrison_auto_update'); ?>
                        <button type="button" class="mcu-button mcu-button-primary mcu-button-sm mcu-action-auto-update" data-nonce="<?php echo esc_attr($auto_update_nonce); ?>">
                            Aggiorna Tutti
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($plugins_with_auto_update)): ?>
                    <div class="mcu-empty-state">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <p>Tutti i plugin ufficiali sono aggiornati.</p>
                    </div>
                <?php else: ?>
                    <table class="mcu-table">
                        <thead>
                            <tr>
                                <th>Plugin</th>
                                <th>Versione Attuale</th>
                                <th>Nuova Versione</th>
                                <th>Stato</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plugins_with_auto_update as $slug => $info): ?>
                                <tr>
                                    <td><?php echo esc_html($info['name']); ?></td>
                                    <td><?php echo esc_html($info['current_version']); ?></td>
                                    <td><span class="mcu-badge mcu-badge-primary"><?php echo esc_html($info['new_version']); ?></span></td>
                                    <td><span class="mcu-badge mcu-badge-warning">In attesa</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Other Updates Buttons -->
            <div class="mcu-card" style="margin-top: 20px; padding: 15px;">
                 <h3 style="margin: 0 0 15px 0;">Strumenti Aggiuntivi</h3>
                 <div style="display:flex; gap: 10px;">
                    <?php $auto_update_nonce = wp_create_nonce('marrison_auto_update'); ?>
                    <button type="button" class="mcu-button mcu-button-secondary marrison-update-themes-btn" 
                            data-nonce="<?php echo esc_attr($auto_update_nonce); ?>">
                        <span class="dashicons dashicons-art"></span> Aggiorna tutti i temi
                    </button>
                    
                    <button type="button" class="mcu-button mcu-button-secondary marrison-update-translations-btn" 
                            data-nonce="<?php echo esc_attr($auto_update_nonce); ?>" style="margin-left: 10px;">
                        <span class="dashicons dashicons-translation"></span> Aggiorna tutte le traduzioni
                    </button>
                 </div>
            </div>

        </div>
        <?php
    }


    public function update_all_themes_ajax() {
        check_ajax_referer('marrison_auto_update', 'nonce');
        
        if (!current_user_can('update_themes')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/theme.php';
        
        wp_update_themes();
        $current = get_site_transient('update_themes');
        
        if (empty($current->response)) {
             wp_send_json_error('Nessun aggiornamento temi disponibile');
        }
        
        $themes = array_keys($current->response);
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        $result = $upgrader->bulk_upgrade($themes);
        
        $success_count = 0;
        if (is_array($result)) {
            foreach ($result as $theme_result) {
                if ($theme_result && !is_wp_error($theme_result)) {
                    $success_count++;
                }
            }
        }
        
        if ($success_count > 0) {
             wp_send_json_success(sprintf('%d temi aggiornati con successo', $success_count));
        } else {
             wp_send_json_error('Nessun tema aggiornato');
        }
    }

    public function update_translations_ajax() {
        check_ajax_referer('marrison_auto_update', 'nonce');
        
        if (!current_user_can('update_core')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/file.php';
        include_once ABSPATH . 'wp-admin/includes/misc.php';
        include_once ABSPATH . 'wp-admin/includes/template.php';
        include_once ABSPATH . 'wp-admin/includes/translation-install.php';
        
        wp_version_check();
        $translations = wp_get_translation_updates();
        
        if (empty($translations)) {
             wp_send_json_error('Nessun aggiornamento traduzioni disponibile');
        }
        
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Language_Pack_Upgrader($skin);
        $result = $upgrader->bulk_upgrade($translations);
        
        $success_count = 0;
        if (is_array($result)) {
            foreach ($result as $trans_result) {
                if ($trans_result && !is_wp_error($trans_result)) {
                    $success_count++;
                }
            }
        }
        
        if ($success_count > 0) {
             wp_send_json_success(sprintf('%d traduzioni aggiornate con successo', $success_count));
        } else {
             wp_send_json_error('Nessuna traduzione aggiornata');
        }
    }

    public function get_all_updates_data() {
        // 1. Private Plugins
        $private_updates = $this->get_available_updates();
        $plugins = get_plugins();
        $private_to_update = [];
        
        foreach ($private_updates as $u) {
            $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($file && isset($plugins[$file]) && version_compare($plugins[$file]['Version'], $u['version'], '<')) {
                $private_to_update[] = [
                    'slug' => $u['slug'],
                    'name' => $u['name'],
                    'version' => $u['version']
                ];
            }
        }

        // 2. Official Plugins
        // Force check
        wp_update_plugins();
        $transient = get_site_transient('update_plugins');
        $official_to_update = [];
        
        $private_slugs = array_map(function($u) { return $u['slug']; }, $private_updates);
        
        // Add known private slugs from option
        $known_slugs = get_option('marrison_known_private_slugs', []);
        if (is_array($known_slugs)) {
            $private_slugs = array_unique(array_merge($private_slugs, $known_slugs));
        }
        
        // Add found private files to exclusion list
        $private_files = [];
        foreach ($private_updates as $u) {
             $found_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
             if ($found_file) $private_files[] = $found_file;
        }

        if (!empty($transient->response)) {
            foreach ($transient->response as $file => $data) {
                // EXCLUDE private repo plugins (priority check on file path)
                if (in_array($file, $private_files)) continue;

                $slug = isset($data->slug) ? $data->slug : dirname($file);
                if ($slug === '.') $slug = basename($file, '.php');
                
                // Secondary check on slugs
                $check_slugs = [dirname($file), basename($file, '.php')];
                if (isset($data->slug)) $check_slugs[] = $data->slug;
                
                $is_private = false;
                foreach ($check_slugs as $s) {
                    if ($s !== '.' && $s !== '' && in_array($s, $private_slugs)) {
                        $is_private = true;
                        break;
                    }
                }
                if ($is_private) continue;

                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file);
                $official_to_update[] = [
                    'file' => $file,
                    'slug' => $slug,
                    'name' => $plugin_data['Name'] ?? $slug,
                    'version' => $data->new_version,
                    'package' => $data->package ?? '',
                    'url' => $data->url ?? ''
                ];
            }
        }

        // 3. Themes
        wp_update_themes();
        $theme_updates = get_site_transient('update_themes');
        $themes_count = !empty($theme_updates->response) ? count($theme_updates->response) : 0;

        // 4. Translations
        wp_version_check();
        include_once ABSPATH . 'wp-admin/includes/translation-install.php';
        $translation_updates = wp_get_translation_updates();
        $translations_count = count($translation_updates);

        return [
            'plugins_private' => $private_to_update,
            'plugins_official' => $official_to_update,
            'themes_count' => $themes_count,
            'translations_count' => $translations_count
        ];
    }

    public function get_all_updates_ajax() {
        check_ajax_referer('marrison_update_all', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $data = $this->get_all_updates_data();

        wp_send_json_success($data);
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

        // Se la directory corretta esiste giÃ , salta
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
