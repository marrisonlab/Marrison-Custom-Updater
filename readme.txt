=== Marrison Custom Updater ===
Contributors: Angelo Marra
Tags: updater, plugin-updates
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 8.0.3
License: GPL-3.0+
License URI: https://www.gnu.org/licenses/gpl-3.0.txt


== Description ==

**Marrison Custom Updater** è una soluzione avanzata per gestire aggiornamenti di plugin e temi privati in WordPress. Permette di collegare il tuo sito WordPress a un repository personalizzato, consentendo di distribuire aggiornamenti per i tuoi plugin e temi proprietari con la stessa facilità di quelli ufficiali di WordPress.org.

**Funzionalità Principali:**

*   **Repository Privato:** Collega il tuo sito a una fonte esterna per ricevere aggiornamenti per plugin e temi non presenti nella directory ufficiale.
*   **Gestione Aggiornamenti Unificata:** Visualizza e installa aggiornamenti per plugin e temi privati direttamente dalla dashboard.
*   **Sistema di Backup Integrato:** Esegue automaticamente backup dei plugin prima dell'aggiornamento, permettendo il ripristino rapido (rollback) in caso di problemi.
*   **Supporto Temi e Plugin:** Gestisce sia estensioni (plugin) che temi grafici.
*   **Aggiornamenti Massivi:** Funzionalità "Aggiorna Tutto" per plugin, temi e traduzioni.
*   **Protezione dai Conflitti:** Sistema intelligente per evitare conflitti di versione con plugin ufficiali aventi lo stesso slug.
*   **Cache Ottimizzata:** Sistema di caching per ridurre le richieste al server remoto e migliorare le prestazioni della dashboard.
*   **Interfaccia Intuitiva:** Pannello di controllo chiaro con indicatori di stato, log delle versioni e gestione delle impostazioni.

Questo plugin è essenziale per agenzie, sviluppatori freelance e organizzazioni che mantengono un ecosistema di plugin personalizzati su molteplici installazioni WordPress.


== Installation ==

1. Scarica il file zip del plugin.
2. Carica il plugin nella tua installazione WordPress tramite la dashboard (Plugin > Aggiungi nuovo > Carica plugin) o via FTP nella cartella `/wp-content/plugins/`.
3. Attiva il plugin tramite il menu 'Plugin' di WordPress.
4. Vai alla pagina 'Impostazioni' del plugin per configurare l'URL del tuo repository privato.


== Changelog ==

= 8.0.3 =

* Fix: Risolto problema di visualizzazione del changelog nella finestra dei dettagli (ora supporta HTML).
* Fix: Risolto problema di rilevamento versione e compatibilità (lettura metadati da file locale).

= 8.0.2 =

* Riorganizzato il menu di amministrazione: ordine Aggiornamenti, Backup, Impostazioni.
* Aggiunta tab "Guida & Download" nella pagina Impostazioni.
* Abilitato il download dei file index.php per la configurazione dei repository plugin e temi.

= 8.0.1 =

* Aggiunti pulsanti per aggiornamento massivo di tutti i temi e tutte le traduzioni.
* Migliorata visualizzazione contatori: ora mostrano una spunta verde quando tutto è aggiornato.
* Risolto problema rilevamento traduzioni.
* Aggiunto scroll automatico alla barra di avanzamento durante gli aggiornamenti.

= 8.0.0 =

* Aggiunto supporto completo per Repository Privato Temi: ora è possibile aggiornare temi privati con la stessa logica dei plugin.
* Nuova sezione "Temi Repository Privato" nella dashboard aggiornamenti con funzionalità di aggiornamento singolo e bulk.
* Aggiunto campo URL Repository Temi nelle impostazioni.
* Unificata la gestione della cache per plugin e temi.
* Aggiornamenti minori all'interfaccia e alle notifiche.

= 7.9.9 =
* Fix: Risolto conflitto cache chiavi con Marrison Custom Installer.

= 7.9.8 =
* Verificato che l'indirizzo del repository privato non abbia valori di default.
* Correzioni minori.

= 7.9.7 =
* Aggiornato nome menu plugin in "AM Updater".
* Aggiunta icona personalizzata (SVG) al menu di amministrazione, integrata con lo stile nativo di WordPress.
* Spostata icona nella cartella `assets/`.

= 7.9.6 =
* Aggiunto supporto multilingua (i18n).
* Create cartelle e file per le traduzioni (.pot, .po).
* Aggiornate le stringhe del codice per essere traducibili.

= 7.9.5 =
* Risolto problema di visualizzazione del numero di versione nel messaggio di conferma dopo l'aggiornamento di un singolo plugin.
* Corretto errore di codifica caratteri nel popup di conferma ripristino backup.
* Risolto warning "Undefined variable $slug" nella generazione della lista plugin.
* Ripristinato il funzionamento AJAX "one-click" per il pulsante "Aggiorna tutti i plugin ufficiali".
* Disabilitato il filtro che forzava l'aggiornamento automatico, permettendo ora la gestione standard tramite interfaccia WordPress.

= 7.9.4 =
* Aggiornata la diagnostica per la privacy: ora mostra solo un sommario e i dettagli dei plugin effettivamente installati, nascondendo la lista completa del repository remoto.

= 7.9.3 =
* Implementata esclusione "tripla" dei plugin privati dalla lista ufficiale (check su dirname, filename e slug interno) per risolvere definitivamente i conflitti.
* Aggiunta indicazione "Inattivo" nella lista dei plugin monitorati per identificare meglio versioni duplicate o non utilizzate.

= 7.9.2 =
* Risolto problema discrepanza versioni tra lista ufficiale e privata: ora i plugin privati sono esclusi aggressivamente dagli aggiornamenti ufficiali basandosi sullo slug, risolvendo conflitti con installazioni duplicate o rinominate.
* Aggiunta lista visiva dei plugin monitorati ma già aggiornati nel pannello privato.

= 7.9.1 =
* Risolto problema di visualizzazione aggiornamenti per plugin installati in sottocartelle non standard (confronto versioni ora supporta spazi vuoti e percorsi complessi).
* Migliorata l'esclusione dei plugin privati dalla lista del repository ufficiale, utilizzando il controllo diretto sul file path.

= 7.9 =
* Migliorata la pulizia dei dati dal repository per evitare problemi di confronto versioni (trimming spazi).
* Forzato aggiornamento cache plugin (v2) per applicare le correzioni immediatamente.
* Migliorato rilevamento plugin installati in cartelle con nomi non standard.

= 7.8 =
* Implementato sistema di persistenza degli slug conosciuti: i plugin privati vengono ora nascosti dagli aggiornamenti pubblici anche se il server del repository è momentaneamente irraggiungibile.

= 7.7 =
* Corretto bug nell'installazione degli aggiornamenti: ora viene rispettata la cartella di installazione originale anche se diversa dallo slug del repository.

= 7.6 =
* Standardizzata logica di rilevamento plugin in tutta l'interfaccia.
* Risolto problema di visualizzazione aggiornamenti per plugin con nome cartella diverso dallo slug.

= 7.5 =
* Migliorato algoritmo di rilevamento plugin: ora cerca anche per nome file se la cartella non corrisponde.
* Pulizia automatica degli slug dal repository remoto.

= 7.4 =
* Aggiunta sezione di diagnostica nelle impostazioni.
* Migliorata logica di sovrascrittura degli aggiornamenti ufficiali.

= 7.3 =
* Risolto problema di sincronizzazione cache tra WP e repository privato.
* Aumentata priorità del filtro aggiornamenti per garantire la precedenza del repository privato.

= 7.2 =
* Priorità assoluta al repository privato: se un plugin è presente nel repository privato, gli aggiornamenti dal repository ufficiale vengono ignorati.

= 7.1 =
* Rimossa URL di default della repository.
* Correzioni e miglioramenti minori.

= 7.0 =
* Rimossa autorizzazione tramite JSON.
* Rimossa pagina Installer.
* Aggiunti pulsanti per aggiornamento massivo di temi e traduzioni.
* Aggiornamento core plugin.

= 6.0 =
* Introdotto supporto per aggiornamento traduzioni.
* Miglioramenti alle performance del checker.

= 5.0 =
* Aggiunta pagina Installer per installazione rapida plugin raccomandati.
* Integrazione con sistema di autorizzazione JSON remoto.

= 4.5 =
* Risolti problemi di compatibilità con versioni recenti di WordPress.
* Aggiunto supporto per aggiornamento temi da repository privato.

= 4.0 =
* Rifacimento interfaccia utente pannello opzioni.
* Aggiunto controllo integrità pacchetti ZIP.

= 3.0 =
* Implementato sistema di caching per ridurre chiamate API al repository.
* Ottimizzazione gestione transienti.

= 2.1 =
* Bugfix: correzione errore su server con configurazioni PHP restrittive.

= 2.0 =
* Aggiunta pagina di configurazione URL repository personalizzato.
* Migliorata gestione errori download.

= 1.0 =
* Rilascio iniziale.
* Funzionalità base di aggiornamento plugin da repository privato.