# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [9.6.5] - 2026-07-12

### Changed
- Backup database con ordinamento per chiave primaria quando disponibile durante l'esportazione a batch.
- Backup file piu tollerante: file mancanti, non leggibili o cambiati durante il job vengono esclusi e riportati invece di interrompere tutto il processo.

### Fixed
- Validazione interna del numero righe esportate per tabella nel dump database; il backup fallisce se il dump scritto non corrisponde allo snapshot letto.

## [9.6.4] - 2026-07-12

### Added
- Nuova opzione per saltare i file piu grandi del limite della singola parte e continuare il backup file.

### Changed
- Report manuale ed email schedulata indicano quanti file grandi sono stati saltati e la dimensione totale esclusa.

## [9.6.3] - 2026-07-12

### Changed
- Backup file manuale eseguito come job AJAX a step, per ridurre timeout e interruzioni di connessione.
- Backup file diviso automaticamente in parti `part001`, `part002`, ecc. sotto soglia, utile su hosting con limite di 1GB per file.
- Avanzamento del backup file calcolato sui byte processati rispetto ai byte totali scansionati.

### Fixed
- Le parti del backup restano temporanee finche non sono chiuse correttamente, evitando backup incompleti dichiarati validi.

## [9.6.2] - 2026-07-12

### Changed
- Backup completo dei file generato in formato `tar.gz` streaming invece di ZIP, per evitare archivi troncati o corrotti sui siti grandi.
- I vecchi backup file `.zip` restano visibili, scaricabili e cancellabili dalla pagina Backup.

### Fixed
- Il backup file fallisce con errore se incontra file o directory non leggibili, evitando archivi incompleti dichiarati come riusciti.

## [9.6.1] - 2026-07-12

### Changed
- Bump versione per includere le correzioni al dump database e alla validazione del backup.

### Fixed
- Il dump database preserva lo `SHOW CREATE TABLE` originale, inclusi `AUTO_INCREMENT`, indici e opzioni tabella.
- Il dump database inizializza le variabili di sessione che ripristina, chiude sempre con `COMMIT` e non usa piu `LOCK TABLES`.
- Gli `INSERT` del dump database includono la lista colonne e vengono divisi in blocchi per migliorare la compatibilita con phpMyAdmin.

## [9.6.0] - 2026-07-12

### Added
- Backup completo dei file del sito in formato `.zip`, eseguibile manualmente dalla pagina Backup.
- Opzione di backup file schedulato prima degli aggiornamenti automatici.
- Link diretti nel report email per scaricare backup database e backup file.
- Cancellazione dei singoli backup dalla pagina Backup.

### Changed
- Rotazione dei backup database e file limitata agli ultimi 3 archivi.
- Backup database generato da snapshot coerente quando MySQL lo consente e compresso con `ZipArchive` quando disponibile.

### Fixed
- Il dump database preserva lo `SHOW CREATE TABLE` originale, inclusi `AUTO_INCREMENT`, indici e opzioni tabella.
- Il dump database inizializza le variabili di sessione che ripristina, chiude sempre con `COMMIT` e non usa piu `LOCK TABLES`.
- Gli `INSERT` del dump database includono la lista colonne e vengono divisi in blocchi per migliorare la compatibilita con phpMyAdmin.
- Download dei backup ZIP in streaming a blocchi per evitare fatal error da memoria esaurita su file grandi.
- Il backup file non usa più PclZip come fallback, evitando fatal error da memoria esaurita su siti grandi.
- Corretta la lettura delle date nei nomi dei backup versionati.
- Rimossi residui JS/commenti di debug.

## [9.5.8] - 2026-07-01

### 🐛 BUG FIX — Badge "aggiornamenti disponibili" bloccato su un valore stale

#### Causa
- `marrison_available_updates_count` (l'opzione che controlla il pallino rosso nel menu) veniva ricalcolata da `check_for_available_updates()` **solo** dopo il completamento riuscito di un aggiornamento manuale (AJAX).
- Non essendo mai ricalcolata al caricamento delle pagine admin, se il valore veniva impostato >0 in un momento precedente (es. un aggiornamento tentato, o durante i test con i filtri disabilitati in 9.5.5-9.5.6) e non si verificava più nessun altro aggiornamento completato, il badge restava bloccato su quel numero anche quando in realtà non c'erano più aggiornamenti disponibili.

#### Fix
- Aggiunto hook `admin_init` → `check_for_available_updates()` per ricalcolare il conteggio ad ogni richiesta admin. `get_available_updates()`/`get_available_theme_updates()` sono già cachate con transient da 6h, quindi non introduce chiamate HTTP ripetute né impatti sulle performance.

---

## [9.5.7] - 2026-07-01

### 🐛 CRITICAL BUG FIX — Nessun aggiornamento rilevato (plugin/temi/self-update)

#### Causa
- I filtri `site_transient_update_plugins`, `site_transient_update_themes` e `plugins_api` erano stati **disabilitati per debug** nella versione 9.5.5 (commento "TEMPORANEAMENTE DISABILITATO PER DEBUG") e **mai riattivati**.
- Di conseguenza `check_for_updates` e `check_for_theme_updates` non venivano mai eseguiti: nessun aggiornamento (privato, pubblico o del plugin stesso) veniva iniettato nel transient `update_plugins`/`update_themes`.
- Questo causava due sintomi distinti riportati dagli utenti:
  1. Gli aggiornamenti pubblicati sul repository (incluse nuove release su GitHub) non apparivano nella lista plugin di WordPress, anche dopo il refresh.
  2. Il self-update del plugin (`update_plugin_ajax`) falliva sempre con errore, perché la logica si basa su `get_site_transient('update_plugins')->response[...]`, mai popolato.

#### Fix
- Riattivati i filtri in `admin_init` come previsto, con i guard `is_admin()` interni (introdotti in 9.5.5) mantenuti come protezione extra.

### 🎯 IMPACT
- Gli aggiornamenti di plugin, temi e del plugin stesso vengono correttamente rilevati e mostrati in WordPress
- Il self-update funziona nuovamente

---

## [9.5.6] - 2026-06-04

### 🐛 CRITICAL BUG FIX — WooCommerce (e plugin correlati) venivano disattivati dopo un aggiornamento

#### Causa 1 — `perform_update`: rimpiazzo non atomico della directory (BUG PRINCIPALE)
- **Problema**: `perform_update` cancellava la directory del plugin (`$wp_filesystem->delete($dest, true)`) PRIMA di copiare i nuovi file. Se `copy_dir` falliva per qualsiasi motivo (permessi, errore filesystem), il plugin rimaneva senza file su disco. Alla richiesta successiva, WordPress tentava di caricare il plugin da `active_plugins` ma non trovava il file → **Fatal Error Protection di WP 5.2+** auto-deattivava il plugin. WP 6.5+ Plugin Dependencies poi rimuoveva automaticamente tutti i plugin con `Requires Plugins: woocommerce` (Stripe, PayPal, Subscriptions, ecc.).
- **Fix**: Rimpiazzo atomico (copy-then-swap):
  1. Copia i nuovi file su una directory temporanea (`plugin-marrison-new-{ts}`) — nessuna azione distruttiva ancora
  2. Rinomina la vecchia directory in backup (`plugin-marrison-old-{ts}`)
  3. Rinomina la temp nella destinazione finale
  4. Se step 2/3 fallisce, ripristina il backup automaticamente
  5. Se tutto ok, elimina il backup

#### Causa 2 — `activate_plugin` con `$silent = false` in contesti di update (BUG SECONDARIO)
- **Problema**: In `update_plugin_ajax`, `bulk_update_ajax`, e `update_official_plugin_ajax`, `activate_plugin` veniva chiamato con `$silent = false` (4° parametro). Se il plugin finiva per qualche ragione fuori da `active_plugins`, venivano eseguiti gli activation hook in un contesto anomalo. Alcuni plugin WooCommerce nei propri activation hook chiamano `deactivate_plugins()` in caso di incompatibilità, con effetti collaterali imprevedibili.
- **Fix**: Tutte le chiamate `activate_plugin` nei flussi di aggiornamento usano ora `$silent = true` e vengono eseguite solo se `!is_plugin_active()` (guard aggiunto).

### 🎯 IMPACT
- I plugin privati (incluso WooCommerce se presente nel repo) non vengono mai lasciati in uno stato "vuoto" su disco durante un aggiornamento
- Gli activation hook non vengono più eseguiti in contesti di re-attivazione post-update

---

## [9.5.5] - 2026-06-03

### 🔧 CRITICAL PERFORMANCE FIX
- **Cache invalidation aggressiva rimossa**: `delete_internal_cache` non è più agganciato a `delete_site_transient_update_plugins` — WP cancella questo transient su quasi ogni pagina admin, il che svuotava il cache del repo ad ogni richiesta
- **GitHub cache cleanup**: `force_clear_github_cache` rimosso da `delete_site_transient_update_plugins` per evitare fetch ripetuti
- **Failure caching**: Aggiunto sistema di "failure cache" di 5 minuti (`marrison_updates_fetch_failed`, `marrison_theme_updates_fetch_failed`, `marrison_github_fetch_failed`) per evitare retry ripetuti su server irraggiungibili
- **Timeout ridotti**: Da 15s/10s a 5s per tutte le chiamate HTTP al repository (`get_available_updates`, `get_available_theme_updates`, `get_github_version`)
- **Guard is_admin()**: Aggiunto in `check_for_updates` e `check_for_theme_updates` come protezione extra per quando i filtri verranno riabilitati
- **Pulizia failure transients**: `delete_internal_cache` ora pulisce anche i transient di fallimento per permettere retry dopo pulizia cache manuale

### 🎯 IMPACT
- **Risolto rallentamento critico**: Siti con repository lento/irraggiungibile non si bloccano più per 15 secondi su ogni pagina admin
- Cache del repository mantenuto correttamente tra i caricamenti pagina
- Fallback intelligente quando il repository non è accessibile (5 min di attesa prima di retry)

---

## [9.5.4] - 2026-05-29

### 🐛 FIX
- **Frontend rallentato**: aggiunto controllo `is_admin()` ai filtri di aggiornamento per evitare chiamate HTTP sul frontend
- **Performance**: i filtri `site_transient_update_plugins` e `site_transient_update_themes` vengono eseguiti solo nell'area admin

### 🎯 IMPACT
- Il frontend non viene più rallentato dalle chiamate al repository privato
- Migliore performance complessiva del sito

---

## [9.5.3] - 2026-05-29

### 🐛 FIX
- **Dashboard bloccata dopo migrazione**: rimosso hook `admin_init` per `check_for_available_updates()` che causava timeout HTTP al repository privato
- **Miglioramento**: il controllo aggiornamenti viene già eseguito tramite filtri `site_transient_update_plugins` e `site_transient_update_themes`

### 🎯 IMPACT
- Dashboard non si blocca più dopo migrazioni o se il repository privato non è accessibile
- Caricamento admin più veloce

---

## [9.5.2] - 2026-05-18

### � FIX
- **Backup DB formato phpMyAdmin**: il dump SQL generato è ora compatibile con l'importazione phpMyAdmin
- **Backup DB compatibilità**: gestione di `AUTO_INCREMENT`, SET SQL_MODE/START TRANSACTION globali
- **Backup DB**: fixato `$wpdb->dbhost()` (proprietà, non metodo)
- **Backup DB**: fixato segno `=` residuo nelle opzioni tabella

### 🎯 IMPACT
- Backup database completamente compatibile con phpMyAdmin per restore affidabile

---

## [9.5.1] - 2026-05-18

### 🔧 ENHANCEMENT
- **Repository index.php**: i file `index.php` per plugin e temi ora scansionano ricorsivamente le sottocartelle per i file `.zip`
- **Organizzazione flessibile**: è ora possibile organizzare i plugin/temi in sottocartelle (es. `repo/client-a/plugin.zip`, `repo/ecommerce/theme.zip`)
- **Download URL corretto**: il percorso relativo delle sottocartelle viene preservato nel `download_url`

### 🎯 IMPACT
- Migliore organizzazione del repository privato
- Possibilità di separare i file per cliente o categoria

---

## [9.5.0] - 2026-05-13

### ✨ NEW FEATURE - Backup Database
- **Backup manuale**: pulsante "Esegui Backup Database" nella pagina Backup per creare un dump SQL on-demand
- **Backup schedulato**: nuova opzione in Impostazioni > Programmazione per eseguire automaticamente un backup del DB prima di ogni aggiornamento automatico
- **Download**: ogni backup database è scaricabile direttamente dalla pagina Backup in formato `.zip`
- **Rotazione automatica**: vengono mantenuti solo gli ultimi 3 backup del database, i precedenti vengono eliminati automaticamente
- **Formato**: dump SQL completo con `DROP TABLE IF EXISTS` + `CREATE TABLE` + `INSERT INTO`, compresso in `.zip`
- **Sicurezza**: i file sono salvati in `wp-content/marrison-backups/` con `.htaccess` `deny from all`

### 🎯 IMPACT
- Protezione database completa prima di ogni aggiornamento
- Possibilità di ripristinare il DB in caso di problemi dopo un aggiornamento

---

## [9.4.3] - 2026-05-12

### 🔧 CRITICAL FIX
- **Email HTML rendering**: Fixed emails arriving as raw HTML code instead of rendered content
- **Root cause**: SMTP plugins (WP Mail SMTP, FluentSMTP, etc.) hooking into `phpmailer_init` could reset the content type after WordPress set it, causing the email to be delivered as `text/plain`
- **Fix**: Added `wp_mail_content_type` filter and forced `isHTML(true)` via `phpmailer_init` at priority 999 (runs last) before every send, then removed both hooks immediately after

### 🎯 IMPACT
- HTML emails now render correctly regardless of which SMTP plugin is active
- Applied to both test emails and scheduled update report emails

---

## [9.4.2] - 2026-05-08

### 🔧 CRITICAL FIX
- **Email sending**: Fixed email delivery failure on 90% of sites
- **From header**: Replaced fabricated `no-reply@domain.com` with real `admin_email`
- **Root cause**: Most hosting providers reject emails from non-configured sender addresses (SPF/DMARC failures)

### 🎯 IMPACT
- Email notifications now work reliably across all hosting environments
- Both test emails and scheduled update reports are delivered correctly
- No more silent email failures due to server restrictions

---

## [9.4.1] - 2026-03-12

### 🔧 FIXES
- **Pulsante "Pulisci Cache"**: Ora funziona correttamente
- **Handler JavaScript**: Corretto per usare form submit invece di AJAX

### ⚡ IMPROVEMENTS
- **Pulizia cache completa**: Inclusa cache GitHub e WordPress
- **Ricaricamento forzato**: Aggiornamenti ricaricati dal repository dopo pulizia
- **Dialogo di conferma**: Aggiunto per sicurezza durante pulizia cache
- **Feedback visivo**: Indicatore di stato durante pulizia cache

### 🎯 IMPACT
- Cache completamente pulita e ricaricata
- Repository aggiornamenti sincronizzato correttamente
- Esperienza utente migliorata con feedback appropriato

---

## [9.4.0] - 2026-03-04

### CRITICAL FIXES
- **Sistema di esclusioni completamente rinnovato**: Ora funziona per tutti i tipi di plugin
- **Gestione unificata**: Plugin premium/privati e WordPress.org gestiti allo stesso modo
- **Riconoscimento automatico plugin premium**: Tramite analisi del PluginURI
- **Sistema di slug duali**: Compatibilità con tutti i plugin (cartella vs WordPress.org)

### BUG FIXES
- Badge "Escluso" ora funziona per tutti i tipi di plugin
- Contatore principale esclude correttamente i plugin esclusi
- Pulsante "Aggiorna tutto" rispetta le esclusioni
- Plugin esclusi non vengono più aggiornati accidentalmente

### IMPROVEMENTS
- **Ripristinati tutti i pulsanti JavaScript mancanti**
- Handler per pulsante "Invia mail di test" nella programmazione
- Handler per pulsante "Pulisci Cache"
- Handler per pulsante "Aggiorna Tutti" plugin pubblici
- Handler per pulsante "Installa selezionati" e "Seleziona tutti"
- Feedback visivo e toast notifications per tutti i pulsanti
- Gestione errori e stati di caricamento per tutti i pulsanti

### IMPACT
- Sistema di esclusioni ora affidabile al 100%
- Tutti i pulsanti dell'interfaccia funzionano correttamente
- Supporto completo per plugin premium, privati e WordPress.org

## [9.3.0] - 2025-03-04

### Added
- **Backup/Restore Extended**: Complete backup/restore functionality now covers all plugins and themes, including both public and private repositories
- **Orphan Backup Cleanup**: Automatic removal of backups for plugins that are no longer installed on the site
- **Single-File Plugin Support**: Full backup/restore support for single-file plugins (not just directory-based plugins)

### Fixed
- **Update All Button**: Fixed "Aggiorna tutto" button that became unresponsive after backup extension
- **Timeout Issues**: Extended execution time limits for bulk update endpoints to handle backup operations during mass updates

### Improved
- **Backup Reliability**: Enhanced backup filename handling and slug detection for both single-file and directory plugins
- **Restore Process**: Improved restore logic to handle both plugin types seamlessly
- **Performance**: Optimized backup cleanup routine to run efficiently when accessing backup page

## [9.2.0] - Previous Release

### UI Improvements
- Centered action buttons in the Updates page header between title and logo
- Standardized button colors throughout the plugin (normal: dark purple, hover: bright pink)
- Removed repository plugin counters (private information) from dashboard and settings
- Moved "Advanced Tools" block to "Guide & Download" tab
- Cleaned up plugin and themes monitoring tables (removed File, Slug, and Status columns)

### Bug Fixes
- Fixed theme repository index.php download issue (absolute paths and separate logic)
- Introduced global `MCU_PLUGIN_DIR` constant for more robust file path management

## [9.1.0] - Previous Release

### General
- Feature update to version 9.1 with general stability and performance improvements
- Updated stable version to reflect latest release

## [8.6.0] - Previous Release

### Bug Fixes
- Removed conflict check with Marrison Custom Installer and made plugin independent
- Renamed main class and traits to MCU_* to avoid name collisions
- Eliminated unwanted activation notice

## [8.5.0] - Previous Release

### Added
- Implemented forced translation updates (Core, Themes, Plugins) with deep cache cleanup and extended timeout
- Added automation for Elementor database update after plugin update
- Integrated Elementor DB update status in automatic email report

### Bug Fixes
- Added safety delay (3 seconds) before Elementor DB trigger for filesystem stability

## [8.4.0] - Previous Release

### UI Improvements
- Updated update frequency names (Daily, Weekly, Monthly, Semi-annual) for better consistency

### Bug Fixes
- Improved next scheduled execution calculation to correctly respect set frequency (Daily, Weekly, Monthly, Semi-annual)

## [8.3.0] - Previous Release

### Bug Fixes
- Fixed critical issue where plugin self-update could cause plugin deactivation
- Implemented atomic update strategy with preventive backup

## [8.2.1] - Previous Release

### Email Improvements
- Added visual status banners (Green/Red) for quick outcome identification
- Enhanced report with detailed sections for errors and skipped updates
- Optimized email graphics (logo, clean footer)

### Core Improvements
- Improved error handling during updates (captures WP_Error error codes)
- Added PHP requirements check before update
- Removed "About" tab from settings panel

## [8.2.0] - Previous Release

### Refactoring
- Complete code restructuring using Traits to improve modularity and stability

### Email Improvements
- Renovated notification email graphics with modern responsive design
- Added version details (Previous -> New) in update report

### Bug Fixes
- Resolved function redefinition conflicts with WordPress core

## [8.1.5] - Previous Release

### Internationalization
- Made scheduling option strings (Weekly, Monthly, etc.) and test email messages translatable
- Updated .pot file with latest strings

## [8.1.4] - Previous Release

### Added
- Weekly scheduling option for automatic updates

## [8.1.3] - Previous Release

### Bug Fixes
- Fixed update detection issue for WPCode Lite (insert-headers-and-footers) when Marrison Custom Updater is active
- Improved private plugin exclusion logic to avoid false positives

## [8.1.2] - Previous Release

### Improvements
- Enhanced private plugin update detection logic
- Added ability to exclude installed private plugins from standard WordPress checks to avoid conflicts

## [8.1.1] - Previous Release

### Improvements
- Improved cron job management: added detailed logs and error handling (try-catch) to prevent blocks
- Fixed bug that prevented email report sending when there were no updates (now always sends if scheduled)
- Resolved PHP "Undefined variable" notice in cron job

## [8.1.0] - Previous Release

### Added
- Initial release with core functionality
