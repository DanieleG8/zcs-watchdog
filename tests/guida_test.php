<?php
/**
 * La guida non deve restare indietro rispetto al codice.
 *
 * Una documentazione che descrive allarmi che non esistono piu', o che tace su
 * quelli nuovi, e' peggio di nessuna documentazione: chi la legge decide in
 * base a qualcosa che non e' vero. Ma nessuno si ricorda di aggiornarla, e un
 * commento "ricordati di..." non ha mai fermato nessuno.
 *
 * Questa prova legge dai sorgenti tutte le notifiche che i due watchdog sanno
 * mandare e pretende di ritrovarle in RIFERIMENTO.md. Aggiungere un allarme
 * senza documentarlo fa fallire la CI, che e' l'unico promemoria che funziona.
 *
 * Si lancia con:  php tests/guida_test.php
 */

$radice = dirname(__DIR__);
$fails  = 0;

function check(string $titolo, $atteso, $ottenuto): void
{
    global $fails;
    if ($atteso === $ottenuto) { echo "  ok   $titolo\n"; return; }
    $fails++;
    echo "  FAIL $titolo\n";
    echo "       atteso:   " . var_export($atteso, true) . "\n";
    echo "       ottenuto: " . var_export($ottenuto, true) . "\n";
}

/**
 * Tutti i titoli di notifica di uno script: sia quelli passati direttamente a
 * notify('X', ...), sia quelli della tabella $titles indicizzata per condizione.
 */
function titoliDiNotifica(string $sorgente): array
{
    $titoli = [];

    preg_match_all("/notify\(\s*'([^']+)'/", $sorgente, $m);
    $titoli = array_merge($titoli, $m[1]);

    if (preg_match('/\$titles\s*=\s*\[(.*?)\];/s', $sorgente, $t)) {
        preg_match_all("/=>\s*'([^']+)'/", $t[1], $m2);
        $titoli = array_merge($titoli, $m2[1]);
    }

    sort($titoli);
    return array_values(array_unique($titoli));
}

function costante(string $sorgente, string $nome): ?string
{
    return preg_match("/const\s+$nome\s*=\s*'([^']*)'/", $sorgente, $m) ? $m[1] : null;
}

$fv     = file_get_contents("$radice/watchdog.php");
$tesla  = file_get_contents("$radice/tesla.php");
$guida  = file_get_contents("$radice/RIFERIMENTO.md");

echo "Ogni allarme del codice e' descritto in RIFERIMENTO.md\n";

$attesi = [
    '[FV ZCS]'    => titoliDiNotifica($fv),
    '[Powerwall]' => titoliDiNotifica($tesla),
];

foreach ($attesi as $prefisso => $titoli) {
    check("  $prefisso: qualche titolo trovato nel sorgente", true, count($titoli) > 0);
    foreach ($titoli as $titolo) {
        check("  $prefisso $titolo", true, str_contains($guida, $titolo));
    }
}

echo "\nIl prefisso di ogni watchdog e' spiegato\n";
foreach (array_keys($attesi) as $prefisso) {
    check("  $prefisso citato nella guida", true, str_contains($guida, $prefisso));
}

echo "\nIl link alla guida nelle mail non e' rotto\n";

$urlFv    = costante($fv, 'GUIDE_URL');
$urlTesla = costante($tesla, 'GUIDE_URL');

check('watchdog.php dichiara GUIDE_URL', true, $urlFv !== null && $urlFv !== '');
check('tesla.php dichiara GUIDE_URL',    true, $urlTesla !== null && $urlTesla !== '');
check('i due watchdog puntano alla stessa guida', $urlFv, $urlTesla);
check('e' . " l'indirizzo e' quello scritto in RIFERIMENTO.md", true,
    $urlFv !== null && str_contains($guida, $urlFv));

echo "\nLe soglie citate nella guida esistono davvero\n";
// Non si controlla il valore (sta nelle Variables del repo, non nel codice):
// si controlla che il nome esista ancora, cosi' rinominare una soglia senza
// aggiornare la guida non passa inosservato.
foreach (['ZERO_W_THRESHOLD', 'ENERGY_WINDOW_MIN', 'STALE_LIMIT_MIN', 'RENOTIFY_HOURS'] as $nome) {
    if (!str_contains($guida, $nome)) continue;
    check("  $nome e' ancora letto da watchdog.php", true, str_contains($fv, "'$nome'"));
}
foreach (['TESLA_STALE_LIMIT_MIN', 'TESLA_OFFGRID_PERSIST_MIN', 'TESLA_OFFGRID_GRID_W',
          'TESLA_UNREACH_PERSIST_MIN', 'TESLA_SOC_MIN_PERCENT'] as $nome) {
    if (!str_contains($guida, $nome)) continue;
    check("  $nome e' ancora letto da tesla.php", true, str_contains($tesla, "'$nome'"));
}

echo "\n" . ($fails === 0
    ? "Tutte le prove sono passate.\n"
    : "$fails prove fallite: aggiorna RIFERIMENTO.md (e la pagina per non tecnici) prima di procedere.\n");
exit($fails === 0 ? 0 : 1);
