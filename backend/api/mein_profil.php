<?php
// Die eigenen Stammdaten (ENT-023). Nur lesend.
//
// Ob Mitarbeitende ihre Stammdaten selbst aendern duerfen, ist offen (OP-21).
// Bis das entschieden ist, gibt es hier bewusst kein Schreiben.
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();

$stmt = db()->prepare(
    'SELECT name, ist_admin, personalnummer, anrede, vorname, nachname, geburtsdatum,
            strasse, ort, telefon, mobil, email, erstellt_am
     FROM mitarbeiter WHERE id = ?'
);
$stmt->execute([(int)$user['id']]);
$m = $stmt->fetch();
if (!$m) {
    json_response(['status' => 'error', 'message' => 'Konto nicht gefunden'], 404);
}
$m['ist_admin'] = (bool)$m['ist_admin'];

// Ein paar eigene Zahlen -- was die Person selbst erfasst hat.
$z = db()->prepare(
    'SELECT COUNT(*) AS anzahl, COALESCE(SUM(netto_h), 0) AS stunden
     FROM rapporte WHERE mitarbeiter_id = ? AND datum >= ?'
);
$z->execute([(int)$user['id'], date('Y-m-01')]);
$monat = $z->fetch() ?: ['anzahl' => 0, 'stunden' => 0];

json_response([
    'status' => 'ok',
    'profil' => $m,
    'monat' => ['anzahl' => (int)$monat['anzahl'], 'stunden' => (float)$monat['stunden']],
]);
