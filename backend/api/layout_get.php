<?php
declare(strict_types=1);
// Gespeicherte Anordnung des ANGEMELDETEN Benutzers (ENT-073).
// Die mitarbeiter_id kommt aus der Sitzung und nie aus der Anfrage -- sonst
// liesse sich die Ansicht eines anderen auslesen.
require __DIR__ . '/../db.php';
require __DIR__ . '/../layout.php';

$user = require_session();
$bereich = trim((string)($_GET['bereich'] ?? ''));
if (!layout_bereich_gueltig($bereich)) {
    json_response(['status' => 'error', 'message' => 'unbekannter Bereich'], 400);
}

$pdo = db();
// "eingerichtet" trennt zwei Faelle, die sonst gleich aussehen: Die Tabelle
// fehlt noch (dann laesst sich auch nichts speichern) oder es ist schlicht
// noch nichts gespeichert.
json_response([
    'status' => 'ok',
    'bereich' => $bereich,
    'layout' => layout_lesen($pdo, (int)$user['id'], $bereich),
    'eingerichtet' => hat_tabelle_layout($pdo),
]);
