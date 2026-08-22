<?php
declare(strict_types=1);
// Echte Ausfuehrung der Anmeldebremse (ENT-075).
//
// Zwei Dinge muessen zugleich stimmen: Die Bremse muss greifen, UND sie
// darf nicht selbst zur Waffe werden -- wer einen Login-Namen kennt, darf
// ihn nicht dauerhaft aussperren koennen.
$quelle = file_get_contents(__DIR__ . '/../backend/anmeldung.php');
preg_match_all('/^const (ANMELD_\w+)\s*=\s*(\d+);/m', $quelle, $k, PREG_SET_ORDER);
foreach ($k as $c) { define($c[1], (int)$c[2]); }
preg_match('/function anmeld_sperre.*?\n\}/s', $quelle, $f);
eval($f[0]);

$ok = 0; $bad = [];
function pruef(string $name, bool $c) { global $ok, $bad; if ($c) { $ok++; } else { $bad[] = $name; } }

pruef('Es gibt ueberhaupt Grenzen',
    defined('ANMELD_MAX_NAME') && ANMELD_MAX_NAME > 0 && ANMELD_MAX_ADRESSE > 0);

// ── Sie greift
pruef('Ein einzelner Fehlversuch sperrt nicht', anmeld_sperre(1, 1) === 0);
pruef('Auch der vorletzte erlaubte nicht', anmeld_sperre(ANMELD_MAX_NAME - 1, 0) === 0);
pruef('KRITISCH: nach ' . ANMELD_MAX_NAME . ' Fehlversuchen auf denselben Namen wird gesperrt',
    anmeld_sperre(ANMELD_MAX_NAME, 0) > 0);
pruef('KRITISCH: wer viele Namen durchprobiert, wird ueber die Adresse gebremst',
    anmeld_sperre(0, ANMELD_MAX_ADRESSE) > 0);
pruef('Die Grenze je Adresse liegt hoeher als die je Name -- ein Betrieb teilt sich eine Adresse',
    ANMELD_MAX_ADRESSE > ANMELD_MAX_NAME);

// ── Sie wird nicht selbst zur Waffe
pruef('KRITISCH: die Sperre ist zeitlich begrenzt, nicht dauerhaft',
    ANMELD_SPERRE_MIN > 0 && ANMELD_SPERRE_MIN <= 60);
pruef('KRITISCH: das Zaehlfenster laeuft von selbst ab',
    ANMELD_FENSTER_MIN > 0 && ANMELD_FENSTER_MIN <= 60);
pruef('Die Wartezeit ist zumutbar, wenn man sich selbst ausgesperrt hat',
    ANMELD_SPERRE_MIN <= 30);

// ── Alltagstauglich
pruef('Wer sich zweimal vertippt, wird nicht gesperrt', anmeld_sperre(2, 2) === 0);
pruef('Ein Betrieb mit mehreren Leuten am selben Anschluss kommt durch',
    anmeld_sperre(0, 8) === 0);

echo count($bad) === 0 ? "$ok Pruefungen bestanden\n" : '';
foreach ($bad as $b) { echo "X $b\n"; }
exit(count($bad) === 0 ? 0 : 1);
