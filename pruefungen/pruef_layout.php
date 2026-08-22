<?php
declare(strict_types=1);
// Echte Ausfuehrung der Layout-Pruefung (ENT-073).
//
// Die Playwright-Suiten taeuschen die Serverantworten vor und fuehren PHP
// nie aus. layout_pruefen() ist aber genau die Stelle, an der fremde Daten
// in die Datenbank wollen -- die muss laufen, nicht nur gelesen werden.
require __DIR__ . '/../backend/layout.php';

$ok = 0; $bad = [];
function pruef(string $name, bool $c) { global $ok, $bad; if ($c) { $ok++; } else { $bad[] = $name; } }

// ── Was durchgehen muss
$gut = layout_pruefen([['id' => 'person', 'sichtbar' => true], ['id' => 'ausweise', 'sichtbar' => false]]);
pruef('Eine gueltige Anordnung kommt durch', is_array($gut) && count($gut) === 2);
pruef('Reihenfolge bleibt erhalten', $gut[0]['id'] === 'person' && $gut[1]['id'] === 'ausweise');
pruef('sichtbar wird zu echtem true/false', $gut[0]['sichtbar'] === true && $gut[1]['sichtbar'] === false);
pruef('Fehlendes sichtbar gilt als sichtbar',
    layout_pruefen([['id' => 'person']])[0]['sichtbar'] === true);

// ── Was NICHT durchgehen darf
pruef('KRITISCH: kein Text statt Liste', layout_pruefen('person') === null);
pruef('KRITISCH: kein Eintrag ohne id', layout_pruefen([['sichtbar' => true]]) === null);
pruef('KRITISCH: keine leere id', layout_pruefen([['id' => '']]) === null);
pruef('KRITISCH: keine Sonderzeichen in der id', layout_pruefen([['id' => 'a<b']]) === null);
pruef('KRITISCH: keine Grossbuchstaben oder Punkte', layout_pruefen([['id' => 'Person.1']]) === null);
pruef('KRITISCH: keine ueberlange id',
    layout_pruefen([['id' => str_repeat('a', LAYOUT_MAX_ID + 1)]]) === null);
pruef('KRITISCH: derselbe Container nicht zweimal',
    layout_pruefen([['id' => 'person'], ['id' => 'person']]) === null);
pruef('KRITISCH: keine unbegrenzte Laenge', layout_pruefen(array_map(
    fn($i) => ['id' => 'c' . $i], range(1, LAYOUT_MAX_EINTRAEGE + 1))) === null);
pruef('Eine leere Liste ist keine Anordnung', layout_pruefen([]) === null);

// ── Bereiche
pruef('Bekannter Bereich wird angenommen', layout_bereich_gueltig('ma_detail'));
pruef('KRITISCH: unbekannter Bereich wird abgewiesen', !layout_bereich_gueltig('beliebig'));
pruef('KRITISCH: leerer Bereich wird abgewiesen', !layout_bereich_gueltig(''));

echo count($bad) === 0 ? "$ok Pruefungen bestanden\n" : '';
foreach ($bad as $b) { echo "X $b\n"; }
exit(count($bad) === 0 ? 0 : 1);
