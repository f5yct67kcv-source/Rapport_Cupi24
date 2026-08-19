<?php
// Schichtabgleich (ENT-045): haelt fest, was tatsaechlich stattgefunden hat.
//
// Bis hierher kannte das System nur den Plan -- Bedarf, Zuteilung, Zusage.
// Abgerechnet und ausgewertet wird aber die Leistung. Dieser Endpunkt
// schreibt das Ist neben den Plan, ohne den Plan zu veraendern: geplante
// Zeiten, Bedarf und Zuteilung bleiben stehen, damit sich Soll und Ist
// spaeter gegenueberstellen lassen.
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

const IST_STATUS = ['offen', 'bestaetigt', 'abweichend', 'ausgefallen'];

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int)($input['id'] ?? 0);
if ($id <= 0) {
    json_response(['status' => 'error', 'message' => 'Einsatz erforderlich'], 400);
}

$status = trim((string)($input['ist_status'] ?? ''));
if (!in_array($status, IST_STATUS, true)) {
    json_response(['status' => 'error', 'message' => 'Unbekannter Status'], 400);
}

$pdo = db();
$s = $pdo->prepare('SELECT id, von, bis FROM einsaetze WHERE id = ?');
$s->execute([$id]);
$einsatz = $s->fetch();
if (!$einsatz) {
    json_response(['status' => 'error', 'message' => 'Einsatz nicht gefunden'], 404);
}

$zeit = function ($wert): ?string {
    $wert = trim((string)$wert);
    return preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $wert) ? substr($wert, 0, 5) : null;
};
$istVon = $zeit($input['ist_von'] ?? '');
$istBis = $zeit($input['ist_bis'] ?? '');
$bemerkung = trim((string)($input['ist_bemerkung'] ?? ''));

// Ein Ausfall hat keine Ist-Zeiten und niemanden anwesend -- sonst stuende in
// der Datenbank, jemand habe an einer Schicht gearbeitet, die es nicht gab.
if ($status === 'ausgefallen') {
    $istVon = null;
    $istBis = null;
}

// Zurueck auf "offen" heisst: der Abgleich wird zurueckgenommen. Dann darf
// auch nicht stehen bleiben, wer ihn wann gemacht hat.
$offen = $status === 'offen';

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'UPDATE einsaetze SET ist_status = ?, ist_von = ?, ist_bis = ?, ist_bemerkung = ?,
                abgeglichen_von = ?, abgeglichen_am = ?
         WHERE id = ?'
    )->execute([
        $status,
        $offen ? null : $istVon,
        $offen ? null : $istBis,
        $bemerkung !== '' ? $bemerkung : null,
        $offen ? null : (int)$user['id'],
        $offen ? null : date('Y-m-d H:i:s'),
        $id,
    ]);

    // Anwesenheit je zugeteilter Person. Nur bekannte Zuteilungen werden
    // beruehrt -- ueber diesen Weg entsteht keine neue Zuteilung, und wer
    // nicht mitgeschickt wird, bleibt unveraendert.
    $bekannt = $pdo->prepare('SELECT mitarbeiter_id FROM einsatz_zuteilung WHERE einsatz_id = ?');
    $bekannt->execute([$id]);
    $zugeteilt = array_map('intval', $bekannt->fetchAll(PDO::FETCH_COLUMN));

    if ($offen || $status === 'ausgefallen') {
        // Kein Abgleich, keine Anwesenheitsaussage.
        $pdo->prepare('UPDATE einsatz_zuteilung SET anwesend = NULL WHERE einsatz_id = ?')->execute([$id]);
    } else {
        $setzen = $pdo->prepare('UPDATE einsatz_zuteilung SET anwesend = ? WHERE einsatz_id = ? AND mitarbeiter_id = ?');
        $gemeldet = [];
        foreach ((array)($input['anwesend'] ?? []) as $eintrag) {
            if (!is_array($eintrag)) { continue; }
            $mid = (int)($eintrag['mitarbeiter_id'] ?? 0);
            if (!in_array($mid, $zugeteilt, true)) { continue; }
            $setzen->execute([!empty($eintrag['anwesend']) ? 1 : 0, $id, $mid]);
            $gemeldet[] = $mid;
        }
        // Wer zugeteilt ist, aber nicht gemeldet wurde, gilt als nicht geprueft
        // und bleibt offen -- lieber eine Luecke als eine erfundene Anwesenheit.
        foreach (array_diff($zugeteilt, $gemeldet) as $mid) {
            $pdo->prepare('UPDATE einsatz_zuteilung SET anwesend = NULL WHERE einsatz_id = ? AND mitarbeiter_id = ?')
                ->execute([$id, $mid]);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response(['status' => 'ok']);
