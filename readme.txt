=== Marrison Custom Updater ===
Author: Angelo Marra
Author URI:  https://marrisonlab.com
Tags: updater, plugin-updates, custom repository, auto update
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 8.2.1
License: GPL-3.0+
License URI: https://www.gnu.org/licenses/gpl-3.0.txt


== Description ==

**Marrison Custom Updater** è una soluzione avanzata per gestire aggiornamenti di plugin e temi privati in WordPress. Permette di collegare il tuo sito WordPress a un repository personalizzato, consentendo di distribuire aggiornamenti per i tuoi plugin e temi proprietari con la stessa facilità di quelli ufficiali di WordPress.org.

**Funzionalità Principali:**

*   **Repository Privato:** Collega il tuo sito a una fonte esterna per ricevere aggiornamenti per plugin e temi non presenti nella directory ufficiale.
*   **Gestione Aggiornamenti Unificata:** Visualizza e installa aggiornamenti per plugin e temi privati direttamente dalla dashboard.
*   **Sistema di Backup Integrato:** Esegue automaticamente backup dei plugin prima dell'aggiornamento, permettendo il ripristino rapido (rollback) in caso di problemi.
*   **Aggiornamenti Automatici:** Configura aggiornamenti automatici programmati (giornalieri o settimanali) con notifiche email dettagliate.
*   **Gestione Traduzioni:** Strumento dedicato per aggiornare le traduzioni dei plugin.
*   **Log e Debug:** Sistema di logging integrato per monitorare le operazioni di aggiornamento e cron job.
*   **Esclusione Plugin:** Possibilità di escludere specifici plugin dagli aggiornamenti automatici.

== Installation ==

1.  Carica la cartella `marrison-custom-updater` nella directory `/wp-content/plugins/` del tuo sito.
2.  Attiva il plugin dal menu 'Plugin' di WordPress.
3.  Vai su 'Marrison Updater' > 'Impostazioni' per configurare l'URL del tuo repository privato.

== Changelog ==

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
