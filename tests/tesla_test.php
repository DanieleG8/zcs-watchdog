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

// IL CASO PANTA, CORRETTO IL 15/09. Per un giorno questo blocco ha preteso che
// un off_grid dichiarato fosse confermato da grid_power, e quando grid_power
// diceva "migliaia di watt dalla rete" l'allarme veniva zittito. Era sbagliato:
// su questo impianto grid_power RIPETE load_power al decimale in ogni campione
// (e' un residuo calcolato, non una misura), e battery_power dichiara 0 mentre
// la carica scende dal 16.3% al 10.6% in ventidue ore. Un campo che non misura
// niente non puo' smentire niente - e intanto l'app Tesla diceva "alimentazione
// dalla rete interrotta". Un watchdog che tace su dati contraddittori e' il
// guasto peggiore che questo repo possa avere.
list($c, $d) = evaluateCondition(
    live(['island_status' => 'off_grid_unintentional', 'grid_status' => 'Inactive',
          'grid_power' => 23725.98, 'load_power' => 23725.98, 'solar_power' => 0,
          'battery_power' => 0, 'percentage_charged' => 10.6]),
    $now, $cfg
);
check('IL CASO PANTA: off_grid con misure incoerenti -> ALLARME, non silenzio', 'offgrid', $c);
check('  smaschera la rete che ripete la casa', true,
    str_contains($d, 'il valore della rete ripete esattamente quello della casa'));
check('  e i watt che non arrivano da nessuna parte', true,
    str_contains($d, 'non li fornisce nessuno'));
check('  dicendo di non fidarsi di quei numeri', true,
    str_contains($d, 'non usarle per dedurre lo stato della rete'));

// Un impianto con misure sensate non deve portarsi dietro l'avvertenza.
list($c, $d) = evaluateCondition(
    live(['island_status' => 'off_grid_unintentional', 'grid_power' => 0,
          'load_power' => 3000, 'battery_power' => 3000, 'solar_power' => 0]),
    $now, $cfg
);
check('off_grid coerente (la batteria alimenta la casa) -> offgrid', 'offgrid', $c);
check('  senza avvertenze sui flussi', false, str_contains($d, 'non sono coerenti'));

// La rete che eroga davvero: valori diversi fra loro, nessuna contraddizione.
list($c, $d) = evaluateCondition(
    live(['island_status' => 'on_grid', 'grid_power' => 2000,
          'load_power' => 3000, 'battery_power' => 1000, 'solar_power' => 0]),
    $now, $cfg
);
check('impianto normale collegato alla rete -> ok', 'ok', $c);

// STALE continua ad avere la precedenza su tutto.
list($c, ) = evaluateCondition(
    live(['island_status' => 'off_grid', 'timestamp' => date('c', $now - 3 * 3600)]),
    $now, $cfg
);
check('off_grid ma dato di 3 ore fa -> stale (prevale)', 'stale', $c);

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
check('body multiriga con delimitatore', true, str_contains($out, "body<<__TESLAEOF__\nriga uno\nriga due\n"));
check('  il testo passato non viene alterato', true, str_contains($out, "riga uno\nriga due"));
// Chi riceve la mail non e' chi ha configurato il sistema: il link alla guida
// deve esserci sempre, non solo negli allarmi importanti.
check('  in fondo c\'e' . " il link alla guida", true, str_contains($out, GUIDE_URL));
check('  il delimitatore chiude comunque il blocco', true, str_contains($out, "\n__TESLAEOF__\n"));

// Svuotare GUIDE_URL toglie il link, senza rompere la notifica.
$tmp2 = tempnam(sys_get_temp_dir(), 'ghout');
putenv("GITHUB_OUTPUT=$tmp2");
putenv('GUIDE_URL=off');
notify('TEST', 'corpo asciutto');
$out2 = (string) file_get_contents($tmp2);
unlink($tmp2);
putenv('GITHUB_OUTPUT');
putenv('GUIDE_URL');
check('GUIDE_URL=off toglie il link', false, str_contains($out2, GUIDE_URL));
check('  ma la notifica parte lo stesso', true, str_contains($out2, 'corpo asciutto'));

// Una Variable non impostata arriva come stringa vuota: deve valere il default,
// altrimenti il link sparirebbe da solo su un repo appena configurato.
$tmp3 = tempnam(sys_get_temp_dir(), 'ghout');
putenv("GITHUB_OUTPUT=$tmp3");
putenv('GUIDE_URL=');
notify('TEST', 'corpo');
$out3 = (string) file_get_contents($tmp3);
unlink($tmp3);
putenv('GITHUB_OUTPUT');
putenv('GUIDE_URL');
check('GUIDE_URL vuota -> vale il predefinito', true, str_contains($out3, GUIDE_URL));

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

// Valore inventato: un code vero, anche gia' consumato, non si commenta
// in chiaro su un repo pubblico.
$atteso = 'EU_codedidprovanonvaleniente0000000000000000000000';
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

echo "\ninterpretaRisposta — una 2xx senza corpo e' un successo\n";

// La PUT che scrive un secret su GitHub risponde 204 a corpo vuoto: se la
// trattiamo come errore, il refresh token appena ottenuto e' perso.
list($ok, , , $err) = interpretaRisposta('', 204, '');
check('204 a corpo vuoto -> ok', true, $ok);
check('  e nessun errore', '', $err);
list($ok, , $dati) = interpretaRisposta('', 201, '');
check('201 a corpo vuoto (secret creato) -> ok', true, $ok);
check('  con dati vuoti, non null', [], $dati);
list($ok) = interpretaRisposta("\n", 204, '');
check('204 con solo spaziatura -> ok', true, $ok);

list($ok, $codice, $dati) = interpretaRisposta('{"key_id":"abc"}', 200, '');
check('200 con JSON -> ok, dati decodificati', 'abc', $dati['key_id']);
check('  e il codice HTTP torna indietro', 200, $codice);

list($ok, , , $err) = interpretaRisposta('<html>manutenzione</html>', 200, '');
check('200 con corpo non JSON -> errore', false, $ok);
check('  lo dice', true, str_contains($err, 'Non JSON'));

list($ok, , , $err) = interpretaRisposta('{"error":"invalid_auth_code"}', 400, '');
check('400 -> errore anche se il corpo e\' JSON valido', false, $ok);
check('  e riporta il corpo, che spiega il perche\'', true, str_contains($err, 'invalid_auth_code'));

list($ok, $codice, , $err) = interpretaRisposta(false, 0, 'timeout');
check('cURL fallito -> errore', false, $ok);
check('  senza codice HTTP da mostrare', 0, $codice);
check('  con il messaggio di cURL', true, str_contains($err, 'timeout'));

echo "\nquadro di entrambi gli impianti in ogni notifica\n";

check('meno di un minuto', 'adesso', etaLeggibile(20));
check('minuti', '7 min fa', etaLeggibile(7 * 60));
check('ore tonde', '3h fa', etaLeggibile(3 * 3600));
check('ore e minuti', '2h12m fa', etaLeggibile(2 * 3600 + 12 * 60));
check('tempo negativo (orologi sfasati) -> adesso', 'adesso', etaLeggibile(-500));

$adesso = 1789449943;
$vivo = ['status' => 'ok', 'riepilogo' => 'Carica 12%, casa 5020 W.', 'riepilogo_ts' => $adesso - 300];

$b = bloccoImpianto('Batteria Tesla', $vivo, $adesso);
check('il blocco riporta etichetta, stato e freschezza', true,
    str_contains($b, 'Batteria Tesla [OK] - 5 min fa'));
check('  e la misura vera e propria', true, str_contains($b, 'Carica 12%, casa 5020 W.'));
check('  senza avvisi se il dato e fresco', false, str_contains($b, 'ATTENZIONE'));

// Un watchdog fermo da ore non deve far credere che quella sia la situazione.
$vecchio = ['status' => 'ok', 'riepilogo' => 'Tutto bene.', 'riepilogo_ts' => $adesso - 5 * 3600];
$b = bloccoImpianto('Batteria Tesla', $vecchio, $adesso);
check('misura di 5 ore fa -> avvisa che e vecchia', true, str_contains($b, 'ATTENZIONE: misura vecchia'));
check('  e dice quanto', true, str_contains($b, '5h fa'));

// L'altro watchdog non e' mai partito: si dichiara, non si inventa.
check('stato assente -> lo dice', 'Fotovoltaico: nessuna misura disponibile.',
    bloccoImpianto('Fotovoltaico', [], $adesso));
check('stato senza riepilogo -> idem', 'Fotovoltaico: nessuna misura disponibile.',
    bloccoImpianto('Fotovoltaico', ['status' => 'ok'], $adesso));

$q = quadroImpianti(
    ['status' => 'zero', 'riepilogo' => 'Solo 0.00 kWh in 65 min.', 'riepilogo_ts' => $adesso],
    $vivo, $adesso
);
check('il quadro nomina tutti e due gli impianti', true,
    str_contains($q, ETICHETTA_MIA) && str_contains($q, ETICHETTA_ALTRA));
check('  con l intestazione', true, str_contains($q, 'Situazione rilevata'));

check('leggiStato su file inesistente -> array vuoto', [], leggiStato('/tmp/non-esiste-davvero.json'));

echo "\n" . ($fails === 0 ? "Tutte le prove sono passate.\n" : "$fails prove fallite.\n");
exit($fails === 0 ? 0 : 1);
