<?php
// Ermittelt Kundenstammdaten aus einem kurzen Satz und ergaenzt fehlende
// Angaben per Internetrecherche (ENT-019). Schreibt nichts in die Datenbank --
// das Ergebnis fuellt nur das Anlegen-Formular vor.
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

// Die Suche laeuft deutlich laenger als eine reine Feldextraktion.
set_time_limit(150);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$text = trim((string)($input['text'] ?? ''));
if ($text === '') {
    json_response(['status' => 'error', 'message' => 'Text erforderlich'], 400);
}

$ergebnis = anthropic_recherche_kunde($text);
if ($ergebnis === null) {
    json_response(['status' => 'error', 'message' => 'Recherche nicht verfuegbar oder ohne Ergebnis'], 502);
}

$felder = [];
foreach (['name', 'strasse', 'ort', 'telefon', 'email'] as $f) {
    $wert = trim((string)($ergebnis[$f] ?? ''));
    if ($wert !== '') {
        $felder[$f] = $wert;
    }
}

// Nur Quellen mit http(s) durchlassen -- der Wert geht als Link in die Oberflaeche.
$quellen = [];
foreach ((array)($ergebnis['quellen'] ?? []) as $q) {
    $q = trim((string)$q);
    if (preg_match('~^https?://~i', $q)) {
        $quellen[] = $q;
    }
}

json_response([
    'status' => 'ok',
    'felder' => $felder,
    'recherchiert' => array_values(array_intersect(
        array_map('strval', (array)($ergebnis['recherchiert'] ?? [])),
        array_keys($felder)
    )),
    'quellen' => array_slice($quellen, 0, 3),
]);
