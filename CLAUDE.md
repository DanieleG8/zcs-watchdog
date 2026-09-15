# Note per chi lavora su questo repo

Due watchdog sorvegliano un impianto reale: `watchdog.php` guarda l'inverter
fotovoltaico ZCS, `tesla.php` la batteria Powerwall. Girano da soli su GitHub
Actions e mandano mail a persone che poi vanno a controllare l'impianto.

**Condividono il job ma non la logica.** Stanno nello stesso loop di
`watchdog.yml` perche' lo scheduler di GitHub e' avaro di risvegli e uno solo
deve coprirli entrambi. Restano pero' due watchdog distinti: stato, soglie,
condizioni e mail separate, uscite con prefissi diversi (`notify` /
`tesla_notify`), e il fallimento di uno non deve mai impedire l'esecuzione
dell'altro. L'unico punto di contatto e' presentazionale: ogni notifica cita la
misura dell'altro impianto leggendola dal suo **file di stato**, mai chiamando
la sua API. Quel dato non entra in nessuna decisione.

Il modo in cui questo sistema fallisce non e' andare in crash: e' **restare
zitto quando doveva parlare**, o **parlare quando non doveva**. Un allarme che
non parte non lo nota nessuno finche' non si guarda la bolletta. Un allarme che
parte a vuoto insegna a ignorare le mail, e da quel momento in poi non serve
piu' nemmeno quando ha ragione.

## La regola che non si salta

**Se cambi cosa il sistema rileva, come lo rileva, o cosa manda, aggiorni la
documentazione nello stesso commit.** Tre posti, tutti e tre:

1. `RIFERIMENTO.md` — l'elenco degli allarmi, la provenienza di ogni valore, le
   soglie. `tests/guida_test.php` fallisce se un titolo di notifica presente nel
   codice non compare qui, quindi questo passaggio la CI lo pretende da sola.
2. **La pagina per non tecnici**, il cui indirizzo e' in cima a `RIFERIMENTO.md`
   e che ogni mail porta in fondo. Questa **nessuna macchina puo' controllarla**:
   e' l'unica parte che dipende da te. La leggono le persone che ricevono gli
   avvisi e che dell'impianto sanno solo dove sta il quadro elettrico: se
   descrive allarmi che non esistono piu', decideranno in base a qualcosa di
   falso.
3. `ISTRUZIONI.md` / `ISTRUZIONI-TESLA.md` se cambia una procedura di
   configurazione.

Aggiungere un allarme e' quindi: la condizione, la prova, la riga in
`RIFERIMENTO.md`, la voce nella pagina. Quattro cose, non una.

## Come si scrive il codice qui

- **La logica di decisione sta in funzioni pure.** `evaluateProduction()`,
  `evaluateCondition()`, `interpretaRisposta()`, `estraiCode()` non toccano rete
  ne' filesystem: prendono dati, restituiscono un verdetto. E' cio' che rende
  possibile provare un guasto senza aspettare che accada.
- **Ogni comportamento nuovo arriva con la sua prova**, in `tests/`. Le prove
  girano senza rete: `php tests/watchdog_test.php`, `php tests/tesla_test.php`,
  `php tests/guida_test.php`.
- **I casi che si provano sono quelli veri.** Le prove piu' importanti in questo
  repo hanno un nome tipo "IL CASO DEL 14/09" o "IL CASO PANTA" e riproducono un
  dato realmente osservato sull'impianto. Se scopri un caso reale che il codice
  sbaglia, quella misura diventa una prova prima ancora della correzione.
- Niente dipendenze: PHP puro con `curl`, `json`, `sodium`. Non aggiungere
  Composer per una comodita'.
- Commenti in italiano, senza accenti nei sorgenti (`e'`, `piu'`).

## Le due decisioni da non smontare per sbaglio

Sono controintuitive e nascono da guasti veri. Se ti sembrano complicazioni
inutili, sono documentate in `RIFERIMENTO.md` e nei commit che le hanno
introdotte.

- **La produzione si giudica sul contatore di energia, non sulla potenza
  dichiarata.** Il portale ZCS ha mostrato 613 W mentre il contatore dei kWh era
  immobile. Un campo di potenza puo' mentire; l'energia entrata no.
- **Le misure di flusso del Powerwall non valgono come prova, `island_status`
  si.** Il 14/09 avevo messo qui la regola opposta: "l'isola dichiarata va
  confermata dal contatore rete". Era sbagliata, ed e' costata un giorno di
  silenzio su un allarme vero. `grid_power` ripete `load_power` al decimale in
  ogni campione (residuo calcolato, non misura) e `battery_power` dice 0 mentre
  la carica cala: un campo che non misura niente non puo' smentire niente.
  Quando i flussi si contraddicono il messaggio lo dichiara; la condizione la
  decide `island_status`. **Su dati incoerenti un watchdog parla, non tace.**

## Il repository e' pubblico

Chiunque legge il codice, la cronologia e **i log dei workflow**. I segreti
restano cifrati e mascherati, ma tutto il resto e' in chiaro. Quindi:

- **Mai un valore reale come dato di prova.** Un `code` OAuth gia' consumato non
  serve piu' a nessuno, ma resta nella cronologia per sempre e in un audit
  sembra una fuga di credenziali. Dati finti e riconoscibili come tali.
- **I valori pubblici finiscono nei log** perche' i workflow li passano come
  `env:` e Actions stampa il blocco: client_id, redirect_uri, site_id, soglie,
  coordinate. Quello che non deve essere leggibile va in un **Secret**, non in
  una Variable, anche quando "non e' proprio un segreto".
- **Niente dettagli dell'impianto nei documenti**: coordinate esatte, numeri di
  serie, identificativi del sito. Nei testi si citano i nomi delle Variables,
  non i loro valori.
- I workflow che portano secret (`watchdog.yml`, `tesla.yml`) **non devono mai**
  avere un trigger `pull_request`: chiunque puo' aprire una PR da un fork. Le
  prove girano su `pull_request` proprio perche' non toccano nessun secret e
  hanno `permissions: contents: read`.

## Cose che si rompono in silenzio

- **Il refresh token Tesla ruota a ogni rinnovo** e va risalvato nel secret del
  repo. Se il salvataggio fallisce, il monitoraggio della batteria muore entro
  24 ore. Qualsiasi cosa tocchi `persistRefreshToken()` o `githubSetSecret()` va
  guardata due volte: una risposta 2xx **senza corpo** e' un successo, non un
  errore.
- **Il cron di GitHub non rispetta la cadenza dichiarata.** Chiesto ogni 15
  minuti ne esegue una frazione. Per questo il cron sveglia soltanto il job, che
  poi cicla al proprio ritmo per ~55 minuti. Non "sistemare" il cron togliendo
  il loop.
- **I file di stato sono generati, e il loro salvataggio non si fonde.** Se due
  run si sovrappongono, un `git pull --rebase` su `state.json` trova un
  conflitto, lascia il repo in HEAD staccato con file non risolti e ogni
  tentativo successivo muore con "Pulling is not possible because you have
  unmerged files": lo stato di quel giro e' perso e il run e' rosso. Il passo
  `Persist state` percio' non rebasa: riparte dal remoto e riscrive i file che
  quel giro ha davvero toccato, perche' per una misura l'ultima scrittura e'
  quella buona.
- **Un secret di una sola lettera maschera quella lettera in tutti i log** del
  repo (`e***it 0`). Mai usare segnaposto corti.
