<?php
/**
 * Prove della valutazione di watchdog.php (potenza + contatore di energia).
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
$cfg = [
    'zero_w_threshold'  => 50,
    'stale_limit_min'   => 60,
    'energy_stall_min'  => 60,
    'lastupdate_is_utc' => false,
    'lat'               => 44.06255,
    'lon'               => 12.45047,
    'day_margin_min'    => 40,
];
$giorno = strtotime('2026-09-14 12:00:00');
$notte  = strtotime('2026-09-14 03:00:00');

function nodo(array $over = [], ?int $now = null): array
{
    $now = $now ?? strtotime('2026-09-14 12:00:00');
    return $over + [
        'powerGenerating'       => 5000,
        'energyGeneratingTotal' => 218530.5,
        'energyGenerating'      => 12.3,
        'lastUpdate'            => gmdate('Y-m-d\TH:i:s\Z', $now - 180),
    ];
}
// stato: contatore visto a $valore, fermo da $fermoMin minuti
function stato(float $valore, int $fermoMin, ?int $now = null): array
{
    $now = $now ?? strtotime('2026-09-14 12:00:00');
    return ['etot' => $valore, 'etot_ts' => $now - $fermoMin * 60];
}

echo "evaluateProduction\n";

list($c, $d, $e) = evaluateProduction(nodo(), $giorno, stato(218530.0, 10), $cfg);
check('giorno, produce, contatore salito -> ok', 'ok', $c);
check('  il cronometro riparte quando il contatore si muove', $giorno, $e['etot_ts']);
check('  memorizza il nuovo valore', 218530.5, $e['etot']);

list($c, ) = evaluateProduction(nodo(), $giorno, stato(218530.5, 200), $cfg);
check('giorno, produce, contatore fermo da 200 min -> allarme', 'noenergy', $c);

list($c, $d) = evaluateProduction(nodo(['powerGenerating' => 613, 'energyGeneratingTotal' => 218529.9]), $giorno, stato(218529.9, 61), $cfg);
check('IL CASO DEL 14/09: 613 W dichiarati ma contatore fermo -> noenergy', 'noenergy', $c);
check('  il messaggio dice che non entra energia', true, str_contains($d, 'non entra energia'));

list($c, ) = evaluateProduction(nodo(['powerGenerating' => 0, 'energyGeneratingTotal' => 218529.9]), $giorno, stato(218529.9, 61), $cfg);
check('giorno, 0 W e contatore fermo da 61 min -> zero', 'zero', $c);

list($c, ) = evaluateProduction(nodo(['powerGenerating' => 0, 'energyGeneratingTotal' => 218529.9]), $giorno, stato(218529.9, 30), $cfg);
check('giorno, 0 W ma contatore fermo solo da 30 min -> ancora ok', 'ok', $c);

list($c, $d) = evaluateProduction(nodo(['powerGenerating' => 0]), $giorno, stato(218530.0, 10), $cfg);
check('giorno, 0 W ma energia in aumento -> ok (campo potenza rotto)', 'ok', $c);
check('  lo segnala nel messaggio', true, str_contains($d, 'campo potenza inaffidabile'));

list($c, $e2) = [null, null];
list($c, $d, $e2) = evaluateProduction(nodo(['powerGenerating' => 0, 'energyGeneratingTotal' => 218529.9], $notte), $notte, stato(218529.9, 600, $notte), $cfg);
check('notte, 0 W e contatore fermo da 10 ore -> ok', 'ok', $c);
check('  di notte il cronometro resta azzerato (niente allarme all alba)', $notte, $e2['etot_ts']);

list($c, ) = evaluateProduction(nodo(['lastUpdate' => gmdate('Y-m-d\TH:i:s\Z', $giorno - 7200)]), $giorno, stato(218530.5, 200), $cfg);
check('dato vecchio di 2 ore -> stale (prevale su tutto)', 'stale', $c);

list($c, $d, $e3) = evaluateProduction(nodo(['energyGeneratingTotal' => 100.0]), $giorno, stato(218530.5, 300), $cfg);
check('contatore azzerato/sostituito -> nessun allarme', 'ok', $c);
check('  e il cronometro riparte', $giorno, $e3['etot_ts']);

$senzaContatore = nodo(['powerGenerating' => 0]);
unset($senzaContatore['energyGeneratingTotal']);
list($c, $d) = evaluateProduction($senzaContatore, $giorno, stato(218530.5, 300), $cfg);
check('contatore assente + 0 W -> si torna al solo criterio potenza', 'zero', $c);
check('  e il messaggio lo dice', true, str_contains($d, 'non disponibile'));

list($c, $d) = evaluateProduction(nodo(['powerGenerating' => 0]), $giorno, [], $cfg);
check('primo giro con 0 W -> ok, ma senza spacciare conferme che non ha', 'ok', $c);
check('  dice che aspetta il contatore', true, str_contains($d, 'attendo conferma dal contatore'));
check('  e non afferma che il campo potenza e rotto', false, str_contains($d, 'inaffidabile'));

list($c, $d, $e4) = evaluateProduction(nodo(), $giorno, [], $cfg);
check('primo giro senza stato -> nessun allarme', 'ok', $c);
check('  e il cronometro parte adesso', $giorno, $e4['etot_ts']);

echo "\n" . ($fails === 0 ? "Tutte le prove sono passate.\n" : "$fails prove fallite.\n");
exit($fails === 0 ? 0 : 1);
