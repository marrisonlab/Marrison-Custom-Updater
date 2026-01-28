<?php
trait Marrison_Scheduling_Trait {
    public function add_custom_cron_intervals($schedules) {
        $schedules['weekly'] = [
            'interval' => 604800, // 7 days
            'display'  => __('Settimanale', 'marrison-custom-updater')
        ];
        $schedules['monthly'] = [
            'interval' => 2592000, // 30 days
            'display'  => __('Una volta al mese', 'marrison-custom-updater')
        ];
        $schedules['biannual'] = [
            'interval' => 15552000, // 180 days (6 months)
            'display'  => __('Ogni 6 mesi', 'marrison-custom-updater')
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

        wp_clear_scheduled_hook('marrison_scheduled_update_event');

        if ($enabled === 'yes') {
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

    public function send_test_email_ajax() {
        check_ajax_referer('marrison_test_email', 'nonce');
        
        $email = sanitize_email($_POST['email']);
        if (!is_email($email)) {
            wp_send_json_error(__('Indirizzo email non valido.', 'marrison-custom-updater'));
        }
        
        $domain = parse_url(get_site_url(), PHP_URL_HOST);
        if (strpos($domain, 'www.') === 0) {
            $domain = substr($domain, 4);
        }
        $from_email = 'no-reply@' . $domain;
        $from_name = get_bloginfo('name');
        
        $subject = '[' . get_bloginfo('name') . '] ' . __('Test Invio Email - Marrison Custom Updater', 'marrison-custom-updater');
        
        $message_html = '<html><body>';
        $message_html .= '<h2>' . __('Test Configurazione Email', 'marrison-custom-updater') . '</h2>';
        $message_html .= '<p>' . __('Ciao,', 'marrison-custom-updater') . '</p>';
        $message_html .= '<p>' . __('Questa è una mail di test inviata da <strong>Marrison Custom Updater</strong> per verificare la configurazione dell\'invio email.', 'marrison-custom-updater') . '</p>';
        $message_html .= '<p style="color: green; font-weight: bold;">' . __('Se leggi questo messaggio, l\'invio funziona correttamente.', 'marrison-custom-updater') . '</p>';
        $message_html .= '<p><small>' . sprintf(__('Inviato dal sito: %s', 'marrison-custom-updater'), esc_url(get_site_url())) . '</small></p>';
        $message_html .= '</body></html>';
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
            'Reply-To: ' . get_option('admin_email')
        );
        
        $sent = wp_mail($email, $subject, $message_html, $headers);
        
        if ($sent) {
            wp_send_json_success(__('Mail inviata correttamente!', 'marrison-custom-updater'));
        } else {
            wp_send_json_error(__('Invio fallito. Verifica i log del server o la configurazione SMTP.', 'marrison-custom-updater'));
        }
    }

    public function run_scheduled_updates() {
        $log_entry = [
            'time' => current_time('mysql'),
            'status' => 'started',
            'message' => 'Cron job started.'
        ];
        update_option('marrison_last_cron_log', $log_entry);

        try {
            @ignore_user_abort(true);
            @set_time_limit(0);

            $data = $this->get_all_updates_data();
            
            $log_entry['message'] = 'Updates check completed.';
            update_option('marrison_last_cron_log', $log_entry);

            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
            include_once ABSPATH . 'wp-admin/includes/theme.php';
            include_once ABSPATH . 'wp-admin/includes/file.php';
            
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            
            $skin = new Automatic_Upgrader_Skin();
            $updated_plugins = [];
            $updated_themes = [];
            $updated_translations = 0;

            // --- 1. Aggiornamento Plugin Privati ---
            if (!empty($data['plugins_private'])) {
                foreach ($data['plugins_private'] as $u) {
                    $plugin_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                    $old_version = 'N/A';
                    if ($plugin_file && file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                        $p_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                        $old_version = $p_data['Version'];
                    }

                    if ($this->perform_update($u['slug'])) {
                        $updated_plugins[] = [
                            'name'        => $u['name'],
                            'old_version' => $old_version,
                            'new_version' => $u['version'],
                            'type'        => 'Privato'
                        ];
                    }
                }
            }
            
            $log_entry['message'] = 'Private plugins processed.';
            update_option('marrison_last_cron_log', $log_entry);

            // --- 2. Aggiornamento Plugin Ufficiali ---
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
                $plugin_info_map = []; // Mappa per conservare info versioni

                foreach ($transient_plugins->response as $file => $data_plugin) {
                    if (in_array($file, $private_files)) continue;
                    
                    $slug = isset($data_plugin->slug) ? $data_plugin->slug : dirname($file);
                    if ($slug === '.' || $slug === '') $slug = basename($file, '.php');
                    if (in_array($slug, $private_slugs)) continue;
                    
                    $plugin_files[] = $file;
                    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file);
                    
                    $plugin_info_map[$file] = [
                        'name' => $plugin_data['Name'] ?? $slug,
                        'old_version' => $plugin_data['Version'] ?? 'N/A',
                        'new_version' => $data_plugin->new_version ?? 'N/A'
                    ];
                }
                
                if (!empty($plugin_files)) {
                    $upgrader = new Plugin_Upgrader($skin);
                    $results = $upgrader->bulk_upgrade($plugin_files);
                    if (is_array($results)) {
                        foreach ($plugin_files as $file) {
                            $res = isset($results[$file]) ? $results[$file] : false;
                            if ($res && !is_wp_error($res)) {
                                $info = $plugin_info_map[$file];
                                $updated_plugins[] = [
                                    'name'        => $info['name'],
                                    'old_version' => $info['old_version'],
                                    'new_version' => $info['new_version'],
                                    'type'        => 'Ufficiale'
                                ];
                            }
                        }
                    }
                    wp_clean_plugins_cache(true);
                    delete_site_transient('update_plugins');
                }
            }
            
            $log_entry['message'] = 'Official plugins processed.';
            update_option('marrison_last_cron_log', $log_entry);

            // --- 3. Aggiornamento Temi ---
            if ($data['themes_count'] > 0) {
                $current = get_site_transient('update_themes');
                if (!empty($current->response)) {
                    $themes = array_keys($current->response);
                    
                    // Prepara info versioni temi
                    $theme_info_map = [];
                    foreach ($themes as $slug) {
                        $theme = wp_get_theme($slug);
                        $new_ver = isset($current->response[$slug]['new_version']) ? $current->response[$slug]['new_version'] : 'N/A';
                        
                        $theme_info_map[$slug] = [
                            'name' => $theme->get('Name'),
                            'old_version' => $theme->get('Version'),
                            'new_version' => $new_ver
                        ];
                    }

                    $theme_upgrader = new Theme_Upgrader($skin);
                    $result = $theme_upgrader->bulk_upgrade($themes);
                    
                    if (is_array($result)) {
                        foreach ($result as $slug => $res) {
                            if ($res && !is_wp_error($res)) {
                                $info = $theme_info_map[$slug];
                                $updated_themes[] = [
                                    'name'        => $info['name'],
                                    'old_version' => $info['old_version'],
                                    'new_version' => $info['new_version']
                                ];
                            }
                        }
                    }
                }
            }
            
            $log_entry['message'] = 'Themes processed.';
            update_option('marrison_last_cron_log', $log_entry);
            
            if ($data['translations_count'] > 0) {
                include_once ABSPATH . 'wp-admin/includes/translation-install.php';
                $translations = wp_get_translation_updates();
                if (!empty($translations)) {
                    $lang_upgrader = new Language_Pack_Upgrader($skin);
                    $result = $lang_upgrader->bulk_upgrade($translations);
                    if ($result && !is_wp_error($result)) {
                        $count = 0;
                        foreach ($result as $r) {
                            if ($r && !is_wp_error($r)) $count++;
                        }
                        $updated_translations = $count;
                    }
                }
            }
            
            $log_entry['message'] = 'Translations processed. Preparing email...';
            update_option('marrison_last_cron_log', $log_entry);
            
            $email = get_option('marrison_auto_update_email');
            if ($email) {
                $has_updates = (!empty($updated_plugins) || !empty($updated_themes) || $updated_translations > 0);
                
                $subject = '[' . get_bloginfo('name') . '] Report Aggiornamento Automatico';
                
                // Stili CSS inline per compatibilità email
                $bg_color = "#2c3338"; // Sfondo scuro (WordPress dark grey)
                $container_bg = "#ffffff"; // Sfondo bianco per il contenuto
                
                $style_body_wrapper = "background-color: {$bg_color}; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 40px 0; width: 100%;";
                $style_container = "background-color: {$container_bg}; border-radius: 10px; max-width: 600px; margin: 0 auto; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);";
                
                $style_h2 = "color: #2c3338; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; margin-top: 0;";
                $style_table = "width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;";
                $style_th = "background-color: #f8f9fa; border: 1px solid #ddd; padding: 10px; text-align: left; font-weight: bold; color: #555;";
                $style_td = "border: 1px solid #ddd; padding: 10px;";
                $style_badge_priv = "background-color: #0073aa; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                $style_badge_off = "background-color: #46b450; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                $style_badge_theme = "background-color: #e65100; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                $style_footer = "margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px; font-size: 12px; color: #777; text-align: center;";

                $message_html = '<!DOCTYPE html><html><body style="' . $style_body_wrapper . '">';
                
                // Container Bianco con angoli smussati
                $message_html .= '<div style="' . $style_container . '">';
                
                $message_html .= '<div style="text-align: center; margin-bottom: 20px;">';
                // Opzionale: inserire logo se disponibile, altrimenti nome sito
                $message_html .= '<h1 style="margin: 0; color: #444; font-size: 24px;">' . get_bloginfo('name') . '</h1>';
                $message_html .= '</div>';
                
                $message_html .= '<h2 style="' . $style_h2 . '">Report Aggiornamenti</h2>';
                
                if ($has_updates) {
                    $message_html .= '<p style="margin-bottom: 20px;">Le operazioni di aggiornamento automatico sono state completate con successo. Di seguito i dettagli:</p>';
                    
                    if (!empty($updated_plugins)) {
                        $message_html .= '<h3 style="color: #444; margin-top: 20px;">🔌 Plugin Aggiornati</h3>';
                        $message_html .= '<table style="' . $style_table . '">';
                        $message_html .= '<thead><tr>';
                        $message_html .= '<th style="' . $style_th . '">Plugin</th>';
                        $message_html .= '<th style="' . $style_th . '">Tipo</th>';
                        $message_html .= '<th style="' . $style_th . '">Versione</th>';
                        $message_html .= '</tr></thead><tbody>';
                        
                        foreach ($updated_plugins as $p) {
                            $badge_style = ($p['type'] === 'Privato') ? $style_badge_priv : $style_badge_off;
                            $message_html .= '<tr>';
                            $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($p['name']) . '</strong></td>';
                            $message_html .= '<td style="' . $style_td . '"><span style="' . $badge_style . '">' . esc_html($p['type']) . '</span></td>';
                            $message_html .= '<td style="' . $style_td . '">' . esc_html($p['old_version']) . ' &rarr; <strong>' . esc_html($p['new_version']) . '</strong></td>';
                            $message_html .= '</tr>';
                        }
                        $message_html .= '</tbody></table>';
                    }
                    
                    if (!empty($updated_themes)) {
                        $message_html .= '<h3 style="color: #444; margin-top: 20px;">🎨 Temi Aggiornati</h3>';
                        $message_html .= '<table style="' . $style_table . '">';
                        $message_html .= '<thead><tr>';
                        $message_html .= '<th style="' . $style_th . '">Tema</th>';
                        $message_html .= '<th style="' . $style_th . '">Versione</th>';
                        $message_html .= '</tr></thead><tbody>';
                        
                        foreach ($updated_themes as $t) {
                            $message_html .= '<tr>';
                            $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($t['name']) . '</strong> <span style="' . $style_badge_theme . '">Tema</span></td>';
                            $message_html .= '<td style="' . $style_td . '">' . esc_html($t['old_version']) . ' &rarr; <strong>' . esc_html($t['new_version']) . '</strong></td>';
                            $message_html .= '</tr>';
                        }
                        $message_html .= '</tbody></table>';
                    }
                    
                    if ($updated_translations > 0) {
                        $message_html .= '<div style="background-color: #f0f0f1; padding: 10px; border-left: 4px solid #0073aa; margin-top: 20px;">';
                        $message_html .= '<strong>🌍 Traduzioni:</strong> Aggiornati ' . intval($updated_translations) . ' pacchetti di traduzione.';
                        $message_html .= '</div>';
                    }
                } else {
                    $message_html .= '<div style="background-color: #e7f7ed; color: #106a33; padding: 15px; border-radius: 5px; text-align: center; font-weight: bold; border: 1px solid #c3e6cb;">';
                    $message_html .= '✅ Nessun aggiornamento necessario. Il sistema è già aggiornato.';
                    $message_html .= '</div>';
                }
                
                $message_html .= '<div style="' . $style_footer . '">';
                $message_html .= 'Report generato automaticamente da <strong>Marrison Custom Updater</strong><br>';
                $message_html .= '<a href="' . esc_url(admin_url()) . '" style="color: #0073aa; text-decoration: none;">Accedi al sito</a>';
                $message_html .= '</div>';
                
                $message_html .= '</div>'; // Chiusura Container
                $message_html .= '</body></html>';
                
                $domain = parse_url(get_site_url(), PHP_URL_HOST);
                if (strpos($domain, 'www.') === 0) {
                    $domain = substr($domain, 4);
                }
                $from_email = 'no-reply@' . $domain;
                $from_name = get_bloginfo('name');
                
                $headers = array(
                    'Content-Type: text/html; charset=UTF-8',
                    'From: ' . $from_name . ' <' . $from_email . '>',
                    'Reply-To: ' . get_option('admin_email')
                );
                
                $sent = wp_mail($email, $subject, $message_html, $headers);
                
                $log_entry['status'] = 'completed';
                $log_entry['message'] = $sent ? 'Email inviata con successo.' : 'Errore invio email.';
                $log_entry['email_sent'] = $sent;
                $log_entry['updates_found'] = $has_updates;
                update_option('marrison_last_cron_log', $log_entry);
            } else {
                $log_entry['status'] = 'completed';
                $log_entry['message'] = 'Nessuna email configurata.';
                update_option('marrison_last_cron_log', $log_entry);
            }

        } catch (Throwable $e) {
            $log_entry['status'] = 'error';
            $log_entry['message'] = 'Errore Critico: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            update_option('marrison_last_cron_log', $log_entry);
        } catch (Exception $e) {
            $log_entry['status'] = 'error';
            $log_entry['message'] = 'Eccezione: ' . $e->getMessage();
            update_option('marrison_last_cron_log', $log_entry);
        }
    }
}
