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
    'lat'               => 44.06255,
    'lon'               => 12.45047,
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

echo "\n" . ($fails === 0 ? "Tutte le prove sono passate.\n" : "$fails prove fallite.\n");
exit($fails === 0 ? 0 : 1);
