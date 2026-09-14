# Note per chi lavora su questo repo

Due watchdog indipendenti sorvegliano un impianto reale: `watchdog.php` guarda
l'inverter fotovoltaico ZCS, `tesla.php` la batteria Powerwall. Girano da soli su
GitHub Actions e mandano mail a persone che poi vanno a controllare l'impianto.

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
- **L'isola dichiarata dal Powerwall va confermata dal contatore rete.** Questo
  impianto dichiara stabilmente `off_grid_unintentional` mentre preleva
  chilowatt dalla rete. Fidarsi dell'etichetta significava una mail di blackout
  ogni sei ore.

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
- **Un secret di una sola lettera maschera quella lettera in tutti i log** del
  repo (`e***it 0`). Mai usare segnaposto corti.
