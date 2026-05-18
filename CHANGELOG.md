# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [9.5.2] - 2026-05-18

### � FIX
- **Backup DB formato phpMyAdmin**: il dump SQL generato è ora identico all'export di phpMyAdmin
- **Backup DB compatibilità**: rimozione completa di AUTO_INCREMENT (colonna e tabella), INSERT senza nomi colonna, SET SQL_MODE/START TRANSACTION globali
- **Backup DB**: fixato `$wpdb->dbhost()` (proprietà, non metodo)
- **Backup DB**: fixato segno `=` residuo dopo rimozione AUTO_INCREMENT

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
- **Rotazione automatica**: vengono mantenuti solo gli ultimi 5 backup del database, i precedenti vengono eliminati automaticamente
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
