<?php
/**
 * Prove della valutazione di watchdog.php: la produzione si giudica
 * sull'energia realmente entrata nella finestra, non sulla potenza dichiarata.
 * Si lancia con:  php tests/watchdog_test.php
 *
 * ZCS_WATCHDOG_TEST impedisce allo script di interrogare l'API al require.
 */

define('ZCS_WATCHDOG_TEST', true);
require __DIR__ . '/../watchdog.php';

$fails = 0;

function check(string $titolo, $atteso, $ottenuto): void
{
    global $fails;
    if ($atteso === $ottenuto) { echo "  ok   $titolo\n"; return; }
    $fails++;
    echo "  FAIL $titolo\n";
    echo "       atteso:   " . var_export($atteso, true) . "\n";
    echo "       ottenuto: " . var_export($ottenuto, true) . "\n";
}

// Impianto di Santarcangelo: il 14/09 il sole sta su ~06:50-19:30, quindi con
// 40 min di margine la "giornata" per il watchdog e' ~07:30-18:50.
// Soglia 2000 W: quella sensata per un impianto che fa ~450 kWh al giorno.
$cfg = [
    'zero_w_threshold'  => 2000,
    'stale_limit_min'   => 60,
    'energy_window_min' => 60,
    'lastupdate_is_utc' => false,
    'lat'               => 44.06,
    'lon'               => 12.45,
    'day_margin_min'    => 40,
];
$giorno = strtotime('2026-09-14 12:00:00');
$notte  = strtotime('2026-09-14 03:00:00');

function nodo(float $etot, float $powerW = 5000, ?int $now = null, ?string $lastUpdate = null): array
{
    $now = $now ?? strtotime('2026-09-14 12:00:00');
    return [
        'powerGenerating'       => $powerW,
        'energyGeneratingTotal' => $etot,
        'lastUpdate'            => $lastUpdate ?? gmdate('Y-m-d\TH:i:s\Z', $now - 180),
    ];
}
// finestra aperta $minuti fa con il contatore a $ref
function finestra(float $ref, int $minuti, bool $prodBad = false, ?int $now = null): array
{
    $now = $now ?? strtotime('2026-09-14 12:00:00');
    return ['etot_ref' => $ref, 'ref_ts' => $now - $minuti * 60, 'prod_bad' => $prodBad];
}

echo "evaluateProduction — soglia 2000 W, finestra 60 min\n";

// 30 kWh in un'ora = 30 kW medi: ampiamente sopra soglia.
list($c, $d, $s) = evaluateProduction(nodo(218560.0), $giorno, finestra(218530.0, 60), $cfg);
check('impianto in produzione -> ok', 'ok', $c);
check('  la finestra riparte dal valore attuale', 218560.0, $s['etot_ref']);
check('  e dall istante attuale', $giorno, $s['ref_ts']);

// 2.5 kWh in 60 min = 2500 W medi > 2000: promosso.
list($c, ) = evaluateProduction(nodo(218532.5), $giorno, finestra(218530.0, 60), $cfg);
check('media appena sopra soglia -> ok', 'ok', $c);

// 1.5 kWh in 60 min = 1500 W medi < 2000: guasto.
list($c, $d, $s) = evaluateProduction(nodo(218531.5), $giorno, finestra(218530.0, 60), $cfg);
check('IL CASO DEL FILO DI CORRENTE: 1500 W medi su 90 kWp -> zero', 'zero', $c);
check('  il messaggio riporta la media', true, str_contains($d, '1500 W medi'));
check('  e marca la finestra come negativa', true, $s['prod_bad']);

// Il caso vero del 14/09: portale 613 W, contatore immobile.
list($c, $d) = evaluateProduction(nodo(218529.9, 613), $giorno, finestra(218529.9, 61), $cfg);
check('IL CASO DEL 14/09: 613 W dichiarati, 0 kWh entrati -> zero', 'zero', $c);
check('  il messaggio riporta i kWh entrati', true, str_contains($d, 'Solo 0.00 kWh'));

// Stesso guasto ma con una potenza dichiarata sopra soglia: il messaggio
// deve dire chiaramente che quei watt non si materializzano.
list($c, $d) = evaluateProduction(nodo(218529.9, 9000), $giorno, finestra(218529.9, 61), $cfg);
check('potenza dichiarata sopra soglia ma 0 kWh entrati -> zero', 'zero', $c);
check('  smaschera la potenza dichiarata', true, str_contains($d, 'non entra da nessuna parte'));

// Campo potenza rotto (0 W) ma energia che entra regolarmente: nessun allarme.
list($c, ) = evaluateProduction(nodo(218560.0, 0), $giorno, finestra(218530.0, 60), $cfg);
check('potenza a 0 ma 30 kW medi entrati -> ok (campo rotto, impianto sano)', 'ok', $c);

// Promozione anticipata: 3 kWh in 20 min = 9000 W medi, non serve aspettare.
list($c, $d, $s) = evaluateProduction(nodo(218533.0), $giorno, finestra(218530.0, 20), $cfg);
check('energia gia sufficiente a 20 min -> ok senza aspettare la finestra', 'ok', $c);
check('  e la finestra riparte da adesso', $giorno, $s['ref_ts']);

// Finestra ancora aperta e non ancora sufficiente: si aspetta, non si grida.
list($c, $d, $s) = evaluateProduction(nodo(218530.1), $giorno, finestra(218530.0, 20), $cfg);
check('poca energia ma finestra ancora aperta -> ok, in attesa', 'ok', $c);
check('  la finestra non viene spostata', $giorno - 1200, $s['ref_ts']);

// Verdetto negativo precedente: resta in allarme finche' non risale.
list($c, ) = evaluateProduction(nodo(218530.1), $giorno, finestra(218530.0, 20, true), $cfg);
check('allarme in corso e finestra aperta -> resta in allarme', 'zero', $c);

// Rientro: l'impianto riparte davvero, l'energia entra, si esce dall'allarme.
list($c, $d, $s) = evaluateProduction(nodo(218545.0), $giorno, finestra(218530.0, 30, true), $cfg);
check('impianto che riparte -> esce dall allarme subito', 'ok', $c);
check('  e azzera il verdetto negativo', false, $s['prod_bad']);

echo "\naltri casi\n";

list($c, $d, $s) = evaluateProduction(nodo(218529.9, 0, $notte), $notte, finestra(218529.9, 600, false, $notte), $cfg);
check('notte, nessuna produzione -> ok', 'ok', $c);
check('  la finestra resta ancorata al presente (niente allarme all alba)', $notte, $s['ref_ts']);

list($c, ) = evaluateProduction(nodo(218530.0, 5000, null, gmdate('Y-m-d\TH:i:s\Z', $giorno - 7200)), $giorno, finestra(218530.0, 200), $cfg);
check('dato vecchio di 2 ore -> stale (prevale su tutto)', 'stale', $c);

list($c, $d, $s) = evaluateProduction(nodo(100.0), $giorno, finestra(218530.5, 300), $cfg);
check('contatore azzerato o inverter sostituito -> nessun allarme', 'ok', $c);
check('  e la finestra riparte dal nuovo valore', 100.0, $s['etot_ref']);

$senza = nodo(0, 0);
unset($senza['energyGeneratingTotal']);
list($c, $d) = evaluateProduction($senza, $giorno, finestra(218530.0, 300), $cfg);
check('contatore assente + potenza sotto soglia -> ripiego sulla sola potenza', 'zeropower', $c);
check('  e il messaggio lo dice', true, str_contains($d, 'non disponibile'));

$senza2 = nodo(0, 9000);
unset($senza2['energyGeneratingTotal']);
list($c, ) = evaluateProduction($senza2, $giorno, finestra(218530.0, 300), $cfg);
check('contatore assente ma potenza alta -> ok', 'ok', $c);

list($c, $d, $s) = evaluateProduction(nodo(218530.0), $giorno, [], $cfg);
check('primo giro senza stato -> nessun allarme', 'ok', $c);
check('  e la finestra parte adesso', $giorno, $s['ref_ts']);

echo "\nIL CASO DEL 14-15/09: la notte non cancella il guasto\n";

// Ieri l'impianto era fermo e l'allarme era partito. Alle 20:41 l'inverter ha
// smesso di trasmettere, alle 00:06 e' partito INVERTER OFFLINE, alle 07:25
// l'inverter e' tornato a parlare e il watchdog ha spedito "Impianto tornato a
// produrre" mentre il contatore era fermo sullo stesso valore da venti ore.

// 1. Lo stale non deve dimenticare che la produzione era gia' giudicata ferma.
list($c, $d, $s) = evaluateProduction(
    nodo(218529.9, 0, $giorno, gmdate('Y-m-d\TH:i:s\Z', $giorno - 7200)),
    $giorno, finestra(218529.9, 120, true), $cfg
);
check('inverter muto mentre era gia in allarme -> stale', 'stale', $c);
check('  ma il verdetto sulla produzione resta negativo', true, $s['prod_bad']);

// 2. Nemmeno la notte lo cancella: al buio non si misura, e "non misurabile"
//    non vuol dire "risolto".
list($c, $d, $s) = evaluateProduction(nodo(218529.9, 0, $notte), $notte, finestra(218529.9, 300, true, $notte), $cfg);
check('notte con allarme produzione in corso -> nessun allarme nuovo', 'ok', $c);
check('  ma il verdetto negativo sopravvive fino all alba', true, $s['prod_bad']);
check('  e la finestra riparte comunque dal presente', $notte, $s['ref_ts']);

// 3. Un contatore che riparte da zero e' un inverter nuovo: li' si azzera tutto.
list($c, $d, $s) = evaluateProduction(nodo(5.0), $giorno, finestra(218530.0, 300, true), $cfg);
check('contatore ripartito da capo -> verdetto azzerato', false, $s['prod_bad']);

// 4. Primo giro senza stato: nessuna eredita' da conservare.
list($c, $d, $s) = evaluateProduction(nodo(218530.0), $giorno, [], $cfg);
check('primo giro senza stato -> verdetto pulito', false, $s['prod_bad']);

echo "\ntestoRientro — un rientro dice da cosa si rientra\n";

check('rientro dalla produzione -> lo dice',
    'Impianto tornato a produrre.', testoRientro('zero', false));
check('rientro dal ripiego sulla potenza -> idem',
    'Impianto tornato a produrre.', testoRientro('zeropower', false));

$t = testoRientro('stale', true);
check('IL MESSAGGIO SBAGLIATO DI STAMATTINA: rientro da stale con guasto aperto', true,
    str_contains($t, 'Inverter tornato a trasmettere'));
// La frase affermativa, non la sottostringa: 'NON risulta tornato a produrre'
// contiene 'tornato a produrre' ed e' esattamente l'opposto di una promessa.
check('  non promette produzione', false, str_contains($t, 'Impianto tornato a produrre'));
check('  e avverte che il guasto e ancora li', true, str_contains($t, 'NON risulta tornato a produrre'));

$t = testoRientro('stale', false);
check('rientro da stale senza guasto noto -> resta prudente', true,
    str_contains($t, "non e' ancora stata misurata"));

$t = testoRientro('unreachable', false);
check('rientro da API muta -> parla di monitoraggio', true, str_contains($t, 'monitoraggio ci vede'));

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
