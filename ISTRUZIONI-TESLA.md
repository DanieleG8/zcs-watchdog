# Istruzioni — Watchdog batteria Tesla Powerwall

Secondo watchdog, gemello di quello fotovoltaico: stessa logica, stesse mail,
stato separato in `state-tesla.json`, workflow separato (`.github/workflows/tesla.yml`).
Usa la **Fleet API ufficiale** di Tesla, quindi funziona da GitHub senza bisogno
di apparecchi accesi in casa.

Tutti i passaggi si fanno dal pannello **Actions** e dal browser: non serve PHP sul tuo PC.

---

## Cosa rileva (e cosa no)

| Allarme | Quando scatta |
|---------|----------------|
| **STALE** | il Gateway non manda piu' telemetria da oltre `TESLA_STALE_LIMIT_MIN` minuti (default 60): sistema staccato, gateway guasto, rete di casa giu' |
| **OFFGRID** | il sistema e' passato in isola (`island_status` diverso da `on_grid`) per oltre `TESLA_OFFGRID_PERSIST_MIN` minuti (default 15): blackout o distacco dalla rete |
| **SOC** | carica sotto `TESLA_SOC_MIN_PERCENT` (default 0 = controllo spento) |
| **AUTH** | il refresh token non e' piu' valido: il monitoraggio e' fermo, va rifatta l'autorizzazione |
| **UNREACH** | la Fleet API non risponde per oltre `TESLA_UNREACH_PERSIST_MIN` minuti (default 30) |

⚠️ La Fleet API **non espone i codici di guasto interni** del Powerwall (la lista
`alerts` dei singoli battery block). Quelli li da' solo l'API locale del Gateway,
raggiungibile dalla rete di casa. Da GitHub si vede lo stato di funzionamento
(telemetria viva, connessione alla rete, flussi di potenza, carica), che e' cio'
che serve per accorgersi che qualcosa non va.

---

## Prerequisiti

1. L'account Tesla **proprietario** del Powerwall (quello dell'app Tesla).
2. Un **dominio con HTTPS** che controlli, dove pubblicare un file: serve alla
   registrazione dell'app presso Tesla. Basta poter caricare un file statico.

---

## Passo 1 — Crea l'app su developer.tesla.com

1. Vai su https://developer.tesla.com e accedi con l'account Tesla.
2. Crea una nuova applicazione. Ti servira' indicare:
   - **Scopes**: spunta almeno *Energy Product Information* (`energy_device_data`).
     Non servono gli scope dei veicoli ne' i comandi.
   - **Allowed Origin / Redirect URI**: un indirizzo del tuo dominio, per esempio
     `https://tuodominio.it/tesla-callback`. Non deve per forza esistere come pagina:
     serve solo a ricevere il `?code=` nella barra degli indirizzi del browser.
3. A fine creazione annota **Client ID** e **Client Secret**.

## Passo 2 — Pubblica la chiave pubblica sul dominio

Da un terminale qualsiasi (o dal pannello del tuo hosting, se offre una shell):

```bash
openssl ecparam -name prime256v1 -genkey -noout -out tesla-private-key.pem
openssl ec -in tesla-private-key.pem -pubout -out com.tesla.3p.public-key.pem
```

Carica **solo** `com.tesla.3p.public-key.pem` in modo che risponda esattamente qui:

```
https://tuodominio.it/.well-known/appspecific/com.tesla.3p.public-key.pem
```

Verifica aprendolo nel browser: devi vedere il contenuto `-----BEGIN PUBLIC KEY-----`.
La chiave **privata** tienila da parte (per il solo monitoraggio energia non serve,
serve ai comandi verso i veicoli): non caricarla da nessuna parte.

## Passo 3 — Crea il PAT per il salvataggio del refresh token

Questo passaggio non e' facoltativo. **Tesla ruota il refresh token a ogni rinnovo**:
quello vecchio resta valido poche ore, quindi lo script deve poter scrivere quello
nuovo nei Secrets del repo.

1. https://github.com/settings/personal-access-tokens/new (token **fine-grained**).
2. **Repository access** → *Only select repositories* → `zcs-watchdog`.
3. **Permissions → Repository permissions**:
   - *Secrets*: **Read and write**
   - *Metadata*: Read (viene messo da solo)
4. Scadenza: mettila lunga e **segnati in agenda di rinnovarlo**, altrimenti un giorno
   il watchdog della batteria si spegne in silenzio.
5. Copia il token: sara' il secret `GH_SECRETS_TOKEN`.

## Passo 4 — Imposta i Secrets

Repo → **Settings → Secrets and variables → Actions → Secrets**.

| Nome                  | Valore                                              |
|-----------------------|-----------------------------------------------------|
| `TESLA_CLIENT_ID`     | Client ID dell'app Tesla                            |
| `TESLA_CLIENT_SECRET` | Client Secret dell'app Tesla                        |
| `TESLA_REDIRECT_URI`  | lo stesso identico redirect URI dichiarato nell'app |
| `GH_SECRETS_TOKEN`    | il PAT del Passo 3                                  |
| `TESLA_REFRESH_TOKEN` | mettilo vuoto o con un valore fittizio: lo riscrive il Passo 6 |
| `TESLA_SITE_ID`       | lo ricavi al Passo 7                                |

I secret `MAIL_*` sono gia' quelli del watchdog fotovoltaico: non serve rifarli.

Variables utili (tab **Variables**, tutte opzionali):

| Nome                        | Default | A cosa serve                               |
|-----------------------------|---------|--------------------------------------------|
| `TESLA_REGION`              | eu      | `eu`, `na` o `cn`                          |
| `TESLA_PUBLIC_KEY_DOMAIN`   | —       | il dominio del Passo 2 (serve al Passo 5)  |
| `TESLA_STALE_LIMIT_MIN`     | 60      | minuti senza telemetria = allarme          |
| `TESLA_OFFGRID_PERSIST_MIN` | 15      | minuti in isola prima di avvisare          |
| `TESLA_UNREACH_PERSIST_MIN` | 30      | minuti di API muta prima di avvisare       |
| `TESLA_SOC_MIN_PERCENT`     | 0       | soglia carica minima (0 = controllo spento)|
| `TESLA_LOOP_INTERVAL_SEC`   | 600     | secondi tra un controllo e il successivo   |

## Passo 5 — Registrazione una tantum del partner account

Imposta prima la variable `TESLA_PUBLIC_KEY_DOMAIN` col dominio del Passo 2
(solo il dominio, es. `tuodominio.it`), poi:

**Actions → Tesla Powerwall Watchdog → Run workflow → mode: `register`**

Deve rispondere "Registrazione completata". Se fallisce, il 99% delle volte e'
la chiave pubblica non raggiungibile all'indirizzo esatto del Passo 2.

## Passo 6 — Autorizza e ottieni il refresh token

1. **Run workflow → mode: `authurl`**. Nel log trovi un indirizzo lunghissimo.
2. Aprilo nel browser, accedi con l'account Tesla proprietario, autorizza.
3. Finirai su una pagina del tuo dominio (anche un 404 va bene): nella barra degli
   indirizzi c'e' `?code=XXXXX`. **Copia solo il valore di `code`.**
4. **Run workflow → mode: `exchange`**, incolla il code nel campo `code`, avvia.

Il log deve dire "Refresh token ottenuto e salvato nel secret TESLA_REFRESH_TOKEN".
Il token non viene mai stampato nei log.

> Il `code` dura pochi minuti ed e' monouso, ma su un repo **pubblico** il valore
> che scrivi nel campo resta visibile nella scheda del run: fai il passo 4 subito
> dopo il passo 3, cosi' quando qualcuno potesse leggerlo e' gia' stato consumato.
> Se ti va stretto, rendi il repo privato per il tempo di questa procedura.

## Passo 7 — Trova il tuo sito e collauda

1. **Run workflow → mode: `sites`** → nel log c'e' l'elenco dei prodotti:
   prendi il valore `energy_site_id` e mettilo nel secret `TESLA_SITE_ID`.
2. **Run workflow → mode: `dump`** → deve stampare `percentage_charged`,
   `battery_power`, `island_status`, `timestamp` ecc. Se li vedi, funziona.
3. **Run workflow → mode: `test`** → deve arrivarti l'email `[Powerwall] TEST`.

Da qui il workflow gira da solo come quello fotovoltaico: il cron sveglia il job,
che poi ricontrolla ogni 10 minuti per ~55 minuti.

---

## Manutenzione e possibili intoppi

- **Il refresh token scade dopo 3 mesi di inutilizzo** e viene ruotato a ogni rinnovo.
  Finche' il watchdog gira, si rinnova da solo. Se lo tieni fermo a lungo, rifai il Passo 6.
- **Se ricevi la mail "TOKEN TESLA DA RINNOVARE"**: lo script ha ottenuto un token nuovo
  ma non e' riuscito a salvarlo (PAT scaduto o senza permesso *Secrets: write*).
  Hai poche ore per sistemare il PAT prima che il monitoraggio si fermi.
- **Se ricevi "TOKEN TESLA NON VALIDO"**: la catena si e' rotta, rifai il Passo 6.
- **Rate limit**: la Fleet API ha una quota di chiamate. Un controllo ogni 10 minuti
  (~144 al giorno piu' un rinnovo token all'ora) sta larghissimo; se un domani vedi
  errori 429, alza `TESLA_LOOP_INTERVAL_SEC`.
- **Prove della logica**: `php tests/tesla_test.php` esegue i controlli che non
  richiedono rete (valutazione degli stati, parsing dei timestamp, formato notifica,
  cache del token).
