<?php
trait MCU_Scheduling_Trait {
    public function add_custom_cron_intervals($schedules) {
        $schedules['weekly'] = [
            'interval' => 604800, // 7 days
            'display'  => __('Settimanale', 'marrison-custom-updater')
        ];
        $schedules['monthly'] = [
            'interval' => 2592000, // 30 days
            'display'  => __('Mensile', 'marrison-custom-updater')
        ];
        $schedules['biannual'] = [
            'interval' => 15552000, // 180 days (6 months)
            'display'  => __('Semestrale', 'marrison-custom-updater')
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
                
                // Se l'orario specificato è già passato per oggi
                if ($target_time <= $now) {
                    switch ($frequency) {
                        case 'weekly':
                            $target_time->modify('+1 week');
                            break;
                        case 'monthly':
                            $target_time->modify('+1 month');
                            break;
                        case 'biannual':
                            $target_time->modify('+6 months');
                            break;
                        case 'daily':
                        default:
                            $target_time->modify('+1 day');
                            break;
                    }
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

        // --- Dati Simulati per il Test ---
        $updated_plugins = [
            [
                'name' => 'Advanced Custom Fields',
                'old_version' => '6.0.0',
                'new_version' => '6.1.0',
                'type' => 'Ufficiale'
            ],
            [
                'name' => 'Marrison Core Plugin',
                'old_version' => '1.0.2',
                'new_version' => '1.0.3',
                'type' => 'Privato'
            ]
        ];

        $updated_themes = [
            [
                'name' => 'Astra',
                'old_version' => '4.0.0',
                'new_version' => '4.1.0'
            ]
        ];

        $failed_updates = [
            [
                'name' => 'Plugin Corrotto Esempio',
                'version' => '2.0.0',
                'type' => 'Privato',
                'error' => 'Archivio ZIP non valido o corrotto.'
            ]
        ];

        $skipped_updates = [
            [
                'name' => 'Plugin Moderno',
                'version' => '3.5.0',
                'reason' => 'Richiede PHP 8.2 (Attuale: ' . phpversion() . ')'
            ]
        ];

        $updated_translations = 5;

        // --- Raccolta Info Reali Stato Sito ---
        global $wpdb;
        $site_info = [
            'wp_version'   => get_bloginfo('version'),
            'php_version'  => phpversion(),
            'server'       => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'N/A',
            'db_version'   => $wpdb->db_version(),
            'memory_limit' => ini_get('memory_limit'),
            'debug_mode'   => (defined('WP_DEBUG') && WP_DEBUG) ? 'Attivo' : 'Disattivo',
            'site_url'     => get_site_url(),
            'home_url'     => get_home_url()
        ];

        $all_plugins = get_plugins();
        $active_plugins = [];
        $inactive_plugins = [];

        foreach ($all_plugins as $path => $plugin) {
            if (is_plugin_active($path)) {
                $active_plugins[] = $plugin;
            } else {
                $inactive_plugins[] = $plugin;
            }
        }
        
        // --- Generazione Email (Stesso Template di run_scheduled_updates) ---
        $domain = parse_url(get_site_url(), PHP_URL_HOST);
        if (strpos($domain, 'www.') === 0) {
            $domain = substr($domain, 4);
        }
        $from_email = 'no-reply@' . $domain;
        $from_name = get_bloginfo('name');
        
        $subject = '[' . get_bloginfo('name') . '] Test Report Aggiornamento (Simulazione)';
        
        // Stili CSS inline
        $bg_color = "#2c3338";
        $container_bg = "#ffffff";
        
        $style_body_wrapper = "background-color: {$bg_color}; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 40px 0; width: 100%;";
        $style_container = "background-color: {$container_bg}; border-radius: 10px; max-width: 600px; margin: 0 auto; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);";
        
        $style_h2 = "color: #2c3338; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; margin-top: 30px;";
        $style_table = "width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;";
        $style_th = "background-color: #f8f9fa; border: 1px solid #ddd; padding: 10px; text-align: left; font-weight: bold; color: #555;";
        $style_td = "border: 1px solid #ddd; padding: 10px;";
        $style_badge_priv = "background-color: #0073aa; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
        $style_badge_off = "background-color: #46b450; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
        $style_badge_theme = "background-color: #e65100; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
        $style_badge_error = "background-color: #d63638; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
        $style_badge_warn = "background-color: #ffb900; color: #333; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
        
        $style_footer = "margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px; font-size: 12px; color: #777; text-align: center;";
        $style_section_title = "color: #444; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #0073aa; padding-left: 10px;";
        $style_section_title_error = "color: #d63638; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #d63638; padding-left: 10px;";
        $style_section_title_warn = "color: #e65100; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #ffb900; padding-left: 10px;";
        $style_list_item = "padding: 5px 0; border-bottom: 1px solid #eee; font-size: 13px;";

        $message_html = '<!DOCTYPE html><html><body style="' . $style_body_wrapper . '">';
        
        $message_html .= '<div style="' . $style_container . '">';
        
        // Header / Logo
        $message_html .= '<div style="text-align: center; margin-bottom: 20px;">';
        $custom_logo_id = get_theme_mod('custom_logo');
        $logo_src = '';
        $is_valid_image = false;
        if ($custom_logo_id) {
            $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
            if ($logo_data) {
                $src = $logo_data[0];
                $path = parse_url($src, PHP_URL_PATH);
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext !== 'svg') {
                    $logo_src = $src;
                    $is_valid_image = true;
                }
            }
        }
        if (!$is_valid_image && function_exists('get_site_icon_url')) {
            $icon_url = get_site_icon_url(512);
            if ($icon_url) {
                $path = parse_url($icon_url, PHP_URL_PATH);
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext !== 'svg') {
                    $logo_src = $icon_url;
                    $is_valid_image = true;
                }
            }
        }
        if ($is_valid_image && !empty($logo_src)) {
                    $message_html .= '<img src="' . esc_url($logo_src) . '" alt="' . esc_attr(get_bloginfo('name')) . '" style="max-width: 200px; max-height: 100px; width: auto; height: auto; display: inline-block; border: 0; outline: none; text-decoration: none;">';
                } else {
            $message_html .= '<h1 style="margin: 0; color: #444; font-size: 24px;">' . get_bloginfo('name') . '</h1>';
        }
        $message_html .= '</div>';
        
        // --- STATUS BANNER (SIMULATO) ---
        // In questa simulazione assumiamo che ci siano problemi (failed/skipped)
        $banner_color = "#d63638"; // Rosso
        $banner_icon = "❌";
        $banner_title = "Attenzione: Rilevati Problemi";
        $banner_desc = "Alcuni aggiornamenti non sono andati a buon fine o sono stati saltati.";
        
        $message_html .= '<div style="background-color: ' . $banner_color . '; color: #ffffff; padding: 20px; text-align: center; border-radius: 6px; margin-bottom: 25px;">';
        $message_html .= '<h2 style="margin: 0; font-size: 22px; color: #ffffff; border: none; font-weight: bold;">' . $banner_icon . ' ' . $banner_title . '</h2>';
        $message_html .= '<p style="margin: 10px 0 0 0; font-size: 15px; opacity: 0.95;">' . $banner_desc . '</p>';
        $message_html .= '</div>';
        
        $message_html .= '<h2 style="' . $style_h2 . '">Report Aggiornamenti (SIMULAZIONE)</h2>';
        $message_html .= '<div style="background-color: #fff8e5; border: 1px solid #ffb900; padding: 10px; margin-bottom: 20px; color: #444; font-size: 13px;"><strong>NOTA:</strong> Questa è una mail di test generata con dati fittizi per mostrarti come apparirà il report reale in caso di errori o avvisi.</div>';
        
        // --- ERRORI (SIMULATI) ---
        if (!empty($failed_updates)) {
            $message_html .= '<h3 style="' . $style_section_title_error . '">❌ Aggiornamenti Falliti</h3>';
            $message_html .= '<table style="' . $style_table . '">';
            $message_html .= '<thead><tr>';
            $message_html .= '<th style="' . $style_th . '">Elemento</th>';
            $message_html .= '<th style="' . $style_th . '">Tipo</th>';
            $message_html .= '<th style="' . $style_th . '">Errore</th>';
            $message_html .= '</tr></thead><tbody>';
            foreach ($failed_updates as $f) {
                $message_html .= '<tr>';
                $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($f['name']) . '</strong></td>';
                $message_html .= '<td style="' . $style_td . '"><span style="' . $style_badge_error . '">' . esc_html($f['type']) . '</span></td>';
                $message_html .= '<td style="' . $style_td . '"><span style="color: #d63638;">' . esc_html($f['error']) . '</span></td>';
                $message_html .= '</tr>';
            }
            $message_html .= '</tbody></table>';
        }

        // --- SKIPPED (SIMULATI) ---
        if (!empty($skipped_updates)) {
            $message_html .= '<h3 style="' . $style_section_title_warn . '">⚠️ Aggiornamenti Saltati</h3>';
            $message_html .= '<table style="' . $style_table . '">';
            $message_html .= '<thead><tr>';
            $message_html .= '<th style="' . $style_th . '">Elemento</th>';
            $message_html .= '<th style="' . $style_th . '">Motivo</th>';
            $message_html .= '</tr></thead><tbody>';
            foreach ($skipped_updates as $s) {
                $message_html .= '<tr>';
                $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($s['name']) . '</strong></td>';
                $message_html .= '<td style="' . $style_td . '">' . esc_html($s['reason']) . '</td>';
                $message_html .= '</tr>';
            }
            $message_html .= '</tbody></table>';
        }
        
        // --- AGGIORNATI (SIMULATI) ---
        if (!empty($updated_plugins)) {
            $message_html .= '<h3 style="' . $style_section_title . '">🔌 Plugin Aggiornati</h3>';
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
            $message_html .= '<h3 style="' . $style_section_title . '">🎨 Temi Aggiornati</h3>';
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

        // --- SEZIONE STATO DEL SITO (REALE) ---
        $message_html .= '<h2 style="' . $style_h2 . '">Stato del Sito</h2>';
        $message_html .= '<table style="' . $style_table . ' width: 100%; border: none;"><tbody>';
        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>WordPress:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">v' . $site_info['wp_version'] . '</td></tr>';
        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>PHP:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">v' . $site_info['php_version'] . '</td></tr>';
        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Web Server:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['server']) . '</td></tr>';
        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Memory Limit:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['memory_limit']) . '</td></tr>';
        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Debug Mode:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['debug_mode']) . '</td></tr>';
        $message_html .= '</tbody></table>';

        $message_html .= '<h3 style="' . $style_section_title . '">✅ Plugin Attivi (' . count($active_plugins) . ')</h3>';
        if (!empty($active_plugins)) {
            $message_html .= '<ul style="list-style-type: none; padding: 0; margin: 0;">';
            foreach ($active_plugins as $p) {
                $message_html .= '<li style="' . $style_list_item . '"><strong>' . esc_html($p['Name']) . '</strong> <span style="color: #777; font-size: 12px;">(v' . esc_html($p['Version']) . ')</span></li>';
            }
            $message_html .= '</ul>';
        }

        $message_html .= '<h3 style="' . $style_section_title . '; border-left-color: #d63638;">🚫 Plugin Inattivi (' . count($inactive_plugins) . ')</h3>';
        if (!empty($inactive_plugins)) {
            $message_html .= '<ul style="list-style-type: none; padding: 0; margin: 0;">';
            foreach ($inactive_plugins as $p) {
                $message_html .= '<li style="' . $style_list_item . '"><strong>' . esc_html($p['Name']) . '</strong> <span style="color: #777; font-size: 12px;">(v' . esc_html($p['Version']) . ')</span></li>';
            }
            $message_html .= '</ul>';
        }
        
        $message_html .= '<div style="' . $style_footer . '">';
        $message_html .= '<a href="' . esc_url(admin_url()) . '" style="color: #0073aa; text-decoration: none;">Accedi al sito</a><br><br>';
        $message_html .= '<span style="font-size: 11px; color: #999;">Powered By <a href="https://marrisonlab.com" target="_blank" style="color: #999; text-decoration: none;">Angelo Marra</a></span>';
        $message_html .= '</div>';
        
        $message_html .= '</div>'; // Chiusura Container
        $message_html .= '</body></html>';
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
            'Reply-To: ' . get_option('admin_email')
        );
        
        $sent = wp_mail($email, $subject, $message_html, $headers);
        
        if ($sent) {
            wp_send_json_success(__('Mail di test (simulata) inviata correttamente!', 'marrison-custom-updater'));
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
            $failed_updates = [];
            $skipped_updates = [];

            // --- 1. Aggiornamento Plugin Privati ---
            if (!empty($data['plugins_private'])) {
                foreach ($data['plugins_private'] as $u) {
                    $plugin_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                    $old_version = 'N/A';
                    if ($plugin_file && file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                        $p_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                        $old_version = $p_data['Version'];
                    }

                    $result = $this->perform_update($u['slug']);
                    if ($result === true) {
                        $updated_plugins[] = [
                            'name'        => $u['name'],
                            'old_version' => $old_version,
                            'new_version' => $u['version'],
                            'type'        => 'Privato'
                        ];
                    } else {
                        $error_msg = is_wp_error($result) ? $result->get_error_message() : __('Errore sconosciuto', 'marrison-custom-updater');
                        $failed_updates[] = [
                            'name' => $u['name'],
                            'version' => $u['version'],
                            'type' => 'Privato',
                            'error' => $error_msg
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
                    
                    // Check PHP Requirements
                    if (isset($data_plugin->requires_php) && version_compare(phpversion(), $data_plugin->requires_php, '<')) {
                        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file);
                        $skipped_updates[] = [
                            'name' => $plugin_data['Name'] ?? $slug,
                            'version' => $data_plugin->new_version,
                            'reason' => sprintf(__('Richiede PHP %s (Attuale: %s)', 'marrison-custom-updater'), $data_plugin->requires_php, phpversion())
                        ];
                        continue;
                    }

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
                            $info = $plugin_info_map[$file];

                            if ($res === true || (is_array($res) && !is_wp_error($res))) {
                                $updated_plugins[] = [
                                    'name'        => $info['name'],
                                    'old_version' => $info['old_version'],
                                    'new_version' => $info['new_version'],
                                    'type'        => 'Ufficiale'
                                ];
                            } else {
                                $error_msg = __('Errore sconosciuto', 'marrison-custom-updater');
                                if (is_wp_error($res)) {
                                    $error_msg = $res->get_error_message();
                                } elseif ($res === false) {
                                    $error_msg = __('Aggiornamento fallito', 'marrison-custom-updater');
                                }
                                $failed_updates[] = [
                                    'name' => $info['name'],
                                    'version' => $info['new_version'],
                                    'type' => 'Ufficiale',
                                    'error' => $error_msg
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
                        foreach ($themes as $slug) {
                            $res = isset($result[$slug]) ? $result[$slug] : false;
                            $info = $theme_info_map[$slug];

                            if ($res === true || (is_array($res) && !is_wp_error($res))) {
                                $updated_themes[] = [
                                    'name'        => $info['name'],
                                    'old_version' => $info['old_version'],
                                    'new_version' => $info['new_version']
                                ];
                            } else {
                                $error_msg = is_wp_error($res) ? $res->get_error_message() : __('Errore aggiornamento tema', 'marrison-custom-updater');
                                $failed_updates[] = [
                                    'name' => $info['name'],
                                    'version' => $info['new_version'],
                                    'type' => 'Tema',
                                    'error' => $error_msg
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
            
            
            // --- 4. Raccolta Info Stato Sito ---
            global $wpdb;
            $site_info = [
                'wp_version'   => get_bloginfo('version'),
                'php_version'  => phpversion(),
                'server'       => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'N/A',
                'db_version'   => $wpdb->db_version(),
                'memory_limit' => ini_get('memory_limit'),
                'debug_mode'   => (defined('WP_DEBUG') && WP_DEBUG) ? 'Attivo' : 'Disattivo',
                'site_url'     => get_site_url(),
                'home_url'     => get_home_url()
            ];

            $all_plugins = get_plugins();
            $active_plugins = [];
            $inactive_plugins = [];

            foreach ($all_plugins as $path => $plugin) {
                if (is_plugin_active($path)) {
                    $active_plugins[] = $plugin;
                } else {
                    $inactive_plugins[] = $plugin;
                }
            }

            $log_entry['message'] = 'Translations processed. Preparing email...';
            update_option('marrison_last_cron_log', $log_entry);
            
            // Check Elementor Log if Elementor was updated
            $elementor_db_info = null;
            $elementor_was_updated = false;
            foreach ($updated_plugins as $p) {
                if (stripos($p['name'], 'elementor') !== false) {
                    $elementor_was_updated = true;
                    break;
                }
            }
            if ($elementor_was_updated && is_plugin_active('elementor/elementor.php')) {
                 if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->db)) {
                      if (method_exists(\Elementor\Plugin::$instance->db, 'is_upgrade_required') && \Elementor\Plugin::$instance->db->is_upgrade_required()) {
                           $elementor_db_info = 'Richiesto (Processo in background avviato)';
                      } else {
                           $elementor_db_info = 'Non richiesto (Database già aggiornato)';
                      }
                 } else {
                      $elementor_db_info = 'Impossibile verificare (Elementor non caricato)';
                 }
            }

            $email = get_option('marrison_auto_update_email');
            if ($email) {
                $has_updates = (!empty($updated_plugins) || !empty($updated_themes) || $updated_translations > 0);
                $has_issues = (!empty($failed_updates) || !empty($skipped_updates));
                
                $subject = '[' . get_bloginfo('name') . '] Report Aggiornamento Automatico';
                if ($has_issues) {
                    $subject .= ' - ATTENZIONE: Rilevati Problemi';
                }
                
                // Stili CSS inline per compatibilità email
                $bg_color = "#2c3338"; // Sfondo scuro (WordPress dark grey)
                $container_bg = "#ffffff"; // Sfondo bianco per il contenuto
                
                $style_body_wrapper = "background-color: {$bg_color}; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 40px 0; width: 100%;";
                $style_container = "background-color: {$container_bg}; border-radius: 10px; max-width: 600px; margin: 0 auto; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);";
                
                $style_h2 = "color: #2c3338; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; margin-top: 30px;";
                $style_table = "width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;";
                $style_th = "background-color: #f8f9fa; border: 1px solid #ddd; padding: 10px; text-align: left; font-weight: bold; color: #555;";
                $style_td = "border: 1px solid #ddd; padding: 10px;";
                $style_badge_priv = "background-color: #0073aa; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                $style_badge_off = "background-color: #46b450; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                $style_badge_theme = "background-color: #e65100; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                $style_badge_error = "background-color: #d63638; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                $style_badge_warn = "background-color: #ffb900; color: #333; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                
                $style_footer = "margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px; font-size: 12px; color: #777; text-align: center;";
                $style_section_title = "color: #444; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #0073aa; padding-left: 10px;";
                $style_section_title_error = "color: #d63638; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #d63638; padding-left: 10px;";
                $style_section_title_warn = "color: #e65100; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #ffb900; padding-left: 10px;";

                $style_list_item = "padding: 5px 0; border-bottom: 1px solid #eee; font-size: 13px;";
                $style_list_item_last = "padding: 5px 0; font-size: 13px;";
                $style_info_row = "display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0;";
                $style_info_label = "font-weight: bold; color: #555;";
                $style_info_value = "color: #333;";

                $message_html = '<!DOCTYPE html><html><body style="' . $style_body_wrapper . '">';
                
                // Container Bianco con angoli smussati
                $message_html .= '<div style="' . $style_container . '">';
                
                $message_html .= '<div style="text-align: center; margin-bottom: 20px;">';
                // Logo o Nome Sito
                $custom_logo_id = get_theme_mod('custom_logo');
                $logo_src = '';
                $is_valid_image = false;

                // 1. Prova Logo Principale
                if ($custom_logo_id) {
                    $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
                    if ($logo_data) {
                        $src = $logo_data[0];
                        // Check per SVG (spesso non supportati nei client mail)
                        $path = parse_url($src, PHP_URL_PATH);
                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                        
                        if ($ext !== 'svg') {
                            $logo_src = $src;
                            $is_valid_image = true;
                        }
                    }
                }

                // 2. Fallback su Site Icon (Favicon) se il logo manca o è SVG
                if (!$is_valid_image && function_exists('get_site_icon_url')) {
                    $icon_url = get_site_icon_url(512); // Richiedi alta risoluzione
                    if ($icon_url) {
                        $path = parse_url($icon_url, PHP_URL_PATH);
                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                        
                        if ($ext !== 'svg') {
                            $logo_src = $icon_url;
                            $is_valid_image = true;
                        }
                    }
                }

                if ($is_valid_image && !empty($logo_src)) {
                     $message_html .= '<img src="' . esc_url($logo_src) . '" alt="' . esc_attr(get_bloginfo('name')) . '" style="max-width: 200px; max-height: 100px; width: auto; height: auto; display: inline-block; border: 0; outline: none; text-decoration: none;">';
                } else {
                    $message_html .= '<h1 style="margin: 0; color: #444; font-size: 24px;">' . get_bloginfo('name') . '</h1>';
                }
                $message_html .= '</div>';
                
                // --- STATUS BANNER ---
                $banner_color = "#46b450"; // Verde (Default: Successo)
                $banner_icon = "✅";
                $banner_title = "Tutto Aggiornato";
                $banner_desc = "Il sistema è aggiornato e sicuro. Nessuna azione richiesta.";
                
                if ($has_issues) {
                    $banner_color = "#d63638"; // Rosso (Errore)
                    $banner_icon = "❌";
                    $banner_title = "Attenzione: Rilevati Problemi";
                    $banner_desc = "Alcuni aggiornamenti non sono andati a buon fine o sono stati saltati.";
                } elseif ($has_updates) {
                    $banner_title = "Aggiornamento Riuscito";
                    $banner_desc = "Tutte le operazioni di aggiornamento sono state completate con successo.";
                }
                
                $message_html .= '<div style="background-color: ' . $banner_color . '; color: #ffffff; padding: 20px; text-align: center; border-radius: 6px; margin-bottom: 25px;">';
                $message_html .= '<h2 style="margin: 0; font-size: 22px; color: #ffffff; border: none; font-weight: bold;">' . $banner_icon . ' ' . $banner_title . '</h2>';
                $message_html .= '<p style="margin: 10px 0 0 0; font-size: 15px; opacity: 0.95;">' . $banner_desc . '</p>';
                $message_html .= '</div>';

                $message_html .= '<h2 style="' . $style_h2 . '">Report Aggiornamenti</h2>';
                
                if ($has_updates || $has_issues) {
                    // --- ERRORI ---
                    if (!empty($failed_updates)) {
                        $message_html .= '<h3 style="' . $style_section_title_error . '">❌ Aggiornamenti Falliti</h3>';
                        $message_html .= '<table style="' . $style_table . '">';
                        $message_html .= '<thead><tr>';
                        $message_html .= '<th style="' . $style_th . '">Elemento</th>';
                        $message_html .= '<th style="' . $style_th . '">Tipo</th>';
                        $message_html .= '<th style="' . $style_th . '">Errore</th>';
                        $message_html .= '</tr></thead><tbody>';
                        
                        foreach ($failed_updates as $f) {
                            $message_html .= '<tr>';
                            $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($f['name']) . '</strong></td>';
                            $message_html .= '<td style="' . $style_td . '"><span style="' . $style_badge_error . '">' . esc_html($f['type']) . '</span></td>';
                            $message_html .= '<td style="' . $style_td . '"><span style="color: #d63638;">' . esc_html($f['error']) . '</span></td>';
                            $message_html .= '</tr>';
                        }
                        $message_html .= '</tbody></table>';
                    }

                    // --- SKIPPED ---
                    if (!empty($skipped_updates)) {
                        $message_html .= '<h3 style="' . $style_section_title_warn . '">⚠️ Aggiornamenti Saltati</h3>';
                        $message_html .= '<table style="' . $style_table . '">';
                        $message_html .= '<thead><tr>';
                        $message_html .= '<th style="' . $style_th . '">Elemento</th>';
                        $message_html .= '<th style="' . $style_th . '">Motivo</th>';
                        $message_html .= '</tr></thead><tbody>';
                        
                        foreach ($skipped_updates as $s) {
                            $message_html .= '<tr>';
                            $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($s['name']) . '</strong></td>';
                            $message_html .= '<td style="' . $style_td . '">' . esc_html($s['reason']) . '</td>';
                            $message_html .= '</tr>';
                        }
                        $message_html .= '</tbody></table>';
                    }
                    
                    if (!empty($updated_plugins)) {
                        $message_html .= '<h3 style="' . $style_section_title . '">🔌 Plugin Aggiornati</h3>';
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
                        $message_html .= '<h3 style="' . $style_section_title . '">🎨 Temi Aggiornati</h3>';
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

                // --- SEZIONE STATO DEL SITO ---
                $message_html .= '<h2 style="' . $style_h2 . '">Stato del Sito</h2>';
                
                // Info Sistema
                $message_html .= '<table style="' . $style_table . ' width: 100%; border: none;"><tbody>';
                $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>WordPress:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">v' . $site_info['wp_version'] . '</td></tr>';
                $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>PHP:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">v' . $site_info['php_version'] . '</td></tr>';
                $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Web Server:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['server']) . '</td></tr>';
                $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Memory Limit:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['memory_limit']) . '</td></tr>';
                $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Debug Mode:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['debug_mode']) . '</td></tr>';
                $message_html .= '</tbody></table>';

                // Plugin Attivi
                $message_html .= '<h3 style="' . $style_section_title . '">✅ Plugin Attivi (' . count($active_plugins) . ')</h3>';
                if (!empty($active_plugins)) {
                    $message_html .= '<ul style="list-style-type: none; padding: 0; margin: 0;">';
                    foreach ($active_plugins as $p) {
                        $message_html .= '<li style="' . $style_list_item . '"><strong>' . esc_html($p['Name']) . '</strong> <span style="color: #777; font-size: 12px;">(v' . esc_html($p['Version']) . ')</span></li>';
                    }
                    $message_html .= '</ul>';
                } else {
                    $message_html .= '<p style="font-size: 13px; color: #777;">Nessun plugin attivo.</p>';
                }

                // Plugin Inattivi
                $message_html .= '<h3 style="' . $style_section_title . '; border-left-color: #d63638;">🚫 Plugin Inattivi (' . count($inactive_plugins) . ')</h3>';
                if (!empty($inactive_plugins)) {
                    $message_html .= '<ul style="list-style-type: none; padding: 0; margin: 0;">';
                    foreach ($inactive_plugins as $p) {
                        $message_html .= '<li style="' . $style_list_item . '"><strong>' . esc_html($p['Name']) . '</strong> <span style="color: #777; font-size: 12px;">(v' . esc_html($p['Version']) . ')</span></li>';
                    }
                    $message_html .= '</ul>';
                } else {
                    $message_html .= '<p style="font-size: 13px; color: #777;">Nessun plugin inattivo.</p>';
                }
                
                if ($elementor_db_info) {
                     $message_html .= '<h3 style="' . $style_section_title . '">Elementor Data</h3>';
                     $message_html .= '<div style="background: #f0f0f1; padding: 10px; font-size: 13px; border-left: 4px solid #0073aa; margin-top: 10px;">';
                     $message_html .= '<strong>Stato Aggiornamento DB:</strong> ' . esc_html(is_string($elementor_db_info) ? $elementor_db_info : print_r($elementor_db_info, true));
                     $message_html .= '</div>';
                }
                
                $message_html .= '<div style="' . $style_footer . '">';
                $message_html .= '<a href="' . esc_url(admin_url()) . '" style="color: #0073aa; text-decoration: none;">Accedi al sito</a><br><br>';
                $message_html .= '<span style="font-size: 11px; color: #999;">Powered By <a href="https://marrisonlab.com" target="_blank" style="color: #999; text-decoration: none;">Angelo Marra</a></span>';
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
