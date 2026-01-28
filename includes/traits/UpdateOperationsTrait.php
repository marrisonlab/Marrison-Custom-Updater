<?php
trait Marrison_Update_Operations_Trait {
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
        $response = wp_remote_get($repo_url . 'index.php', ['timeout' => 15]);
        if (is_wp_error($response)) return [];
        $updates = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($updates)) return [];
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
        $response = wp_remote_get($repo_url . 'index.php', ['timeout' => 15]);
        if (is_wp_error($response)) return [];
        $updates = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($updates)) return [];
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

    private function perform_update($slug) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        if (!$wp_filesystem) return false;
        foreach ($this->get_available_updates() as $update) {
            if ($update['slug'] !== $slug) continue;
            $zip = download_url($update['download_url']);
            if (is_wp_error($zip)) return false;
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
            unzip_file($zip, $upgrade_dir);
            unlink($zip);
            $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
            if (empty($dirs)) return false;
            $source = trailingslashit($dirs[0]);
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
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
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
        $pattern = $backup_dir . '/' . $type . '-' . $slug . '-*-backup.zip';
        foreach (glob($pattern) as $f) @unlink($f);
        if ($type === 'plugin') {
            foreach (glob($backup_dir . '/' . $slug . '-*-backup.zip') as $f) @unlink($f);
        }
        $date = date('Ymd');
        $time = date('His');
        $ver_str = $version ? $version : 'na';
        $filename = sprintf('%s-%s-v%s-%s-%s-backup.zip', $type, $slug, $ver_str, $date, $time);
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
}
