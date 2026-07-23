# ZCS Watchdog

Controllo automatico dell'inverter fotovoltaico **ZCS Azzurro** tramite GitHub Actions.
Ogni 15 minuti interroga l'API realtime e avvisa (Telegram / webhook) se l'impianto
smette di produrre o l'inverter va offline.

## Cosa rileva

- **STALE** — l'inverter non trasmette piu' dati (`lastUpdate` piu' vecchio della soglia). Controllo 24h/24.
- **ZERO** — di giorno (tra alba e tramonto) la potenza resta sotto soglia per N minuti.
- **UNREACH** — l'API non risponde: warning di monitoraggio, distinto dall'allarme impianto.

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
| `ZERO_W_THRESHOLD`  | 50      | W sotto cui = "zero produzione"        |
| `ZERO_PERSIST_MIN`  | 90      | min di zero diurno prima dell'allarme  |
| `STALE_LIMIT_MIN`   | 45      | min senza dati = inverter offline      |
| `RENOTIFY_HOURS`    | 6       | promemoria mentre resta in allarme     |
| `LASTUPDATE_IS_UTC` | false   | metti `true` se l'API restituisce UTC  |

### 3. Calibrazione (consigliata)
In locale, con le variabili d'ambiente valorizzate:

```bash
ZCS_CLIENT_CODE=... ZCS_AUTH_KEY=... ZCS_THING_KEY=... php watchdog.php --dump
```

Guarda il formato di `lastUpdate`: se e' in UTC, imposta la variabile `LASTUPDATE_IS_UTC=true`
(altrimenti rischi falsi "stale"). Con `--test` provi l'invio della notifica.

### 4. Attivazione
Vai in **Actions**, abilita i workflow, e lancia una volta a mano (**Run workflow**)
per verificare che giri. Poi parte da solo ogni 15 minuti.

## Note

- L'endpoint API gira sulla porta **19003** e il suo certificato spesso non valida:
  `ZCS_VERIFY_SSL` e' `false` di default. Le API di GitHub raggiungono la porta senza problemi.
- `CLIENT_CODE`, `AUTH_KEY` e `THING_KEY` vanno richiesti a ZCS/Zucchetti (accesso API).
  L'endpoint qui usato e' quello noto pubblicamente: se ZCS te ne fornisce uno aggiornato,
  cambia la costante `ENDPOINT` in `watchdog.php`.
- Progetto non affiliato a Zucchetti Centro Sistemi S.p.A.
