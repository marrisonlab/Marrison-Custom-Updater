=== Marrison Custom Updater ===
Author: Angelo Marra
Author URI:  https://marrisonlab.com
Tags: updater, plugin-updates, custom repository, auto update
Requires at least: 6.0
Tested up to: 6.9.1
Requires PHP: 7.4
Stable tag: 9.4.3
License: GPL-3.0+
License URI: https://www.gnu.org/licenses/gpl-3.0.txt


== Description ==

**Marrison Custom Updater** è una soluzione avanzata per gestire aggiornamenti di plugin e temi privati in WordPress. Permette di collegare il tuo sito WordPress a un repository personalizzato, consentendo di distribuire aggiornamenti per i tuoi plugin e temi proprietari con la stessa facilità di quelli ufficiali di WordPress.org.

**Funzionalità Principali:**

*   **Repository Privato:** Collega il tuo sito a una fonte esterna per ricevere aggiornamenti per plugin e temi non presenti nella directory ufficiale.
*   **Gestione Aggiornamenti Unificata:** Visualizza e installa aggiornamenti per plugin e temi privati direttamente dalla dashboard.
*   **Sistema di Backup Integrato:** Esegue automaticamente backup di tutti i plugin e temi (privati e pubblici) prima dell'aggiornamento, permettendo il ripristino rapido (rollback) in caso di problemi.
*   **Pulizia Backup Orfani:** Rimuove automaticamente i backup dei plugin che non sono più installati sul sito.
*   **Aggiornamenti Automatici:** Configura aggiornamenti automatici programmati (giornalieri o settimanali) con notifiche email dettagliate.
*   **Gestione Traduzioni:** Strumento dedicato per aggiornare le traduzioni dei plugin.
*   **Log e Debug:** Sistema di logging integrato per monitorare le operazioni di aggiornamento e cron job.
*   **Esclusione Plugin:** Possibilità di escludere specifici plugin dagli aggiornamenti automatici.

== Installation ==

1.  Carica la cartella `marrison-custom-updater` nella directory `/wp-content/plugins/` del tuo sito.
2.  Attiva il plugin dal menu 'Plugin' di WordPress.
3.  Vai su 'Marrison Updater' > 'Impostazioni' per configurare l'URL del tuo repository privato.

== Changelog ==

= 9.4.3 =
* **Fix critico**: Le email arrivavano con l'HTML grezzo visibile invece del contenuto renderizzato
* **Causa**: Plugin SMTP (WP Mail SMTP, FluentSMTP, ecc.) potevano resettare il content-type tramite `phpmailer_init`, forzando l'invio come `text/plain`
* **Fix**: Aggiunto filtro `wp_mail_content_type` e forzato `isHTML(true)` tramite `phpmailer_init` a priorità 999 prima di ogni invio, rimossi subito dopo
* **Impatto**: Le email HTML ora vengono renderizzate correttamente indipendentemente dal plugin SMTP attivo

= 9.4.2 =
* **Fix critico**: Risolto fallimento invio email sul 90% dei siti
* **Fix**: Sostituito indirizzo From fabbricato `no-reply@dominio.com` con reale `admin_email`
* **Causa**: La maggior parte degli hosting rifiuta email da indirizzi mittente non configurati (fallimenti SPF/DMARC)
* **Impatto**: Le notifiche email ora funzionano affidabilmente su tutti gli ambienti hosting

= 9.4.1 =
* **Correzione**: Pulsante "Pulisci Cache" ora funziona correttamente
* **Miglioramento**: Pulizia cache completa inclusa cache GitHub e WordPress
* **Miglioramento**: Forza ricaricamento aggiornamenti dal repository dopo pulizia
* **Correzione**: Handler JavaScript corretto per usare form submit invece di AJAX
* **Miglioramento**: Aggiunto dialogo di conferma per pulizia cache
* **Miglioramento**: Feedback visivo durante pulizia cache

= 9.4 =
* **Correzione critica**: Sistema di esclusioni completamente rinnovato
* **Nuovo sistema**: Gestione unificata per plugin premium/privati e WordPress.org
* **Miglioramento**: Riconoscimento automatico plugin premium tramite PluginURI
* **Miglioramento**: Sistema di slug duali per compatibilità con tutti i plugin
* **Correzione**: Badge "Escluso" ora funziona per tutti i tipi di plugin
* **Correzione**: Contatore principale esclude correttamente i plugin esclusi
* **Correzione**: Pulsante "Aggiorna tutto" rispetta le esclusioni
* **Correzione**: Ripristinati tutti i pulsanti JavaScript mancanti
* **Nuovo**: Handler per pulsante "Invia mail di test" nella programmazione
* **Nuovo**: Handler per pulsante "Pulisci Cache"
* **Nuovo**: Handler per pulsante "Aggiorna Tutti" plugin pubblici
* **Nuovo**: Handler per pulsante "Installa selezionati" e "Seleziona tutti"
* **Miglioramento**: Feedback visivo e toast notifications per tutti i pulsanti
* **Miglioramento**: Gestione errori e stati di caricamento per tutti i pulsanti

= 9.3 =
* **Feature:** Esteso il sistema di backup/restore a tutti i plugin e temi, inclusi quelli da repository pubbliche e private.
* **Feature:** Aggiunta pulizia automatica dei backup orfani (backup di plugin non più installati).
* **Improvement:** Supporto completo per plugin single-file e plugin in cartella nel sistema di backup/restore.
* **Fix:** Risolto il problema del pulsante "Aggiorna tutto" che non rispondeva dopo l'introduzione dei backup estesi.
* **Improvement:** Esteso il tempo di esecuzione per gli endpoint di aggiornamento massivo per gestire backup durante update multipli.

= 9.2 =
* UI: Centrati i pulsanti nella testata della pagina Aggiornamenti tra titolo e logo.
* UI: Standardizzati i colori dei pulsanti in tutto il plugin (normale: viola scuro, hover: rosa acceso).
* UI: Rimossi i contatori dei plugin nel repository (informazione privata) dalla dashboard e dalle impostazioni.
* UI: Spostato il blocco "Strumenti Avanzati" nella tab "Guida & Download".
* UI: Pulizia delle colonne nelle tabelle dei plugin e temi monitorati (rimosse colonne File, Slug e Stato).
* Fix: Risolto il problema del download del file index.php per il repository dei temi (percorsi assoluti e logica separata).
* Core: Introdotta costante globale `MCU_PLUGIN_DIR` per una gestione più robusta dei percorsi dei file.

= 9.1 =
* Feature: Aggiornamento alla versione 9.1 con miglioramenti generali di stabilità e prestazioni.
* Update: Versione stabile aggiornata per riflettere l'ultimo rilascio.

= 8.6 =
* Fix: Rimosso controllo conflitto con Marrison Custom Installer e reso plugin indipendente.
* Fix: Rinominato classe principale e trait in MCU_* per evitare collisioni di nomi.
* Fix: Eliminato notice di attivazione non voluto.

= 8.5 =
* Feature: Implementato aggiornamento forzato delle traduzioni (Core, Temi, Plugin) con pulizia profonda della cache e timeout esteso.
* Feature: Aggiunta automazione per l'aggiornamento del database di Elementor dopo l'aggiornamento del plugin.
* Feature: Integrato stato aggiornamento DB Elementor nel report email automatico.
* Fix: Aggiunto delay di sicurezza (3 secondi) prima del trigger DB Elementor per stabilità filesystem.

= 8.4 =
* UI: Aggiornati i nomi delle frequenze di aggiornamento (Giornaliera, Settimanale, Mensile, Semestrale) per una migliore coerenza.
* Fix: Migliorato il calcolo della prossima esecuzione programmata per rispettare correttamente la frequenza impostata (Giornaliera, Settimanale, Mensile, Semestrale).

= 8.3 =
* Fix: Risolto problema critico per cui l'aggiornamento automatico del plugin stesso (self-update) poteva causare la disattivazione del plugin. Implementata strategia di aggiornamento atomico con backup preventivo.

= 8.2.1 =
*   Email: Aggiunti banner di stato visivi (Verde/Rosso) per una rapida identificazione dell'esito.
*   Email: Migliorato il report con sezioni dettagliate per errori e aggiornamenti saltati.
*   Email: Ottimizzazione grafica (logo, footer pulito).
*   Core: Migliorata la gestione degli errori durante gli aggiornamenti (cattura codici errore WP_Error).
*   Core: Aggiunto controllo requisiti PHP prima dell'aggiornamento.
*   UI: Rimossa tab "About" dal pannello impostazioni.

= 8.2.0 =
*   Refactoring: Ristrutturazione completa del codice utilizzando Traits per migliorare modularità e stabilità.
*   Email: Grafica delle email di notifica rinnovata con design moderno e responsive.
*   Email: Aggiunto dettaglio versioni (Precedente -> Nuova) nel report degli aggiornamenti.
*   Fix: Risolti conflitti di ridefinizione funzioni con il core di WordPress.

= 8.1.5 =
*   Migliorata l'internazionalizzazione: rese traducibili le stringhe delle opzioni di pianificazione (Settimanale, Mensile, ecc.) e dei messaggi di test email.
*   Aggiornato il file .pot con le ultime stringhe.

= 8.1.4 =
*   Aggiunta opzione di pianificazione settimanale per gli aggiornamenti automatici.

= 8.1.3 =
*   FIX: Risolto problema di rilevamento aggiornamenti per WPCode Lite (insert-headers-and-footers) quando il plugin Marrison Custom Updater è attivo.
*   Migliorata la logica di esclusione dei plugin privati per evitare falsi positivi.

= 8.1.2 =
*   Migliorata la logica di rilevamento degli aggiornamenti per i plugin privati.
*   Aggiunta la possibilità di escludere i plugin privati installati dai controlli standard di WordPress per evitare conflitti.

= 8.1.1 =
*   Migliorata la gestione del cron job: aggiunti log dettagliati e gestione errori (try-catch) per evitare blocchi.
*   Corretto bug che impediva l'invio del report email se non c'erano aggiornamenti (ora invia sempre se programmato).
*   Risolto avviso PHP "Undefined variable" nel cron job.

= 8.1.0 =
*   Aggiunto pulsante per inviare email di test nelle impostazioni di pianificazione.
*   Migliorata l'interfaccia utente con feedback visivo (spinner, messaggi di successo/errore) per l'invio email.
*   Impostato header "From" corretto (no-reply@dominio) per le email inviate.
*   Ottimizzato il caricamento degli script JS nell'admin.

= 8.0.0 =
*   Rifattorizzazione completa del codice.
*   Nuova interfaccia utente a tab.
*   Migliorato il sistema di backup e rollback.
*   Supporto per aggiornamenti temi.
