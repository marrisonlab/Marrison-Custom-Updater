<?php
/**
 * Plugin Name: Marrison Custom Updater
 * Description: Updater custom con repository remoto, update reale dei file, singolo e bulk.
<<<<<<< Updated upstream
 * Version: 1.4.1
 * Author: Your Name
=======
 * Version: 1.4.3
 * Author: Angelo Marra
>>>>>>> Stashed changes
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

        return $transient;
    }

    private function find_plugin_file($slug) {
        foreach (get_plugins() as $file => $data) {
            $dir = dirname($file);
            if ($dir === '.' || $dir === '') {
                $dir = basename($file, '.php');
            }
            if ($dir === $slug) return $file;
        }
        return null;
    }

    public function plugin_info($false, $action, $args) {
        if ($action !== 'plugin_information') return $false;
        foreach ($this->get_available_updates() as $update) {
            if ($update['slug'] === $args->slug) {
                return (object)$update;
            }
        }
        return $false;
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

        foreach ($_POST['plugins'] ?? [] as $slug) {
            $this->perform_update(sanitize_text_field($slug));
        }

        wp_redirect(admin_url('tools.php?page=marrison-updater&bulk_updated=1'));
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
            [$this, 'admin_page']
        );
    }

    public function admin_page() {

        $updates   = $this->get_available_updates();
        $plugins   = get_plugins();
        $updated   = $_GET['updated'] ?? '';

        ?>
        <div class="wrap">
            <h1>Marrison Updater</h1>

            <?php if (isset($_GET['bulk_updated'])): ?>
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
                            <th><input type="checkbox" id="select-all"></th>
                            <th>Plugin</th>
                            <th>Versione</th>
                            <th>Azione</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php foreach ($updates as $u):
                        foreach ($plugins as $file => $data) {
                            $slug = dirname($file);
                            if ($slug === '.' || $slug === '') {
                                $slug = basename($file, '.php');
                            }

                            if ($slug === $u['slug'] && version_compare($data['Version'], $u['version'], '<')):
                    ?>
                        <tr>
                            <td><input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>"></td>
                            <td><?php echo esc_html($u['name']); ?></td>
                            <td><?php echo esc_html($data['Version'] . ' → ' . $u['version']); ?></td>
                            <td>
                                <?php if ($updated === $slug): ?>
                                    <strong style="color:green;">✓ Aggiornato</strong>
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
                const selectAll = document.getElementById('select-all');
                const checkboxes = document.querySelectorAll('input[name="plugins[]"]');

                if (selectAll) {
                    selectAll.addEventListener('change', function() {
                        checkboxes.forEach(function(checkbox) {
                            checkbox.checked = selectAll.checked;
                        });
                    });
                }
            });
        </script>
        <?php
    }
}

new Marrison_Custom_Updater;
