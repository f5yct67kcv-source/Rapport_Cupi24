<?php
// Fachlogik der Einsatzplanung (ENT-021): Feiertage und die Ableitung
// einzelner Schichten aus den Masterschichten eines Objekts.
//
// Wichtige Abgrenzung: Hier wird ein Kalendertag als Feiertag MARKIERT.
// Ob daraus ein Zeitbonus, ein Zuschlag oder eine Feiertagsentschaedigung
// folgt, ist offen (GAV-AUS-003, GAV-AUS-006) und wird hier bewusst NICHT
// beantwortet.
declare(strict_types=1);

// Ostersonntag nach der anonymen gregorianischen Berechnung. Bewusst selbst
// gerechnet: easter_date() braucht die Kalender-Erweiterung, die auf einem
// geteilten Hosting nicht garantiert ist.
function ostersonntag(int $jahr): string
{
    $a = $jahr % 19;
    $b = intdiv($jahr, 100);
    $c = $jahr % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $monat = intdiv($h + $l - 7 * $m + 114, 31);
    $tag = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $jahr, $monat, $tag);
}

// Die dem Sonntag gleichgestellten Feiertage im Kanton Solothurn.
//
// Quelle: Arbeitsinspektorat Kanton Solothurn, Art. 20a ArG. Acht kantonale
// Tage zuzueglich der Bundesfeier, die in der ganzen Schweiz gleichgestellt
// ist.
//
// NICHT enthalten sind Berchtoldstag, Oster- und Pfingstmontag, Mariae
// Empfaengnis und Stephanstag. Sie stehen in gebraeuchlichen Kalendern, sind
// aber nicht durchgehend gleichgestellt (siehe GAV-AUS-006).
//
// Ebenfalls nicht abgebildet: der Bezirk Bucheggberg kennt Fronleichnam,
// Mariae Himmelfahrt und Allerheiligen nicht, und einzelne Gemeinden koennen
// eigene Tage haben. Fuer die heutigen Objekte ohne Wirkung -- wer das
// braucht, traegt den Tag von Hand nach.
function feiertage_solothurn(int $jahr): array
{
    $o = new DateTimeImmutable(ostersonntag($jahr));
    $tag = fn(int $plus) => $o->modify(($plus >= 0 ? '+' : '') . $plus . ' days')->format('Y-m-d');

    return [
        ['datum' => sprintf('%04d-01-01', $jahr), 'name' => 'Neujahr',            'halbtags' => 0, 'ab_zeit' => null],
        ['datum' => $tag(-2),                     'name' => 'Karfreitag',         'halbtags' => 0, 'ab_zeit' => null],
        // Der 1. Mai gilt in Solothurn nur nachmittags -- ein halber Feiertag.
        ['datum' => sprintf('%04d-05-01', $jahr), 'name' => 'Tag der Arbeit (ab Mittag)', 'halbtags' => 1, 'ab_zeit' => '12:00'],
        ['datum' => $tag(39),                     'name' => 'Auffahrt',           'halbtags' => 0, 'ab_zeit' => null],
        ['datum' => $tag(60),                     'name' => 'Fronleichnam',       'halbtags' => 0, 'ab_zeit' => null],
        ['datum' => sprintf('%04d-08-01', $jahr), 'name' => 'Bundesfeier',        'halbtags' => 0, 'ab_zeit' => null],
        ['datum' => sprintf('%04d-08-15', $jahr), 'name' => 'Mariä Himmelfahrt',  'halbtags' => 0, 'ab_zeit' => null],
        ['datum' => sprintf('%04d-11-01', $jahr), 'name' => 'Allerheiligen',      'halbtags' => 0, 'ab_zeit' => null],
        ['datum' => sprintf('%04d-12-25', $jahr), 'name' => 'Weihnachten',        'halbtags' => 0, 'ab_zeit' => null],
    ];
}

const FEIERTAG_QUELLE = 'Arbeitsinspektorat Kanton Solothurn (ohne Bezirk Bucheggberg), Art. 20a ArG';

// Feiertage eines Kantons in einem Zeitraum, als Zuordnung Datum -> Name.
function feiertage_im_zeitraum(string $kanton, string $von, string $bis): array
{
    $s = db()->prepare('SELECT datum, name, halbtags FROM feiertage WHERE kanton = ? AND datum BETWEEN ? AND ?');
    $s->execute([$kanton, $von, $bis]);
    $map = [];
    foreach ($s->fetchAll() as $r) {
        $map[$r['datum']] = ['name' => $r['name'], 'halbtags' => (int)$r['halbtags']];
    }
    return $map;
}

// Welche Schichten wuerden im Zeitraum aus den Masterschichten eines Objekts
// entstehen? Schreibt nichts -- das Ergebnis dient der Vorschau und wird von
// schichten_erzeugen.php mit denselben Regeln noch einmal berechnet.
function planung_vorschlag(int $objektId, string $von, string $bis): array
{
    $o = db()->prepare('SELECT * FROM objekte WHERE id = ?');
    $o->execute([$objektId]);
    $objekt = $o->fetch();
    if (!$objekt) {
        return ['fehler' => 'Objekt nicht gefunden'];
    }

    $ms = db()->prepare(
        'SELECT * FROM masterschichten
         WHERE objekt_id = ? AND gueltig_ab <= ? AND (gueltig_bis IS NULL OR gueltig_bis >= ?)
         ORDER BY von, name'
    );
    $ms->execute([$objektId, $bis, $von]);
    $vorlagen = $ms->fetchAll();

    // Was aus diesen Vorlagen im Zeitraum schon existiert, wird nicht doppelt
    // angelegt.
    $vorhanden = [];
    $ex = db()->prepare(
        'SELECT masterschicht_id, datum FROM einsaetze
         WHERE objekt_id = ? AND datum BETWEEN ? AND ? AND masterschicht_id IS NOT NULL'
    );
    $ex->execute([$objektId, $von, $bis]);
    foreach ($ex->fetchAll() as $r) {
        $vorhanden[$r['masterschicht_id'] . '|' . $r['datum']] = true;
    }

    $feiertage = feiertage_im_zeitraum((string)$objekt['kanton'], $von, $bis);
    $wochenfeld = [1 => 'bedarf_mo', 2 => 'bedarf_di', 3 => 'bedarf_mi', 4 => 'bedarf_do',
                   5 => 'bedarf_fr', 6 => 'bedarf_sa', 7 => 'bedarf_so'];

    $neu = [];
    $uebersprungen = 0;
    $ende = new DateTimeImmutable($bis);

    foreach ($vorlagen as $v) {
        $tag = new DateTimeImmutable(max($von, $v['gueltig_ab']));
        $letzter = $v['gueltig_bis'] !== null && $v['gueltig_bis'] < $bis
            ? new DateTimeImmutable($v['gueltig_bis']) : $ende;

        while ($tag <= $letzter) {
            $datum = $tag->format('Y-m-d');
            $bedarf = 0;

            if ($v['rhythmus'] === 'intervall') {
                // Strikter Rhythmus ab dem Startdatum, ohne Ruecksicht auf
                // Wochentage und Feiertage (ENT-021, als ANNAHME vermerkt).
                $start = new DateTimeImmutable((string)($v['intervall_start'] ?: $v['gueltig_ab']));
                $abstand = max(1, (int)$v['intervall_tage']);
                $diff = (int)$start->diff($tag)->format('%r%a');
                if ($diff >= 0 && $diff % $abstand === 0) {
                    $bedarf = (int)$v['bedarf_intervall'];
                }
            } else {
                // Ein Feiertag ersetzt den Wochentagsbedarf, er ergaenzt ihn nicht.
                $bedarf = isset($feiertage[$datum])
                    ? (int)$v['bedarf_feiertag']
                    : (int)$v[$wochenfeld[(int)$tag->format('N')]];
            }

            if ($bedarf > 0) {
                if (isset($vorhanden[$v['id'] . '|' . $datum])) {
                    $uebersprungen++;
                } else {
                    $neu[] = [
                        'datum' => $datum,
                        'masterschicht_id' => (int)$v['id'],
                        'name' => $v['name'],
                        'kuerzel' => $v['kuerzel'],
                        'von' => substr((string)$v['von'], 0, 5),
                        'bis' => substr((string)$v['bis'], 0, 5),
                        'bedarf' => $bedarf,
                        'status' => (int)$v['auf_abruf'] ? 'provisorisch' : 'geplant',
                        'feiertag' => $feiertage[$datum]['name'] ?? null,
                        'art' => $v['art'],
                    ];
                }
            }
            $tag = $tag->modify('+1 day');
        }
    }

    usort($neu, fn($a, $b) => [$a['datum'], $a['von']] <=> [$b['datum'], $b['von']]);

    return [
        'objekt' => [
            'id' => (int)$objekt['id'],
            'name' => $objekt['name'],
            'kunde_id' => $objekt['kunde_id'] === null ? null : (int)$objekt['kunde_id'],
            'kunde_name' => $objekt['kunde_name'],
            'strasse' => $objekt['strasse'],
            'ort' => $objekt['ort'],
            'kanton' => $objekt['kanton'],
            'einsatzart' => $objekt['einsatzart'],
        ],
        'neu' => $neu,
        'uebersprungen' => $uebersprungen,
        'vorlagen' => count($vorlagen),
        'feiertage' => count($feiertage),
    ];
}

// Zeitfenster eines Einsatzes als Zeitstempel. Liegt "bis" vor "von", laeuft
// der Einsatz ueber Mitternacht in den Folgetag.
function zeitfenster(string $datum, string $von, string $bis): array
{
    $tag = substr($datum, 0, 10);
    $a = strtotime($tag . ' ' . substr($von, 0, 5));
    $b = strtotime($tag . ' ' . substr($bis, 0, 5));
    if ($b <= $a) {
        $b += 86400;
    }
    return [$a, $b];
}

// Wer von den gewuenschten Mitarbeitenden ist im selben Zeitfenster schon
// anderswo eingeteilt? (ENT-022)
//
// Aneinandergrenzende Schichten sind KEINE Doppelbelegung: 22:00-22:30 und
// 22:30-22:45 beruehren sich nur. Das ist Absicht -- eine Fahrtzeit schliesst
// direkt an die Runde an.
//
// Abgesagte Einsaetze blockieren nicht. Provisorische schon: die Person ist
// dafuer vorgesehen und kann nicht gleichzeitig woanders sein.
function doppelbelegungen(int $einsatzId, string $datum, string $von, string $bis, array $mitarbeiterIds): array
{
    if (!$mitarbeiterIds) {
        return [];
    }
    [$a, $b] = zeitfenster($datum, $von, $bis);

    // Der Tag davor und danach muss mit, weil Nachtschichten ueber Mitternacht
    // laufen und sonst durchrutschen wuerden.
    $marken = implode(',', array_fill(0, count($mitarbeiterIds), '?'));
    $sql = "SELECT e.id, e.datum, e.von, e.bis, e.kunde_name, e.titel, e.status,
                   z.mitarbeiter_id, m.vorname, m.nachname, m.name
            FROM einsatz_zuteilung z
            JOIN einsaetze e ON e.id = z.einsatz_id
            JOIN mitarbeiter m ON m.id = z.mitarbeiter_id
            WHERE z.mitarbeiter_id IN ($marken)
              AND e.id <> ?
              AND e.status <> 'abgesagt'
              AND e.datum BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY)";
    $stmt = db()->prepare($sql);
    $stmt->execute([...$mitarbeiterIds, $einsatzId, $datum, $datum]);

    $treffer = [];
    foreach ($stmt->fetchAll() as $r) {
        [$c, $d] = zeitfenster($r['datum'], $r['von'], $r['bis']);
        if ($c < $b && $a < $d) {
            $name = trim(($r['vorname'] ?? '') . ' ' . ($r['nachname'] ?? '')) ?: $r['name'];
            $treffer[] = [
                'mitarbeiter_id' => (int)$r['mitarbeiter_id'],
                'name' => $name,
                'einsatz_id' => (int)$r['id'],
                'was' => trim(($r['titel'] ?: $r['kunde_name']) . ' ' . substr($r['von'], 0, 5) . '–' . substr($r['bis'], 0, 5)),
                'datum' => $r['datum'],
            ];
        }
    }
    return $treffer;
}
