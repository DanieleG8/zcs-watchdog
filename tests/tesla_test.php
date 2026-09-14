<?php
/**
 * Prove della logica di tesla.php che non tocca la rete.
 * Si lancia con:  php tests/tesla_test.php
 *
 * TESLA_WATCHDOG_TEST impedisce a tesla.php di eseguire main() al require.
 */

define('TESLA_WATCHDOG_TEST', true);
require __DIR__ . '/../tesla.php';

$fails = 0;

function check(string $titolo, $atteso, $ottenuto): void
{
    global $fails;
    if ($atteso === $ottenuto) {
        echo "  ok   $titolo\n";
        return;
    }
    $fails++;
    echo "  FAIL $titolo\n";
    echo "       atteso:   " . var_export($atteso, true) . "\n";
    echo "       ottenuto: " . var_export($ottenuto, true) . "\n";
}

$now = 1789369200; // 2026-09-14 09:00 Europe/Rome
$cfg = ['stale_limit_min' => 60, 'soc_min_percent' => 0];

function live(array $over = [], int $now = 1789369200): array
{
    return $over + [
        'timestamp'          => date('c', $now - 120),
        'island_status'      => 'on_grid',
        'grid_status'        => 'Active',
        'percentage_charged' => 62.5,
        'battery_power'      => -1500,
        'load_power'         => 800,
        'grid_power'         => 0,
        'solar_power'        => 2300,
    ];
}

echo "evaluateCondition\n";

list($c, $d) = evaluateCondition(live(), $now, $cfg);
check('sistema normale -> ok', 'ok', $c);
check('riepilogo leggibile', 'Carica 63%, batteria -1500 W, casa 800 W, rete 0 W, solare 2300 W.', $d);

list($c, ) = evaluateCondition(live(['timestamp' => date('c', $now - 3 * 3600)]), $now, $cfg);
check('telemetria di 3 ore fa -> stale', 'stale', $c);

list($c, ) = evaluateCondition(live(['timestamp' => date('c', $now - 59 * 60)]), $now, $cfg);
check('telemetria a 59 min (sotto soglia 60) -> ok', 'ok', $c);

list($c, ) = evaluateCondition(live(['timestamp' => null]), $now, $cfg);
check('timestamp assente -> stale', 'stale', $c);

list($c, $d) = evaluateCondition(live(['island_status' => 'off_grid_unintentional', 'grid_status' => 'Islanded']), $now, $cfg);
check('isola non voluta -> offgrid', 'offgrid', $c);
check('il messaggio riporta island_status', true, str_contains($d, 'off_grid_unintentional'));

list($c, ) = evaluateCondition(live(['island_status' => 'off_grid_intentional']), $now, $cfg);
check('isola volontaria -> comunque offgrid', 'offgrid', $c);

list($c, $d) = evaluateCondition(live(['island_status' => 'off_grid', 'storm_mode_active' => true]), $now, $cfg);
check('storm mode segnalato nel messaggio', true, str_contains($d, 'Storm Mode'));

list($c, ) = evaluateCondition(live(['island_status' => '', 'grid_status' => 'Islanded']), $now, $cfg);
check('senza island_status si usa grid_status', 'offgrid', $c);

list($c, ) = evaluateCondition(live(['island_status' => '', 'grid_status' => 'Active']), $now, $cfg);
check('grid_status Active senza island_status -> ok', 'ok', $c);

list($c, ) = evaluateCondition(live(['percentage_charged' => 3.0]), $now, ['stale_limit_min' => 60, 'soc_min_percent' => 5]);
check('carica 3% sotto soglia 5% -> soc', 'soc', $c);

list($c, ) = evaluateCondition(live(['percentage_charged' => 3.0]), $now, $cfg);
check('controllo carica disattivato (soglia 0) -> ok', 'ok', $c);

list($c, ) = evaluateCondition(
    live(['percentage_charged' => 3.0, 'island_status' => 'off_grid']),
    $now,
    ['stale_limit_min' => 60, 'soc_min_percent' => 5]
);
check('isola ha la precedenza sulla carica bassa', 'offgrid', $c);

echo "\nparseTimestamp\n";
check('ISO 8601 con offset', 1789371480, parseTimestamp('2026-09-14T09:38:00+02:00'));
check('ISO 8601 UTC', 1789371480, parseTimestamp('2026-09-14T07:38:00Z'));
check('epoch in millisecondi', 1789371480, parseTimestamp('1789371480000'));
check('stringa vuota', null, parseTimestamp(''));

echo "\nnotify -> GITHUB_OUTPUT\n";
$tmp = tempnam(sys_get_temp_dir(), 'ghout');
putenv("GITHUB_OUTPUT=$tmp");
notify('POWERWALL IN ISOLA (rete assente)', "riga uno\nriga due");
$out = (string) file_get_contents($tmp);
unlink($tmp);
putenv('GITHUB_OUTPUT');
check('notify=1 presente', true, str_contains($out, "notify=1\n"));
check('subject con prefisso', true, str_contains($out, 'subject=[Powerwall] POWERWALL IN ISOLA (rete assente)'));
check('body multiriga con delimitatore', true, str_contains($out, "body<<__TESLAEOF__\nriga uno\nriga due\n__TESLAEOF__"));

echo "\ncache dell'access token\n";
$cache = tempnam(sys_get_temp_dir(), 'tokc');
putenv("TESLA_TOKEN_CACHE=$cache");
unlink($cache);
check('cache assente', null, cachedAccessToken());
storeAccessToken(['access_token' => 'abc123', 'expires_in' => 28800]);
check('token valido riletto', 'abc123', cachedAccessToken());
check('permessi 0600', '0600', substr(sprintf('%o', fileperms($cache)), -4));
storeAccessToken(['access_token' => 'abc123', 'expires_in' => 60]);
check('token in scadenza scartato (margine 120 s)', null, cachedAccessToken());
storeAccessToken(['access_token' => '', 'expires_in' => 28800]);
check('token vuoto scartato', null, cachedAccessToken());
unlink($cache);
putenv('TESLA_TOKEN_CACHE');

echo "\ngithubSetSecret senza PAT\n";
putenv('GH_SECRETS_TOKEN');
list($ok, $err) = githubSetSecret('TESLA_REFRESH_TOKEN', 'valore');
check('fallisce in modo pulito', false, $ok);
check('spiega cosa manca', 'GH_SECRETS_TOKEN non impostato', $err);

echo "\nestraiCode — il code si puo' incollare come URL intero\n";

$atteso = 'EU_u1ta0xfsfztdjlrxwg1wf9v237xzmaxd7miy2mxcf9zj2avg';
check('solo il code -> invariato', $atteso, estraiCode($atteso));
check('spazi intorno -> ripuliti', $atteso, estraiCode("  $atteso\n"));
check(
    'URL intero della redirect -> estrae il code',
    $atteso,
    estraiCode("https://www.pn-ta.it/teslapath?code=$atteso&issuer=https%3A%2F%2Fauth.tesla.com%2Foauth2%2Fv3&state=5bd8e1e9")
);
check(
    'code non in prima posizione -> lo trova lo stesso',
    $atteso,
    estraiCode("https://www.pn-ta.it/teslapath?state=abc&code=$atteso")
);
check('sola query string -> funziona', $atteso, estraiCode("?code=$atteso&state=abc"));
check('URL di errore senza code -> vuoto (non si tenta lo scambio)', '', estraiCode('https://www.pn-ta.it/teslapath?error=access_denied'));
check('stringa vuota -> vuoto', '', estraiCode('   '));

echo "\n" . ($fails === 0 ? "Tutte le prove sono passate.\n" : "$fails prove fallite.\n");
exit($fails === 0 ? 0 : 1);
