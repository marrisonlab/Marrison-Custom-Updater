<?php
trait MCU_Update_Operations_Trait {
    private function get_available_updates() {
        $custom_repo_url = get_option('marrison_repo_url');
        $repo_url = !empty($custom_repo_url) ? trailingslashit($custom_repo_url) : $this->updates_url;
        if (empty($repo_url)) return [];
        $cached = get_transient('marrison_available_updates_v2');
        if ($cached !== false && is_array($cached)) {
            $is_clean = true;
            foreach ($cached as $u) {
                if (isset($u['name']) && (strpos($u['name'], '/i\'') !== false || strpos($u['name'], '$') !== false)) {
                    $is_clean = false;
                    break;
                }
            }
            if ($is_clean) return $cached;
        }
        if (get_transient('marrison_updates_fetch_failed') !== false) {
            return [];
        }
        $response = wp_remote_get($repo_url . 'index.php', ['timeout' => 5]);
        if (is_wp_error($response)) {
            set_transient('marrison_updates_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return [];
        }
        $updates = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($updates)) {
            set_transient('marrison_updates_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return [];
        }
        $cleaned_updates = [];
        foreach ($updates as $u) {
            if (!isset($u['slug'])) continue;
            $u['slug'] = trim($u['slug']);
            if (isset($u['version'])) $u['version'] = trim($u['version']);
            if (isset($u['name'])) $u['name'] = trim($u['name']);
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) continue;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) continue;
            $cleaned_updates[] = $u;
        }
        $updates = $cleaned_updates;
        set_transient('marrison_available_updates_v2', $updates, $this->cache_duration);
        return $updates;
    }

    private function get_available_theme_updates() {
        $repo_url = get_option('marrison_themes_repo_url');
        if (empty($repo_url)) return [];
        $repo_url = trailingslashit($repo_url);
        $cached = get_transient('marrison_available_theme_updates');
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        if (get_transient('marrison_theme_updates_fetch_failed') !== false) {
            return [];
        }
        $response = wp_remote_get($repo_url . 'index.php', ['timeout' => 5]);
        if (is_wp_error($response)) {
            set_transient('marrison_theme_updates_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return [];
        }
        $updates = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($updates)) {
            set_transient('marrison_theme_updates_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return [];
        }
        $cleaned_updates = [];
        foreach ($updates as $u) {
            if (!isset($u['slug'])) continue;
            $u['slug'] = trim($u['slug']);
            if (isset($u['version'])) $u['version'] = trim($u['version']);
            if (isset($u['name'])) $u['name'] = trim($u['name']);
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) continue;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) continue;
            $cleaned_updates[] = $u;
        }
        $updates = $cleaned_updates;
        set_transient('marrison_available_theme_updates', $updates, $this->cache_duration);
        return $updates;
    }

    protected function is_item_excluded($slug, $type = 'plugin') {
        $option_name = ($type === 'theme') ? 'marrison_excluded_themes' : 'marrison_excluded_plugins';
        $excluded = get_option($option_name, []);
        if (!is_array($excluded)) $excluded = [];
        return in_array($slug, $excluded);
    }

    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // --- Filter out excluded plugins from ALL updates (including public repo) ---
        if (!empty($transient->response)) {
            $excluded_plugins = get_option('marrison_excluded_plugins', []);
            if (!empty($excluded_plugins)) {
                foreach ($transient->response as $plugin_file => $data) {
                    $slug = dirname($plugin_file);
                    if ($slug === '.' || $slug === '') $slug = basename($plugin_file, '.php');
                    
                    if (in_array($slug, $excluded_plugins)) {
                        unset($transient->response[$plugin_file]);
                    }
                }
            }
        }

        $updates = $this->get_available_updates();

        foreach ($updates as $update) {
            $slug = $update['slug'];
            
            if ($this->is_item_excluded($slug, 'plugin')) {
                continue;
            }

            $plugin_file = $this->find_plugin_file($slug, $update['name'] ?? '');

            if ($plugin_file && isset($transient->checked[$plugin_file])) {
                $current_version = $transient->checked[$plugin_file];
                
                if (version_compare($current_version, $update['version'], '<')) {
                    $plugin_data = new stdClass();
                    $plugin_data->slug = $slug;
                    $plugin_data->plugin = $plugin_file;
                    $plugin_data->new_version = $update['version'];
                    $plugin_data->url = $update['info_url'] ?? '';
                    $plugin_data->package = $update['download_url'];
                    $plugin_data->icons = isset($update['icons']) ? (array)$update['icons'] : [];
                    $plugin_data->banners = isset($update['banners']) ? (array)$update['banners'] : [];
                    $plugin_data->banners_rtl = isset($update['banners_rtl']) ? (array)$update['banners_rtl'] : [];
                    
                    $transient->response[$plugin_file] = $plugin_data;
                }
            }
        }
        return $transient;
    }

    public function check_for_theme_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // --- Filter out excluded themes from ALL updates (including public repo) ---
        if (!empty($transient->response)) {
            $excluded_themes = get_option('marrison_excluded_themes', []);
            if (!empty($excluded_themes)) {
                foreach ($excluded_themes as $excluded_slug) {
                    if (isset($transient->response[$excluded_slug])) {
                        unset($transient->response[$excluded_slug]);
                    }
                }
            }
        }

        $updates = $this->get_available_theme_updates();

        foreach ($updates as $update) {
            $slug = $update['slug'];

            if ($this->is_item_excluded($slug, 'theme')) {
                continue;
            }

            $theme = wp_get_theme($slug);

            if ($theme->exists()) {
                $current_version = $theme->get('Version');
                if (version_compare($current_version, $update['version'], '<')) {
                    $theme_data = [];
                    $theme_data['theme'] = $slug;
                    $theme_data['new_version'] = $update['version'];
                    $theme_data['url'] = $update['info_url'] ?? '';
                    $theme_data['package'] = $update['download_url'];
                    
                    $transient->response[$slug] = $theme_data;
                }
            }
        }
        return $transient;
    }

    public function plugin_info($res, $action, $args) {
        if ($action !== 'plugin_information') {
            return $res;
        }

        if (empty($args->slug)) {
            return $res;
        }

        $updates = $this->get_available_updates();
        foreach ($updates as $update) {
            if ($update['slug'] === $args->slug) {
                $res = new stdClass();
                $res->name = $update['name'];
                $res->slug = $update['slug'];
                $res->version = $update['version'];
                $res->tested = $update['tested'] ?? '';
                $res->requires = $update['requires'] ?? '';
                $res->author = $update['author'] ?? '';
                $res->author_profile = $update['author_profile'] ?? '';
                $res->download_link = $update['download_url'];
                $res->trunk = $update['download_url'];
                $res->requires_php = $update['requires_php'] ?? '';
                $res->last_updated = $update['last_updated'] ?? '';
                $res->sections = [
                    'description' => $update['description'] ?? 'No description provided.',
                    'installation' => $update['installation'] ?? 'No installation instructions provided.',
                    'changelog' => $update['changelog'] ?? 'No changelog provided.'
                ];
                $res->banners = isset($update['banners']) ? (array)$update['banners'] : [];
                return $res;
            }
        }

        return $res;
    }

    private function find_plugin_file($slug, $name = '') {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        
        // 1. Cerca per dirname (cartella dello slug)
        foreach ($all_plugins as $file => $data) {
            if (dirname($file) === $slug) {
                return $file;
            }
        }
        
        // 2. Cerca per nome esatto (se fornito)
        if (!empty($name)) {
            foreach ($all_plugins as $file => $data) {
                if ($data['Name'] === $name) {
                    return $file;
                }
            }
        }

        // 3. Fallback: cerca se il file inizia con lo slug
        foreach ($all_plugins as $file => $data) {
            if (strpos($file, $slug . '/') === 0 || $file === $slug . '.php') {
                return $file;
            }
        }

        return false;
    }
    
    public function delete_internal_cache() {
        delete_transient('marrison_available_updates_v2');
        delete_transient('marrison_available_theme_updates');
    }


    private function perform_update($slug) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        if (!$wp_filesystem) return new WP_Error('fs_init_failed', __('Impossibile inizializzare il filesystem.', 'marrison-custom-updater'));
        foreach ($this->get_available_updates() as $update) {
            if ($update['slug'] !== $slug) continue;
            $zip = download_url($update['download_url']);
            if (is_wp_error($zip)) return $zip;
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
            $this->create_backup($slug, $current_version, 'plugin', $plugin_file);
            $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-' . $slug;
            wp_mkdir_p($upgrade_dir);
            $unzip = unzip_file($zip, $upgrade_dir);
            unlink($zip);
            
            if (is_wp_error($unzip)) {
                return $unzip;
            }

            $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
            if (empty($dirs)) {
                return new WP_Error('empty_archive', __('Archivio vuoto o non valido.', 'marrison-custom-updater'));
            } 
            $source = trailingslashit($dirs[0]);
            $dest_folder = $slug;
            if ($plugin_file) {
                $installed_dir = dirname($plugin_file);
                if ($installed_dir !== '.' && $installed_dir !== '') {
                    $dest_folder = $installed_dir;
                }
            }
            $dest      = trailingslashit(WP_PLUGIN_DIR . '/' . $dest_folder);
            $dest_temp = WP_PLUGIN_DIR . '/' . $dest_folder . '-marrison-new-' . time();
            $dest_old  = WP_PLUGIN_DIR . '/' . $dest_folder . '-marrison-old-' . time();

            // 1. Copy new files to a temporary directory first (no destructive action yet)
            $result = copy_dir($source, $dest_temp);
            $wp_filesystem->delete($upgrade_dir, true);

            if (is_wp_error($result)) {
                $wp_filesystem->delete($dest_temp, true);
                return $result;
            }

            // 2. Atomic swap: rename old → backup, new → final
            $old_exists = $wp_filesystem->is_dir($dest);
            if ($old_exists) {
                if (!rename(untrailingslashit($dest), $dest_old)) {
                    $wp_filesystem->delete($dest_temp, true);
                    return new WP_Error('rename_old_failed', __('Impossibile rinominare la directory del plugin esistente.', 'marrison-custom-updater'));
                }
            }

            if (!rename($dest_temp, untrailingslashit($dest))) {
                // Restore old directory before returning error
                if ($old_exists && is_dir($dest_old)) {
                    rename($dest_old, untrailingslashit($dest));
                }
                $wp_filesystem->delete($dest_temp, true);
                return new WP_Error('rename_new_failed', __('Impossibile spostare la nuova versione del plugin.', 'marrison-custom-updater'));
            }

            // 3. Delete the old backup directory
            if ($old_exists && is_dir($dest_old)) {
                $wp_filesystem->delete($dest_old, true);
            }
            
            delete_site_transient('update_plugins');
            wp_clean_plugins_cache(true);
            update_option('marrison_last_plugins_update_time', current_time('mysql'));
            return true;
        }
        return new WP_Error('update_not_found', __('Aggiornamento non trovato.', 'marrison-custom-updater'));
    }

    private function perform_self_update($download_url) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        if (!$wp_filesystem) return new WP_Error('fs_error', 'Filesystem init failed');
        
        $zip = download_url($download_url);
        if (is_wp_error($zip)) return $zip;
        
        $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-custom-updater-temp';
        $wp_filesystem->delete($upgrade_dir, true); // Clean previous attempts
        wp_mkdir_p($upgrade_dir);
        
        $unzip = unzip_file($zip, $upgrade_dir);
        @unlink($zip);
        
        if (is_wp_error($unzip)) return $unzip;
        
        $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) {
             $wp_filesystem->delete($upgrade_dir, true);
             return new WP_Error('empty_zip', 'Zip archive is empty or invalid structure');
        }
        
        $source = trailingslashit($dirs[0]);
        $dest   = trailingslashit(WP_PLUGIN_DIR . '/marrison-custom-updater');
        
        // Strategy: Move current to backup, copy new, if fail restore backup
        $backup_dest = WP_PLUGIN_DIR . '/marrison-custom-updater-backup-' . time();
        $moved_backup = false;
        
        if ($wp_filesystem->is_dir($dest)) {
            // Try to move to backup
            if (!$wp_filesystem->move($dest, $backup_dest)) {
                // If move fails, try direct delete (fallback, risky but standard)
                // But better to fail safe if we can't backup
                // Let's try to proceed with delete if move failed? 
                // No, let's try copy_dir first to a temp dest? 
                // Actually, standard WP way is Maintenance mode + delete + copy.
                // But we want to avoid deactivation.
                // Let's try standard delete if move fails, but log it?
                // For now, let's assume move works or fail.
                $wp_filesystem->delete($upgrade_dir, true);
                return new WP_Error('backup_failed', 'Could not backup existing version');
            }
            $moved_backup = true;
        }
        
        $result = copy_dir($source, $dest);
        
        if (is_wp_error($result)) {
            // Restore backup
            if ($moved_backup) {
                $wp_filesystem->move($backup_dest, $dest);
            }
            $wp_filesystem->delete($upgrade_dir, true);
            return $result;
        }
        
        // Success
        if ($moved_backup) {
            $wp_filesystem->delete($backup_dest, true);
        }
        $wp_filesystem->delete($upgrade_dir, true);
        
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);
        
        return true;
    }

    private function get_backup_dir() {
        $dir = WP_CONTENT_DIR . '/marrison-backups';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/index.php', '<?php // Silence is golden');
            file_put_contents($dir . '/.htaccess', 'deny from all');
        }
        return $dir;
    }

    private function cleanup_orphan_plugin_backups() {
        $backup_dir = $this->get_backup_dir();
        if (!is_dir($backup_dir)) {
            return;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed_slugs = [];
        foreach (array_keys(get_plugins()) as $plugin_file) {
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
                $installed_slugs[] = basename($plugin_file, '.php');
            } else {
                $installed_slugs[] = $plugin_dir;
            }
        }
        $installed_slugs = array_values(array_unique(array_filter($installed_slugs)));

        foreach (glob($backup_dir . '/*-backup.zip') as $file) {
            $filename = basename($file);
            $is_plugin_backup = true;
            $parse_name = $filename;

            if (strpos($filename, 'theme-') === 0) {
                continue;
            }

            if (strpos($filename, 'plugin-') === 0) {
                $parse_name = substr($filename, 7);
            }

            $slug = '';
            if (preg_match('/^(.*?)-v.*-backup\.zip$/', $parse_name, $matches)) {
                $slug = $matches[1];
            } elseif (preg_match('/^(.*?)-backup\.zip$/', $parse_name, $matches)) {
                $slug = $matches[1];
            }

            if (!$is_plugin_backup || empty($slug)) {
                continue;
            }

            if (!in_array($slug, $installed_slugs, true)) {
                @unlink($file);
            }
        }
    }

    private function create_backup($slug, $version = '', $type = 'plugin', $known_file = '') {
        $source = '';
        $backup_slug = $slug;
        if ($type === 'plugin') {
            $plugin_file = $known_file ? $known_file : $this->find_plugin_file($slug);
            if (!$plugin_file) return false;
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
                $source = WP_PLUGIN_DIR . '/' . $plugin_file;
                $backup_slug = basename($plugin_file, '.php');
            } else {
                $source = WP_PLUGIN_DIR . '/' . $plugin_dir;
                $backup_slug = $plugin_dir;
            }
        } else {
            $theme = wp_get_theme($slug);
            if (!$theme->exists()) return false;
            $source = get_theme_root() . '/' . $slug;
        }
        if (!file_exists($source)) return false;
        if (empty($backup_slug)) return false;
        $backup_dir = $this->get_backup_dir();
        $pattern = $backup_dir . '/' . $type . '-' . $backup_slug . '-*-backup.zip';
        foreach (glob($pattern) as $f) @unlink($f);
        if ($type === 'plugin') {
            foreach (glob($backup_dir . '/' . $backup_slug . '-*-backup.zip') as $f) @unlink($f);
            if ($backup_slug !== $slug) {
                foreach (glob($backup_dir . '/' . $slug . '-*-backup.zip') as $f) @unlink($f);
                foreach (glob($backup_dir . '/plugin-' . $slug . '-*-backup.zip') as $f) @unlink($f);
            }
        }
        $date = date('Ymd');
        $time = date('His');
        $ver_str = $version ? $version : 'na';
        $filename = sprintf('%s-%s-v%s-%s-%s-backup.zip', $type, $backup_slug, $ver_str, $date, $time);
        $zip_file = $backup_dir . '/' . $filename;
        if (file_exists($zip_file)) @unlink($zip_file);
        if (!class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }
        $archive = new PclZip($zip_file);
        $remove_path = ($type === 'theme') ? get_theme_root() : WP_PLUGIN_DIR;
        $v_list = $archive->create($source, PCLZIP_OPT_REMOVE_PATH, $remove_path);
        return ($v_list != 0);
    }

    private function perform_restore($filename) {
        try {
            $backup_dir = $this->get_backup_dir();
            $zip_file = $backup_dir . '/' . $filename;
            if (!file_exists($zip_file)) {
                return new WP_Error('not_found', 'Backup not found');
            }
            $type = 'plugin';
            $slug = '';
            if (strpos($filename, 'theme-') === 0) {
                $type = 'theme';
                $remaining = substr($filename, 6);
                if (preg_match('/^(.*?)-v.*-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                } elseif (preg_match('/^(.*?)-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                }
            } elseif (strpos($filename, 'plugin-') === 0) {
                $type = 'plugin';
                $remaining = substr($filename, 7);
                if (preg_match('/^(.*?)-v.*-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                } elseif (preg_match('/^(.*?)-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                }
            } else {
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
            if ( ! WP_Filesystem() ) {
                return new WP_Error('fs_error', 'Filesystem error - Could not initialize');
            }
            if (!$wp_filesystem) {
                return new WP_Error('fs_error', 'Filesystem error - Object is null');
            }

            // Check if plugin is active before deleting
            $was_active = false;
            if ($type === 'plugin') {
                if (!function_exists('is_plugin_active')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $current_file = $this->find_plugin_file($slug);
                if ($current_file && is_plugin_active($current_file)) {
                    $was_active = true;
                }
            }

            $dest_root = ($type === 'theme') ? get_theme_root() : WP_PLUGIN_DIR;
            $dest = $dest_root . '/' . $slug;
            if (realpath($dest) === realpath($dest_root)) {
                return new WP_Error('invalid_dest', 'Destination invalid');
            }
            if ($wp_filesystem->is_dir($dest)) {
                $deleted = $wp_filesystem->delete($dest, true);
                if (!$deleted) {
                    $trash_dir = $dest_root . '/.' . $slug . '_trash_' . time();
                    if ($wp_filesystem->move($dest, $trash_dir)) {
                        $wp_filesystem->delete($trash_dir, true);
                    }
                }
            } elseif ($type === 'plugin') {
                $current_file = $this->find_plugin_file($slug);
                if ($current_file && dirname($current_file) === '.') {
                    $single_file_dest = WP_PLUGIN_DIR . '/' . $current_file;
                    if ($wp_filesystem->exists($single_file_dest)) {
                        $wp_filesystem->delete($single_file_dest);
                    }
                }
            }
            $result = unzip_file($zip_file, $dest_root);
            if (is_wp_error($result)) {
                return $result;
            }
            if ($type === 'theme') {
                delete_site_transient('update_themes');
                wp_clean_themes_cache(true);
            } else {
                delete_site_transient('update_plugins');
                wp_clean_plugins_cache(true);

                if ($was_active) {
                    $new_file = $this->find_plugin_file($slug);
                    if ($new_file) {
                        activate_plugin($new_file);
                    }
                }
            }
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

    private function perform_theme_update($slug, $download_url) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        if (!$wp_filesystem) return false;
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

    public function create_db_backup() {
        global $wpdb;

        $backup_dir   = $this->get_backup_dir();
        $date         = date('Ymd');
        $time         = date('His');
        $sql_filename = 'db-backup-' . $date . '-' . $time . '.sql';
        $zip_filename = 'db-backup-' . $date . '-' . $time . '.zip';
        $sql_path     = $backup_dir . '/' . $sql_filename;
        $zip_path     = $backup_dir . '/' . $zip_filename;

        $snapshot_started = false;
        $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if ($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') !== false) {
            $snapshot_started = true;
        }

        $tables = $wpdb->get_col('SHOW TABLES');
        if (empty($tables)) {
            if ($snapshot_started) {
                $wpdb->query('COMMIT');
            }
            return false;
        }

        sort($tables);

        $handle = fopen($sql_path, 'w');
        if (!$handle) {
            if ($snapshot_started) {
                $wpdb->query('COMMIT');
            }
            return false;
        }

        fwrite($handle, "-- phpMyAdmin SQL Dump\n");
        fwrite($handle, "-- version 5.2.1\n");
        fwrite($handle, "-- https://www.phpmyadmin.net/\n");
        fwrite($handle, "--\n");
        fwrite($handle, "-- Host: " . $wpdb->dbhost . "\n");
        fwrite($handle, "-- Generation Time: " . date('r') . "\n");
        fwrite($handle, "-- Server version: " . $wpdb->db_version() . "\n");
        fwrite($handle, "-- PHP Version: " . phpversion() . "\n");
        fwrite($handle, "--\n");
        fwrite($handle, "-- Database: `" . DB_NAME . "`\n");
        fwrite($handle, "--\n\n");

        fwrite($handle, "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n");
        fwrite($handle, "/*!40103 SET TIME_ZONE='+00:00' */;\n");
        fwrite($handle, "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n");
        fwrite($handle, "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n");
        fwrite($handle, "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n");
        fwrite($handle, "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
        fwrite($handle, "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
        fwrite($handle, "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
        fwrite($handle, "/*!40101 SET NAMES utf8mb4 */;\n\n");
        fwrite($handle, "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n");
        fwrite($handle, "START TRANSACTION;\n\n");

        foreach ($tables as $table) {
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if (!$create) continue;

            $create_sql = $create[1];
            $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
            $expected_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            $dumped_rows = 0;
            $order_clause = $this->get_db_backup_order_clause($wpdb, $table);

            $auto_increment_columns = array_filter($columns, function($col) {
                return isset($col['Extra']) && stripos($col['Extra'], 'auto_increment') !== false;
            });
            if (!empty($auto_increment_columns) && stripos($create_sql, 'AUTO_INCREMENT') === false) {
                fclose($handle);
                if ($snapshot_started) {
                    $wpdb->query('COMMIT');
                }
                @unlink($sql_path);
                return new WP_Error('missing_auto_increment', sprintf(__('Schema non valido per la tabella %s: AUTO_INCREMENT mancante.', 'marrison-custom-updater'), $table));
            }

            fwrite($handle, "--\n-- Table structure for table `{$table}`\n--\n\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($handle, "/*!40101 SET @saved_cs_client     = @@character_set_client */;\n");
            fwrite($handle, "/*!40101 SET character_set_client = utf8mb4 */;\n");
            fwrite($handle, $create_sql . ";\n");
            fwrite($handle, "/*!40101 SET character_set_client = @saved_cs_client */;\n\n");

            $column_names = array_map(function($col) {
                return '`' . str_replace('`', '``', $col['Field']) . '`';
            }, $columns);
            $numeric_cols = [];
            foreach ($columns as $col) {
                $numeric_cols[$col['Field']] = (bool) preg_match(
                    '/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal|numeric|real|bit|year)/i',
                    $col['Type']
                );
            }

            $offset = 0;
            $batch  = 500;
            $insert_prefix = "INSERT INTO `{$table}` (" . implode(', ', $column_names) . ") VALUES\n";
            $insert_chunk_limit = 1024 * 1024;
            while (true) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `{$table}`{$order_clause} LIMIT %d OFFSET %d", $batch, $offset),
                    ARRAY_A
                );
                if (empty($rows)) break;

                $value_rows = [];
                $current_insert_size = strlen($insert_prefix);
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($columns as $col) {
                        $field = $col['Field'];
                        $val = array_key_exists($field, $row) ? $row[$field] : null;
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } elseif (!empty($numeric_cols[$field]) && is_numeric($val)) {
                            $vals[] = $val;
                        } else {
                            $vals[] = "'" . $this->escape_for_sql($wpdb, (string) $val) . "'";
                        }
                    }
                    $tuple = '(' . implode(', ', $vals) . ')';
                    $tuple_size = strlen($tuple) + 2;
                    if (!empty($value_rows) && ($current_insert_size + $tuple_size) > $insert_chunk_limit) {
                        fwrite($handle, $insert_prefix . implode(",\n", $value_rows) . ";\n");
                        $value_rows = [];
                        $current_insert_size = strlen($insert_prefix);
                    }
                    $value_rows[] = $tuple;
                    $current_insert_size += $tuple_size;
                }

                if (!empty($value_rows)) {
                    fwrite($handle, $insert_prefix . implode(",\n", $value_rows) . ";\n");
                }

                $offset += $batch;
                $dumped_rows += count($rows);
                if (count($rows) < $batch) break;
            }

            if ($dumped_rows !== $expected_rows) {
                fclose($handle);
                if ($snapshot_started) {
                    $wpdb->query('COMMIT');
                }
                @unlink($sql_path);
                return new WP_Error(
                    'db_backup_row_count_mismatch',
                    sprintf(__('Backup database non valido per la tabella %1$s: attese %2$d righe, scritte %3$d.', 'marrison-custom-updater'), $table, $expected_rows, $dumped_rows)
                );
            }

            fwrite($handle, "-- --------------------------------------------------------\n\n");
        }

        fwrite($handle, "COMMIT;\n\n");
        fwrite($handle, "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n");
        fwrite($handle, "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n");
        fwrite($handle, "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n");
        fwrite($handle, "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n");
        fwrite($handle, "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
        fwrite($handle, "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
        fwrite($handle, "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
        fwrite($handle, "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n");
        fclose($handle);
        if ($snapshot_started) {
            $wpdb->query('COMMIT');
        }

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $opened = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $result = false;
            if ($opened === true) {
                $added = $zip->addFile($sql_path, $sql_filename);
                $closed = $zip->close();
                $result = $added && $closed;
            }
        } else {
            if (!class_exists('PclZip')) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }
            $archive = new PclZip($zip_path);
            $result  = $archive->create($sql_path, PCLZIP_OPT_REMOVE_PATH, $backup_dir);
        }
        @unlink($sql_path);

        if (!$result) return false;

        $db_backups = glob($backup_dir . '/db-backup-*.zip');
        if (is_array($db_backups) && count($db_backups) > 3) {
            usort($db_backups, function($a, $b) { return filemtime($a) - filemtime($b); });
            foreach (array_slice($db_backups, 0, count($db_backups) - 3) as $f) @unlink($f);
        }

        return $zip_filename;
    }

    private function get_db_backup_order_clause($wpdb, $table) {
        $keys = $wpdb->get_results("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if (empty($keys)) {
            return '';
        }

        usort($keys, function($a, $b) {
            return (int) $a['Seq_in_index'] - (int) $b['Seq_in_index'];
        });

        $columns = [];
        foreach ($keys as $key) {
            if (!empty($key['Column_name'])) {
                $columns[] = '`' . str_replace('`', '``', $key['Column_name']) . '`';
            }
        }

        return empty($columns) ? '' : ' ORDER BY ' . implode(', ', $columns);
    }

    public function create_files_backup() {
        $state = $this->init_files_backup_job();
        if (is_wp_error($state)) {
            return $state;
        }

        do {
            $running_state = $state;
            $state = $this->process_files_backup_job($state);
            if (is_wp_error($state)) {
                $this->cleanup_files_backup_job_artifacts($running_state);
                return $state;
            }
        } while ($state['status'] !== 'complete');

        update_option('marrison_last_files_backup_skipped', [
            'count' => (int) ($state['skipped_count'] ?? 0),
            'bytes' => (int) ($state['skipped_bytes'] ?? 0),
            'files' => $state['skipped_files'] ?? [],
            'time' => time(),
        ], false);

        return count($state['parts']) === 1 ? $state['parts'][0] : $state['parts'];
    }

    private function create_files_backup_tar_gz($backup_dir, $date, $time, $root_path) {
        $archive_filename = 'files-backup-' . $date . '-' . $time . '.tar.gz';
        $archive_path     = $backup_dir . '/' . $archive_filename;

        if (!function_exists('gzopen')) {
            return new WP_Error(
                'zlib_missing',
                __('Backup file non disponibile: abilita l\'estensione PHP zlib sul server per creare archivi tar.gz.', 'marrison-custom-updater')
            );
        }

        if (!is_dir($root_path) || !is_writable($backup_dir)) {
            return new WP_Error('backup_dir_not_writable', __('Directory backup non scrivibile.', 'marrison-custom-updater'));
        }

        if (file_exists($archive_path)) {
            @unlink($archive_path);
        }

        $handle = @gzopen($archive_path, 'wb1');
        if (!$handle) {
            return new WP_Error('targz_open_failed', __('Impossibile creare il file tar.gz del backup.', 'marrison-custom-updater'));
        }

        $stats = ['files' => 0, 'dirs' => 0, 'bytes' => 0];
        $result = $this->add_files_to_backup_tar_gz($handle, $root_path, $root_path, $archive_path, $stats);
        if (!is_wp_error($result) && !$this->write_tar_data($handle, str_repeat("\0", 1024))) {
            $result = new WP_Error('targz_write_failed', __('Errore durante la chiusura dell\'archivio tar.gz.', 'marrison-custom-updater'));
        }

        $closed = @gzclose($handle);

        if (is_wp_error($result) || !$closed || !file_exists($archive_path) || $stats['files'] === 0) {
            if (file_exists($archive_path)) {
                @unlink($archive_path);
            }

            return is_wp_error($result)
                ? $result
                : new WP_Error('targz_create_failed', __('Il backup file non è stato creato correttamente.', 'marrison-custom-updater'));
        }

        $file_backups = array_merge(
            glob($backup_dir . '/files-backup-*.tar.gz') ?: [],
            glob($backup_dir . '/files-backup-*.zip') ?: []
        );
        if (count($file_backups) > 3) {
            usort($file_backups, function($a, $b) { return filemtime($a) - filemtime($b); });
            foreach (array_slice($file_backups, 0, count($file_backups) - 3) as $f) @unlink($f);
        }

        return $archive_filename;
    }

    private function get_files_backup_part_limit() {
        if (defined('MCU_FILES_BACKUP_PART_LIMIT')) {
            return max(1024 * 1024, (int) MCU_FILES_BACKUP_PART_LIMIT);
        }

        return 850 * 1024 * 1024;
    }

    private function get_files_backup_job_key($job_id) {
        return 'mcu_files_backup_job_' . sanitize_key($job_id);
    }

    private function save_files_backup_job($state) {
        set_transient($this->get_files_backup_job_key($state['job_id']), $state, DAY_IN_SECONDS);
    }

    private function load_files_backup_job($job_id) {
        $state = get_transient($this->get_files_backup_job_key($job_id));
        return is_array($state) ? $state : false;
    }

    private function delete_files_backup_job($state) {
        if (!empty($state['manifest_path']) && file_exists($state['manifest_path'])) {
            @unlink($state['manifest_path']);
        }
        if (!empty($state['job_id'])) {
            delete_transient($this->get_files_backup_job_key($state['job_id']));
        }
    }

    private function cleanup_files_backup_job_artifacts($state) {
        if (!empty($state['backup_dir']) && !empty($state['prefix'])) {
            foreach (glob(trailingslashit($state['backup_dir']) . $state['prefix'] . '-part*.tar.gz*') ?: [] as $file) {
                @unlink($file);
            }
        }
        $this->delete_files_backup_job($state);
    }

    private function init_files_backup_job() {
        $backup_dir = $this->get_backup_dir();
        $root_path  = wp_normalize_path(untrailingslashit(ABSPATH));
        $date       = date('Ymd');
        $time       = date('His');
        $prefix     = 'files-backup-' . $date . '-' . $time;
        $job_id     = wp_generate_password(12, false, false);
        $manifest   = $backup_dir . '/' . $prefix . '-' . $job_id . '.manifest.tmp';

        if (!function_exists('gzopen')) {
            return new WP_Error(
                'zlib_missing',
                __('Backup file non disponibile: abilita l\'estensione PHP zlib sul server per creare archivi tar.gz.', 'marrison-custom-updater')
            );
        }

        if (!is_dir($root_path) || !is_writable($backup_dir)) {
            return new WP_Error('backup_dir_not_writable', __('Directory backup non scrivibile.', 'marrison-custom-updater'));
        }

        $scan = $this->scan_files_backup_manifest($root_path, $backup_dir, $manifest);
        if (is_wp_error($scan)) {
            if (file_exists($manifest)) {
                @unlink($manifest);
            }
            return $scan;
        }

        $state = [
            'job_id' => $job_id,
            'prefix' => $prefix,
            'backup_dir' => $backup_dir,
            'root_path' => $root_path,
            'manifest_path' => $manifest,
            'manifest_offset' => 0,
            'total_entries' => $scan['entries'],
            'total_files' => $scan['files'],
            'total_bytes' => $scan['bytes'],
            'skipped_count' => $scan['skipped_count'],
            'skipped_bytes' => $scan['skipped_bytes'],
            'skipped_files' => $scan['skipped_files'],
            'processed_entries' => 0,
            'processed_files' => 0,
            'processed_bytes' => 0,
            'current_part' => 1,
            'current_part_entries' => 0,
            'parts' => [],
            'status' => 'running',
            'created_at' => time(),
            'part_limit' => $this->get_files_backup_part_limit(),
        ];
        $this->save_files_backup_job($state);

        return $state;
    }

    private function scan_files_backup_manifest($root_path, $backup_dir, $manifest_path) {
        $handle = @fopen($manifest_path, 'wb');
        if (!$handle) {
            return new WP_Error('backup_manifest_failed', __('Impossibile creare il manifest del backup file.', 'marrison-custom-updater'));
        }

        $stats = ['entries' => 0, 'files' => 0, 'bytes' => 0, 'skipped_count' => 0, 'skipped_bytes' => 0, 'skipped_files' => []];
        $stack = [$root_path];
        $part_limit = $this->get_files_backup_part_limit();
        $skip_large_files = get_option('marrison_files_backup_skip_large_files') === 'yes';

        while (!empty($stack)) {
            $dir = array_pop($stack);
            $items = @scandir($dir);
            if (!is_array($items)) {
                $relative_dir = ltrim(str_replace($root_path, '', wp_normalize_path($dir)), '/\\');
                $this->record_files_backup_skipped($stats, $relative_dir ?: $dir, 0, 'directory_not_readable');
                continue;
            }

            sort($items);
            for ($i = count($items) - 1; $i >= 0; $i--) {
                $item = $items[$i];
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $path = $dir . '/' . $item;
                if (is_link($path) || $this->is_excluded_from_files_backup($path, $manifest_path)) {
                    continue;
                }

                $relative_path = ltrim(str_replace($root_path, '', wp_normalize_path($path)), '/\\');
                if ($relative_path === '') {
                    continue;
                }

                if (is_dir($path)) {
                    $entry = [
                        'type' => 'dir',
                        'path' => $path,
                        'rel' => $relative_path,
                        'mtime' => @filemtime($path) ?: time(),
                        'mode' => @fileperms($path) ?: 0755,
                        'size' => 0,
                    ];
                    fwrite($handle, wp_json_encode($entry) . "\n");
                    $stats['entries']++;
                    $stack[] = $path;
                    continue;
                }

                if (!is_file($path)) {
                    continue;
                }

                if (!is_readable($path)) {
                    $this->record_files_backup_skipped($stats, $relative_path, 0, 'file_not_readable');
                    continue;
                }

                $size = @filesize($path);
                if ($size === false) {
                    $this->record_files_backup_skipped($stats, $relative_path, 0, 'file_size_unavailable');
                    continue;
                }

                $part_margin = min(2 * 1024 * 1024, max(128 * 1024, (int) floor($part_limit * 0.05)));
                if ($size > ($part_limit - $part_margin)) {
                    if ($skip_large_files) {
                        $this->record_files_backup_skipped($stats, $relative_path, $size, 'file_too_large');
                        continue;
                    }

                    fclose($handle);
                    return new WP_Error(
                        'backup_single_file_too_large',
                        sprintf(__('File troppo grande per il limite del singolo archivio (%1$s): %2$s', 'marrison-custom-updater'), size_format($part_limit), $relative_path)
                    );
                }

                $entry = [
                    'type' => 'file',
                    'path' => $path,
                    'rel' => $relative_path,
                    'mtime' => @filemtime($path) ?: time(),
                    'mode' => @fileperms($path) ?: 0644,
                    'size' => $size,
                ];
                fwrite($handle, wp_json_encode($entry) . "\n");
                $stats['entries']++;
                $stats['files']++;
                $stats['bytes'] += $size;
            }
        }

        fclose($handle);
        if ($stats['files'] === 0) {
            return new WP_Error('backup_empty', __('Nessun file leggibile trovato per il backup.', 'marrison-custom-updater'));
        }

        return $stats;
    }

    private function process_files_backup_job($state) {
        $deadline = time() + 8;
        $handle = @fopen($state['manifest_path'], 'rb');
        if (!$handle) {
            return new WP_Error('backup_manifest_missing', __('Manifest del backup non trovato.', 'marrison-custom-updater'));
        }
        fseek($handle, (int) $state['manifest_offset']);

        while (!feof($handle) && time() < $deadline) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }

            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                fclose($handle);
                return new WP_Error('backup_manifest_invalid', __('Manifest del backup non valido.', 'marrison-custom-updater'));
            }

            $result = $this->write_files_backup_job_entry($state, $entry);
            if (is_wp_error($result)) {
                fclose($handle);
                return $result;
            }

            $state['processed_entries']++;
            if ($entry['type'] === 'file') {
                $state['processed_files']++;
                $state['processed_bytes'] += (int) $entry['size'];
            }
            $state['manifest_offset'] = ftell($handle);
        }

        $complete = feof($handle);
        fclose($handle);

        if ($complete) {
            $result = $this->finalize_files_backup_job_part($state);
            if (is_wp_error($result)) {
                return $result;
            }
            $state['status'] = 'complete';
            $this->cleanup_files_backup_sets($state['backup_dir'], 3);
            $this->delete_files_backup_job($state);
        } else {
            $this->save_files_backup_job($state);
        }

        return $state;
    }

    private function record_files_backup_skipped(&$state, $relative_path, $size = 0, $reason = '') {
        $state['skipped_count'] = (int) ($state['skipped_count'] ?? 0) + 1;
        $state['skipped_bytes'] = (int) ($state['skipped_bytes'] ?? 0) + max(0, (int) $size);
        if (!isset($state['skipped_files']) || !is_array($state['skipped_files'])) {
            $state['skipped_files'] = [];
        }
        if (count($state['skipped_files']) < 50) {
            $state['skipped_files'][] = [
                'path' => $relative_path,
                'size' => max(0, (int) $size),
                'reason' => $reason,
            ];
        }
    }

    private function write_files_backup_job_entry(&$state, $entry) {
        $part_path = $this->get_files_backup_job_part_path($state);
        $entry_size = ($entry['type'] === 'file') ? (int) $entry['size'] : 0;

        $part_margin = min(2 * 1024 * 1024, max(128 * 1024, (int) floor(((int) $state['part_limit']) * 0.05)));
        if ($state['current_part_entries'] > 0 && file_exists($part_path) && (filesize($part_path) + $entry_size + $part_margin) > (int) $state['part_limit']) {
            $result = $this->finalize_files_backup_job_part($state);
            if (is_wp_error($result)) {
                return $result;
            }
            $state['current_part']++;
            $state['current_part_entries'] = 0;
            $part_path = $this->get_files_backup_job_part_path($state);
        }

        if ($entry['type'] === 'file') {
            if (!file_exists($entry['path']) || !is_file($entry['path']) || !is_readable($entry['path'])) {
                $this->record_files_backup_skipped($state, $entry['rel'], (int) $entry['size'], 'file_missing_or_not_readable');
                return true;
            }

            $current_size = @filesize($entry['path']);
            if ($current_size === false || (int) $current_size !== (int) $entry['size']) {
                $this->record_files_backup_skipped($state, $entry['rel'], (int) $entry['size'], 'file_changed_during_backup');
                return true;
            }
        }

        $mode = file_exists($part_path) ? 'ab1' : 'wb1';
        $handle = @gzopen($part_path, $mode);
        if (!$handle) {
            return new WP_Error('targz_open_failed', __('Impossibile aprire una parte tar.gz del backup.', 'marrison-custom-updater'));
        }

        if ($entry['type'] === 'dir') {
            $ok = $this->write_tar_header($handle, rtrim($entry['rel'], '/') . '/', 0, (int) $entry['mtime'], '5', (int) $entry['mode']);
            @gzclose($handle);
            if (!$ok) {
                return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura della directory: %s', 'marrison-custom-updater'), $entry['rel']));
            }
            $state['current_part_entries']++;
            return true;
        }

        $ok = $this->write_tar_header($handle, $entry['rel'], (int) $entry['size'], (int) $entry['mtime'], '0', (int) $entry['mode']);
        if ($ok) {
            $ok = $this->write_file_to_tar_gz_handle($handle, $entry['path'], $entry['rel'], (int) $entry['size']);
        }
        @gzclose($handle);

        if (is_wp_error($ok)) {
            $this->record_files_backup_skipped($state, $entry['rel'], (int) $entry['size'], 'file_read_failed');
            return true;
        }
        if (!$ok) {
            $this->record_files_backup_skipped($state, $entry['rel'], (int) $entry['size'], 'file_write_failed');
            return true;
        }

        $state['current_part_entries']++;
        return true;
    }

    private function write_file_to_tar_gz_handle($handle, $path, $relative_path, $size) {
        $file_handle = @fopen($path, 'rb');
        if (!$file_handle) {
            return new WP_Error('backup_file_open_failed', sprintf(__('Impossibile aprire il file durante il backup: %s', 'marrison-custom-updater'), $relative_path));
        }

        $written = 0;
        while ($written < $size && !feof($file_handle)) {
            $remaining = $size - $written;
            $chunk = fread($file_handle, min(1024 * 1024, $remaining));
            if ($chunk === false) {
                fclose($file_handle);
                return new WP_Error('backup_file_read_failed', sprintf(__('Errore durante la lettura del file: %s', 'marrison-custom-updater'), $relative_path));
            }
            if ($chunk === '') {
                continue;
            }
            if (!$this->write_tar_data($handle, $chunk)) {
                fclose($file_handle);
                return false;
            }
            $written += strlen($chunk);
        }
        fclose($file_handle);

        $padding = (512 - ($written % 512)) % 512;
        if ($padding > 0 && !$this->write_tar_data($handle, str_repeat("\0", $padding))) {
            return false;
        }

        return $written === $size;
    }

    private function get_files_backup_job_part_path($state, $final = false) {
        $path = trailingslashit($state['backup_dir']) . $state['prefix'] . '-part' . sprintf('%03d', (int) $state['current_part']) . '.tar.gz';
        return $final ? $path : $path . '.tmp';
    }

    private function finalize_files_backup_job_part(&$state) {
        $part_path = $this->get_files_backup_job_part_path($state);
        if ($state['current_part_entries'] <= 0 || !file_exists($part_path)) {
            return true;
        }

        $handle = @gzopen($part_path, 'ab1');
        if (!$handle) {
            return new WP_Error('targz_open_failed', __('Impossibile finalizzare una parte tar.gz del backup.', 'marrison-custom-updater'));
        }
        $ok = $this->write_tar_data($handle, str_repeat("\0", 1024));
        $closed = @gzclose($handle);
        if (!$ok || !$closed) {
            return new WP_Error('targz_write_failed', __('Errore durante la finalizzazione di una parte tar.gz del backup.', 'marrison-custom-updater'));
        }

        $final_path = $this->get_files_backup_job_part_path($state, true);
        if (!@rename($part_path, $final_path)) {
            return new WP_Error('targz_rename_failed', __('Impossibile finalizzare il nome di una parte tar.gz del backup.', 'marrison-custom-updater'));
        }

        $filename = basename($final_path);
        if (!in_array($filename, $state['parts'], true)) {
            $state['parts'][] = $filename;
        }

        return true;
    }

    private function cleanup_files_backup_sets($backup_dir, $max_sets = 3) {
        foreach (glob($backup_dir . '/files-backup-*.tmp') ?: [] as $tmp_file) {
            if (filemtime($tmp_file) < time() - DAY_IN_SECONDS) {
                @unlink($tmp_file);
            }
        }

        $files = array_merge(
            glob($backup_dir . '/files-backup-*.tar.gz') ?: [],
            glob($backup_dir . '/files-backup-*.zip') ?: []
        );
        $sets = [];
        foreach ($files as $file) {
            $filename = basename($file);
            if (preg_match('/^(files-backup-\d{8}-\d{6})(?:-part\d{3})?\.(?:tar\.gz|zip)$/', $filename, $matches)) {
                $sets[$matches[1]][] = $file;
            }
        }
        if (count($sets) <= $max_sets) {
            return;
        }

        uasort($sets, function($a, $b) {
            return max(array_map('filemtime', $b)) - max(array_map('filemtime', $a));
        });

        $old_sets = array_slice($sets, $max_sets, null, true);
        foreach ($old_sets as $set_files) {
            foreach ($set_files as $file) {
                @unlink($file);
            }
        }
    }

    private function add_files_to_backup_tar_gz($handle, $dir, $root_path, $archive_path, &$stats) {
        $items = @scandir($dir);
        if (!is_array($items)) {
            return new WP_Error('backup_read_failed', sprintf(__('Impossibile leggere la directory: %s', 'marrison-custom-updater'), $dir));
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_link($path) || $this->is_excluded_from_files_backup($path, $archive_path)) {
                continue;
            }

            $relative_path = ltrim(str_replace($root_path, '', wp_normalize_path($path)), '/\\');
            if ($relative_path === '') {
                continue;
            }

            if (is_dir($path)) {
                $mtime = @filemtime($path);
                if (!$this->write_tar_header($handle, rtrim($relative_path, '/') . '/', 0, $mtime ?: time(), '5', @fileperms($path) ?: 0755)) {
                    return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura della directory: %s', 'marrison-custom-updater'), $relative_path));
                }

                $stats['dirs']++;
                $result = $this->add_files_to_backup_tar_gz($handle, $path, $root_path, $archive_path, $stats);
                if (is_wp_error($result)) {
                    return $result;
                }
            } elseif (is_file($path)) {
                if (!is_readable($path)) {
                    return new WP_Error('backup_file_not_readable', sprintf(__('File non leggibile durante il backup: %s', 'marrison-custom-updater'), $relative_path));
                }

                $size = @filesize($path);
                if ($size === false) {
                    return new WP_Error('backup_file_size_failed', sprintf(__('Impossibile determinare la dimensione del file: %s', 'marrison-custom-updater'), $relative_path));
                }

                $mtime = @filemtime($path);
                if (!$this->write_tar_header($handle, $relative_path, $size, $mtime ?: time(), '0', @fileperms($path) ?: 0644)) {
                    return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura dell\'header del file: %s', 'marrison-custom-updater'), $relative_path));
                }

                $file_handle = @fopen($path, 'rb');
                if (!$file_handle) {
                    return new WP_Error('backup_file_open_failed', sprintf(__('Impossibile aprire il file durante il backup: %s', 'marrison-custom-updater'), $relative_path));
                }

                $written = 0;
                while (!feof($file_handle)) {
                    $chunk = fread($file_handle, 1024 * 1024);
                    if ($chunk === false) {
                        fclose($file_handle);
                        return new WP_Error('backup_file_read_failed', sprintf(__('Errore durante la lettura del file: %s', 'marrison-custom-updater'), $relative_path));
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    if (!$this->write_tar_data($handle, $chunk)) {
                        fclose($file_handle);
                        return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura del file: %s', 'marrison-custom-updater'), $relative_path));
                    }
                    $written += strlen($chunk);
                }
                fclose($file_handle);

                $padding = (512 - ($written % 512)) % 512;
                if ($padding > 0 && !$this->write_tar_data($handle, str_repeat("\0", $padding))) {
                    return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura del padding del file: %s', 'marrison-custom-updater'), $relative_path));
                }

                $stats['files']++;
                $stats['bytes'] += $written;
            }
        }

        return true;
    }

    private function write_tar_header($handle, $path, $size, $mtime, $typeflag = '0', $mode = 0644, $allow_pax = true) {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($allow_pax && !$this->tar_path_fits_ustar($path)) {
            $pax_data = $this->build_tar_pax_record('path', $path);
            $pax_name = 'PaxHeaders/' . substr(basename($path), 0, 90);
            if (!$this->write_tar_header($handle, $pax_name, strlen($pax_data), time(), 'x', 0644, false)) {
                return false;
            }
            if (!$this->write_tar_data($handle, $pax_data)) {
                return false;
            }
            $padding = (512 - (strlen($pax_data) % 512)) % 512;
            if ($padding > 0 && !$this->write_tar_data($handle, str_repeat("\0", $padding))) {
                return false;
            }
        }

        list($name, $prefix) = $this->get_tar_name_fields($path);
        $header  = str_pad($name, 100, "\0");
        $header .= $this->format_tar_number($mode & 0777, 8);
        $header .= $this->format_tar_number(0, 8);
        $header .= $this->format_tar_number(0, 8);
        $header .= $this->format_tar_number($size, 12);
        $header .= $this->format_tar_number($mtime, 12);
        $header .= str_repeat(' ', 8);
        $header .= $typeflag;
        $header .= str_repeat("\0", 100);
        $header .= "ustar\0";
        $header .= '00';
        $header .= str_pad('mcu', 32, "\0");
        $header .= str_pad('mcu', 32, "\0");
        $header .= $this->format_tar_number(0, 8);
        $header .= $this->format_tar_number(0, 8);
        $header .= str_pad($prefix, 155, "\0");
        $header .= str_repeat("\0", 12);

        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }
        $checksum_field = sprintf('%06o', $checksum) . "\0 ";
        $header = substr($header, 0, 148) . $checksum_field . substr($header, 156);

        return $this->write_tar_data($handle, $header);
    }

    private function write_tar_data($handle, $data) {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = @gzwrite($handle, substr($data, $offset));
            if ($written === false || $written <= 0) {
                return false;
            }
            $offset += $written;
        }
        return true;
    }

    private function build_tar_pax_record($key, $value) {
        $payload = $key . '=' . $value . "\n";
        $length = strlen($payload) + 2;
        do {
            $record = $length . ' ' . $payload;
            $new_length = strlen($record);
            if ($new_length === $length) {
                return $record;
            }
            $length = $new_length;
        } while (true);
    }

    private function get_tar_name_fields($path) {
        if (strlen($path) <= 100) {
            return [$path, ''];
        }

        $best = null;
        $length = strlen($path);
        for ($i = 0; $i < $length; $i++) {
            if ($path[$i] !== '/') {
                continue;
            }
            $prefix = substr($path, 0, $i);
            $name = substr($path, $i + 1);
            if (strlen($prefix) <= 155 && strlen($name) <= 100) {
                $best = [$name, $prefix];
            }
        }

        if ($best) {
            return $best;
        }

        return [substr(basename($path), 0, 100), ''];
    }

    private function tar_path_fits_ustar($path) {
        if (strlen($path) <= 100) {
            return true;
        }

        $length = strlen($path);
        for ($i = 0; $i < $length; $i++) {
            if ($path[$i] !== '/') {
                continue;
            }
            $prefix = substr($path, 0, $i);
            $name = substr($path, $i + 1);
            if (strlen($prefix) <= 155 && strlen($name) <= 100) {
                return true;
            }
        }

        return false;
    }

    private function format_tar_number($value, $length) {
        $max_octal = pow(8, $length - 1) - 1;
        if ($value <= $max_octal) {
            return sprintf('%0' . ($length - 1) . 'o', $value) . "\0";
        }

        $bytes = array_fill(0, $length, 0);
        for ($i = $length - 1; $i >= 0; $i--) {
            $bytes[$i] = $value & 0xff;
            $value = intdiv($value, 256);
        }
        $bytes[0] |= 0x80;
        return implode('', array_map('chr', $bytes));
    }

    private function is_excluded_from_files_backup($path, $zip_path) {
        $normalized_path = wp_normalize_path($path);
        $exclude_roots = [
            wp_normalize_path($this->get_backup_dir()),
            wp_normalize_path(WP_CONTENT_DIR . '/upgrade'),
            wp_normalize_path(WP_CONTENT_DIR . '/cache'),
            wp_normalize_path(ABSPATH . '.git'),
        ];

        foreach ($exclude_roots as $exclude_root) {
            if ($exclude_root && strpos($normalized_path, untrailingslashit($exclude_root) . '/') === 0) {
                return true;
            }
            if ($normalized_path === untrailingslashit($exclude_root)) {
                return true;
            }
        }

        $basename = basename($normalized_path);
        if (in_array($basename, ['debug.log', 'error_log'], true)) {
            return true;
        }

        return $normalized_path === wp_normalize_path($zip_path);
    }

    private function get_backup_download_token($filename, $type) {
        return wp_hash($type . '|' . $filename);
    }

    private function get_backup_download_url($filename, $type = 'db') {
        $action = ($type === 'files') ? 'marrison_download_files_backup' : 'marrison_download_db_backup';
        return add_query_arg(
            [
                'action' => $action,
                'file'   => $filename,
                'token'  => $this->get_backup_download_token($filename, $type),
            ],
            admin_url('admin-post.php')
        );
    }

    private function can_download_backup($filename, $type) {
        $token = sanitize_text_field($_GET['token'] ?? '');
        if ($token && hash_equals($this->get_backup_download_token($filename, $type), $token)) {
            return true;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return false;
        }

        return isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'marrison_download_' . $type . '_backup');
    }

    private function escape_for_sql($wpdb, $val) {
        if (isset($wpdb->dbh) && ($wpdb->dbh instanceof mysqli)) {
            return mysqli_real_escape_string($wpdb->dbh, $val);
        }
        return str_replace(
            ["\\", "'", "\n", "\r", "\x00", "\x1a"],
            ["\\\\", "\\'", "\\n", "\\r", "\\0", "\\Z"],
            $val
        );
    }

    public function ajax_db_backup() {
        check_ajax_referer('marrison_db_backup', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        try {
            $filename = $this->create_db_backup();
            if (is_wp_error($filename)) {
                wp_send_json_error($filename->get_error_message());
            } elseif ($filename) {
                wp_send_json_success(['message' => 'Backup database completato!', 'filename' => $filename]);
            } else {
                wp_send_json_error('Errore durante la creazione del backup database.');
            }
        } catch (Exception $e) {
            wp_send_json_error('Errore: ' . $e->getMessage());
        } catch (Error $e) {
            wp_send_json_error('Errore fatale: ' . $e->getMessage());
        }
    }

    public function ajax_files_backup() {
        check_ajax_referer('marrison_files_backup', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        @set_time_limit(30);
        @ini_set('memory_limit', '512M');

        try {
            $job_id = sanitize_key($_POST['job_id'] ?? '');
            $state = $job_id ? $this->load_files_backup_job($job_id) : $this->init_files_backup_job();
            if (is_wp_error($state)) {
                wp_send_json_error($state->get_error_message());
            }
            if (!$state) {
                wp_send_json_error(__('Job backup non trovato o scaduto.', 'marrison-custom-updater'));
            }

            $running_state = $state;
            $state = $this->process_files_backup_job($state);
            if (is_wp_error($state)) {
                $this->cleanup_files_backup_job_artifacts($running_state);
                wp_send_json_error($state->get_error_message());
            }

            $percent = $state['total_bytes'] > 0
                ? min(99, (int) floor(($state['processed_bytes'] / $state['total_bytes']) * 100))
                : 0;

            if ($state['status'] === 'complete') {
                $message = sprintf(__('Backup file completato in %d parti.', 'marrison-custom-updater'), count($state['parts']));
                if (!empty($state['skipped_count'])) {
                    $message .= ' ' . sprintf(
                        __('Saltati %1$d file (%2$s).', 'marrison-custom-updater'),
                        (int) $state['skipped_count'],
                        size_format((int) $state['skipped_bytes'])
                    );
                }

                wp_send_json_success([
                    'done' => true,
                    'percent' => 100,
                    'message' => $message,
                    'files' => $state['parts'],
                    'skipped_count' => (int) $state['skipped_count'],
                    'skipped_files' => $state['skipped_files'],
                ]);
            } else {
                wp_send_json_success([
                    'done' => false,
                    'job_id' => $state['job_id'],
                    'percent' => $percent,
                    'message' => sprintf(
                        __('Backup file in corso: %1$d/%2$d file, %3$s/%4$s.', 'marrison-custom-updater'),
                        (int) $state['processed_files'],
                        (int) $state['total_files'],
                        size_format((int) $state['processed_bytes']),
                        size_format((int) $state['total_bytes'])
                    ),
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error('Errore: ' . $e->getMessage());
        } catch (Error $e) {
            wp_send_json_error('Errore fatale: ' . $e->getMessage());
        }
    }

    public function ajax_delete_backup() {
        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'marrison_delete_backup_' . $filename)) {
            wp_send_json_error(__('Security check failed', 'marrison-custom-updater'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        if (!$this->is_valid_backup_filename($filename)) {
            wp_send_json_error(__('File backup non valido.', 'marrison-custom-updater'));
        }

        $backup_dir = $this->get_backup_dir();
        $file_path = $backup_dir . '/' . $filename;
        $real_backup_dir = realpath($backup_dir);
        $real_file_path = realpath($file_path);

        if (!$real_backup_dir || !$real_file_path || strpos(wp_normalize_path($real_file_path), trailingslashit(wp_normalize_path($real_backup_dir))) !== 0) {
            wp_send_json_error(__('File backup non trovato.', 'marrison-custom-updater'));
        }

        if (!is_file($real_file_path) || !@unlink($real_file_path)) {
            wp_send_json_error(__('Impossibile eliminare il backup.', 'marrison-custom-updater'));
        }

        wp_send_json_success(['message' => __('Backup eliminato correttamente.', 'marrison-custom-updater')]);
    }

    private function is_valid_backup_filename($filename) {
        if (empty($filename)) {
            return false;
        }

        return (
            (strpos($filename, 'db-backup-') === 0 && pathinfo($filename, PATHINFO_EXTENSION) === 'zip') ||
            $this->is_files_backup_filename($filename) ||
            preg_match('/^.+-backup\.zip$/', $filename)
        );
    }

    private function is_files_backup_filename($filename) {
        return (bool) preg_match('/^files-backup-\d{8}-\d{6}(?:-part\d{3})?\.(zip|tar\.gz)$/', $filename);
    }

    public function download_db_backup() {
        $filename = sanitize_file_name($_GET['file'] ?? '');
        if (empty($filename) || strpos($filename, 'db-backup-') !== 0 || pathinfo($filename, PATHINFO_EXTENSION) !== 'zip') {
            wp_die('File non valido.');
        }

        if (!$this->can_download_backup($filename, 'db')) {
            wp_die(esc_html__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        $backup_dir = $this->get_backup_dir();
        $file_path  = $backup_dir . '/' . $filename;
        if (!file_exists($file_path)) wp_die('File non trovato.');

        $this->stream_backup_download($file_path, $filename);
    }

    public function download_files_backup() {
        $filename = sanitize_file_name($_GET['file'] ?? '');
        if (empty($filename) || !$this->is_files_backup_filename($filename)) {
            wp_die('File non valido.');
        }

        if (!$this->can_download_backup($filename, 'files')) {
            wp_die(esc_html__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        $backup_dir = $this->get_backup_dir();
        $file_path  = $backup_dir . '/' . $filename;
        if (!file_exists($file_path)) wp_die('File non trovato.');

        $this->stream_backup_download($file_path, $filename);
    }

    private function stream_backup_download($file_path, $filename) {
        @set_time_limit(0);
        @ini_set('zlib.output_compression', 'Off');

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            wp_die('Impossibile leggere il file.');
        }

        $content_type = (substr($filename, -7) === '.tar.gz') ? 'application/gzip' : 'application/zip';

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        $chunk_size = 1024 * 1024;
        while (!feof($handle)) {
            echo fread($handle, $chunk_size);
            flush();
            if (connection_aborted()) {
                break;
            }
        }

        fclose($handle);
        exit;
    }

    public function trigger_elementor_db_update($upgrader_object, $options) {


        if (!isset($options['action']) || $options['action'] !== 'update') {
            return;
        }
        if (!isset($options['type']) || $options['type'] !== 'plugin') {
            return;
        }
        
        $plugins = [];
        if (isset($options['plugins']) && is_array($options['plugins'])) {
            $plugins = $options['plugins'];
        } elseif (isset($options['plugin'])) {
            $plugins = [$options['plugin']];
        }

        if (empty($plugins)) {
            return;
        }

        $elementor_updated = false;
        foreach ($plugins as $plugin) {
            // Elementor slug/file is typically 'elementor/elementor.php'
            if (strpos($plugin, 'elementor/elementor.php') !== false) {
                $elementor_updated = true;
                break;
            }
        }

        if ($elementor_updated) {
            // Breve delay per assicurare che il filesystem sia stabile e la cache aggiornata
            sleep(3);

            if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
                return;
            }
            
            // Assicurati che le classi necessarie siano caricate
            if ( class_exists( '\Elementor\App\Modules\ImportExport\Utils' ) || class_exists( '\Elementor\Plugin' ) ) {
                
                // Forza l'aggiornamento del database di Elementor
                // Rimosso il metodo get_remote_info() che non esiste nelle versioni recenti o è privato
                
                if (isset(\Elementor\Plugin::$instance->updater) && method_exists(\Elementor\Plugin::$instance->updater, 'update')) {
                    \Elementor\Plugin::$instance->updater->update();
                    error_log('[Marrison Updater] Elementor DB update triggered automatically.');
                }
            }
        }
    }
}
