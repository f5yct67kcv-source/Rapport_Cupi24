<?php
// Einen Sperrtag-Eintrag im Ereignis-Feed der Uebersicht als erledigt
// markieren (ENT-033). Loescht nichts -- die Sperre selbst bleibt gueltig
// und sichtbar in der Planung, sie verschwindet nur aus dem "Neu"-Feed.
declare(strict_types=1);
require __DIR__ . '/../db.php';

$user = require_session();
if (!$user['ist_admin']) {
    json_response(['status' => 'error', 'message' => 'nur fuer Admin'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'nur POST'], 405);
}

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int)($in['id'] ?? 0);
if ($id <= 0) {
    json_response(['status' => 'error', 'message' => 'id erforderlich'], 400);
}

$s = db()->prepare('UPDATE verfuegbarkeiten SET gesehen_am = NOW() WHERE id = ? AND gesehen_am IS NULL');
$s->execute([$id]);

json_response(['status' => 'ok', 'id' => $id]);
