<?php
// Rueckmeldung eines Mitarbeitenden zu einer eigenen Schicht (ENT-021/023).
//
// Die Zusage ist eine Information, KEINE Voraussetzung fuer den Einsatz --
// die Einteilung bleibt bestehen, egal was hier gesetzt wird.
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$einsatzId = (int)($in['einsatz_id'] ?? 0);
$zusage = trim((string)($in['zusage'] ?? ''));

if (!in_array($zusage, ['offen', 'zugesagt', 'abgelehnt'], true)) {
    json_response(['status' => 'error', 'message' => 'unbekannte Rueckmeldung'], 400);
}

// Nur die eigene Zuteilung -- die WHERE-Bedingung ist hier die Absicherung.
$stmt = db()->prepare(
    'UPDATE einsatz_zuteilung SET zusage = ? WHERE einsatz_id = ? AND mitarbeiter_id = ?'
);
$stmt->execute([$zusage, $einsatzId, (int)$user['id']]);

if ($stmt->rowCount() === 0) {
    // Entweder gibt es die Zuteilung nicht, oder der Wert war schon gesetzt.
    $chk = db()->prepare('SELECT 1 FROM einsatz_zuteilung WHERE einsatz_id = ? AND mitarbeiter_id = ?');
    $chk->execute([$einsatzId, (int)$user['id']]);
    if (!$chk->fetchColumn()) {
        json_response(['status' => 'error', 'message' => 'Diese Schicht gehoert nicht zu dir'], 404);
    }
}

json_response(['status' => 'ok', 'zusage' => $zusage]);
