<?php
/**
 * Tesla Powerwall - Watchdog batteria di accumulo (Fleet API ufficiale)
 * ---------------------------------------------------------------------
 * Interroga l'endpoint live_status del sito energia e avvisa se:
 *   1) STALE    -> il Gateway non manda piu' telemetria (timestamp vecchio)
 *   2) OFFGRID  -> il sistema e' andato in isola (blackout o guasto rete)
 *   3) SOC      -> stato di carica sotto la soglia minima impostata
 *   4) UNREACH  -> Fleet API non risponde / token non valido
 *
 * Gemello di watchdog.php (fotovoltaico ZCS): stessa logica di stato,
 * anti-spam e notifica. Lo stato vive in state-tesla.json.
 *
 * ATTENZIONE AL REFRESH TOKEN: Tesla lo ruota a ogni rinnovo (e' monouso,
 * il precedente resta valido solo ~24h) e scade dopo 3 mesi di non utilizzo.
 * Va quindi risalvato dopo ogni refresh: lo script riscrive da solo il
 * secret TESLA_REFRESH_TOKEN del repo tramite le API di GitHub (serve un PAT
 * in GH_SECRETS_TOKEN). Senza quello il monitoraggio si ferma entro 24 ore.
 *
 * Modalita' (vedi ISTRUZIONI-TESLA.md):
 *   php tesla.php --authurl        URL da aprire per autorizzare l'app
 *   php tesla.php --register       registrazione partner account (una tantum)
 *   php tesla.php --exchange CODE  scambia il code della redirect in token
 *   php tesla.php --sites          elenca i siti energia (per TESLA_SITE_ID)
 *   php tesla.php --dump           stampa il live_status grezzo
 *   php tesla.php --test           invia una notifica di prova
 *   php tesla.php                  controllo normale
 */

date_default_timezone_set(env('TZ', 'Europe/Rome'));

/* ------------------------- Config da ambiente ------------------------- */

// Credenziali app Tesla (developer.tesla.com)
$CLIENT_ID     = env('TESLA_CLIENT_ID', '');
$CLIENT_SECRET = env('TESLA_CLIENT_SECRET', '');       // serve solo a --register e --exchange
$REFRESH_TOKEN = env('TESLA_REFRESH_TOKEN', '');
$REDIRECT_URI  = env('TESLA_REDIRECT_URI', '');
$SITE_ID       = env('TESLA_SITE_ID', '');
$REGION        = strtolower(env('TESLA_REGION', 'eu')); // eu | na | cn

const TOKEN_URL = 'https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token';
const AUTH_URL  = 'https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/authorize';
const SCOPES    = 'openid offline_access energy_device_data';
const REGIONS   = [
    'na' => 'https://fleet-api.prd.na.vn.cloud.tesla.com',
    'eu' => 'https://fleet-api.prd.eu.vn.cloud.tesla.com',
    'cn' => 'https://fleet-api.prd.cn.vn.cloud.tesla.cn',
];
const HTTP_TIMEOUT = 30;

// Soglie
$STALE_LIMIT_MIN     = (int) env('TESLA_STALE_LIMIT_MIN', '60');
$OFFGRID_PERSIST_MIN = (int) env('TESLA_OFFGRID_PERSIST_MIN', '15');
// 90 e non 30: la notte del 16/09 il gateway di Tesla ha alternato 504, 424 e
// 503 per mezz'ora buona, e alle 03:12 e' partita una mail per un disservizio
// del loro cloud. Sotto l'ora e mezza non si distingue un guasto da un
// singhiozzo, e una mail notturna che non chiede niente a nessuno insegna
// soltanto a non aprire le mail.
$UNREACH_PERSIST_MIN = (int) env('TESLA_UNREACH_PERSIST_MIN', '90');
$SOC_MIN_PERCENT     = (float) env('TESLA_SOC_MIN_PERCENT', '0'); // 0 = controllo disattivato

// Stessa posizione usata dal watchdog fotovoltaico: serve a sapere se il sole
// c'e'. Una batteria che si scarica di notte non e' un guasto, di giorno si'.
$LAT            = (float) env('PLANT_LAT', '45.0');
$LON            = (float) env('PLANT_LON', '9.0');
$DAY_MARGIN_MIN = (int) env('DAY_MARGIN_MIN', '40');
$RENOTIFY_HOURS      = (int) env('RENOTIFY_HOURS', '4');

// Notifiche (stessi canali del watchdog fotovoltaico)
$TG_BOT_TOKEN = env('TG_BOT_TOKEN', '');
$TG_CHAT_ID   = env('TG_CHAT_ID', '');
$WEBHOOK_URL  = env('WEBHOOK_URL', '');

// Guida alle mail per chi le riceve (vedi RIFERIMENTO.md).
const GUIDE_URL = 'https://claude.ai/code/artifact/19aa137e-428a-4742-89c8-e0df7f06aaa9';

const STATE_FILE   = __DIR__ . '/state-tesla.json';
const STATE_ALTRO  = __DIR__ . '/state.json';
/**
 * Non vedere non e' un verdetto sull'impianto.
 *
 * CIECHE sono le condizioni in cui il watchdog non ha misurato niente: l'API
 * non risponde, il token non vale piu'. Dicono qualcosa di noi, non della
 * batteria. IMPIANTO sono i verdetti veri, quelli letti su un dato.
 *
 * La differenza conta perche' una cecita' passeggera non deve cancellare un
 * allarme in corso. Il 16/09 il cloud di Tesla si e' spento per mezz'ora nel
 * mezzo di un'isola iniziata il giorno prima alle 14:40: al ritorno della
 * telemetria l'isola e' ripartita da zero, e la mail successiva l'avrebbe
 * annunciata come "in corso da 0 min" invece che da quindici ore.
 */
const CIECHE   = ['unreachable', 'auth'];
const IMPIANTO = ['offgrid', 'soc', 'stale'];

const ETICHETTA_MIA   = 'Batteria Tesla';
const ETICHETTA_ALTRA = 'Fotovoltaico';

// Misura del giro corrente: finisce nello stato e in fondo a ogni notifica.
$MISURA = [];

if (!defined('TESLA_WATCHDOG_TEST')) {
    exit(main($argv ?? []));
}


/* ================================ Main ================================ */

function main(array $argv): int
{
    global $CLIENT_ID, $REFRESH_TOKEN, $SITE_ID, $REGION;
    global $STALE_LIMIT_MIN, $OFFGRID_PERSIST_MIN, $UNREACH_PERSIST_MIN, $SOC_MIN_PERCENT, $RENOTIFY_HOURS;
    global $LAT, $LON, $DAY_MARGIN_MIN;

    $flag = $argv[1] ?? '';

    if ($flag === '--authurl')  return cmdAuthUrl();
    if ($flag === '--register') return cmdRegister();
    if ($flag === '--exchange') return cmdExchange($argv[2] ?? '');
    if ($flag === '--test') {
        notify('TEST', "Notifica di prova dal watchdog Powerwall. Canali OK.");
        logline('Inviata notifica di test.');
        return 0;
    }

    if ($CLIENT_ID === '' || $REFRESH_TOKEN === '') {
        fwrite(STDERR, "Mancano TESLA_CLIENT_ID e/o TESLA_REFRESH_TOKEN.\n");
        return 1;
    }
    if (!array_key_exists($REGION, REGIONS)) {
        fwrite(STDERR, "TESLA_REGION '$REGION' non valida (usa eu, na o cn).\n");
        return 1;
    }
    $base = REGIONS[$REGION];

    // 1. Access token: riusa quello in cache finche' e' valido, cosi' il loop
    //    del workflow non rinnova (e non fa ruotare) il refresh token ogni giro.
    // Le modalita' di diagnostica servono a collaudare la configurazione: se
    // qualcosa non va devono stamparlo, non far partire mail di allarme.
    $diagnostica = in_array($flag, ['--sites', '--dump'], true);

    list($access, $err) = getAccessToken(false);
    if ($access === null) {
        if ($diagnostica) {
            fwrite(STDERR, "Rinnovo token fallito: $err\n");
            return 1;
        }
        // Un token rifiutato non e' un blip di rete: niente attesa, si avvisa subito.
        $isAuth = (bool) preg_match('/invalid_grant|invalid_client|unauthorized|HTTP 40[013]/i', $err);
        return handleCondition($isAuth ? 'auth' : 'unreachable',
            'Rinnovo del collegamento a Tesla fallito. ' . spiegaErrore($err), null);
    }

    $path = $flag === '--sites' ? '/api/1/products' : "/api/1/energy_sites/$SITE_ID/live_status";
    if ($flag !== '--sites' && $SITE_ID === '') {
        fwrite(STDERR, "Manca TESLA_SITE_ID (ricavalo con la modalita' 'sites').\n");
        return 1;
    }

    // 2. Chiamata all'API. Se il token in cache e' stato revocato, uno e un solo
    //    tentativo con un token nuovo prima di dichiarare guasto il monitoraggio.
    list($ok, $code, $data, $err) = apiGet($base, $access, $path);
    if (!$ok && in_array($code, [401, 403], true)) {
        logline('Access token rifiutato: ne chiedo uno nuovo e riprovo.');
        list($access, $aerr) = getAccessToken(true);
        if ($access === null) {
            if ($diagnostica) { fwrite(STDERR, "Rinnovo token fallito: $aerr\n"); return 1; }
            return handleCondition('auth',
                'Rinnovo del collegamento a Tesla fallito. ' . spiegaErrore($aerr), null);
        }
        list($ok, $code, $data, $err) = apiGet($base, $access, $path);
    }

    if ($flag === '--sites') {
        echo $ok ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
                 : "Errore: $err\n";
        return $ok ? 0 : 1;
    }

    $live = is_array($data['response'] ?? null) ? $data['response'] : null;

    if ($flag === '--dump') {
        echo 'HTTP ok: ' . ($ok ? 'si' : 'no') . "\n";
        if (!$ok) { echo "Errore: $err\n"; return 1; }
        echo json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $ts = parseTimestamp($live['timestamp'] ?? null);
        echo 'timestamp: ' . ($live['timestamp'] ?? 'assente')
           . ' -> ' . ($ts ? date('Y-m-d H:i:s', $ts) . ' (' . round((time() - $ts) / 60, 1) . ' min fa)' : 'non parsato') . "\n";
        return 0;
    }

    if (!$ok || $live === null) {
        if ($diagnostica) { fwrite(STDERR, "Errore: " . ($ok ? 'risposta senza campo response.' : $err) . "\n"); return 1; }
        return handleCondition('unreachable',
            $ok ? 'Tesla ha risposto, ma senza i dati del Powerwall.' : spiegaErrore($err), null);
    }

    // 3. Valutazione
    $adesso = time();
    list($condition, $detail) = evaluateCondition($live, $adesso, [
        'stale_limit_min'  => $STALE_LIMIT_MIN,
        'soc_min_percent'  => $SOC_MIN_PERCENT,
        'e_giorno'         => isDaytime($adesso, $LAT, $LON, $DAY_MARGIN_MIN),
    ]);
    return handleCondition($condition, $detail, $live);
}

/**
 * Dice se i flussi di potenza si contraddicono, e in che modo.
 *
 * Su questo impianto non tornano: grid_power ripete load_power al decimale in
 * ogni campione (e' un residuo calcolato, non una misura indipendente), e
 * battery_power dichiara 0 mentre la carica scende di ora in ora. Con batteria
 * e solare a zero, i chilowatt attribuiti alla casa non li fornisce nessuno.
 *
 * Non si puo' dedurre da qui se la rete ci sia o no. Si puo' pero' DIRLO a chi
 * legge la mail, invece di far finta che i numeri vogliano dire qualcosa.
 */
function notaFlussiIncoerenti(array $live): string
{
    $carico  = (float) ($live['load_power'] ?? 0);
    $batt    = (float) ($live['battery_power'] ?? 0);
    $solare  = (float) ($live['solar_power'] ?? 0);
    $rete    = $live['grid_power'] ?? null;

    $note = [];
    if ($rete !== null && (float) $rete === $carico && $carico != 0.0) {
        $note[] = 'il valore della rete ripete esattamente quello della casa';
    }
    if ($carico > 0 && abs($batt) < 1 && abs($solare) < 1) {
        $note[] = 'batteria e solare a zero mentre la casa risulta assorbire: '
                . 'quei watt non li fornisce nessuno';
    }
    if (!$note) return '';

    return ' ATTENZIONE, le misure di flusso non sono coerenti (' . implode('; ', $note)
         . '): non usarle per dedurre lo stato della rete, guarda il contatore o l\'app Tesla.';
}

/**
 * Traduce il live_status in una condizione. Separata dal resto per poterla
 * provare con risposte finte (vedi tests/tesla_test.php).
 */
/**
 * Una percentuale scritta senza mentire sull'arrotondamento.
 *
 * Con round() la mail del 16/09 diceva "Carica 20% sotto la soglia 20%", che
 * letta cosi' non sta in piedi: la carica vera era 19,6%. Un avviso che sembra
 * sbagliato viene trattato come sbagliato, anche quando ha ragione.
 */
function fmtPerc(?float $v): string
{
    if ($v === null) return 'n/d';
    return rtrim(rtrim(sprintf('%.1f', $v), '0'), '.') . '%';
}

/** Copia di quella in watchdog.php: i due script restano indipendenti. */
function isDaytime(int $now, float $lat, float $lon, int $marginMin): bool
{
    $info = date_sun_info($now, $lat, $lon);
    $rise = $info['sunrise'] ?? null;
    $set  = $info['sunset']  ?? null;
    if (!$rise || !$set) return true;   // nel dubbio si giudica
    $m = $marginMin * 60;
    return ($now >= $rise + $m) && ($now <= $set - $m);
}

function evaluateCondition(array $live, int $now, array $cfg): array
{
    $ts     = parseTimestamp($live['timestamp'] ?? null);
    $ageMin = $ts ? ($now - $ts) / 60 : PHP_INT_MAX;
    $soc    = isset($live['percentage_charged']) ? (float) $live['percentage_charged'] : null;
    $island = (string) ($live['island_status'] ?? '');
    $grid   = (string) ($live['grid_status'] ?? '');

    $riepilogo = sprintf(
        'Carica %s, batteria %s W, casa %s W, rete %s W, solare %s W.',
        fmtPerc($soc),
        fmtW($live['battery_power'] ?? null),
        fmtW($live['load_power'] ?? null),
        fmtW($live['grid_power'] ?? null),
        fmtW($live['solar_power'] ?? null)
    );

    if ($ageMin > $cfg['stale_limit_min']) {
        return ['stale', sprintf('Ultima telemetria %s (%.0f min fa). %s',
            $ts ? date('Y-m-d H:i:s', $ts) : 'n/d', $ageMin, $riepilogo)];
    }

    // island_status e' il campo affidabile; grid_status come ripiego.
    $offgrid = $island !== ''
        ? !str_starts_with($island, 'on_grid')
        : in_array($grid, ['Islanded', 'Inactive'], true);

    if ($offgrid) {
        $extra = !empty($live['storm_mode_active']) ? ' Storm Mode attivo.' : '';
        return ['offgrid', sprintf('Sistema in isola (island_status: %s, grid_status: %s).%s %s%s',
            $island !== '' ? $island : 'n/d', $grid !== '' ? $grid : 'n/d', $extra, $riepilogo,
            notaFlussiIncoerenti($live))];
    }

    if ($cfg['soc_min_percent'] > 0 && $soc !== null && $soc < $cfg['soc_min_percent']) {
        // DI NOTTE LA BATTERIA SI SCARICA: E' IL SUO MESTIERE.
        // Senza sole non si ricarica, e la casa assorbe piu' di quanto lei
        // contenga: arrivare al mattino in riserva e' il ciclo previsto, non
        // un guasto. Va detto che qui siamo per forza CON LA RETE PRESENTE,
        // perche' l'isola e' valutata prima e ha la precedenza: la casa non
        // resta senza niente, la batteria vuota se la copre la rete.
        // Di giorno lo stesso valore e' un'altra cosa - c'e' il sole e la
        // carica non sale - e li' l'avviso parte.
        if (!($cfg['e_giorno'] ?? true)) {
            return ['notte', sprintf('Notte: carica %s sotto la soglia %s, ma senza sole e\' il '
                . 'ciclo normale e la rete c\'e\'. Si rivaluta di giorno. %s',
                fmtPerc($soc), fmtPerc((float) $cfg['soc_min_percent']), $riepilogo)];
        }
        return ['soc', sprintf('Carica %s sotto la soglia %s, con il sole gia\' alto: la batteria '
            . 'non si sta ricaricando. %s',
            fmtPerc($soc), fmtPerc((float) $cfg['soc_min_percent']), $riepilogo)];
    }

    return ['ok', $riepilogo];
}

/**
 * Un errore HTTP raccontato a chi la mail la riceve.
 *
 * Prima qui finiva il corpo grezzo della risposta, tagliato a 300 caratteri:
 *
 *   HTTP 503. Body: {"response":null,"error":"https://powergate...:443/api/v4/
 *   energy_site/live_status =\u003e \u003chtml\u003e\r\n\u003chead\u003e...503 Service
 *
 * Tagliato a meta' parola, con l'HTML dentro il JSON e le escape non risolte.
 * Chi riceve queste mail dell'impianto sa dove sta il quadro elettrico: da
 * quella riga non ricava niente, e una mail che non si capisce vale zero anche
 * quando ha ragione. Il codice resta scritto, perche' a chi mette le mani nel
 * sistema serve, ma dopo la frase e su una riga sola.
 */
function spiegaErrore(string $err): string
{
    if ($err === '') return '';

    $codice = preg_match('/^HTTP (\d{3})/', $err, $m) ? (int) $m[1] : 0;

    if (str_starts_with($err, 'cURL:')) {
        return "Non si riesce nemmeno a raggiungere i server di Tesla (la connessione non parte). "
             . 'Dettaglio: ' . rigaTecnica($err) . '.';
    }

    $frasi = [
        500 => 'I server di Tesla hanno un problema interno',
        502 => 'I server di Tesla non rispondono',
        503 => 'I server di Tesla non rispondono',
        504 => 'I server di Tesla rispondono troppo lentamente e la richiesta scade',
        424 => 'Tesla risponde, ma non riesce a leggere il Powerwall',
        429 => 'Tesla sta limitando le richieste: ne sono state fatte troppe',
    ];

    if (isset($frasi[$codice])) {
        return $frasi[$codice] . " (errore $codice). Non e' un guasto dell'impianto: quasi sempre "
             . 'rientra da solo. Dettaglio: ' . rigaTecnica($err) . '.';
    }
    if ($codice === 401 || $codice === 403) {
        return "Tesla rifiuta l'autorizzazione (errore $codice). Questo non rientra da solo: va "
             . 'rifatto il collegamento all\'account Tesla. Dettaglio: ' . rigaTecnica($err) . '.';
    }
    if ($codice >= 400) {
        return "Tesla ha risposto con un errore $codice. Dettaglio: " . rigaTecnica($err) . '.';
    }
    return rigaTecnica($err);
}

/**
 * Il dettaglio tecnico ridotto a una riga leggibile: niente HTML, niente
 * escape unicode, niente a capo, e un taglio su una parola intera.
 */
function rigaTecnica(string $err, int $max = 140): string
{
    // Il messaggio di Tesla sta dentro il JSON. Il corpo arriva gia' tagliato a
    // 300 caratteri, quindi la stringa puo' non avere la virgoletta di
    // chiusura: si prende comunque quello che c'e', altrimenti si ricadrebbe
    // sul corpo grezzo, che e' esattamente cio' che si vuole evitare.
    if (preg_match('/"error"\s*:\s*"((?:[^"\\\\]|\\\\.)*)("|$)/', $err, $m)) {
        $err = $m[1];
    }
    // \u003e e compagnia: le escape JSON non risolte finivano nella mail.
    $err = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/',
        fn($u) => mb_chr(hexdec($u[1]), 'UTF-8') ?: '', $err) ?? $err;
    $err = stripcslashes($err);
    // Tesla antepone l'indirizzo del proprio servizio interno al messaggio:
    // "https://powergate.../live_status => 503 Service...". Conta cio' che
    // viene dopo la freccia.
    if (($i = strpos($err, '=>')) !== false) $err = substr($err, $i + 2);

    $t = html_entity_decode(strip_tags($err), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    $t = rtrim($t, " .\t\"");
    if ($t === '') return 'nessun dettaglio leggibile';
    if (mb_strlen($t) <= $max) return $t;

    $tagliato = mb_substr($t, 0, $max);
    $spazio   = mb_strrpos($tagliato, ' ');
    // Meglio una parola in meno che una parola a meta'.
    return rtrim($spazio > $max / 2 ? mb_substr($tagliato, 0, $spazio) : $tagliato, " ,;:") . '...';
}

/**
 * Un "monitoraggio cieco" annunciato da solo lascia credere che sotto non ci
 * fosse niente. Se invece la telemetria si e' spenta mentre un allarme era in
 * corso, la mail deve dirlo: l'ultima cosa vista resta la cosa piu' probabile,
 * e chi legge deve sapere che non e' finita, solo che non si vede piu'.
 */
function notaAllarmeSotto(string $condition, array $memoria): string
{
    if (!in_array($condition, CIECHE, true) || !isset($memoria['imp_status'])) return '';
    $nomi = [
        'offgrid' => 'sistema in isola, senza rete',
        'soc'     => 'batteria quasi scarica',
        'stale'   => 'Powerwall senza telemetria',
    ];
    $che = $nomi[$memoria['imp_status']] ?? $memoria['imp_status'];
    return "\n\nATTENZIONE: quando la telemetria si e' spenta era in corso un allarme ("
         . $che . ', dalle ' . date('Y-m-d H:i', (int) $memoria['imp_since'])
         . "). Non e' rientrato: semplicemente non si vede piu'.";
}

/**
 * Le chiavi imp_* cosi' come stanno, se ci sono.
 *
 * saveState() riscrive il file per intero: un ramo che dimentica queste chiavi
 * cancella la memoria di un allarme d'impianto messo da parte durante una
 * cecita'. Il ramo notturno passa di qui appunto per non farlo.
 */
function memoriaConservata(array $state): array
{
    $fuori = [];
    foreach (['imp_status', 'imp_since', 'imp_last_notified'] as $k) {
        if (isset($state[$k])) $fuori[$k] = $state[$k];
    }
    return $fuori;
}

/**
 * Da cosa si rientra, e se di quel qualcosa era stata mandata una mail.
 *
 * Guardare solo 'last_notified' non basta: se l'ultima condizione era cieca,
 * la notifica che conta e' quella dell'allarme d'impianto rimasto sotto. Senza
 * questo, un'isola notificata ieri e coperta stanotte da un buco di telemetria
 * finirebbe senza che nessuno sappia che e' finita.
 *
 * Restituisce [da_cosa, gia_notificato].
 */
function rientroDa(array $state): array
{
    $prev = $state['status'] ?? 'ok';
    $sotto = in_array($prev, CIECHE, true) ? ($state['imp_status'] ?? null) : null;
    $notificato = ($state['last_notified'] ?? 0) > 0
               || ($sotto !== null && ($state['imp_last_notified'] ?? 0) > 0);
    return [$sotto ?? $prev, $notificato];
}

/**
 * Quando comincia l'allarme corrente e quando era stato notificato l'ultima
 * volta, tenendo conto che una cecita' passeggera non lo azzera.
 *
 * Tre casi:
 *  - stessa condizione di prima: continua, ovvio;
 *  - condizione cieca: l'allarme d'impianto che correva viene messo da parte
 *    in imp_* e ritrovato dopo, per quanto duri il buco;
 *  - telemetria tornata su un impianto che sta ancora come l'avevamo lasciato:
 *    l'allarme riprende con la sua data vera, non riparte da adesso.
 *
 * Restituisce ['since', 'last_notified', 'memoria', 'ripreso'].
 */
function continuitaAllarme(array $state, string $condition, int $now): array
{
    $prev  = $state['status'] ?? 'ok';
    $sotto = in_array($prev, CIECHE, true) ? ($state['imp_status'] ?? null) : null;

    $memoria = [];
    if (in_array($condition, CIECHE, true)) {
        if (in_array($prev, IMPIANTO, true)) {
            $memoria = [
                'imp_status'        => $prev,
                'imp_since'         => (int) ($state['since'] ?? $now),
                'imp_last_notified' => (int) ($state['last_notified'] ?? 0),
            ];
        } elseif ($sotto !== null) {
            $memoria = [
                'imp_status'        => $sotto,
                'imp_since'         => (int) ($state['imp_since'] ?? $now),
                'imp_last_notified' => (int) ($state['imp_last_notified'] ?? 0),
            ];
        }
    }

    if ($prev === $condition) {
        return ['since' => (int) ($state['since'] ?? $now),
                'last_notified' => (int) ($state['last_notified'] ?? 0),
                'memoria' => $memoria, 'ripreso' => false];
    }
    if ($sotto === $condition) {
        return ['since' => (int) ($state['imp_since'] ?? $now),
                'last_notified' => (int) ($state['imp_last_notified'] ?? 0),
                'memoria' => $memoria, 'ripreso' => true];
    }
    return ['since' => $now, 'last_notified' => 0, 'memoria' => $memoria, 'ripreso' => false];
}

/**
 * Macchina a stati + anti-spam: identica nello spirito a watchdog.php.
 */
function handleCondition(string $condition, string $detail, ?array $live): int
{
    global $OFFGRID_PERSIST_MIN, $UNREACH_PERSIST_MIN, $RENOTIFY_HOURS, $MISURA;

    $state = loadState();
    $now   = time();
    $MISURA = ['status' => $condition, 'riepilogo' => $detail, 'riepilogo_ts' => $now];
    $prev  = $state['status'] ?? 'ok';
    $hb    = date('Y-m-d');

    // NOTTE: nessun verdetto, quindi nessuna mail e nessun rientro.
    // Lo stato di prima resta com'e'. Se un allarme SOC era gia' aperto di
    // giorno non si chiude al tramonto - sarebbe un "tornato normale" falso,
    // lo stesso errore gia' fatto sul fotovoltaico - e non ne parte uno nuovo
    // per una scarica che ci si aspetta. All'alba si torna a giudicare.
    if ($condition === 'notte') {
        $prevNotte = $prev;
        logline("NOTTE (stato '$prevNotte' conservato). $detail");
        saveState([
            'status'        => $prevNotte,
            'since'         => $state['since'] ?? $now,
            'last_notified' => $state['last_notified'] ?? 0,
            'last_ok'       => $state['last_ok'] ?? 0,
            'hb'            => $hb,
        ] + memoriaConservata($state));
        return 0;
    }

    $persistMin = [
        'stale'       => 0,   // il timeout e' gia' dentro TESLA_STALE_LIMIT_MIN
        'offgrid'     => $OFFGRID_PERSIST_MIN,
        'soc'         => 0,
        'auth'        => 0,   // token non valido: nessuna attesa, il monitoraggio e' gia' fermo
        'unreachable' => $UNREACH_PERSIST_MIN,
        'ok'          => 0,
    ];

    if ($condition === 'ok') {
        // Come nel watchdog fotovoltaico: niente "tutto risolto" per un allarme
        // che non e' mai stato comunicato. Ma se l'ultima cosa vista era una
        // cecita', l'allarme comunicato e' quello d'impianto che stava sotto:
        // guardare solo 'last_notified' farebbe finire un'isola in silenzio.
        list($daCosa, $notificato) = rientroDa($state);
        if ($prev !== 'ok' && $notificato) {
            notify('RIENTRO', "Powerwall tornato normale.\n$detail");
            logline("RIENTRO da '$daCosa'. $detail");
        } elseif ($prev !== 'ok') {
            logline("Rientro da '$daCosa' senza notifica: l'allarme non era mai stato inviato. $detail");
        } else {
            logline("OK. $detail");
        }
        saveState(['status' => 'ok', 'since' => $now, 'last_notified' => 0, 'last_ok' => $now, 'hb' => $hb]);
        return 0;
    }

    // Quello che si porta dietro una condizione cieca: l'allarme d'impianto che
    // stava correndo, con la sua data di inizio e le sue notifiche gia' fatte.
    $c            = continuitaAllarme($state, $condition, $now);
    $since        = $c['since'];
    $lastNotified = $c['last_notified'];
    $memoria      = $c['memoria'];
    if ($c['ripreso']) {
        logline("Telemetria tornata: '$condition' prosegue da " . date('Y-m-d H:i', $since) . '.');
    }

    $elapsedMin = ($now - $since) / 60;
    if ($elapsedMin < $persistMin[$condition]) {
        logline(sprintf("PENDING '%s' %.0f/%d min. %s", $condition, $elapsedMin, $persistMin[$condition], $detail));
        saveState(['status' => $condition, 'since' => $since, 'last_notified' => $lastNotified,
                   'last_ok' => $state['last_ok'] ?? 0, 'hb' => $hb] + $memoria);
        return 0;
    }

    $shouldNotify = ($lastNotified === 0) || (($now - $lastNotified) >= $RENOTIFY_HOURS * 3600);
    if ($shouldNotify) {
        $titles = [
            'stale'       => 'POWERWALL SENZA TELEMETRIA',
            'offgrid'     => 'POWERWALL IN ISOLA (rete assente)',
            'soc'         => 'POWERWALL QUASI SCARICO',
            'auth'        => 'TOKEN TESLA NON VALIDO (monitoraggio fermo)',
            'unreachable' => 'MONITORAGGIO CIECO (Fleet API non raggiungibile)',
        ];
        $body = $titles[$condition] . "\n\n$detail\n\nAnomalia in corso dalle "
              . date('Y-m-d H:i', $since) . sprintf(' (%.0f min).', $elapsedMin);
        $body .= notaAllarmeSotto($condition, $memoria);
        notify($titles[$condition], $body);
        $lastNotified = $now;
        logline("NOTIFICATO '$condition'. $detail");
    } else {
        logline(sprintf("ALLARME '%s' attivo (gia' notificato). %s", $condition, $detail));
    }

    saveState(['status' => $condition, 'since' => $since, 'last_notified' => $lastNotified,
               'last_ok' => $state['last_ok'] ?? 0, 'hb' => $hb] + $memoria);
    return 0;
}


/* ========================= Comandi una tantum ========================= */

function cmdAuthUrl(): int
{
    global $CLIENT_ID, $REDIRECT_URI;
    if ($CLIENT_ID === '' || $REDIRECT_URI === '') {
        fwrite(STDERR, "Servono TESLA_CLIENT_ID e TESLA_REDIRECT_URI.\n");
        return 1;
    }
    $state = bin2hex(random_bytes(8));
    $url = AUTH_URL . '?' . http_build_query([
        'response_type' => 'code',
        'client_id'     => $CLIENT_ID,
        'redirect_uri'  => $REDIRECT_URI,
        'scope'         => SCOPES,
        'state'         => $state,
        'prompt'        => 'login',
    ]);
    echo "Apri questo indirizzo nel browser, accedi con l'account Tesla e autorizza:\n\n$url\n\n";
    echo "Verrai rimandato al redirect URI con ?code=... nella barra degli indirizzi:\n";
    echo "incolla l'indirizzo intero (o il solo code) nella modalita' 'exchange'.\n";
    echo "ATTENZIONE: il code scade in pochi minuti, fallo subito.\n";
    return 0;
}

function cmdRegister(): int
{
    global $CLIENT_ID, $CLIENT_SECRET, $REGION;
    if ($CLIENT_ID === '' || $CLIENT_SECRET === '') {
        fwrite(STDERR, "Servono TESLA_CLIENT_ID e TESLA_CLIENT_SECRET.\n");
        return 1;
    }
    if (!array_key_exists($REGION, REGIONS)) {
        fwrite(STDERR, "TESLA_REGION '$REGION' non valida.\n");
        return 1;
    }
    $base   = REGIONS[$REGION];
    $domain = env('TESLA_PUBLIC_KEY_DOMAIN', '');
    if ($domain === '') {
        fwrite(STDERR, "Manca TESLA_PUBLIC_KEY_DOMAIN (il dominio dove hai pubblicato la chiave pubblica).\n");
        return 1;
    }

    list($ok, $code, $data, $err) = httpJson('POST', TOKEN_URL, [], [
        'grant_type'    => 'client_credentials',
        'client_id'     => $CLIENT_ID,
        'client_secret' => $CLIENT_SECRET,
        'scope'         => SCOPES,
        'audience'      => $base,
    ], true);
    if (!$ok) { fwrite(STDERR, "Partner token fallito: $err\n"); return 1; }

    $partner = $data['access_token'] ?? '';
    list($ok, $code, $data, $err) = httpJson('POST', $base . '/api/1/partner_accounts',
        ['Authorization: Bearer ' . $partner], ['domain' => $domain]);
    if (!$ok) { fwrite(STDERR, "Registrazione partner fallita: $err\n"); return 1; }

    echo "Registrazione completata per il dominio $domain.\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    return 0;
}

/**
 * Accetta sia il solo code sia l'URL intero della redirect: il code dura pochi
 * minuti, e far ritagliare a mano il parametro e' il modo piu' facile per
 * arrivare tardi (o per portarsi dietro un '&issuer=...' di troppo).
 */
function estraiCode(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    if (!str_contains($raw, '=')) return $raw;      // e' gia' il solo code

    $query = parse_url($raw, PHP_URL_QUERY);
    if ($query === null || $query === false) $query = ltrim($raw, '?');
    parse_str((string) $query, $par);
    return isset($par['code']) ? trim((string) $par['code']) : '';
}

function cmdExchange(string $codeParam): int
{
    global $CLIENT_ID, $CLIENT_SECRET, $REDIRECT_URI;
    $codeParam = estraiCode($codeParam);
    if ($codeParam === '') { fwrite(STDERR, "Manca il code da scambiare.\n"); return 1; }
    if ($CLIENT_ID === '' || $CLIENT_SECRET === '' || $REDIRECT_URI === '') {
        fwrite(STDERR, "Servono TESLA_CLIENT_ID, TESLA_CLIENT_SECRET e TESLA_REDIRECT_URI.\n");
        return 1;
    }

    list($ok, $code, $data, $err) = httpJson('POST', TOKEN_URL, [], [
        'grant_type'    => 'authorization_code',
        'client_id'     => $CLIENT_ID,
        'client_secret' => $CLIENT_SECRET,
        'code'          => $codeParam,
        'redirect_uri'  => $REDIRECT_URI,
    ], true);
    if (!$ok) { fwrite(STDERR, "Scambio fallito: $err\n"); return 1; }

    $refresh = (string) ($data['refresh_token'] ?? '');
    if ($refresh === '') { fwrite(STDERR, "Risposta senza refresh_token (hai chiesto lo scope offline_access?).\n"); return 1; }

    list($saved, $serr) = githubSetSecret('TESLA_REFRESH_TOKEN', $refresh);
    if ($saved) {
        echo "Refresh token ottenuto e salvato nel secret TESLA_REFRESH_TOKEN.\n";
        return 0;
    }
    // Mai stampare il token nel log di un repo pubblico.
    fwrite(STDERR, "Refresh token ottenuto ma NON salvato: $serr\n");
    fwrite(STDERR, "Configura GH_SECRETS_TOKEN e ripeti, oppure rifai l'autorizzazione.\n");
    return 1;
}


/* ============================ Fleet API =============================== */

/**
 * Ritorna [accessToken|null, errore]. Con $force salta la cache.
 * La cache sta nella temp del runner: sparisce a fine job, non finisce nel repo.
 */
function getAccessToken(bool $force): array
{
    global $CLIENT_ID, $REFRESH_TOKEN;

    if (!$force) {
        $cached = cachedAccessToken();
        if ($cached !== null) return [$cached, ''];
    }

    list($ok, $tokens, $err) = refreshAccessToken($CLIENT_ID, $REFRESH_TOKEN);
    if (!$ok) return [null, $err];

    persistRefreshToken($REFRESH_TOKEN, $tokens['refresh_token'] ?? '');
    storeAccessToken($tokens);
    return [(string) $tokens['access_token'], ''];
}

function tokenCachePath(): string
{
    return env('TESLA_TOKEN_CACHE', sys_get_temp_dir() . '/tesla-access.json');
}

function cachedAccessToken(): ?string
{
    $f = tokenCachePath();
    if (!is_file($f)) return null;
    $j = json_decode((string) file_get_contents($f), true);
    if (!is_array($j)) return null;
    // 120 s di margine per non usare un token che scade a meta' chiamata.
    if (((int) ($j['expires_at'] ?? 0)) - 120 <= time()) return null;
    $t = (string) ($j['access_token'] ?? '');
    return $t !== '' ? $t : null;
}

function storeAccessToken(array $tokens): void
{
    $f = tokenCachePath();
    @file_put_contents($f, json_encode([
        'access_token' => (string) ($tokens['access_token'] ?? ''),
        'expires_at'   => time() + (int) ($tokens['expires_in'] ?? 28800),
    ]), LOCK_EX);
    @chmod($f, 0600);
}

function refreshAccessToken(string $clientId, string $refreshToken): array
{
    list($ok, $code, $data, $err) = httpJson('POST', TOKEN_URL, [], [
        'grant_type'    => 'refresh_token',
        'client_id'     => $clientId,
        'refresh_token' => $refreshToken,
    ], true);
    if (!$ok) return [false, null, $err];
    if (empty($data['access_token'])) return [false, null, 'Risposta senza access_token.'];
    return [true, $data, ''];
}

/**
 * Tesla ruota il refresh token a ogni rinnovo: se non risalviamo quello nuovo,
 * entro ~24h il vecchio smette di funzionare e il watchdog diventa cieco.
 */
function persistRefreshToken(string $old, string $new): void
{
    if ($new === '' || $new === $old) return;

    list($ok, $err) = githubSetSecret('TESLA_REFRESH_TOKEN', $new);
    if ($ok) {
        logline('Refresh token ruotato e risalvato nel secret.');
        return;
    }
    logline("ATTENZIONE: refresh token ruotato ma non salvato ($err).");
    notify('TOKEN TESLA DA RINNOVARE',
        "Tesla ha ruotato il refresh token ma non sono riuscito a salvarlo nel secret del repo:\n$err\n\n"
      . "Il vecchio token resta valido per poche ore: senza intervento il monitoraggio della batteria si ferma.");
}

function apiGet(string $base, string $access, string $path): array
{
    return httpJson('GET', $base . $path, ['Authorization: Bearer ' . $access]);
}


/* ======================= Secret su GitHub (PAT) ======================= */

/**
 * Scrive un Actions secret del repo. Il valore va cifrato con la chiave
 * pubblica del repo (libsodium sealed box), come da API GitHub.
 */
function githubSetSecret(string $name, string $value): array
{
    $pat  = env('GH_SECRETS_TOKEN', '');
    $repo = env('GITHUB_REPOSITORY', '');
    if ($pat === '')  return [false, 'GH_SECRETS_TOKEN non impostato'];
    if ($repo === '') return [false, 'GITHUB_REPOSITORY non impostato'];
    if (!function_exists('sodium_crypto_box_seal')) return [false, 'estensione sodium non disponibile'];

    $headers = [
        'Authorization: Bearer ' . $pat,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: zcs-watchdog',
    ];

    list($ok, $code, $data, $err) = httpJson('GET', "https://api.github.com/repos/$repo/actions/secrets/public-key", $headers);
    if (!$ok) return [false, "public-key: $err"];

    $key   = base64_decode((string) ($data['key'] ?? ''), true);
    $keyId = (string) ($data['key_id'] ?? '');
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES || $keyId === '') {
        return [false, 'chiave pubblica del repo non valida'];
    }

    $sealed = base64_encode(sodium_crypto_box_seal($value, $key));
    list($ok, $code, $data, $err) = httpJson('PUT', "https://api.github.com/repos/$repo/actions/secrets/$name",
        $headers, ['encrypted_value' => $sealed, 'key_id' => $keyId]);
    return $ok ? [true, ''] : [false, "PUT secret: $err"];
}


/* ============================== Utility =============================== */

function env(string $k, string $default = ''): string {
    $v = getenv($k);
    return ($v === false || $v === '') ? $default : $v;
}

/**
 * Ritorna [ok, httpCode, dataDecodificato, errore].
 * $form = true -> corpo application/x-www-form-urlencoded (endpoint OAuth).
 */
function httpJson(string $method, string $url, array $headers = [], ?array $body = null, bool $form = false): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_HTTPHEADER     => array_merge($headers, ['Accept: application/json']),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $form ? http_build_query($body) : json_encode($body);
        $opts[CURLOPT_HTTPHEADER][] = $form
            ? 'Content-Type: application/x-www-form-urlencoded'
            : 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);

    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    return interpretaRisposta($resp, $code, $cerr);
}

/**
 * Legge l'esito di una risposta HTTP. Vive separata da httpJson perche' e' la
 * parte che si puo' provare senza rete (vedi tests/tesla_test.php).
 */
function interpretaRisposta($resp, int $code, string $cerr): array
{
    if ($resp === false)             return [false, 0, null, "cURL: $cerr"];
    $data = json_decode((string) $resp, true);
    if ($code < 200 || $code >= 300) return [false, $code, $data, "HTTP $code. Body: " . substr((string) $resp, 0, 300)];
    // Una risposta 2xx puo' legittimamente non avere corpo: la PUT che scrive un
    // secret su GitHub risponde 201 o 204 a corpo vuoto. Pretendere del JSON qui
    // significava buttare via un refresh token appena ottenuto.
    if (trim((string) $resp) === '') return [true, $code, [], ''];
    if (!is_array($data))            return [false, $code, null, 'Non JSON: ' . substr((string) $resp, 0, 300)];
    return [true, $code, $data, ''];
}

function parseTimestamp($raw): ?int
{
    if ($raw === null || $raw === '') return null;
    if (is_numeric($raw)) {
        $n = (int) $raw;
        if ($n > 1000000000000) $n = intdiv($n, 1000);
        return $n;
    }
    $dt = date_create((string) $raw);
    return $dt ? $dt->getTimestamp() : (strtotime((string) $raw) ?: null);
}

function fmtW($v): string
{
    return $v === null ? 'n/d' : (string) round((float) $v);
}

function loadState(): array
{
    if (!is_file(STATE_FILE)) return [];
    $j = json_decode((string) file_get_contents(STATE_FILE), true);
    return is_array($j) ? $j : [];
}

function saveState(array $s): void
{
    global $MISURA;
    // La misura viaggia nello stato cosi' l'altro watchdog puo' citarla nelle
    // sue mail senza interrogare questa API.
    if (isset($MISURA['riepilogo'])) {
        $s['riepilogo']    = $MISURA['riepilogo'];
        $s['riepilogo_ts'] = $MISURA['riepilogo_ts'];
    }
    file_put_contents(STATE_FILE, json_encode($s, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
}

function logline(string $msg): void
{
    echo date('Y-m-d H:i:s') . "  $msg\n";
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

function notify(string $tag, string $body): void
{
    global $TG_BOT_TOKEN, $TG_CHAT_ID, $WEBHOOK_URL;

    global $MISURA;
    $now  = time();
    $mio  = $MISURA ?: leggiStato(STATE_FILE);
    $body = $body . "\n\n" . quadroImpianti($mio, leggiStato(STATE_ALTRO), $now);
    $body = conGuida($body);

    // Come nel watchdog fotovoltaico: lo script decide QUANDO notificare,
    // l'invio SMTP lo fa lo step successivo del workflow.
    $ghOut = getenv('GITHUB_OUTPUT');
    if ($ghOut !== false && $ghOut !== '') {
        // Prefisso distinto: girando nello stesso job dell'altro watchdog, due
        // chiavi 'notify' nello stesso GITHUB_OUTPUT si sovrascriverebbero e
        // una delle due mail non partirebbe.
        $d = '__TESLAEOF__';
        $out = "tesla_notify=1\n"
             . "tesla_subject=[Powerwall] $tag\n"
             . "tesla_body<<$d\n" . $body . "\n$d\n";
        @file_put_contents($ghOut, $out, FILE_APPEND);
    }

    if ($TG_BOT_TOKEN !== '' && $TG_CHAT_ID !== '') {
        $ch = curl_init("https://api.telegram.org/bot{$TG_BOT_TOKEN}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => http_build_query([
                'chat_id' => $TG_CHAT_ID,
                'text'    => "[Powerwall] $tag\n$body",
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
