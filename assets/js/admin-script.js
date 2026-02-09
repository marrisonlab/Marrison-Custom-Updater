jQuery(document).ready(function($) {
    console.log('Marrison Custom Updater JS Loaded');
    
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

    // Monitoring Sync
    $('#mcu_sync_monitoring').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $result = $('#mcu_sync_result');
        
        $btn.prop('disabled', true).text('Sincronizzazione...');
        $result.text('').removeClass('mcu-text-success mcu-text-error');
        
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_sync_monitoring',
                nonce: marrisonUpdater.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.text('Sincronizzato!').css('color', 'green');
                    MCU.toast('Stato inviato al master con successo.', 'success');
                } else {
                    $result.text('Errore').css('color', 'red');
                    MCU.toast('Errore: ' + (response.data || 'Errore sconosciuto'), 'error');
                }
            },
            error: function() {
                $result.text('Errore di connessione').css('color', 'red');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Sincronizza Ora');
            }
        });
    });

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
                
                if (response.success) {
                    MCU.updateProgress(100, 'Completato!');
                    MCU.toast(itemLabel + ' aggiornato con successo!', 'success');
                    
                    $btn.removeClass('mcu-button-primary').addClass('mcu-button-secondary')
                        .html('<span class="dashicons dashicons-yes"></span> Aggiornato')
                        .css('color', 'var(--mcu-success)')
                        .css('border-color', 'var(--mcu-success)');
                        
                    // Update version in row if possible
                    $btn.closest('tr').find('.current-version').text(version).css('color', 'var(--mcu-success)');
                    
                    MCU.updateProgress(100, 'Ricaricamento pagina...');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    MCU.updateProgress(0, 'Errore');
                    MCU.toast(response.data || 'Errore durante l\'aggiornamento', 'error');
                    $btn.prop('disabled', false).removeClass('updating').text('Riprova');
                }
                MCU.hideProgress();
            },
            error: function() {
                clearInterval(progressInterval);
                MCU.updateProgress(0, 'Errore di connessione');
                MCU.toast('Errore di connessione al server', 'error');
                $btn.prop('disabled', false).removeClass('updating').text('Riprova');
                MCU.hideProgress();
            }
        });
    });

    // --- Bulk Private Update ---
    $('.mcu-action-bulk-update-private').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var type = $btn.data('type'); // 'plugin' or 'theme'
        var $checkboxes;
        
        if (type === 'plugin') {
            $checkboxes = $('input[name="plugins[]"]:checked');
        } else {
            $checkboxes = $('input[name="themes[]"]:checked');
        }
        
        if ($checkboxes.length === 0) {
            alert('Seleziona almeno un elemento da aggiornare');
            return;
        }
        
        if(!confirm('Vuoi aggiornare ' + $checkboxes.length + ' elementi selezionati?')) return;
        
        // Disable button
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt dashicons-spin"></span> Avvio...');
        MCU.showProgress('Aggiornamento Multiplo (' + (type === 'plugin' ? 'Plugin' : 'Temi') + ')');
        
        var items = [];
        $checkboxes.each(function() {
            items.push({
                slug: $(this).val(),
                nonce: $(this).data('nonce')
            });
        });
        
        var currentIndex = 0;
        var successCount = 0;
        var failedItems = [];
        
        function updateNextItem() {
            if (currentIndex >= items.length) {
                // Done
                if (successCount > 0) {
                    MCU.updateProgress(100, 'Completato!');
                    MCU.toast('Aggiornati ' + successCount + ' elementi su ' + items.length, 'success');
                    
                    MCU.updateProgress(100, 'Ricaricamento pagina...');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    MCU.updateProgress(0, 'Errore');
                    MCU.toast('Nessun elemento aggiornato', 'error');
                    $btn.prop('disabled', false).text('Aggiorna Selezionati');
                }
                MCU.hideProgress();
                return;
            }
            
            var item = items[currentIndex];
            var slug = item.slug;
            var nonce = item.nonce;
            var action = (type === 'theme') ? 'marrison_update_private_theme_ajax' : 'marrison_update_plugin_ajax';
            
            var percent = Math.round((currentIndex / items.length) * 100);
            MCU.updateProgress(percent, 'Aggiornamento: ' + slug);
            
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
                        failedItems.push(slug);
                    }
                    currentIndex++;
                    updateNextItem();
                },
                error: function() {
                    failedItems.push(slug);
                    currentIndex++;
                    updateNextItem();
                }
            });
        }
        
        updateNextItem();
    });

    // --- Bulk Auto Update (Official) ---
    $('.mcu-action-auto-update').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var nonce = $btn.data('nonce');
        
        if(!confirm('Vuoi davvero aggiornare tutti i plugin ufficiali?')) return;
        
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt dashicons-spin"></span> Analisi...');
        MCU.showProgress('Aggiornamento Multiplo (Ufficiali)');
        MCU.updateProgress(5, 'Recupero lista aggiornamenti...');
        
        // 1. Get list of updates
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_get_official_updates_ajax',
                nonce: nonce
            },
            success: function(response) {
                if (!response.success) {
                    MCU.toast(response.data || 'Errore recupero lista', 'error');
                    $btn.prop('disabled', false).html('Aggiorna tutti i plugin ufficiali');
                    MCU.hideProgress();
                    return;
                }
                
                var items = response.data;
                if (items.length === 0) {
                    MCU.updateProgress(100, 'Nessun aggiornamento necessario');
                    setTimeout(() => MCU.hideProgress(), 2000);
                    $btn.prop('disabled', false).html('Aggiorna tutti i plugin ufficiali');
                    return;
                }
                
                // 2. Process updates sequentially
                var currentIndex = 0;
                var successCount = 0;
                var total = items.length;
                
                function updateNextItem() {
                    if (currentIndex >= total) {
                        // Done
                        if (successCount > 0) {
                            MCU.updateProgress(100, 'Completato!');
                            MCU.toast(successCount + ' plugin ufficiali aggiornati', 'success');
                            
                            MCU.updateProgress(100, 'Ricaricamento pagina...');
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            MCU.updateProgress(0, 'Errore');
                            MCU.toast('Nessun plugin aggiornato', 'error');
                            $btn.prop('disabled', false).html('Aggiorna tutti i plugin ufficiali');
                            MCU.hideProgress();
                        }
                        return;
                    }
                    
                    var item = items[currentIndex];
                    // Ensure percentage grows but leaves room for completion
                    var percent = Math.round((currentIndex / total) * 90); 
                    if (percent < 10) percent = 10;
                    
                    MCU.updateProgress(percent, 'Aggiornamento: ' + item.name + ' (' + (currentIndex + 1) + '/' + total + ')');
                    
                    $.ajax({
                        url: marrisonUpdater.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'marrison_update_official_plugin_ajax',
                            file: item.file,
                            package: item.package,
                            new_version: item.version,
                            nonce: nonce
                        },
                        success: function(res) {
                            if (res.success) {
                                successCount++;
                            }
                            currentIndex++;
                            // Small delay to let UI breathe
                            setTimeout(updateNextItem, 500);
                        },
                        error: function() {
                            currentIndex++;
                            setTimeout(updateNextItem, 500);
                        }
                    });
                }
                
                updateNextItem();
            },
            error: function() {
                MCU.toast('Errore di connessione', 'error');
                $btn.prop('disabled', false).html('Aggiorna tutti i plugin ufficiali');
                MCU.hideProgress();
            }
        });
    });

    // --- Update All Themes (Official + Private) ---
    $('.marrison-update-themes-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var nonce = $btn.data('nonce');
        
        if(!confirm('Vuoi davvero aggiornare tutti i temi?')) return;
        
        $btn.prop('disabled', true);
        MCU.showProgress('Aggiornamento Temi');
        MCU.updateProgress(10, 'Analisi temi...');
        
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_update_all_themes_ajax',
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    MCU.updateProgress(100, 'Completato!');
                    MCU.toast(response.data || 'Temi aggiornati', 'success');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    MCU.updateProgress(100, 'Nessun aggiornamento');
                    MCU.toast(response.data || 'Nessun tema da aggiornare', 'info'); // Use info/warning style logic if available, otherwise it falls back
                    setTimeout(MCU.hideProgress, 2000);
                    $btn.prop('disabled', false);
                }
            },
            error: function() {
                MCU.updateProgress(0, 'Errore');
                MCU.toast('Errore di connessione', 'error');
                $btn.prop('disabled', false);
                setTimeout(MCU.hideProgress, 2000);
            }
        });
    });

    // --- Update All Translations ---
    $('.marrison-update-translations-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var nonce = $btn.data('nonce');
        
        if(!confirm('Vuoi aggiornare tutte le traduzioni?')) return;
        
        $btn.prop('disabled', true);
        MCU.showProgress('Aggiornamento Traduzioni');
        MCU.updateProgress(10, 'Analisi traduzioni...');
        
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_update_translations_ajax',
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    MCU.updateProgress(100, 'Completato!');
                    MCU.toast(response.data || 'Traduzioni aggiornate', 'success');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    MCU.updateProgress(100, 'Nessun aggiornamento');
                    MCU.toast(response.data || 'Nessuna traduzione da aggiornare', 'info');
                    setTimeout(MCU.hideProgress, 2000);
                    $btn.prop('disabled', false);
                }
            },
            error: function() {
                MCU.updateProgress(0, 'Errore');
                MCU.toast('Errore di connessione', 'error');
                $btn.prop('disabled', false);
                setTimeout(MCU.hideProgress, 2000);
            }
        });
    });

    // --- Restore Backup Handler ---
    $('.mcu-action-restore').on('click', function(e) {
        e.preventDefault();
        if (!confirm('Sei sicuro di voler ripristinare questo backup? La versione corrente verrà sovrascritta.')) return;
        
        var $btn = $(this);
        var filename = $btn.data('filename');
        var nonce = $btn.data('nonce');
        
        $btn.prop('disabled', true).text('Ripristino...');
        MCU.showProgress('Ripristino Backup: ' + filename);
        
        // Fake animation
        var p = 0;
        var interval = setInterval(() => {
            p += 5; if(p>90) p=90;
            MCU.updateProgress(p, 'Ripristino in corso...');
        }, 500);
        
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_restore_plugin_ajax',
                filename: filename,
                nonce: nonce
            },
            success: function(response) {
                clearInterval(interval);
                if(response.success) {
                    MCU.updateProgress(100, 'Ripristinato!');
                    MCU.toast('Backup ripristinato con successo', 'success');
                    
                    MCU.updateProgress(100, 'Ricaricamento pagina...');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    MCU.updateProgress(0, 'Errore');
                    MCU.toast(response.data || 'Errore ripristino', 'error');
                    $btn.prop('disabled', false).text('Ripristina');
                }
                MCU.hideProgress();
            },
            error: function() {
                clearInterval(interval);
                MCU.toast('Errore di connessione', 'error');
                $btn.prop('disabled', false).text('Ripristina');
                MCU.hideProgress();
            }
        });
    });

    // --- Cache Clear ---
    $('.mcu-action-clear-cache').on('click', function(e) {
        // This is usually a form submit, but we can animate the button
        $(this).addClass('updating').html('<span class="dashicons dashicons-update-alt dashicons-spin"></span> Pulizia...');
    });

    // --- Select All Checkboxes ---
    $('#cb-select-all-1').on('change', function() {
        var checked = $(this).is(':checked');
        $('input[name="plugins[]"]').prop('checked', checked);
    });

    $('#cb-select-all-themes').on('change', function() {
        var checked = $(this).is(':checked');
        $('input[name="themes[]"]').prop('checked', checked);
    });

    // --- Update All Handler ---
    $('.mcu-action-update-all').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var nonceAuto = $btn.data('nonce-auto');
        var nonceBulk = $btn.data('nonce-bulk');
        var nonceAll = $btn.data('nonce-all');

        if (!confirm('Sei sicuro di voler aggiornare TUTTO (plugin, temi e traduzioni)?')) return;

        $btn.prop('disabled', true);
        MCU.showProgress('Analisi aggiornamenti...');
        MCU.updateProgress(5, 'Recupero lista aggiornamenti...');

        // 1. Get List
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_get_all_updates_ajax',
                nonce: nonceAll
            },
            success: function(res) {
                if (!res.success) {
                    MCU.updateProgress(0, 'Errore');
                    MCU.toast(res.data || 'Errore recupero aggiornamenti', 'error');
                    $btn.prop('disabled', false);
                    setTimeout(MCU.hideProgress, 2000);
                    return;
                }

                var data = res.data;
                var queue = [];
                
                // Build Queue
                // Private Plugins
                if (data.plugins_private && data.plugins_private.length > 0) {
                    data.plugins_private.forEach(function(p) {
                        queue.push({
                            type: 'private_plugin',
                            slug: p.slug,
                            name: p.name,
                            version: p.version
                        });
                    });
                }
                
                // Official Plugins
                if (data.plugins_official && data.plugins_official.length > 0) {
                    data.plugins_official.forEach(function(p) {
                        queue.push({
                            type: 'official_plugin',
                            file: p.file,
                            name: p.name,
                            version: p.version,
                            package: p.package
                        });
                    });
                }
                
                // Themes (Bulk)
                if (data.themes_count > 0) {
                    queue.push({
                        type: 'themes_all',
                        name: 'Tutti i Temi',
                        count: data.themes_count
                    });
                }
                
                // Translations (Bulk)
                if (data.translations_count > 0) {
                    queue.push({
                        type: 'translations_all',
                        name: 'Traduzioni',
                        count: data.translations_count
                    });
                }

                if (queue.length === 0) {
                    MCU.updateProgress(100, 'Tutto aggiornato!');
                    MCU.toast('Nessun aggiornamento disponibile', 'success');
                    $btn.prop('disabled', false);
                    setTimeout(MCU.hideProgress, 2000);
                    return;
                }

                // Process Queue
                var total = queue.length;
                var current = 0;
                var successCount = 0;

                function processNext() {
                    if (current >= total) {
                        MCU.updateProgress(100, 'Tutto completato!');
                        MCU.toast('Aggiornamento completo terminato', 'success');
                        
                        MCU.updateProgress(100, 'Ricaricamento pagina...');
                        setTimeout(() => location.reload(), 2000);
                        return;
                    }

                    var item = queue[current];
                    var percent = Math.round(((current) / total) * 90);
                    if (percent < 5) percent = 5;
                    
                    MCU.updateProgress(percent, 'Aggiornamento: ' + item.name + ' (' + (current + 1) + '/' + total + ')');

                    var ajaxData = {};
                    var action = '';
                    var nonce = '';

                    if (item.type === 'private_plugin') {
                        action = 'marrison_update_plugin_ajax';
                        nonce = nonceBulk; // Use bulk nonce for private plugins
                        ajaxData = {
                            action: action,
                            slug: item.slug,
                            nonce: nonce
                        };
                    } else if (item.type === 'official_plugin') {
                        action = 'marrison_update_official_plugin_ajax';
                        nonce = nonceAuto;
                        ajaxData = {
                            action: action,
                            file: item.file,
                            package: item.package,
                            new_version: item.version,
                            nonce: nonce
                        };
                    } else if (item.type === 'themes_all') {
                        action = 'marrison_update_all_themes_ajax';
                        nonce = nonceAuto;
                        ajaxData = {
                            action: action,
                            nonce: nonce
                        };
                    } else if (item.type === 'translations_all') {
                        action = 'marrison_update_translations_ajax';
                        nonce = nonceAuto;
                        ajaxData = {
                            action: action,
                            nonce: nonce
                        };
                    }

                    $.ajax({
                        url: marrisonUpdater.ajaxurl,
                        type: 'POST',
                        data: ajaxData,
                        success: function(response) {
                            if (response.success) {
                                successCount++;
                            }
                            current++;
                            // Small delay
                            setTimeout(processNext, 500);
                        },
                        error: function() {
                            current++;
                            setTimeout(processNext, 500);
                        }
                    });
                }

                processNext();

            },
            error: function() {
                MCU.updateProgress(0, 'Errore');
                MCU.toast('Errore di connessione', 'error');
                $btn.prop('disabled', false);
                setTimeout(MCU.hideProgress, 2000);
            }
        });
    });

    // --- Test Email Handler ---
    $(document).on('click', '#marrison_test_email_btn', function(e) {
        e.preventDefault();
        console.log('Test Email Button Clicked');
        
        var $btn = $(this);
        var email = $('#marrison_auto_update_email').val();
        var nonce = $btn.data('nonce');
        var $result = $('#marrison_test_email_result');

        if (!email) {
            alert('Inserisci un indirizzo email.');
            return;
        }

        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Invio in corso...');
        $result.text('').css('color', '');

        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_test_email',
                email: email,
                nonce: nonce
            },
            success: function(response) {
                console.log('Email Test Response:', response);
                if (response.success) {
                    $result.text(response.data).css('color', 'green');
                } else {
                    $result.text(response.data).css('color', 'red');
                    alert('Errore: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('Email Test Error:', error);
                $result.text('Errore di connessione: ' + error).css('color', 'red');
                alert('Errore di connessione AJAX: ' + error);
            },
            complete: function() {
                $btn.prop('disabled', false).text('Invia mail di test');
            }
        });
    });

});
