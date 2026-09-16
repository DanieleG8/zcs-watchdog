# Riferimento — mail che puoi ricevere e valori misurati

Due watchdog indipendenti su un unico giro di controllo. Girano nello stesso
job (`.github/workflows/watchdog.yml`), uno dopo l'altro, ogni cinque minuti:
un solo risveglio copre tutti e due gli impianti. Ma restano separati dove
conta: stato proprio, soglie proprie, mail proprie. Se uno dei due script
fallisce, l'altro viene eseguito lo stesso e continua a sorvegliare.

| | Fotovoltaico ZCS | Batteria Tesla |
|---|---|---|
| Script | `watchdog.php` | `tesla.php` |
| Stato | `state.json` | `state-tesla.json` |
| Prefisso mail | `[FV ZCS]` | `[Powerwall]` |
| Uscite del job | `notify` / `subject` / `body` | `tesla_notify` / `tesla_subject` / `tesla_body` |

`.github/workflows/tesla.yml` non ha piu' un cron: resta la cassetta degli
attrezzi della batteria (`authurl`, `register`, `exchange`, `sites`, `dump`,
`test`), da lanciare a mano.

**Ogni mail porta la misura di entrambi gli impianti.** In fondo al messaggio,
sotto `-- Situazione rilevata --`, ci sono le due righe con stato, freschezza
del dato e riepilogo: un inverter fermo si legge diversamente se la batteria e'
carica o se e' a terra. Il dato dell'altro impianto si legge dal suo file di
stato, mai chiamando la sua API: se quel watchdog non ha mai girato il blocco
lo dichiara, e se la misura e' piu' vecchia di due ore lo segnala invece di
spacciarla per attuale.

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
| **INVERTER OFFLINE (nessun dato)** | **di giorno** il `lastUpdate` e' fermo da troppo: l'inverter non parla piu' col portale. Di notte il silenzio non e' un allarme (vedi sotto) | 60 min |
| **MONITORAGGIO CIECO (API non raggiungibile)** | l'API ZCS non risponde, o risponde senza dati validi. Non sai nulla dell'impianto | 30 min |
| **RIENTRO** | si e' chiusa una delle anomalie sopra. Il testo dice **quale**: "Impianto tornato a produrre" solo se la produzione e' stata misurata, altrimenti "Inverter tornato a trasmettere" con l'avvertenza che la produzione non e' verificata (o che l'ultima misura era negativa) | subito |
| **TEST** | solo se la lanci a mano (`mode: test`) | — |

### Batteria — `[Powerwall]`

| Oggetto | Cosa e' successo | Dopo quanto parte |
|---------|------------------|-------------------|
| **POWERWALL SENZA TELEMETRIA** | il Gateway non manda dati da oltre 60 min: sistema staccato, gateway guasto, o rete di casa giu' | subito (il timeout e' gia' la soglia) |
| **POWERWALL IN ISOLA (rete assente)** | `island_status` fuori da `on_grid`: il sistema dichiara di non avere la rete. Se le misure di flusso si contraddicono, il messaggio lo segnala invece di zittire l'allarme | 15 min |
| **POWERWALL QUASI SCARICO** | carica sotto la soglia minima. **Oggi spento** (`TESLA_SOC_MIN_PERCENT` = 0) | subito |
| **TOKEN TESLA NON VALIDO (monitoraggio fermo)** | la catena dei refresh token si e' rotta: il monitoraggio della batteria e' fermo, va rifatta l'autorizzazione | subito |
| **TOKEN TESLA DA RINNOVARE** | Tesla ha ruotato il token ma non e' stato possibile risalvarlo nel secret (PAT scaduto o senza permesso *Secrets: write*). **Hai poche ore** prima che il monitoraggio si fermi | subito |
| **MONITORAGGIO CIECO (Fleet API non raggiungibile)** | la Fleet API non risponde. Se la telemetria si e' spenta mentre un allarme era in corso, la mail lo nomina: non e' rientrato, semplicemente non si vede piu' | 90 min |
| **RIENTRO** | il Powerwall e' tornato normale | subito |
| **TEST** | solo se la lanci a mano | — |

### Due regole che valgono per tutte

- **Anti-spam**: finche' l'anomalia dura, la mail si ripete ogni 4 ore
  (`RENOTIFY_HOURS`), non a ogni controllo.
- **Niente rientri fantasma**: il RIENTRO parte solo se l'allarme corrispondente
  era stato davvero spedito. Un guasto risolto prima della soglia di persistenza
  non genera ne' allarme ne' "tutto risolto".
- **Di notte il fotovoltaico non produce verdetti, e quindi nemmeno mail.**
  Fra tramonto e alba (piu' il margine di `DAY_MARGIN_MIN`) la condizione e'
  `notte`, che non e' un giudizio ma la sua assenza: lo stato precedente resta
  com'e' — allarme aperto compreso — non parte nessuna notifica e non viene
  annunciato nessun rientro. All'alba si torna a misurare.

  Nasce da un caso vero. Le notti del 14→15 e 15→16/09 l'inverter ha smesso di
  trasmettere alle 19:40 ed e' tornato alle 07:18: il datalogger vive sul lato
  DC e al buio si spegne. Il watchdog ha spedito "INVERTER OFFLINE" a mezzanotte
  e un rientro alle 07:20, due notti di fila, senza che ci fosse nulla da fare
  ne' l'una ne' l'altra volta. Al buio l'impianto non produce comunque: una mail
  che sveglia e alla quale non si puo' rispondere insegna solo a ignorare le
  mail. **Lo stesso silenzio di giorno resta STALE**, perche' li' si interviene.

  Il verdetto negativo del giorno attraversa la notte e si azzera solo se il
  contatore riparte da capo, cioe' se l'inverter e' stato sostituito.

- **Non vedere non e' un verdetto: mentre non si vede, l'ultima cosa vista
  resta.** Le condizioni `unreachable` (API muta) e `auth` (token non valido)
  dicono qualcosa del monitoraggio, non della batteria. Se scattano mentre un
  allarme d'impianto e' aperto, quell'allarme non viene cancellato: viene messo
  da parte con la sua data di inizio e le mail gia' spedite, e ritrovato tale e
  quale quando la telemetria torna.

  Nasce anche questa da un caso vero. La notte del 16/09 il gateway di Tesla ha
  alternato 504, 424 e 503 per mezz'ora, nel mezzo di un'isola iniziata il
  giorno prima alle 14:40. Alle 03:12 e' partita una mail per un disservizio
  che non era dell'impianto — da qui i 90 minuti di pazienza invece di 30 — e
  alle 05:20, tornata la telemetria, l'isola e' ripartita da zero: la mail
  successiva l'avrebbe annunciata come nuova, "in corso da 0 min", invece che
  da quindici ore. C'era anche un silenzio possibile: se l'isola fosse finita
  durante il buco, il RIENTRO non sarebbe partito, perche' si guardava solo la
  notifica della cecita'. Ora si guarda quella dell'allarme rimasto sotto.

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
| `grid_power` | dovrebbe essere lo scambio con la rete, in W | **su questo impianto ripete `load_power`**: non decide niente, compare nel riepilogo |
| `percentage_charged` | carica della batteria, in % | soglia SOC |
| `battery_power` | potenza della batteria (negativo = in carica) | riepilogo |
| `load_power` | consumo di casa | riepilogo |
| `solar_power` | produzione vista dal Powerwall | riepilogo |
| `storm_mode_active` | modalita' tempesta | nota nel messaggio |

**I flussi di potenza di questo impianto non sono attendibili** (accertato il
15/09/2026, correggendo una regola sbagliata del giorno prima). In sei campioni
su sei `grid_power` ripete `load_power` **al decimale**: non e' una misura
indipendente, e' un residuo calcolato. E `battery_power` dichiara 0 mentre la
carica scende dal 16,3% al 10,6% in ventidue ore, il che e' impossibile: una
batteria che si scarica eroga. Con batteria e solare a zero, i chilowatt
attribuiti alla casa non li fornisce nessuno.

Per un giorno l'allarme isola ha preteso conferma proprio da `grid_power`, e
veniva zittito ogni volta. Era la peggior forma di errore possibile qui: il
sistema taceva mentre l'app Tesla diceva "alimentazione dalla rete interrotta".

Ora `island_status` decide da solo, e quando i flussi si contraddicono il
messaggio **lo dichiara** (`ATTENZIONE, le misure di flusso non sono coerenti`)
invece di dedurne qualcosa. Da un dato incoerente non si conclude: si avvisa.

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
| `TESLA_UNREACH_PERSIST_MIN` | 90 (default) | minuti di Fleet API muta |
| `TESLA_SOC_MIN_PERCENT` | 0 (default) | soglia carica — **0 = controllo spento** |
| `TESLA_LOOP_INTERVAL_SEC` | 600 (default) | secondi fra un controllo e il successivo |

---

## 4. Quanto ci mette una mail ad arrivare

Il cron di GitHub e' inaffidabile: chiesto ogni 15 minuti, ne rispetta forse un
sesto. Per questo il cron serve solo a **svegliare** il job, che poi continua da
solo per ~55 minuti ricontrollando a intervalli regolari. Appena una condizione
matura, il loop esce subito e la mail parte: il ritardo massimo fra il momento in
cui l'anomalia e' accertata e la mail e' un intervallo di controllo, non un'ora.
