=== GRS SMTP ===
Contributors: grsdigital
Tags: smtp, email, mail, log, wp mail
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Invia le email di WordPress tramite il server SMTP del dominio, con email di test, log degli invii, avvisi di errore e controllo DNS.

== Description ==

GRS SMTP fa partire tutte le email del sito (moduli, WooCommerce, notifiche, recupero password) dal server SMTP della casella del cliente, invece che dalla funzione mail() del server. Risultato: meno email in spam e mittente corretto.

* Impostazioni SMTP con preset per Aruba, Register.it, Gmail / Google Workspace, Microsoft 365, Brevo e hosting cPanel/Plesk.
* Mittente personalizzato (email e nome): mai più wordpress@dominio e "WordPress".
* Password cifrata nel database oppure definita in wp-config.php.
* Email di test con spiegazione degli errori e dettaglio della connessione.
* Log degli invii con reinvio, anteprima, ricerca e pulizia automatica.
* Avviso in bacheca e via email quando un invio fallisce.
* Controllo SPF, DMARC e DKIM del dominio mittente.
* Modalità staging: blocca gli invii sulle copie di sviluppo.
* Avviso se è attivo un altro plugin SMTP.

Sviluppato da GRS Digital - https://grsdigital.it - info@grsdigital.it

== Installation ==

1. Bacheca → Plugin → Aggiungi nuovo → Carica plugin, scegli lo zip di GRS SMTP e attiva. Dalla 1.1.1 gli aggiornamenti successivi arrivano in automatico dalle release su GitHub.
2. GRS DEVs → SMTP: scegli il provider, inserisci utente, password e mittente, salva.
3. Scheda "Email di test": invia un test.
4. Scheda "Diagnostica": esegui il controllo DNS del dominio mittente.

= Configurazione da wp-config.php (facoltativa) =

Costanti disponibili: GRS_SMTP_HOST, GRS_SMTP_PORT, GRS_SMTP_ENCRYPTION (ssl, tls, none), GRS_SMTP_AUTH, GRS_SMTP_USER, GRS_SMTP_PASS, GRS_SMTP_FROM_EMAIL, GRS_SMTP_FROM_NAME, GRS_SMTP_DISABLE_DELIVERY. Aggiornamenti: arrivano dal repository pubblico https://github.com/grsdigitaldev/grs-smtp (sovrascrivibile con GRS_SMTP_UPDATE_REPO); GRS_SMTP_GITHUB_TOKEN serve solo per un eventuale repository privato.

== Frequently Asked Questions ==

= Le email continuano ad arrivare in spam =

Controlla la scheda Diagnostica: il dominio mittente deve avere SPF e DMARC corretti e, se possibile, DKIM attivo presso il provider. Usa come mittente lo stesso indirizzo dell'account SMTP.

= Il test fallisce con "Could not connect" =

L'hosting potrebbe bloccare le porte SMTP in uscita. Prova 587 con TLS oppure 465 con SSL e, se non funziona, chiedi all'hosting di sbloccarle.

= Cosa succede se cambio le chiavi di wp-config.php? =

La password salvata non è più decifrabile: il plugin lo segnala e basta inserirla di nuovo.

= Il plugin funziona in multisite? =

Sì, con impostazioni separate per ogni sito.

== Changelog ==

= 1.2.0 - 2026-10-17 =
* Nuova grafica: intestazione con logo GRS, schede a pillola, sezioni in card, pulsanti e campi arrotondati, colori GRS (grafite e giallo), stati più leggibili. Solo CSS leggero, nessuno script o font aggiuntivo.
* Lo stile è condiviso con gli altri plugin GRS tramite il componente GRS DEVs 1.1.0 e viene caricato solo sulle pagine GRS DEVs.

= 1.1.1 - 2026-10-17 =
* Aggiornamenti automatici attivi: il plugin controlla le release pubblicate su https://github.com/grsdigitaldev/grs-smtp e propone l'aggiornamento in bacheca.

= 1.1.0 - 2026-10-17 =
* Nuovo: la pagina del plugin si trova nel menu unico "GRS DEVs" (sotto Bacheca, voce "SMTP") insieme agli altri plugin GRS Digital; i vecchi indirizzi in Impostazioni portano automaticamente alla nuova pagina.
* Nuovo: GRS SMTP compare nella panoramica di GRS DEVs.

= 1.0.1 - 2026-10-17 =
* Corretto: la password SMTP non si perde più quando GRS Security (1.0.1+) rigenera le chiavi SALT: viene cifrata di nuovo in automatico.
* Corretto: avviso "Deprecated" su PHP 7.4 nei suggerimenti sugli errori.
* Migliorato: se le chiavi SALT cambiano per altri motivi, test, log e avvisi spiegano che la password va reinserita.
* Migliorato: suggerimenti sugli errori anche quando WordPress è in italiano o in altre lingue; nuovo suggerimento per i destinatari rifiutati.

= 1.0.0 - 2026-10-17 =
* Prima release.

== Upgrade Notice ==

= 1.2.0 =
Nuova grafica delle pagine del plugin, nessun cambiamento al funzionamento.

= 1.1.1 =
Da installare a mano una volta: dalla versione successiva gli aggiornamenti arrivano in automatico.

= 1.1.0 =
La pagina del plugin si sposta da Impostazioni al menu GRS DEVs → SMTP.

= 1.0.1 =
Consigliato se usi GRS Security: evita di dover reinserire la password SMTP dopo la rigenerazione delle chiavi SALT.

= 1.0.0 =
Prima release.
