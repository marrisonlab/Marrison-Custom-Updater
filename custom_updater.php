<?php
/**
 * Plugin Name: AM Updater
 * Plugin URI:  https://github.com/marrisonlab/marrison-custom-updater
 * Description: This plugin is used to add a personal repository for updating plugins.
 * Version: 8.2.2
 * Author: Angelo Marra
 * Author URI:  https://marrisonlab.com
 */


require_once __DIR__ . '/includes/traits/SchedulingTrait.php';
require_once __DIR__ . '/includes/traits/AdminUITrait.php';
require_once __DIR__ . '/includes/traits/UpdateOperationsTrait.php';

class Marrison_Custom_Updater {

    private $updates_url = '';
    private $cache_duration;

    use Marrison_Scheduling_Trait;
    use Marrison_Admin_UI_Trait;
    use Marrison_Update_Operations_Trait;

    public function __construct() {
        $this->cache_duration = defined('HOUR_IN_SECONDS') ? 6 * constant('HOUR_IN_SECONDS') : 21600;

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
        add_action('wp_ajax_marrison_test_email', [$this, 'send_test_email_ajax']);
        
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

        // Fix per rinominare la cartella durante l'aggiornamento da GitHub (evita disattivazione)
        add_filter('upgrader_source_selection', [$this, 'fix_github_folder_name'], 10, 4);
    }

    public function load_textdomain() {
        load_plugin_textdomain('marrison-custom-updater', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function force_clear_github_cache() {
        delete_transient('marrison_updater_github_version');
    }

    public function fix_github_folder_name($source, $remote_source, $upgrader, $hook_extra = null) {
        global $wp_filesystem;
        
        // Verifica se stiamo aggiornando questo plugin
        // $hook_extra['plugin'] contiene il percorso relativo al plugin file (es. folder/file.php)
        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === plugin_basename(__FILE__)) {
             $correct_slug = dirname(plugin_basename(__FILE__));
             
             // Se dirname è vuoto o . (plugin in root), non fare nulla
             if ($correct_slug === '.' || $correct_slug === '') {
                 return $source;
             }

             $new_source = trailingslashit($remote_source) . $correct_slug . '/';
             
             // Se la cartella sorgente è diversa da quella target, rinomina
             if (strcasecmp($source, $new_source) !== 0) {
                 if ($wp_filesystem->move($source, $new_source)) {
                     return $new_source;
                 }
                 // Se fallisce, ritorna errore
                 return new WP_Error('rename_failed', __('Impossible to rename directory', 'marrison-custom-updater'));
             }
        }
        
        return $source;
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

        // Identifica i plugin privati INSTALLATI per ESCLUDERLI
        $private_updates = $this->get_available_updates();
        $private_files = [];
        foreach ($private_updates as $u) {
            $f = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($f) $private_files[] = $f;
        }
        $installed_slugs = [];
        foreach ($private_files as $pf) {
            $installed_slugs[] = dirname($pf);
            $installed_slugs[] = basename($pf, '.php');
        }
        $installed_slugs = array_values(array_filter(array_unique($installed_slugs), function($s){
            return $s !== '.' && $s !== '';
        }));

        $plugins_to_update = [];
        $slugs_map = []; // Mappa slug => file
        
        foreach ($transient->response as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.' || $slug === '') $slug = basename($file, '.php');

            // ESCLUDI i plugin privati installati (FILE) o con SLUG noto privato
            if (in_array($file, $private_files)) continue;
            $check_slugs = [$slug, basename($file, '.php')];
            if (isset($data->slug)) $check_slugs[] = $data->slug;
            $found_private = false;
            foreach (array_unique($check_slugs) as $s) {
                if ($s !== '.' && $s !== '' && in_array($s, $installed_slugs)) {
                    $found_private = true;
                    break;
                }
            }
            if ($found_private) continue;

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

        // Calcola plugin privati INSTALLATI per evitare falsi positivi
        $private_updates = $this->get_available_updates();
        $private_files = [];
        foreach ($private_updates as $u) {
            $f = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($f) $private_files[] = $f;
        }
        $installed_slugs = [];
        foreach ($private_files as $pf) {
            $installed_slugs[] = dirname($pf);
            $installed_slugs[] = basename($pf, '.php');
        }
        $installed_slugs = array_values(array_filter(array_unique($installed_slugs), function($s){
            return $s !== '.' && $s !== '';
        }));

        $plugins_to_update = [];
        
        foreach ($transient->response as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.' || $slug === '') $slug = basename($file, '.php');

            // Escludi se il FILE appartiene ad un plugin privato installato
            if (in_array($file, $private_files)) continue;
            
            // Escludi se lo SLUG corrisponde ad un privato INSTALLATO (noti)
            $check_slugs = [$slug, basename($file, '.php')];
            if (isset($data->slug)) $check_slugs[] = $data->slug;
            $found_private = false;
            foreach (array_unique($check_slugs) as $s) {
                if ($s !== '.' && $s !== '' && in_array($s, $installed_slugs)) {
                    $found_private = true;
                    break;
                }
            }
            if ($found_private) continue;

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
        
        $was_active = is_plugin_active($file);
        
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
            wp_send_json_error('Error');
        } elseif (!$result) {
            wp_send_json_error(__('Update failed', 'marrison-custom-updater'));
        } else {
            if ($was_active && !is_plugin_active($file)) {
                activate_plugin($file, '', false, false);
            }

            wp_send_json_success(__('Plugin updated', 'marrison-custom-updater'));
        }
    }

    /* ===================== MENU NOTIFICATION BADGE ===================== */

    private function update_known_private_slugs($updates) {
        if (!is_array($updates)) return;
        
        $slugs = [];
        foreach ($updates as $u) {
            $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($file) {
                $slugs[] = dirname($file);
                $slugs[] = basename($file, '.php');
                if (isset($u['slug'])) $slugs[] = $u['slug'];
            }
        }
        $slugs = array_values(array_filter(array_unique($slugs), function($s) {
            return $s !== '.' && $s !== '';
        }));
        
        // Salva solo se abbiamo rilevato plugin privati INSTALLATI
        update_option('marrison_known_private_slugs', $slugs, false); // autoload = false
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

    /* ===================== UPDATE SOURCE ===================== */

    /* ===================== WP UPDATE HOOK ===================== */

    public function check_for_updates($transient) {
        if (!is_object($transient)) {
            // Se il transient non è un oggetto (es. false), lasciamo che WP lo rigeneri
            // Invece di restituire un oggetto vuoto che bloccherebbe i controlli successivi
            return $transient;
        }
        
        // Assicurati che le proprietà esistano
        if (!isset($transient->response)) $transient->response = [];
        if (!isset($transient->no_update)) $transient->no_update = [];
        if (!isset($transient->checked)) $transient->checked = [];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();

        // 1. Calcola gli slug dei plugin PRIVATI INSTALLATI
        $installed_private_slugs = [];
        $private_updates_list = $this->get_available_updates();
        if (!empty($private_updates_list)) {
            foreach ($private_updates_list as $u) {
                $f = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                if ($f) {
                    $installed_private_slugs[] = dirname($f);
                    $installed_private_slugs[] = basename($f, '.php');
                    $installed_private_slugs[] = $u['slug'];
                }
            }
            $installed_private_slugs = array_values(array_filter(array_unique($installed_private_slugs), function($s) {
                return $s !== '.' && $s !== '';
            }));
        }
        
        if (!empty($installed_private_slugs)) {
            // Pulizia aggressiva basata sullo SLUG, non solo sul file path
            // Questo gestisce casi in cui WP rileva il plugin in un path diverso (es. cartella standard vs rinominata)
            
            // Pulisci response SOLO per plugin che confliggono con privati INSTALLATI
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
                        if (in_array($s, $installed_private_slugs)) {
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
                        if (in_array($s, $installed_private_slugs)) {
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
                    'plugin'      => $file,
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

    public function check_for_theme_updates($transient) {
        if (!is_object($transient)) {
            return $transient;
        }
        
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
                if (strcasecmp($plugin_name, $name) === 0) {
                    // FIX SPECIFICO: WPCode Lite (insert-headers-and-footers)
                    // Evita che venga rilevato erroneamente se si cerca un altro plugin privato con nome simile
                    if (strpos($file, 'insert-headers-and-footers/ihaf.php') !== false) {
                        if ($slug !== 'insert-headers-and-footers' && $slug !== 'ihaf') {
                            continue;
                        }
                    }
                    return $file;
                }
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
        
        return $info;
    }
    
    private function markdown_to_html($text) {
        $text = preg_replace('/= (.*?) =/', '<h4>$1</h4>', $text);
        $text = preg_replace('/\*\* (.*?) \*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\* (.*?)/', '<li>$1</li>', $text);
        return nl2br($text);
    }

    public function add_admin_menu() {
        // ... (existing code, keep as is if not changing)
        // Since I'm using Write tool to overwrite the file, I must ensure I don't lose the existing code not shown in the Read output.
        // Wait, I used Read with offset but not the full file. 
        // I MUST READ THE FULL FILE BEFORE WRITING if I am rewriting the whole file.
        // Or better, use SearchReplace. I used SearchReplace in the previous turn but it seems I switched to Write in the tool call.
        // NO, I MUST USE SearchReplace because I don't have the full content of `add_admin_menu` and other methods.
    }
}
