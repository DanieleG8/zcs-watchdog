# ZCS Watchdog

Controllo automatico dell'inverter fotovoltaico **ZCS Azzurro** tramite GitHub Actions.
Interroga l'API realtime ogni 5 minuti e avvisa (email, piu' Telegram / webhook opzionali)
se l'impianto smette di produrre o l'inverter va offline.

Nel repo convivono due watchdog indipendenti:

| Workflow | Cosa guarda | Stato | Istruzioni |
|----------|-------------|-------|------------|
| `watchdog.yml` (`watchdog.php`) | inverter fotovoltaico ZCS Azzurro | `state.json` | `ISTRUZIONI.md` |
| `tesla.yml` (`tesla.php`) | batteria Tesla Powerwall via Fleet API | `state-tesla.json` | `ISTRUZIONI-TESLA.md` |

Condividono i secret `MAIL_*` e i canali di notifica; per il resto sono separati.

**[RIFERIMENTO.md](RIFERIMENTO.md)** elenca tutte le mail che i due watchdog possono
mandare, da quale campo dell'API nasce ogni valore, e le soglie attive.

## Cosa rileva (fotovoltaico)

- **STALE** — l'inverter non trasmette piu' dati (`lastUpdate` piu' vecchio della soglia). Controllo 24h/24.
- **ZERO** — di giorno l'energia **realmente entrata** nella finestra equivale a meno di
  `ZERO_W_THRESHOLD` watt medi.
- **UNREACH** — l'API non risponde: warning di monitoraggio, distinto dall'allarme impianto.

### Come si giudica la produzione (e perche' non basta la potenza)

Il 14/09/2026 il portale ZCS mostrava 613 W mentre l'API dava 0 W, e il contatore
`energyGeneratingTotal` non si muoveva di un decimo di kWh da oltre un'ora — il portale
stesso, nella stessa pagina, segnava "Energia Generata Giornalmente: 0 kWh".

Il watchdog quindi **non crede alla potenza dichiarata**: misura quanta energia entra
davvero nel contatore cumulativo in `ENERGY_WINDOW_MIN` minuti e la traduce in watt medi,
da confrontare con `ZERO_W_THRESHOLD`. La potenza istantanea resta solo un'informazione
nel messaggio.

- Se nella finestra entra abbastanza energia, la finestra si chiude in anticipo e
  riparte: un impianto che produce bene viene promosso subito, senza aspettare.
- Se la finestra si esaurisce sotto soglia, e' un guasto — anche se la potenza dichiara
  il contrario (allora il messaggio lo dice: "il portale segna X W ma quell'energia non
  entra da nessuna parte").
- Se il campo della potenza e' rotto ma l'energia entra, nessun allarme.
- Di notte la finestra resta ancorata al presente, cosi' la pausa delle ore buie non fa
  scattare nulla all'alba.
- Se l'API smettesse di mandare il contatore, si torna al vecchio criterio della sola
  potenza (con l'attesa di `ZERO_PERSIST_MIN`) invece di perdere l'allarme.

Attenzione alla taglia: la soglia e' l'unico parametro che dice "quanto poco e' troppo
poco". Su un impianto che fa ~450 kWh al giorno, 50 W non distinguono un guasto da un
impianto sano: va portata a qualche migliaio di watt.

Anti-spam: una notifica all'ingresso in allarme, una al rientro, promemoria ogni `RENOTIFY_HOURS`.
Lo stato vive in `state.json`, ricommittato dal workflow solo quando cambia (piu' un
"battito" giornaliero che tiene attivo lo scheduler — GitHub disabilita i cron dopo
60 giorni senza commit).

## Setup

### 1. Secrets del repo
`Settings > Secrets and variables > Actions > New repository secret`

| Secret            | Valore                                             |
|-------------------|----------------------------------------------------|
| `ZCS_CLIENT_CODE` | header `Client` (da ZCS/Zucchetti)                 |
| `ZCS_AUTH_KEY`    | header `Authorization` (da ZCS/Zucchetti)          |
| `ZCS_THING_KEY`   | seriale/thingKey dell'inverter                     |
| `MAIL_SERVER`     | host SMTP (es. `smtp.gmail.com`)                   |
| `MAIL_PORT`       | `465` (SSL)                                        |
| `MAIL_USERNAME`   | utente SMTP                                        |
| `MAIL_PASSWORD`   | password SMTP / App Password Gmail                |
| `MAIL_FROM`       | indirizzo mittente                                |
| `MAIL_TO`         | destinatario/i (virgola per più)                  |
| `TG_BOT_TOKEN`    | token bot Telegram — opzionale (canale extra)     |
| `TG_CHAT_ID`      | chat id — opzionale (canale extra)                |
| `WEBHOOK_URL`     | URL POST JSON `{tag,text}` — opzionale (canale extra) |

La notifica di default è via **email (SMTP)**: lo step "Send email" del workflow parte
solo quando lo script decide di notificare. Telegram/webhook sono canali aggiuntivi facoltativi.

### 2. Variables (opzionali — altrimenti valgono i default nello script)
`Settings > Secrets and variables > Actions > Variables`

| Variable            | Default | Note                                   |
|---------------------|---------|----------------------------------------|
| `PLANT_LAT`         | 44.0637 | latitudine impianto (per alba/tramonto)|
| `PLANT_LON`         | 12.4460 | longitudine impianto                   |
| `ZERO_W_THRESHOLD`  | 50      | W sotto cui = "zero produzione" (alzalo in proporzione all'impianto: su 90 kWp, 50 W non distinguono un guasto da un impianto sano) |
| `ZERO_PERSIST_MIN`  | 90      | attesa del solo ripiego senza contatore |
| `STALE_LIMIT_MIN`   | 45      | min senza dati = inverter offline      |
| `ENERGY_WINDOW_MIN` | 60      | minuti su cui si misura l'energia entrata |
| `RENOTIFY_HOURS`    | 6       | promemoria mentre resta in allarme     |
| `LASTUPDATE_IS_UTC` | false   | metti `true` se l'API restituisce UTC  |
| `LOOP_MINUTES`      | 55      | durata del loop interno (vedi sotto)   |
| `LOOP_INTERVAL_SEC` | 300     | secondi tra un controllo e il successivo|

### 3. Calibrazione (consigliata)
In locale, con le variabili d'ambiente valorizzate:

```bash
ZCS_CLIENT_CODE=... ZCS_AUTH_KEY=... ZCS_THING_KEY=... php watchdog.php --dump
```

Guarda il formato di `lastUpdate`: se e' in UTC, imposta la variabile `LASTUPDATE_IS_UTC=true`
(altrimenti rischi falsi "stale"). Con `--test` provi l'invio della notifica.

### 4. Attivazione
Vai in **Actions**, abilita i workflow, e lancia una volta a mano (**Run workflow**)
per verificare che giri. Poi parte da solo.

## Cadenza reale dei controlli

Lo scheduler `cron` di GitHub **non e' affidabile**: con `*/15 * * * *` (96 esecuzioni
attese al giorno) sul campo ne partivano circa 17, con buchi medi di 3-4 ore e punte
di 6. Un watchdog che guarda l'impianto ogni 6 ore non serve a molto.

Rimedio adottato: **ogni esecuzione, una volta partita, resta viva** e ripete il
controllo ogni `LOOP_INTERVAL_SEC` secondi per `LOOP_MINUTES` minuti. Il cron fitto
serve solo a farsi svegliare il prima possibile; la copertura la garantisce il loop.

- Se scatta una notifica, il loop **esce subito** e la mail parte nello step successivo:
  il ritardo massimo e' un intervallo (5 min), non l'intera finestra.
- `concurrency` impedisce sovrapposizioni: le esecuzioni in eccesso restano in coda e
  partono appena la precedente finisce, quindi la copertura resta continua.
- Per consumare meno runner (irrilevante su repo pubblico, dove i minuti sono illimitati)
  basta abbassare `LOOP_MINUTES`. Se lo alzi sopra ~60, alza anche `timeout-minutes`
  nel workflow.

Se un giorno vuoi una cadenza garantita al minuto, il workflow accetta gia' un trigger
esterno: un servizio cron gratuito (es. cron-job.org) che chiami
`POST /repos/OWNER/REPO/dispatches` con `{"event_type":"zcs-check"}` e un token.

## Note

- L'endpoint API gira sulla porta **19003** e il suo certificato spesso non valida:
  `ZCS_VERIFY_SSL` e' `false` di default. Le API di GitHub raggiungono la porta senza problemi.
- `CLIENT_CODE`, `AUTH_KEY` e `THING_KEY` vanno richiesti a ZCS/Zucchetti (accesso API).
  L'endpoint qui usato e' quello noto pubblicamente: se ZCS te ne fornisce uno aggiornato,
  cambia la costante `ENDPOINT` in `watchdog.php`.
- Le modalita' di avvio manuale sono: `run` (normale, in loop), `once` (un solo controllo),
  `dump` (stampa i valori grezzi, non tocca lo stato), `test` (invia una notifica di prova).
- Prove della logica: `php tests/watchdog_test.php` (fotovoltaico) e `php tests/tesla_test.php` (Powerwall).
- Progetto non affiliato a Zucchetti Centro Sistemi S.p.A. ne' a Tesla Inc.
