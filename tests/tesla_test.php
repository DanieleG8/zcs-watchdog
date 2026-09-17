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
$cfg = ['stale_limit_min' => 60, 'soc_min_percent' => 0, 'e_giorno' => true];

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
check('riepilogo leggibile', 'Carica 62.5%, batteria -1500 W, casa 800 W, rete 0 W, solare 2300 W.', $d);

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

list($c, ) = evaluateCondition(live(['percentage_charged' => 3.0]), $now, ['stale_limit_min' => 60, 'soc_min_percent' => 5, 'e_giorno' => true]);
check('carica 3% sotto soglia 5% -> soc', 'soc', $c);

list($c, ) = evaluateCondition(live(['percentage_charged' => 3.0]), $now, $cfg);
check('controllo carica disattivato (soglia 0) -> ok', 'ok', $c);

list($c, ) = evaluateCondition(
    live(['percentage_charged' => 3.0, 'island_status' => 'off_grid']),
    $now,
    ['stale_limit_min' => 60, 'soc_min_percent' => 5, 'e_giorno' => true]
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


/* ==========================================================================
 * LA NOTTE DEL 16/09: il cloud di Tesla si spegne in mezzo a un'isola
 *
 * Dati veri, dal log del run 915. Alle 00:42 UTC il gateway di Tesla comincia
 * a rispondere 504, poi 424, poi 503; va avanti mezz'ora e alle 03:12 ora
 * locale parte una mail "MONITORAGGIO CIECO". Alle 05:20 la telemetria torna,
 * e l'isola c'e' ancora: era iniziata il 15/09 alle 14:40.
 *
 * Due cose sono andate storte. La mail per mezz'ora di disservizio altrui, di
 * notte, e' rumore. E soprattutto: al ritorno l'isola e' ripartita da zero,
 * perche' 'since' e 'last_notified' erano stati sovrascritti dalla cecita'.
 * La mail successiva avrebbe annunciato come nuova un'anomalia di quindici ore.
 *
 * Non vedere non e' un verdetto: mentre non si vede, l'ultima cosa vista resta.
 * ========================================================================== */
echo "\nLa notte del 16/09: la cecita' non cancella l'isola\n";

$isolaDa = 1789480800;          // 15/09 14:40 (finto, ma della forma giusta)
$notificataAlle = 1789498983;   // 15/09 19:43
$buio    = 1789520539;          // 16/09 01:42, il primo 504
$ritorno = 1789536044;          // 16/09 05:20, la telemetria torna

$inIsola = ['status' => 'offgrid', 'since' => $isolaDa,
            'last_notified' => $notificataAlle, 'last_ok' => 1789467687];

// 1. Cade la telemetria: l'isola va messa da parte, non buttata.
$c = continuitaAllarme($inIsola, 'unreachable', $buio);
check('cecita su isola -> ricomincia a contare la cecita', $buio, $c['since']);
check('  la cecita non eredita le notifiche dell isola', 0, $c['last_notified']);
check('  ma l isola resta in memoria', 'offgrid', $c['memoria']['imp_status'] ?? null);
check('  con la sua data vera', $isolaDa, $c['memoria']['imp_since'] ?? null);
check('  e con la mail gia mandata', $notificataAlle, $c['memoria']['imp_last_notified'] ?? null);

// 2. La cecita' dura. La memoria non si perde di giro in giro.
$cieco = ['status' => 'unreachable', 'since' => $buio, 'last_notified' => 0,
          'imp_status' => 'offgrid', 'imp_since' => $isolaDa,
          'imp_last_notified' => $notificataAlle];
$c = continuitaAllarme($cieco, 'unreachable', $buio + 1800);
check('cecita che persiste -> conta dal primo errore', $buio, $c['since']);
check('  e si porta ancora dietro l isola', $isolaDa, $c['memoria']['imp_since'] ?? null);

// 3. LA CORREZIONE. La telemetria torna, l'isola c'e' ancora: prosegue.
$c = continuitaAllarme($cieco, 'offgrid', $ritorno);
check('IL CASO: isola ritrovata -> riprende dalla data vera', $isolaDa, $c['since']);
check('  e NON e una nuova anomalia da adesso', false, $c['since'] === $ritorno);
check('  la mail gia mandata conta ancora (niente doppione)', $notificataAlle, $c['last_notified']);
check('  e il log lo dichiara', true, $c['ripreso']);
check('  fuori dalla cecita nessuna memoria da tenere', [], $c['memoria']);

// 4. Se invece al ritorno l'impianto sta facendo altro, quello e' nuovo davvero.
$c = continuitaAllarme($cieco, 'soc', $ritorno);
check('al ritorno una condizione diversa -> allarme nuovo', $ritorno, $c['since']);
check('  e da notificare', 0, $c['last_notified']);

// 5. L'isola finisce mentre non si vedeva: il rientro va detto lo stesso.
//    Prima si guardava solo 'last_notified', che durante la cecita' e' 0:
//    l'isola sarebbe finita senza che nessuno lo sapesse.
list($daCosa, $notificato) = rientroDa($cieco);
check('rientro dopo cecita -> nomina l allarme vero', 'offgrid', $daCosa);
check('  e sa che era stato notificato', true, $notificato);

// Una cecita' mai notificata, senza niente sotto, non merita un rientro.
list($daCosa, $notificato) = rientroDa(['status' => 'unreachable', 'since' => $buio, 'last_notified' => 0]);
check('cecita sola e muta -> nessun rientro da annunciare', false, $notificato);
check('  e il nome resta il suo', 'unreachable', $daCosa);

// Il caso normale non cambia: allarme visto, notificato, poi rientrato.
list($daCosa, $notificato) = rientroDa(['status' => 'offgrid', 'since' => $isolaDa,
                                        'last_notified' => $notificataAlle]);
check('rientro normale -> invariato', 'offgrid', $daCosa);
check('  e va annunciato', true, $notificato);

echo "\nLa mail di cecita dice cosa c era sotto\n";

$nota = notaAllarmeSotto('unreachable', ['imp_status' => 'offgrid', 'imp_since' => $isolaDa,
                                         'imp_last_notified' => $notificataAlle]);
check('nomina l allarme rimasto scoperto', true, str_contains($nota, 'sistema in isola'));
check('  dice che non e rientrato', true, str_contains($nota, "Non e' rientrato"));
check('  e da quando dura', true, str_contains($nota, date('Y-m-d H:i', $isolaDa)));
check('senza niente sotto non inventa nulla', '', notaAllarmeSotto('unreachable', []));
check('e su un allarme vero non c entra', '',
    notaAllarmeSotto('offgrid', ['imp_status' => 'soc', 'imp_since' => $isolaDa]));

echo "\nLA MAIL DELLA NOTTE DEL 17/09: la batteria si scarica, e va bene cosi\n";

/* Arrivata alle 02:19: "POWERWALL QUASI SCARICO - Carica 20% sotto la soglia
 * 20%", anomalia in corso dalle 21:05. Due cose sbagliate.
 *
 * La prima e' il testo: 20 non e' sotto 20. La carica vera era 19,6% e round()
 * la faceva salire a 20 su tutti e due i lati del confronto. Un avviso che
 * sembra sbagliato viene trattato come sbagliato, anche quando ha ragione.
 *
 * La seconda e' l'avviso stesso. Di notte il solare non carica e la casa
 * assorbe piu' di quanto la batteria contenga: arrivare al mattino in riserva
 * e' il ciclo previsto. E siamo per forza con la rete presente, perche'
 * l'isola viene valutata prima e ha la precedenza: la casa non resta senza
 * niente. Con RENOTIFY_HOURS a 4 ore quella mail tornava tutta la notte.
 */
$notteCfg  = ['stale_limit_min' => 60, 'soc_min_percent' => 20, 'e_giorno' => false];
$giornoCfg = ['stale_limit_min' => 60, 'soc_min_percent' => 20, 'e_giorno' => true];
$scarica   = ['percentage_charged' => 19.6, 'battery_power' => 0,
              'load_power' => 2752, 'grid_power' => 2752, 'solar_power' => 0];

list($c, $d) = evaluateCondition(live($scarica), $now, $notteCfg);
check('IL CASO: batteria in riserva di notte -> nessun verdetto', 'notte', $c);
check('  il messaggio dice che e il ciclo normale', true, str_contains($d, 'ciclo normale'));
check('  e che la rete copre', true, str_contains($d, "la rete c'e'"));
check('  e quando si torna a guardare', true, str_contains($d, 'Si rivaluta di giorno'));

// Di giorno la stessa carica e' un'altra cosa: c'e' il sole e non sale.
list($c, $d) = evaluateCondition(live($scarica), $now, $giornoCfg);
check('la stessa carica di giorno -> allarme (li si interviene)', 'soc', $c);
check('  e dice perche cambia', true, str_contains($d, 'non si sta ricaricando'));

// L'isola ha la precedenza: di notte, senza rete, si parla eccome.
list($c, ) = evaluateCondition(
    live($scarica + ['island_status' => 'off_grid_unintentional', 'grid_status' => 'Islanded']),
    $now, $notteCfg);
check('di notte SENZA rete -> l isola parla lo stesso', 'offgrid', $c);

// E la telemetria morta resta un guasto anche col buio.
list($c, ) = evaluateCondition(live($scarica + ['timestamp' => date('c', $now - 3 * 3600)]),
    $now, $notteCfg);
check('di notte la telemetria morta resta un guasto', 'stale', $c);

// Una batteria carica di notte non produce nessun verdetto notturno.
list($c, ) = evaluateCondition(live(['percentage_charged' => 80.0]), $now, $notteCfg);
check('di notte con la batteria carica -> ok normale', 'ok', $c);

// Il flag mancante non deve zittire: nel dubbio un watchdog parla.
list($c, ) = evaluateCondition(live($scarica), $now,
    ['stale_limit_min' => 60, 'soc_min_percent' => 20]);
check('cfg senza il flag giorno/notte -> parla', 'soc', $c);

echo "\nLa percentuale non mente sull arrotondamento\n";

check('19,6 non diventa 20', '19.6%', fmtPerc(19.6));
check('  cosi la frase non dice piu 20% sotto la soglia 20%', false,
    str_contains((string) evaluateCondition(live($scarica), $now, $giornoCfg)[1], 'Carica 20% sotto la soglia 20%'));
check('un valore tondo resta tondo', '20%', fmtPerc(20.0));
check('carica assente -> n/d', 'n/d', fmtPerc(null));

echo "\nLa notte non cancella la memoria di un allarme messo da parte\n";
// saveState riscrive tutto il file: il ramo notturno deve riportarsi dietro
// le chiavi imp_*, altrimenti un'isola messa da parte durante una cecita'
// sparisce al primo giro di buio.
check('le chiavi imp_* attraversano la notte',
    ['imp_status' => 'offgrid', 'imp_since' => 111, 'imp_last_notified' => 222],
    memoriaConservata(['status' => 'unreachable', 'since' => 9,
                       'imp_status' => 'offgrid', 'imp_since' => 111, 'imp_last_notified' => 222]));
check('senza memoria non si inventa niente', [], memoriaConservata(['status' => 'ok']));

echo "\nLA MAIL DEL 16/09: un errore 503 detto a chi la mail la legge\n";

// Il testo che e' arrivato davvero, riportato dal destinatario. Tagliato a
// meta' parola ("503 Service" e basta), con l'HTML annidato dentro il JSON e
// le escape \u003e non risolte. Ripetuto due volte nella stessa mail.
$reale = 'HTTP 503. Body: {"response":null,"error":"https://powergate.prd.sn.tesla.services:443'
       . '/api/v4/energy_site/live_status => <html>\r\n<head>'
       . '<title>503 Service Temporarily Unavailable</title></head>'
       . '\r\n<body>\r\n<center><h1>503 Service';

$m = spiegaErrore($reale);
check('IL CASO: 503 -> una frase, non un corpo HTTP', true,
    str_contains($m, 'I server di Tesla non rispondono'));
check('  dice a chi legge che non e l impianto', true,
    str_contains($m, "Non e' un guasto dell'impianto"));
check('  niente HTML', false, str_contains($m, '<html'));
check('  niente escape \u003e non risolte', false, str_contains($m, '\u003e'));
check('  e nemmeno il > che ne esce se si decodifica a meta', false, str_contains($m, '=>'));
check('  niente \r\n letterali', false, str_contains($m, '\r\n'));
check('  niente JSON grezzo', false, str_contains($m, '"response":null'));
check('  il dettaglio tecnico resta, ma leggibile', true,
    str_contains($m, 'Service Temporarily Unavailable'));
check('  e tutto sta in una riga', false, str_contains($m, "\n"));

// Il 424 e il 504 della stessa notte: stessa cura, frase diversa.
check('424 -> dice che Tesla non legge il Powerwall', true, str_contains(
    spiegaErrore('HTTP 424. Body: {"response":null,"error":"https://x => {Message: \"Error getting live status\", Status: 424}"}'),
    'non riesce a leggere il Powerwall'));
check('504 -> parla di lentezza, non di guasto', true, str_contains(
    spiegaErrore('HTTP 504. Body: {"response":null,"error":"https://x => Gateway Timeout"}'),
    'troppo lentamente'));

// Un token rifiutato non rientra da solo: la mail non deve far credere il contrario.
$a = spiegaErrore('HTTP 401. Body: {"error":"invalid_grant"}');
check('401 -> avverte che serve intervenire', true, str_contains($a, 'non rientra da solo'));
check('  e non promette che passa', false, str_contains($a, 'rientra da solo.'));

check('connessione mai partita -> lo dice', true,
    str_contains(spiegaErrore('cURL: Operation timed out after 30001 milliseconds'), 'non parte'));
check('nessun errore -> nessuna frase', '', spiegaErrore(''));

echo "\nIl dettaglio tecnico non si taglia a meta parola\n";
check('un corpo enorme viene accorciato', true,
    mb_strlen(rigaTecnica('x ' . str_repeat('parolalunga ', 80))) <= 145);
check('  e il taglio si vede', true, str_contains(rigaTecnica(str_repeat('parolalunga ', 80)), '...'));
check('  senza mozzare la parola', false,
    (bool) preg_match('/parolalung\.\.\./', rigaTecnica(str_repeat('parolalunga ', 80))));
check('un corpo vuoto non produce una riga muta', 'nessun dettaglio leggibile', rigaTecnica('<html></html>'));

echo "\nLa pazienza sulla cecita e quella dichiarata\n";
// Mezz'ora di 503 altrui, di notte, non e' un guasto: e' un singhiozzo.
check('il predefinito di TESLA_UNREACH_PERSIST_MIN e 90 min', '90',
    preg_match("/TESLA_UNREACH_PERSIST_MIN',\s*'(\d+)'/", file_get_contents(__DIR__ . '/../tesla.php'), $m)
        ? $m[1] : null);
check('unreachable e auth sono le condizioni cieche', ['unreachable', 'auth'], CIECHE);
check('offgrid, soc e stale sono verdetti sull impianto', ['offgrid', 'soc', 'stale'], IMPIANTO);


echo "\n" . ($fails === 0 ? "Tutte le prove sono passate.\n" : "$fails prove fallite.\n");
exit($fails === 0 ? 0 : 1);
