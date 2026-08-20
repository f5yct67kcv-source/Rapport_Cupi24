<?php
// Schichtabgleich (ENT-045): haelt fest, was tatsaechlich stattgefunden hat.
//
// Bis hierher kannte das System nur den Plan -- Bedarf, Zuteilung, Zusage.
// Abgerechnet und ausgewertet wird aber die Leistung. Dieser Endpunkt
// schreibt das Ist neben den Plan, ohne den Plan zu veraendern: geplante
// Zeiten, Bedarf und Zuteilung bleiben stehen, damit sich Soll und Ist
// spaeter gegenueberstellen lassen.
//
// Abgeglichen wird JE PERSON, nicht je Schicht: dieselbe Person kann am
// selben Tag auf zwei Objekten unterschiedlich lang gearbeitet haben. Eine
// Schicht, der niemand zugeteilt war, wird als Ganzes abgeglichen -- sonst
// verschwaende sie stillschweigend aus der Rueckschau.
//
// Nimmt eine oder viele Zeilen entgegen; der Sammelabgleich ist derselbe
// Aufruf mit mehreren Eintraegen, kein zweiter Weg.
//
// Bewusst KEINE Berechnung: es werden Zeiten und Anwesenheiten festgehalten,
// aber keine Stunden, keine Zuschlaege und keine Auslagen daraus abgeleitet.
// Das waere GAV-Auslegung und ist bis zu den Eintraegen im Auslegungsregister
// gesperrt (CLAUDE.md Teil B, OP-20, OP-32).
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

// offen      = noch nicht geprueft
// anwesend   = war da, so wie in den Ist-Zeiten festgehalten
// abwesend   = war nicht da, die Schicht fand aber statt
// ausgefallen= die Schicht fand gar nicht statt
const IST_STATUS = ['offen', 'anwesend', 'abwesend', 'ausgefallen'];

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$zeilen = $input['zeilen'] ?? null;
if (!is_array($zeilen) || !$zeilen) {
    json_response(['status' => 'error', 'message' => 'Keine Zeilen uebergeben'], 400);
}
if (count($zeilen) > 500) {
    json_response(['status' => 'error', 'message' => 'Zu viele Zeilen auf einmal (hoechstens 500)'], 400);
}

$zeit = function ($wert): ?string {
    $wert = trim((string)$wert);
    return preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $wert) ? substr($wert, 0, 5) : null;
};

$pdo = db();
$pdo->beginTransaction();
try {
    $jetzt = date('Y-m-d H:i:s');
    $wer = (int)$user['id'];

    $person = $pdo->prepare(
        'UPDATE einsatz_zuteilung
         SET ist_status = ?, ist_von = ?, ist_bis = ?,
             ist_pause_von = ?, ist_pause_min = ?,
             ist_pause_bezahlt_ma = ?, ist_pause_bezahlt_kunde = ?, ist_bemerkung = ?,
             abgeglichen_von = ?, abgeglichen_am = ?
         WHERE einsatz_id = ? AND mitarbeiter_id = ?'
    );
    $schicht = $pdo->prepare(
        'UPDATE einsaetze
         SET ist_status = ?, ist_von = ?, ist_bis = ?,
             ist_pause_von = ?, ist_pause_min = ?,
             ist_pause_bezahlt_ma = ?, ist_pause_bezahlt_kunde = ?, ist_bemerkung = ?,
             abgeglichen_von = ?, abgeglichen_am = ?
         WHERE id = ?'
    );

    $geschrieben = 0;
    foreach ($zeilen as $z) {
        if (!is_array($z)) { continue; }
        $einsatzId = (int)($z['einsatz_id'] ?? 0);
        if ($einsatzId <= 0) { continue; }

        $status = trim((string)($z['ist_status'] ?? ''));
        if (!in_array($status, IST_STATUS, true)) { continue; }

        // Zuruecknehmen loescht auch die Spur, wer wann geprueft hat -- ein
        // offener Abgleich darf nicht so aussehen, als sei er schon einmal
        // bestaetigt worden.
        $offen = $status === 'offen';
        // Wer nicht da war oder wessen Schicht ausfiel, hat keine Ist-Zeiten.
        $ohneZeit = $offen || $status === 'abwesend' || $status === 'ausgefallen';

        $von = $ohneZeit ? null : $zeit($z['ist_von'] ?? '');
        $bis = $ohneZeit ? null : $zeit($z['ist_bis'] ?? '');
        $pause = null;
        if (!$ohneZeit && isset($z['ist_pause_min']) && $z['ist_pause_min'] !== '') {
            $pause = max(0, min(1440, (int)$z['ist_pause_min']));
        }
        // Pausenbeginn plus Dauer (ENT-046) -- das Ende ergibt sich daraus und
        // wird bewusst nicht gespeichert, damit es nur eine Wahrheit gibt.
        $pauseVon = $ohneZeit ? null : $zeit($z['ist_pause_von'] ?? '');
        // Drei Zustaende, nicht zwei: null = noch nicht entschieden, 0 = nein,
        // 1 = ja. Das Zusammenfallen von 'nicht gefragt' und 'nein' waere bei
        // GAV-AUS-004 genau der Fehler, den das Register verhindern soll.
        $jaNein = function ($wert): ?int {
            if ($wert === null || $wert === '' || $wert === 'offen') { return null; }
            return in_array($wert, [1, '1', true, 'ja', 'true'], true) ? 1 : 0;
        };
        $bezahltMa    = $ohneZeit ? null : $jaNein($z['ist_pause_bezahlt_ma'] ?? null);
        $bezahltKunde = $ohneZeit ? null : $jaNein($z['ist_pause_bezahlt_kunde'] ?? null);
        $bemerkung = trim((string)($z['ist_bemerkung'] ?? ''));
        $bemerkung = $bemerkung !== '' ? $bemerkung : null;

        $maId = (int)($z['mitarbeiter_id'] ?? 0);
        if ($maId > 0) {
            $person->execute([$status, $von, $bis, $pauseVon, $pause,
                $bezahltMa, $bezahltKunde, $bemerkung,
                $offen ? null : $wer, $offen ? null : $jetzt, $einsatzId, $maId]);
            $geschrieben += $person->rowCount() > 0 ? 1 : 0;
            continue;
        }
        // Ohne Mitarbeitenden ist die Zeile die unbesetzte Schicht selbst.
        $schicht->execute([$status, $von, $bis, $pauseVon, $pause,
            $bezahltMa, $bezahltKunde, $bemerkung,
            $offen ? null : $wer, $offen ? null : $jetzt, $einsatzId]);
        $geschrieben += $schicht->rowCount() > 0 ? 1 : 0;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response(['status' => 'ok', 'geschrieben' => $geschrieben]);
