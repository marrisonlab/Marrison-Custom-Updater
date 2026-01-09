<?php
/**
 * Plugin Name: Marrison Custom Updater
 * Plugin URI:  https://marrisonlab.com
 * Description: Updater custom con repository remoto, update reale dei file, singolo e bulk.
 * Version: 2
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
        
        // Hook per aggiungere link al plugin Marrison Updater nella pagina dei plugin
        add_filter('plugin_action_links', [$this, 'add_marrison_action_links'], 10, 2);
    }

    /* ===================== UPDATE SOURCE ===================== */

    private function get_available_updates() {
        $cached = get_transient('marrison_available_updates');
        if ($cached !== false) return $cached;

        $response = wp_remote_get($this->updates_url . 'index.php', ['timeout' => 15]);
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
        }

        // Estrai il changelog
        if (preg_match('/== Changelog ==\s*(.*?)$/s', $readme, $match)) {
            $info->changelog = trim($match[1]);
        }

        // Dati base
        $info->name = 'Marrison Custom Updater';
        $info->slug = 'marrison-custom-updater';
        $info->version = $this->get_github_version();
        $info->author = 'Angelo Marra';
        $info->author_profile = 'https://marrisonlab.com';
        $info->plugin_url = 'https://github.com/marrisonlab/marrison-custom-updater';
        $info->download_url = 'https://github.com/marrisonlab/marrison-custom-updater/archive/refs/tags/v' . $info->version . '.zip';
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
            'description' => $info->description,
            'changelog' => $info->changelog
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
            esc_url(admin_url('tools.php?page=marrison-updater')),
            esc_html__('Impostazioni', 'marrison-custom-updater')
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

        wp_redirect(admin_url('tools.php?page=marrison-updater&updated=' . $slug));
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
        wp_redirect(admin_url('tools.php?page=marrison-updater&' . $query));
        exit;
    }

    public function clear_cache() {
        check_admin_referer('marrison_clear_cache');
        delete_transient('marrison_available_updates');
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);

        wp_redirect(admin_url('tools.php?page=marrison-updater&cache_cleared=1'));
        exit;
    }

    /* ===================== ADMIN UI ===================== */

    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Marrison Updater',
            'Marrison Updater',
            'manage_options',
            'marrison-updater',
            [$this,'admin_page']
        );
    }

    public function admin_page() {

        $updates     = $this->get_available_updates();
        $plugins     = get_plugins();
        $updated     = $_GET['updated'] ?? '';
        $bulkUpdated = $_GET['bulk_updated'] ?? [];
        if (!is_array($bulkUpdated)) $bulkUpdated = [$bulkUpdated];

        ?>
        <div class="wrap">
            <h1>Marrison Updater</h1>

            <?php if ($bulkUpdated): ?>
                <div class="notice notice-success"><p>Bulk update completato ✔</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="notice notice-info"><p>Cache pulita ✔</p></div>
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
                                    <strong style="color:green;">✔ Aggiornato</strong>
                                <?php else: ?>
                                    <a class="button button-primary"
                                       href="<?php echo wp_nonce_url(
                                           admin_url('admin-post.php?action=marrison_update_plugin&slug=' . $slug),
                                           'marrison_update_' . $slug
                                       ); ?>">
                                        Aggiorna
                                    </a>
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
                </p>
            </form>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_clear_cache'); ?>
                <input type="hidden" name="action" value="marrison_clear_cache">
                <button class="button">Pulisci cache</button>
            </form>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
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
            });
        </script>
        <?php
    }
}

new Marrison_Custom_Updater;
/**
 * Fix definitivo: rinomina la cartella del plugin GitHub con suffisso versione
 * (es. marrison-custom-updater-1.9 → marrison-custom-updater)
 */
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) {

    if ( empty( $hook_extra['type'] ) || $hook_extra['type'] !== 'plugin' ) {
        return;
    }

    $plugins_dir = WP_PLUGIN_DIR;
    $expected    = $plugins_dir . '/marrison-custom-updater';

    // Cerca cartelle tipo marrison-custom-updater-*
    foreach ( glob( $plugins_dir . '/marrison-custom-updater-*', GLOB_ONLYDIR ) as $dir ) {

        // Se esiste già quella corretta, rimuovi la vecchia
        if ( is_dir( $expected ) ) {
            // opzionale: cleanup
            // WP_Filesystem può essere usato se vuoi essere ultra-safe
            continue;
        }

        rename( $dir, $expected );
        break;
    }

}, 10, 2 );
