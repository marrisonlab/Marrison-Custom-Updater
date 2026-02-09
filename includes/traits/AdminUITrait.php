<?php
if (!trait_exists('Marrison_Admin_UI_Trait')) {
trait Marrison_Admin_UI_Trait {
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'marrison-updater') === false) {
            return;
        }
        wp_enqueue_style('mcu-admin-style', plugin_dir_url(__FILE__) . '../../assets/css/admin-style.css', [], '8.2.0');
        wp_enqueue_script('mcu-admin-script', plugin_dir_url(__FILE__) . '../../assets/js/admin-script.js', ['jquery'], '8.2.0', true);
        wp_localize_script('mcu-admin-script', 'marrisonUpdater', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('marrison_ajax_nonce')
        ]);
    }

    public function add_admin_menu() {
        add_menu_page(
            'AM Updater',
            'AM Updater',
            'manage_options',
            'marrison-updater',
            [$this,'admin_page'],
            'dashicons-update',
            30
        );
        add_submenu_page(
            'marrison-updater',
            'Aggiornamenti',
            'Aggiornamenti',
            'manage_options',
            'marrison-updater',
            [$this, 'admin_page']
        );
        add_submenu_page(
            'marrison-updater',
            'Backup',
            'Backup',
            'manage_options',
            'marrison-updater-backups',
            [$this, 'backup_page']
        );
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
                                        <option value="daily" <?php selected('daily', get_option('marrison_auto_update_frequency')); ?>>Giornaliera</option>
                                        <option value="weekly" <?php selected('weekly', get_option('marrison_auto_update_frequency')); ?>>Settimanale</option>
                                        <option value="monthly" <?php selected('monthly', get_option('marrison_auto_update_frequency')); ?>>Mensile</option>
                                        <option value="biannual" <?php selected('biannual', get_option('marrison_auto_update_frequency')); ?>>Semestrale</option>
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
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <input type="email" id="marrison_auto_update_email" name="marrison_auto_update_email" value="<?php echo esc_attr(get_option('marrison_auto_update_email', get_option('admin_email'))); ?>" class="regular-text">
                                        <button type="button" id="marrison_test_email_btn" class="button button-secondary" data-nonce="<?php echo wp_create_nonce('marrison_test_email'); ?>">Invia mail di test</button>
                                        <span id="marrison_test_email_result" style="font-weight: 600;"></span>
                                    </div>
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
                            
                            $freq_slug = get_option('marrison_auto_update_frequency', 'daily');
                            $freq_labels = [
                                'daily' => 'Giornaliera',
                                'weekly' => 'Settimanale',
                                'monthly' => 'Mensile',
                                'biannual' => 'Semestrale'
                            ];
                            $freq_label = isset($freq_labels[$freq_slug]) ? $freq_labels[$freq_slug] : $freq_slug;
                        ?>
                            <div class="mcu-notice mcu-notice-info" style="margin-top: 20px;">
                                <span class="dashicons dashicons-clock"></span> Prossima esecuzione programmata: <strong><?php echo $date->format('d/m/Y H:i'); ?></strong> <small>(Frequenza: <?php echo esc_html($freq_label); ?>)</small>
                            </div>
                        <?php endif; ?>
                        <?php 
                        $last_log = get_option('marrison_last_cron_log');
                        if ($last_log && is_array($last_log)): 
                        ?>
                            <div class="mcu-card" style="margin-top: 20px; border-left: 4px solid <?php echo ($last_log['status'] === 'completed' && (!isset($last_log['email_sent']) || $last_log['email_sent'])) ? 'var(--mcu-success)' : 'var(--mcu-danger)'; ?>;">
                                <h3 style="margin-top: 0;">Ultima Esecuzione</h3>
                                <p><strong>Data:</strong> <?php echo esc_html($last_log['time']); ?></p>
                                <p><strong>Stato:</strong> <?php echo esc_html($last_log['status']); ?></p>
                                <p><strong>Messaggio:</strong> <?php echo esc_html($last_log['message']); ?></p>
                                <?php if (isset($last_log['updates_found'])): ?>
                                    <p><strong>Aggiornamenti Trovati:</strong> <?php echo $last_log['updates_found'] ? 'Sì' : 'No'; ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div style="margin-top: 20px;">
                            <button class="mcu-button mcu-button-primary" type="submit">Salva Programmazione</button>
                        </div>
                    </form>
                </div>
            <?php elseif ($active_tab == 'howto'): ?>
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
        if ($type === 'theme') {
            $source_dir = plugin_dir_path(__FILE__) . '../../add_this_file_for_themes_repo/';
            $file = $source_dir . 'index.php';
            $filename = 'index-themes.php';
        } else {
            $source_dir = plugin_dir_path(__FILE__) . '../../add_this_file_for_plugin_repo/';
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
                $('#marrison-select-all').on('change', function() {
                    $('.marrison-plugin-cb').prop('checked', $(this).is(':checked'));
                });
                $('#marrison-install-form').on('submit', function(e) {
                    var selected = $('.marrison-plugin-cb:checked');
                    if (selected.length === 0) {
                        alert('Seleziona almeno un plugin da installare.');
                        return false;
                    }
                    e.preventDefault();
                    var plugins = [];
                    selected.each(function() { plugins.push($(this).val()); });
                    var total = plugins.length;
                    var processed = 0;
                    var success = 0;
                    $('#marrison-install-progress').slideDown();
                    $('#marrison-install-btn').prop('disabled', true).text('Installazione in corso...');
                    $('.marrison-plugin-cb').prop('disabled', true);
                    function processNext() {
                        if (processed >= total) {
                            $('#marrison-install-status').text('Completato!');
                            $('#marrison-install-info').text(success + ' su ' + total + ' plugin installati correttamente. Ricaricamento...');
                            setTimeout(function() { location.reload(); }, 1500);
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
                                action: 'marrison_update_plugin_ajax',
                                slug: slug,
                                nonce: '<?php echo wp_create_nonce("marrison_bulk_update"); ?>'
                            },
                            success: function(response) {
                                if (response.success) { success++; } else { console.error('Errore installazione ' + slug + ':', response); }
                            },
                            error: function(xhr, status, error) { console.error('Errore AJAX ' + slug + ':', error); },
                            complete: function() {
                                processed++;
                                $('#marrison-install-bar').css('width', Math.round((processed / total) * 100) + '%');
                                processNext();
                            }
                        });
                    }
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
                usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
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
        $repo_url_config = get_option('marrison_repo_url');
        $theme_repo_url_config = get_option('marrison_themes_repo_url');
        $transient_plugins = get_site_transient('update_plugins');
        $public_plugin_updates_count = 0;
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
        $transient_themes = get_site_transient('update_themes');
        $public_theme_updates_count = 0;
        $private_theme_slugs = [];
        foreach ($theme_updates as $u) {
            $private_theme_slugs[] = $u['slug'];
        }
        if (!empty($transient_themes->response)) {
            foreach ($transient_themes->response as $slug => $data) {
                if (in_array($slug, $private_theme_slugs)) continue;
                $public_theme_updates_count++;
            }
        }
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
            <?php 
                $auto_update_plugins = (array) get_site_option('auto_update_plugins', []);
                $transient = get_site_transient('update_plugins');
                $private_updates = $this->get_available_updates();
                $private_files = [];
                foreach ($private_updates as $u) {
                    $found_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                    if ($found_file) $private_files[] = $found_file;
                }
                $private_slugs = [];
                foreach ($private_files as $pf) {
                    $private_slugs[] = dirname($pf);
                    $private_slugs[] = basename($pf, '.php');
                }
                $private_slugs = array_values(array_filter(array_unique($private_slugs), function($s){
                    return $s !== '.' && $s !== '';
                }));
                $plugins_with_auto_update = [];
                if (!empty($transient->response)) {
                    foreach ($transient->response as $file => $data) {
                        $slug = isset($data->slug) ? $data->slug : dirname($file);
                        if ($slug === '.') $slug = basename($file, '.php');
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

        // Increase execution time to avoid timeouts during multiple remote checks
        @set_time_limit(0);

        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/file.php';
        include_once ABSPATH . 'wp-admin/includes/misc.php';
        include_once ABSPATH . 'wp-admin/includes/template.php';
        include_once ABSPATH . 'wp-admin/includes/translation-install.php';
        
        // Force refresh of all update transients to ensure we get the latest translation data
        delete_site_transient('update_core');
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        
        // Trigger checks
        wp_version_check();
        wp_update_plugins();
        wp_update_themes();
        
        $translations = wp_get_translation_updates();
        if (empty($translations)) {
             wp_send_json_error('Nessun aggiornamento traduzioni disponibile dopo il controllo forzato.');
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
             wp_send_json_success('Processo completato.');
        }
    }

    public function get_all_updates_data() {
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
        wp_update_plugins();
        $transient = get_site_transient('update_plugins');
        $official_to_update = [];
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
        if (!empty($transient->response)) {
            foreach ($transient->response as $file => $data) {
                if (in_array($file, $private_files)) continue;
                $slug = isset($data->slug) ? $data->slug : dirname($file);
                if ($slug === '.') $slug = basename($file, '.php');
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
        wp_update_themes();
        $theme_updates = get_site_transient('update_themes');
        $themes_count = !empty($theme_updates->response) ? count($theme_updates->response) : 0;
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

    public function add_menu_notification_badge() {
        $update_count = get_option('marrison_available_updates_count', 0);
        if ($update_count > 0) {
            global $menu;
            foreach ($menu as $key => $item) {
                if (isset($item[2]) && $item[2] === 'marrison-updater') {
                    $menu[$key][0] .= ' <span class="marrison-update-badge awaiting-mod count-' . $update_count . '">' . $update_count . '</span>';
                    break;
                }
            }
        }
    }

    public function add_menu_badge_styles() {
        $icon_url = plugin_dir_url(__FILE__) . '../../assets/icon.svg';
        ?>
        <style>
        #toplevel_page_marrison-updater .wp-menu-image:before { display: none; }
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
        #adminmenu .marrison-update-badge { position: relative; top: -1px; left: 2px; }
        #adminmenu .wp-submenu a[href="admin.php?page=marrison-updater"] { position: relative; }
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
}
