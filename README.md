# Marrison Custom Updater

[![Latest Version](https://img.shields.io/badge/version-9.6.6-blue.svg)](https://github.com/marrisonlab/marrison-custom-updater)
[![WordPress Version](https://img.shields.io/badge/WordPress-6.0%2B-green.svg)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-green.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--3.0%2B-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.txt)

**Marrison Custom Updater** è una soluzione avanzata per gestire aggiornamenti di plugin e temi privati in WordPress. Permette di collegare il tuo sito WordPress a un repository personalizzato, consentendo di distribuire aggiornamenti per i tuoi plugin e temi proprietari con la stessa facilità di quelli ufficiali di WordPress.org.

## ✨ Funzionalità Principali

- 🔄 **Repository Privato**: Collega il tuo sito a una fonte esterna per ricevere aggiornamenti per plugin e temi non presenti nella directory ufficiale
- 📦 **Gestione Aggiornamenti Unificata**: Visualizza e installa aggiornamenti per plugin e temi privati direttamente dalla dashboard
- 💾 **Sistema di Backup Integrato**: Esegue automaticamente backup di tutti i plugin e temi (privati e pubblici) prima dell'aggiornamento, permettendo il ripristino rapido (rollback) in caso di problemi
- 🧹 **Pulizia Backup Orfani**: Rimuove automaticamente i backup dei plugin che non sono più installati sul sito
- ⏰ **Aggiornamenti Automatici**: Configura aggiornamenti automatici programmati (giornalieri o settimanali) con notifiche email dettagliate
- 🌐 **Gestione Traduzioni**: Strumento dedicato per aggiornare le traduzioni dei plugin
- 📊 **Log e Debug**: Sistema di logging integrato per monitorare le operazioni di aggiornamento e cron job
- 🚫 **Esclusione Plugin**: Possibilità di escludere specifici plugin dagli aggiornamenti automatici

## � Installation

1. Upload the `marrison-custom-updater` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'Marrison Updater' > 'Settings' to configure your private repository URL

## 📋 Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- PHP zlib extension for full file backups in tar.gz format
- Access to plugin files for backup/restore operations

## 🔄 Version History

### [9.6.6] - 2026-07-24

- Backup database con scrittura SQL controllata byte per byte, manifest JSON e hash SHA256 dentro lo ZIP
- Validazione dello ZIP dopo la compressione: il backup viene mostrato come riuscito solo se SQL, manifest, dimensione e hash coincidono
- Gli aggiornamenti automatici vengono bloccati se un backup richiesto non viene completato e verificato
- Il backup DB verificato richiede tabelle InnoDB; view, trigger o engine non transazionali vengono bloccati con errore esplicito
- Backup file piu severo sugli errori durante la scrittura tar.gz: se l'archivio potrebbe essere corrotto, il job fallisce invece di dichiarare successo
- Pagina Backup e report email indicano quando un backup database e stato verificato

### [9.6.5] - 2026-07-12

- Backup database con ordinamento per chiave primaria quando disponibile e validazione interna del numero righe esportate per tabella
- Backup file piu tollerante: file mancanti, non leggibili o cambiati durante il job vengono esclusi e riportati invece di interrompere tutto il processo

### [9.6.4] - 2026-07-12

- Nuova opzione per saltare i file piu grandi del limite della singola parte e continuare il backup file
- Report manuale ed email schedulata indicano quanti file grandi sono stati saltati e la dimensione totale esclusa

### [9.6.3] - 2026-07-12

- Backup file manuale eseguito come job AJAX a step, per ridurre timeout e interruzioni di connessione
- Backup file diviso automaticamente in parti `part001`, `part002`, ecc. sotto soglia, utile su hosting con limite di 1GB per file
- Le parti restano temporanee finche non sono chiuse correttamente, evitando di mostrare backup incompleti come validi

### [9.6.2] - 2026-07-12

- Backup completo dei file generato in formato `tar.gz` streaming invece di ZIP, per evitare archivi troncati o corrotti sui siti grandi
- I vecchi backup file `.zip` restano visibili, scaricabili e cancellabili dalla pagina Backup
- Il backup file fallisce con errore se incontra file o directory non leggibili, evitando archivi incompleti dichiarati come riusciti

### [9.6.1] - 2026-07-12

- Dump database reso importabile con maggiore affidabilita: preserva `AUTO_INCREMENT`, chiude con `COMMIT`, evita `LOCK TABLES` e divide gli `INSERT` in blocchi
- Validazione piu robusta del backup DB prima della creazione del file ZIP

### [9.6.0] - 2026-07-12

- Backup completo dei file del sito, manuale e schedulato
- Link diretti nel report email per scaricare backup database e file
- Rotazione automatica limitata agli ultimi 3 backup
- Dump database piu affidabile per phpMyAdmin: preserva `AUTO_INCREMENT`, chiude con `COMMIT`, evita `LOCK TABLES` e divide gli `INSERT` in blocchi
- Pulizia residui debug/commenti e fix parsing date backup

### [9.5.5] - 2026-06-03

#### 🔧 CRITICAL PERFORMANCE FIX
- **Cache invalidation aggressiva rimossa**: `delete_internal_cache` non è più agganciato a `delete_site_transient_update_plugins`
- **GitHub cache cleanup**: `force_clear_github_cache` rimosso da `delete_site_transient_update_plugins`
- **Failure caching**: Aggiunto sistema di "failure cache" di 5 minuti per evitare retry ripetuti su server irraggiungibili
- **Timeout ridotti**: Da 15s/10s a 5s per tutte le chiamate HTTP al repository
- **Guard is_admin()**: Aggiunto in `check_for_updates` e `check_for_theme_updates` come protezione extra

#### 🎯 IMPACT
- **Risolto rallentamento critico**: Siti con repository lento/irraggiungibile non si bloccano più per 15 secondi su ogni pagina admin
- Cache del repository mantenuta correttamente tra i caricamenti pagina
- Fallback intelligente quando il repository non è accessibile

### [9.4.1] - 2026-03-12

#### 🔧 FIXES
- **Pulsante "Pulisci Cache"**: Ora funziona correttamente
- **Handler JavaScript**: Corretto per usare form submit invece di AJAX

#### ⚡ IMPROVEMENTS
- **Pulizia cache completa**: Inclusa cache GitHub e WordPress
- **Ricaricamento forzato**: Aggiornamenti ricaricati dal repository dopo pulizia
- **Dialogo di conferma**: Aggiunto per sicurezza durante pulizia cache
- **Feedback visivo**: Indicatore di stato durante pulizia cache

#### 🎯 IMPACT
- Cache completamente pulita e ricaricata
- Repository aggiornamenti sincronizzato correttamente
- Esperienza utente migliorata con feedback appropriato

### [9.4.0] - 2026-03-04

#### 🔧 CRITICAL FIXES
- **Sistema di esclusioni completamente rinnovato**: Ora funziona per tutti i tipi di plugin
- **Gestione unificata**: Plugin premium/privati e WordPress.org gestiti allo stesso modo
- **Riconoscimento automatico plugin premium**: Tramite analisi del PluginURI
- **Sistema di slug duali**: Compatibilità con tutti i plugin (cartella vs WordPress.org)

#### 🐛 BUG FIXES
- Badge "Escluso" ora funziona per tutti i tipi di plugin
- Contatore principale esclude correttamente i plugin esclusi
- Pulsante "Aggiorna tutto" rispetta le esclusioni
- Plugin esclusi non vengono più aggiornati accidentalmente

#### ⚡ IMPROVEMENTS
- **Ripristinati tutti i pulsanti JavaScript mancanti**
- Handler per pulsante "Invia mail di test" nella programmazione
- Handler per pulsante "Pulisci Cache"
- Handler per pulsante "Aggiorna Tutti" plugin pubblici
- Handler per pulsante "Installa selezionati" e "Seleziona tutti"
- Feedback visivo e toast notifications per tutti i pulsanti
- Gestione errori e stati di caricamento per tutti i pulsanti

#### 🎯 IMPACT
- Sistema di esclusioni ora affidabile al 100%
- Tutti i pulsanti dell'interfaccia funzionano correttamente
- Supporto completo per plugin premium, privati e WordPress.org

### [9.3.0] - 2026-03-04

## 🔧 Configurazione

### Repository Plugin

1. Vai su **Marrison Updater** > **Impostazioni**
2. Inserisci l'URL del tuo repository privato nella sezione "Repository Plugin"
3. Salva le impostazioni

### Repository Temi

1. Nella stessa pagina "Impostazioni", inserisci l'URL del repository temi
2. Salva le impostazioni

### Aggiornamenti Automatici

1. Vai su **Marrison Updater** > **Pianificazione**
2. Configura la frequenza degli aggiornamenti automatici
3. Imposta le notifiche email
4. Salva le impostazioni

## 💾 Sistema di Backup e Restore

### Backup Automatico

Il plugin crea automaticamente un backup prima di ogni aggiornamento per:
- ✅ Plugin privati (repository personalizzato)
- ✅ Plugin pubblici (WordPress.org)
- ✅ Plugin single-file
- ✅ Plugin in cartella
- ✅ Temi privati
- ✅ Temi pubblici

I backup database includono un manifest JSON con conteggi, dimensione SQL e hash SHA256; la pagina Backup mostra se l'archivio e stato verificato. Se un backup richiesto non viene completato e verificato, gli aggiornamenti automatici vengono bloccati.

### Pulizia Backup Orfani

Quando accedi alla pagina **Backup**, il plugin:
- Controlla quali plugin sono ancora installati
- Rimuove automaticamente i backup dei plugin non più presenti
- Mantiene la cartella backup pulita e organizzata

### Restore

1. Vai su **Marrison Updater** > **Backup**
2. Trova il backup desiderato nella lista
3. Clicca "Ripristina" per ripristinare quella versione specifica
4. Il plugin verrà ripristinato e riattivato se necessario

## 🔄 Aggiornamento Massivo

Il pulsante **"Aggiorna tutto"** permette di aggiornare in sequenza:
1. Plugin privati
2. Plugin pubblici/ufficiali
3. Tutti i temi
4. Traduzioni

Ogni aggiornamento include un backup automatico prima dell'installazione.

## 📧 Notifiche Email

Il plugin invia report dettagliati dopo ogni aggiornamento automatico contenente:
- Lista plugin aggiornati con versioni (precedente → nuova)
- Errori eventuali con codici di errore
- Plugin saltati e motivazione
- Stato dei backup database/file, con verifica e link download quando disponibili
- Stato aggiornamento database Elementor (se applicabile)

## 🐛 Troubleshooting

### Plugin non rilevato dal repository
- Verifica che il file JSON del repository sia formattato correttamente
- Controlla che l'URL sia accessibile pubblicamente
- Assicurati che gli slug nel repository corrispondano ai nomi delle cartelle plugin

### Backup non creati
- Verifica i permessi della cartella `wp-content/marrison-backups`
- Controlla che `ZipArchive`/PclZip sia disponibile per i backup database e che `zlib` sia attiva per i backup file `tar.gz`
- Per i backup database verificati, controlla che le tabelle siano InnoDB e che non siano presenti view, trigger o engine non transazionali

### Aggiornamenti automatici non partono
- Verifica che i cron job WordPress siano attivi
- Controlla la configurazione email per le notifiche
- Controlla i log di errore WordPress

## 📝 Changelog

### 9.6.6
- Backup database verificato con manifest JSON, dimensione SQL e hash SHA256 inclusi nello ZIP
- Validazione post-compressione dello ZIP prima di dichiarare il backup riuscito
- Aggiornamenti automatici bloccati quando un backup richiesto non viene completato e verificato
- Backup file piu severo sugli errori durante la scrittura `tar.gz`
- Stato di verifica visibile nella pagina Backup e nei report email programmati

Vedi il file [CHANGELOG.md](CHANGELOG.md) per un elenco completo delle modifiche versione per versione.

## 🤝 Contributi

I contributi sono benvenuti! Per favore:
1. Fai un fork del repository
2. Crea un branch per la tua funzionalità (`git checkout -b feature/amazing-feature`)
3. Fai il commit delle tue modifiche (`git commit -m 'Add some amazing feature'`)
4. Fai il push al branch (`git push origin feature/amazing-feature`)
5. Apri una Pull Request

## 📄 Licenza

Questo plugin è rilasciato sotto licenza GPL-3.0+. Vedi il file [LICENSE](LICENSE) per maggiori dettagli.

## 🆘 Supporto

Per supporto e domande:
- **Issues GitHub**: [marrisonlab/marrison-custom-updater](https://github.com/marrisonlab/marrison-custom-updater/issues)
- **Sito web**: [Marrisonlab](https://marrisonlab.com)
- **Email**: supporto@marrisonlab.com

## 🙏 Ringraziamenti

- A tutta la community WordPress per l'ispirazione e il supporto
- Ai contributori che hanno migliorato questo progetto nel tempo
- Agli utenti che forniscono feedback preziosi per il miglioramento continuo

---

**Sviluppato con ❤️ da [Marrisonlab](https://marrisonlab.com)**
