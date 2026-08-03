<?php
/**
 * ZCS Azzurro - Watchdog produzione fotovoltaico (versione GitHub Actions)
 * -----------------------------------------------------------------------
 * Interroga l'API realtime dell'inverter ZCS e avvisa se:
 *   1) STALE   -> l'inverter non trasmette piu' dati (timestamp vecchio)   [24h]
 *   2) ZERO    -> di giorno la potenza generata resta a zero               [solo alba-tramonto]
 *   3) UNREACH -> l'API non risponde (rete / endpoint / auth)              [warning monitoraggio]
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
$LAT = (float) env('PLANT_LAT', '44.0637');
$LON = (float) env('PLANT_LON', '12.4460');

// Soglie
$ZERO_W_THRESHOLD    = (int) env('ZERO_W_THRESHOLD', '50');
$ZERO_PERSIST_MIN    = (int) env('ZERO_PERSIST_MIN', '90');
$STALE_LIMIT_MIN     = (int) env('STALE_LIMIT_MIN', '45');
$UNREACH_PERSIST_MIN = (int) env('UNREACH_PERSIST_MIN', '30');
$DAY_MARGIN_MIN      = (int) env('DAY_MARGIN_MIN', '40');
$RENOTIFY_HOURS      = (int) env('RENOTIFY_HOURS', '6');
$LASTUPDATE_IS_UTC   = envBool('LASTUPDATE_IS_UTC', false);

// Notifiche
$TG_BOT_TOKEN = env('TG_BOT_TOKEN', '');
$TG_CHAT_ID   = env('TG_CHAT_ID', '');
$WEBHOOK_URL  = env('WEBHOOK_URL', ''); // opzionale: riceve un POST JSON {tag,text}

const STATE_FILE = __DIR__ . '/state.json';

/* ----------------------------- Runtime -------------------------------- */

$argvv  = $argv ?? [];
$isTest = in_array('--test', $argvv, true);
$isDump = in_array('--dump', $argvv, true);

if ($isTest) {
    notify('TEST', "Notifica di prova dal watchdog ZCS. Canali OK.");
    logline('Inviata notifica di test.');
    exit(0);
}

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
if (!$ok) {
    $condition = 'unreachable';
    $detail    = $err;
} else {
    $node = extractNode($data, $THING_KEY);
    if ($node === null) {
        $condition = 'unreachable';
        $detail    = 'Risposta senza dati validi (verifica auth/thingKey). Raw: ' . substr(json_encode($data), 0, 400);
    } else {
        $powerW = (float) ($node['powerGenerating'] ?? 0);
        $lastTs = parseLastUpdate($node['lastUpdate'] ?? null, $LASTUPDATE_IS_UTC);
        $ageMin = $lastTs ? ($now - $lastTs) / 60 : PHP_INT_MAX;

        if ($ageMin > $STALE_LIMIT_MIN) {
            $condition = 'stale';
            $detail    = sprintf('Ultimo dato %s (%.0f min fa).', $lastTs ? date('Y-m-d H:i:s', $lastTs) : 'n/d', $ageMin);
        } elseif (isDaytime($now, $LAT, $LON, $DAY_MARGIN_MIN) && $powerW <= $ZERO_W_THRESHOLD) {
            $condition = 'zero';
            $detail    = sprintf('Potenza %.0f W di giorno (soglia %d W). Ultimo dato %s.',
                          $powerW, $ZERO_W_THRESHOLD, $lastTs ? date('H:i', $lastTs) : 'n/d');
        } else {
            $condition = 'ok';
            $detail    = sprintf('Potenza %.0f W. Ultimo dato %s.', $powerW, $lastTs ? date('H:i', $lastTs) : 'n/d');
        }
    }
}

/* 2. Stato + anti-spam */
$persistMin = [
    'zero'        => $ZERO_PERSIST_MIN,
    'stale'       => $STALE_LIMIT_MIN,
    'unreachable' => $UNREACH_PERSIST_MIN,
    'ok'          => 0,
];
$prev = $state['status'] ?? 'ok';
$hb   = date('Y-m-d'); // heartbeat: cambia una volta al giorno -> tiene "attivo" il repo

if ($condition === 'ok') {
    $wasNotified = ($state['last_notified'] ?? 0) > 0;
    if ($prev !== 'ok' && $wasNotified) {
        // Il testo del rientro dipende dal tipo di anomalia rientrata: un
        // ripristino API/inverter non e' "tornato a produrre".
        $rientroMsg = [
            'zero'        => 'Impianto tornato a produrre.',
            'stale'       => 'Inverter di nuovo online: dati aggiornati.',
            'unreachable' => 'Monitoraggio ripristinato: API di nuovo raggiungibile.',
        ];
        $msg = $rientroMsg[$prev] ?? 'Situazione tornata alla normalita\'.';
        notify('RIENTRO', "$msg\n$detail");
        logline("RIENTRO da '$prev'. $detail");
    } elseif ($prev !== 'ok') {
        // Anomalia rientrata durante il periodo di grazia: nessun allarme era
        // stato inviato, quindi niente RIENTRO (solo log).
        logline("Anomalia transitoria '$prev' rientrata senza allarme. $detail");
    } else {
        logline("OK. $detail");
    }
    saveState(['status' => 'ok', 'since' => $now, 'last_notified' => 0, 'last_ok' => $now, 'hb' => $hb]);
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
               'last_ok' => $state['last_ok'] ?? 0, 'hb' => $hb]);
    exit(0);
}

$shouldNotify = ($lastNotified === 0) || (($now - $lastNotified) >= $RENOTIFY_HOURS * 3600);
if ($shouldNotify) {
    $titles = [
        'zero'        => 'NESSUNA PRODUZIONE',
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
           'last_ok' => $state['last_ok'] ?? 0, 'hb' => $hb]);
exit(0);


/* ============================== Funzioni ============================== */

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
    file_put_contents(STATE_FILE, json_encode($s, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
}
function logline(string $msg): void {
    echo date('Y-m-d H:i:s') . "  $msg\n"; // finisce nel log del job Actions
}

function notify(string $tag, string $body): void {
    global $TG_BOT_TOKEN, $TG_CHAT_ID, $WEBHOOK_URL;

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
