<?php
/**
 * ZCS Azzurro - Watchdog produzione fotovoltaico (versione GitHub Actions)
 * -----------------------------------------------------------------------
 * Interroga l'API realtime dell'inverter ZCS e avvisa se:
 *   1) STALE    -> l'inverter non trasmette piu' dati (timestamp vecchio)  [24h]
 *   2) ZERO     -> di giorno l'energia realmente accumulata nella finestra
 *                  equivale a meno di ZERO_W_THRESHOLD watt medi            [solo alba-tramonto]
 *   3) UNREACH  -> l'API non risponde (rete / endpoint / auth)             [warning monitoraggio]
 *
 * Il giudizio sulla produzione NON si basa sulla potenza istantanea ma
 * sull'energia contata davvero: il 14/09/2026 il portale ZCS mostrava 613 W
 * mentre l'API dava 0 W, e energyGeneratingTotal non si muoveva di un decimo
 * di kWh da oltre un'ora (il portale stesso segnava 0 kWh giornalieri).
 *
 * Si misura quindi quanta energia entra in ENERGY_WINDOW_MIN minuti e la si
 * traduce in watt medi, confrontandoli con la stessa soglia: la potenza
 * istantanea resta solo un'informazione nel messaggio. Cosi' un campo potenza
 * rotto non genera falsi allarmi (se l'energia entra, l'impianto produce) e
 * una produzione simbolica non li nasconde (100 W su un impianto da 90 kWp
 * sono un guasto, anche se il contatore si muove).
 *
 * Pensato per girare su GitHub Actions via cron. La configurazione arriva
 * dalle variabili d'ambiente (impostate come Secrets/Variables del repo).
 * Lo stato e' su state.json, che il workflow ricommitta quando cambia.
 *
 * Notifiche: Telegram e/o webhook generico (per Slack, WhatsApp, ecc.).
 * mail() NON e' usata: sui runner non c'e' un MTA.
 *
 * Uso locale/diagnostica:
 *   php watchdog.php --dump   -> stampa potenza e lastUpdate grezzi (per calibrare)
 *   php watchdog.php --test   -> invia una notifica di prova
 */

date_default_timezone_set(env('TZ', 'Europe/Rome'));

/* ------------------------- Config da ambiente ------------------------- */

// API ZCS (secrets)
$CLIENT_CODE = env('ZCS_CLIENT_CODE', '');
$AUTH_KEY    = env('ZCS_AUTH_KEY', '');
$THING_KEY   = env('ZCS_THING_KEY', '');
const ENDPOINT   = 'https://third.zcsazzurroportal.com:19003';
$VERIFY_SSL  = envBool('ZCS_VERIFY_SSL', false);
const HTTP_TIMEOUT = 30;

// Posizione impianto (per alba/tramonto)
// Precisione volutamente grossolana: per alba e tramonto bastano pochi km,
// e le coordinate esatte di casa non hanno motivo di stare in chiaro.
$LAT = (float) env('PLANT_LAT', '44.06');
$LON = (float) env('PLANT_LON', '12.45');

// Soglie
$ZERO_W_THRESHOLD    = (int) env('ZERO_W_THRESHOLD', '50');
$ZERO_PERSIST_MIN    = (int) env('ZERO_PERSIST_MIN', '90');
$STALE_LIMIT_MIN     = (int) env('STALE_LIMIT_MIN', '45');
$ENERGY_WINDOW_MIN   = (int) env('ENERGY_WINDOW_MIN', '60');
$UNREACH_PERSIST_MIN = (int) env('UNREACH_PERSIST_MIN', '30');
$DAY_MARGIN_MIN      = (int) env('DAY_MARGIN_MIN', '40');
$RENOTIFY_HOURS      = (int) env('RENOTIFY_HOURS', '4');
$LASTUPDATE_IS_UTC   = envBool('LASTUPDATE_IS_UTC', false);

// Notifiche
$TG_BOT_TOKEN = env('TG_BOT_TOKEN', '');
$TG_CHAT_ID   = env('TG_CHAT_ID', '');
$WEBHOOK_URL  = env('WEBHOOK_URL', ''); // opzionale: riceve un POST JSON {tag,text}

// Guida alle mail per chi le riceve (vedi RIFERIMENTO.md).
const GUIDE_URL = 'https://claude.ai/code/artifact/19aa137e-428a-4742-89c8-e0df7f06aaa9';

const STATE_FILE   = __DIR__ . '/state.json';
const STATE_ALTRO  = __DIR__ . '/state-tesla.json';
const ETICHETTA_MIA   = 'Fotovoltaico';
const ETICHETTA_ALTRA = 'Batteria Tesla';

// Misura del giro corrente: finisce nello stato e in fondo a ogni notifica.
$MISURA = [];

/* ----------------------------- Runtime -------------------------------- */

$argvv  = $argv ?? [];
$isTest = in_array('--test', $argvv, true);
$isDump = in_array('--dump', $argvv, true);

if ($isTest) {
    notify('TEST', "Notifica di prova dal watchdog ZCS. Canali OK.");
    logline('Inviata notifica di test.');
    exit(0);
}

if (defined('ZCS_WATCHDOG_TEST')) return;   // caricato dai test: niente rete, niente stato

list($ok, $data, $err) = fetchRealtime($CLIENT_CODE, $AUTH_KEY, $THING_KEY, $VERIFY_SSL);

if ($isDump) {
    echo 'HTTP ok: ' . ($ok ? 'si' : 'no') . "\n";
    if (!$ok) { echo "Errore: $err\n"; exit(1); }
    $node = extractNode($data, $THING_KEY);
    echo 'Nodo: ' . json_encode($node, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $ts = parseLastUpdate($node['lastUpdate'] ?? null, $LASTUPDATE_IS_UTC);
    echo 'powerGenerating (W): ' . ($node['powerGenerating'] ?? 'assente') . "\n";
    echo 'lastUpdate: ' . ($node['lastUpdate'] ?? 'assente')
       . ' -> ' . ($ts ? date('Y-m-d H:i:s', $ts) . ' (' . round((time() - $ts) / 60, 1) . ' min fa)' : 'non parsato') . "\n";
    exit(0);
}

$state = loadState();
$now   = time();

/* 1. Condizione attuale */
$energy = ['etot_ref' => $state['etot_ref'] ?? null, 'ref_ts' => $now, 'prod_bad' => false];

if (!$ok) {
    $condition = 'unreachable';
    $detail    = spiegaErrore($err);
} else {
    $node = extractNode($data, $THING_KEY);
    if ($node === null) {
        $condition = 'unreachable';
        $detail    = 'Il portale ZCS ha risposto, ma senza i dati dell\'impianto '
                    . '(da controllare ZCS_AUTH_KEY e ZCS_THING_KEY). Dettaglio: '
                    . rigaTecnica((string) json_encode($data)) . '.';
    } else {
        list($condition, $detail, $energy) = evaluateProduction($node, $now, $state, [
            'zero_w_threshold'  => $ZERO_W_THRESHOLD,
            'stale_limit_min'   => $STALE_LIMIT_MIN,
            'energy_window_min' => $ENERGY_WINDOW_MIN,
            'lastupdate_is_utc' => $LASTUPDATE_IS_UTC,
            'lat'               => $LAT,
            'lon'               => $LON,
            'day_margin_min'    => $DAY_MARGIN_MIN,
        ]);
    }
}

$MISURA = ['status' => $condition, 'riepilogo' => $detail, 'riepilogo_ts' => $now];

/* 1-bis. Notte: nessun verdetto, quindi nessuna notifica e nessun rientro.
   Lo stato precedente resta com'e', allarme aperto compreso: al buio non si
   scopre niente di nuovo e non si risolve niente. */
if ($condition === 'notte') {
    $prevNotte = $state['status'] ?? 'ok';
    logline("NOTTE (stato '$prevNotte' conservato). $detail");

    // PERCHE' L'INVERTER TACE DI NOTTE, NON SI SA ANCORA.
    // Il silenzio e' agganciato al sole (ultimo campione pochi minuti dopo il
    // tramonto, primo poco dopo l'alba), ma questo non dice se a fermarsi sia
    // l'inverter o soltanto la registrazione del portale: chi ha l'impianto
    // dice che la sera non si spegne. La differenza si vede solo guardando
    // l'intero nodo ZCS a notte fonda, e nessuno stara' sveglio a farlo.
    // Quindi lo registra il watchdog: una riga all'ora, con gli identificativi
    // dell'impianto omessi perche' questo log e' pubblico. Se fra le 22:00 e
    // le 06:00 qualche campo cambia, l'inverter sta ancora parlando; se il
    // nodo e' identico riga dopo riga, non arriva piu' niente.
    $oraDump = date('Y-m-d H');
    if (($state['notte_dump'] ?? '') !== $oraDump) {
        logline('NOTTE, nodo ZCS cosi\' com\'e\': ' . nodoPerLog($node ?? null));
    }

    saveState([
        'status'        => $prevNotte,
        'since'         => $state['since'] ?? $now,
        'last_notified' => $state['last_notified'] ?? 0,
        'last_ok'       => $state['last_ok'] ?? 0,
        'hb'            => date('Y-m-d'),
        'notte_dump'    => $oraDump,
    ] + $energy);
    exit(0);
}

/* 2. Stato + anti-spam */
$persistMin = [
    'zero'        => 0,   // l'attesa e' gia' dentro la finestra di misura
    'zeropower'   => $ZERO_PERSIST_MIN,   // ripiego senza contatore: si aspetta come prima
    'stale'       => $STALE_LIMIT_MIN,
    'unreachable' => $UNREACH_PERSIST_MIN,
    'ok'          => 0,
];
$prev = $state['status'] ?? 'ok';
$hb   = date('Y-m-d'); // heartbeat: cambia una volta al giorno -> tiene "attivo" il repo

if ($condition === 'ok') {
    // Il rientro si annuncia solo se l'allarme era stato davvero notificato:
    // altrimenti si manda un "tutto risolto" per un guasto mai comunicato.
    if ($prev !== 'ok' && ($state['last_notified'] ?? 0) > 0) {
        notify('RIENTRO', testoRientro($prev, (bool) ($energy['prod_bad'] ?? false)) . "\n$detail");
        logline("RIENTRO da '$prev'. $detail");
    } elseif ($prev !== 'ok') {
        logline("Rientro da '$prev' senza notifica: l'allarme non era mai stato inviato. $detail");
    } else {
        logline("OK. $detail");
    }
    saveState(['status' => 'ok', 'since' => $now, 'last_notified' => 0, 'last_ok' => $now, 'hb' => $hb] + $energy);
    exit(0);
}

if ($prev === $condition) {
    $since        = $state['since'] ?? $now;
    $lastNotified = $state['last_notified'] ?? 0;
} else {
    $since        = $now;
    $lastNotified = 0;
}

$elapsedMin = ($now - $since) / 60;
if ($elapsedMin < $persistMin[$condition]) {
    logline(sprintf("PENDING '%s' %.0f/%d min. %s", $condition, $elapsedMin, $persistMin[$condition], $detail));
    saveState(['status' => $condition, 'since' => $since, 'last_notified' => $lastNotified,
               'last_ok' => $state['last_ok'] ?? 0, 'hb' => $hb] + $energy);
    exit(0);
}

$shouldNotify = ($lastNotified === 0) || (($now - $lastNotified) >= $RENOTIFY_HOURS * 3600);
if ($shouldNotify) {
    $titles = [
        'zero'        => 'NESSUNA PRODUZIONE',
        'zeropower'   => 'NESSUNA PRODUZIONE (contatore non disponibile)',
        'stale'       => 'INVERTER OFFLINE (nessun dato)',
        'unreachable' => 'MONITORAGGIO CIECO (API non raggiungibile)',
    ];
    $body = $titles[$condition] . "\n\n$detail\n\nAnomalia in corso dalle "
          . date('Y-m-d H:i', $since) . sprintf(' (%.0f min).', $elapsedMin);
    notify($titles[$condition], $body);
    $lastNotified = $now;
    logline("NOTIFICATO '$condition'. $detail");
} else {
    logline(sprintf("ALLARME '%s' attivo (gia' notificato). %s", $condition, $detail));
}

saveState(['status' => $condition, 'since' => $since, 'last_notified' => $lastNotified,
           'last_ok' => $state['last_ok'] ?? 0, 'hb' => $hb] + $energy);
exit(0);


/* ============================== Funzioni ============================== */

/**
 * Il testo di un rientro deve dire COSA e' rientrato. "Impianto tornato a
 * produrre" spedito alla fine di un allarme di comunicazione e' una bugia:
 * l'inverter ha ripreso a parlare, della produzione non si sa nulla. E se
 * l'ultima misura era negativa, va detto a chiare lettere che il guasto
 * che ha fatto partire tutto e' ancora li'.
 */
function testoRientro(string $prev, bool $prodBad): string
{
    if ($prev === 'zero' || $prev === 'zeropower') {
        return 'Impianto tornato a produrre.';
    }

    $cosa = [
        'stale'       => 'Inverter tornato a trasmettere.',
        'unreachable' => 'API di nuovo raggiungibile: il monitoraggio ci vede.',
    ][$prev] ?? 'Anomalia rientrata.';

    return $cosa . ($prodBad
        ? ' ATTENZIONE: riguarda solo il collegamento. L\'ultima misura di produzione'
          . ' era negativa, quindi l\'impianto NON risulta tornato a produrre.'
        : ' Riguarda solo il collegamento: la produzione non e\' ancora stata misurata.');
}

/**
 * Decide la condizione incrociando due segnali indipendenti: la potenza
 * istantanea e l'avanzamento del contatore di energia totale.
 *
 * Ritorna [condizione, dettaglio, statoContatore] dove statoContatore va
 * risalvato nello stato ('etot' = ultimo valore visto, 'etot_ts' = quando
 * quel valore e' comparso, cioe' l'ultima volta che il contatore si e' mosso).
 *
 * Di notte il cronometro del contatore viene tenuto azzerato: altrimenti la
 * fermata fisiologica delle ore buie farebbe scattare un allarme all'alba.
 */
function evaluateProduction(array $node, int $now, array $state, array $cfg): array
{
    $powerW = (float) ($node['powerGenerating'] ?? 0);
    $lastTs = parseLastUpdate($node['lastUpdate'] ?? null, $cfg['lastupdate_is_utc']);
    $ageMin = $lastTs ? ($now - $lastTs) / 60 : PHP_INT_MAX;
    $quando = $lastTs ? date('H:i', $lastTs) : 'n/d';
    $soglia = (float) $cfg['zero_w_threshold'];
    $finestra = (int) $cfg['energy_window_min'];

    // prod_bad si trascina: un inverter che smette di parlare non e' un inverter
    // che ha ripreso a produrre. Azzerarlo qui faceva dimenticare l'allarme
    // produzione appena calava il buio.
    $prodBadPrec = (bool) ($state['prod_bad'] ?? false);
    $isDay = isDaytime($now, $cfg['lat'], $cfg['lon'], $cfg['day_margin_min']);

    // DI NOTTE NON SI GIUDICA. Questo inverter smette di trasmettere a ridosso
    // del tramonto e riprende dopo l'alba: il 15 e il 16/09 l'ultimo dato e'
    // arrivato alle 19:40 (tramonto 19:35) e il primo alle 07:18 (alba 07:02).
    // Perche' lo faccia non si sa da fuori, e non serve saperlo: il silenzio
    // segue il sole, e tanto basta. Due notti di fila il watchdog ha spedito
    // "INVERTER OFFLINE" a mezzanotte e un "rientro" alle 07:20, senza che ci
    // fosse niente da fare ne' l'una ne' l'altra volta. Al buio l'impianto non
    // produce comunque: una segnalazione che sveglia e alla quale non si puo'
    // rispondere insegna solo a ignorare le mail.
    //
    // La finestra la decide il calcolo di alba e tramonto, non il silenzio: il
    // margine di DAY_MARGIN_MIN la stringe da tutte e due le parti, cosi' il
    // giudizio si chiude prima che l'inverter taccia e riapre dopo che ha
    // ripreso.
    //
    // 'notte' non e' un verdetto: e' l'assenza di verdetto. Chi la riceve
    // conserva lo stato di prima - allarme aperto compreso - senza notificare
    // e senza annunciare rientri. All'alba si torna a misurare, e se l'inverter
    // e' ancora muto allora si', quello e' un guasto da raccontare.
    if (!$isDay) {
        $etotOra = isset($node['energyGeneratingTotal']) ? (float) $node['energyGeneratingTotal'] : null;
        $testo = $ageMin > $cfg['stale_limit_min']
            ? sprintf('Notte: inverter in silenzio da %.0f min (ultimo dato %s). Normale al buio, '
                . 'si rivaluta all\'alba.', $ageMin, $quando)
            : sprintf('Notte: nessuna produzione attesa. Potenza %.0f W. Ultimo dato %s.', $powerW, $quando);
        return ['notte', $testo, [
            'etot_ref' => $etotOra ?? ($state['etot_ref'] ?? null),
            'ref_ts'   => $now,          // la finestra resta ancorata al presente
            'prod_bad' => $prodBadPrec,  // il verdetto del giorno sopravvive alla notte
        ]];
    }

    if ($ageMin > $cfg['stale_limit_min']) {
        return ['stale', sprintf('Ultimo dato %s (%.0f min fa).',
            $lastTs ? date('Y-m-d H:i:s', $lastTs) : 'n/d', $ageMin),
            ['etot_ref' => $state['etot_ref'] ?? null, 'ref_ts' => $now, 'prod_bad' => $prodBadPrec]];
    }

    $etot  = isset($node['energyGeneratingTotal']) ? (float) $node['energyGeneratingTotal'] : null;

    // Senza contatore non si puo' misurare nulla: si torna al vecchio criterio
    // della sola potenza istantanea, con la sua attesa (ZERO_PERSIST_MIN).
    if ($etot === null) {
        $vuoto = ['etot_ref' => null, 'ref_ts' => $now, 'prod_bad' => $prodBadPrec];
        if ($isDay && $powerW <= $soglia) {
            return ['zeropower', sprintf('Potenza %.0f W di giorno (soglia %.0f W), contatore di '
                . 'energia non disponibile. Ultimo dato %s.', $powerW, $soglia, $quando), $vuoto];
        }
        return ['ok', sprintf('Potenza %.0f W, contatore non disponibile. Ultimo dato %s.',
            $powerW, $quando), $vuoto];
    }

    // Di notte la finestra resta ferma al presente: la pausa delle ore buie non
    // deve trasformarsi in un allarme alla prima luce.
    $ref   = isset($state['etot_ref']) ? (float) $state['etot_ref'] : null;
    $refTs = (int) ($state['ref_ts'] ?? 0);

    // Contatore azzerato o inverter sostituito: si riparte davvero da capo,
    // verdetto precedente compreso.
    if ($etot < $ref) {
        return ['ok', sprintf('Contatore ripartito da %.1f kWh. Potenza %.0f W. Ultimo dato %s.',
            $etot, $powerW, $quando),
            ['etot_ref' => $etot, 'ref_ts' => $now, 'prod_bad' => false]];
    }

    // Primo giro senza stato: non c'e' finestra da confrontare, si parte pulito.
    if ($ref === null || $refTs === 0) {
        return ['ok', sprintf('Potenza %.0f W. Totale %.1f kWh. Ultimo dato %s.', $powerW, $etot, $quando),
            ['etot_ref' => $etot, 'ref_ts' => $now, 'prod_bad' => false]];
    }

    $minuti   = ($now - $refTs) / 60;
    $entrata  = $etot - $ref;                          // kWh accumulati nella finestra
    $servono  = $soglia * $minuti / 60 / 1000;         // kWh minimi per stare sopra soglia
    $mediaW   = $minuti > 0 ? $entrata * 1000 * 60 / $minuti : 0.0;
    $prodBad  = (bool) ($state['prod_bad'] ?? false);

    if ($entrata >= $servono && $entrata > 0) {
        // Bastano i kWh gia' entrati per superare la soglia: promosso subito,
        // senza aspettare la fine della finestra. La finestra riparte da qui.
        return ['ok', sprintf('Media %.0f W negli ultimi %.0f min (soglia %.0f W). '
            . 'Potenza istantanea %.0f W. Ultimo dato %s.', $mediaW, $minuti, $soglia, $powerW, $quando),
            ['etot_ref' => $etot, 'ref_ts' => $now, 'prod_bad' => false]];
    }

    if ($minuti >= $finestra) {
        // Finestra intera sotto soglia: e' un guasto, comunque lo racconti la potenza.
        $extra = $powerW > $soglia
            ? sprintf(' Il portale segna %.0f W ma quell\'energia non entra da nessuna parte.', $powerW)
            : sprintf(' Potenza istantanea %.0f W.', $powerW);
        return ['zero', sprintf('Solo %.2f kWh in %.0f min: %.0f W medi contro una soglia di %.0f W.%s '
            . 'Totale %.1f kWh. Ultimo dato %s.', $entrata, $minuti, $mediaW, $soglia, $extra, $etot, $quando),
            ['etot_ref' => $etot, 'ref_ts' => $now, 'prod_bad' => true]];
    }

    // Finestra ancora aperta: si conserva il verdetto precedente.
    $stato = ['etot_ref' => $ref, 'ref_ts' => $refTs, 'prod_bad' => $prodBad];
    $testo = sprintf('%.2f kWh in %.0f min (finestra %d min, soglia %.0f W). Potenza istantanea %.0f W. Ultimo dato %s.',
        $entrata, $minuti, $finestra, $soglia, $powerW, $quando);
    return [$prodBad ? 'zero' : 'ok', $testo, $stato];
}

function env(string $k, string $default = ''): string {
    $v = getenv($k);
    return ($v === false || $v === '') ? $default : $v;
}
function envBool(string $k, bool $default): bool {
    $v = getenv($k);
    if ($v === false || $v === '') return $default;
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

function fetchRealtime(string $client, string $auth, string $thing, bool $verify): array {
    $payload = json_encode([
        'realtimeData' => [
            'command' => 'realtimeData',
            'params'  => ['thingKey' => $thing, 'requiredValues' => '*'],
        ],
    ]);
    $ch = curl_init(ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Client: ' . $client,
            'Authorization: ' . $auth,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false)               return [false, null, "cURL: $cerr"];
    if ($code < 200 || $code >= 300)   return [false, null, "HTTP $code. Body: " . substr($resp, 0, 300)];
    $data = json_decode($resp, true);
    if (!is_array($data))              return [false, null, 'Non JSON: ' . substr($resp, 0, 300)];
    return [true, $data, ''];
}

function extractNode(?array $data, string $thing): ?array {
    $value = $data['realtimeData']['params']['value'][0] ?? null;
    if (!is_array($value)) return null;
    if (isset($value[$thing]) && is_array($value[$thing])) return $value[$thing];
    $first = reset($value);
    return is_array($first) ? $first : null;
}

/**
 * Un errore HTTP raccontato a chi la mail la riceve. Stessa idea del gemello
 * in tesla.php: nella mail finiva il corpo grezzo della risposta tagliato a
 * meta' parola, con HTML ed escape non risolte dentro. Chi riceve queste mail
 * sa dove sta il quadro elettrico, non cosa sia un 503.
 */
function spiegaErrore(string $err): string {
    if ($err === '') return '';
    $codice = preg_match('/^HTTP (\d{3})/', $err, $m) ? (int) $m[1] : 0;

    if (str_starts_with($err, 'cURL:')) {
        return 'Non si riesce nemmeno a raggiungere il portale ZCS (la connessione non parte). '
             . 'Dettaglio: ' . rigaTecnica($err) . '.';
    }
    $frasi = [
        500 => 'Il portale ZCS ha un problema interno',
        502 => 'Il portale ZCS non risponde',
        503 => 'Il portale ZCS non risponde',
        504 => 'Il portale ZCS risponde troppo lentamente e la richiesta scade',
        429 => 'Il portale ZCS sta limitando le richieste: ne sono state fatte troppe',
    ];
    if (isset($frasi[$codice])) {
        return $frasi[$codice] . " (errore $codice). Non e' un guasto dell'impianto: quasi sempre "
             . 'rientra da solo. Dettaglio: ' . rigaTecnica($err) . '.';
    }
    if ($codice === 401 || $codice === 403) {
        return "Il portale ZCS rifiuta le credenziali (errore $codice). Questo non rientra da solo: "
             . 'vanno controllati ZCS_CLIENT_CODE e ZCS_AUTH_KEY. Dettaglio: ' . rigaTecnica($err) . '.';
    }
    if ($codice >= 400) {
        return "Il portale ZCS ha risposto con un errore $codice. Dettaglio: " . rigaTecnica($err) . '.';
    }
    return rigaTecnica($err);
}

/** Il dettaglio tecnico su una riga: niente HTML, niente a capo, taglio su parola intera. */
function rigaTecnica(string $err, int $max = 140): string {
    $t = html_entity_decode(strip_tags($err), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    $t = rtrim($t, " .\t");
    if ($t === '') return 'nessun dettaglio leggibile';
    if (mb_strlen($t) <= $max) return $t;
    $tagliato = mb_substr($t, 0, $max);
    $spazio   = mb_strrpos($tagliato, ' ');
    return rtrim($spazio > $max / 2 ? mb_substr($tagliato, 0, $spazio) : $tagliato, " ,;:") . '...';
}

/**
 * Il nodo ZCS pronto per finire nel log, senza gli identificativi dell'impianto.
 *
 * Il log dei workflow di questo repo e' PUBBLICO: numeri di serie, thingKey e
 * simili non ci devono comparire. I nomi dei campi si', perche' sono
 * esattamente cio' che serve guardare.
 */
function nodoPerLog(?array $node): string {
    if (!is_array($node)) return 'nessun nodo';
    $fuori = [];
    foreach ($node as $k => $v) {
        if (chiaveSensibile((string) $k) || valoreIdentificativo($v)) {
            $fuori[$k] = is_scalar($v) ? '[omesso, ' . strlen((string) $v) . ' caratteri]' : '[omesso]';
            continue;
        }
        $fuori[$k] = is_scalar($v) || $v === null ? $v : '[' . gettype($v) . ']';
    }
    return json_encode($fuori, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Nomi di campo che non devono uscire. 'sn$' e non '\bsn\b': in 'deviceSn'
 *  non c'e' nessun confine di parola prima di 'Sn', e quel seriale passava. */
function chiaveSensibile(string $k): bool {
    return (bool) preg_match('/key|serial|uuid|mac|token|passw|secret|code|^id$|sn$|^sn/i', $k);
}

/**
 * Seconda rete, che non si fida dei nomi: i campi del portale ZCS non li
 * conosciamo tutti, e quello nuovo che porta un seriale si chiamera' in un
 * modo a cui nessuno ha pensato. Una stringa lunga, senza spazi e fatta solo
 * di caratteri da identificativo si omette comunque. Le date no: sono la cosa
 * che qui serve di piu' guardare.
 */
function valoreIdentificativo($v): bool {
    if (!is_string($v) || strlen($v) <= 12) return false;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return false;   // una data resta
    if (is_numeric($v)) return false;                            // una misura resta
    return (bool) preg_match('/^[A-Za-z0-9._:-]+$/', $v);
}

function parseLastUpdate($raw, bool $isUtc): ?int {
    if ($raw === null || $raw === '') return null;
    if (is_numeric($raw)) {
        $n = (int) $raw;
        if ($n > 1000000000000) $n = intdiv($n, 1000);
        return $n;
    }
    $tz = new DateTimeZone($isUtc ? 'UTC' : date_default_timezone_get());
    $dt = date_create((string) $raw, $tz);
    return $dt ? $dt->getTimestamp() : (strtotime((string) $raw) ?: null);
}

function isDaytime(int $now, float $lat, float $lon, int $marginMin): bool {
    $info = date_sun_info($now, $lat, $lon);
    $rise = $info['sunrise'] ?? null;
    $set  = $info['sunset']  ?? null;
    if (!$rise || !$set) return true;
    $m = $marginMin * 60;
    return ($now >= $rise + $m) && ($now <= $set - $m);
}

function loadState(): array {
    if (!is_file(STATE_FILE)) return [];
    $j = json_decode((string) file_get_contents(STATE_FILE), true);
    return is_array($j) ? $j : [];
}
function saveState(array $s): void {
    global $MISURA;
    // La misura viaggia nello stato cosi' l'altro watchdog puo' citarla nelle
    // sue mail senza interrogare questa API.
    if (isset($MISURA['riepilogo'])) {
        $s['riepilogo']    = $MISURA['riepilogo'];
        $s['riepilogo_ts'] = $MISURA['riepilogo_ts'];
    }
    file_put_contents(STATE_FILE, json_encode($s, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
}
function logline(string $msg): void {
    echo date('Y-m-d H:i:s') . "  $msg\n"; // finisce nel log del job Actions
}


/**
 * Ogni notifica porta con se' il link alla guida in parole povere: chi riceve
 * la mail spesso non e' chi ha configurato il sistema, e un oggetto come
 * "MONITORAGGIO CIECO" da solo non dice a nessuno cosa fare.
 * Si puo' sovrascrivere con la variable GUIDE_URL, o svuotare per toglierlo.
 */
function conGuida(string $body): string
{
    // Una Variable non impostata arriva qui come stringa vuota, quindi il vuoto
    // vuol dire "usa il predefinito", non "togli il link": per toglierlo davvero
    // serve dirlo, con GUIDE_URL = off.
    $url = env('GUIDE_URL', GUIDE_URL);
    if (in_array(strtolower($url), ['', 'off', 'no', 'none'], true)) return $body;
    return $body . "\n\n--\nCosa significa questa mail e cosa fare: " . $url;
}


/* ---- Quadro di entrambi gli impianti ---------------------------------- */

/**
 * Chi riceve un allarme vuole sapere cosa sta succedendo, non solo cosa e'
 * scattato: un inverter fermo si legge diversamente se la batteria e' carica
 * o se e' a terra. Ogni notifica porta quindi la misura di tutti e due.
 *
 * Il dato dell'altro impianto si legge dal suo file di stato, mai chiamando la
 * sua API: i due watchdog restano indipendenti, e se quello la' e' fermo il
 * blocco lo dichiara invece di inventare.
 */
function etaLeggibile(int $secondi): string
{
    $min = (int) round(max(0, $secondi) / 60);
    if ($min < 1)  return 'adesso';
    if ($min < 60) return "$min min fa";
    $ore = intdiv($min, 60);
    $res = $min % 60;
    return $res > 0 ? "{$ore}h{$res}m fa" : "{$ore}h fa";
}

function bloccoImpianto(string $etichetta, array $stato, int $now, int $vecchiaOltreMin = 120): string
{
    $riep = trim((string) ($stato['riepilogo'] ?? ''));
    if ($riep === '') return "$etichetta: nessuna misura disponibile.";

    $ts    = (int) ($stato['riepilogo_ts'] ?? 0);
    $quando = $ts > 0 ? etaLeggibile($now - $ts) : 'data ignota';
    $riga   = sprintf('%s [%s] - %s', $etichetta,
        strtoupper((string) ($stato['status'] ?? '?')), $quando);

    // Una misura vecchia non va spacciata per la situazione di adesso.
    if ($ts > 0 && ($now - $ts) > $vecchiaOltreMin * 60) {
        $riga .= ' - ATTENZIONE: misura vecchia, puo' . "'" . ' non essere la situazione attuale';
    }
    return $riga . "\n  " . $riep;
}

function leggiStato(string $file): array
{
    if (!is_file($file)) return [];
    $j = json_decode((string) file_get_contents($file), true);
    return is_array($j) ? $j : [];
}

function quadroImpianti(array $mio, array $altro, int $now): string
{
    return "-- Situazione rilevata --\n"
        . bloccoImpianto(ETICHETTA_MIA, $mio, $now) . "\n"
        . bloccoImpianto(ETICHETTA_ALTRA, $altro, $now);
}

function notify(string $tag, string $body): void {
    global $TG_BOT_TOKEN, $TG_CHAT_ID, $WEBHOOK_URL;

    global $MISURA;
    $now  = time();
    $mio  = $MISURA ?: leggiStato(STATE_FILE);
    $body = $body . "\n\n" . quadroImpianti($mio, leggiStato(STATE_ALTRO), $now);
    $body = conGuida($body);

    // GitHub Actions: espone la notifica allo step "Send email" del workflow.
    // Lo script decide QUANDO notificare; l'invio SMTP lo fa il workflow.
    $ghOut = getenv('GITHUB_OUTPUT');
    if ($ghOut !== false && $ghOut !== '') {
        $d = '__ZCSEOF__';
        $out = "notify=1\n"
             . "subject=[FV ZCS] $tag\n"
             . "body<<$d\n" . $body . "\n$d\n";
        @file_put_contents($ghOut, $out, FILE_APPEND);
    }

    if ($TG_BOT_TOKEN !== '' && $TG_CHAT_ID !== '') {
        $url = "https://api.telegram.org/bot{$TG_BOT_TOKEN}/sendMessage";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => http_build_query([
                'chat_id' => $TG_CHAT_ID,
                'text'    => "[FV ZCS] $tag\n$body",
            ]),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    if ($WEBHOOK_URL !== '') {
        $ch = curl_init($WEBHOOK_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(['tag' => $tag, 'text' => $body]),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
