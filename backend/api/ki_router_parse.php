<?php
// Ordnet einen diktierten oder getippten Text einem Bereich zu (ENT-032):
// neuer Mitarbeiter, neuer Kunde oder neuer Einsatz. Schreibt nichts -- das
// Ergebnis oeffnet nur den passenden bestehenden Dialog, vorbefuellt wie bei
// den einzelnen Diktaten. Deckt bewusst nur die NEUANLAGE ab, keine Aenderung
// bestehender Datensaetze (siehe ENT-032).
declare(strict_types=1);
require __DIR__ . '/../db.php';
require __DIR__ . '/../ai.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$text = trim((string)($input['text'] ?? ''));
if ($text === '') {
    json_response(['status' => 'error', 'message' => 'Text erforderlich'], 400);
}
$heute = trim((string)($input['heute'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $heute)) {
    $heute = date('Y-m-d');
}

$kunden = db()->query('SELECT name FROM kunden ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$mitarbeiter = db()->query(
    'SELECT name, vorname, nachname FROM mitarbeiter WHERE aktiv = 1 ORDER BY name'
)->fetchAll();

$e = anthropic_route_diktat($text, $kunden, $mitarbeiter, $heute);
if ($e === null) {
    json_response(['status' => 'error', 'message' => 'Erkennung nicht verfuegbar'], 502);
}

$bereich = (string)($e['bereich'] ?? '');
if (!in_array($bereich, ['mitarbeiter', 'kunde', 'einsatz'], true)) {
    json_response([
        'status' => 'error',
        'message' => 'Konnte keinem Bereich zugeordnet werden -- bitte im jeweiligen Bereich direkt diktieren.',
    ], 422);
}

if ($bereich === 'mitarbeiter') {
    json_response(['status' => 'ok', 'bereich' => $bereich, 'felder' => (array)($e['mitarbeiter'] ?? [])]);
}
if ($bereich === 'kunde') {
    json_response(['status' => 'ok', 'bereich' => $bereich, 'felder' => (array)($e['kunde'] ?? [])]);
}

// bereich === 'einsatz': dieselbe Filterung wie ki_einsatz_parse.php -- nur
// bekannte Login-Namen duerfen als Zuteilung in die Oberflaeche gelangen.
$roh = (array)($e['einsatz'] ?? []);
$felder = [];
foreach (['kunde_name', 'titel', 'strasse', 'ort', 'datum', 'von', 'bis', 'einsatzart', 'bemerkung'] as $f) {
    $wert = trim((string)($roh[$f] ?? ''));
    if ($wert !== '') {
        $felder[$f] = $wert;
    }
}
if (isset($roh['bedarf']) && (int)$roh['bedarf'] > 0) {
    $felder['bedarf'] = min(99, (int)$roh['bedarf']);
}
$bekannt = array_column($mitarbeiter, 'name');
$maNamen = array_values(array_intersect(
    array_map('strval', (array)($roh['mitarbeiter_login_namen'] ?? [])),
    $bekannt
));

json_response([
    'status' => 'ok',
    'bereich' => $bereich,
    'felder' => $felder,
    'mitarbeiter_login_namen' => $maNamen,
]);
