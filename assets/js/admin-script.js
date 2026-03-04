jQuery(document).ready(function($) {
    console.log('WP Master Updater JS Loaded');
    
    // UI Helpers
    const MCU = {
        showProgress: function(title) {
            $('.mcu-progress-container').slideDown();
            // Scroll to progress bar
            $('html, body').animate({
                scrollTop: $('.mcu-progress-container').offset().top - 50
            }, 500);
            
            $('#mcu-progress-title').text(title || 'Elaborazione in corso...');
            this.updateProgress(0, 'Avvio...');
        },
        
        hideProgress: function() {
            setTimeout(function() {
                $('.mcu-progress-container').slideUp();
            }, 2000);
        },
        
        updateProgress: function(percent, status) {
            $('.mcu-progress-bar').css('width', percent + '%');
            $('#mcu-progress-status-text').text(status);
        },

        toast: function(message, type) {
            // Simple toast implementation or fallback to alert/notice
            // For now, let's use the progress bar completion state as a "toast"
            // or append a notice to the header
            const noticeClass = type === 'success' ? 'mcu-notice-success' : 'mcu-notice-error';
            const icon = type === 'success' ? 'yes' : 'warning';
            
            const html = `
                <div class="mcu-notice ${noticeClass}" style="display:none">
                    <span class="dashicons dashicons-${icon}"></span>
                    ${message}
                    <button type="button" class="notice-dismiss" style="margin-left:auto;background:none;border:none;cursor:pointer;"><span class="dashicons dashicons-dismiss"></span></button>
                </div>
            `;
            
            const $notice = $(html);
            $('.mcu-header').after($notice);
            $notice.slideDown();
            
            setTimeout(() => {
                $notice.slideUp(() => $notice.remove());
            }, 5000);
        }
    };

    $(document).on('click', '.notice-dismiss', function() {
        $(this).closest('.mcu-notice').slideUp(() => $(this).closest('.mcu-notice').remove());
    });
        // --- Restore Backup Handler ---
    $(document).on('click', '.mcu-action-restore', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var filename = $btn.data('filename');
        var nonce = $btn.data('nonce');
        
        if (!confirm('Sei sicuro di voler ripristinare questo backup? L\'attuale versione verrà sovrascritta.')) {
            return;
        }

        $btn.prop('disabled', true).addClass('updating').html('<span class="dashicons dashicons-update-alt dashicons-spin"></span> Ripristino...');
        MCU.showProgress('Ripristino backup in corso...');
        MCU.updateProgress(10, 'Preparazione ripristino...');

        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_restore_plugin_ajax',
                filename: filename,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $btn.removeClass('updating').addClass('updated').prop('disabled', true).html('<span class="dashicons dashicons-yes"></span> Ripristinato');
                    MCU.updateProgress(100, 'Ripristino completato!');
                    MCU.toast('Backup ripristinato con successo!', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $btn.prop('disabled', false).removeClass('updating').html('<span class="dashicons dashicons-undo"></span> Ripristina');
                    MCU.updateProgress(100, 'Errore!');
                    MCU.toast('Errore: ' + (response.data || 'Sconosciuto'), 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).removeClass('updating').html('<span class="dashicons dashicons-undo"></span> Ripristina');
                MCU.updateProgress(100, 'Errore di connessione');
                MCU.toast('Errore di connessione al server.', 'error');
            }
        });
    });

    // Monitoring Sync removed


    // --- Single Update Handler ---
    $('.mcu-action-update').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var slug = $btn.data('slug');
        var nonce = $btn.data('nonce');
        var type = $btn.data('type') || 'plugin';
        var version = $btn.data('version');
        
        var action = (type === 'theme') ? 'marrison_update_private_theme_ajax' : 'marrison_update_plugin_ajax';
        var itemLabel = (type === 'theme') ? 'Tema' : 'Plugin';
        
        if($btn.prop('disabled')) return;

        // UI Updates
        $btn.prop('disabled', true).addClass('updating').html('<span class="dashicons dashicons-update-alt dashicons-spin"></span>');
        MCU.showProgress('Aggiornamento ' + itemLabel);
        
        // Fake progress simulation
        var progress = 0;
        var progressInterval = setInterval(function() {
            progress += Math.random() * 5; // Slower increment
            if (progress > 90) progress = 90;
            
            let statusText = 'Scaricamento pacchetto...';
            if (progress > 30) statusText = 'Estrazione file...';
            if (progress > 60) statusText = 'Installazione...';
            
            MCU.updateProgress(progress, statusText);
        }, 800); // Slower interval
        
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: action,
                slug: slug,
                nonce: nonce
            },
            success: function(response) {
                clearInterval(progressInterval);
                MCU.updateProgress(100, 'Completato!');
                
                if (response.success) {
                    $btn.removeClass('updating').addClass('updated').html('<span class="dashicons dashicons-yes"></span> Aggiornato');
                    $btn.closest('tr').find('.mcu-badge-warning').removeClass('mcu-badge-warning').addClass('mcu-badge-success').text(version);
                    // Remove arrow and new version badge
                    $btn.closest('tr').find('.dashicons-arrow-right-alt2, .mcu-badge-success').remove();
                    
                    MCU.toast(itemLabel + ' aggiornato con successo!', 'success');
                    
                    // Reload to reflect changes globally
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    $btn.prop('disabled', false).removeClass('updating').html('Riprova');
                    MCU.updateProgress(100, 'Errore!');
                    MCU.toast('Errore: ' + (response.data || 'Sconosciuto'), 'error');
                }
            },
            error: function() {
                clearInterval(progressInterval);
                $btn.prop('disabled', false).removeClass('updating').html('Riprova');
                MCU.updateProgress(100, 'Errore di connessione');
                MCU.toast('Errore di connessione al server.', 'error');
            }
        });
    });

    // --- Bulk Update Handler ---
    $('.mcu-action-bulk-update-private').on('click', function(e) {
        e.preventDefault();
        var type = $(this).data('type') || 'plugin'; // plugin or theme
        var selector = (type === 'theme') ? 'input[name="themes[]"]:checked' : 'input[name="plugins[]"]:checked';
        var selected = $(selector);
        
        if (selected.length === 0) {
            alert('Seleziona almeno un elemento da aggiornare.');
            return;
        }

        if (!confirm('Sei sicuro di voler aggiornare ' + selected.length + ' elementi?')) {
            return;
        }

        MCU.showProgress('Aggiornamento massivo in corso...');
        var total = selected.length;
        var processed = 0;
        var successCount = 0;
        var failCount = 0;

        // Disable buttons
        $('.mcu-action-bulk-update-private').prop('disabled', true);
        selected.prop('disabled', true);

        function processNext(index) {
            if (index >= total) {
                MCU.updateProgress(100, 'Tutti gli aggiornamenti completati.');
                setTimeout(function() {
                    location.reload();
                }, 1000);
                return;
            }

            var $checkbox = $(selected[index]);
            var slug = $checkbox.val();
            var nonce = $checkbox.data('nonce');
            var action = (type === 'theme') ? 'marrison_update_private_theme_ajax' : 'marrison_update_plugin_ajax';

            MCU.updateProgress(Math.round((index / total) * 100), 'Aggiornamento: ' + slug + '...');

            $.ajax({
                url: marrisonUpdater.ajaxurl,
                type: 'POST',
                data: {
                    action: action,
                    slug: slug,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        successCount++;
                    } else {
                        failCount++;
                        console.error('Update failed for ' + slug, response);
                    }
                },
                error: function() {
                    failCount++;
                },
                complete: function() {
                    processed++;
                    processNext(index + 1);
                }
            });
        }

        processNext(0);
    });

    // --- Update All (Repo + Public) ---
    $('.mcu-action-update-all').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var nonceAll = $btn.attr('data-nonce-all');
        var nonceBulk = $btn.attr('data-nonce-bulk');
        var nonceAuto = $btn.attr('data-nonce-auto');
        
        if (!confirm('Sei sicuro di voler aggiornare tutti i plugin, temi e traduzioni?')) {
            return;
        }

        $btn.prop('disabled', true).addClass('updating');
        MCU.showProgress('Controllo aggiornamenti in corso...');
        MCU.updateProgress(5, 'Recupero lista aggiornamenti...');

        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_get_all_updates_ajax',
                nonce: nonceAll
            },
            success: function(response) {
                if (!response.success) {
                    MCU.updateProgress(100, 'Errore: ' + (response.data || 'Sconosciuto'));
                    MCU.toast('Errore durante il recupero degli aggiornamenti', 'error');
                    $btn.prop('disabled', false).removeClass('updating');
                    return;
                }

                var data = response.data;
                var queue = [];

                // 1. Private Plugins
                if (data.plugins_private && data.plugins_private.length > 0) {
                    data.plugins_private.forEach(function(plugin) {
                        queue.push({
                            type: 'plugin_private',
                            label: 'Plugin Privato: ' + (plugin.name || plugin.slug),
                            slug: plugin.slug,
                            nonce: nonceBulk
                        });
                    });
                }

                // 2. Official Plugins
                if (data.plugins_official && data.plugins_official.length > 0) {
                    data.plugins_official.forEach(function(plugin) {
                        queue.push({
                            type: 'plugin_official',
                            label: 'Plugin Ufficiale: ' + (plugin.name || plugin.slug),
                            file: plugin.file,
                            package: plugin.package,
                            new_version: plugin.version,
                            nonce: nonceAuto
                        });
                    });
                }

                // 3. Themes
                if (data.themes_count > 0) {
                    queue.push({
                        type: 'themes',
                        label: 'Temi (' + data.themes_count + ')',
                        nonce: nonceAll // or nonceAuto depending on backend
                    });
                }

                // 4. Translations
                if (data.translations_count > 0) {
                    queue.push({
                        type: 'translations',
                        label: 'Traduzioni (' + data.translations_count + ')',
                        nonce: nonceAuto
                    });
                }

                if (queue.length === 0) {
                    MCU.updateProgress(100, 'Nessun aggiornamento necessario.');
                    MCU.toast('Tutto aggiornato!', 'success');
                    $btn.prop('disabled', false).removeClass('updating');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                    return;
                }

                // Process Queue
                var total = queue.length;
                var processed = 0;
                var successCount = 0;
                var failCount = 0;

                function processQueue(index) {
                    if (index >= total) {
                        MCU.updateProgress(100, 'Tutti gli aggiornamenti completati!');
                        MCU.toast('Aggiornamento massivo completato.', 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                        return;
                    }

                    var item = queue[index];
                    var progress = Math.round(((index + 1) / total) * 100);
                    MCU.updateProgress(progress, 'Aggiornamento in corso: ' + item.label + '...');

                    var ajaxData = {};
                    
                    if (item.type === 'plugin_private') {
                        ajaxData = {
                            action: 'marrison_update_plugin_ajax',
                            slug: item.slug,
                            nonce: item.nonce
                        };
                    } else if (item.type === 'plugin_official') {
                        ajaxData = {
                            action: 'marrison_update_official_plugin_ajax',
                            file: item.file,
                            package: item.package,
                            new_version: item.new_version,
                            nonce: item.nonce
                        };
                    } else if (item.type === 'themes') {
                        ajaxData = {
                            action: 'marrison_update_all_themes_ajax',
                            nonce: item.nonce
                        };
                    } else if (item.type === 'translations') {
                        ajaxData = {
                            action: 'marrison_update_translations_ajax',
                            nonce: item.nonce
                        };
                    }

                    $.ajax({
                        url: marrisonUpdater.ajaxurl,
                        type: 'POST',
                        data: ajaxData,
                        success: function(res) {
                            if (res.success) {
                                successCount++;
                            } else {
                                failCount++;
                                console.error('Update failed for ' + item.label, res);
                            }
                        },
                        error: function() {
                            failCount++;
                        },
                        complete: function() {
                            processed++;
                            processQueue(index + 1);
                        }
                    });
                }

                processQueue(0);
            },
            error: function() {
                MCU.updateProgress(100, 'Errore di connessione');
                MCU.toast('Errore di connessione al server.', 'error');
                $btn.prop('disabled', false).removeClass('updating');
            }
        });
    });

    // --- Clear Cache ---
    // Handled by form submit in PHP

    // --- Checkbox Select All ---
    $('#cb-select-all-1').on('change', function() {
        $('input[name="plugins[]"]').prop('checked', $(this).is(':checked'));
    });
    
    $('#cb-select-all-themes').on('change', function() {
        $('input[name="themes[]"]').prop('checked', $(this).is(':checked'));
    });

    // --- Exclusion Toggle Handler ---
    $(document).on('change', '.marrison_exclude_toggle', function() {
        const $toggle = $(this);
        const slug = $toggle.data('slug');
        const type = $toggle.data('type');
        const isChecked = $toggle.is(':checked');
        
        $toggle.prop('disabled', true);
        
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_toggle_exclusion',
                nonce: marrisonUpdater.toggle_exclusion_nonce,
                slug: slug,
                type: type,
                state: isChecked ? 1 : 0
            },
            success: function(response) {
                $toggle.prop('disabled', false);
                if (response.success) {
                    MCU.toast('Impostazione salvata: ' + (isChecked ? 'Escluso' : 'Incluso'), 'success');
                    // Optionally reload or update counters if needed immediately
                    // For now, a toast is enough feedback
                } else {
                    $toggle.prop('checked', !isChecked); // Revert
                    MCU.toast('Errore nel salvataggio', 'error');
                }
            },
            error: function() {
                $toggle.prop('disabled', false);
                $toggle.prop('checked', !isChecked); // Revert
                MCU.toast('Errore di connessione', 'error');
            }
        });
    });

});
