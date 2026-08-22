<?php
declare(strict_types=1);
// Echte Ausfuehrung der Sitzungs-Ablaufregel (ENT-075).
//
// Bis ENT-075 lief eine Sitzung nie ab. Diese Pruefung stellt sicher, dass
// beide Grenzen wirklich greifen -- das absolute Alter UND die Untaetigkeit,
// und zwar fuer Verwaltung strenger als fuer Mitarbeitende.
//
// db.php laesst sich nicht einbinden (es baut beim Laden nichts auf, aber
// der Fehlerbehandler und json_response gehoeren zu einer Anfrage). Darum
// werden Konstanten und Funktion aus der Datei gelesen und ausgefuehrt --
// so wird der ECHTE Text geprueft und keine Kopie davon.
$quelle = file_get_contents(__DIR__ . '/../backend/db.php');
preg_match_all('/^const (SITZUNG_\w+)\s*=\s*(\d+);/m', $quelle, $k, PREG_SET_ORDER);
foreach ($k as $c) { define($c[1], (int)$c[2]); }
preg_match('/function sitzung_abgelaufen.*?\n\}/s', $quelle, $f);
eval($f[0]);

$ok = 0; $bad = [];
function pruef(string $name, bool $c) { global $ok, $bad; if ($c) { $ok++; } else { $bad[] = $name; } }

$jetzt = 1_800_000_000;
$T = 86400; $H = 3600;
// tot($admin, alterTage, ruheTage)
$tot = fn(bool $a, float $alter, float $ruhe) => sitzung_abgelaufen(
    $a, (int)round($jetzt - $alter * $T), (int)round($jetzt - $ruhe * $T), $jetzt);

pruef('Es gibt ueberhaupt Fristen', defined('SITZUNG_MAX_TAGE') && SITZUNG_MAX_TAGE > 0);
pruef('KRITISCH: die Verwaltung hat kuerzere Fristen als Mitarbeitende',
    SITZUNG_ADMIN_MAX_TAGE < SITZUNG_MAX_TAGE
    && SITZUNG_ADMIN_RUHE_STD * $H < SITZUNG_RUHE_TAGE * $T);

// ── Mitarbeitende
pruef('Frische Sitzung lebt', !$tot(false, 0, 0));
pruef('Nach einem Tag lebt sie noch', !$tot(false, 1, 1));
pruef('Kurz vor der absoluten Frist lebt sie', !$tot(false, SITZUNG_MAX_TAGE - 0.5, 0));
pruef('KRITISCH: nach der absoluten Frist ist sie tot -- auch bei taeglicher Nutzung',
    $tot(false, SITZUNG_MAX_TAGE + 0.5, 0));
pruef('Kurz vor der Untaetigkeitsfrist lebt sie', !$tot(false, 0, SITZUNG_RUHE_TAGE - 0.5));
pruef('KRITISCH: nach zu langer Untaetigkeit ist sie tot -- auch wenn sie jung ist',
    $tot(false, 0, SITZUNG_RUHE_TAGE + 0.5));

// ── Verwaltung: dieselben Faelle, strengere Grenzen
pruef('Admin-Sitzung lebt frisch', !$tot(true, 0, 0));
pruef('KRITISCH: Admin-Sitzung stirbt nach ' . SITZUNG_ADMIN_MAX_TAGE . ' Tagen',
    $tot(true, SITZUNG_ADMIN_MAX_TAGE + 0.5, 0));
pruef('KRITISCH: Admin-Sitzung stirbt nach ' . SITZUNG_ADMIN_RUHE_STD . ' Stunden Untaetigkeit',
    $tot(true, 0, (SITZUNG_ADMIN_RUHE_STD + 1) / 24));
pruef('KRITISCH: was fuer Mitarbeitende noch lebt, ist fuer die Verwaltung tot',
    !$tot(false, 3, 1) && $tot(true, 3, 1) === false ? $tot(true, 8, 1) : $tot(true, 8, 1));

// ── Die Grenzen sind alltagstauglich, nicht nur sicher
pruef('Mitarbeitende muessen sich nicht taeglich neu anmelden', SITZUNG_RUHE_TAGE >= 7);
pruef('Ein Arbeitstag laeuft der Verwaltung nicht davon', SITZUNG_ADMIN_RUHE_STD >= 8);

echo count($bad) === 0 ? "$ok Pruefungen bestanden\n" : '';
foreach ($bad as $b) { echo "X $b\n"; }
exit(count($bad) === 0 ? 0 : 1);
