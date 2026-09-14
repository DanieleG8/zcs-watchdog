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
$UNREACH_PERSIST_MIN = (int) env('TESLA_UNREACH_PERSIST_MIN', '30');
$SOC_MIN_PERCENT     = (float) env('TESLA_SOC_MIN_PERCENT', '0'); // 0 = controllo disattivato
$RENOTIFY_HOURS      = (int) env('RENOTIFY_HOURS', '6');

// Notifiche (stessi canali del watchdog fotovoltaico)
$TG_BOT_TOKEN = env('TG_BOT_TOKEN', '');
$TG_CHAT_ID   = env('TG_CHAT_ID', '');
$WEBHOOK_URL  = env('WEBHOOK_URL', '');

const STATE_FILE = __DIR__ . '/state-tesla.json';

if (!defined('TESLA_WATCHDOG_TEST')) {
    exit(main($argv ?? []));
}


/* ================================ Main ================================ */

function main(array $argv): int
{
    global $CLIENT_ID, $REFRESH_TOKEN, $SITE_ID, $REGION;
    global $STALE_LIMIT_MIN, $OFFGRID_PERSIST_MIN, $UNREACH_PERSIST_MIN, $SOC_MIN_PERCENT, $RENOTIFY_HOURS;

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
    list($access, $err) = getAccessToken(false);
    if ($access === null) {
        // Un token rifiutato non e' un blip di rete: niente attesa, si avvisa subito.
        $isAuth = (bool) preg_match('/invalid_grant|invalid_client|unauthorized|HTTP 40[013]/i', $err);
        return handleCondition($isAuth ? 'auth' : 'unreachable', "Rinnovo token fallito: $err", null);
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
            return handleCondition('auth', "Rinnovo token fallito: $aerr", null);
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
        return handleCondition('unreachable', $ok ? 'Risposta senza campo response.' : $err, null);
    }

    // 3. Valutazione
    list($condition, $detail) = evaluateCondition($live, time(), [
        'stale_limit_min'  => $STALE_LIMIT_MIN,
        'soc_min_percent'  => $SOC_MIN_PERCENT,
    ]);
    return handleCondition($condition, $detail, $live);
}

/**
 * Traduce il live_status in una condizione. Separata dal resto per poterla
 * provare con risposte finte (vedi tests/tesla_test.php).
 */
function evaluateCondition(array $live, int $now, array $cfg): array
{
    $ts     = parseTimestamp($live['timestamp'] ?? null);
    $ageMin = $ts ? ($now - $ts) / 60 : PHP_INT_MAX;
    $soc    = isset($live['percentage_charged']) ? (float) $live['percentage_charged'] : null;
    $island = (string) ($live['island_status'] ?? '');
    $grid   = (string) ($live['grid_status'] ?? '');

    $riepilogo = sprintf(
        'Carica %s, batteria %s W, casa %s W, rete %s W, solare %s W.',
        $soc === null ? 'n/d' : round($soc) . '%',
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
        return ['offgrid', sprintf('Sistema in isola (island_status: %s, grid_status: %s).%s %s',
            $island !== '' ? $island : 'n/d', $grid !== '' ? $grid : 'n/d', $extra, $riepilogo)];
    }

    if ($cfg['soc_min_percent'] > 0 && $soc !== null && $soc < $cfg['soc_min_percent']) {
        return ['soc', sprintf('Carica %.0f%% sotto la soglia %.0f%%. %s',
            $soc, $cfg['soc_min_percent'], $riepilogo)];
    }

    return ['ok', $riepilogo];
}

/**
 * Macchina a stati + anti-spam: identica nello spirito a watchdog.php.
 */
function handleCondition(string $condition, string $detail, ?array $live): int
{
    global $OFFGRID_PERSIST_MIN, $UNREACH_PERSIST_MIN, $RENOTIFY_HOURS;

    $state = loadState();
    $now   = time();
    $prev  = $state['status'] ?? 'ok';
    $hb    = date('Y-m-d');

    $persistMin = [
        'stale'       => 0,   // il timeout e' gia' dentro TESLA_STALE_LIMIT_MIN
        'offgrid'     => $OFFGRID_PERSIST_MIN,
        'soc'         => 0,
        'auth'        => 0,   // token non valido: nessuna attesa, il monitoraggio e' gia' fermo
        'unreachable' => $UNREACH_PERSIST_MIN,
        'ok'          => 0,
    ];

    if ($condition === 'ok') {
        if ($prev !== 'ok') {
            notify('RIENTRO', "Powerwall tornato normale.\n$detail");
            logline("RIENTRO da '$prev'. $detail");
        } else {
            logline("OK. $detail");
        }
        saveState(['status' => 'ok', 'since' => $now, 'last_notified' => 0, 'last_ok' => $now, 'hb' => $hb]);
        return 0;
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
        notify($titles[$condition], $body);
        $lastNotified = $now;
        logline("NOTIFICATO '$condition'. $detail");
    } else {
        logline(sprintf("ALLARME '%s' attivo (gia' notificato). %s", $condition, $detail));
    }

    saveState(['status' => $condition, 'since' => $since, 'last_notified' => $lastNotified,
               'last_ok' => $state['last_ok'] ?? 0, 'hb' => $hb]);
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
    echo "copia quel valore e lancialo con la modalita' 'exchange' (il code dura pochi minuti).\n";
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

function cmdExchange(string $codeParam): int
{
    global $CLIENT_ID, $CLIENT_SECRET, $REDIRECT_URI;
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

    if ($resp === false)             return [false, 0, null, "cURL: $cerr"];
    $data = json_decode((string) $resp, true);
    if ($code < 200 || $code >= 300) return [false, $code, $data, "HTTP $code. Body: " . substr((string) $resp, 0, 300)];
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
    file_put_contents(STATE_FILE, json_encode($s, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
}

function logline(string $msg): void
{
    echo date('Y-m-d H:i:s') . "  $msg\n";
}

function notify(string $tag, string $body): void
{
    global $TG_BOT_TOKEN, $TG_CHAT_ID, $WEBHOOK_URL;

    // Come nel watchdog fotovoltaico: lo script decide QUANDO notificare,
    // l'invio SMTP lo fa lo step successivo del workflow.
    $ghOut = getenv('GITHUB_OUTPUT');
    if ($ghOut !== false && $ghOut !== '') {
        $d = '__TESLAEOF__';
        $out = "notify=1\n"
             . "subject=[Powerwall] $tag\n"
             . "body<<$d\n" . $body . "\n$d\n";
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
