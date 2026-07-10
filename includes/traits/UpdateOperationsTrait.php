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

        $tables = $wpdb->get_col('SHOW TABLES');
        if (empty($tables)) return false;

        sort($tables);

        $handle = fopen($sql_path, 'w');
        if (!$handle) return false;

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

        fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        fwrite($handle, "START TRANSACTION;\n");
        fwrite($handle, "SET time_zone = \"+00:00\";\n\n");

        fwrite($handle, "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
        fwrite($handle, "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
        fwrite($handle, "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
        fwrite($handle, "/*!40101 SET NAMES utf8mb4 */;\n\n");

        foreach ($tables as $table) {
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if (!$create) continue;

            $create_sql = $create[1];
            $create_sql = preg_replace('/ AUTO_INCREMENT/', '', $create_sql);
            $create_sql = preg_replace('/=\d+ DEFAULT/', '= DEFAULT', $create_sql);
            $create_sql = preg_replace('/=\d+ COLLATE/', '= COLLATE', $create_sql);
            $create_sql = preg_replace('/= DEFAULT/', ' DEFAULT', $create_sql);
            $create_sql = preg_replace('/= COLLATE/', ' COLLATE', $create_sql);

            fwrite($handle, "--\n-- Table structure for table `{$table}`\n--\n\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($handle, "/*!40101 SET @saved_cs_client     = @@character_set_client */;\n");
            fwrite($handle, "/*!40101 SET character_set_client = utf8mb4 */;\n");
            fwrite($handle, $create_sql . ";\n");
            fwrite($handle, "/*!40101 SET character_set_client = @saved_cs_client */;\n\n");

            fwrite($handle, "--\n-- Dumping data for table `{$table}`\n--\n\n");
            fwrite($handle, "LOCK TABLES `{$table}` WRITE;\n");
            fwrite($handle, "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n");

            $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
            $numeric_cols = [];
            foreach ($columns as $col) {
                $numeric_cols[$col['Field']] = (bool) preg_match(
                    '/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal|numeric|real|bit|year)/i',
                    $col['Type']
                );
            }

            $offset = 0;
            $batch  = 500;
            $has_data = false;
            while (true) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch, $offset),
                    ARRAY_A
                );
                if (empty($rows)) break;

                $has_data = true;
                $value_rows = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $field => $val) {
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } elseif (!empty($numeric_cols[$field]) && is_numeric($val)) {
                            $vals[] = $val;
                        } else {
                            $vals[] = "'" . $this->escape_for_sql($wpdb, (string) $val) . "'";
                        }
                    }
                    $value_rows[] = '(' . implode(', ', $vals) . ')';
                }
                fwrite($handle, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $value_rows) . ";\n");

                $offset += $batch;
                if (count($rows) < $batch) break;
            }

            fwrite($handle, "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n");
            fwrite($handle, "UNLOCK TABLES;\n\n");

            fwrite($handle, "-- --------------------------------------------------------\n\n");
        }

        fwrite($handle, "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n");
        fwrite($handle, "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n");
        fwrite($handle, "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n");
        fwrite($handle, "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n");
        fwrite($handle, "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
        fwrite($handle, "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
        fwrite($handle, "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
        fwrite($handle, "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n");
        fclose($handle);

        if (!class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }
        $archive = new PclZip($zip_path);
        $result  = $archive->create($sql_path, PCLZIP_OPT_REMOVE_PATH, $backup_dir);
        @unlink($sql_path);

        if ($result == 0) return false;

        $db_backups = glob($backup_dir . '/db-backup-*.zip');
        if (is_array($db_backups) && count($db_backups) > 5) {
            usort($db_backups, function($a, $b) { return filemtime($a) - filemtime($b); });
            foreach (array_slice($db_backups, 0, count($db_backups) - 5) as $f) @unlink($f);
        }

        return $zip_filename;
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
            if ($filename) {
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

    public function download_db_backup() {
        check_admin_referer('marrison_download_db_backup');
        if (!current_user_can('manage_options')) wp_die(esc_html__('Permessi insufficienti.', 'marrison-custom-updater'));

        $filename = sanitize_file_name($_GET['file'] ?? '');
        if (empty($filename) || strpos($filename, 'db-backup-') !== 0 || pathinfo($filename, PATHINFO_EXTENSION) !== 'zip') {
            wp_die('File non valido.');
        }

        $backup_dir = $this->get_backup_dir();
        $file_path  = $backup_dir . '/' . $filename;
        if (!file_exists($file_path)) wp_die('File non trovato.');

        if (ob_get_length()) ob_end_clean();
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        readfile($file_path);
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
