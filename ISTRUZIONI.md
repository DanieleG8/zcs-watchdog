# Istruzioni di installazione — ZCS Watchdog su GitHub

Guida per pubblicare e configurare tutto **a mano**, senza connettori.
Notifiche via **email (SMTP)** — nessun bot Telegram necessario.
Tempo stimato: ~15 minuti. Non serve installare PHP sul tuo PC: calibrazione e test
si lanciano dal pannello Actions.

---

## Contenuto del pacchetto

```
zcs-watchdog/
├── .github/workflows/watchdog.yml   # il job schedulato (cron 15 min + avvio manuale + invio email)
├── watchdog.php                     # lo script di controllo
├── state.json                       # stato iniziale (verra' aggiornato dal workflow)
├── .gitignore
├── README.md                        # descrizione tecnica
└── ISTRUZIONI.md                    # questo file
```

⚠️ La cartella `.github` inizia con un punto: su alcuni sistemi è nascosta.
Assicurati di caricarla, altrimenti il workflow non parte.

### Come funziona la mail
Lo script **decide quando notificare** (allarme, rientro, o test) con la sua logica
anti-spam. Quando decide, "passa" la notifica al workflow, che invia l'email tramite
un server **SMTP** (il tuo di dominio, oppure Gmail). Su GitHub non si può usare la
`mail()` di PHP perché i runner non hanno un server di posta: per questo si usa SMTP.

---

## Passo 1 — Crea il repository

1. Vai su https://github.com/new
2. **Repository name**: `zcs-watchdog`
3. Visibilità:
   - **Public** → minuti di Actions *illimitati* (consigliato: qui non c'è nulla di sensibile nel codice, le credenziali stanno nei Secrets).
   - **Private** → 2000 min/mese inclusi. A 15 minuti consumi ~2880 min/mese, quindi **su private supereresti il limite**: o lo tieni public, o porti il cron a 30 minuti (`*/30 * * * *`).
4. **Non** aggiungere README/gitignore (li porti tu). Crea il repo.

---

## Passo 2 — Carica i file

**Via web (piu' semplice):**
1. Nel repo → **Add file → Upload files**.
2. Trascina *il contenuto* della cartella `zcs-watchdog` (non la cartella stessa):
   `watchdog.php`, `state.json`, `README.md`, `.gitignore` e la cartella `.github`.
   - Se il drag&drop non prende `.github`, usa **Add file → Create new file**, come nome
     scrivi `.github/workflows/watchdog.yml` (le `/` creano le cartelle) e incolla il contenuto.
3. **Commit changes**.

**Oppure via git:**
```bash
cd zcs-watchdog
git init && git add . && git commit -m "ZCS watchdog"
git branch -M main
git remote add origin https://github.com/TUO_USER/zcs-watchdog.git
git push -u origin main
```

---

## Passo 3 — Procurati le credenziali API ZCS

Tre valori, da richiedere a **ZCS / Zucchetti** (accesso API del portale):
- **Client** → secret `ZCS_CLIENT_CODE`
- **Authorization** → secret `ZCS_AUTH_KEY`
- **thingKey** (= seriale inverter, anche sull'etichetta laterale) → secret `ZCS_THING_KEY`

---

## Passo 4 — Prepara l'invio email (SMTP)

Ti serve un account SMTP da cui spedire. Due strade:

### A) SMTP del tuo dominio / provider
Recupera dal tuo hosting/provider di posta: host SMTP, porta (usa **465 SSL**),
utente e password della casella. Sono i valori dei secret `MAIL_*` del Passo 5.

### B) Gmail (veloce)
1. Attiva la verifica in due passaggi sull'account Google.
2. Crea una **App Password**: https://myaccount.google.com/apppasswords
   (Google la genera solo con 2FA attiva; è una password dedicata a questo uso).
3. Usa:
   - `MAIL_SERVER` = `smtp.gmail.com`
   - `MAIL_PORT`   = `465`
   - `MAIL_USERNAME` = il tuo indirizzo Gmail
   - `MAIL_PASSWORD` = l'App Password generata (NON la password normale)
   - `MAIL_FROM` = il tuo indirizzo Gmail

> Il workflow usa la porta 465 con SSL (`secure: true`). Se il tuo server richiede la 587
> (STARTTLS), imposta `MAIL_PORT=587` e nel file `watchdog.yml`, nello step "Send email",
> cambia `secure: true` in `secure: false`.

---

## Passo 5 — Imposta i Secrets

Repo → **Settings → Secrets and variables → Actions → tab "Secrets" → New repository secret**.

| Nome              | Valore                                   | Obbligatorio |
|-------------------|------------------------------------------|--------------|
| `ZCS_CLIENT_CODE` | header Client                            | sì           |
| `ZCS_AUTH_KEY`    | header Authorization                     | sì           |
| `ZCS_THING_KEY`   | seriale inverter                         | sì           |
| `MAIL_SERVER`     | host SMTP (es. `smtp.gmail.com`)         | sì           |
| `MAIL_PORT`       | `465`                                    | sì           |
| `MAIL_USERNAME`   | utente SMTP                              | sì           |
| `MAIL_PASSWORD`   | password SMTP / App Password             | sì           |
| `MAIL_FROM`       | indirizzo mittente                       | sì           |
| `MAIL_TO`         | indirizzo/i destinatario (virgola per più) | sì         |

Canali extra facoltativi (se un giorno vuoi anche Telegram o un webhook, in aggiunta alla mail):
`TG_BOT_TOKEN`, `TG_CHAT_ID`, `WEBHOOK_URL`. Se non li imposti, restano semplicemente inattivi.

---

## Passo 6 — (Opzionale) Imposta le Variables

Stessa pagina, **tab "Variables"**. Se non le imposti, valgono i default dello script.

| Nome                | Default | A cosa serve                          |
|---------------------|---------|---------------------------------------|
| `PLANT_LAT`         | 44.0637 | latitudine impianto (alba/tramonto)   |
| `PLANT_LON`         | 12.4460 | longitudine impianto                  |
| `ZERO_W_THRESHOLD`  | 50      | W sotto cui = "zero produzione"       |
| `ZERO_PERSIST_MIN`  | 90      | min di zero diurno prima dell'allarme |
| `STALE_LIMIT_MIN`   | 45      | min senza dati = inverter offline     |
| `RENOTIFY_HOURS`    | 6       | ogni quante ore ripetere l'allarme    |
| `LASTUPDATE_IS_UTC` | false   | metti `true` se l'API dà orari in UTC |

👉 Metti `PLANT_LAT`/`PLANT_LON` con le coordinate reali del tuo impianto.

---

## Passo 7 — Abilita e calibra

1. Tab **Actions** → se richiesto, **"I understand my workflows, enable them"**.
2. **ZCS Watchdog → Run workflow → mode: `dump`** → avvia.
   - Nel log vedrai `powerGenerating` e `lastUpdate`.
   - **Controlla `lastUpdate`**: se è indietro di 1-2 ore rispetto all'ora reale, l'API è in UTC
     → imposta la variable `LASTUPDATE_IS_UTC=true` (altrimenti falsi "offline").
3. **Run workflow → mode: `test`** → devi ricevere l'**email** di prova.
   Se non arriva: ricontrolla i secret `MAIL_*`; con Gmail assicurati di usare l'App Password
   (non la password normale) e la porta 465.

---

## Passo 8 — Attivazione definitiva

Da qui parte da solo ogni 15 minuti.
- Se qualcosa non produce → ricevi l'email di allarme; al ritorno alla normalità → email di rientro.
- Prima esecuzione normale: **Run workflow → mode: `run`** (o aspetta il cron).

---

## Note e possibili intoppi

- **Ritardi del cron**: le esecuzioni schedulate di GitHub possono slittare di qualche minuto o
  accorparsi. Per un watchdog fotovoltaico è irrilevante.
- **Disabilitazione dopo 60 giorni**: GitHub sospende i cron se il repo non riceve commit.
  Lo script scrive un "battito" giornaliero in `state.json` che il workflow committa: il repo
  resta attivo anche a impianto sano.
- **Endpoint/porta**: l'API gira su `third.zcsazzurroportal.com:19003`. Se ZCS ti dà un endpoint
  aggiornato, cambia la costante `ENDPOINT` in `watchdog.php`.
- **Commit automatici**: vedrai commit `state: aggiornamento ...` fatti dal bot del workflow.
  È il meccanismo con cui lo stato sopravvive tra un'esecuzione e l'altra.
- **Errori "unreachable" occasionali**: se l'API ha un blip di rete, lo script aspetta 30 min di
  irraggiungibilità prima di avvisarti, così non ti spamma per singoli errori transitori.
- **SMTP che rifiuta l'invio**: alcuni provider bloccano l'SMTP autenticato di default (es. certe
  caselle aziendali). Se la casella del tuo dominio dà errori di autenticazione, la via più rapida
  è l'App Password di Gmail descritta al Passo 4B.
