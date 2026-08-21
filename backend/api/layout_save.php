<?php
declare(strict_types=1);
// Anordnung des ANGEMELDETEN Benutzers speichern (ENT-073).
require __DIR__ . '/../db.php';
require __DIR__ . '/../layout.php';

$user = require_session();
$in = json_decode(file_get_contents('php://input'), true) ?: [];

$bereich = trim((string)($in['bereich'] ?? ''));
if (!layout_bereich_gueltig($bereich)) {
    json_response(['status' => 'error', 'message' => 'unbekannter Bereich'], 400);
}

$layout = layout_pruefen($in['layout'] ?? null);
if ($layout === null) {
    json_response(['status' => 'error', 'message' => 'Anordnung nicht lesbar'], 400);
}

$pdo = db();
if (!hat_tabelle_layout($pdo)) {
    json_response(['status' => 'error',
        'message' => 'Die Tabelle für die Anordnung fehlt noch — einmal „Einrichtung“ ausführen.'], 409);
}

layout_schreiben($pdo, (int)$user['id'], $bereich, $layout);
json_response(['status' => 'ok', 'bereich' => $bereich, 'layout' => $layout]);
