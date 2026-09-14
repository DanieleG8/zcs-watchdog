# Riferimento — mail che puoi ricevere e valori misurati

Due watchdog indipendenti, due impianti, due stati separati. Non si parlano:
se cade il fotovoltaico la batteria continua a essere sorvegliata, e viceversa.

| | Fotovoltaico ZCS | Batteria Tesla |
|---|---|---|
| Script | `watchdog.php` | `tesla.php` |
| Workflow | `.github/workflows/watchdog.yml` | `.github/workflows/tesla.yml` |
| Stato | `state.json` | `state-tesla.json` |
| Prefisso mail | `[FV ZCS]` | `[Powerwall]` |
| Cadenza | controllo ogni 5 min | controllo ogni 10 min |

---

## 0. La guida per chi le mail le riceve

Ogni notifica si chiude con un link a una spiegazione in parole povere:

    https://claude.ai/code/artifact/19aa137e-428a-4742-89c8-e0df7f06aaa9

Chi riceve la mail spesso non e' chi ha configurato il sistema, e un oggetto come
`MONITORAGGIO CIECO` da solo non dice a nessuno cosa fare. La pagina ordina le
mail per urgenza e per ognuna dice cosa significa e chi va chiamato.

> La pagina e' **privata**: perche' il link serva a qualcosa va condivisa dal menu
> della pagina stessa con chi riceve gli avvisi.

Per cambiare indirizzo basta la Variable `GUIDE_URL` del repo, senza toccare il
codice. Per togliere il link dalle mail il valore va messo a `off`: lasciarla
vuota **non** basta, perche' una Variable non impostata arriva allo script come
stringa vuota e vale il predefinito. Il valore predefinito sta nella
costante `GUIDE_URL` di `watchdog.php` e `tesla.php`, e
`tests/guida_test.php` verifica che i due coincidano e che sia lo stesso scritto qui.

**Se cambi il funzionamento, questa pagina va aggiornata insieme a questo file**:
`tests/guida_test.php` blocca la CI se un allarme del codice non compare in
RIFERIMENTO.md, ma la pagina per non tecnici nessuna macchina puo' controllarla.

---

## 1. Le mail

Tutte arrivano dallo stesso mittente (`ZCS Watchdog`) allo stesso indirizzo.
L'oggetto e' sempre `[prefisso] TITOLO`; il corpo ripete il titolo, aggiunge
il dettaglio con i numeri del momento e chiude con da quanto dura l'anomalia.

### Fotovoltaico — `[FV ZCS]`

| Oggetto | Cosa e' successo | Dopo quanto parte |
|---------|------------------|-------------------|
| **NESSUNA PRODUZIONE** | nella finestra di misura e' entrata meno energia di quella che la soglia richiede: l'impianto non sta producendo (o produce un filo di corrente) | subito a fine finestra: l'attesa e' gia' dentro i 60 min di misura |
| **NESSUNA PRODUZIONE (contatore non disponibile)** | l'API non ha restituito il contatore di energia, si giudica sulla sola potenza istantanea | 90 min di persistenza |
| **INVERTER OFFLINE (nessun dato)** | il `lastUpdate` dell'inverter e' fermo da troppo: l'inverter non parla piu' col portale | 60 min |
| **MONITORAGGIO CIECO (API non raggiungibile)** | l'API ZCS non risponde, o risponde senza dati validi. Non sai nulla dell'impianto | 30 min |
| **RIENTRO** | l'impianto e' tornato a produrre | subito |
| **TEST** | solo se la lanci a mano (`mode: test`) | — |

### Batteria — `[Powerwall]`

| Oggetto | Cosa e' successo | Dopo quanto parte |
|---------|------------------|-------------------|
| **POWERWALL SENZA TELEMETRIA** | il Gateway non manda dati da oltre 60 min: sistema staccato, gateway guasto, o rete di casa giu' | subito (il timeout e' gia' la soglia) |
| **POWERWALL IN ISOLA (rete assente)** | `island_status` fuori da `on_grid` **e** il contatore rete conferma che non passa corrente: blackout vero | 15 min |
| **POWERWALL QUASI SCARICO** | carica sotto la soglia minima. **Oggi spento** (`TESLA_SOC_MIN_PERCENT` = 0) | subito |
| **TOKEN TESLA NON VALIDO (monitoraggio fermo)** | la catena dei refresh token si e' rotta: il monitoraggio della batteria e' fermo, va rifatta l'autorizzazione | subito |
| **TOKEN TESLA DA RINNOVARE** | Tesla ha ruotato il token ma non e' stato possibile risalvarlo nel secret (PAT scaduto o senza permesso *Secrets: write*). **Hai poche ore** prima che il monitoraggio si fermi | subito |
| **MONITORAGGIO CIECO (Fleet API non raggiungibile)** | la Fleet API non risponde | 30 min |
| **RIENTRO** | il Powerwall e' tornato normale | subito |
| **TEST** | solo se la lanci a mano | — |

### Due regole che valgono per tutte

- **Anti-spam**: finche' l'anomalia dura, la mail si ripete ogni 4 ore
  (`RENOTIFY_HOURS`), non a ogni controllo.
- **Niente rientri fantasma**: il RIENTRO parte solo se l'allarme corrispondente
  era stato davvero spedito. Un guasto risolto prima della soglia di persistenza
  non genera ne' allarme ne' "tutto risolto".

---

## 2. Da dove arrivano i valori

### Fotovoltaico — API ZCS Azzurro

Endpoint `https://third.zcsazzurroportal.com:19003`, comando `realtimeData`,
autenticazione con `ZCS_CLIENT_CODE` + `ZCS_AUTH_KEY`, impianto identificato da
`ZCS_THING_KEY`. I campi si leggono da `realtimeData.params.value[0][thingKey]`.

| Campo | Da dove | A cosa serve |
|-------|---------|--------------|
| `energyGeneratingTotal` | contatore di energia totale dell'inverter, in kWh | **e' il giudice della produzione.** La differenza fra due letture divisa per il tempo da' i watt medi effettivi |
| `powerGenerating` | potenza istantanea dichiarata, in W | **non decide niente**, si limita a comparire nel messaggio. E' il campo che il 14/09 dichiarava 613 W mentre il contatore non si muoveva di un grammo |
| `lastUpdate` | ora dell'ultimo dato dell'inverter | decide lo STALE |

Alba e tramonto **non** arrivano dall'API: sono calcolati in locale dalle
coordinate dell'impianto (Variables `PLANT_LAT` / `PLANT_LON`) con
40 minuti di margine per lato. Fuori da quella finestra la misura resta
ancorata al presente e nessun allarme di produzione puo' scattare: di notte
non produrre e' normale.

**Perche' l'energia e non la potenza.** Un campo di potenza puo' restare
appeso a un valore vecchio, o riportare un valore che non si materializza da
nessuna parte. Il contatore no: se sale, l'energia e' entrata davvero. Il
criterio e' quindi "quanti kWh sono entrati nell'ultima ora", tradotto in watt
medi e confrontato con la soglia. Superata la soglia la finestra riparte da
capo, anche prima dei 60 minuti.

### Batteria — Fleet API Tesla

Endpoint europeo `https://fleet-api.prd.eu.vn.cloud.tesla.com`, chiamata
`/api/1/energy_sites/<TESLA_SITE_ID>/live_status`, autenticazione OAuth con
rotazione automatica del refresh token.

| Campo | Cosa e' | A cosa serve |
|-------|---------|--------------|
| `timestamp` | ora del campione | decide lo STALE |
| `island_status` | `on_grid` / `off_grid_intentional` / `off_grid_unintentional` | candidato all'allarme isola |
| `grid_status` | `Active` / `Inactive` / `Islanded` | ripiego se `island_status` manca |
| `grid_power` | scambio con la rete, in W (positivo = prelievo) | **conferma o smentisce l'isola**, e compare nel riepilogo |
| `percentage_charged` | carica della batteria, in % | soglia SOC |
| `battery_power` | potenza della batteria (negativo = in carica) | riepilogo |
| `load_power` | consumo di casa | riepilogo |
| `solar_power` | produzione vista dal Powerwall | riepilogo |
| `storm_mode_active` | modalita' tempesta | nota nel messaggio |

**Perche' l'isola va confermata.** Questo impianto dichiara stabilmente
`off_grid_unintentional` mentre dal contatore passano migliaia di watt. Un
sistema in isola non scambia con la rete, per definizione: se `grid_power`
supera in valore assoluto `TESLA_OFFGRID_GRID_W` (200 W, quanto basta a
lasciar fuori i consumi di servizio del gateway), l'etichetta e' sbagliata e
l'allarme non parte. Se `grid_power` manca del tutto non c'e' niente da
confrontare e si crede all'etichetta: meglio un falso allarme che un blackout
silenzioso.

**Cosa la Fleet API non dice.** I codici di guasto interni del Powerwall (la
lista `alerts` dei singoli battery block) li espone solo l'API locale del
Gateway, raggiungibile dalla rete di casa. Da GitHub si vede se il sistema
funziona — telemetria viva, rete collegata, flussi di potenza, carica — non
perche' un singolo modulo si lamenta.

---

## 3. Le soglie attive oggi

Sono tutte Variables del repo: si cambiano senza toccare il codice.

### Fotovoltaico

| Variable | Valore | Significato |
|----------|--------|-------------|
| `ZERO_W_THRESHOLD` | 1000 | watt medi sotto i quali si grida |
| `ENERGY_WINDOW_MIN` | 60 (default) | ampiezza della finestra di misura |
| `ZERO_PERSIST_MIN` | 90 | attesa del ripiego senza contatore |
| `STALE_LIMIT_MIN` | 60 | minuti di silenzio dell'inverter |
| `UNREACH_PERSIST_MIN` | 30 (default) | minuti di API muta |
| `DAY_MARGIN_MIN` | 40 (default) | margine su alba e tramonto |
| `RENOTIFY_HOURS` | 4 | ogni quanto si ripete un allarme |
| `LOOP_INTERVAL_SEC` | 300 | secondi fra un controllo e il successivo |

### Batteria

| Variable | Valore | Significato |
|----------|--------|-------------|
| `TESLA_STALE_LIMIT_MIN` | 60 (default) | minuti senza telemetria |
| `TESLA_OFFGRID_PERSIST_MIN` | 15 (default) | minuti in isola prima di avvisare |
| `TESLA_OFFGRID_GRID_W` | 200 (default) | scambio oltre il quale l'isola non e' credibile |
| `TESLA_UNREACH_PERSIST_MIN` | 30 (default) | minuti di Fleet API muta |
| `TESLA_SOC_MIN_PERCENT` | 0 (default) | soglia carica — **0 = controllo spento** |
| `TESLA_LOOP_INTERVAL_SEC` | 600 (default) | secondi fra un controllo e il successivo |

---

## 4. Quanto ci mette una mail ad arrivare

Il cron di GitHub e' inaffidabile: chiesto ogni 15 minuti, ne rispetta forse un
sesto. Per questo il cron serve solo a **svegliare** il job, che poi continua da
solo per ~55 minuti ricontrollando a intervalli regolari. Appena una condizione
matura, il loop esce subito e la mail parte: il ritardo massimo fra il momento in
cui l'anomalia e' accertata e la mail e' un intervallo di controllo, non un'ora.
