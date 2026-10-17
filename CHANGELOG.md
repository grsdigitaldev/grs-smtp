# Changelog

Tutte le modifiche rilevanti a GRS SMTP. Formato [Keep a Changelog](https://keepachangelog.com/it/1.1.0/), versioni [SemVer](https://semver.org/lang/it/).

## [1.1.1] - 2026-10-17

### Modificato
- Aggiornamenti automatici attivati: `Updater::DEFAULT_REPO` = https://github.com/grsdigitaldev/grs-smtp e header `Update URI` sullo stesso indirizzo. Il plugin scarica solo lo zip allegato alla release (`grs-smtp-x.y.z.zip`), mai il sorgente automatico del repository.

## [1.1.0] - 2026-10-17

### Aggiunto
- Pagina del plugin nel menu condiviso "GRS DEVs" (componente `includes/grs-devs/` versione 1.0.1, voce "SMTP") e registrazione nella panoramica dei plugin GRS.

### Modificato
- La pagina passa da `options-general.php?page=grs-smtp` a `admin.php?page=grs-smtp`: i vecchi indirizzi vengono reindirizzati mantenendo scheda e filtri. Link "Impostazioni", avvisi, email di allarme e barra admin aggiornati.
- L'esito del salvataggio delle impostazioni viene mostrato dal plugin (fuori da Impostazioni WordPress non lo fa più in automatico).

## [1.0.1] - 2026-10-17

### Corretto
- La password SMTP cifrata diventava illeggibile quando GRS Security rigenerava le chiavi SALT di wp-config.php (invii falliti con "535 Incorrect authentication data"). Ora GRS SMTP ascolta l'azione `grs_security_salts_regenerated` (GRS Security 1.0.1+) e cifra di nuovo la password con le chiavi nuove.
- Avviso "Deprecated" di PHP 7.4 nei suggerimenti sugli errori (codice 535 confrontato come numero).

### Migliorato
- Se la password non è più leggibile (chiavi cambiate a mano o da una migrazione), email di test, log e avvisi lo dicono esplicitamente invece del suggerimento generico sulle credenziali.
- I suggerimenti sugli errori riconoscono i messaggi di PHPMailer tradotti da WordPress nella lingua del sito.
- Nuovo suggerimento per i destinatari rifiutati dal server.

## [1.0.0] - 2026-10-17

### Aggiunto
- Invio delle email di WordPress tramite server SMTP (host, porta, SSL/TLS/nessuna, TLS automatico, autenticazione).
- Preset provider: Aruba, Register.it, Gmail / Google Workspace, Microsoft 365, Brevo, hosting cPanel/Plesk.
- Password SMTP cifrata nel database (AES-256) o definibile in wp-config.php; tutte le impostazioni principali sovrascrivibili da costanti.
- Mittente personalizzato (email e nome) con opzione "forza" e Return-Path allineato: niente più wordpress@dominio e "WordPress".
- Email di test con suggerimenti sugli errori comuni e debug SMTP con credenziali e contenuto nascosti.
- Log degli invii con stato, errore, dettaglio, anteprima sicura, reinvio, eliminazione, ricerca, filtri e conservazione automatica.
- Avviso in bacheca e (opzionale) email di allarme senza SMTP quando un invio fallisce.
- Controllo DNS del dominio mittente: SPF, DMARC, DKIM (con selettore), coerenza mittente/account.
- Modalità staging per bloccare tutti gli invii, con indicatore nella barra admin.
- Rilevamento di altri plugin SMTP attivi e di wp_mail() sostituito.
- Exporter/eraser privacy e testo suggerito per l'informativa.
- Aggiornamenti automatici da GitHub (Plugin Update Checker) quando configurati.
