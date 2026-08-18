<?php
// Setzt Personen ueber einen Zeitraum auf eine Masterschicht (ENT-026).
//
// Entspricht dem diktierten Befehl "setze Mitarbeiter XY von Datum bis Datum
// auf die Vormittagsschicht". Fehlende Schichten entstehen dabei aus der
// Vorlage.
//
// Zugeteilt wird ADDITIV: wer schon auf der Schicht steht, bleibt stehen.
// Tage, an denen jemand zeitlich schon anderswo eingeteilt ist, werden
// uebersprungen und ausgewiesen -- stillschweigend zu scheitern waere
// schlimmer als gar nicht zu schreiben (ENT-022).
declare(strict_types=1);
require __DIR__ . '/../db.php';
require __DIR__ . '/../planung.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$objektId   = (int)($in['objekt_id'] ?? 0);
$msId       = (int)($in['masterschicht_id'] ?? 0);
$von        = trim((string)($in['von'] ?? ''));
$bis        = trim((string)($in['bis'] ?? ''));
$nurPruefen = !empty($in['nur_pruefen']);

foreach (['von' => $von, 'bis' => $bis] as $feld => $wert) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wert)) {
        json_response(['status' => 'error', 'message' => "$feld im Format JJJJ-MM-TT erforderlich"], 400);
    }
}
if ($bis < $von) {
    json_response(['status' => 'error', 'message' => 'Das Enddatum liegt vor dem Beginn'], 400);
}
if ((int)(new DateTimeImmutable($von))->diff(new DateTimeImmutable($bis))->format('%a') > 400) {
    json_response(['status' => 'error', 'message' => 'Hoechstens 400 Tage auf einmal'], 400);
}

$m = db()->prepare('SELECT * FROM masterschichten WHERE id = ? AND objekt_id = ?');
$m->execute([$msId, $objektId]);
$vorlage = $m->fetch();
if (!$vorlage) {
    json_response(['status' => 'error', 'message' => 'Schichtvorlage nicht gefunden'], 404);
}

$gewuenscht = array_values(array_unique(array_map('intval', (array)($in['mitarbeiter'] ?? []))));
if (!$gewuenscht) {
    json_response(['status' => 'error', 'message' => 'Niemand ausgewaehlt'], 400);
}
$marken = implode(',', array_fill(0, count($gewuenscht), '?'));
$p = db()->prepare("SELECT id, name, vorname, nachname FROM mitarbeiter
                    WHERE aktiv = 1 AND id IN ($marken)");
$p->execute($gewuenscht);
$leute = $p->fetchAll();
if (!$leute) {
    json_response(['status' => 'error', 'message' => 'Keine aktive Person darunter'], 400);
}
$namen = [];
foreach ($leute as $l) {
    $namen[(int)$l['id']] = trim(($l['vorname'] ?? '') . ' ' . ($l['nachname'] ?? '')) ?: $l['name'];
}

$b = planung_bedarf($objektId, $von, $bis);
if (isset($b['fehler'])) {
    json_response(['status' => 'error', 'message' => $b['fehler']], 404);
}
$tage = array_values(array_filter($b['bedarf'], fn($s) => $s['masterschicht_id'] === $msId));
if (!$tage) {
    json_response([
        'status' => 'error',
        'message' => 'Diese Schicht hat im gewaehlten Zeitraum an keinem Tag Bedarf. '
            . 'Zuerst ueber "Masterschichten auf den Zeitraum legen" den Bedarf setzen.',
    ], 400);
}

$o = $b['objekt'];
$pdo = db();
$pdo->beginTransaction();
try {
    $gesetzt = 0; $schonDa = 0; $neueSchichten = 0;
    $konflikte = [];

    foreach ($tage as $s) {
        $datum = $s['datum'];
        $vh = $pdo->prepare('SELECT * FROM einsaetze WHERE objekt_id = ? AND masterschicht_id = ? AND datum = ?');
        $vh->execute([$objektId, $msId, $datum]);
        $einsatz = $vh->fetch();

        if ($einsatz && $einsatz['status'] === 'abgesagt') {
            continue;   // abgesagte Tage werden nicht wiederbelebt
        }

        $eVon = $einsatz ? substr((string)$einsatz['von'], 0, 5) : $s['von'];
        $eBis = $einsatz ? substr((string)$einsatz['bis'], 0, 5) : $s['bis'];
        $einsatzId = $einsatz ? (int)$einsatz['id'] : 0;

        // Wer ist an diesem Tag ueberhaupt frei?
        $frei = [];
        foreach ($leute as $l) {
            $id = (int)$l['id'];
            $doppelt = doppelbelegungen($einsatzId, $datum, $eVon, $eBis, [$id]);
            if ($doppelt) {
                $konflikte[] = [
                    'datum' => $datum,
                    'name' => $namen[$id],
                    'was' => $doppelt[0]['was'],
                ];
                continue;
            }
            $frei[] = $id;
        }
        if (!$frei) {
            continue;
        }

        if (!$einsatz) {
            $einsatzart = $s['art'] === 'fahrtzeit' ? 'Fahrtzeit' : $o['einsatzart'];
            $ins = $pdo->prepare(
                'INSERT INTO einsaetze (kunde_id, kunde_name, objekt_id, masterschicht_id, titel,
                                        strasse, ort, einsatzart, datum, von, bis, bedarf, status, erstellt_von)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $o['kunde_id'], $o['kunde_name'], $objektId, $msId, $s['name'],
                $o['strasse'], $o['ort'], $einsatzart, $datum, $s['von'], $s['bis'],
                max((int)$s['bedarf'], count($frei)), $s['status'], (int)$user['id'],
            ]);
            $einsatzId = (int)$pdo->lastInsertId();
            $neueSchichten++;
        }

        foreach ($frei as $id) {
            $chk = $pdo->prepare('SELECT 1 FROM einsatz_zuteilung WHERE einsatz_id = ? AND mitarbeiter_id = ?');
            $chk->execute([$einsatzId, $id]);
            if ($chk->fetch()) { $schonDa++; continue; }
            $pdo->prepare('INSERT INTO einsatz_zuteilung (einsatz_id, mitarbeiter_id) VALUES (?, ?)')
                ->execute([$einsatzId, $id]);
            $gesetzt++;
        }
    }

    $antwort = [
        'status' => 'ok',
        'tage' => count($tage),
        'gesetzt' => $gesetzt,
        'schon_da' => $schonDa,
        'neue_schichten' => $neueSchichten,
        'konflikte' => array_slice($konflikte, 0, 30),
        'konflikte_gesamt' => count($konflikte),
        'schicht' => trim(($vorlage['kuerzel'] ? $vorlage['kuerzel'] . ' · ' : '') . $vorlage['name']),
        'personen' => array_values($namen),
        'von' => $von, 'bis' => $bis,
    ];

    if ($nurPruefen) {
        $pdo->rollBack();
        $antwort['nur_pruefen'] = true;
        json_response($antwort);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response($antwort);
