<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$d = json_decode(file_get_contents('php://input'), true) ?? [];

$required = ['datum', 'kunde', 'strasse', 'ort', 'von', 'bis'];
foreach ($required as $f) {
    if (empty($d[$f])) {
        json_response(['status' => 'error', 'message' => "Pflichtfeld fehlt: $f"], 400);
    }
}

// Nettostunden serverseitig berechnen, nicht dem Client vertrauen.
$von = DateTime::createFromFormat('H:i', $d['von']);
$bis = DateTime::createFromFormat('H:i', $d['bis']);
$pause = (int)($d['pause'] ?? 0);
if (!$von || !$bis) {
    json_response(['status' => 'error', 'message' => 'ungueltige Zeitangabe'], 400);
}
$diffMin = ($bis->getTimestamp() - $von->getTimestamp()) / 60;
if ($diffMin < 0) $diffMin += 24 * 60; // Ueber Mitternacht
$nettoH = round(($diffMin - $pause) / 60, 2);
if ($nettoH < 0) {
    json_response(['status' => 'error', 'message' => 'Pause groesser als Arbeitszeit'], 400);
}

// Unterschrift optional: nur ein data:image/png;base64,... akzeptieren, Groesse begrenzen.
$sig = $d['sig'] ?? null;
if ($sig !== null) {
    if (!is_string($sig) || strlen($sig) > 2_000_000 || !preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $sig)) {
        json_response(['status' => 'error', 'message' => 'ungueltige Unterschrift'], 400);
    }
}

$stmt = db()->prepare(
    'INSERT INTO rapporte (mitarbeiter_id, datum, kunde, strasse, ort, auftrag_nr, einsatzart, von, bis, pause_min, netto_h, unterzeichner, unterschrift, bemerkung)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $user['id'], $d['datum'], $d['kunde'], $d['strasse'], $d['ort'],
    $d['auftragNr'] ?? null, $d['einsatzart'] ?? 'Verkehrsdienst',
    $d['von'], $d['bis'], $pause, $nettoH,
    $d['sigName'] ?? null, $sig, $d['bemerkung'] ?? null,
]);

json_response(['status' => 'ok', 'netto_h' => $nettoH]);
